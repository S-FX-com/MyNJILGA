<?php
/**
 * Wiping a dues year billed in error (MyNJILGA_Year_Wipe), and the two
 * guards that make deleting its rows safe: a late Stripe event naming a
 * deleted row id must not land on a newer row that reused it, and the
 * voided invoices a wipe leaves in Stripe are not "orphans".
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-year-wipe.php';

class YearWipeTest extends NJILGA_TestCase {

    private function row( int $id, string $status, array $over = [] ): object {
        return (object) ( $over + [
            'id'                    => $id,
            'dues_year'             => 2026,
            'fluentcrm_company_id'  => 100 + $id,
            'invoice_kind'          => 'combined',
            'status'                => $status,
            'total_amount_cents'    => 12500,
            'amount_paid_cents'     => 0,
            'paid_off_stripe_cents' => 0,
            'gateway_invoice_id'    => in_array( $status, [ 'created', 'sent', 'voided', 'paid' ], true ) ? 'in_' . $id : null,
        ] );
    }

    private function ids( array $rows ): array {
        return array_map( static function ( $r ) { return (int) $r->id; }, $rows );
    }

    public function testPlanSortsRowsAndKeepsOnlineJoins(): void {
        $plan = MyNJILGA_Year_Wipe::plan( [
            $this->row( 1, 'draft' ),
            $this->row( 2, 'excluded' ),
            $this->row( 3, 'approved' ),
            $this->row( 4, 'created', [ 'total_amount_cents' => 62500 ] ),
            $this->row( 5, 'sent' ),
            $this->row( 6, 'voided' ),
            $this->row( 7, 'paid', [ 'invoice_kind' => 'join' ] ),
        ] );

        $this->assertSame( [ 1, 2, 3, 6 ], $this->ids( $plan['delete'] ) );
        $this->assertSame( [ 4, 5 ], $this->ids( $plan['void'] ) );
        $this->assertSame( [], $plan['kept'] );
        $this->assertSame( 1, $plan['joins'], 'a paid online join is a real membership — never wiped' );
        $this->assertSame( 6, $plan['firms'] );
        $this->assertSame( 75000, $plan['open_cents'] );
    }

    /** Anything with money against it, or already acted on, is kept — never voided or deleted — and the rest still goes. */
    public function testRowsWithAPaymentAreKept(): void {
        $cases = [
            'Paid'                                 => $this->row( 1, 'paid' ),
            'A payment is processing'              => $this->row( 2, 'processing' ),
            'Already swept by the downgrade sweep' => $this->row( 3, 'downgraded' ),
            'Written off in Stripe'                => $this->row( 4, 'uncollectible' ),
            'Partly paid'                          => $this->row( 5, 'sent', [ 'amount_paid_cents' => 100 ] ),
        ];
        foreach ( $cases as $reason => $row ) {
            $this->assertSame( $reason, MyNJILGA_Year_Wipe::keep_reason( $row ), $reason );
        }
        $this->assertSame( 'Partly paid', MyNJILGA_Year_Wipe::keep_reason( $this->row( 6, 'created', [ 'paid_off_stripe_cents' => 5000 ] ) ), 'paid by check' );
        $this->assertTrue( MyNJILGA_Year_Wipe::keep_reason( $this->row( 7, 'created', [ 'gateway_invoice_id' => '' ] ) ) !== '', 'an open invoice with no Stripe id cannot be voided' );

        // The reported case: 168 created, one of them paid — 167 go, 1 stays.
        $plan = MyNJILGA_Year_Wipe::plan( array_merge( array_values( $cases ), [ $this->row( 8, 'created' ), $this->row( 9, 'draft' ) ] ) );
        $this->assertSame( [ 1, 2, 3, 4, 5 ], array_map( static function ( $k ) { return (int) $k['row']->id; }, $plan['kept'] ) );
        $this->assertSame( [ 8 ], $this->ids( $plan['void'] ) );
        $this->assertSame( [ 9 ], $this->ids( $plan['delete'] ) );
        $this->assertSame( 2, $plan['firms'], 'only the firms the wipe changes' );
    }

    /** A wiped year is closed to new batches even though the invoices it kept still give it rows. */
    public function testAWipedYearStaysClosed(): void {
        $current = MyNJILGA_Invoicing::current_dues_year();
        $this->assertTrue( MyNJILGA_Invoicing::year_is_open( $current, true ) );
        $this->assertFalse( MyNJILGA_Invoicing::year_is_open( $current, true, true ) );
        $this->assertTrue( strpos( MyNJILGA_Invoicing::closed_year_message( $current, true ), 'wiped' ) !== false );
    }

    public function testOnlyYearsBeforeTheComingOneQualify(): void {
        $next = MyNJILGA_Invoicing::default_dues_year();
        $this->assertTrue( MyNJILGA_Year_Wipe::year_qualifies( $next - 1 ) );
        $this->assertFalse( MyNJILGA_Year_Wipe::year_qualifies( $next ), 'the batch being prepared is never wipeable' );
        $this->assertFalse( MyNJILGA_Year_Wipe::year_qualifies( $next + 1 ) );
    }

    /** The DELETE is pinned to the year, the mode, non-joins and safe statuses — not just the ids. */
    public function testDeleteIsScopedBeyondTheIds(): void {
        global $wpdb;
        $wpdb->reset();
        MyNJILGA_Dues_Invoice_Table::delete_wiped( [ 4, 9 ], 2026, true );
        $sql = $wpdb->last_query();

        foreach ( [ 'DELETE FROM', 'dues_year = 2026', 'livemode = 1', "invoice_kind <> 'join'", "status IN ('draft','excluded','approved','voided')", 'id IN (4,9)' ] as $needle ) {
            $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected \"$needle\" in: $sql" );
        }

        $wpdb->reset();
        $this->assertSame( 0, MyNJILGA_Dues_Invoice_Table::delete_wiped( [], 2026, true ) );
        $this->assertCount( 0, $wpdb->queries, 'no ids, no query' );
    }

    /**
     * A late event for a wiped 2026 invoice carries njilga_row_id = a
     * deleted id. If MySQL has since reused that id for a 2027 row, the
     * event must not resolve to it.
     */
    public function testAWebhookDoesNotLandOnARowFromAnotherYear(): void {
        $row2027 = (object) [ 'id' => 42, 'dues_year' => 2027, 'invoice_kind' => 'combined', 'status' => 'created' ];
        $db      = new class( $row2027 ) extends NJILGA_Recording_Wpdb {
            private $row;
            public function __construct( $row ) { $this->row = $row; }
            public function get_row( string $query ) {
                $this->queries[] = $query;
                return strpos( $query, 'WHERE id = 42' ) !== false ? $this->row : null;
            }
        };

        $resolve = new ReflectionMethod( 'MyNJILGA_Stripe_Webhook', 'resolve_invoice_row' );
        $resolve->setAccessible( true );

        $saved           = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $db;
        try {
            $stale = [ 'id' => 'in_old', 'metadata' => [ 'njilga_row_id' => '42', 'njilga_dues_year' => '2026' ] ];
            $this->assertSame( null, $resolve->invoke( null, 'invoice.voided', $stale ) );

            $match = [ 'id' => 'in_new', 'metadata' => [ 'njilga_row_id' => '42', 'njilga_dues_year' => '2027' ] ];
            $this->assertSame( $row2027, $resolve->invoke( null, 'invoice.voided', $match ) );

            $noYear = [ 'id' => 'in_new', 'metadata' => [ 'njilga_row_id' => '42' ] ];
            $this->assertSame( $row2027, $resolve->invoke( null, 'invoice.voided', $noYear ), 'no year on the event: trusted as before' );
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }
}
