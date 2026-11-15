<?php
/**
 * The HTML invoice email (MyNJILGA_Invoice_Email::build_html()) and its
 * invoice lines (MyNJILGA_Dues_Roster::email_rows()): everything a firm or
 * member typed is escaped, the button goes to the real payment link (and
 * nowhere when there isn't one yet), and the summary carries every charge.
 */
declare( strict_types=1 );

class InvoiceEmailHtmlTest extends NJILGA_TestCase {

    private function input( array $over = [] ): array {
        return $over + [
            'is_assessment' => false, 'year' => 2027, 'firm' => 'Law Office of Eileen Fahey', 'total' => '$325.00',
            'name' => 'Eileen K. Fahey', 'first_name' => 'Eileen', 'assessment_label' => 'Trustee Dinner Assessment',
            'covers' => '', 'link' => 'https://invoice.stripe.com/i/acct_1/test_abc?s=ap', 'remittance' => "NJILGA\nPO Box 1\nTrenton, NJ 08601",
            'subject_tpl' => '', 'intro_tpl' => '', 'closing_tpl' => '', 'heading_tpl' => '', 'button_tpl' => '',
            'rows' => [
                [ 'name' => 'Eileen K. Fahey', 'item' => 'Professional Membership (1st Member)', 'amount' => '$125.00' ],
                [ 'name' => '', 'item' => 'Trustee Dinner Assessment (Trustee)', 'amount' => '$200.00' ],
            ],
            'covers_note' => 'Paying this invoice marks everyone listed above as current for 2027.',
            'invoice_number' => 'NJILGA-2217', 'due_date' => 'December 31, 2026',
            'logo_url' => 'https://www.njilga.org/logo.png', 'site_name' => 'NJILGA', 'site_url' => 'https://www.njilga.org/',
        ];
    }

    private function has( string $needle, string $html, string $why = '' ): void {
        $this->assertTrue( strpos( $html, $needle ) !== false, ( $why !== '' ? $why . ': ' : '' ) . "expected \"$needle\"" );
    }

    public function testTheEmailCarriesTheInvoiceAndThePayButton(): void {
        $h = MyNJILGA_Invoice_Email::build_html( $this->input() );
        $this->has( 'NJILGA Membership Dues – Action Required', $h, 'default heading' );
        $this->has( 'Hi Eileen K. Fahey,', $h, 'default opening' );
        $this->has( 'Law Office of Eileen Fahey', $h );
        $this->has( 'Invoice NJILGA-2217 &middot; Due December 31, 2026', $h );
        $this->has( 'Professional Membership (1st Member)', $h );
        $this->has( 'Trustee Dinner Assessment (Trustee)', $h );
        $this->has( '$325.00', $h );
        $this->has( 'href="https://invoice.stripe.com/i/acct_1/test_abc?s=ap"', $h, 'the button opens the Stripe invoice' );
        $this->has( '>Pay My Dues Online</a>', $h );
        $this->has( 'Prefer to pay by check?', $h );
        $this->has( 'PO Box 1<br', $h, 'the address keeps its lines' );
        $this->has( 'src="https://www.njilga.org/logo.png"', $h );
    }

    /** The header image spans the email; without one, the site's name stands in. */
    public function testTheHeaderImageSpansTheEmail(): void {
        $h = MyNJILGA_Invoice_Email::build_html( $this->input() );
        $this->has( 'width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;border-radius:8px 8px 0 0;"', $h, 'full width, the card\'s top corners' );
        $this->has( 'alt="NJILGA"', $h );

        $test = MyNJILGA_Invoice_Email::build_html( $this->input( [ 'banner' => 'TEST — not sent to the firm' ] ) );
        $this->has( 'border-radius:0;"', $test, 'square under the TEST banner' );

        $none = MyNJILGA_Invoice_Email::build_html( $this->input( [ 'logo_url' => '' ] ) );
        $this->assertTrue( strpos( $none, '<img' ) === false, 'no image' );
        $this->has( 'letter-spacing:1px;color:#1d2b4f;', $none, 'the site name in text' );
    }

    /** Blank on the Invoice Email tab = NJILGA's header image; a URL set there wins. */
    public function testTheHeaderImageDefaultsToNjilgas(): void {
        $this->assertSame( MyNJILGA_Invoice_Email::DEFAULT_HEADER_IMAGE, MyNJILGA_Invoice_Sender::logo_url( [ 'logo_url' => '' ] ) );
        $this->assertSame( MyNJILGA_Invoice_Email::DEFAULT_HEADER_IMAGE, MyNJILGA_Invoice_Sender::logo_url( [ 'logo_url' => '   ' ] ) );
        $this->assertSame( 'https://njilga.org/wp-content/uploads/2026/06/fallback_image_njilga.png', MyNJILGA_Invoice_Email::DEFAULT_HEADER_IMAGE );
        $this->assertSame( 'https://njilga.org/custom.png', MyNJILGA_Invoice_Sender::logo_url( [ 'logo_url' => 'https://njilga.org/custom.png' ] ) );
    }

    public function testEverythingTypedIsEscaped(): void {
        $h = MyNJILGA_Invoice_Email::build_html( $this->input( [
            'firm' => 'Evil <script>alert(1)</script> LLP',
            'rows' => [ [ 'name' => '<img src=x onerror=alert(1)>', 'item' => 'Dues', 'amount' => '$1.00' ] ],
            'intro_tpl' => "Hello {first_name} <b>there</b>",
        ] ) );
        $this->assertTrue( strpos( $h, '<script>' ) === false, 'no raw script tag' );
        $this->assertTrue( strpos( $h, '<img src=x' ) === false, 'no raw img from a name' );
        $this->assertTrue( strpos( $h, '<b>there</b>' ) === false, 'staff wording is text, not markup' );
        $this->has( 'Hello Eileen &lt;b&gt;there&lt;/b&gt;', $h );
    }

    /** A preview before the invoice exists has no payment link — the button must not point at the placeholder text. */
    public function testNoLinkNoButtonTarget(): void {
        $h = MyNJILGA_Invoice_Email::build_html( $this->input( [ 'link' => MyNJILGA_Invoice_Email::PLACEHOLDER_LINK ] ) );
        $this->assertTrue( strpos( $h, '<a href="[' ) === false );
        $this->assertTrue( preg_match( '#<a href="https://invoice#', $h ) === 0 );
        $this->has( 'the payment link appears here', $h );
    }

    public function testCustomWordingAndBanner(): void {
        $h = MyNJILGA_Invoice_Email::build_html( $this->input( [
            'heading_tpl' => '{year} Dues for {firm}',
            'button_tpl'  => 'Pay {total} now',
            'intro_tpl'   => "Colleagues,\n\nDues are:\n- **Professional:** \$125\n- Members 2–5: \$75",
            'banner'      => "*** TEST — sent only to me@x.test ***",
        ] ) );
        $this->has( '2027 Dues for Law Office of Eileen Fahey', $h );
        $this->has( '>Pay $325.00 now</a>', $h );
        $this->has( '<strong>Professional:</strong>', $h );
        $this->has( '<ul', $h );
        $this->has( 'TEST — sent only to me@x.test', $h );
    }

    public function testAnAssessmentInvoiceSaysSo(): void {
        $h = MyNJILGA_Invoice_Email::build_html( $this->input( [ 'is_assessment' => true ] ) );
        $this->has( 'NJILGA Trustee Dinner Assessment – Action Required', $h );
        $this->has( '>Pay Online</a>', $h );
    }

    public function testEmailRowsListEveryCharge(): void {
        $members = [
            [ 'name' => 'Eileen K. Fahey', 'category_label' => 'Professional Membership', 'tier_label' => '1st Member', 'dues_cents' => 12500, 'assessment_cents' => 20000, 'assessment_label' => 'Trustee Dinner Assessment', 'assessment_qualifier' => 'Trustee' ],
            [ 'name' => 'Pat Past', 'category_label' => 'Past President Membership (Exempt)', 'dues_cents' => 0, 'assessment_cents' => 0, 'unbilled_reason' => '', 'dues_note' => '' ],
        ];
        $r = MyNJILGA_Dues_Roster::email_rows( $members, 2027 );
        $this->assertCount( 3, $r['rows'] );
        $this->assertSame( [ 'name' => 'Eileen K. Fahey', 'item' => 'Professional Membership (1st Member)', 'amount' => '$125.00' ], $r['rows'][0] );
        $this->assertSame( '', $r['rows'][1]['name'], 'the same person\'s second charge' );
        $this->assertSame( 'Pat Past', $r['rows'][2]['name'] );
        $this->assertTrue( strpos( $r['rows'][2]['item'], 'No charge' ) === 0 );
        $this->assertSame( 'Paying this invoice marks everyone listed above as current for 2027.', $r['note'] );

        $assess = MyNJILGA_Dues_Roster::email_rows( [ $members[0] ], 2027, 'assessment' );
        $this->assertCount( 1, $assess['rows'], 'an assessment invoice lists only the assessment' );
    }

    public function testHeadingAndButtonAreOneLine(): void {
        $this->assertSame( 'A B', MyNJILGA_Invoice_Email::clean( 'heading', "A\nB" ) );
        $this->assertSame( 'Pay now', MyNJILGA_Invoice_Email::clean( 'button', " Pay\r\nnow " ) );
    }
}
