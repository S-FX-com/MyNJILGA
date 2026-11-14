<?php
/**
 * Moving a paid online join to the coming dues year
 * (MyNJILGA_Join_Year_Move): a late-year join recorded against this year,
 * before next year's invoices or the join cutover existed, so next year's
 * batch doesn't bill its members a second time.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/join/class-join-year-move.php';

class JoinYearMoveTest extends NJILGA_TestCase {

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
        $this->assertSame( '', MyNJILGA_Join_Year_Move::refusal( $this->join(), $next ) );
        $this->assertTrue( MyNJILGA_Join_Year_Move::offered( $this->join() ) );
    }

    public function testOnlyAPaidJoinOneYearBackIsMoved(): void {
        $next = MyNJILGA_Invoicing::default_dues_year();
        $cases = [
            'a firm invoice'           => $this->join( [ 'invoice_kind' => 'combined' ] ),
            'a join still clearing'    => $this->join( [ 'status' => 'processing' ] ),
            'already the coming year'  => $this->join( [ 'dues_year' => $next ] ),
            'two years back'           => $this->join( [ 'dues_year' => $next - 2 ] ),
        ];
        foreach ( $cases as $name => $row ) {
            $this->assertTrue( MyNJILGA_Join_Year_Move::refusal( $row, $next ) !== '', $name );
            $this->assertFalse( MyNJILGA_Join_Year_Move::offered( $row ), $name );
        }
    }

    /** The UPDATE only moves a row that is still a paid join in the year it was read in. */
    public function testTheMoveIsConditional(): void {
        global $wpdb;
        $wpdb->reset();
        MyNJILGA_Dues_Invoice_Table::move_join_year( 7, 2026, 2027, '{"dues_year":2027}' );
        $sql = $wpdb->last_query();
        foreach ( [ 'SET dues_year = 2027', 'WHERE id = 7', 'AND dues_year = 2026', "invoice_kind = 'join'", "status = 'paid'" ] as $needle ) {
            $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected \"$needle\" in: $sql" );
        }
    }
}
