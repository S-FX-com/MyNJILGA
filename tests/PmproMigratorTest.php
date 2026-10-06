<?php
/**
 * PMPro Migrator's pure mapping: one PMPro order → one history row.
 */
declare( strict_types=1 );

class PmproMigratorTest extends NJILGA_TestCase {

    private function order( array $o = [] ): array {
        return array_merge( [
            'id' => 7, 'code' => 'ABCDE12345', 'user_id' => 12, 'membership_id' => 3,
            'subtotal' => '125.00', 'tax' => '0', 'couponamount' => '', 'total' => '125.00',
            'payment_type' => 'Stripe', 'cardtype' => 'Visa', 'accountnumber' => 'XXXXXXXXXXXX4242',
            'status' => 'success', 'gateway' => 'stripe', 'gateway_environment' => 'live',
            'payment_transaction_id' => 'ch_999', 'timestamp' => '2025-01-12 14:30:00', 'notes' => '',
        ], $o );
    }

    private function ctx( array $o = [] ): array {
        return array_merge( [
            'contact_id' => 5, 'contact_name' => 'Ann Brown', 'contact_email' => 'ann@sj.test',
            'company_id' => 42, 'company_name' => 'Smith & Jones LLP', 'dues_year' => 0, 'batch_id' => 'pmpro-1', 'user_id' => 3,
        ], $o );
    }

    private function map( array $order = [], array $ctx = [] ): array {
        return MyNJILGA_PMPro_Migrator::map_order( $this->order( $order ), [ 3 => 'Professional Membership' ], $this->ctx( $ctx ) );
    }

    // -------------------------------------------------------------------

    public function testAnOrderBecomesAPaidInvoice(): void {
        $r = $this->map();

        $this->assertSame( 'pmpro', $r['source'] );
        $this->assertSame( '7', $r['source_ref'], 'keyed by the PMPro order id, so a re-run skips it' );
        $this->assertSame( 'pmpro-1', $r['batch_id'] );
        $this->assertSame( 'ABCDE12345', $r['invoice_number'], 'PMPro\'s own invoice code' );
        $this->assertSame( 2025, $r['dues_year'], 'the year the order was placed' );
        $this->assertSame( '2025 Professional Membership', $r['description'] );
        $this->assertSame( 'paid', $r['status'] );
        $this->assertSame( 12500, $r['total_cents'] );
        $this->assertSame( 12500, $r['paid_cents'] );
        $this->assertSame( '2025-01-12', $r['invoice_date'] );
        $this->assertSame( '2025-01-12 14:30:00', $r['paid_at'] );
        $this->assertSame( 'card', $r['method'] );
        $this->assertSame( 'Visa ••4242', $r['method_detail'] );
        $this->assertSame( 'ch_999', $r['reference'] );
        $this->assertSame( 42, $r['company_id'] );
        $this->assertSame( 5, $r['contact_id'] );
        $this->assertSame( ',5,', $r['member_ids'] );
        $this->assertSame( 3, $r['created_by'] );
        $this->assertTrue( strpos( $r['notes'], 'order #7 (ABCDE12345)' ) !== false );
    }

    public function testTheDuesYearCanBeSetOverTheOrderDate(): void {
        // A November order for the following year's dues.
        $r = $this->map( [ 'timestamp' => '2024-11-20 09:00:00' ], [ 'dues_year' => 2025 ] );
        $this->assertSame( 2025, $r['dues_year'] );
        $this->assertSame( '2025 Professional Membership', $r['description'] );
        $this->assertSame( '2024-11-20', $r['invoice_date'], 'the real date is kept' );
    }

    public function testLinesAlwaysAddUpToTheAmountPaid(): void {
        $cases = [
            'plain'                 => [ '125.00', '0', '', '125.00' ],
            'coupon'                => [ '125.00', '0', '25.00', '100.00' ],
            'tax'                   => [ '100.00', '8.25', '', '108.25' ],
            'coupon and tax'        => [ '125.00', '6.00', '25.00', '106.00' ],
            'no subtotal recorded'  => [ '', '', '', '75.00' ],
            'figures that disagree' => [ '125.00', '0', '', '110.00' ],
            'a free order'          => [ '0', '0', '', '0' ],
        ];
        foreach ( $cases as $name => $c ) {
            $r     = $this->map( [ 'subtotal' => $c[0], 'tax' => $c[1], 'couponamount' => $c[2], 'total' => $c[3] ] );
            $lines = json_decode( $r['line_items'], true );
            $this->assertSame( $r['total_cents'], array_sum( array_column( $lines, 'amount' ) ), $name );
            $this->assertTrue( count( $lines ) >= 1, $name );
            $this->assertSame( [ 5 ], array_values( array_unique( array_column( $lines, 'contact_id' ) ) ), "$name: every line names the member" );
        }
    }

    public function testACouponShowsAsANegativeDiscountLine(): void {
        $r     = $this->map( [ 'subtotal' => '125.00', 'couponamount' => '25.00', 'total' => '100.00' ] );
        $lines = json_decode( $r['line_items'], true );
        $this->assertSame( [ 12500, -2500 ], array_column( $lines, 'amount' ) );
        $this->assertSame( 'Discount', $lines[1]['title'] );
    }

    public function testDisagreeingFiguresGetAnAdjustmentLine(): void {
        $lines = MyNJILGA_PMPro_Migrator::lines( '2025 X', 12500, 0, 0, 11000, 5 );
        $this->assertSame( [ 12500, -1500 ], array_column( $lines, 'amount' ) );
        $this->assertSame( 'Adjustment', $lines[1]['title'] );
    }

    public function testAnUnknownLevelStillGetsADescription(): void {
        $r = MyNJILGA_PMPro_Migrator::map_order( $this->order( [ 'membership_id' => 99 ] ), [], $this->ctx() );
        $this->assertSame( '2025 Membership level #99', $r['description'] );
        $r = MyNJILGA_PMPro_Migrator::map_order( $this->order( [ 'membership_id' => 0 ] ), [], $this->ctx() );
        $this->assertSame( '2025 Membership', $r['description'] );
    }

    public function testWithoutATransactionIdTheReferenceIsTheOrderCode(): void {
        $this->assertSame( 'ABCDE12345', $this->map( [ 'payment_transaction_id' => '' ] )['reference'] );
    }

    public function testPMProNotesAreCarried(): void {
        $r = $this->map( [ 'notes' => 'Paid at the dinner' ] );
        $this->assertTrue( strpos( $r['notes'], 'PMPro note: Paid at the dinner' ) !== false );
    }

    // -------------------------------------------------------------------
    // How it was paid
    // -------------------------------------------------------------------

    public function testPaymentMethods(): void {
        $m = static function ( array $o ): array {
            return MyNJILGA_PMPro_Migrator::method_for( array_merge( [ 'gateway' => '', 'payment_type' => '', 'cardtype' => '', 'accountnumber' => '' ], $o ) );
        };
        $this->assertSame( [ 'card', 'Visa ••4242' ], $m( [ 'gateway' => 'stripe', 'cardtype' => 'Visa', 'accountnumber' => 'XXXXXXXXXXXX4242' ] ) );
        $this->assertSame( [ 'card', 'Mastercard' ], $m( [ 'cardtype' => 'MasterCard' ] ) );
        $this->assertSame( [ 'card', '' ], $m( [ 'gateway' => 'stripe' ] ), 'a card gateway with no card detail' );
        $this->assertSame( [ 'check', '' ], $m( [ 'gateway' => 'check' ] ) );
        $this->assertSame( [ 'check', '' ], $m( [ 'payment_type' => 'Check' ] ) );
        $this->assertSame( [ 'paypal', '' ], $m( [ 'gateway' => 'paypalexpress' ] ) );
        $this->assertSame( [ 'other', '' ], $m( [] ), 'a $0 order has no payment method' );
    }

    public function testMoneyIsTextInPMPro(): void {
        $this->assertSame( 12500, MyNJILGA_PMPro_Migrator::cents( '125.00' ) );
        $this->assertSame( 0, MyNJILGA_PMPro_Migrator::cents( '' ) );
        $this->assertSame( 0, MyNJILGA_PMPro_Migrator::cents( null ) );
        $this->assertSame( 12345, MyNJILGA_PMPro_Migrator::cents( 123.45 ) );
    }

    // -------------------------------------------------------------------
    // The form
    // -------------------------------------------------------------------

    public function testTheWindowDefaultsAndIsKeptInOrder(): void {
        $default = [ '2025-01-01', '2025-12-31' ];
        $p       = [ 'MyNJILGA_PMPro_Migrator', 'parse_opts' ];

        $this->assertSame( [ 'from' => '2025-01-01', 'to' => '2025-12-31', 'year' => 0, 'skip_zero' => false ], $p( [], $default ) );
        $this->assertSame( '2024-11-01', $p( [ 'from' => '2024-11-01', 'to' => '2025-10-31' ], $default )['from'] );
        $swapped = $p( [ 'from' => '2025-10-31', 'to' => '2024-11-01' ], $default );
        $this->assertSame( [ '2024-11-01', '2025-10-31' ], [ $swapped['from'], $swapped['to'] ], 'an end before the start is swapped' );
        $this->assertSame( '2025-01-01', $p( [ 'from' => 'garbage' ], $default )['from'], 'a bad date falls back' );
        $this->assertSame( 2025, $p( [ 'year' => '2025' ], $default )['year'] );
        $this->assertSame( 0, $p( [ 'year' => '12' ], $default )['year'], 'an implausible year means each order\'s own' );
        $this->assertTrue( $p( [ 'skip_zero' => '1' ], $default )['skip_zero'] );
    }
}
