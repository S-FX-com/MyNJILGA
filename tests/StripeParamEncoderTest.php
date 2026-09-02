<?php
/**
 * Unit tests for MyNJILGA_Stripe_Client::encode_params() — the pure,
 * hand-written Stripe bracket-notation form encoder. Loaded directly
 * (not via the plugin's require_once block) because the class must be
 * requireable without WordPress: this test proves that boundary holds.
 *
 * request() itself is NOT tested here — it calls wp_remote_request() and
 * needs network mocking, which is out of scope for this pure-function
 * test file.
 */
require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-client.php';

class StripeParamEncoderTest extends NJILGA_TestCase {

    public function testFlatScalar(): void {
        $this->assertSame(
            'customer=cus_123',
            MyNJILGA_Stripe_Client::encode_params( [ 'customer' => 'cus_123' ] )
        );
    }

    public function testNestedMetadataObject(): void {
        $this->assertSame(
            'metadata%5Bnjilga_row_id%5D=42',
            MyNJILGA_Stripe_Client::encode_params( [ 'metadata' => [ 'njilga_row_id' => '42' ] ] )
        );
    }

    public function testArrayOfObjectsPreservesIndexOrder(): void {
        $params = [
            'lines' => [
                [ 'amount' => 12500, 'description' => 'Ann Brown' ],
                [ 'amount' => 7500, 'description' => 'Bob Carter' ],
            ],
        ];
        $this->assertSame(
            'lines%5B0%5D%5Bamount%5D=12500&lines%5B0%5D%5Bdescription%5D=Ann%20Brown'
            . '&lines%5B1%5D%5Bamount%5D=7500&lines%5B1%5D%5Bdescription%5D=Bob%20Carter',
            MyNJILGA_Stripe_Client::encode_params( $params )
        );
    }

    /**
     * Three levels deep, through an array of objects — the actual shape
     * of the gateway's add_lines payload, where every line carries its
     * own metadata map (lines[n][metadata][k]). Nested keys stay bracketed
     * and index order is preserved all the way down.
     */
    public function testThreeLevelNestingThroughArrayOfObjects(): void {
        $params = [
            'lines' => [
                [ 'amount' => 12500, 'metadata' => [ 'njilga_contact_id' => 101, 'njilga_kind' => 'dues' ] ],
                [ 'amount' => 20000, 'metadata' => [ 'njilga_contact_id' => 102, 'njilga_kind' => 'assessment' ] ],
            ],
        ];
        $this->assertSame(
            'lines%5B0%5D%5Bamount%5D=12500'
            . '&lines%5B0%5D%5Bmetadata%5D%5Bnjilga_contact_id%5D=101'
            . '&lines%5B0%5D%5Bmetadata%5D%5Bnjilga_kind%5D=dues'
            . '&lines%5B1%5D%5Bamount%5D=20000'
            . '&lines%5B1%5D%5Bmetadata%5D%5Bnjilga_contact_id%5D=102'
            . '&lines%5B1%5D%5Bmetadata%5D%5Bnjilga_kind%5D=assessment',
            MyNJILGA_Stripe_Client::encode_params( $params )
        );
    }

    public function testBooleansEncodeAsLiteralStrings(): void {
        $this->assertSame(
            'archived=true&active=false',
            MyNJILGA_Stripe_Client::encode_params( [ 'archived' => true, 'active' => false ] )
        );
    }

    public function testNullValueIsOmittedEntirely(): void {
        $this->assertSame(
            'customer=cus_123',
            MyNJILGA_Stripe_Client::encode_params( [ 'customer' => 'cus_123', 'coupon' => null ] )
        );
    }

    public function testExpandArray(): void {
        $this->assertSame(
            'expand%5B0%5D=data.payment_intent',
            MyNJILGA_Stripe_Client::encode_params( [ 'expand' => [ 'data.payment_intent' ] ] )
        );
    }

    public function testValueCharactersAreEscapedPerValue(): void {
        // Proves rawurlencode() is actually applied to each value: '&' and
        // ' ' inside a description must not leak into the query string
        // structure.
        $this->assertSame(
            'description=Smith%20%26%20Jones%2C%20LLP',
            MyNJILGA_Stripe_Client::encode_params( [ 'description' => 'Smith & Jones, LLP' ] )
        );
    }

    public function testIntegersAndFloatsStringifyNormally(): void {
        $this->assertSame(
            'amount=12500&rate=1.5',
            MyNJILGA_Stripe_Client::encode_params( [ 'amount' => 12500, 'rate' => 1.5 ] )
        );
    }
}
