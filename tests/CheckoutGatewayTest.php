<?php
/**
 * Unit tests for the Stripe gateway's checkout half
 * (MyNJILGA_Checkout_Gateway) — the Stripe Checkout Session an online
 * join pays through. Uses the same injected fake transport as
 * StripeGatewayTest; every setting create_checkout() would read from
 * MyNJILGA_Stripe_Connection is supplied on $context instead.
 */
require_once __DIR__ . '/StripeGatewayTest.php';

class CheckoutGatewayTest extends NJILGA_TestCase {

    private function lines(): array {
        return [
            [ 'title' => 'Zed Zulu — 2026 Professional Membership (1st Member)', 'unit_price_cents' => 12500, 'quantity' => 1, 'line_meta' => [] ],
            [ 'title' => 'Ann Adams — 2026 Professional Membership (no charge, Members 6+)', 'unit_price_cents' => 0, 'quantity' => 1, 'line_meta' => [] ],
        ];
    }

    private function context( array $overrides = [] ): array {
        return array_merge( [
            'join_id'        => 9,
            'attempt'        => 2,
            'dues_year'      => 2026,
            'customer_email' => 'zed@example.com',
            'success_url'    => 'https://njilga.test/join/?njilga_join=return&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'     => 'https://njilga.test/join/?njilga_join=cancel',
            'description'    => '2026 NJILGA Professional Membership — Zed Zulu',
            'company_name'   => 'Smith & Jones LLP',
            'allow_ach'      => true,
            'create_invoice' => true,
            'mode'           => 'test',
            'currency'       => 'usd',
            'footer'         => 'Thank you.',
            'key_salt'       => 'site1',
        ], $overrides );
    }

    public function test_create_checkout_builds_one_session_with_every_line(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'body' => [ 'id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'expires_at' => 1790000000 ] ] );

        $r = ( new MyNJILGA_Stripe_Invoice_Gateway( $client ) )->create_checkout( $this->lines(), $this->context() );

        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'cs_test_1', $r['session_id'] );
        $this->assertTrue( $r['ach_offered'] );
        $this->assertCount( 1, $client->calls );

        $call = $client->calls[0];
        $p    = $call['params'];
        $this->assertSame( 'POST', $call['method'] );
        $this->assertSame( '/checkout/sessions', $call['path'] );
        $this->assertSame( 'njilga-join-site1-9-2-test', $call['opts']['idempotency_key'] );
        $this->assertSame( 'payment', $p['mode'] );
        $this->assertSame( [ 'card', 'us_bank_account' ], $p['payment_method_types'] );
        $this->assertSame( 'always', $p['customer_creation'] );
        $this->assertSame( 12500, $p['line_items'][0]['price_data']['unit_amount'] );
        $this->assertSame( 0, $p['line_items'][1]['price_data']['unit_amount'], '$0 colleagues stay on the receipt.' );
        $this->assertSame( 9, $p['metadata']['njilga_join_id'] );
        $this->assertSame( 'my-njilga-join', $p['metadata']['source'], 'Never the orphan-scan source.' );
        $this->assertSame( $p['metadata'], $p['payment_intent_data']['metadata'] );
        $this->assertTrue( $p['invoice_creation']['enabled'] );
        $this->assertSame( $p['metadata'], $p['invoice_creation']['invoice_data']['metadata'] );
        $this->assertSame( 'Firm', $p['invoice_creation']['invoice_data']['custom_fields'][0]['name'] );
        // The template placeholder must survive untouched for Stripe to fill in.
        $this->assertTrue( strpos( $p['success_url'], '{CHECKOUT_SESSION_ID}' ) !== false );
    }

    public function test_ach_rejection_retries_card_only_with_a_fresh_key(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'ok' => false, 'status' => 400, 'error' => 'The payment method type provided: us_bank_account is invalid.' ] );
        $client->queue_response( [ 'body' => [ 'id' => 'cs_test_2', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_2' ] ] );

        $r = ( new MyNJILGA_Stripe_Invoice_Gateway( $client ) )->create_checkout( $this->lines(), $this->context() );

        $this->assertTrue( $r['ok'] );
        $this->assertFalse( $r['ach_offered'] );
        $this->assertCount( 2, $client->calls );
        $this->assertSame( [ 'card' ], $client->calls[1]['params']['payment_method_types'] );
        $this->assertSame( 'njilga-join-site1-9-2-test-card', $client->calls[1]['opts']['idempotency_key'] );
    }

    public function test_other_failures_do_not_retry(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'ok' => false, 'status' => 400, 'error' => 'Invalid email address.' ] );

        $r = ( new MyNJILGA_Stripe_Invoice_Gateway( $client ) )->create_checkout( $this->lines(), $this->context() );

        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'Invalid email address.', $r['error'] );
        $this->assertCount( 1, $client->calls );
    }

    public function test_invoice_creation_is_optional(): void {
        $client = new FakeStripeClientForGatewayTest();
        $client->queue_response( [ 'body' => [ 'id' => 'cs_test_3', 'url' => 'https://checkout.stripe.com/x' ] ] );

        ( new MyNJILGA_Stripe_Invoice_Gateway( $client ) )->create_checkout( $this->lines(), $this->context( [ 'create_invoice' => false, 'allow_ach' => false ] ) );

        $p = $client->calls[0]['params'];
        $this->assertFalse( isset( $p['invoice_creation'] ) );
        $this->assertSame( [ 'card' ], $p['payment_method_types'] );
        $this->assertSame( 'njilga-join-site1-9-2-test-card', $client->calls[0]['opts']['idempotency_key'] );
    }

    public function test_refuses_without_a_join_id_or_lines(): void {
        $client  = new FakeStripeClientForGatewayTest();
        $gateway = new MyNJILGA_Stripe_Invoice_Gateway( $client );

        $this->assertFalse( $gateway->create_checkout( $this->lines(), $this->context( [ 'join_id' => 0 ] ) )['ok'] );
        $this->assertFalse( $gateway->create_checkout( [], $this->context() )['ok'] );
        $this->assertCount( 0, $client->calls );
    }

    public function test_normalize_checkout_reads_expanded_and_bare_references(): void {
        $expanded = MyNJILGA_Stripe_Invoice_Gateway::normalize_checkout( [
            'id'               => 'cs_1',
            'status'           => 'complete',
            'payment_status'   => 'paid',
            'amount_total'     => 20000,
            'currency'         => 'USD',
            'livemode'         => true,
            'customer'         => 'cus_1',
            'customer_details' => [ 'email' => 'zed@example.com' ],
            'metadata'         => [ 'njilga_join_id' => '9' ],
            'payment_intent'   => [ 'id' => 'pi_1', 'latest_charge' => [ 'id' => 'ch_1' ] ],
            'invoice'          => [ 'id' => 'in_1', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/1' ],
        ] );
        $this->assertSame( 'pi_1', $expanded['payment_intent_id'] );
        $this->assertSame( 'ch_1', $expanded['payment_intent']['latest_charge']['id'] );
        $this->assertSame( 'in_1', $expanded['invoice_id'] );
        $this->assertSame( 'usd', $expanded['currency'] );
        $this->assertTrue( $expanded['livemode'] );

        $bare = MyNJILGA_Stripe_Invoice_Gateway::normalize_checkout( [ 'id' => 'cs_2', 'payment_intent' => 'pi_2', 'invoice' => null ] );
        $this->assertSame( 'pi_2', $bare['payment_intent_id'] );
        $this->assertSame( null, $bare['payment_intent'] );
        $this->assertSame( '', $bare['invoice_id'] );
    }
}
