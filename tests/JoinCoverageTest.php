<?php
/**
 * Only settled online-join money covers anyone on a firm's invoice: which
 * committed joins count as paid, how a frozen $0 "paid via online join"
 * member is re-checked before the invoice goes out, and that a firm
 * invoice "lists" someone only when they are among its members — not
 * because they are its Owner or bill-to.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/join/class-join-orders-table.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-dues-preview.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-creator.php';

class JoinCoverageTest extends NJILGA_TestCase {

    private function join( string $status, array $progress = [] ): object {
        return (object) [ 'id' => 12, 'status' => $status, 'progress' => $progress ? json_encode( $progress ) : null ];
    }

    private function member( int $contactId, int $duesCents, string $note = '', string $name = '' ): array {
        return [ 'contact_id' => $contactId, 'name' => $name, 'dues_cents' => $duesCents, 'dues_note' => $note, 'assessment_cents' => 0, 'unbilled_reason' => '' ];
    }

    // -------------------------------------------------------------------
    // Which committed joins count as paid
    // -------------------------------------------------------------------

    public function test_a_clearing_ach_join_covers_nobody(): void {
        $this->assertFalse( MyNJILGA_Join_Orders_Table::payment_settled( $this->join( MyNJILGA_Join_Orders_Table::STATUS_PROCESSING ) ) );
    }

    public function test_a_paid_join_held_by_a_verification_problem_covers_nobody(): void {
        // sync() marks it paid, with last_error, but never claims it.
        $this->assertFalse( MyNJILGA_Join_Orders_Table::payment_settled( $this->join( MyNJILGA_Join_Orders_Table::STATUS_PAID ) ) );
        $this->assertFalse( MyNJILGA_Join_Orders_Table::payment_settled( $this->join( MyNJILGA_Join_Orders_Table::STATUS_PAID, [ 'reopened_from' => 'expired' ] ) ) );
    }

    public function test_a_verified_paid_join_waiting_on_a_retry_covers_its_people(): void {
        $this->assertTrue( MyNJILGA_Join_Orders_Table::payment_settled( $this->join( MyNJILGA_Join_Orders_Table::STATUS_PAID, [ 'payment_confirmed' => true ] ) ) );
        // Claimed before the marker existed: it got as far as the payer's contact.
        $this->assertTrue( MyNJILGA_Join_Orders_Table::payment_settled( $this->join( MyNJILGA_Join_Orders_Table::STATUS_PAID, [ 'contact_id' => 44 ] ) ) );
    }

    public function test_claimed_and_applied_joins_cover_their_people(): void {
        $this->assertTrue( MyNJILGA_Join_Orders_Table::payment_settled( $this->join( MyNJILGA_Join_Orders_Table::STATUS_FULFILLING ) ) );
        $this->assertTrue( MyNJILGA_Join_Orders_Table::payment_settled( $this->join( MyNJILGA_Join_Orders_Table::STATUS_FULFILLED ) ) );
    }

    public function test_unpaid_and_closed_joins_cover_nobody(): void {
        foreach ( [ 'pending', 'failed', 'expired', 'abandoned', 'review', 'rejected' ] as $status ) {
            $this->assertFalse( MyNJILGA_Join_Orders_Table::payment_settled( $this->join( $status, [ 'payment_confirmed' => true ] ) ), $status );
        }
    }

    // -------------------------------------------------------------------
    // Preview: unsettled joins are flagged, not priced at $0
    // -------------------------------------------------------------------

    public function test_pending_join_members_are_flagged_at_their_price(): void {
        $members = MyNJILGA_Dues_Preview::flag_join_pending(
            [ $this->member( 5, 12500 ), $this->member( 6, 7500 ) ],
            [ 'dues_pending' => [ 6 => 'online join #12, whose bank payment is still clearing' ] ]
        );
        $this->assertFalse( isset( $members[0]['join_pending'] ) );
        $this->assertSame( 'online join #12, whose bank payment is still clearing', $members[1]['join_pending'] );
        $this->assertSame( 7500, $members[1]['dues_cents'], 'The price is left alone' );
        $this->assertSame( '', $members[1]['dues_note'] );
    }

    public function test_only_join_notes_at_zero_count_as_covered_by_a_join(): void {
        $this->assertTrue( MyNJILGA_Dues_Preview::priced_as_join_covered( $this->member( 6, 0, 'paid via online join' ) ) );
        $this->assertTrue( MyNJILGA_Dues_Preview::priced_as_join_covered( $this->member( 6, 0, 'online join payment clearing' ) ), '3.1.3 drafts' );
        $this->assertFalse( MyNJILGA_Dues_Preview::priced_as_join_covered( $this->member( 6, 0, 'Members 6+' ) ) );
        $this->assertFalse( MyNJILGA_Dues_Preview::priced_as_join_covered( $this->member( 6, 12500, 'paid via online join' ) ) );
        $inactive                    = $this->member( 6, 0, 'paid via online join' );
        $inactive['unbilled_reason'] = 'inactive';
        $this->assertFalse( MyNJILGA_Dues_Preview::priced_as_join_covered( $inactive ) );
    }

    // -------------------------------------------------------------------
    // Invoice creation: a $0 member whose join no longer covers them
    // -------------------------------------------------------------------

    public function test_a_zero_member_whose_join_payment_is_gone_is_caught(): void {
        $members = [
            $this->member( 5, 12500, '', 'Ann Brown' ),
            $this->member( 6, 0, 'online join payment clearing', 'Bo Chen' ),
            $this->member( 7, 0, 'paid via online join', 'Cy Dunn' ),
            $this->member( 8, 0, 'Members 6+', 'Di Eames' ),
        ];
        // Cy's join is paid; Bo's debit failed (or is still clearing).
        $lapsed = MyNJILGA_Invoice_Creator::members_no_longer_covered( $members, [ 7 => 91 ] );
        $this->assertCount( 1, $lapsed );
        $this->assertSame( 6, $lapsed[0]['contact_id'] );
        $this->assertSame( 'Bo Chen', $lapsed[0]['name'] );
    }

    public function test_a_settled_join_not_yet_applied_still_covers(): void {
        $members = [ $this->member( 6, 0, 'paid via online join' ) ];
        $this->assertSame( [], MyNJILGA_Invoice_Creator::members_no_longer_covered( $members, [ 6 => 0 ] ) );
    }

    public function test_a_zero_member_with_no_contact_is_named_and_caught(): void {
        $lapsed = MyNJILGA_Invoice_Creator::members_no_longer_covered( [ $this->member( 0, 0, 'paid via online join' ) ], [] );
        $this->assertSame( 'Contact #0', $lapsed[0]['name'] );
    }

    public function test_a_billed_member_covered_by_a_settled_unapplied_join_is_flagged(): void {
        $paid = MyNJILGA_Invoice_Creator::members_paid_by_join( [ $this->member( 6, 7500, '', 'Bo Chen' ) ], [ 6 => 0 ] );
        $this->assertCount( 1, $paid );
        $this->assertSame( 0, $paid[0]['join_row_id'] );
    }

    // -------------------------------------------------------------------
    // "Listed on the invoice" means listed among its members
    // -------------------------------------------------------------------

    private function snapshot(): string {
        return json_encode( [
            'version' => 2,
            'owner'   => [ 'contact_id' => 5, 'name' => 'Olive Owner' ],
            'bill_to' => [ 'contact_id' => 5, 'name' => 'Olive Owner' ],
            'members' => [
                [ 'contact_id' => 6, 'name' => 'Bo Chen', 'dues_cents' => 12500 ],
                [ 'contact_id' => 7, 'name' => 'Cy Dunn', 'dues_cents' => 0, 'dues_note' => 'paid via online join' ],
            ],
        ] );
    }

    public function test_the_owner_or_bill_to_alone_is_not_listed(): void {
        $this->assertSame( null, MyNJILGA_Dues_Invoice_Table::listed_member( $this->snapshot(), 5 ) );
        $this->assertSame( null, MyNJILGA_Dues_Invoice_Table::listed_member( $this->snapshot(), 0 ) );
    }

    public function test_a_member_is_listed_with_their_entry(): void {
        $m = MyNJILGA_Dues_Invoice_Table::listed_member( (object) [ 'roster_snapshot' => $this->snapshot() ], 7 );
        $this->assertSame( 'Cy Dunn', $m['name'] );
        $this->assertSame( 0, $m['dues_cents'] );
    }

    public function test_open_rows_listing_a_contact_are_read_in_the_mode_and_kinds(): void {
        $saved = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class extends NJILGA_Recording_Wpdb {
            public function esc_like( string $text ): string {
                return addcslashes( $text, '_%\\' );
            }
        };
        try {
            MyNJILGA_Dues_Invoice_Table::open_rows_listing_contact( 42, 2027, false );
            $sql = $GLOBALS['wpdb']->last_query();
            $this->assertTrue( strpos( $sql, 'dues_year = 2027 AND livemode = 0' ) !== false, $sql );
            $this->assertTrue( strpos( $sql, "status IN ('draft', 'approved', 'created', 'sent', 'processing')" ) !== false, $sql );
            $this->assertTrue( strpos( $sql, "invoice_kind NOT IN ('assessment', 'join')" ) !== false, $sql );
            $this->assertTrue( strpos( $sql, "LIKE '%\"contact\\_id\":42,%'" ) !== false, $sql );

            MyNJILGA_Dues_Invoice_Table::rows_listing_member( 42, 2027, true, [ 'paid' ], true );
            $sql = $GLOBALS['wpdb']->last_query();
            $this->assertTrue( strpos( $sql, "status IN ('paid')" ) !== false, $sql );
            $this->assertTrue( strpos( $sql, "invoice_kind NOT IN ('assessment')" ) !== false, $sql );
            $this->assertTrue( strpos( $sql, 'livemode = 1' ) !== false, $sql );

            $GLOBALS['wpdb']->reset();
            $this->assertSame( [], MyNJILGA_Dues_Invoice_Table::rows_listing_member( 42, 2027, true, [] ) );
            $this->assertSame( [], MyNJILGA_Dues_Invoice_Table::rows_listing_member( 0, 2027, true, [ 'paid' ] ) );
            $this->assertCount( 0, $GLOBALS['wpdb']->queries );
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }
}
