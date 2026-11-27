<?php
/**
 * The short link to a firm's Stripe invoice: the token (row id + signature),
 * what it parses back to, and where a link is allowed to send a visitor.
 */
require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-short-link.php';

class InvoiceShortLinkTest extends NJILGA_TestCase {

    public function test_a_token_round_trips_to_its_row(): void {
        foreach ( [ 1, 7, 35, 36, 1234, 987654321 ] as $id ) {
            $token = MyNJILGA_Invoice_Short_Link::make_token( $id, 'secret-a' );
            $this->assertSame( $id, MyNJILGA_Invoice_Short_Link::parse_token( $token, 'secret-a' ), "row $id" );
        }
    }

    public function test_a_token_is_short(): void {
        $this->assertTrue( strlen( MyNJILGA_Invoice_Short_Link::make_token( 1234, 'secret-a' ) ) <= 16 );
    }

    public function test_a_token_from_another_site_or_row_does_not_work(): void {
        $token = MyNJILGA_Invoice_Short_Link::make_token( 42, 'secret-a' );
        $this->assertSame( 0, MyNJILGA_Invoice_Short_Link::parse_token( $token, 'secret-b' ), 'another site\'s salt' );
        [ , $sig ] = explode( '-', $token );
        $this->assertSame( 0, MyNJILGA_Invoice_Short_Link::parse_token( '43-' . $sig, 'secret-a' ), 'a different row, same signature' );
    }

    public function test_a_malformed_token_is_refused(): void {
        foreach ( [ '', '16', '16-', '-abcdef0123', '16-xyz', 'g!-abcdef0123', '0-abcdef0123', '16-abcdef012', '16-abcdef01234', "16-abcdef0123\n" ] as $bad ) {
            $this->assertSame( 0, MyNJILGA_Invoice_Short_Link::parse_token( $bad, 'secret-a' ), json_encode( $bad ) );
        }
    }

    public function test_it_only_sends_a_visitor_to_https(): void {
        $this->assertSame( 'https://invoice.stripe.com/i/acct_1/live_2', MyNJILGA_Invoice_Short_Link::destination( (object) [ 'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_2' ] ) );
        $this->assertSame( '', MyNJILGA_Invoice_Short_Link::destination( (object) [ 'hosted_invoice_url' => 'http://example.com/x' ] ) );
        $this->assertSame( '', MyNJILGA_Invoice_Short_Link::destination( (object) [ 'hosted_invoice_url' => 'javascript:alert(1)' ] ) );
        $this->assertSame( '', MyNJILGA_Invoice_Short_Link::destination( (object) [ 'hosted_invoice_url' => null ] ) );
        $this->assertSame( '', MyNJILGA_Invoice_Short_Link::destination( null ), 'a row that no longer exists' );
    }
}
