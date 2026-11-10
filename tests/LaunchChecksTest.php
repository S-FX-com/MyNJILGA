<?php
/**
 * The Launch Wizard's readiness checklist and preview review
 * (MyNJILGA_Launch_Checks): every verdict is a pure function of a facts array,
 * so each one is pinned here.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/launch/class-launch-checks.php';

class LaunchChecksTest extends NJILGA_TestCase {

    /** A site that is ready to go: Live, healthy, scheduler up, everything configured. */
    private function goodFacts( array $over = [] ): array {
        return array_merge( [
            'year' => 2027,
            'urls' => [ 'settings' => 'S', 'payments' => 'P', 'setup' => 'U', 'invoicing' => 'I', 'firms' => 'F', 'dashboard' => 'D', 'step2' => 'S2', 'step5' => 'S5' ],
            'mode' => 'live', 'connected' => true, 'health' => [], 'health_warnings' => [], 'encryption' => true,
            'as_available' => true, 'reconcile_scheduled' => true, 'events_stuck' => 0,
            'categories' => [
                [ 'key' => 'law_student', 'label' => 'Law Student', 'tag' => 'law-student', 'tag_exists' => true, 'price_cents' => 3000, 'tier_eligible' => false, 'tiers' => [] ],
                [ 'key' => 'professional', 'label' => 'Professional', 'tag' => 'professional', 'tag_exists' => true, 'price_cents' => 12500, 'tier_eligible' => true, 'tiers' => [
                    [ 'label' => '1st Member', 'from' => 1, 'to' => 1, 'price_cents' => 12500 ],
                    [ 'label' => 'Members 2–5', 'from' => 2, 'to' => 5, 'price_cents' => 7500 ],
                    [ 'label' => 'Members 6+', 'from' => 6, 'to' => 0, 'price_cents' => 0 ],
                ] ],
            ],
            'assessment_cents' => 20000, 'default_category' => 'professional', 'default_category_label' => 'Professional', 'defaulted_total' => 0,
            'join_enabled' => true, 'join_year_now' => 2027, 'batch_size' => 10, 'reply_to' => 'dues@njilga.org', 'cc_description' => 'The bill-to contact only — nobody is copied.',
            'remittance' => 'NJILGA, PO Box 1, Newark NJ', 'due_ts' => strtotime( '2026-12-31 12:00:00 UTC' ), 'mail_ok' => true, 'mail_test_at' => 1794000000,
            'firms_no_owner' => 0, 'legacy_paid_tag' => 0,
        ], $over );
    }

    /** @return array<string,array<string,mixed>> id => check */
    private function byId( array $facts ): array {
        $out = [];
        foreach ( MyNJILGA_Launch_Checks::evaluate( $facts ) as $c ) {
            $out[ $c['id'] ] = $c;
        }
        return $out;
    }

    private function status( array $facts, string $id ): ?string {
        return $this->byId( $facts )[ $id ]['status'] ?? null;
    }

    // ---- a clean site -------------------------------------------------------

    public function testAReadySiteHasNoFailuresAndNoWarnings(): void {
        $n = MyNJILGA_Launch_Checks::summarize( MyNJILGA_Launch_Checks::evaluate( $this->goodFacts() ) );
        $this->assertSame( 0, $n['fail'] );
        $this->assertSame( 0, $n['warn'] );
        $this->assertTrue( $n['ok'] >= 8 );
        $this->assertTrue( $n['info'] >= 3, 'the by-eye confirmations are still listed' );
    }

    // ---- Stripe -------------------------------------------------------------

    public function testTestModeIsAWarningNotAFailure(): void {
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'mode' => 'test' ] ), 'mode' ) );
        $this->assertSame( 'ok', $this->status( $this->goodFacts(), 'mode' ) );
    }

    public function testNotConnectedFailsAndSkipsTheHealthCheck(): void {
        $c = $this->byId( $this->goodFacts( [ 'connected' => false ] ) );
        $this->assertSame( 'fail', $c['connected']['status'] );
        $this->assertFalse( isset( $c['health'] ) );
    }

    public function testAStripeHealthProblemFailsWithStripesOwnMessage(): void {
        $c = $this->byId( $this->goodFacts( [ 'health' => [ 'This site\'s webhook endpoint exists in Stripe, but its signing secret is not on file here.', 'another' ] ] ) );
        $this->assertSame( 'fail', $c['health']['status'] );
        $this->assertTrue( strpos( $c['health']['detail'], 'signing secret' ) !== false );
        $this->assertSame( 'P', $c['health']['fix']['url'], 'the fix link goes to Settings → Payments' );
    }

    public function testAHealthCheckThatCouldNotRunIsAWarningNotAFailure(): void {
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'health' => null ] ), 'health' ) );
    }

    public function testStripeAdvisoryWarningsAreSurfaced(): void {
        $c = $this->byId( $this->goodFacts( [ 'health_warnings' => [ 'No webhook events in 90 days.', 'ACH is not enabled.' ] ] ) );
        $this->assertSame( 'warn', $c['health_warning_0']['status'] );
        $this->assertSame( 'warn', $c['health_warning_1']['status'] );
    }

    public function testStripesOwnEmailsAreAlwaysAnInfoToConfirm(): void {
        $this->assertSame( 'info', $this->status( $this->goodFacts(), 'stripe_emails' ) );
    }

    // ---- Background jobs ----------------------------------------------------

    public function testNoActionSchedulerFails(): void {
        $c = $this->byId( $this->goodFacts( [ 'as_available' => false ] ) );
        $this->assertSame( 'fail', $c['as']['status'] );
        $this->assertFalse( isset( $c['reconcile'] ) );
    }

    public function testTheDailyReconcileBeingMissingIsAWarning(): void {
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'reconcile_scheduled' => false ] ), 'reconcile' ) );
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'reconcile_scheduled' => null ] ), 'reconcile' ) );
        $this->assertSame( 'ok', $this->status( $this->goodFacts(), 'reconcile' ) );
    }

    public function testStuckStripeEventsAreAWarning(): void {
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'events_stuck' => 3 ] ), 'events' ) );
        $this->assertSame( 'ok', $this->status( $this->goodFacts( [ 'events_stuck' => 0 ] ), 'events' ) );
        $this->assertSame( null, $this->status( $this->goodFacts( [ 'events_stuck' => null ] ), 'events' ), 'unknown → not shown' );
    }

    public function testStuckEventsAreOnlyThoseReceivedAndLeftBehind(): void {
        $now = '2026-11-10 12:00:00';
        $rows = [
            (object) [ 'status' => 'received',  'received_at' => '2026-11-10 11:30:00' ], // old, stuck
            (object) [ 'status' => 'received',  'received_at' => '2026-11-10 11:55:00' ], // just arrived: still being processed
            (object) [ 'status' => 'processed', 'received_at' => '2026-11-10 09:00:00' ],
            (object) [ 'status' => 'failed',    'received_at' => '2026-11-10 09:00:00' ],
            (object) [ 'status' => 'received',  'received_at' => '2026-11-09 08:00:00' ], // very old, stuck
        ];
        $this->assertSame( 2, MyNJILGA_Launch_Checks::stuck_events( $rows, $now ) );
        $this->assertSame( 0, MyNJILGA_Launch_Checks::stuck_events( $rows, 'not a date' ) );
    }

    // ---- Dues settings ------------------------------------------------------

    public function testAMissingCategoryTagFailsAndNamesIt(): void {
        $f = $this->goodFacts();
        $f['categories'][0]['tag_exists'] = false;
        $c = $this->byId( $f );
        $this->assertSame( 'fail', $c['tags']['status'] );
        $this->assertTrue( strpos( $c['tags']['detail'], 'Law Student' ) !== false && strpos( $c['tags']['detail'], 'law-student' ) !== false );
        $this->assertSame( 'ok', $this->status( $this->goodFacts(), 'tags' ) );
    }

    public function testABrokenTierLadderFails(): void {
        $f = $this->goodFacts();
        $f['categories'][1]['tiers'][0]['from'] = 0; // the exact thing a blank "From" does
        $f['categories'][1]['tiers'][0]['from'] = 2;
        $this->assertSame( 'fail', $this->status( $f, 'tiers' ) );
        $this->assertSame( null, $this->status( $this->goodFacts(), 'tiers' ), 'a good ladder raises nothing' );
    }

    private function cat( array $tiers ): array {
        return [ 'tier_eligible' => true, 'tiers' => $tiers ];
    }

    public function testTierLadderProblemsAreFoundAndTheGoodOneIsClean(): void {
        $good = $this->cat( [
            [ 'label' => 'First', 'from' => 1, 'to' => 1 ], [ 'label' => '2-5', 'from' => 2, 'to' => 5 ], [ 'label' => '6+', 'from' => 6, 'to' => 0 ],
        ] );
        $this->assertSame( [], MyNJILGA_Launch_Checks::tier_problems( $good ) );
        $this->assertSame( [], MyNJILGA_Launch_Checks::tier_problems( [ 'tier_eligible' => false, 'tiers' => [] ] ), 'flat categories have no ladder' );

        $this->assertCount( 1, MyNJILGA_Launch_Checks::tier_problems( $this->cat( [] ) ) );

        $startsAt2 = $this->cat( [ [ 'label' => 'A', 'from' => 2, 'to' => 5 ], [ 'label' => 'B', 'from' => 6, 'to' => 0 ] ] );
        $this->assertTrue( strpos( implode( ' ', MyNJILGA_Launch_Checks::tier_problems( $startsAt2 ) ), 'not 1' ) !== false );

        $gap = $this->cat( [ [ 'label' => 'A', 'from' => 1, 'to' => 2 ], [ 'label' => 'B', 'from' => 5, 'to' => 0 ] ] );
        $this->assertTrue( strpos( implode( ' ', MyNJILGA_Launch_Checks::tier_problems( $gap ) ), 'ranks 3–4 fall in no tier' ) !== false );

        $overlap = $this->cat( [ [ 'label' => 'A', 'from' => 1, 'to' => 5 ], [ 'label' => 'B', 'from' => 4, 'to' => 0 ] ] );
        $this->assertTrue( strpos( implode( ' ', MyNJILGA_Launch_Checks::tier_problems( $overlap ) ), 'overlap' ) !== false );

        $closedAtEnd = $this->cat( [ [ 'label' => 'A', 'from' => 1, 'to' => 1 ], [ 'label' => 'B', 'from' => 2, 'to' => 5 ] ] );
        $this->assertTrue( strpos( implode( ' ', MyNJILGA_Launch_Checks::tier_problems( $closedAtEnd ) ), 'last tier stops at rank 5' ) !== false );

        $openInMiddle = $this->cat( [ [ 'label' => 'A', 'from' => 1, 'to' => 0 ], [ 'label' => 'B', 'from' => 2, 'to' => 0 ] ] );
        $this->assertTrue( strpos( implode( ' ', MyNJILGA_Launch_Checks::tier_problems( $openInMiddle ) ), 'open-ended but is not the last' ) !== false );
    }

    public function testPricesAreSpelledOutForAConfirmingEye(): void {
        $c = $this->byId( $this->goodFacts() );
        $this->assertSame( 'info', $c['prices']['status'] );
        $this->assertTrue( strpos( $c['prices']['detail'], 'Law Student — $30.00 flat' ) !== false );
        $this->assertTrue( strpos( $c['prices']['detail'], '$125.00 1st member, $75.00 members 2–5, free members 6+' ) !== false );
        $this->assertTrue( strpos( $c['prices']['detail'], '$200.00' ) !== false, 'the dinner assessment is listed too' );
    }

    public function testUntaggedContactsAreFlaggedBecauseTheyWillBeBilledAsTheDefault(): void {
        $c = $this->byId( $this->goodFacts( [ 'defaulted_total' => 14 ] ) );
        $this->assertSame( 'warn', $c['default_category']['status'] );
        $this->assertTrue( strpos( $c['default_category']['title'], '14 contacts' ) !== false );
        $this->assertTrue( strpos( $c['default_category']['detail'], 'billed as Professional' ) !== false );
        $this->assertSame( 'ok', $this->status( $this->goodFacts(), 'default_category' ) );
        $this->assertSame( 'info', $this->status( $this->goodFacts( [ 'defaulted_total' => null ] ), 'default_category' ), 'unknown count → just say what the default is' );
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'default_category' => '' ] ), 'default_category' ), 'no default at all' );
    }

    public function testJoinersPayingTheOldYearIsFlaggedWithAFixLink(): void {
        $c = $this->byId( $this->goodFacts( [ 'join_year_now' => 2026 ] ) );
        $this->assertSame( 'warn', $c['join_year']['status'] );
        $this->assertTrue( strpos( $c['join_year']['title'], '2026, not 2027' ) !== false );
        $this->assertSame( 'S2', $c['join_year']['fix']['url'] );
        $this->assertSame( 'ok', $this->status( $this->goodFacts(), 'join_year' ) );
        $this->assertSame( null, $this->status( $this->goodFacts( [ 'join_enabled' => false, 'join_year_now' => 2026 ] ), 'join_year' ), 'joining off → not relevant' );
    }

    public function testALargeCreationBatchIsAWarning(): void {
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'batch_size' => 25 ] ), 'batch' ) );
        $this->assertSame( null, $this->status( $this->goodFacts( [ 'batch_size' => 10 ] ), 'batch' ) );
    }

    // ---- Emails -------------------------------------------------------------

    public function testMissingReplyToAndRemittanceAreWarnings(): void {
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'reply_to' => '' ] ), 'reply_to' ) );
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'remittance' => '  ' ] ), 'remittance' ) );
        $this->assertSame( 'ok', $this->status( $this->goodFacts(), 'reply_to' ) );
        $this->assertSame( 'ok', $this->status( $this->goodFacts(), 'remittance' ) );
    }

    public function testMailStatesAreFailWarnAndOk(): void {
        $this->assertSame( 'fail', $this->status( $this->goodFacts( [ 'mail_ok' => false ] ), 'mail' ) );
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'mail_test_at' => null ] ), 'mail' ), 'never tested' );
        $this->assertSame( 'ok', $this->status( $this->goodFacts(), 'mail' ) );
    }

    public function testTheDueDateIsShown(): void {
        $c = $this->byId( $this->goodFacts() );
        $this->assertTrue( strpos( $c['due']['title'], 'December 31, 2026' ) !== false );
        $this->assertSame( null, $this->status( $this->goodFacts( [ 'due_ts' => 0 ] ), 'due' ) );
    }

    public function testTheCcDescriptionFollowsThePolicy(): void {
        $this->assertTrue( strpos( MyNJILGA_Launch_Checks::cc_description( [ 'send_cc_mode' => 'owner_only' ] ), 'nobody is copied' ) !== false );
        $this->assertTrue( strpos( MyNJILGA_Launch_Checks::cc_description( [ 'send_cc_mode' => 'all_members' ] ), 'every member' ) !== false );
        $this->assertTrue( strpos( MyNJILGA_Launch_Checks::cc_description( [ 'send_cc_mode' => 'custom', 'send_cc_emails' => 'a@x.org, b@x.org' ] ), 'a@x.org, b@x.org' ) !== false );
        $this->assertTrue( strpos( MyNJILGA_Launch_Checks::cc_description( [ 'send_cc_mode' => 'custom', 'send_cc_emails' => '' ] ), 'empty' ) !== false );
    }

    // ---- Members and firms --------------------------------------------------

    public function testFirmsWithoutAnOwnerAndLegacyTagsAreWarnings(): void {
        $c = $this->byId( $this->goodFacts( [ 'firms_no_owner' => 4, 'legacy_paid_tag' => 120 ] ) );
        $this->assertSame( 'warn', $c['owners']['status'] );
        $this->assertTrue( strpos( $c['owners']['title'], '4 firms have no Owner' ) !== false );
        $this->assertSame( 'warn', $c['legacy_tag']['status'] );
        $this->assertTrue( strpos( $c['legacy_tag']['title'], '120 members carry only' ) !== false );
        $this->assertSame( 'warn', $this->status( $this->goodFacts( [ 'firms_no_owner' => 1 ] ), 'owners' ) );
        $this->assertTrue( strpos( $this->byId( $this->goodFacts( [ 'firms_no_owner' => 1 ] ) )['owners']['title'], '1 firm has no Owner' ) !== false, 'singular reads properly' );
        $this->assertSame( null, $this->status( $this->goodFacts( [ 'firms_no_owner' => null, 'legacy_paid_tag' => null ] ), 'owners' ), 'unknown → not shown' );
    }

    // ---- The preview --------------------------------------------------------

    private function invoiceRow( int $id, string $status, array $snapshot = [], array $over = [] ): object {
        $snap = array_merge( [ 'version' => 2, 'company' => [ 'name' => 'Firm ' . $id ], 'bill_to' => [ 'contact_id' => 1, 'name' => 'Owner', 'email' => 'o' . $id . '@firm.test' ], 'members' => [] ], $snapshot );
        return (object) array_merge( [ 'id' => $id, 'status' => $status, 'invoice_kind' => 'combined', 'total_amount_cents' => 12500, 'last_error' => '', 'roster_snapshot' => json_encode( $snap ) ], $over );
    }

    public function testTheReviewCountsEachBucketAndSumsReadyDollars(): void {
        $rows = [
            $this->invoiceRow( 1, 'draft' ), $this->invoiceRow( 2, 'draft', [], [ 'total_amount_cents' => 20000 ] ),
            $this->invoiceRow( 3, 'approved' ), $this->invoiceRow( 4, 'created' ), $this->invoiceRow( 5, 'sent' ),
            $this->invoiceRow( 6, 'processing' ), $this->invoiceRow( 7, 'paid' ),
            $this->invoiceRow( 8, 'excluded', [ 'exclusion_reason' => 'excluded_no_owner' ], [ 'total_amount_cents' => 0 ] ),
            $this->invoiceRow( 9, 'excluded', [ 'exclusion_reason' => 'excluded_no_members' ] ),
            $this->invoiceRow( 10, 'excluded', [ 'exclusion_reason' => 'excluded_zero_total' ] ),
            $this->invoiceRow( 11, 'excluded', [ 'exclusion_reason' => 'something_new' ] ),
            $this->invoiceRow( 12, 'paid', [], [ 'invoice_kind' => 'join' ] ), // an online join: not a firm invoice
        ];
        $r = MyNJILGA_Launch_Checks::review( $rows );
        $this->assertSame( 11, $r['total'], 'the online join is left out' );
        $this->assertSame( 2, $r['ready'] );
        $this->assertSame( 32500, $r['ready_cents'] );
        $this->assertSame( 1, $r['creating'] );
        $this->assertSame( 1, $r['created_unsent'] );
        $this->assertSame( 2, $r['sent'], 'processing (ACH clearing) counts as sent' );
        $this->assertSame( 1, $r['paid'] );
        $this->assertSame( 4, $r['excluded'] );
        $this->assertSame( [ 'no_owner' => 1, 'no_members' => 1, 'zero_total' => 1, 'other' => 1 ], $r['excluded_by'] );
        $this->assertSame( 4, count( $r['attention'] ) );
    }

    public function testTheAttentionListIsAlphabetical(): void {
        $rows = [
            $this->invoiceRow( 1, 'excluded', [ 'exclusion_reason' => 'excluded_no_owner', 'company' => [ 'name' => 'Zeta LLP' ] ] ),
            $this->invoiceRow( 2, 'excluded', [ 'exclusion_reason' => 'excluded_no_owner', 'company' => [ 'name' => 'alpha PC' ] ] ),
        ];
        $names = array_column( MyNJILGA_Launch_Checks::review( $rows )['attention'], 'name' );
        $this->assertSame( [ 'alpha PC', 'Zeta LLP' ], $names );
    }

    public function testDraftsWithNoBillToEmailAreCounted(): void {
        $rows = [ $this->invoiceRow( 1, 'draft' ), $this->invoiceRow( 2, 'draft', [ 'bill_to' => [ 'contact_id' => 5, 'name' => 'No Email', 'email' => '' ] ] ) ];
        $this->assertSame( 1, MyNJILGA_Launch_Checks::review( $rows )['no_bill_to_email'] );
    }

    public function testRowsWithAnErrorAreCountedWhateverTheirStage(): void {
        $rows = [
            $this->invoiceRow( 1, 'draft', [], [ 'last_error' => 'Stripe declined.' ] ),
            $this->invoiceRow( 2, 'created', [], [ 'last_error' => 'wp_mail() failed' ] ),
            $this->invoiceRow( 3, 'paid', [], [ 'last_error' => 'old note on a paid row' ] ), // not an open problem
        ];
        $r = MyNJILGA_Launch_Checks::review( $rows );
        $this->assertSame( 2, $r['errors'] );
        $this->assertSame( [ 1, 2 ], array_column( $r['error_rows'], 'id' ) );
    }

    public function testNoPreviewIsAnInfoNotAProblem(): void {
        $checks = MyNJILGA_Launch_Checks::review_checks( MyNJILGA_Launch_Checks::review( [] ), 2027 );
        $this->assertCount( 1, $checks );
        $this->assertSame( 'info', $checks[0]['status'] );
    }

    public function testThePreviewVerdicts(): void {
        $rows = [
            $this->invoiceRow( 1, 'draft' ),
            $this->invoiceRow( 2, 'draft', [ 'bill_to' => [ 'contact_id' => 5, 'name' => 'No Email', 'email' => '' ] ], [ 'last_error' => 'boom' ] ),
            $this->invoiceRow( 3, 'excluded', [ 'exclusion_reason' => 'excluded_no_owner' ] ),
            $this->invoiceRow( 4, 'excluded', [ 'exclusion_reason' => 'excluded_zero_total' ] ),
        ];
        $by = [];
        foreach ( MyNJILGA_Launch_Checks::review_checks( MyNJILGA_Launch_Checks::review( $rows ), 2027, 'I' ) as $c ) {
            $by[ $c['id'] ] = $c;
        }
        $this->assertSame( 'ok', $by['exists']['status'] );
        $this->assertSame( 'fail', $by['errors']['status'] );
        $this->assertSame( 'warn', $by['no_owner']['status'] );
        $this->assertSame( 'info', $by['zero_total']['status'] );
        $this->assertSame( 'warn', $by['bill_to']['status'] );
        $this->assertSame( 'I', $by['errors']['fix']['url'] );
    }

    public function testAPreviewWhereEverythingWasExcludedSaysSo(): void {
        $rows = [ $this->invoiceRow( 1, 'excluded', [ 'exclusion_reason' => 'excluded_no_owner' ] ) ];
        $ids  = array_column( MyNJILGA_Launch_Checks::review_checks( MyNJILGA_Launch_Checks::review( $rows ), 2027 ), 'id' );
        $this->assertTrue( in_array( 'nothing_ready', $ids, true ) );
        $rows[] = $this->invoiceRow( 2, 'draft' );
        $ids = array_column( MyNJILGA_Launch_Checks::review_checks( MyNJILGA_Launch_Checks::review( $rows ), 2027 ), 'id' );
        $this->assertFalse( in_array( 'nothing_ready', $ids, true ) );
    }

    public function testASuccessfulPreviewRaisesNoWarnings(): void {
        $rows   = [ $this->invoiceRow( 1, 'draft' ), $this->invoiceRow( 2, 'draft' ) ];
        $checks = MyNJILGA_Launch_Checks::review_checks( MyNJILGA_Launch_Checks::review( $rows ), 2027 );
        $n      = MyNJILGA_Launch_Checks::summarize( $checks );
        $this->assertSame( 0, $n['fail'] + $n['warn'] );
    }
}
