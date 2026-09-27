<?php
/**
 * The pure parts of online-join fulfillment and its view: whether a
 * Checkout Session may be trusted as a join's payment, when two firm
 * names are one firm, which email domains prove nothing, and the upsell
 * copy built from a category's tiers.
 */
require_once dirname( __DIR__ ) . '/includes/join/class-join-orders-table.php';
require_once dirname( __DIR__ ) . '/includes/join/class-join-fulfillment.php';
require_once dirname( __DIR__ ) . '/includes/join/class-join-view.php';

class JoinFulfillmentTest extends NJILGA_TestCase {

    private function join( array $overrides = [] ): object {
        return (object) array_merge( [
            'id'                => 9,
            'stripe_session_id' => 'cs_live_1',
            'livemode'          => 1,
            'total_cents'       => 20000,
            'currency'          => 'usd',
        ], $overrides );
    }

    private function session( array $overrides = [] ): array {
        return array_merge( [
            'id'             => 'cs_live_1',
            'status'         => 'complete',
            'payment_status' => 'paid',
            'amount_total'   => 20000,
            'currency'       => 'usd',
            'livemode'       => true,
            'metadata'       => [ 'njilga_join_id' => '9' ],
        ], $overrides );
    }

    public function test_a_matching_paid_session_verifies(): void {
        $this->assertSame( '', MyNJILGA_Join_Fulfillment::verify_session( $this->join(), $this->session() ) );
    }

    public function test_session_must_be_the_one_on_file_for_this_join(): void {
        $this->assertTrue( MyNJILGA_Join_Fulfillment::verify_session( $this->join(), $this->session( [ 'id' => 'cs_live_2' ] ) ) !== '' );
        $this->assertTrue( MyNJILGA_Join_Fulfillment::verify_session( $this->join(), $this->session( [ 'metadata' => [ 'njilga_join_id' => '10' ] ] ) ) !== '' );
    }

    public function test_mode_amount_and_currency_must_match(): void {
        $this->assertTrue( MyNJILGA_Join_Fulfillment::verify_session( $this->join(), $this->session( [ 'livemode' => false ] ) ) !== '', 'A test-mode payment never settles a live join.' );
        $this->assertTrue( strpos( MyNJILGA_Join_Fulfillment::verify_session( $this->join(), $this->session( [ 'amount_total' => 12500 ] ) ), '$125.00' ) !== false );
        $this->assertTrue( MyNJILGA_Join_Fulfillment::verify_session( $this->join(), $this->session( [ 'currency' => 'eur' ] ) ) !== '' );
    }

    public function test_an_open_session_is_not_held_to_the_amount(): void {
        $this->assertSame( '', MyNJILGA_Join_Fulfillment::verify_session( $this->join(), $this->session( [ 'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 0 ] ) ) );
    }

    public function test_firm_key_ignores_case_punctuation_and_entity_suffix(): void {
        $a = MyNJILGA_Join_Fulfillment::firm_key( 'Smith & Jones, LLP' );
        $this->assertSame( $a, MyNJILGA_Join_Fulfillment::firm_key( 'smith and jones llp' ) );
        $this->assertSame( $a, MyNJILGA_Join_Fulfillment::firm_key( 'The Smith and Jones' ) );
        $this->assertSame( 'smith and jones', $a );
        $this->assertTrue( MyNJILGA_Join_Fulfillment::firm_key( 'Smith & Brown LLP' ) !== $a, 'A different firm stays different.' );
        $this->assertSame( MyNJILGA_Join_Fulfillment::firm_key( 'Doe, P.C.' ), MyNJILGA_Join_Fulfillment::firm_key( 'Doe PC' ) );
    }

    public function test_free_mail_domains_never_prove_a_firm(): void {
        $this->assertTrue( MyNJILGA_Join_Fulfillment::is_free_mail( 'gmail.com' ) );
        $this->assertTrue( MyNJILGA_Join_Fulfillment::is_free_mail( 'Outlook.com' ) );
        $this->assertFalse( MyNJILGA_Join_Fulfillment::is_free_mail( 'smithjones.com' ) );
        $this->assertSame( 'smithjones.com', MyNJILGA_Join_Fulfillment::email_domain( 'Ann@SmithJones.com' ) );
        $this->assertSame( '', MyNJILGA_Join_Fulfillment::email_domain( 'not-an-email' ) );
    }

    public function test_upsell_copy_follows_the_tiers(): void {
        $ladder = [
            [ 'from' => 1, 'to' => 1, 'price_cents' => 12500, 'label' => '1st Member' ],
            [ 'from' => 2, 'to' => 5, 'price_cents' => 7500, 'label' => 'Members 2–5' ],
            [ 'from' => 6, 'to' => 0, 'price_cents' => 0, 'label' => 'Members 6+' ],
        ];
        $this->assertSame( 'Members 2–5 from your firm join for $75 each; beyond 5, additional colleagues join free.', MyNJILGA_Join_View::ladder_sentence( $ladder ) );
        $this->assertSame( '', MyNJILGA_Join_View::ladder_sentence( [ $ladder[0] ] ) );
    }

    public function test_money_and_coverage_labels(): void {
        $this->assertSame( '$125', MyNJILGA_Join_View::dollars( 12500 ) );
        $this->assertSame( '$12.50', MyNJILGA_Join_View::dollars( 1250 ) );
        $this->assertSame( '2026 membership', MyNJILGA_Join_View::covers( 2026, 2026 ) );
        $this->assertSame( 'Rest of 2026 and all of 2027', MyNJILGA_Join_View::covers( 2027, 2026 ) );
    }
}
