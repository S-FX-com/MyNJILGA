<?php
/**
 * An invoice emailed again as a reminder (MyNJILGA_Invoice_Email): the "Reminder:" subject, the
 * line saying the invoice is unpaid, in the plain-text and HTML versions — and the email an
 * ordinary invoice gets, unchanged byte for byte.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-email.php';

class InvoiceReminderTest extends NJILGA_TestCase {

    /** @return array<string,mixed> */
    private function input( array $over = [] ): array {
        return array_merge( [
            'is_assessment' => false, 'year' => 2027, 'firm' => 'Alpha LLP', 'total' => '$290.00',
            'name' => 'Owen Owner', 'first_name' => 'Owen', 'assessment_label' => '',
            'covers' => 'This invoice covers 2 people.', 'link' => 'https://pay.example.test/i/2', 'remittance' => '',
            'subject_tpl' => '', 'intro_tpl' => '', 'closing_tpl' => '', 'heading_tpl' => '', 'button_tpl' => '',
            'rows' => [], 'covers_note' => '', 'invoice_number' => 'NJILGA-2301', 'due_date' => '', 'logo_url' => '', 'site_name' => '', 'site_url' => '',
        ], $over );
    }

    private const REMINDER = 'This is a friendly reminder. Our records show that invoice NJILGA-2301 ($290.00) has not been paid yet. If you have already paid it, thank you — please disregard this message.';

    public function testAReminderSaysSoInTheSubjectAndTheBody(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'reminder' => [ 'number' => 'NJILGA-2301', 'total' => '$290.00' ] ] ) );
        $this->assertSame( 'Reminder: 2027 NJILGA Membership Dues Invoice — Alpha LLP', $m['subject'] );
        $this->assertSame(
            "Hi Owen Owner,\n\nAlpha LLP's 2027 NJILGA membership dues invoice totals \$290.00.\n\n" . self::REMINDER . "\n\nThis invoice covers 2 people.\n\nPay online here: https://pay.example.test/i/2\n\nThank you,\nNJILGA",
            $m['body'],
            'after the opening, before the list of who it covers — and the pay link is still there'
        );
    }

    public function testACustomSubjectIsMarkedToo_ButNeverTwice(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'reminder' => [ 'number' => 'X-1', 'total' => '' ], 'subject_tpl' => 'Your {year} dues — {firm}' ] ) );
        $this->assertSame( 'Reminder: Your 2027 dues — Alpha LLP', $m['subject'] );
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'reminder' => [ 'number' => 'X-1', 'total' => '' ], 'subject_tpl' => 'Reminder — {firm}' ] ) );
        $this->assertSame( 'Reminder — Alpha LLP', $m['subject'], 'a subject already starting "Reminder" is left alone' );
    }

    public function testWithoutANumberOrTotalTheReminderStillMakesSense(): void {
        $this->assertSame(
            'This is a friendly reminder. Our records show that this invoice has not been paid yet. If you have already paid it, thank you — please disregard this message.',
            MyNJILGA_Invoice_Email::reminder_notice( [ 'reminder' => [ 'number' => '', 'total' => '' ] ] )
        );
        $this->assertSame(
            'This is a friendly reminder. Our records show that this invoice ($290.00) has not been paid yet. If you have already paid it, thank you — please disregard this message.',
            MyNJILGA_Invoice_Email::reminder_notice( [ 'reminder' => [ 'number' => '', 'total' => '$290.00' ] ] )
        );
        $this->assertSame( '', MyNJILGA_Invoice_Email::reminder_notice( [ 'reminder' => [] ] ), 'an empty "reminder" is not a reminder' );
    }

    /** A reminder of an updated invoice is a reminder first; the body still says it replaces the earlier one. */
    public function testAReminderOfAnUpdatedInvoiceIsAReminderFirst(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [
            'reminder' => [ 'number' => 'NJILGA-2301', 'total' => '$290.00' ],
            'replaces' => [ 'number' => 'NJILGA-2290', 'total' => '$200.00' ],
        ] ) );
        $this->assertSame( 'Reminder: 2027 NJILGA Membership Dues Invoice — Alpha LLP', $m['subject'], 'not "Reminder: Updated Invoice: …"' );
        $this->assertTrue( strpos( $m['body'], self::REMINDER ) !== false );
        $this->assertTrue( strpos( $m['body'], 'It replaces invoice NJILGA-2290 ($200.00), which has been voided.' ) !== false, 'and it still names the invoice it replaces' );
        $this->assertTrue( strpos( $m['body'], self::REMINDER ) < strpos( $m['body'], 'This is an updated invoice.' ), 'the reminder comes first' );
    }

    /** Nothing about an ordinary invoice's email changes. */
    public function testAnInvoiceThatIsNotAReminderEmailsExactlyAsBefore(): void {
        $plain = MyNJILGA_Invoice_Email::build( $this->input() );
        $this->assertSame( '2027 NJILGA Membership Dues Invoice — Alpha LLP', $plain['subject'] );
        foreach ( [ null, [], 'x', 0 ] as $odd ) {
            $this->assertSame( $plain, MyNJILGA_Invoice_Email::build( $this->input( [ 'reminder' => $odd ] ) ), 'an empty "reminder" changes nothing' );
        }
        $html = MyNJILGA_Invoice_Email::build_html( $this->input() );
        $this->assertFalse( strpos( $html, 'reminder' ) !== false, 'no reminder wording in the HTML' );
        $this->assertSame( $html, MyNJILGA_Invoice_Email::build_html( $this->input( [ 'reminder' => [] ] ) ) );
    }

    public function testTheHtmlEmailShowsTheReminderEscaped(): void {
        $html = MyNJILGA_Invoice_Email::build_html( $this->input( [ 'reminder' => [ 'number' => 'NJ<b>-1', 'total' => '$290.00' ] ] ) );
        $this->assertTrue( strpos( $html, '<strong>This is a friendly reminder.</strong> Our records show that invoice NJ&lt;b&gt;-1 ($290.00) has not been paid yet.' ) !== false, 'the notice, with its number escaped' );
        $this->assertFalse( strpos( $html, 'NJ<b>-1' ) !== false, 'nothing typed becomes markup' );
        $this->assertTrue( strpos( $html, 'Reminder: Alpha LLP 2027 invoice: $290.00' ) !== false, 'the preheader says it is a reminder' );
    }

    public function testTheUpdatedSubjectNowSaysUpdatedInvoice(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'replaces' => [ 'number' => 'NJILGA-2290', 'total' => '$200.00' ] ] ) );
        $this->assertSame( 'Updated Invoice: 2027 NJILGA Membership Dues Invoice — Alpha LLP', $m['subject'] );
    }
}
