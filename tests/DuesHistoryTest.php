<?php
/**
 * The pure half of Dues History: turning an invoice row (live Stripe or
 * recreated history) into one entry shape, classifying its status, and
 * adding entries up. No database — rows are built by hand.
 */
declare( strict_types=1 );

class DuesHistoryTest extends NJILGA_TestCase {

    // -------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------

    /** A firm invoice for two members: Ann $125, and Ed $75 + a $200 assessment. */
    private function live_row( array $o = [] ): object {
        $members = [
            [ 'contact_id' => 5, 'name' => 'Ann Brown', 'first_name' => 'Ann', 'last_name' => 'Brown', 'email' => 'ann@sj.test', 'category_key' => 'professional', 'category_label' => 'Professional Membership', 'tier_label' => '1st Member', 'dues_cents' => 12500, 'assessment_cents' => 0 ],
            [ 'contact_id' => 9, 'name' => 'Ed Fox', 'first_name' => 'Ed', 'last_name' => 'Fox', 'email' => 'ed@sj.test', 'category_key' => 'professional', 'category_label' => 'Professional Membership', 'tier_label' => 'Members 2–5', 'dues_cents' => 7500, 'assessment_cents' => 20000, 'assessment_label' => 'Trustee Dinner Assessment', 'assessment_qualifier' => 'Officer' ],
        ];
        $person   = [ 'contact_id' => 5, 'name' => 'Ann Brown', 'first_name' => 'Ann', 'last_name' => 'Brown', 'email' => 'ann@sj.test' ];
        $snapshot = MyNJILGA_Dues_Snapshot::build( 2027, 'firm', 'combined', [ 'id' => 42, 'name' => 'Smith & Jones LLP' ], $person, $person, $members );

        return (object) array_merge( [
            'id'                         => 11,
            'dues_year'                  => 2027,
            'fluentcrm_company_id'       => 42,
            'fluentcrm_owner_contact_id' => 5,
            'bill_to_contact_id'         => 5,
            'invoice_kind'               => 'combined',
            'livemode'                   => 1,
            'status'                     => 'sent',
            'total_amount_cents'         => 40000,
            'amount_paid_cents'          => 0,
            'amount_due_cents'           => 40000,
            'amount_refunded_cents'      => 0,
            'primary_method'             => null,
            'gateway_invoice_number'     => 'ABC-0001',
            'hosted_invoice_url'         => 'https://invoice.stripe.com/i/x',
            'invoice_pdf_url'            => 'https://pay.stripe.com/pdf',
            'due_date'                   => '2027-02-01',
            'paid_at'                    => null,
            'sent_at'                    => '2027-01-05 09:00:00',
            'finalized_at'               => '2027-01-04 09:00:00',
            'created_at'                 => '2027-01-03 09:00:00',
            'roster_snapshot'            => json_encode( $snapshot ),
        ], $o );
    }

    private function hist_row( array $o = [] ): object {
        return (object) array_merge( [
            'id'             => 3,
            'source'         => 'pmpro',
            'dues_year'      => 2025,
            'company_id'     => 42,
            'company_name'   => 'Smith & Jones LLP',
            'contact_id'     => 5,
            'contact_name'   => 'Ann Brown',
            'contact_email'  => 'ann@sj.test',
            'member_ids'     => ',5,',
            'invoice_number' => 'ABCDE12345',
            'description'    => '2025 Professional Membership',
            'line_items'     => json_encode( [ [ 'title' => '2025 Professional Membership', 'amount' => 12500, 'contact_id' => 5 ] ] ),
            'status'         => 'paid',
            'total_cents'    => 12500,
            'paid_cents'     => 12500,
            'invoice_date'   => '2025-01-10',
            'due_date'       => null,
            'paid_at'        => '2025-01-12 14:30:00',
            'method'         => 'card',
            'method_detail'  => 'Visa ••4242',
            'reference'      => 'ch_123',
            'notes'          => 'Recreated from PMPro order #7.',
        ], $o );
    }

    private function payment( array $o = [] ): object {
        return (object) array_merge( [
            'kind' => 'payment', 'amount_cents' => 40000, 'occurred_at' => '2027-01-20 10:00:00', 'method' => 'card',
            'card_brand' => 'visa', 'last4' => '4242', 'bank_name' => null, 'reference' => null, 'receipt_url' => 'https://pay.stripe.com/r', 'status' => 'succeeded',
        ], $o );
    }

    // -------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------

    public function testOnlyIssuedInvoicesAreDuesTransactions(): void {
        $this->assertSame( 'open', MyNJILGA_Dues_History::classify_live( 'created' ) );
        $this->assertSame( 'open', MyNJILGA_Dues_History::classify_live( 'sent' ) );
        $this->assertSame( 'processing', MyNJILGA_Dues_History::classify_live( 'processing' ) );
        $this->assertSame( 'paid', MyNJILGA_Dues_History::classify_live( 'paid' ) );
        $this->assertSame( 'lapsed', MyNJILGA_Dues_History::classify_live( 'downgraded' ) );
        $this->assertSame( 'void', MyNJILGA_Dues_History::classify_live( 'voided' ) );
        $this->assertSame( 'uncollectible', MyNJILGA_Dues_History::classify_live( 'uncollectible' ) );
        foreach ( [ 'draft', 'approved', 'excluded', '' ] as $notIssued ) {
            $this->assertSame( null, MyNJILGA_Dues_History::classify_live( $notIssued ), "$notIssued is not an issued invoice" );
        }
    }

    /** The live status values must keep meaning what history thinks they mean. */
    public function testEveryLiveStatusIsClassified(): void {
        foreach ( MyNJILGA_Dues_Invoice_Table::ALL_STATUSES as $status ) {
            $c = MyNJILGA_Dues_History::classify_live( $status );
            $this->assertTrue( $c === null || in_array( $c, [ 'paid', 'open', 'processing', 'lapsed', 'void', 'uncollectible' ], true ), "unclassified: $status" );
        }
        $this->assertSame( null, MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'status' => 'draft' ] ) ) );
    }

    // -------------------------------------------------------------------
    // A live invoice
    // -------------------------------------------------------------------

    public function testLiveEntryCarriesTheInvoiceAndWhoItCovers(): void {
        $e = MyNJILGA_Dues_History::live_entry( $this->live_row() );

        $this->assertSame( 'live:11', $e['key'] );
        $this->assertSame( 'ABC-0001', $e['number'] );
        $this->assertSame( 'stripe', $e['origin'] );
        $this->assertSame( 'open', $e['status'] );
        $this->assertSame( 2027, $e['year'] );
        $this->assertSame( 'Smith & Jones LLP', $e['company'] );
        $this->assertSame( 42, $e['company_id'] );
        $this->assertSame( 'Ann Brown', $e['bill_to'] );
        $this->assertSame( 40000, $e['total'] );
        $this->assertSame( 40000, $e['balance'] );
        $this->assertSame( '2027-01-05', $e['issued'], 'issued = when it was sent' );
        $this->assertSame( '2027-02-01', $e['due'] );
        $this->assertSame( '2027 membership dues — 2 members', $e['description'] );
        $this->assertSame( [ 5, 9 ], $e['member_ids'] );
        $this->assertSame( 'https://invoice.stripe.com/i/x', $e['hosted_url'] );

        // Ed's dues line, then his assessment line — three lines, adding up to the total.
        $this->assertCount( 3, $e['lines'] );
        $this->assertSame( 40000, array_sum( array_column( $e['lines'], 'amount' ) ) );
    }

    public function testAnOpenInvoiceWhoseAmountDueWasNeverSyncedIsStillOwedInFull(): void {
        $e = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'amount_due_cents' => 0 ] ) );
        $this->assertSame( 40000, $e['balance'] );

        $e = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'amount_due_cents' => 0, 'amount_paid_cents' => 10000 ] ) );
        $this->assertSame( 30000, $e['balance'] );
    }

    public function testAPaidInvoiceOwesNothingWhateverItsAmountDueColumnSays(): void {
        $e = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'status' => 'paid', 'amount_paid_cents' => 40000, 'amount_due_cents' => 40000, 'paid_at' => '2027-01-20 10:00:00' ] ) );
        $this->assertSame( 0, $e['balance'] );
        $this->assertFalse( MyNJILGA_Dues_History::is_open( $e ) );
        $this->assertSame( '2027-01-20', $e['paid_on'] );
    }

    public function testVoidedAndWrittenOffInvoicesOweNothing(): void {
        foreach ( [ 'voided', 'uncollectible' ] as $status ) {
            $e = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'status' => $status ] ) );
            $this->assertSame( 0, $e['balance'], $status );
            $this->assertFalse( MyNJILGA_Dues_History::is_open( $e ), $status );
        }
    }

    /** The Payments ledger counts a downgraded invoice as outstanding — so must the tab. */
    public function testALapsedInvoiceStillCountsAsOpen(): void {
        $e = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'status' => 'downgraded' ] ) );
        $this->assertSame( 'lapsed', $e['status'] );
        $this->assertTrue( MyNJILGA_Dues_History::is_open( $e ) );
    }

    public function testAnACHInFlightInvoiceIsOpenButNotOverdue(): void {
        $e = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'status' => 'processing' ] ) );
        $this->assertTrue( MyNJILGA_Dues_History::is_open( $e ) );
        $this->assertFalse( MyNJILGA_Dues_History::is_overdue( $e, '2030-01-01' ), 'a payment is already on its way' );
    }

    public function testOverdueMeansOpenAndPastDue(): void {
        $e = MyNJILGA_Dues_History::live_entry( $this->live_row() ); // due 2027-02-01
        $this->assertFalse( MyNJILGA_Dues_History::is_overdue( $e, '2027-02-01' ), 'due today is not yet overdue' );
        $this->assertTrue( MyNJILGA_Dues_History::is_overdue( $e, '2027-02-02' ) );

        $noDue = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'due_date' => null ] ) );
        $this->assertFalse( MyNJILGA_Dues_History::is_overdue( $noDue, '2099-01-01' ) );
    }

    public function testIssuedFallsBackThroughFinalizedToCreated(): void {
        $e = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'sent_at' => null ] ) );
        $this->assertSame( '2027-01-04', $e['issued'] );
        $e = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'sent_at' => null, 'finalized_at' => '0000-00-00 00:00:00' ] ) );
        $this->assertSame( '2027-01-03', $e['issued'], 'a zero date is no date' );
    }

    public function testAnInvoiceWithoutANumberIsNamedByItsId(): void {
        $e = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'gateway_invoice_number' => '' ] ) );
        $this->assertSame( '#11', $e['number'] );
    }

    // -------------------------------------------------------------------
    // Payments
    // -------------------------------------------------------------------

    public function testLivePaymentsAreListedNewestFirstAndRefundsAreNegative(): void {
        $e = MyNJILGA_Dues_History::live_entry(
            $this->live_row( [ 'status' => 'paid', 'amount_paid_cents' => 40000, 'amount_refunded_cents' => 5000 ] ),
            [
                $this->payment( [ 'occurred_at' => '2027-01-20 10:00:00' ] ),
                $this->payment( [ 'kind' => 'refund', 'amount_cents' => 5000, 'occurred_at' => '2027-03-01 08:00:00', 'card_brand' => null, 'last4' => null ] ),
            ]
        );

        $this->assertCount( 2, $e['payments'] );
        $this->assertSame( 'refund', $e['payments'][0]['kind'], 'newest first' );
        $this->assertSame( -5000, $e['payments'][0]['amount'] );
        $this->assertSame( 40000, $e['payments'][1]['amount'] );
        $this->assertSame( 'Visa ••4242', $e['payments'][1]['method'] );
        $this->assertSame( 'live:11', $e['payments'][1]['invoice_key'], 'a flat payment list still says what each payment was for' );
        $this->assertSame( 'ABC-0001', $e['payments'][1]['invoice_number'] );
    }

    public function testPaymentMethodWordingMatchesThePaymentsLedger(): void {
        $m = static function ( array $o ): string {
            return MyNJILGA_Dues_History::live_payment_method( (object) array_merge( [ 'method' => '', 'card_brand' => null, 'last4' => null, 'bank_name' => null, 'reference' => null ], $o ) );
        };
        $this->assertSame( 'Visa ••4242', $m( [ 'method' => 'card', 'card_brand' => 'visa', 'last4' => '4242' ] ) );
        $this->assertSame( 'ACH — Chase ••6789', $m( [ 'method' => 'us_bank_account', 'bank_name' => 'Chase', 'last4' => '6789' ] ) );
        $this->assertSame( 'Check #4417', $m( [ 'method' => 'check', 'reference' => '4417' ] ) );
        $this->assertSame( 'Check', $m( [ 'method' => 'check' ] ) );
        $this->assertSame( 'Wire ref 9081', $m( [ 'method' => 'wire', 'reference' => '9081' ] ) );
        $this->assertSame( 'Cash', $m( [ 'method' => 'cash' ] ) );
        $this->assertSame( 'Marked paid in Stripe', $m( [ 'method' => 'other', 'reference' => 'Marked paid in Stripe' ] ) );
        $this->assertSame( 'Other', $m( [ 'method' => 'other' ] ) );
        $this->assertSame( 'Other', $m( [] ) );
    }

    /** The text Stripe writes for an out-of-band payment must not drift from the one this class matches. */
    public function testTheMarkedPaidWordingMatchesTheWebhooksConstant(): void {
        $this->assertSame( MyNJILGA_Stripe_Webhook::MARKED_PAID_IN_STRIPE, 'Marked paid in Stripe' );
    }

    // -------------------------------------------------------------------
    // Recreated history
    // -------------------------------------------------------------------

    public function testHistoryEntryIsAPaidInvoiceWithItsOnePayment(): void {
        $e = MyNJILGA_Dues_History::hist_entry( $this->hist_row() );

        $this->assertSame( 'hist:3', $e['key'] );
        $this->assertSame( 'pmpro', $e['origin'] );
        $this->assertSame( 'ABCDE12345', $e['number'] );
        $this->assertSame( 'paid', $e['status'] );
        $this->assertSame( 0, $e['balance'] );
        $this->assertSame( '2025-01-10', $e['issued'] );
        $this->assertSame( '2025-01-12', $e['paid_on'] );
        $this->assertSame( [ 5 ], $e['member_ids'] );
        $this->assertCount( 1, $e['payments'] );
        $this->assertSame( 12500, $e['payments'][0]['amount'] );
        $this->assertSame( 'Visa ••4242', $e['payments'][0]['method'] );
        $this->assertSame( 'ch_123', $e['payments'][0]['reference'] );
        $this->assertSame( '', $e['hosted_url'], 'a recreated invoice has no Stripe page' );
    }

    public function testAnOpenHistoryInvoiceOwesTheUnpaidRemainder(): void {
        $e = MyNJILGA_Dues_History::hist_entry( $this->hist_row( [ 'status' => 'open', 'total_cents' => 20000, 'paid_cents' => 5000, 'due_date' => '2025-03-01' ] ) );
        $this->assertSame( 'open', $e['status'] );
        $this->assertSame( 15000, $e['balance'] );
        $this->assertTrue( MyNJILGA_Dues_History::is_open( $e ) );
        $this->assertCount( 1, $e['payments'], 'the part payment is on record' );

        $unpaid = MyNJILGA_Dues_History::hist_entry( $this->hist_row( [ 'status' => 'open', 'total_cents' => 20000, 'paid_cents' => 0, 'paid_at' => null ] ) );
        $this->assertCount( 0, $unpaid['payments'] );
    }

    public function testAVoidHistoryInvoiceOwesNothing(): void {
        $e = MyNJILGA_Dues_History::hist_entry( $this->hist_row( [ 'status' => 'void', 'paid_cents' => 0 ] ) );
        $this->assertSame( 'void', $e['status'] );
        $this->assertSame( 0, $e['balance'] );
    }

    public function testHistoryEntryToleratesBlankFields(): void {
        $e = MyNJILGA_Dues_History::hist_entry( $this->hist_row( [
            'invoice_number' => '', 'description' => '', 'contact_name' => '', 'invoice_date' => null, 'line_items' => 'not json', 'member_ids' => null,
        ] ) );
        $this->assertSame( '#H3', $e['number'] );
        $this->assertSame( '2025 membership dues', $e['description'] );
        $this->assertSame( 'ann@sj.test', $e['bill_to'], 'falls back to the email' );
        $this->assertSame( '2025-01-12', $e['issued'], 'falls back to the paid date' );
        $this->assertSame( [], $e['lines'] );
        $this->assertSame( [ 5 ], $e['member_ids'], 'the contact is always a member of their own invoice' );
    }

    public function testMethodWordingForHistoryPayments(): void {
        $this->assertSame( 'Visa ••4242', MyNJILGA_Dues_History::full_method( 'card', 'Visa ••4242', 'ch_1' ) );
        $this->assertSame( 'Check #4417', MyNJILGA_Dues_History::full_method( 'check', '', '4417' ) );
        $this->assertSame( 'Wire ref 12', MyNJILGA_Dues_History::full_method( 'wire', '', '12' ) );
        $this->assertSame( 'PayPal', MyNJILGA_Dues_History::full_method( 'paypal', '', 'tx' ) );
        $this->assertSame( 'ACH', MyNJILGA_Dues_History::full_method( 'ach', '', '' ) );
        $this->assertSame( '', MyNJILGA_Dues_History::full_method( '', '', '' ) );
    }

    // -------------------------------------------------------------------
    // Figures
    // -------------------------------------------------------------------

    public function testShareIsTheContactsOwnLines(): void {
        $e = MyNJILGA_Dues_History::live_entry( $this->live_row() );
        $this->assertSame( 12500, MyNJILGA_Dues_History::share_for( $e, 5 ) );
        $this->assertSame( 27500, MyNJILGA_Dues_History::share_for( $e, 9 ), 'dues plus the assessment' );
        $this->assertSame( null, MyNJILGA_Dues_History::share_for( $e, 77 ), 'not on the invoice' );
        $this->assertSame( null, MyNJILGA_Dues_History::share_for( $e, 0 ) );
    }

    public function testSummaryAddsUpWhatIsOwedAndWhatCameIn(): void {
        $open = MyNJILGA_Dues_History::live_entry( $this->live_row() );
        $paid = MyNJILGA_Dues_History::live_entry(
            $this->live_row( [ 'id' => 12, 'dues_year' => 2026, 'status' => 'paid', 'amount_paid_cents' => 40000, 'amount_refunded_cents' => 5000, 'paid_at' => '2026-01-20 10:00:00' ] ),
            [ $this->payment( [ 'occurred_at' => '2026-01-20 10:00:00' ] ) ]
        );
        $hist = MyNJILGA_Dues_History::hist_entry( $this->hist_row() );

        $s = MyNJILGA_Dues_History::summarise( [ $open, $paid, $hist ] );
        $this->assertSame( 3, $s['invoices'] );
        $this->assertSame( 1, $s['open_count'] );
        $this->assertSame( 40000, $s['open_cents'] );
        $this->assertSame( 40000 + 12500, $s['paid_cents'] );
        $this->assertSame( 5000, $s['refunded_cents'] );
        $this->assertSame( '2026-01-20', $s['last_payment'] );
        $this->assertSame( 2025, $s['first_year'] );
        $this->assertSame( 2027, $s['last_year'] );
    }

    public function testSummaryOfNothingIsZero(): void {
        $s = MyNJILGA_Dues_History::summarise( [] );
        $this->assertSame( 0, $s['invoices'] );
        $this->assertSame( 0, $s['open_cents'] );
        $this->assertSame( '', $s['last_payment'] );
        $this->assertSame( 0, $s['first_year'] );
    }

    public function testARefundIsNeverTheLastPayment(): void {
        $e = MyNJILGA_Dues_History::live_entry(
            $this->live_row( [ 'status' => 'paid', 'amount_paid_cents' => 40000, 'amount_refunded_cents' => 5000 ] ),
            [ $this->payment( [ 'occurred_at' => '2027-01-20 10:00:00' ] ), $this->payment( [ 'kind' => 'refund', 'amount_cents' => 5000, 'occurred_at' => '2027-06-01 08:00:00' ] ) ]
        );
        $this->assertSame( '2027-01-20', MyNJILGA_Dues_History::summarise( [ $e ] )['last_payment'] );
    }

    public function testEntriesSortNewestYearFirstAndOpenOnesOldestFirst(): void {
        $a = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'id' => 1, 'dues_year' => 2025 ] ) );
        $b = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'id' => 2, 'dues_year' => 2027 ] ) );
        $c = MyNJILGA_Dues_History::live_entry( $this->live_row( [ 'id' => 3, 'dues_year' => 2026, 'status' => 'paid', 'amount_paid_cents' => 40000 ] ) );

        $sorted = MyNJILGA_Dues_History::sort_entries( [ $a, $b, $c ] );
        $this->assertSame( [ 2027, 2026, 2025 ], array_column( $sorted, 'year' ) );

        $open = MyNJILGA_Dues_History::open_entries( [ $a, $b, $c ] );
        $this->assertSame( [ 2025, 2027 ], array_column( $open, 'year' ), 'only what is owed, oldest first' );
    }

    public function testAllPaymentsAreFlattenedNewestFirst(): void {
        $a = MyNJILGA_Dues_History::hist_entry( $this->hist_row( [ 'id' => 1, 'paid_at' => '2025-01-12 10:00:00' ] ) );
        $b = MyNJILGA_Dues_History::hist_entry( $this->hist_row( [ 'id' => 2, 'paid_at' => '2026-02-01 10:00:00' ] ) );
        $all = MyNJILGA_Dues_History::all_payments( [ $a, $b ] );
        $this->assertSame( [ 'hist:2', 'hist:1' ], array_column( $all, 'invoice_key' ) );
    }

    // -------------------------------------------------------------------
    // The member_ids column
    // -------------------------------------------------------------------

    public function testMemberIdsAreStoredWithDelimitersOnBothEnds(): void {
        $this->assertSame( ',5,9,', MyNJILGA_Dues_History::member_ids_column( [ 9, 5, 5, 0, -3 ] ) );
        $this->assertSame( '', MyNJILGA_Dues_History::member_ids_column( [ 0 ] ) );
        $this->assertSame( [ 5, 9 ], MyNJILGA_Dues_History::parse_member_ids( ',5,9,' ) );
        $this->assertSame( [ 5, 9, 2 ], MyNJILGA_Dues_History::parse_member_ids( ',5,9,', 2 ) );
    }

    /** The LIKE the table runs is '%,5,%' — it must not find contact 15 or 55. */
    public function testALikeOnOneIdNeverMatchesAnother(): void {
        $stored = MyNJILGA_Dues_History::member_ids_column( [ 15, 55, 105 ] );
        $this->assertFalse( strpos( $stored, ',5,' ) !== false );
        $this->assertTrue( strpos( $stored, ',15,' ) !== false );
    }

    public function testDatePartDropsTimeAndZeroDates(): void {
        $this->assertSame( '2027-01-05', MyNJILGA_Dues_History::date_part( '2027-01-05 09:00:00' ) );
        $this->assertSame( '', MyNJILGA_Dues_History::date_part( '0000-00-00 00:00:00' ) );
        $this->assertSame( '', MyNJILGA_Dues_History::date_part( '' ) );
    }
}
