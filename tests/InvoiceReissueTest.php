<?php
/**
 * Issuing a voided invoice again (MyNJILGA_Invoice_Reissue), and the guards
 * that keep the old Stripe invoice from touching the new one: a late
 * webhook naming the row but the OLD invoice id must not resolve to it.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/interface-invoice-gateway.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-invoice-gateway.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-reissue.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-events-table.php';

class InvoiceReissueTest extends NJILGA_TestCase {

    private function row( array $over = [] ): object {
        return (object) ( $over + [ 'id' => 7, 'dues_year' => 2027, 'invoice_kind' => 'combined', 'status' => 'voided', 'amount_paid_cents' => 0, 'paid_off_stripe_cents' => 0 ] );
    }

    public function testOnlyAnUnpaidVoidedAnnualInvoiceCanBeIssuedAgain(): void {
        $this->assertSame( '', MyNJILGA_Invoice_Reissue::refusal( $this->row() ) );
        foreach ( [
            'still open'      => $this->row( [ 'status' => 'created' ] ),
            'paid'            => $this->row( [ 'status' => 'paid' ] ),
            'an online join'  => $this->row( [ 'invoice_kind' => 'join' ] ),
            'partly paid'     => $this->row( [ 'amount_paid_cents' => 500 ] ),
            'paid by check'   => $this->row( [ 'paid_off_stripe_cents' => 500 ] ),
        ] as $name => $row ) {
            $this->assertTrue( MyNJILGA_Invoice_Reissue::refusal( $row ) !== '', $name );
        }
    }

    /** The row goes back to draft only if it is still that voided, unpaid, annual row — and loses every Stripe trace. */
    public function testReopeningIsConditionalAndClearsTheStripeInvoice(): void {
        global $wpdb;
        $wpdb->reset();
        MyNJILGA_Dues_Invoice_Table::reopen_voided( 7 );
        $sql = $wpdb->last_query();
        foreach ( [ "SET status = 'draft'", 'gateway_invoice_id = NULL', 'hosted_invoice_url = NULL', 'sent_at = NULL', 'voided_at = NULL', 'WHERE id = 7', "AND status = 'voided'", "invoice_kind <> 'join'", 'amount_paid_cents = 0 AND paid_off_stripe_cents = 0' ] as $needle ) {
            $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected \"$needle\" in: $sql" );
        }
    }

    /** A retired invoice's id AND number both lead to its entry; the map keeps only the newest. */
    public function testTheRetiredInvoiceMapTracesBothIdAndNumber(): void {
        $entry = MyNJILGA_Dues_Invoice_Table::retired_entry( $this->row( [ 'id' => 41, 'fluentcrm_company_id' => 12, 'livemode' => 1, 'bill_to_contact_id' => 5, 'billing_mode' => 'firm' ] ), '2026-11-14 09:00:00' );
        $this->assertSame( [ 'row' => 41, 'company' => 12, 'year' => 2027, 'live' => 1, 'kind' => 'combined', 'bill_to' => 5, 'mode' => 'firm', 'at' => '2026-11-14 09:00:00' ], $entry, 'the firm, year, mode, kind and contact it belonged to, and when' );

        $map = MyNJILGA_Dues_Invoice_Table::retired_map_add( [], 'in_OLD', 'NJILGA-2290', $entry );
        $this->assertSame( [ 'in_OLD' => $entry, 'NJILGA-2290' => $entry ], $map );

        $other = MyNJILGA_Dues_Invoice_Table::retired_entry( $this->row( [ 'fluentcrm_company_id' => 30, 'livemode' => 0 ] ), '2026-11-14 09:05:00' );
        $map   = MyNJILGA_Dues_Invoice_Table::retired_map_add( $map, 'in_OTHER', '', $other );
        $this->assertSame( $other, $map['in_OTHER'], 'an invoice with no number still maps by id' );

        $big = [];
        for ( $i = 0; $i < MyNJILGA_Dues_Invoice_Table::RETIRED_KEEP; $i++ ) {
            $big[ 'in_' . $i ] = $entry;
        }
        $big = MyNJILGA_Dues_Invoice_Table::retired_map_add( $big, 'in_NEWEST', '', $other );
        $this->assertCount( MyNJILGA_Dues_Invoice_Table::RETIRED_KEEP, $big );
        $this->assertSame( $other, $big['in_NEWEST'], 'the newest is kept' );
        $this->assertFalse( isset( $big['in_0'] ), 'the oldest drops off' );
    }

    /** The webhook-history trace reads only invoice events that name a row — never a charge, payment or session. */
    public function testTheWebhookHistoryTraceReadsOnlyInvoiceEvents(): void {
        global $wpdb;
        $wpdb->reset();
        $this->assertSame( null, MyNJILGA_Stripe_Events_Table::invoice_trace( 'in_OLD' ) );
        $sql = $wpdb->last_query();
        foreach ( [ "object_id = 'in_OLD'", "type LIKE 'invoice.%'", 'invoice_row_id > 0', 'ORDER BY id DESC LIMIT 1' ] as $needle ) {
            $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected \"$needle\" in: $sql" );
        }
        $wpdb->reset();
        $this->assertSame( null, MyNJILGA_Stripe_Events_Table::invoice_trace( '' ) );
        $this->assertCount( 0, $wpdb->queries, 'no reference, no query' );
    }

    /** A new Stripe key for the next create, so Stripe doesn't replay the voided invoice. */
    public function testTheNextCreateUsesAFreshStripeKey(): void {
        $first = MyNJILGA_Stripe_Invoice_Gateway::idempotency_key( 7, 2027, 'live', 0 );
        $again = MyNJILGA_Stripe_Invoice_Gateway::idempotency_key( 7, 2027, 'live', 1 );
        $this->assertTrue( $first !== $again );
    }

    /** A late event about the OLD invoice names the row in its metadata, but not the row's current invoice: it must not land on it. */
    public function testAnEventForTheOldInvoiceDoesNotLandOnTheReissuedRow(): void {
        $row = (object) [ 'id' => 7, 'dues_year' => 2027, 'invoice_kind' => 'combined', 'status' => 'created', 'gateway_invoice_id' => 'in_NEW' ];
        $db  = new class( $row ) extends NJILGA_Recording_Wpdb {
            private $row;
            public function __construct( $row ) { $this->row = $row; }
            public function get_row( string $query ) {
                $this->queries[] = $query;
                return strpos( $query, 'WHERE id = 7' ) !== false ? $this->row : null;
            }
        };
        $resolve = new ReflectionMethod( 'MyNJILGA_Stripe_Webhook', 'resolve_invoice_row' );
        $resolve->setAccessible( true );

        $saved           = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $db;
        try {
            $old = [ 'id' => 'in_OLD', 'metadata' => [ 'njilga_row_id' => '7', 'njilga_dues_year' => '2027' ] ];
            $this->assertSame( null, $resolve->invoke( null, 'invoice.voided', $old ), 'the voided invoice, not this row\'s' );

            $new = [ 'id' => 'in_NEW', 'metadata' => [ 'njilga_row_id' => '7', 'njilga_dues_year' => '2027' ] ];
            $this->assertSame( $row, $resolve->invoke( null, 'invoice.paid', $new ) );
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }
}
