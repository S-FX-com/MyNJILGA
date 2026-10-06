<?php
/**
 * The Firm Renewal Lookup's rules: which firms a search finds, what a
 * result shows (and, as important, what it never does), when a payment
 * link is offered, and how often a visitor may search.
 */
declare( strict_types=1 );

class FirmRenewalLookupTest extends NJILGA_TestCase {

    // -------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------

    /**
     * An invoice row for $firm, billed to Ann Brown, covering Ann and Ed.
     *
     * @param array<string,mixed> $o
     */
    private function row( int $id, int $companyId, string $firm, array $o = [] ): object {
        $members = [
            [ 'contact_id' => 5, 'name' => 'Ann Brown', 'first_name' => 'Ann', 'last_name' => 'Brown', 'email' => 'ann.brown@private.test', 'dues_cents' => 12500, 'assessment_cents' => 0 ],
            [ 'contact_id' => 9, 'name' => 'Edwina Fox-Secret', 'first_name' => 'Edwina', 'last_name' => 'Fox-Secret', 'email' => 'edwina@private.test', 'dues_cents' => 7500, 'assessment_cents' => 0 ],
        ];
        $ann      = [ 'contact_id' => 5, 'name' => 'Ann Brown', 'first_name' => 'Ann', 'last_name' => 'Brown', 'email' => 'ann.brown@private.test' ];
        $snapshot = MyNJILGA_Dues_Snapshot::build( 2027, 'firm', 'combined', [ 'id' => $companyId, 'name' => $firm ], $ann, $ann, $members );

        return (object) array_merge( [
            'id'                   => $id,
            'dues_year'            => 2027,
            'fluentcrm_company_id' => $companyId,
            'status'               => 'sent',
            'total_amount_cents'   => 20000,
            'amount_due_cents'     => 20000,
            'hosted_invoice_url'   => 'https://invoice.stripe.com/i/acct_1/live_' . $id,
            'roster_snapshot'      => json_encode( $snapshot ),
        ], $o );
    }

    /** @return array<int,object> */
    private function rows(): array {
        return [
            $this->row( 1, 10, 'Smith & Jones, LLP' ),
            $this->row( 2, 11, 'Smithson Law Offices' ),
            $this->row( 3, 12, 'Poe & Roe PC' ),
        ];
    }

    // -------------------------------------------------------------------
    // Search words and matching
    // -------------------------------------------------------------------

    public function testAQueryNeedsAtLeastThreeLettersOrDigits(): void {
        $this->assertFalse( MyNJILGA_Firm_Renewal_Lookup::query_ok( '' ) );
        $this->assertFalse( MyNJILGA_Firm_Renewal_Lookup::query_ok( 'ab' ) );
        $this->assertFalse( MyNJILGA_Firm_Renewal_Lookup::query_ok( ' a b ' ) );
        $this->assertFalse( MyNJILGA_Firm_Renewal_Lookup::query_ok( '&&&' ) );
        $this->assertFalse( MyNJILGA_Firm_Renewal_Lookup::query_ok( 'A&B' ), 'two letters, however they are punctuated' );
        $this->assertTrue( MyNJILGA_Firm_Renewal_Lookup::query_ok( 'abc' ) );
        $this->assertTrue( MyNJILGA_Firm_Renewal_Lookup::query_ok( 'A&B Law' ) );
    }

    public function testSearchWordsAreReducedLikeFirmNames(): void {
        $t = [ 'MyNJILGA_Firm_Renewal_Lookup', 'tokens' ];
        $this->assertSame( [ 'smith', 'jones' ], $t( 'Smith & Jones, LLP' ) );
        $this->assertSame( [ 'smith', 'jones' ], $t( 'smith and jones' ) );
        $this->assertSame( [ 'smith' ], $t( 'Smith' ) );
        $this->assertSame( [ 'smith' ], $t( 'smith smith' ), 'no duplicates' );
        $this->assertSame( [], $t( 'LLP' ), 'only an entity suffix: nothing left to search for' );
        $this->assertSame( [ 'roe' ], $t( 'J Roe' ), 'a lone initial would match nearly every firm' );
    }

    public function testEverySearchWordMustAppearInTheFirmName(): void {
        $m = [ 'MyNJILGA_Firm_Renewal_Lookup', 'name_matches' ];
        $this->assertTrue( $m( 'Smith & Jones, LLP', [ 'smith', 'jones' ] ) );
        $this->assertTrue( $m( 'Smith & Jones, LLP', [ 'smi' ] ), 'a part of a word is enough' );
        $this->assertTrue( $m( 'Smith & Jones, LLP', [ 'jones', 'smith' ] ), 'in any order' );
        $this->assertFalse( $m( 'Smith & Jones, LLP', [ 'smith', 'poe' ] ) );
        $this->assertFalse( $m( 'Smith & Jones, LLP', [] ), 'no words matches nothing, not everything' );
        $this->assertFalse( $m( 'Smith & Jones, LLP', [ 'llp' ] ), 'the entity suffix is noise, on both sides' );
    }

    public function testTheLikeliestFirmComesFirst(): void {
        $r = [ 'MyNJILGA_Firm_Renewal_Lookup', 'rank' ];
        $this->assertSame( 0, $r( 'Smith & Jones, LLP', 'smith and jones' ) );
        $this->assertSame( 1, $r( 'Smithson Law Offices', 'smith' ) );
        $this->assertSame( 2, $r( 'Jones & Smith', 'smith' ) );
    }

    // -------------------------------------------------------------------
    // Which invoices can be paid
    // -------------------------------------------------------------------

    public function testOnlyIssuedInvoicesWithAnHttpsPaymentPageArePayable(): void {
        $s = [ 'MyNJILGA_Firm_Renewal_Lookup', 'row_state' ];
        $this->assertSame( 'pay', $s( $this->row( 1, 1, 'X', [ 'status' => 'created' ] ) ) );
        $this->assertSame( 'pay', $s( $this->row( 1, 1, 'X', [ 'status' => 'sent' ] ) ) );
        $this->assertSame( 'clearing', $s( $this->row( 1, 1, 'X', [ 'status' => 'processing' ] ) ) );

        foreach ( [ 'draft', 'approved', 'excluded', 'paid', 'voided', 'uncollectible', 'downgraded' ] as $status ) {
            $this->assertSame( '', $s( $this->row( 1, 1, 'X', [ 'status' => $status ] ) ), "$status is never offered for payment" );
        }
        $this->assertSame( '', $s( $this->row( 1, 1, 'X', [ 'hosted_invoice_url' => '' ] ) ), 'no payment page, nothing to pay on' );
        $this->assertSame( '', $s( $this->row( 1, 1, 'X', [ 'hosted_invoice_url' => 'http://evil.test/pay' ] ) ), 'a payment page must be https' );
        $this->assertSame( '', $s( $this->row( 1, 1, 'X', [ 'hosted_invoice_url' => 'javascript:alert(1)' ] ) ) );
    }

    // -------------------------------------------------------------------
    // Selecting firms
    // -------------------------------------------------------------------

    public function testASearchFindsFirmsWithAnOpenInvoice(): void {
        $r = MyNJILGA_Firm_Renewal_Lookup::select( $this->rows(), 'smith' );

        $names = array_column( $r['firms'], 'name' );
        $this->assertTrue( in_array( 'Smith & Jones, LLP', $names, true ) );
        $this->assertTrue( in_array( 'Smithson Law Offices', $names, true ) );
        $this->assertFalse( in_array( 'Poe & Roe PC', $names, true ) );
        $this->assertSame( 2, $r['total'] );
    }

    public function testAFirmWithOnlyAPaidInvoiceIsNotFoundAtAll(): void {
        $rows = [ $this->row( 1, 10, 'Smith & Jones, LLP', [ 'status' => 'paid' ] ) ];
        $this->assertSame( [], MyNJILGA_Firm_Renewal_Lookup::select( $rows, 'smith' )['firms'] );
    }

    /** The visitor can't tell a firm that doesn't exist from one that has paid: both come back empty. */
    public function testAnUnknownFirmAndAPaidFirmLookIdentical(): void {
        $paid    = MyNJILGA_Firm_Renewal_Lookup::select( [ $this->row( 1, 10, 'Smith & Jones, LLP', [ 'status' => 'paid' ] ) ], 'smith' );
        $unknown = MyNJILGA_Firm_Renewal_Lookup::select( [ $this->row( 1, 10, 'Smith & Jones, LLP', [ 'status' => 'paid' ] ) ], 'zzzunknown' );
        $this->assertSame( $unknown, $paid );
    }

    public function testBestMatchFirstThenAlphabetical(): void {
        $r = MyNJILGA_Firm_Renewal_Lookup::select( [
            $this->row( 1, 1, 'Jones & Smith' ),
            $this->row( 2, 2, 'Smithson Law Offices' ),
            $this->row( 3, 3, 'Smith' ),
        ], 'smith' );
        $this->assertSame( [ 'Smith', 'Smithson Law Offices', 'Jones & Smith' ], array_column( $r['firms'], 'name' ) );
    }

    public function testAFirmWithSeveralOpenInvoicesListsThemOldestYearFirst(): void {
        $r = MyNJILGA_Firm_Renewal_Lookup::select( [
            $this->row( 2, 10, 'Smith & Jones, LLP', [ 'dues_year' => 2027 ] ),
            $this->row( 1, 10, 'Smith & Jones, LLP', [ 'dues_year' => 2026 ] ),
        ], 'smith jones' );
        $this->assertCount( 1, $r['firms'] );
        $this->assertSame( [ 2026, 2027 ], array_column( $r['firms'][0]['invoices'], 'year' ) );
    }

    public function testAtMostEightFirmsAreListedButTheTotalIsReported(): void {
        $rows = [];
        for ( $i = 1; $i <= 12; $i++ ) {
            $rows[] = $this->row( $i, $i, sprintf( 'Smith Firm %02d', $i ) );
        }
        $r = MyNJILGA_Firm_Renewal_Lookup::select( $rows, 'smith' );
        $this->assertCount( MyNJILGA_Firm_Renewal_Lookup::MAX_FIRMS, $r['firms'] );
        $this->assertSame( 12, $r['total'] );
    }

    public function testATooShortSearchFindsNothing(): void {
        $this->assertSame( [], MyNJILGA_Firm_Renewal_Lookup::select( $this->rows(), 'sm' )['firms'] );
        $this->assertSame( [], MyNJILGA_Firm_Renewal_Lookup::select( $this->rows(), 'LLP' )['firms'], 'an entity suffix alone is not a name' );
    }

    public function testAnInvoiceAlreadyClearingIsShownAsInFlightWithNoPaymentLink(): void {
        $r   = MyNJILGA_Firm_Renewal_Lookup::select( [ $this->row( 1, 10, 'Smith & Jones, LLP', [ 'status' => 'processing' ] ) ], 'smith' );
        $inv = $r['firms'][0]['invoices'][0];
        $this->assertSame( 'clearing', $inv['state'] );
        $this->assertSame( '', $inv['url'], 'never offer a second payment while one is on its way' );
    }

    // -------------------------------------------------------------------
    // What a result shows — and never shows
    // -------------------------------------------------------------------

    public function testAResultShowsTheFirmYearAmountAndAPaymentLink(): void {
        $r   = MyNJILGA_Firm_Renewal_Lookup::select( [ $this->row( 1, 10, 'Smith & Jones, LLP' ) ], 'smith' );
        $inv = $r['firms'][0]['invoices'][0];

        $this->assertSame( 2027, $inv['year'] );
        $this->assertSame( 20000, $inv['amount'] );
        $this->assertSame( 'pay', $inv['state'] );
        $this->assertSame( 'https://invoice.stripe.com/i/acct_1/live_1', $inv['url'] );
        $this->assertSame( 2, $inv['members'] );
        $this->assertSame( 'Ann B.', $inv['billed_to'] );
    }

    public function testTheAmountIsWhatIsStillDue(): void {
        $part = MyNJILGA_Firm_Renewal_Lookup::select( [ $this->row( 1, 10, 'Smith & Jones, LLP', [ 'amount_due_cents' => 5000 ] ) ], 'smith' );
        $this->assertSame( 5000, $part['firms'][0]['invoices'][0]['amount'] );
        $unsynced = MyNJILGA_Firm_Renewal_Lookup::select( [ $this->row( 1, 10, 'Smith & Jones, LLP', [ 'amount_due_cents' => 0 ] ) ], 'smith' );
        $this->assertSame( 20000, $unsynced['firms'][0]['invoices'][0]['amount'], 'falls back to the total' );
    }

    /** The point of the lookup being safe to leave public: nothing that identifies a person gets out. */
    public function testNoEmailAddressOrMemberNameEverLeavesTheSelection(): void {
        $r    = MyNJILGA_Firm_Renewal_Lookup::select( $this->rows(), 'smith' );
        $json = json_encode( $r );

        foreach ( [ 'private.test', '@', 'Fox-Secret', 'Edwina', 'Brown' ] as $leak ) {
            $this->assertFalse( strpos( $json, $leak ) !== false, "\"$leak\" must not appear in what a result carries" );
        }
        $this->assertTrue( strpos( $json, 'Ann B.' ) !== false, 'only first name and last initial' );
    }

    public function testShortNames(): void {
        $n = static function ( array $p ): string {
            return MyNJILGA_Firm_Renewal_Lookup::short_name( array_merge( [ 'name' => '', 'first_name' => '', 'last_name' => '', 'email' => '' ], $p ) );
        };
        $this->assertSame( 'Ann B.', $n( [ 'first_name' => 'Ann', 'last_name' => 'Brown' ] ) );
        $this->assertSame( 'Ann B.', $n( [ 'name' => 'Ann Brown' ] ) );
        $this->assertSame( 'Ann B.', $n( [ 'name' => 'Ann van der Berg' ] ), 'the last word is the surname' );
        $this->assertSame( 'Ann', $n( [ 'first_name' => 'Ann' ] ) );
        $this->assertSame( '', $n( [ 'name' => 'Madonna' ] ), 'a lone word could be anything' );
        $this->assertSame( '', $n( [ 'name' => 'ann@private.test' ] ), 'an email address is never shown' );
        $this->assertSame( '', $n( [] ) );
    }

    // -------------------------------------------------------------------
    // verify="email"
    // -------------------------------------------------------------------

    public function testVerifyModeShowsAFirmOnlyToSomeoneOnItsInvoice(): void {
        $rows = [ $this->row( 1, 10, 'Smith & Jones, LLP' ) ];

        $member = MyNJILGA_Firm_Renewal_Lookup::select( $rows, 'smith', true, 'edwina@private.test' );
        $this->assertCount( 1, $member['firms'], 'a member of the invoice' );

        $billTo = MyNJILGA_Firm_Renewal_Lookup::select( $rows, 'smith', true, 'ANN.BROWN@Private.Test ' );
        $this->assertCount( 1, $billTo['firms'], 'case and spaces don\'t matter' );

        $stranger = MyNJILGA_Firm_Renewal_Lookup::select( $rows, 'smith', true, 'stranger@elsewhere.test' );
        $this->assertSame( [], $stranger['firms'] );

        $blank = MyNJILGA_Firm_Renewal_Lookup::select( $rows, 'smith', true, '' );
        $this->assertSame( [], $blank['firms'], 'verify mode with no email finds nothing' );
    }

    public function testWithoutVerifyAnEmailIsIgnored(): void {
        $rows = [ $this->row( 1, 10, 'Smith & Jones, LLP' ) ];
        $this->assertCount( 1, MyNJILGA_Firm_Renewal_Lookup::select( $rows, 'smith', false, 'stranger@elsewhere.test' )['firms'] );
    }

    public function testRosterEmailLookupCoversBillToOwnerAndMembers(): void {
        $row = $this->row( 1, 10, 'Smith & Jones, LLP' );
        $h   = [ 'MyNJILGA_Firm_Renewal_Lookup', 'roster_has_email' ];
        $this->assertTrue( $h( $row, 'ann.brown@private.test' ) );
        $this->assertTrue( $h( $row, 'edwina@private.test' ) );
        $this->assertFalse( $h( $row, 'nobody@private.test' ) );
        $this->assertFalse( $h( $row, '' ) );
    }

    // -------------------------------------------------------------------
    // Rate limits
    // -------------------------------------------------------------------

    public function testSearchesAreLimitedInAShortAndADailyWindow(): void {
        [ $short ] = MyNJILGA_Firm_Renewal_Lookup::LIMIT_SHORT;
        [ $day ]   = MyNJILGA_Firm_Renewal_Lookup::LIMIT_DAY;

        $this->assertTrue( MyNJILGA_Firm_Renewal_Lookup::limits_allow( 0, 0 ) );
        $this->assertTrue( MyNJILGA_Firm_Renewal_Lookup::limits_allow( $short - 1, $day - 1 ) );
        $this->assertFalse( MyNJILGA_Firm_Renewal_Lookup::limits_allow( $short, 0 ), 'the short window is spent' );
        $this->assertFalse( MyNJILGA_Firm_Renewal_Lookup::limits_allow( 0, $day ), 'the daily window is spent' );
        $this->assertTrue( $short < $day, 'a burst is limited harder than a day\'s use' );
    }

    public function testTheShortcodeNameIsStable(): void {
        $this->assertSame( 'njilga_firm_renewal_lookup', MyNJILGA_Firm_Renewal_Lookup::SHORTCODE );
    }

    // -------------------------------------------------------------------
    // Review findings: the minimum, the visitor bucket, multibyte names
    // -------------------------------------------------------------------

    /** Three characters in total isn't a three-letter search: judge the words that will actually be searched. */
    public function testTheMinimumIsAppliedToTheSearchWordsNotTheRawText(): void {
        $ok = [ 'MyNJILGA_Firm_Renewal_Lookup', 'query_ok' ];
        $this->assertFalse( $ok( 'a bc' ), '"a" is dropped, leaving the two letters "bc" to match nearly anything' );
        $this->assertFalse( $ok( 'jo ne' ), 'two short words are not a three-letter word' );
        $this->assertFalse( $ok( 'a b c d' ) );
        $this->assertFalse( $ok( 'the' ), 'a stopword is not a name' );
        $this->assertFalse( $ok( 'LLP' ) );
        $this->assertTrue( $ok( 'a bcd' ) );
        $this->assertTrue( $ok( 'Poe & Roe' ), 'two short names still have a 3-letter word: "poe"' );
        $this->assertSame( [], MyNJILGA_Firm_Renewal_Lookup::select( $this->rows(), 'a sm' )['firms'], 'and a bypassing search finds nothing' );
    }

    public function testAnIPv4VisitorIsTheirOwnBucket(): void {
        $b = [ 'MyNJILGA_Firm_Renewal_Lookup', 'ip_bucket' ];
        $this->assertSame( 'v4:203.0.113.9', $b( '203.0.113.9' ) );
        $this->assertTrue( $b( '203.0.113.9' ) !== $b( '203.0.113.10' ) );
    }

    /** One connection owns a whole /64 — rotating inside it must not buy a fresh allowance. */
    public function testAnIPv6VisitorIsTheirWholeSlash64(): void {
        $b = [ 'MyNJILGA_Firm_Renewal_Lookup', 'ip_bucket' ];
        $this->assertSame( $b( '2001:db8:1:2::1' ), $b( '2001:db8:1:2:ffff:ffff:ffff:ffff' ) );
        $this->assertSame( $b( '2001:db8:1:2::1' ), $b( '2001:0db8:0001:0002:aaaa:bbbb:cccc:dddd' ), 'however it is written' );
        $this->assertTrue( $b( '2001:db8:1:2::1' ) !== $b( '2001:db8:1:3::1' ), 'a different /64 is a different visitor' );
        $this->assertSame( 'v6:20010db800010002', $b( '2001:db8:1:2::1' ) );
    }

    public function testAnIPv4MappedIPv6AddressIsTheIPv4Visitor(): void {
        $b = [ 'MyNJILGA_Firm_Renewal_Lookup', 'ip_bucket' ];
        $this->assertSame( $b( '203.0.113.9' ), $b( '::ffff:203.0.113.9' ) );
    }

    /** No address must share a bucket, not skip the limit; junk is bucketed as typed. */
    public function testAMissingOrOddAddressIsStillLimited(): void {
        $b = [ 'MyNJILGA_Firm_Renewal_Lookup', 'ip_bucket' ];
        $this->assertSame( 'unknown', $b( '' ) );
        $this->assertSame( 'unknown', $b( '   ' ) );
        $this->assertSame( 'raw:not-an-ip', $b( 'Not-An-IP' ) );
    }

    public function testAMultibyteSurnameGivesAWholeCharacterInitial(): void {
        if ( ! function_exists( 'mb_substr' ) ) {
            return;
        }
        $n = static function ( string $last ): string {
            return MyNJILGA_Firm_Renewal_Lookup::short_name( [ 'name' => '', 'first_name' => 'Ann', 'last_name' => $last, 'email' => '' ] );
        };
        $this->assertSame( 'Ann Á.', $n( 'Álvarez' ) );
        $this->assertSame( 'Ann Ö.', $n( 'öztürk' ), 'upper-cased too' );
        $this->assertSame( 'Ann 李.', $n( '李' ) );
        foreach ( [ 'Álvarez', 'öztürk', '李', 'Ōno' ] as $last ) {
            $this->assertTrue( mb_check_encoding( $n( $last ), 'UTF-8' ), "$last: the initial is valid UTF-8" );
        }
    }
}
