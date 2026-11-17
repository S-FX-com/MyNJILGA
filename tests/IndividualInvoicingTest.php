<?php
/**
 * Members without a firm and their individual invoices (MyNJILGA_Individual_Invoicing): who the
 * Invoicing page offers an invoice, who it does not and why, the invoice candidate that is made
 * for them, and the Payments grouping that must keep each of them apart.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-dues-preview.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-individual-invoicing.php';
require_once dirname( __DIR__ ) . '/includes/class-page-payments.php';

class IndividualInvoicingTest extends NJILGA_TestCase {

    private function priced( array $tags, array $over = [] ): array {
        $defaults = MyNJILGA_Dues_Settings::defaults();
        $config   = array_merge( [
            'default_category' => $defaults['general']['default_category'],
            'inactive_tag'     => $defaults['general']['inactive_tag'],
            'categories'       => $defaults['categories'],
            'assessment'       => $defaults['assessment'],
        ], $over );
        return MyNJILGA_Pricing_Engine::price( [ [
            'contact_id' => 7, 'first_name' => 'Jane', 'last_name' => 'Doe', 'name' => 'Jane Doe', 'email' => 'jane@example.test', 'tags' => $tags,
        ] ], $config );
    }

    private function person( array $over = [] ): array {
        return MyNJILGA_Dues_Snapshot::person( $over + [ 'contact_id' => 7, 'name' => 'Jane Doe', 'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.test' ] );
    }

    // -------------------------------------------------------------------------
    // Who is on no firm
    // -------------------------------------------------------------------------

    public function testOnlyContactsOnNoFirmAreListed_ByName(): void {
        $contacts = [
            5 => [ 'id' => 5, 'name' => 'zed Zimmer',  'firm_ids' => [] ],
            6 => [ 'id' => 6, 'name' => 'Ann Able',    'firm_ids' => [ 40 ] ],
            7 => [ 'id' => 7, 'name' => 'bob baker',   'firm_ids' => [] ],
            8 => [ 'id' => 8, 'name' => 'Cy Cole',     'firm_ids' => [ 40, 41 ] ],
            9 => [ 'id' => 9, 'name' => 'Ann Able',    'firm_ids' => [] ],
        ];
        $out = MyNJILGA_Individual_Invoicing::without_firm( $contacts );
        $this->assertSame( [ 9, 7, 5 ], array_keys( $out ), 'case-insensitive by name, ties by id; a contact on any roster is left out' );
        $this->assertSame( [], MyNJILGA_Individual_Invoicing::without_firm( [] ) );
        $this->assertSame( [ 3 ], array_keys( MyNJILGA_Individual_Invoicing::without_firm( [ 3 => [ 'id' => 3, 'name' => 'No roster key' ] ] ) ), 'a contact with no roster information is on no firm' );
    }

    public function testASelfBilledRowIsTheOneThisClassMakes(): void {
        $own = [ 'fluentcrm_company_id' => 0, 'invoice_kind' => 'combined', 'billing_mode' => 'individual' ];
        $this->assertTrue( MyNJILGA_Individual_Invoicing::is_self_billed( (object) $own ) );
        $this->assertTrue( MyNJILGA_Individual_Invoicing::is_self_billed( $own ), 'an array reads the same' );
        $this->assertFalse( MyNJILGA_Individual_Invoicing::is_self_billed( (object) array_merge( $own, [ 'invoice_kind' => 'join' ] ) ), 'an online join is not one' );
        $this->assertFalse( MyNJILGA_Individual_Invoicing::is_self_billed( (object) array_merge( $own, [ 'fluentcrm_company_id' => 12 ] ) ), 'a firm member\'s individual invoice is a firm\'s' );
        $this->assertFalse( MyNJILGA_Individual_Invoicing::is_self_billed( (object) array_merge( $own, [ 'billing_mode' => 'firm' ] ) ) );
        $this->assertFalse( MyNJILGA_Individual_Invoicing::is_self_billed( (object) array_merge( $own, [ 'invoice_kind' => 'assessment' ] ) ) );
        $this->assertFalse( MyNJILGA_Individual_Invoicing::is_self_billed( (object) [ 'fluentcrm_company_id' => 0, 'invoice_kind' => 'combined' ] ), 'the Settings sample row has no billing mode — it is not one' );
    }

    // -------------------------------------------------------------------------
    // The verdict
    // -------------------------------------------------------------------------

    private function verdict( array $over = [] ): array {
        return MyNJILGA_Individual_Invoicing::verdict( $over + [ 'on_firm' => '', 'email' => 'jane@example.test', 'total_cents' => 12500, 'reason' => '', 'existing' => '' ] );
    }

    public function testSomeoneOwingAndReachableCanBeInvoiced(): void {
        $v = $this->verdict();
        $this->assertSame( [ 'bill', true, '' ], [ $v['code'], $v['can'], $v['why'] ] );
    }

    public function testAnInvoiceAlreadyPastDraftSettlesIt_EvenOverAFirm(): void {
        foreach ( [ 'created', 'sent', 'paid', 'voided', 'processing', 'downgraded', 'uncollectible' ] as $status ) {
            $v = $this->verdict( [ 'existing' => $status ] );
            $this->assertSame( [ 'invoiced', false ], [ $v['code'], $v['can'] ], $status );
        }
        $v = $this->verdict( [ 'existing' => 'created', 'on_firm' => 'Alpha LLP' ] );
        $this->assertSame( 'invoiced', $v['code'], 'the invoice is the fact; the firm is reported by the page' );
    }

    public function testAFirmMemberIsNeverOfferedASecondInvoice(): void {
        $v = $this->verdict( [ 'on_firm' => 'Alpha LLP' ] );
        $this->assertSame( [ 'on_firm', false ], [ $v['code'], $v['can'] ] );
        $this->assertTrue( strpos( $v['why'], 'Alpha LLP' ) !== false && strpos( $v['why'], 'firm\'s invoice bills them' ) !== false );
        $v = $this->verdict( [ 'on_firm' => 'Alpha LLP', 'existing' => 'draft' ] );
        $this->assertSame( [ 'on_firm', false ], [ $v['code'], $v['can'] ], 'not even to refresh a draft: the firm\'s invoice is the one' );
    }

    public function testNoAddressNoInvoice(): void {
        foreach ( [ '', '   ' ] as $email ) {
            $v = $this->verdict( [ 'email' => $email ] );
            $this->assertSame( [ 'no_email', false ], [ $v['code'], $v['can'] ] );
        }
    }

    public function testNothingOwedIsSaidWhy_AndAStaleDraftCanStillBeRefreshed(): void {
        $v = $this->verdict( [ 'total_cents' => 0, 'reason' => 'Already paid for this year' ] );
        $this->assertSame( [ 'nothing', false, 'Already paid for this year' ], [ $v['code'], $v['can'], $v['why'] ] );
        $v = $this->verdict( [ 'total_cents' => 0 ] );
        $this->assertSame( 'Nothing owed this year', $v['why'] );
        foreach ( [ 'draft', 'excluded' ] as $status ) {
            $v = $this->verdict( [ 'total_cents' => 0, 'existing' => $status ] );
            $this->assertSame( [ 'nothing', true ], [ $v['code'], $v['can'] ], 'a draft priced at $0 now can be refreshed to "nothing to bill"' );
        }
    }

    public function testAnOpenDraftIsRefreshedNotAddedTwice(): void {
        foreach ( [ 'draft', 'excluded' ] as $status ) {
            $v = $this->verdict( [ 'existing' => $status ] );
            $this->assertSame( [ 'drafted', true ], [ $v['code'], $v['can'] ], $status );
        }
        $this->assertTrue( MyNJILGA_Individual_Invoicing::is_open_draft( 'draft' ) );
        $this->assertFalse( MyNJILGA_Individual_Invoicing::is_open_draft( 'approved' ), 'an approved row is being created: leave it alone' );
    }

    public function testWhyAPersonComesToZero(): void {
        $tags = [ 'professional' ];
        $this->assertSame( 'Inactive', MyNJILGA_Individual_Invoicing::nothing_reason( [ 'unbilled_reason' => 'inactive', 'dues_note' => 'inactive' ] ) );
        $this->assertSame( 'No category tag — add one in the CRM', MyNJILGA_Individual_Invoicing::nothing_reason( [ 'unbilled_reason' => 'no category tag' ] ) );
        $this->assertSame( 'Already paid for this year', MyNJILGA_Individual_Invoicing::nothing_reason( [ 'dues_note' => MyNJILGA_Dues_Preview::NOTE_YEAR_PAID ] ) );
        $this->assertSame( 'Paid through an online join', MyNJILGA_Individual_Invoicing::nothing_reason( [ 'dues_note' => MyNJILGA_Dues_Preview::NOTE_JOIN_PAID ] ) );
        $this->assertSame( 'Student — $0 dues', MyNJILGA_Individual_Invoicing::nothing_reason( [ 'dues_note' => 'student' ] ) );
        $this->assertSame( 'Nothing owed this year', MyNJILGA_Individual_Invoicing::nothing_reason( [] ) );
        $this->assertTrue( (bool) $tags );
    }

    // -------------------------------------------------------------------------
    // The candidate
    // -------------------------------------------------------------------------

    public function testTheCandidateIsThePersonAloneBilledToThemselves_UnderNoFirm(): void {
        $priced = $this->priced( [ 'professional' ] );
        $this->assertTrue( $priced['totals']['total_cents'] > 0, 'the fixture has to owe something' );
        $c = MyNJILGA_Individual_Invoicing::candidate( 2027, $this->person(), $priced['members'] );

        $this->assertSame( 0, $c['company_id'] );
        $this->assertSame( 'Jane Doe', $c['company_name'], 'the name the screens print for the row is the person\'s' );
        $this->assertSame( [ 'individual', 'combined', 'draft', 2027 ], [ $c['billing_mode'], $c['invoice_kind'], $c['status'], $c['dues_year'] ] );
        $this->assertSame( 7, $c['bill_to']['contact_id'] );
        $this->assertSame( $c['bill_to'], $c['owner'], 'they are their own owner' );
        $this->assertCount( 1, $c['members'] );
        $this->assertSame( $priced['totals']['total_cents'], $c['totals']['total_cents'] );
    }

    public function testNothingOwedMakesAnExcludedPlaceholder_NotADraft(): void {
        $priced = $this->priced( [ 'professional', 'inactive' ] );
        $c      = MyNJILGA_Individual_Invoicing::candidate( 2027, $this->person(), $priced['members'] );
        $this->assertSame( 0, $c['totals']['total_cents'] );
        $this->assertSame( MyNJILGA_Dues_Preview::EXCLUDED_ZERO_TOTAL, $c['status'] );
    }

    public function testTheStoredSnapshotNamesThePerson_AndReadsBackAsAFirmlessInvoice(): void {
        $c    = MyNJILGA_Individual_Invoicing::candidate( 2027, $this->person(), $this->priced( [ 'professional' ] )['members'] );
        $snap = MyNJILGA_Dues_Preview::snapshot_for_candidate( $c );
        $row  = (object) [ 'fluentcrm_company_id' => 0, 'invoice_kind' => 'combined', 'billing_mode' => 'individual', 'roster_snapshot' => json_encode( $snap ) ];
        $this->assertSame( 'Jane Doe', MyNJILGA_Dues_Snapshot::company_name( $row ), 'never "Company #0"' );
        $this->assertSame( 'jane@example.test', MyNJILGA_Dues_Snapshot::bill_to( $row )['email'] );
        $this->assertFalse( MyNJILGA_Dues_Snapshot::is_firm_wide( 'individual', 'combined' ), 'so a re-address to a "new owner" can never apply' );
        $this->assertTrue( MyNJILGA_Individual_Invoicing::is_self_billed( $row ) );
    }

    public function testWithoutANameTheEmailIsTheName(): void {
        $c = MyNJILGA_Individual_Invoicing::candidate( 2027, MyNJILGA_Dues_Snapshot::person( [ 'contact_id' => 9, 'email' => 'pat@example.test' ] ), $this->priced( [ 'professional' ] )['members'] );
        $this->assertSame( 'pat@example.test', $c['company_name'] );
    }

    public function testASentenceSumsUpARun(): void {
        $this->assertSame( '3 individual invoices added under No Invoice, 1 draft refreshed, 2 skipped.', MyNJILGA_Individual_Invoicing::summary( [ 'added' => 3, 'refreshed' => 1, 'skipped' => [ 'a', 'b' ] ] ) );
        $this->assertSame( '1 individual invoice added under No Invoice.', MyNJILGA_Individual_Invoicing::summary( [ 'added' => 1, 'refreshed' => 0, 'skipped' => [] ] ) );
        $this->assertSame( 'Nothing was added.', MyNJILGA_Individual_Invoicing::summary( [ 'added' => 0, 'refreshed' => 0, 'skipped' => [] ] ) );
    }

    // -------------------------------------------------------------------------
    // Joined a firm after being invoiced
    // -------------------------------------------------------------------------

    private function own( int $contactId, string $status, string $name = 'Jane Doe' ): object {
        return (object) [
            'id' => $contactId * 10, 'status' => $status, 'fluentcrm_company_id' => 0, 'invoice_kind' => 'combined', 'billing_mode' => 'individual',
            'bill_to_contact_id' => $contactId, 'roster_snapshot' => json_encode( [ 'version' => 2, 'company' => [ 'id' => 0, 'name' => $name ] ] ),
        ];
    }

    public function testAnOpenInvoiceForSomeoneNowOnAFirmIsFlagged(): void {
        $rows     = [ 7 => $this->own( 7, 'created' ), 8 => $this->own( 8, 'sent', 'Bob Baker' ), 9 => $this->own( 9, 'draft', 'Cy Cole' ) ];
        $contacts = [ 7 => [ 'firm_ids' => [ 40 ] ], 8 => [ 'firm_ids' => [] ], 9 => [ 'firm_ids' => [ 41, 40 ] ] ];
        $firms    = [ 40 => [ 'name' => 'Alpha LLP' ], 41 => [ 'name' => 'Beta PC' ] ];
        $out      = MyNJILGA_Individual_Invoicing::conflicts( $rows, $contacts, $firms );
        $this->assertSame(
            [ [ 'id' => 7, 'name' => 'Jane Doe', 'firm' => 'Alpha LLP', 'status' => 'created' ], [ 'id' => 9, 'name' => 'Cy Cole', 'firm' => 'Beta PC', 'status' => 'draft' ] ],
            $out,
            'Bob is on no firm; the first firm of the roster list is named'
        );
    }

    public function testASettledInvoiceIsNotAConflict(): void {
        $rows     = [ 1 => $this->own( 1, 'paid' ), 2 => $this->own( 2, 'voided' ), 3 => $this->own( 3, 'downgraded' ), 4 => $this->own( 4, 'excluded' ) ];
        $contacts = [ 1 => [ 'firm_ids' => [ 40 ] ], 2 => [ 'firm_ids' => [ 40 ] ], 3 => [ 'firm_ids' => [ 40 ] ], 4 => [ 'firm_ids' => [ 40 ] ] ];
        $this->assertSame( [], MyNJILGA_Individual_Invoicing::conflicts( $rows, $contacts, [ 40 => [ 'name' => 'Alpha LLP' ] ] ), 'nothing is billing them twice' );
        $this->assertSame( [], MyNJILGA_Individual_Invoicing::conflicts( [], [], [] ) );
    }

    /** The delete that drops a stale draft is pinned to that one kind of row — never a firm's, a join's or a created invoice. */
    public function testTheDraftDeleteIsPinnedToAMembersOwnDrafts(): void {
        global $wpdb;
        $wpdb->reset();
        MyNJILGA_Dues_Invoice_Table::delete_individual_drafts( [ 70, 80 ], 2027, true );
        $sql = $wpdb->last_query();
        foreach ( [ 'DELETE FROM', 'dues_year = 2027', 'livemode = 1', 'fluentcrm_company_id = 0', "billing_mode = 'individual'", "invoice_kind = 'combined'", "status IN ('draft', 'excluded')", 'id IN (70,80)' ] as $needle ) {
            $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected \"$needle\" in: $sql" );
        }
        $wpdb->reset();
        $this->assertSame( 0, MyNJILGA_Dues_Invoice_Table::delete_individual_drafts( [], 2027, true ) );
        $this->assertCount( 0, $wpdb->queries, 'no ids, no query' );
    }

    // -------------------------------------------------------------------------
    // Payments: each firmless person is their own group
    // -------------------------------------------------------------------------

    private function line( int $company, int $billTo, string $name, int $year, string $status = 'created', int $due = 12500 ): array {
        return [ 'companyId' => $company, 'billToId' => $billTo, 'firm' => $name, 'year' => $year, 'status' => $status, 'paid' => 0, 'due' => $due ];
    }

    public function testPaymentsKeepsEveryFirmlessPersonApart(): void {
        $groups = MyNJILGA_Page_Payments::group_by_firm( [
            $this->line( 0, 7, 'Jane Doe', 2027 ),
            $this->line( 0, 8, 'Bob Baker', 2027 ),
            $this->line( 0, 7, 'Jane Doe', 2026, 'paid', 0 ),
            $this->line( 40, 1, 'Alpha LLP', 2027 ),
            $this->line( 40, 2, 'Alpha LLP', 2027 ),
        ] );
        $this->assertCount( 3, $groups, 'Jane, Bob, and Alpha — not one "firm" for everyone on no firm' );
        $by = [];
        foreach ( $groups as $g ) {
            $by[ $g['name'] ] = $g;
        }
        $this->assertSame( [ 2026, 2027 ], array_keys( $by['Jane Doe']['years'] ), 'a person\'s years stay together' );
        $this->assertSame( 12500, $by['Jane Doe']['outstandingCents'] );
        $this->assertSame( 12500, $by['Bob Baker']['outstandingCents'], 'Jane\'s balance did not leak into Bob\'s' );
        $this->assertSame( 25000, $by['Alpha LLP']['outstandingCents'], 'a firm\'s two invoices are still one group' );
        $this->assertSame( 0, $by['Jane Doe']['companyId'] );
        $this->assertSame( 40, $by['Alpha LLP']['companyId'] );
    }
}
