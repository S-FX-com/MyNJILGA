<?php
/**
 * The invoicing side's guards around online joins: a frozen annual
 * invoice must not bill someone an online join already paid for, and the
 * Checkout permission probe's answer is kept (and re-asked) the way the
 * public Membership page relies on — a definite answer stands, no answer
 * keeps the last definite one.
 *
 * The table reads are asserted at the SQL level against the recording
 * $wpdb stub in tests/bootstrap.php, like tests/ModeIsolationTest.php.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-creator.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-connection.php';

class InvoicingJoinGuardsTest extends NJILGA_TestCase {

    private function member( int $contactId, int $duesCents, string $name = '' ): array {
        return [ 'contact_id' => $contactId, 'name' => $name, 'dues_cents' => $duesCents, 'assessment_cents' => 0 ];
    }

    // -------------------------------------------------------------------
    // Invoice creation: members already paid through a join
    // -------------------------------------------------------------------

    public function test_a_billed_member_covered_by_a_paid_join_is_flagged(): void {
        $members = [ $this->member( 5, 12500, 'Ann Brown' ), $this->member( 6, 7500, 'Bo Chen' ) ];
        $paid    = MyNJILGA_Invoice_Creator::members_paid_by_join( $members, [ 6 => 91 ] );

        $this->assertCount( 1, $paid );
        $this->assertSame( 6, $paid[0]['contact_id'] );
        $this->assertSame( 'Bo Chen', $paid[0]['name'] );
        $this->assertSame( 91, $paid[0]['join_row_id'] );
    }

    public function test_a_member_already_at_zero_dues_is_not_flagged(): void {
        // Priced $0 by a refreshed preview ("paid via online join"), or a
        // 6th-or-later member: leaving them on the invoice charges nothing.
        $paid = MyNJILGA_Invoice_Creator::members_paid_by_join( [ $this->member( 6, 0, 'Bo Chen' ) ], [ 6 => 91 ] );
        $this->assertSame( [], $paid );
    }

    public function test_members_no_join_covers_are_not_flagged(): void {
        $members = [ $this->member( 5, 12500, 'Ann Brown' ), $this->member( 0, 7500, 'No contact' ) ];
        $this->assertSame( [], MyNJILGA_Invoice_Creator::members_paid_by_join( $members, [ 7 => 91 ] ) );
        $this->assertSame( [], MyNJILGA_Invoice_Creator::members_paid_by_join( $members, [] ) );
    }

    public function test_a_flagged_member_without_a_name_is_named_by_contact(): void {
        $paid = MyNJILGA_Invoice_Creator::members_paid_by_join( [ $this->member( 8, 12500 ) ], [ 8 => 3 ] );
        $this->assertSame( 'Contact #8', $paid[0]['name'] );
    }

    public function test_join_paid_contacts_reads_only_paid_join_rows_in_the_mode(): void {
        foreach ( [ true, false ] as $livemode ) {
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->reset();
            MyNJILGA_Dues_Invoice_Table::join_paid_contacts( 2027, $livemode );
            $sql = $wpdb->last_query();

            $this->assertTrue( strpos( $sql, 'dues_year = 2027' ) !== false, $sql );
            $this->assertTrue( strpos( $sql, $livemode ? 'livemode = 1' : 'livemode = 0' ) !== false, $sql );
            $this->assertTrue( strpos( $sql, "status = 'paid'" ) !== false, $sql );
            $this->assertTrue( strpos( $sql, "invoice_kind = 'join'" ) !== false, $sql );
        }
    }

    public function test_get_many_with_no_ids_emits_no_sql(): void {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->reset();
        $this->assertSame( [], MyNJILGA_Dues_Invoice_Table::get_many( [ 0, -3 ] ) );
        $this->assertCount( 0, $wpdb->queries );
    }

    public function test_get_many_reads_every_id_in_one_query(): void {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->reset();
        MyNJILGA_Dues_Invoice_Table::get_many( [ 4, 9, 4 ] );
        $this->assertCount( 1, $wpdb->queries );
        $this->assertTrue( strpos( $wpdb->last_query(), 'id IN (4,9)' ) !== false, $wpdb->last_query() );
    }

    // -------------------------------------------------------------------
    // Checkout permission probe
    // -------------------------------------------------------------------

    public function test_probe_verdict_is_decided_on_the_status(): void {
        $this->assertSame( 'yes', MyNJILGA_Stripe_Connection::checkout_probe_verdict( 404 ), 'No such session: the key may write sessions' );
        $this->assertSame( 'yes', MyNJILGA_Stripe_Connection::checkout_probe_verdict( 400 ) );
        $this->assertSame( 'no', MyNJILGA_Stripe_Connection::checkout_probe_verdict( 401 ) );
        $this->assertSame( 'no', MyNJILGA_Stripe_Connection::checkout_probe_verdict( 403 ) );
        $this->assertSame( 'unknown', MyNJILGA_Stripe_Connection::checkout_probe_verdict( 0 ), 'Transport failure' );
        $this->assertSame( 'unknown', MyNJILGA_Stripe_Connection::checkout_probe_verdict( 503 ) );
    }

    public function test_a_definite_answer_replaces_the_last_and_stands_for_the_ttl(): void {
        $now   = 1700000000;
        $entry = MyNJILGA_Stripe_Connection::next_checkout_access_entry( [ 'state' => 'no', 'next_check' => 0, 'checked_at' => 1 ], 'yes', $now );

        $this->assertSame( 'yes', $entry['state'] );
        $this->assertSame( $now + MyNJILGA_Stripe_Connection::CHECKOUT_ACCESS_TTL_SECONDS, $entry['next_check'] );
        $this->assertSame( $now, $entry['checked_at'] );
    }

    public function test_no_answer_keeps_the_last_definite_one_and_retries_soon(): void {
        $now = 1700000000;
        foreach ( [ 'yes', 'no' ] as $known ) {
            $entry = MyNJILGA_Stripe_Connection::next_checkout_access_entry( [ 'state' => $known, 'next_check' => 5, 'checked_at' => 123 ], 'unknown', $now );

            $this->assertSame( $known, $entry['state'], 'A Stripe outage must not flip a known answer' );
            $this->assertSame( $now + MyNJILGA_Stripe_Connection::CHECKOUT_ACCESS_RETRY_SECONDS, $entry['next_check'] );
            $this->assertSame( 123, $entry['checked_at'], 'Still the time Stripe last actually answered' );
        }
    }

    public function test_no_answer_with_nothing_known_stays_unknown(): void {
        $entry = MyNJILGA_Stripe_Connection::next_checkout_access_entry( [], 'unknown', 1700000000 );
        $this->assertSame( 'unknown', $entry['state'] );
        $this->assertSame( 0, $entry['checked_at'] );
        $this->assertTrue( MyNJILGA_Stripe_Connection::CHECKOUT_ACCESS_RETRY_SECONDS < MyNJILGA_Stripe_Connection::CHECKOUT_ACCESS_TTL_SECONDS );
    }
}
