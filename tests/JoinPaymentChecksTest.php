<?php
/**
 * More of online-join fulfillment's pure rules: when a completed-but-
 * unpaid checkout's bank debit has already failed (so sync() can close
 * the join without the async_payment_failed webhook), and which email
 * domains are public mailboxes rather than a firm's own.
 */
require_once dirname( __DIR__ ) . '/includes/join/class-join-orders-table.php';
require_once dirname( __DIR__ ) . '/includes/join/class-join-fulfillment.php';

class JoinPaymentChecksTest extends NJILGA_TestCase {

    /**
     * @param array<string,mixed>|null $pi
     */
    private function unpaid( ?array $pi ): array {
        return [
            'id'             => 'cs_live_1',
            'status'         => 'complete',
            'payment_status' => 'unpaid',
            'payment_intent' => $pi,
        ];
    }

    public function test_a_clearing_debit_has_not_failed(): void {
        $this->assertSame( null, MyNJILGA_Join_Fulfillment::bank_payment_failure( $this->unpaid( [ 'status' => 'processing' ] ) ) );
    }

    public function test_waiting_on_microdeposits_has_not_failed(): void {
        $this->assertSame( null, MyNJILGA_Join_Fulfillment::bank_payment_failure( $this->unpaid( [ 'status' => 'requires_action' ] ) ) );
    }

    public function test_without_the_payment_intent_nothing_is_decided(): void {
        $this->assertSame( null, MyNJILGA_Join_Fulfillment::bank_payment_failure( $this->unpaid( null ) ) );
    }

    public function test_a_refused_debit_has_failed_with_stripes_reason(): void {
        $r = MyNJILGA_Join_Fulfillment::bank_payment_failure( $this->unpaid( [
            'status'             => 'requires_payment_method',
            'last_payment_error' => [ 'code' => 'payment_method_provider_decline', 'message' => 'The account has insufficient funds.' ],
        ] ) );
        $this->assertSame( 'The account has insufficient funds.', $r );
    }

    public function test_a_canceled_payment_intent_has_failed(): void {
        $this->assertSame( '', MyNJILGA_Join_Fulfillment::bank_payment_failure( $this->unpaid( [ 'status' => 'canceled' ] ) ) );
    }

    public function test_a_payment_error_counts_only_once_the_debit_has_stopped(): void {
        $error = [ 'message' => 'Verification failed.' ];
        $this->assertSame( 'Verification failed.', MyNJILGA_Join_Fulfillment::bank_payment_failure( $this->unpaid( [ 'status' => 'requires_confirmation', 'last_payment_error' => $error ] ) ) );
        $this->assertSame( null, MyNJILGA_Join_Fulfillment::bank_payment_failure( $this->unpaid( [ 'status' => 'processing', 'last_payment_error' => $error ] ) ), 'A debit still clearing has not failed.' );
        $this->assertSame( null, MyNJILGA_Join_Fulfillment::bank_payment_failure( $this->unpaid( [ 'status' => 'requires_action', 'last_payment_error' => $error ] ) ), 'A wrong microdeposit guess can still be retried.' );
    }

    public function test_public_mail_providers_beyond_the_big_four(): void {
        foreach ( [ 'gmx.net', 'web.de', 'protonmail.ch', 'tutanota.com', 'aim.com', 'lawyer.com', 'privaterelay.appleid.com' ] as $d ) {
            $this->assertTrue( MyNJILGA_Join_Fulfillment::is_free_mail( $d ), $d . ' is a public provider' );
        }
    }

    public function test_country_domains_of_the_big_providers(): void {
        foreach ( [ 'yahoo.co.uk', 'hotmail.co.uk', 'hotmail.fr', 'yahoo.com.au', 'gmx.de', 'outlook.es', 'live.ca', 'yandex.kz' ] as $d ) {
            $this->assertTrue( MyNJILGA_Join_Fulfillment::is_free_mail( $d ), $d . ' is a public provider' );
        }
        $this->assertFalse( MyNJILGA_Join_Fulfillment::is_free_mail( 'yahoolaw.com' ), 'A firm whose name starts with a provider\'s is still a firm.' );
        $this->assertFalse( MyNJILGA_Join_Fulfillment::is_free_mail( 'outlooklegal.co.uk' ) );
    }

    public function test_regional_subdomains_count_as_their_provider(): void {
        $this->assertTrue( MyNJILGA_Join_Fulfillment::is_free_mail( 'nj.rr.com' ) );
        $this->assertTrue( MyNJILGA_Join_Fulfillment::is_free_mail( 'NYC.RR.COM.' ) );
        $this->assertFalse( MyNJILGA_Join_Fulfillment::is_free_mail( 'rr.com.smithlaw.com' ) );
        $this->assertFalse( MyNJILGA_Join_Fulfillment::is_free_mail( '' ) );
    }

    public function test_a_given_list_replaces_the_built_in_one(): void {
        $this->assertTrue( MyNJILGA_Join_Fulfillment::is_free_mail( 'shared-suite.com', [ 'shared-suite.com' ] ) );
        $this->assertFalse( MyNJILGA_Join_Fulfillment::is_free_mail( 'lawyer.com', [ 'shared-suite.com' ] ), 'A site may take a listed domain out.' );
        $this->assertTrue( MyNJILGA_Join_Fulfillment::is_free_mail( 'yahoo.fr', [] ), 'The country-domain families always apply.' );
    }
}
