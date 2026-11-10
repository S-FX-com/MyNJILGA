<?php
/**
 * The invoice email's wording (MyNJILGA_Invoice_Email): the built-in text must
 * be byte-for-byte what the email said before it could be customised, custom
 * wording must substitute merge tags without ever removing the pay link, and
 * nothing a person types or a firm name contains may break the message.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-email.php';

class InvoiceEmailTest extends NJILGA_TestCase {

    private const COVERS = "This invoice covers 1 person:\n\n  - Ann Able — \$125.00 Professional\n\n  Total: \$125.00\n\nPaying this invoice marks everyone listed above as current for 2027.";

    /** @return array<string,mixed> */
    private function input( array $over = [] ): array {
        return array_merge( [
            'is_assessment' => false, 'year' => 2027, 'firm' => 'Alpha & Beta LLP', 'total' => '$125.00',
            'name' => 'Owen Owner', 'first_name' => 'Owen', 'assessment_label' => 'Trustee Dinner Assessment',
            'covers' => self::COVERS, 'link' => 'https://pay.example.test/i/1', 'remittance' => '',
            'subject_tpl' => '', 'intro_tpl' => '', 'closing_tpl' => '',
        ], $over );
    }

    // ---- the built-in wording is unchanged -----------------------------------

    public function testTheBuiltInDuesEmailIsUnchanged(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input() );
        $this->assertSame( '2027 NJILGA Membership Dues Invoice — Alpha & Beta LLP', $m['subject'] );
        $this->assertSame(
            "Hi Owen Owner,\n\nAlpha & Beta LLP's 2027 NJILGA membership dues invoice totals \$125.00.\n\n" . self::COVERS
            . "\n\nPay online here: https://pay.example.test/i/1\n\nThank you,\nNJILGA",
            $m['body']
        );
    }

    public function testTheBuiltInMailACheckLineIsUnchanged(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'remittance' => "NJILGA\nPO Box 1\nNewark NJ" ] ) );
        $this->assertSame(
            "Pay online here: https://pay.example.test/i/1\n\nPay online at the link above, or mail a check to: NJILGA\nPO Box 1\nNewark NJ\n\nThank you,\nNJILGA",
            substr( $m['body'], (int) strpos( $m['body'], 'Pay online here' ) )
        );
    }

    public function testTheBuiltInAssessmentEmailIsUnchanged(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'is_assessment' => true, 'total' => '$200.00', 'covers' => '' ] ) );
        $this->assertSame( '2027 NJILGA Trustee Dinner Assessment Invoice', $m['subject'] );
        $this->assertSame(
            "Hi Owen Owner,\n\nYour 2027 NJILGA Trustee Dinner Assessment invoice totals \$200.00.\n\nPay online here: https://pay.example.test/i/1\n\nThank you,\nNJILGA",
            $m['body']
        );
    }

    public function testTheBuiltInEmailForAFirmWithNoNameIsUnchanged(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'firm' => '', 'covers' => '' ] ) );
        $this->assertSame( '2027 NJILGA Membership Dues Invoice', $m['subject'] );
        $this->assertSame( "Hi Owen Owner,\n\nYour 2027 NJILGA membership dues invoice totals \$125.00.", substr( $m['body'], 0, strpos( $m['body'], "\n\nPay online" ) ) );
    }

    public function testWhitespaceOnlyWordingMeansTheBuiltInText(): void {
        $this->assertSame( MyNJILGA_Invoice_Email::build( $this->input() ), MyNJILGA_Invoice_Email::build( $this->input( [ 'subject_tpl' => '  ', 'intro_tpl' => "\n ", 'closing_tpl' => "\t" ] ) ) );
    }

    public function testTheShownDefaultsRenderToTheBuiltInText(): void {
        // The placeholders staff see must describe what really goes out (for a firm that has a name).
        $in   = $this->input();
        $vars = MyNJILGA_Invoice_Email::vars( $in );
        $built = MyNJILGA_Invoice_Email::build( $in );
        $this->assertSame( $built['subject'], MyNJILGA_Invoice_Email::render( MyNJILGA_Invoice_Email::default_template( 'subject' ), $vars ) );
        $this->assertSame( 0, strpos( $built['body'], MyNJILGA_Invoice_Email::render( MyNJILGA_Invoice_Email::default_template( 'intro' ), $vars ) ) );
        $this->assertSame( MyNJILGA_Invoice_Email::render( MyNJILGA_Invoice_Email::default_template( 'closing' ), $vars ), substr( $built['body'], -strlen( "Thank you,\nNJILGA" ) ) );
    }

    // ---- custom wording --------------------------------------------------------

    public function testCustomWordingSubstitutesMergeTags(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [
            'subject_tpl' => 'NJILGA {year} dues for {firm}: {total}',
            'intro_tpl'   => "Dear {first_name},\n\nHere is the {year} {invoice} for {firm} ({total}).",
            'closing_tpl' => "With thanks,\nThe NJILGA team",
        ] ) );
        $this->assertSame( 'NJILGA 2027 dues for Alpha & Beta LLP: $125.00', $m['subject'] );
        $this->assertSame( 0, strpos( $m['body'], "Dear Owen,\n\nHere is the 2027 membership dues invoice for Alpha & Beta LLP (\$125.00).\n\nThis invoice covers" ) );
        $this->assertSame( "With thanks,\nThe NJILGA team", substr( $m['body'], -strlen( "With thanks,\nThe NJILGA team" ) ) );
    }

    public function testTheInvoiceTagNamesAnAssessment(): void {
        $v = MyNJILGA_Invoice_Email::vars( $this->input( [ 'is_assessment' => true ] ) );
        $this->assertSame( 'Trustee Dinner Assessment invoice', $v['invoice'] );
        $this->assertSame( 'membership dues invoice', MyNJILGA_Invoice_Email::vars( $this->input() )['invoice'] );
    }

    public function testTheFirmTagFallsBackWhenThereIsNoName(): void {
        $this->assertSame( 'your firm', MyNJILGA_Invoice_Email::vars( $this->input( [ 'firm' => '' ] ) )['firm'] );
        $this->assertSame( 'your firm', MyNJILGA_Invoice_Email::vars( $this->input( [ 'firm' => '   ' ] ) )['firm'] );
    }

    public function testTheFirstNameFallsBackToTheFirstWordOfTheFullName(): void {
        $this->assertSame( 'Owen', MyNJILGA_Invoice_Email::vars( $this->input( [ 'first_name' => '', 'name' => 'Owen  Owner' ] ) )['first_name'] );
        $this->assertSame( 'Maria', MyNJILGA_Invoice_Email::vars( $this->input( [ 'first_name' => 'Maria', 'name' => 'Maria Santos' ] ) )['first_name'] );
        $this->assertSame( 'owen@example.org', MyNJILGA_Invoice_Email::vars( $this->input( [ 'first_name' => '', 'name' => 'owen@example.org' ] ) )['first_name'], 'a bare email is all there is' );
        $this->assertSame( '', MyNJILGA_Invoice_Email::vars( $this->input( [ 'first_name' => '', 'name' => '' ] ) )['first_name'] );
    }

    public function testTagsAreCaseInsensitiveAndUnknownOnesAreLeftAlone(): void {
        $v = MyNJILGA_Invoice_Email::vars( $this->input() );
        $this->assertSame( '2027 / $125.00 / {nope}', MyNJILGA_Invoice_Email::render( '{YEAR} / {Total} / {nope}', $v ) );
    }

    public function testThePayLinkAndTheCoverageAreAlwaysThereWhateverTheWording(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'intro_tpl' => 'Hello.', 'closing_tpl' => 'Bye.', 'remittance' => 'PO Box 1' ] ) );
        $this->assertTrue( strpos( $m['body'], 'Pay online here: https://pay.example.test/i/1' ) !== false, 'the link cannot be edited out' );
        $this->assertTrue( strpos( $m['body'], '- Ann Able' ) !== false, 'who it covers cannot be edited out' );
        $this->assertTrue( strpos( $m['body'], 'mail a check to: PO Box 1' ) !== false );
        $this->assertSame( 0, strpos( $m['body'], 'Hello.' ) );
    }

    public function testASubjectIsAlwaysOneLineEvenIfAFirmNameIsNot(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'firm' => "Evil LLP\r\nBcc: attacker@example.test", 'subject_tpl' => 'Dues — {firm}' ] ) );
        $this->assertSame( 0, preg_match( '/[\r\n]/', $m['subject'] ) );
        $this->assertSame( 'Dues — Evil LLP Bcc: attacker@example.test', $m['subject'] );
    }

    public function testASubjectThatRendersEmptyFallsBackToTheBuiltInOne(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'subject_tpl' => '{first_name}', 'first_name' => '', 'name' => '' ] ) );
        $this->assertSame( '2027 NJILGA Membership Dues Invoice — Alpha & Beta LLP', $m['subject'] );
    }

    public function testCustomWordingAppliesToAssessmentInvoicesToo(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'is_assessment' => true, 'covers' => '', 'intro_tpl' => 'Hello {first_name}: your {year} {invoice} is {total}.', 'subject_tpl' => '{year} {invoice}' ] ) );
        $this->assertSame( '2027 Trustee Dinner Assessment invoice', $m['subject'] );
        $this->assertSame( 0, strpos( $m['body'], 'Hello Owen: your 2027 Trustee Dinner Assessment invoice is $125.00.' ) );
    }

    // ---- what staff type -----------------------------------------------------

    public function testCleaningStripsMarkupAndControlCharacters(): void {
        $this->assertSame( 'Hello', MyNJILGA_Invoice_Email::clean( 'intro', "<p>Hello</p>\x00\x07" ) );
        $this->assertSame( 'Bold move', MyNJILGA_Invoice_Email::clean( 'subject', '<b>Bold</b> <i>move</i>' ) );
    }

    public function testCleaningKeepsParagraphsButTidiesThem(): void {
        $this->assertSame( "One\n\nTwo\nThree", MyNJILGA_Invoice_Email::clean( 'intro', "\r\n One\r\n\r\n\r\n\r\nTwo\nThree  \n" ) );
        $this->assertSame( "A\n\nB", MyNJILGA_Invoice_Email::clean( 'closing', "A\n\n\n\n\nB" ) );
    }

    public function testASubjectIsFlattenedToOneLine(): void {
        $this->assertSame( 'A B C', MyNJILGA_Invoice_Email::clean( 'subject', "A\nB\r\n  C \n" ) );
    }

    public function testLengthsAreBounded(): void {
        $this->assertSame( MyNJILGA_Invoice_Email::MAX_SUBJECT, strlen( MyNJILGA_Invoice_Email::clean( 'subject', str_repeat( 'x', 900 ) ) ) );
        $this->assertSame( MyNJILGA_Invoice_Email::MAX_TEXT, strlen( MyNJILGA_Invoice_Email::clean( 'intro', str_repeat( 'x', 9000 ) ) ) );
    }

    public function testATypoedMergeTagIsCaughtBeforeItCanBeEmailed(): void {
        $this->assertSame( [], MyNJILGA_Invoice_Email::unknown_tags( 'Hi {name}, {FIRM} owes {total} for {year}. {invoice} {first_name}' ) );
        $this->assertSame( [ 'frim', 'tota1' ], MyNJILGA_Invoice_Email::unknown_tags( 'Hi {name}: {frim} {FRIM} {tota1}' ) );
        $this->assertSame( [], MyNJILGA_Invoice_Email::unknown_tags( 'Braces without a name { } or {with spaces} are not tags' ) );
    }

    public function testEveryDocumentedTagIsSubstituted(): void {
        $v = MyNJILGA_Invoice_Email::vars( $this->input() );
        foreach ( array_keys( MyNJILGA_Invoice_Email::TAGS ) as $tag ) {
            $this->assertTrue( isset( $v[ $tag ] ), $tag . ' is documented, so it must have a value' );
        }
        $this->assertSame( count( MyNJILGA_Invoice_Email::TAGS ), count( $v ), 'and no tag works without being documented' );
    }

    public function testCustomIsDetectedPerField(): void {
        $this->assertFalse( MyNJILGA_Invoice_Email::is_custom( [ 'subject' => '', 'intro' => ' ', 'closing' => '' ] ) );
        $this->assertTrue( MyNJILGA_Invoice_Email::is_custom( [ 'subject' => '', 'intro' => '', 'closing' => 'Bye' ] ) );
        $this->assertFalse( MyNJILGA_Invoice_Email::is_custom( [] ) );
    }
}
