<?php
/**
 * Moving a paid online join to the coming dues year
 * (MyNJILGA_Paid_Year_Move): a late-year join recorded against this year,
 * before next year's invoices or the join cutover existed, so next year's
 * batch doesn't bill its members a second time.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-paid-year-move.php';

class PaidYearMoveTest extends NJILGA_TestCase {

    private function join( array $over = [] ): object {
        return (object) ( $over + [
            'id'           => 7,
            'dues_year'    => MyNJILGA_Invoicing::default_dues_year() - 1,
            'invoice_kind' => 'join',
            'status'       => 'paid',
        ] );
    }

    public function testAPaidJoinFromThisYearMovesToTheComingOne(): void {
        $next = MyNJILGA_Invoicing::default_dues_year();
        $this->assertSame( '', MyNJILGA_Paid_Year_Move::refusal( $this->join(), $next ) );
        $this->assertTrue( MyNJILGA_Paid_Year_Move::offered( $this->join() ) );
    }

    /** The reported case: a firm's 2026 dues invoice, paid Oct 30, counted toward 2027. */
    public function testAPaidFirmInvoiceFromThisYearMovesToo(): void {
        foreach ( [ 'combined', 'dues' ] as $kind ) {
            $this->assertTrue( MyNJILGA_Paid_Year_Move::offered( $this->join( [ 'invoice_kind' => $kind ] ) ), $kind );
        }
    }

    /** The firm's coming-year preview is cleared; a real invoice there stops the move; a join never does. */
    public function testTargetYearConflicts(): void {
        $c = MyNJILGA_Paid_Year_Move::target_conflicts( [
            (object) [ 'id' => 1, 'invoice_kind' => 'combined', 'status' => 'draft' ],
            (object) [ 'id' => 2, 'invoice_kind' => 'combined', 'status' => 'excluded' ],
            (object) [ 'id' => 3, 'invoice_kind' => 'join', 'status' => 'paid' ],
        ] );
        $this->assertSame( [ 1, 2 ], $c['clear'] );
        $this->assertSame( [], $c['stop'] );

        foreach ( [ 'approved', 'created', 'sent', 'paid', 'voided' ] as $status ) {
            $c = MyNJILGA_Paid_Year_Move::target_conflicts( [ (object) [ 'id' => 4, 'invoice_kind' => 'combined', 'status' => $status ] ] );
            $this->assertCount( 1, $c['stop'], $status );
        }
    }

    public function testOnlyAPaidJoinOneYearBackIsMoved(): void {
        $next = MyNJILGA_Invoicing::default_dues_year();
        $cases = [
            'an assessment-only invoice' => $this->join( [ 'invoice_kind' => 'assessment' ] ),
            'an unpaid firm invoice'   => $this->join( [ 'invoice_kind' => 'combined', 'status' => 'sent' ] ),
            'a join still clearing'    => $this->join( [ 'status' => 'processing' ] ),
            'already the coming year'  => $this->join( [ 'dues_year' => $next ] ),
            'two years back'           => $this->join( [ 'dues_year' => $next - 2 ] ),
        ];
        foreach ( $cases as $name => $row ) {
            $this->assertTrue( MyNJILGA_Paid_Year_Move::refusal( $row, $next ) !== '', $name );
            $this->assertFalse( MyNJILGA_Paid_Year_Move::offered( $row ), $name );
        }
    }

    /** The UPDATE only moves a row that is still a paid join in the year it was read in. */
    public function testTheMoveIsConditional(): void {
        global $wpdb;
        $wpdb->reset();
        MyNJILGA_Dues_Invoice_Table::move_paid_year( 7, 2026, 2027, 'combined', '{"dues_year":2027}' );
        $sql = $wpdb->last_query();
        foreach ( [ 'SET dues_year = 2027', 'WHERE id = 7', 'AND dues_year = 2026', "invoice_kind = 'combined'", "status = 'paid'" ] as $needle ) {
            $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected \"$needle\" in: $sql" );
        }
    }
}
