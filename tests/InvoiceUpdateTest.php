<?php
/**
 * Resend Updated Invoice (MyNJILGA_Invoice_Update), the pure parts: which
 * invoices may be replaced, what the review screen reports as changed, the
 * fingerprint that ties the confirm button to what was reviewed, what
 * Stripe's own copy of the old invoice must say, and the wording an updated
 * invoice's email carries — with the ordinary email unchanged byte for byte.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-update.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-email.php';

class InvoiceUpdateTest extends NJILGA_TestCase {

    private function row( array $over = [] ): object {
        return (object) ( $over + [
            'id' => 7, 'dues_year' => 2027, 'invoice_kind' => 'combined', 'status' => 'sent',
            'amount_paid_cents' => 0, 'paid_off_stripe_cents' => 0, 'gateway_invoice_id' => 'in_OLD',
            'gateway_invoice_number' => 'NJILGA-2290', 'total_amount_cents' => 20000,
        ] );
    }

    /** @return array<string,mixed> */
    private function member( int $id, string $name, int $dues, int $assess = 0, string $cat = 'Professional Membership', string $tier = '1st Member' ): array {
        return [ 'contact_id' => $id, 'name' => $name, 'email' => strtolower( str_replace( ' ', '.', $name ) ) . '@firm.test', 'category_label' => $cat, 'tier_label' => $tier, 'dues_cents' => $dues, 'assessment_cents' => $assess ];
    }

    /** @return array<string,mixed> */
    private function snap( array $members, array $over = [] ): array {
        return $over + [
            'company' => [ 'id' => 12, 'name' => 'Alpha LLP' ],
            'bill_to' => [ 'contact_id' => 5, 'name' => 'Owen Owner', 'email' => 'owen@firm.test' ],
            'members' => $members,
        ];
    }

    // ---- which invoice may be replaced ---------------------------------------

    public function testOnlyAnUnpaidCreatedOrEmailedAnnualInvoiceCanBeReplaced(): void {
        $this->assertSame( '', MyNJILGA_Invoice_Update::refusal( $this->row() ) );
        $this->assertSame( '', MyNJILGA_Invoice_Update::refusal( $this->row( [ 'status' => 'created' ] ) ) );
        foreach ( [
            'paid'           => $this->row( [ 'status' => 'paid' ] ),
            'clearing (ACH)' => $this->row( [ 'status' => 'processing' ] ),
            'voided'         => $this->row( [ 'status' => 'voided' ] ),
            'not created'    => $this->row( [ 'status' => 'draft' ] ),
            'approved'       => $this->row( [ 'status' => 'approved' ] ),
            'uncollectible'  => $this->row( [ 'status' => 'uncollectible' ] ),
            'an online join' => $this->row( [ 'invoice_kind' => 'join' ] ),
            'partly paid'    => $this->row( [ 'amount_paid_cents' => 500 ] ),
            'paid by check'  => $this->row( [ 'paid_off_stripe_cents' => 500 ] ),
            'no stripe id'   => $this->row( [ 'gateway_invoice_id' => '' ] ),
        ] as $name => $row ) {
            $this->assertTrue( MyNJILGA_Invoice_Update::refusal( $row ) !== '', $name );
        }
    }

    public function testEachRefusalSaysWhatToDoInstead(): void {
        $this->assertTrue( strpos( MyNJILGA_Invoice_Update::refusal( $this->row( [ 'status' => 'voided' ] ) ), 'Issue again' ) !== false, 'voided → Issue again' );
        $this->assertTrue( strpos( MyNJILGA_Invoice_Update::refusal( $this->row( [ 'status' => 'draft' ] ) ), 'Refresh Firms' ) !== false, 'not created → Refresh Firms' );
        $this->assertTrue( strpos( MyNJILGA_Invoice_Update::refusal( $this->row( [ 'status' => 'processing' ] ) ), 'clearing' ) !== false, 'ACH' );
    }

    // ---- what changed ---------------------------------------------------------

    public function testAnUnchangedInvoiceReportsNoChange(): void {
        $s = $this->snap( [ $this->member( 1, 'Ann Able', 12500 ), $this->member( 2, 'Bob Baker', 7500 ) ] );
        $d = MyNJILGA_Invoice_Update::diff( $s, $s );
        $this->assertFalse( $d['any'] );
        $this->assertSame( [], $d['changes'] );
        $this->assertSame( 20000, $d['old_total'] );
        $this->assertSame( 20000, $d['new_total'] );
    }

    public function testAddedRemovedAndChangedMembersAreEachReported(): void {
        $old = $this->snap( [ $this->member( 1, 'Ann Able', 12500 ), $this->member( 2, 'Bob Baker', 7500 ), $this->member( 3, 'Cy Cole', 7500 ) ] );
        $new = $this->snap( [ $this->member( 1, 'Ann Able', 12500 ), $this->member( 2, 'Bob Baker', 9000 ), $this->member( 4, 'Di Dunn', 7500 ) ] );
        $d   = MyNJILGA_Invoice_Update::diff( $old, $new );
        $this->assertTrue( $d['any'] );
        $this->assertSame( 27500, $d['old_total'] );
        $this->assertSame( 29000, $d['new_total'] );
        $by = [];
        foreach ( $d['changes'] as $c ) {
            $by[ $c['name'] ] = $c;
        }
        $this->assertCount( 3, $d['changes'] );
        $this->assertSame( 'changed', $by['Bob Baker']['kind'] );
        $this->assertSame( 7500, $by['Bob Baker']['old_cents'] );
        $this->assertSame( 9000, $by['Bob Baker']['new_cents'] );
        $this->assertSame( 'added', $by['Di Dunn']['kind'] );
        $this->assertSame( 7500, $by['Di Dunn']['new_cents'] );
        $this->assertSame( 'removed', $by['Cy Cole']['kind'] );
        $this->assertSame( 7500, $by['Cy Cole']['old_cents'] );
        $this->assertSame( 0, $by['Cy Cole']['new_cents'] );
    }

    /** A category change that happens to cost the same still changes the invoice's lines. */
    public function testACategoryChangeAtTheSamePriceIsStillAChange(): void {
        $old = $this->snap( [ $this->member( 1, 'Ann Able', 12500, 0, 'Professional Membership' ) ] );
        $new = $this->snap( [ $this->member( 1, 'Ann Able', 12500, 0, 'Associate Membership' ) ] );
        $d   = MyNJILGA_Invoice_Update::diff( $old, $new );
        $this->assertTrue( $d['any'] );
        $this->assertSame( 'changed', $d['changes'][0]['kind'] );
        $this->assertSame( 'Professional Membership · 1st Member', $d['changes'][0]['old_label'] );
        $this->assertSame( 'Associate Membership · 1st Member', $d['changes'][0]['new_label'] );
    }

    public function testADuesAndAssessmentAreOneLineAmount(): void {
        $old = $this->snap( [ $this->member( 1, 'Ann Able', 12500, 0 ) ] );
        $new = $this->snap( [ $this->member( 1, 'Ann Able', 12500, 20000 ) ] );
        $d   = MyNJILGA_Invoice_Update::diff( $old, $new );
        $this->assertSame( 12500, $d['changes'][0]['old_cents'] );
        $this->assertSame( 32500, $d['changes'][0]['new_cents'] );
    }

    public function testAMemberAddedAtZeroDollarsIsStillALineOnTheInvoice(): void {
        $old = $this->snap( [ $this->member( 1, 'Ann Able', 12500 ) ] );
        $new = $this->snap( [ $this->member( 1, 'Ann Able', 12500 ), $this->member( 2, 'Bob Baker', 0 ) ] );
        $d   = MyNJILGA_Invoice_Update::diff( $old, $new );
        $this->assertTrue( $d['any'] );
        $this->assertSame( 'added', $d['changes'][0]['kind'] );
        $this->assertSame( 0, $d['changes'][0]['new_cents'] );
    }

    public function testAChangedBillToOrFirmNameIsReported(): void {
        $members = [ $this->member( 1, 'Ann Able', 12500 ) ];
        $d = MyNJILGA_Invoice_Update::diff( $this->snap( $members ), $this->snap( $members, [ 'bill_to' => [ 'contact_id' => 9, 'name' => 'Jane New', 'email' => 'jane@firm.test' ] ] ) );
        $this->assertTrue( $d['any'] );
        $this->assertTrue( $d['bill_to']['changed'] );
        $this->assertSame( 'Owen Owner (owen@firm.test)', $d['bill_to']['old'] );
        $this->assertSame( 'Jane New (jane@firm.test)', $d['bill_to']['new'] );

        $d = MyNJILGA_Invoice_Update::diff( $this->snap( $members ), $this->snap( $members, [ 'company' => [ 'id' => 12, 'name' => 'Alpha & Beta LLP' ] ] ) );
        $this->assertTrue( $d['any'] );
        $this->assertTrue( $d['firm']['changed'] );
        $this->assertFalse( $d['bill_to']['changed'] );

        // The same person's email, only re-cased, is not a change.
        $d = MyNJILGA_Invoice_Update::diff( $this->snap( $members ), $this->snap( $members, [ 'bill_to' => [ 'contact_id' => 5, 'name' => 'Owen Owner', 'email' => 'OWEN@Firm.test' ] ] ) );
        $this->assertFalse( $d['bill_to']['changed'] );
        $this->assertFalse( $d['any'] );
    }

    public function testMembersWithoutAContactAreMatchedByNameAndEmail(): void {
        $a = $this->member( 0, 'Ann Able', 12500 );
        $b = $this->member( 0, 'Bob Baker', 7500 );
        $d = MyNJILGA_Invoice_Update::diff( $this->snap( [ $a, $b ] ), $this->snap( [ $a ] ) );
        $this->assertCount( 1, $d['changes'] );
        $this->assertSame( 'removed', $d['changes'][0]['kind'] );
        $this->assertSame( 'Bob Baker', $d['changes'][0]['name'] );
    }

    public function testTwoLinesForTheSameContactAreNotCollapsed(): void {
        $one = $this->member( 1, 'Ann Able', 12500 );
        $d   = MyNJILGA_Invoice_Update::diff( $this->snap( [ $one, $one ] ), $this->snap( [ $one ] ) );
        $this->assertTrue( $d['any'] );
        $this->assertSame( 25000, $d['old_total'] );
        $this->assertSame( 12500, $d['new_total'] );
    }

    // ---- everything the invoice prints about a person counts --------------------

    public function testARenameIsAChange(): void {
        $old = $this->snap( [ $this->member( 1, 'Ann Able', 12500 ) ] );
        $new = $this->snap( [ array_merge( $this->member( 1, 'Ann Able', 12500 ), [ 'name' => 'Anne Able' ] ) ] );
        $d   = MyNJILGA_Invoice_Update::diff( $old, $new );
        $this->assertTrue( $d['any'], 'a name fixed in CRM changes the invoice\'s lines' );
        $this->assertSame( 'changed', $d['changes'][0]['kind'] );
        $this->assertSame( 'Anne Able', $d['changes'][0]['name'] );
        $this->assertSame( 'Ann Able', $d['changes'][0]['old_name'] );
    }

    public function testAChangeInWhyAZeroIsZeroIsAChange(): void {
        $inactive = array_merge( $this->member( 1, 'Ann Able', 0 ), [ 'dues_note' => 'inactive' ] );
        $paid     = array_merge( $this->member( 1, 'Ann Able', 0 ), [ 'dues_note' => 'paid via online join' ] );
        $d        = MyNJILGA_Invoice_Update::diff( $this->snap( [ $inactive ] ), $this->snap( [ $paid ] ) );
        $this->assertTrue( $d['any'] );
        $this->assertSame( 'Professional Membership · 1st Member — inactive', $d['changes'][0]['old_label'] );
        $this->assertSame( 'Professional Membership · 1st Member — paid via online join', $d['changes'][0]['new_label'] );
        $unbilled = array_merge( $this->member( 1, 'Ann Able', 0 ), [ 'unbilled_reason' => 'no category tag' ] );
        $this->assertTrue( MyNJILGA_Invoice_Update::diff( $this->snap( [ $this->member( 1, 'Ann Able', 0 ) ] ), $this->snap( [ $unbilled ] ) )['any'], 'the unbilled reason counts too' );
    }

    public function testTheAssessmentsWordingIsPartOfTheInvoiceToo(): void {
        $officer = array_merge( $this->member( 1, 'Ann Able', 12500, 20000 ), [ 'assessment_label' => 'Trustee Dinner Assessment', 'assessment_qualifier' => 'Officer' ] );
        $trustee = array_merge( $this->member( 1, 'Ann Able', 12500, 20000 ), [ 'assessment_label' => 'Trustee Dinner Assessment', 'assessment_qualifier' => 'Trustee' ] );
        $d       = MyNJILGA_Invoice_Update::diff( $this->snap( [ $officer ] ), $this->snap( [ $trustee ] ) );
        $this->assertTrue( $d['any'], 'same price, different qualifier' );
        $this->assertSame( 'Professional Membership · 1st Member + Trustee Dinner Assessment (Officer)', $d['changes'][0]['old_label'] );
        // An assessment label on a member who owes no assessment is not printed, so it is not a change.
        $none = array_merge( $this->member( 1, 'Ann Able', 12500, 0 ), [ 'assessment_label' => 'Trustee Dinner Assessment' ] );
        $this->assertFalse( MyNJILGA_Invoice_Update::diff( $this->snap( [ $this->member( 1, 'Ann Able', 12500, 0 ) ] ), $this->snap( [ $none ] ) )['any'] );
    }

    // ---- whether the new invoice is emailed ---------------------------------------

    public function testOnlyAnInvoiceTheFirmReceivedIsEmailedAgain(): void {
        $this->assertTrue( MyNJILGA_Invoice_Update::was_emailed( $this->row( [ 'status' => 'sent' ] ) ) );
        $this->assertTrue( MyNJILGA_Invoice_Update::was_emailed( $this->row( [ 'status' => 'created', 'sent_at' => '2026-11-10 09:00:00' ] ) ), 'sent_at alone is proof it went' );
        $this->assertFalse( MyNJILGA_Invoice_Update::was_emailed( $this->row( [ 'status' => 'created', 'sent_at' => null ] ) ) );
        $this->assertFalse( MyNJILGA_Invoice_Update::was_emailed( $this->row( [ 'status' => 'created', 'sent_at' => '' ] ) ) );
    }

    public function testNothingIsEmailedInTestModeOrForAnInvoiceNeverSent(): void {
        $sent   = $this->row( [ 'status' => 'sent' ] );
        $unsent = $this->row( [ 'status' => 'created', 'sent_at' => null ] );
        $this->assertSame( [ 'was_emailed' => true, 'will_email' => true, 'why_not' => '' ], MyNJILGA_Invoice_Update::email_plan( $sent, true ), 'Live + emailed: send' );
        $this->assertSame( [ 'was_emailed' => false, 'will_email' => false, 'why_not' => 'unsent' ], MyNJILGA_Invoice_Update::email_plan( $unsent, true ), 'Live + never emailed: replace quietly' );
        $this->assertSame( [ 'was_emailed' => true, 'will_email' => false, 'why_not' => 'test' ], MyNJILGA_Invoice_Update::email_plan( $sent, false ), 'Test mode never emails firms' );
        $this->assertSame( [ 'was_emailed' => false, 'will_email' => false, 'why_not' => 'test' ], MyNJILGA_Invoice_Update::email_plan( $unsent, false ), 'Test mode wins over "never sent"' );
    }

    public function testTheNoticeNamesTheInvoiceTheFirmActuallyHolds(): void {
        $sent = $this->row( [ 'status' => 'sent' ] );
        $this->assertSame( [ 'number' => 'NJILGA-2290', 'id' => 'in_OLD', 'total_cents' => 20000 ], MyNJILGA_Invoice_Update::carry_replaces( $sent ), 'an emailed invoice is the one replaced' );

        $plain = $this->row( [ 'status' => 'created', 'sent_at' => null, 'roster_snapshot' => json_encode( $this->snap( [], [ 'version' => 2 ] ) ) ] );
        $this->assertSame( [], MyNJILGA_Invoice_Update::carry_replaces( $plain ), 'a never-emailed invoice has nothing to name' );

        // A replacement whose email failed, replaced again before it was ever sent: the firm still holds the ORIGINAL.
        $prior   = [ 'number' => 'NJILGA-2201', 'id' => 'in_FIRST', 'total_cents' => 11100 ];
        $chained = $this->row( [ 'status' => 'created', 'sent_at' => null, 'roster_snapshot' => json_encode( $this->snap( [], [ 'version' => 2, 'replaces' => $prior ] ) ) ] ); // a real snapshot is always version 2
        $this->assertSame( $prior, MyNJILGA_Invoice_Update::carry_replaces( $chained ), 'the notice keeps naming the first invoice' );
    }

    // ---- the fingerprint -------------------------------------------------------

    public function testTheFingerprintFollowsWhatWasReviewed(): void {
        $row  = $this->row();
        $snap = $this->snap( [ $this->member( 1, 'Ann Able', 12500 ) ] );
        $fp   = MyNJILGA_Invoice_Update::fingerprint( $row, $snap );
        $this->assertSame( $fp, MyNJILGA_Invoice_Update::fingerprint( $row, $snap ), 'stable' );
        $this->assertSame( 64, strlen( $fp ) );

        $changed = [
            'a price'          => [ $row, $this->snap( [ $this->member( 1, 'Ann Able', 12600 ) ] ) ],
            'a member'         => [ $row, $this->snap( [ $this->member( 1, 'Ann Able', 12500 ), $this->member( 2, 'Bob Baker', 100 ) ] ) ],
            'a category'       => [ $row, $this->snap( [ $this->member( 1, 'Ann Able', 12500, 0, 'Associate Membership' ) ] ) ],
            'a name'           => [ $row, $this->snap( [ array_merge( $this->member( 1, 'Ann Able', 12500 ), [ 'name' => 'Anne Able' ] ) ] ) ],
            'why a $0 is $0'   => [ $row, $this->snap( [ array_merge( $this->member( 1, 'Ann Able', 12500 ), [ 'dues_note' => 'already paid' ] ) ] ) ],
            'the bill-to'      => [ $row, $this->snap( [ $this->member( 1, 'Ann Able', 12500 ) ], [ 'bill_to' => [ 'contact_id' => 9, 'email' => 'x@firm.test' ] ] ) ],
            'the old invoice'  => [ $this->row( [ 'gateway_invoice_id' => 'in_OTHER' ] ), $snap ],
            'the old status'   => [ $this->row( [ 'status' => 'created' ] ), $snap ],
            'the old total'    => [ $this->row( [ 'total_amount_cents' => 19999 ] ), $snap ],
            'another firm row' => [ $this->row( [ 'id' => 8 ] ), $snap ],
        ];
        foreach ( $changed as $name => [ $r, $s ] ) {
            $this->assertTrue( MyNJILGA_Invoice_Update::fingerprint( $r, $s ) !== $fp, $name . ' changes the fingerprint' );
        }
    }

    // ---- what Stripe says about the old invoice ------------------------------

    public function testOnlyAnOpenUnpaidInvoiceIsVoided(): void {
        $v = MyNJILGA_Invoice_Update::stripe_verdict( [ 'status' => 'open', 'amount_paid_cents' => 0 ] );
        $this->assertTrue( $v['go'] );
        $this->assertFalse( $v['already_void'] );

        $v = MyNJILGA_Invoice_Update::stripe_verdict( [ 'status' => 'void', 'amount_paid_cents' => 0 ] );
        $this->assertTrue( $v['go'], 'already void in Stripe: carry on' );
        $this->assertTrue( $v['already_void'], '…without voiding it a second time' );

        foreach ( [
            'not found'      => null,
            'paid'           => [ 'status' => 'paid', 'amount_paid_cents' => 20000 ],
            'partly paid'    => [ 'status' => 'open', 'amount_paid_cents' => 500 ],
            'ACH in flight'  => [ 'status' => 'open', 'amount_paid_cents' => 0, 'payment_intent' => [ 'status' => 'processing' ] ],
            'uncollectible'  => [ 'status' => 'uncollectible', 'amount_paid_cents' => 0 ],
            'a draft'        => [ 'status' => 'draft', 'amount_paid_cents' => 0 ],
            'no status'      => [ 'amount_paid_cents' => 0 ],
        ] as $name => $live ) {
            $v = MyNJILGA_Invoice_Update::stripe_verdict( $live );
            $this->assertFalse( $v['go'], $name );
            $this->assertTrue( $v['error'] !== '', $name . ' says why' );
            $this->assertTrue( strpos( $v['error'], 'nothing was changed' ) !== false, $name . ' says nothing changed' );
        }
        // A payment intent that isn't processing is no obstacle.
        $v = MyNJILGA_Invoice_Update::stripe_verdict( [ 'status' => 'open', 'amount_paid_cents' => 0, 'payment_intent' => [ 'status' => 'requires_payment_method' ] ] );
        $this->assertTrue( $v['go'] );
    }

    public function testTheNewInvoiceRecordsWhichOneItReplaces(): void {
        $this->assertSame(
            [ 'number' => 'NJILGA-2290', 'id' => 'in_OLD', 'total_cents' => 20000 ],
            MyNJILGA_Invoice_Update::replaces_from( $this->row() )
        );
    }

    // ---- the email --------------------------------------------------------------

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

    public function testAnUpdatedInvoiceSaysSoAndNamesTheOneItReplaces(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'replaces' => [ 'number' => 'NJILGA-2290', 'total' => '$200.00' ] ] ) );
        $this->assertSame( 'Updated Invoice: 2027 NJILGA Membership Dues Invoice — Alpha LLP', $m['subject'] );
        $notice = 'This is an updated invoice. It replaces invoice NJILGA-2290 ($200.00), which has been voided. Please disregard the earlier invoice and its payment link, and pay this one.';
        $this->assertTrue( strpos( $m['body'], $notice ) !== false, 'the notice is in the body' );
        $this->assertSame(
            "Hi Owen Owner,\n\nAlpha LLP's 2027 NJILGA membership dues invoice totals \$290.00.\n\n" . $notice . "\n\nThis invoice covers 2 people.\n\nPay online here: https://pay.example.test/i/2\n\nThank you,\nNJILGA",
            $m['body'],
            'after the opening, before the list of who it covers'
        );
    }

    public function testTheNoticeSurvivesCustomWordingAndCannotBeEditedAway(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [
            'replaces' => [ 'number' => 'NJILGA-2290', 'total' => '' ],
            'subject_tpl' => 'Your {year} dues — {firm}', 'intro_tpl' => 'Hello {first_name}.', 'closing_tpl' => 'Regards',
        ] ) );
        $this->assertSame( 'Updated Invoice: Your 2027 dues — Alpha LLP', $m['subject'], 'custom subjects are marked too' );
        $this->assertTrue( strpos( $m['body'], 'It replaces invoice NJILGA-2290, which has been voided.' ) !== false );
        $this->assertTrue( strpos( $m['body'], '(' ) === false || strpos( $m['body'], 'NJILGA-2290 (' ) === false, 'no empty total in brackets' );
    }

    public function testASubjectAlreadyMarkedUpdatedIsNotMarkedTwice(): void {
        $m = MyNJILGA_Invoice_Email::build( $this->input( [ 'replaces' => [ 'number' => 'X-1', 'total' => '' ], 'subject_tpl' => 'Updated dues invoice — {firm}' ] ) );
        $this->assertSame( 'Updated dues invoice — Alpha LLP', $m['subject'] );
    }

    public function testWithoutANumberTheNoticeStillSaysWhichInvoiceIsMeant(): void {
        $n = MyNJILGA_Invoice_Email::updated_notice( [ 'replaces' => [ 'number' => '', 'total' => '$200.00' ] ] );
        $this->assertSame( 'This is an updated invoice. It replaces the invoice we sent you earlier ($200.00), which has been voided. Please disregard the earlier invoice and its payment link, and pay this one.', $n );
    }

    /** Nothing about an ordinary invoice's email changes — byte for byte. */
    public function testAnInvoiceThatReplacesNothingEmailsExactlyAsBefore(): void {
        $plain = MyNJILGA_Invoice_Email::build( $this->input() );
        $this->assertSame( '2027 NJILGA Membership Dues Invoice — Alpha LLP', $plain['subject'] );
        $this->assertSame(
            "Hi Owen Owner,\n\nAlpha LLP's 2027 NJILGA membership dues invoice totals \$290.00.\n\nThis invoice covers 2 people.\n\nPay online here: https://pay.example.test/i/2\n\nThank you,\nNJILGA",
            $plain['body']
        );
        foreach ( [ null, [], 'x' ] as $odd ) {
            $this->assertSame( $plain, MyNJILGA_Invoice_Email::build( $this->input( [ 'replaces' => $odd ] ) ), 'an empty "replaces" changes nothing' );
        }
        $html = MyNJILGA_Invoice_Email::build_html( $this->input() );
        $this->assertFalse( strpos( $html, 'updated invoice' ) !== false, 'no notice in the HTML' );
        $this->assertFalse( strpos( $html, 'Updated:' ) !== false );
        $this->assertSame( $html, MyNJILGA_Invoice_Email::build_html( $this->input( [ 'replaces' => [] ] ) ) );
    }

    public function testTheHtmlEmailShowsTheNoticeEscaped(): void {
        $html = MyNJILGA_Invoice_Email::build_html( $this->input( [ 'replaces' => [ 'number' => 'NJ<b>-1', 'total' => '$200.00' ] ] ) );
        $this->assertTrue( strpos( $html, '<strong>This is an updated invoice.</strong> It replaces invoice NJ&lt;b&gt;-1 ($200.00), which has been voided.' ) !== false, 'the notice, with its number escaped' );
        $this->assertFalse( strpos( $html, 'NJ<b>-1' ) !== false, 'nothing typed becomes markup' );
        $this->assertTrue( strpos( $html, 'Updated Invoice: Alpha LLP 2027 invoice: $290.00' ) !== false, 'the preheader says it is an update' );
    }
}
