<?php
/**
 * Unit tests for MyNJILGA_Stripe_Webhook::verify_signature() — the pure,
 * WordPress-free half of the Stripe webhook receiver (spec: Stripe
 * migration phase 3). Loaded directly (not via the plugin's require_once
 * block) because the class file must be requireable without WordPress:
 * this test proves that boundary holds, the same way
 * StripeParamEncoderTest.php and StripeGatewayTest.php do for their own
 * pure/injectable seams.
 *
 * handle()/process_event()/register() are NOT exercised here — they call
 * WordPress functions (register_rest_route, $wpdb, current_time, ...)
 * and need a full WP bootstrap, out of scope for this dependency-free
 * runner. Merely requiring the class file is safe even though those
 * methods reference WordPress classes/functions in their bodies — PHP
 * only resolves that when the method actually runs, and none of these
 * tests call them.
 */
require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-webhook.php';

class WebhookSignatureTest extends NJILGA_TestCase {

    private const SECRET  = 'whsec_test_secret_abc123';
    private const PAYLOAD = '{"id":"evt_123","type":"invoice.paid","livemode":false,"data":{"object":{"id":"in_123"}}}';

    private function sign( string $payload, int $timestamp, string $secret = self::SECRET ): string {
        return hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
    }

    // -------------------------------------------------------------------
    // A valid signature passes.
    // -------------------------------------------------------------------

    public function testValidSignaturePasses(): void {
        $now    = 1700000000;
        $sig    = $this->sign( self::PAYLOAD, $now );
        $header = 't=' . $now . ',v1=' . $sig;

        $this->assertTrue(
            MyNJILGA_Stripe_Webhook::verify_signature( self::PAYLOAD, $header, self::SECRET, $now )
        );
    }

    // -------------------------------------------------------------------
    // A tampered payload (same signature) fails.
    // -------------------------------------------------------------------

    public function testTamperedPayloadFails(): void {
        $now    = 1700000000;
        $sig    = $this->sign( self::PAYLOAD, $now );
        $header = 't=' . $now . ',v1=' . $sig;

        $tampered = str_replace( 'in_123', 'in_456', self::PAYLOAD );

        $this->assertFalse(
            MyNJILGA_Stripe_Webhook::verify_signature( $tampered, $header, self::SECRET, $now )
        );
    }

    // -------------------------------------------------------------------
    // A signature computed with the wrong secret fails.
    // -------------------------------------------------------------------

    public function testWrongSecretFails(): void {
        $now    = 1700000000;
        $sig    = $this->sign( self::PAYLOAD, $now, 'whsec_totally_different_secret' );
        $header = 't=' . $now . ',v1=' . $sig;

        $this->assertFalse(
            MyNJILGA_Stripe_Webhook::verify_signature( self::PAYLOAD, $header, self::SECRET, $now )
        );
    }

    // -------------------------------------------------------------------
    // A validly-computed signature outside the 300s window fails
    // (replay protection) — even though the HMAC itself checks out.
    // -------------------------------------------------------------------

    public function testStaleTimestampFailsEvenWithValidSignature(): void {
        $eventTime = 1700000000;
        $sig       = $this->sign( self::PAYLOAD, $eventTime );
        $header    = 't=' . $eventTime . ',v1=' . $sig;

        $now = $eventTime + 600; // 10 minutes later — outside the 300s window.

        $this->assertFalse(
            MyNJILGA_Stripe_Webhook::verify_signature( self::PAYLOAD, $header, self::SECRET, $now )
        );
    }

    // -------------------------------------------------------------------
    // A header with ONLY a v0= scheme (no v1= at all) never falls back
    // to it.
    // -------------------------------------------------------------------

    public function testOnlyV0SchemeNeverFallsBack(): void {
        $now = 1700000000;
        // A "correct" v0 signature (Stripe's older, unsalted scheme) —
        // even matching it must never substitute for a missing v1.
        $v0     = hash_hmac( 'sha256', self::PAYLOAD, self::SECRET );
        $header = 't=' . $now . ',v0=' . $v0;

        $this->assertFalse(
            MyNJILGA_Stripe_Webhook::verify_signature( self::PAYLOAD, $header, self::SECRET, $now )
        );
    }

    // -------------------------------------------------------------------
    // Multiple v1= values (a signing-secret rotation window) — only the
    // SECOND one is correct, and that's enough to pass.
    // -------------------------------------------------------------------

    public function testAnyMatchingV1AmongMultipleSucceeds(): void {
        $now = 1700000000;

        $wrongSig   = $this->sign( self::PAYLOAD, $now, 'whsec_previous_rotated_secret' );
        $correctSig = $this->sign( self::PAYLOAD, $now );

        $header = 't=' . $now . ',v1=' . $wrongSig . ',v1=' . $correctSig;

        $this->assertTrue(
            MyNJILGA_Stripe_Webhook::verify_signature( self::PAYLOAD, $header, self::SECRET, $now )
        );
    }

    // -------------------------------------------------------------------
    // Missing t= or missing v1= entirely both fail outright.
    // -------------------------------------------------------------------

    public function testMissingTimestampFails(): void {
        $now    = 1700000000;
        $sig    = $this->sign( self::PAYLOAD, $now );
        $header = 'v1=' . $sig; // no t=

        $this->assertFalse(
            MyNJILGA_Stripe_Webhook::verify_signature( self::PAYLOAD, $header, self::SECRET, $now )
        );
    }

    public function testMissingV1Fails(): void {
        $now    = 1700000000;
        $header = 't=' . $now; // no v1= at all

        $this->assertFalse(
            MyNJILGA_Stripe_Webhook::verify_signature( self::PAYLOAD, $header, self::SECRET, $now )
        );
    }
}
