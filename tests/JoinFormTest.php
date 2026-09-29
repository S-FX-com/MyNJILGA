<?php
/**
 * The pure parts of the join form's controller and its upload limits:
 * the next-year cutover test, spotting a POST whose body PHP threw away
 * for being over post_max_size, how an upload limit is stated to people,
 * which nonces are bound to the visitor's cookie, and which Stripe mode a
 * join runs in.
 */
require_once dirname( __DIR__ ) . '/includes/join/class-join-form.php';
require_once dirname( __DIR__ ) . '/includes/join/class-join-documents.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-connection.php';

class JoinFormTest extends NJILGA_TestCase {

    public function test_cutover_applies_from_its_own_day_on(): void {
        $this->assertTrue( MyNJILGA_Join_Form::past_cutover( '10-01', '10-01' ) );
        $this->assertTrue( MyNJILGA_Join_Form::past_cutover( '12-31', '10-01' ) );
        $this->assertFalse( MyNJILGA_Join_Form::past_cutover( '09-30', '10-01' ) );
        $this->assertFalse( MyNJILGA_Join_Form::past_cutover( '01-01', '10-01' ), 'a new calendar year starts before the cutover again' );
    }

    public function test_no_or_malformed_cutover_means_none(): void {
        $this->assertFalse( MyNJILGA_Join_Form::past_cutover( '12-31', '' ) );
        $this->assertFalse( MyNJILGA_Join_Form::past_cutover( '12-31', '13-01' ) );
        $this->assertFalse( MyNJILGA_Join_Form::past_cutover( '12-31', '10/01' ) );
        $this->assertFalse( MyNJILGA_Join_Form::past_cutover( '12-31', '2026-10-01' ) );
    }

    public function test_an_emptied_oversized_post_is_spotted(): void {
        $this->assertTrue( MyNJILGA_Join_Form::body_exceeded_limit( 'POST', true, 9000000, 8388608 ) );
    }

    public function test_ordinary_requests_are_not_mistaken_for_oversized_posts(): void {
        $this->assertFalse( MyNJILGA_Join_Form::body_exceeded_limit( 'GET', true, 9000000, 8388608 ), 'not a POST' );
        $this->assertFalse( MyNJILGA_Join_Form::body_exceeded_limit( 'POST', false, 9000000, 8388608 ), 'PHP kept the body' );
        $this->assertFalse( MyNJILGA_Join_Form::body_exceeded_limit( 'POST', true, 8388608, 8388608 ), 'exactly at the limit is allowed' );
        $this->assertFalse( MyNJILGA_Join_Form::body_exceeded_limit( 'POST', true, 0, 8388608 ), 'an empty POST' );
        $this->assertFalse( MyNJILGA_Join_Form::body_exceeded_limit( 'POST', true, 9000000, 0 ), 'post_max_size = 0 means no limit' );
    }

    public function test_upload_limits_read_as_people_would_write_them(): void {
        $this->assertSame( '8 MB', MyNJILGA_Join_Documents::size_label( 8388608 ) );
        $this->assertSame( '2 MB', MyNJILGA_Join_Documents::size_label( 2097152 ) );
        $this->assertSame( '10 MB', MyNJILGA_Join_Documents::size_label( 10485760 ) );
        $this->assertSame( '1.5 MB', MyNJILGA_Join_Documents::size_label( 1572864 ) );
        $this->assertSame( '512 KB', MyNJILGA_Join_Documents::size_label( 524288 ) );
    }

    public function test_upload_limit_label_never_promises_more_than_is_accepted(): void {
        // 1.99 MB must not be rounded up to "2 MB".
        $this->assertSame( '1.9 MB', MyNJILGA_Join_Documents::size_label( 2097151 ) );
        $this->assertSame( '1 MB', MyNJILGA_Join_Documents::size_label( 1048576 + 1000 ) );
    }

    public function test_only_the_visitor_form_nonces_are_bound_to_the_visitor(): void {
        // The code AJAX calls and anyone else's nonces keep WordPress's
        // shared logged-out nonce; nothing here needs WordPress to decide.
        $this->assertSame( 0, MyNJILGA_Join_Form::visitor_nonce_uid( 0, MyNJILGA_Join_Form::NONCE_ACTION . '_code' ) );
        $this->assertSame( 0, MyNJILGA_Join_Form::visitor_nonce_uid( 0, 'woocommerce-process_checkout' ) );
        $this->assertSame( 0, MyNJILGA_Join_Form::visitor_nonce_uid( 0, MyNJILGA_Join_Form::NONCE_ACTION ) );
        $this->assertSame( 0, MyNJILGA_Join_Form::visitor_nonce_uid( 0, -1 ) );
        $this->assertSame( 0, MyNJILGA_Join_Form::visitor_nonce_uid( 0, MyNJILGA_Join_Form::NONCE_ACTION . '_resume' ), 'signed-in only; its nonce is per-user anyway' );
    }

    public function test_joins_follow_the_mode_active_in_settings(): void {
        $test = MyNJILGA_Stripe_Connection::MODE_TEST;
        $live = MyNJILGA_Stripe_Connection::MODE_LIVE;
        $this->assertSame( $test, MyNJILGA_Join_Form::mode_for( $test, false ), 'a site switched to Test takes every join in Test' );
        $this->assertSame( $live, MyNJILGA_Join_Form::mode_for( $live, false ) );
    }

    public function test_staff_can_still_rehearse_in_test_while_the_site_is_live(): void {
        $test = MyNJILGA_Stripe_Connection::MODE_TEST;
        $this->assertSame( $test, MyNJILGA_Join_Form::mode_for( MyNJILGA_Stripe_Connection::MODE_LIVE, true ) );
        $this->assertSame( $test, MyNJILGA_Join_Form::mode_for( $test, true ) );
    }
}
