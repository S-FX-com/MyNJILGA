<?php
/**
 * Unit tests for MyNJILGA_Stripe_Invoice_Gateway::create_order() — the
 * one interface method this class can exercise without WordPress, via
 * the injectable MyNJILGA_Stripe_Client seam (see the gateway's
 * constructor / private client() helper).
 *
 * Every other interface method (is_available, find_or_create_customer,
 * invoice_status, mark_paid_out_of_band, ...) reaches into
 * MyNJILGA_Stripe_Connection, which calls get_option()/current_time() —
 * genuinely WordPress-dependent — and is out of scope for this
 * dependency-free runner.
 *
 * Loading the class itself is safe without WordPress: PHP only executes
 * a method's body when it's called, and create_order() touches no
 * WordPress function directly — every Stripe call goes through the
 * injected client, and every setting create_order() would otherwise
 * pull from MyNJILGA_Stripe_Connection (collection_method, days_until_due,
 * currency, footer, mode) is instead read straight off $context in these
 * tests, which always supply them.
 */
require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-client.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-dues-snapshot.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/interface-invoice-gateway.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-invoice-gateway.php';

/**
 * Records every call made through it and answers with a queue of canned
 * responses programmed per test. Subclasses the real transport (rather
 * than duck-typing a bare interface) purely so it satisfies the
 * gateway constructor's `?MyNJILGA_Stripe_Client` type hint without
 * touching that class — request() is overridden outright, so the parent
 * implementation (and its WordPress-dependent wp_remote_request() call)
 * never runs.
 */
class FakeStripeClientForGatewayTest extends MyNJILGA_Stripe_Client {

    /** @var array<int,array{method:string,path:string,params:array<string,mixed>,opts:array<string,mixed>}> */
    public $calls = [];

    /** @var array<int,array{ok:bool,status:int,body:array<string,mixed>,request_id:string,error:string,code:string}> */
    private $queue = [];

    public function __construct() {
        parent::__construct( 'sk_test_fake' );
    }

    /**
     * @param array{ok?:bool,status?:int,body?:array<string,mixed>,error?:string} $response
     */
    public function queue_response( array $response ): void {
        $this->queue[] = array_merge( [
            'ok'         => true,
            'status'     => 200,
            'body'       => [],
            'request_id' => 'req_fake',
            'error'      => '',
            'code'       => '',
        ], $response );
    }

    public function request( string $method, string $path, array $params = [], array $opts = [] ): array {
        $this->calls[] = [ 'method' => $method, 'path' => $path, 'params' => $params, 'opts' => $opts ];
        if ( ! empty( $this->queue ) ) {
            return array_shift( $this->queue );
        }
        return [ 'ok' => true, 'status' => 200, 'body' => [], 'request_id' => 'req_fake', 'error' => '', 'code' => '' ];
    }
}

class StripeGatewayTest extends NJILGA_TestCase {

    /**
     * @return array<int,array<string,mixed>>
     */
    private function sample_line_items( int $count ): array {
        $items = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $items[] = [
                'title'            => sprintf( 'Member %d — 2027 Professional Membership (1st Member)', $i ),
                'unit_price_cents' => 12500,
                'quantity'         => 1,
                'product_id'       => 0,
                'variation_id'     => 0,
                'line_meta'        => [
                    'contact_id' => 100 + $i,
                    'dues_year'  => 2027,
                    'kind'       => 'dues',
                    'category'   => 'professional',
                    'tier'       => 'first',
                    'rank'       => 1,
                ],
            ];
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function sample_context( array $overrides = [] ): array {
        return array_merge( [
            'dues_year'         => 2027,
            'company_id'        => 42,
            'company_name'      => 'Smith & Jones LLP',
            'invoice_row_id'    => 7,
            'invoice_kind'      => 'combined',
            'mode'              => 'test',
            'collection_method' => 'send_invoice',
            'days_until_due'    => 30,
            'currency'          => 'usd',
            'footer'            => 'Thank you.',
        ], $overrides );
    }

    // -------------------------------------------------------------------
    // (a) Full create -> add_lines -> finalize sequence, right order/paths
    // -------------------------------------------------------------------

    public function testCreateOrderRunsCreateAddLinesFinalizeInOrder(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'body' => [ 'id' => 'in_123' ] ] );
        $client->queue_response( [ 'body' => [] ] );
        $client->queue_response( [ 'body' => [
            'id'                 => 'in_123',
            'number'             => 'NJILGA-0001',
            'hosted_invoice_url' => 'https://pay.stripe.com/inv_hosted',
            'invoice_pdf'        => 'https://pay.stripe.com/inv_hosted.pdf',
            'due_date'           => 1830297600, // 2027-12-31
        ] ] );

        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );
        $result  = $gateway->create_order( 'cus_1', $this->sample_line_items( 2 ), $this->sample_context() );

        $this->assertTrue( $result['ok'] );
        $this->assertCount( 3, $client->calls );

        $this->assertSame( 'POST', $client->calls[0]['method'] );
        $this->assertSame( '/invoices', $client->calls[0]['path'] );

        $this->assertSame( 'POST', $client->calls[1]['method'] );
        $this->assertSame( '/invoices/in_123/add_lines', $client->calls[1]['path'] );

        $this->assertSame( 'POST', $client->calls[2]['method'] );
        $this->assertSame( '/invoices/in_123/finalize', $client->calls[2]['path'] );

        $this->assertSame( 'in_123', $result['invoice_id'] );
        $this->assertSame( 'NJILGA-0001', $result['invoice_number'] );
        $this->assertSame( 'https://pay.stripe.com/inv_hosted', $result['hosted_url'] );
        $this->assertSame( 'https://pay.stripe.com/inv_hosted.pdf', $result['pdf_url'] );
        $this->assertSame( gmdate( 'Y-m-d', 1830297600 ), $result['due_date'] );
    }

    public function testCreateOrderAddLinesLineShape(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'body' => [ 'id' => 'in_9' ] ] );
        $client->queue_response( [ 'body' => [] ] );
        $client->queue_response( [ 'body' => [ 'id' => 'in_9' ] ] );

        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );
        $gateway->create_order( 'cus_1', $this->sample_line_items( 1 ), $this->sample_context() );

        $line = $client->calls[1]['params']['lines'][0];
        $this->assertSame( 12500, $line['amount'] );
        $this->assertSame( 'Member 0 — 2027 Professional Membership (1st Member)', $line['description'] );
        $this->assertSame( 100, $line['metadata']['njilga_contact_id'] );
        $this->assertSame( 'dues', $line['metadata']['njilga_kind'] );
        $this->assertSame( 'professional', $line['metadata']['njilga_category'] );
        $this->assertSame( 'first', $line['metadata']['njilga_tier'] );
        $this->assertSame( 1, $line['metadata']['njilga_rank'] );
    }

    // -------------------------------------------------------------------
    // (b) Metadata on the initial POST /v1/invoices
    // -------------------------------------------------------------------

    public function testCreateOrderMetadataOnInitialPost(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'body' => [ 'id' => 'in_1' ] ] );
        $client->queue_response( [ 'body' => [] ] );
        $client->queue_response( [ 'body' => [ 'id' => 'in_1' ] ] );

        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );
        $gateway->create_order( 'cus_1', $this->sample_line_items( 1 ), $this->sample_context() );

        $params = $client->calls[0]['params'];
        $this->assertSame( 'cus_1', $params['customer'] );
        $this->assertSame( 'send_invoice', $params['collection_method'] );
        $this->assertSame( 30, $params['days_until_due'] );
        $this->assertFalse( $params['auto_advance'] );
        $this->assertSame( 'exclude', $params['pending_invoice_items_behavior'] );
        $this->assertSame( 'usd', $params['currency'] );
        $this->assertSame( '2027 NJILGA Membership Dues — Smith & Jones LLP', $params['description'] );
        $this->assertSame( 'Thank you.', $params['footer'] );
        $this->assertSame( [ 'card', 'us_bank_account' ], $params['payment_settings']['payment_method_types'] );

        $metadata = $params['metadata'];
        $this->assertSame( 7, $metadata['njilga_row_id'] );
        $this->assertSame( 42, $metadata['njilga_company_id'] );
        $this->assertSame( 2027, $metadata['njilga_dues_year'] );
        $this->assertSame( 'combined', $metadata['njilga_invoice_kind'] );
        $this->assertSame( 0, $metadata['njilga_bill_to_contact_id'] );
        $this->assertSame( '1', $metadata['njilga_settles_dues'] );
        $this->assertSame( 'my-njilga', $metadata['source'] );
    }

    public function testCreateOrderAssessmentKindDoesNotSettleDues(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'body' => [ 'id' => 'in_2' ] ] );
        $client->queue_response( [ 'body' => [] ] );
        $client->queue_response( [ 'body' => [ 'id' => 'in_2' ] ] );

        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );
        $gateway->create_order( 'cus_1', $this->sample_line_items( 1 ), $this->sample_context( [ 'invoice_kind' => 'assessment' ] ) );

        $this->assertSame( '0', $client->calls[0]['params']['metadata']['njilga_settles_dues'] );
    }

    // -------------------------------------------------------------------
    // (c) Idempotency-Key format
    // -------------------------------------------------------------------

    public function testIdempotencyKeyFormat(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'body' => [ 'id' => 'in_3' ] ] );
        $client->queue_response( [ 'body' => [] ] );
        $client->queue_response( [ 'body' => [ 'id' => 'in_3' ] ] );

        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );
        $gateway->create_order( 'cus_1', $this->sample_line_items( 1 ), $this->sample_context( [
            'invoice_row_id' => 99,
            'dues_year'      => 2028,
            'mode'           => 'live',
        ] ) );

        $this->assertSame( 'njilga-inv-99-2028-live', $client->calls[0]['opts']['idempotency_key'] );
    }

    // -------------------------------------------------------------------
    // (d) >50 line items chunks add_lines calls at 50
    // -------------------------------------------------------------------

    public function testMoreThan50LineItemsChunksAddLinesCalls(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'body' => [ 'id' => 'in_4' ] ] ); // create
        $client->queue_response( [ 'body' => [] ] ); // add_lines chunk 1 (50)
        $client->queue_response( [ 'body' => [] ] ); // add_lines chunk 2 (10)
        $client->queue_response( [ 'body' => [ 'id' => 'in_4' ] ] ); // finalize

        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );
        $result  = $gateway->create_order( 'cus_1', $this->sample_line_items( 60 ), $this->sample_context() );

        $this->assertTrue( $result['ok'] );
        $this->assertCount( 4, $client->calls );
        $this->assertSame( '/invoices/in_4/add_lines', $client->calls[1]['path'] );
        $this->assertCount( 50, $client->calls[1]['params']['lines'] );
        $this->assertSame( '/invoices/in_4/add_lines', $client->calls[2]['path'] );
        $this->assertCount( 10, $client->calls[2]['params']['lines'] );
        $this->assertSame( '/invoices/in_4/finalize', $client->calls[3]['path'] );
    }

    // -------------------------------------------------------------------
    // (e) >250 line items rejected before any HTTP call
    // -------------------------------------------------------------------

    public function testMoreThan250LineItemsRejectedBeforeAnyHttpCall(): void {
        $client  = new FakeStripeClientForGatewayTest();
        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );

        $result = $gateway->create_order( 'cus_1', $this->sample_line_items( 251 ), $this->sample_context() );

        $this->assertFalse( $result['ok'] );
        $this->assertCount( 0, $client->calls );
        $this->assertSame( 'Too many line items for one Stripe invoice (250 max) — 251 given.', $result['error'] );
    }

    // -------------------------------------------------------------------
    // (f) A 402 on the initial POST surfaces as ok=>false, never throws
    // -------------------------------------------------------------------

    public function testInitialPost402SurfacesAsFailureNotException(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'ok' => false, 'status' => 402, 'error' => 'Your card was declined.' ] );

        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );
        $result  = $gateway->create_order( 'cus_1', $this->sample_line_items( 1 ), $this->sample_context() );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'Your card was declined.', $result['error'] );
        $this->assertCount( 1, $client->calls, 'A failed create must not proceed to add_lines/finalize.' );
    }

    // -------------------------------------------------------------------
    // Cleanup on a partial failure
    // -------------------------------------------------------------------

    public function testAddLinesFailureDeletesTheOrphanedDraft(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'body' => [ 'id' => 'in_5' ] ] ); // create
        $client->queue_response( [ 'ok' => false, 'status' => 400, 'error' => 'Invalid line amount.' ] ); // add_lines fails
        $client->queue_response( [ 'body' => [] ] ); // DELETE cleanup

        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );
        $result  = $gateway->create_order( 'cus_1', $this->sample_line_items( 1 ), $this->sample_context() );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'Invalid line amount.', $result['error'] );
        $this->assertCount( 3, $client->calls );
        $this->assertSame( 'DELETE', $client->calls[2]['method'] );
        $this->assertSame( '/invoices/in_5', $client->calls[2]['path'] );
    }

    public function testFinalizeFailureNotesManualCleanupWhenDeleteAlsoFails(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'body' => [ 'id' => 'in_6' ] ] ); // create
        $client->queue_response( [ 'body' => [] ] ); // add_lines
        $client->queue_response( [ 'ok' => false, 'status' => 400, 'error' => 'Nothing to invoice.' ] ); // finalize fails
        $client->queue_response( [ 'ok' => false, 'status' => 400, 'error' => 'Cannot delete.' ] ); // DELETE cleanup also fails

        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );
        $result  = $gateway->create_order( 'cus_1', $this->sample_line_items( 1 ), $this->sample_context() );

        $this->assertFalse( $result['ok'] );
        $this->assertTrue( strpos( $result['error'], 'Nothing to invoice.' ) === 0 );
        $this->assertTrue( strpos( $result['error'], 'manually' ) !== false, 'Original error must note manual cleanup may be needed.' );
    }
}
