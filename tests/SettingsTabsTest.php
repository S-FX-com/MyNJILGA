<?php
/**
 * The Settings page is split into tabs, each saving only its own settings.
 * The safety of that rests on one invariant — every setting belongs to
 * exactly one tab — which is pinned here, along with the Invoice Email tab's
 * pure helpers and the example firm its "Send test" uses.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-email.php';
require_once dirname( __DIR__ ) . '/includes/class-page-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-page-invoice-email.php';

class SettingsTabsTest extends NJILGA_TestCase {

    /** @return array<int,string> every `general` setting there is. */
    private function allGeneralKeys(): array {
        return array_keys( MyNJILGA_Dues_Settings::defaults()['general'] );
    }

    /** @return array<int,string> every `general` setting some tab claims. */
    private function claimedKeys(): array {
        $claimed = [];
        foreach ( MyNJILGA_Page_Settings::TAB_KEYS as $keys ) {
            $claimed = array_merge( $claimed, $keys );
        }
        return array_merge( $claimed, MyNJILGA_Page_Invoice_Email::KEYS );
    }

    public function testEverySettingBelongsToExactlyOneTab(): void {
        $claimed = $this->claimedKeys();
        $all     = $this->allGeneralKeys();

        $unhomed = array_values( array_diff( $all, $claimed ) );
        $this->assertSame( [], $unhomed, 'a setting no tab edits can never be changed: ' . implode( ', ', $unhomed ) );

        $phantom = array_values( array_diff( $claimed, $all ) );
        $this->assertSame( [], $phantom, 'a tab claims a setting that does not exist: ' . implode( ', ', $phantom ) );

        $dupes = array_keys( array_filter( array_count_values( $claimed ), static function ( $n ) { return $n > 1; } ) );
        $this->assertSame( [], $dupes, 'a setting saved by two tabs would be overwritten by whichever saves second: ' . implode( ', ', $dupes ) );
    }

    public function testTheTabsAreDescribedAndSavedConsistently(): void {
        $tabs = array_keys( MyNJILGA_Page_Settings::TABS );
        $this->assertSame( $tabs, array_keys( MyNJILGA_Page_Settings::TAB_BLURBS ), 'every tab has a header blurb' );

        // The tabs handle_save() serves, plus the two with their own handlers, are all of them.
        $served = array_merge( MyNJILGA_Page_Settings::SAVE_TABS, [ 'email', 'payments' ] );
        sort( $served );
        $sorted = $tabs;
        sort( $sorted );
        $this->assertSame( $sorted, $served );

        $this->assertFalse( in_array( 'email', MyNJILGA_Page_Settings::SAVE_TABS, true ), 'the email tab saves through its own handler' );
        $this->assertFalse( in_array( 'payments', MyNJILGA_Page_Settings::SAVE_TABS, true ) );
        foreach ( MyNJILGA_Page_Settings::SAVE_TABS as $tab ) {
            $this->assertTrue( isset( MyNJILGA_Page_Settings::TAB_KEYS[ $tab ] ), $tab . ' has a key list' );
        }
        $this->assertSame( 'categories', $tabs[0], 'the first tab is where the page opens' );
    }

    public function testTheSavingHandlersTakeNoParameters(): void {
        // admin-post passes '' to an argument-less action's callbacks: a declared parameter is a fatal.
        foreach ( [ [ 'MyNJILGA_Page_Settings', 'handle_save' ], [ 'MyNJILGA_Page_Settings', 'handle_reset' ], [ 'MyNJILGA_Page_Invoice_Email', 'handle_submit' ] ] as [ $class, $method ] ) {
            $ref = new \ReflectionMethod( $class, $method );
            $this->assertTrue( $ref->isPublic() && $ref->isStatic(), "$class::$method must be public static" );
            $this->assertSame( 0, $ref->getNumberOfParameters(), "$class::$method must take no parameters" );
        }
    }

    public function testTheEmailTabsSettingsAreTheOnesTheEmailReads(): void {
        // Everything the invoice email reads from settings is editable on its tab.
        foreach ( [ 'send_cc_mode', 'send_cc_emails', 'send_reply_to' ] as $k ) {
            $this->assertTrue( in_array( $k, MyNJILGA_Page_Invoice_Email::KEYS, true ), $k );
        }
        foreach ( MyNJILGA_Invoice_Email::FIELDS as $key ) {
            $this->assertTrue( in_array( $key, MyNJILGA_Page_Invoice_Email::KEYS, true ), $key );
        }
    }

    // ---- the Invoice Email tab's pure helpers ---------------------------------

    public function testWordingProblemsNameTheFieldAndTheTag(): void {
        $this->assertSame( [], MyNJILGA_Page_Invoice_Email::wording_problems( [ 'subject' => '{year} {firm}', 'intro' => 'Hi {first_name}', 'closing' => 'Thanks' ] ) );
        $this->assertSame(
            [ 'subject:frim', 'closing:sign' ],
            MyNJILGA_Page_Invoice_Email::wording_problems( [ 'subject' => '{year} {frim}', 'intro' => 'Hi {name}', 'closing' => 'Best, {sign}' ] )
        );
        $this->assertSame( [], MyNJILGA_Page_Invoice_Email::wording_problems( [] ), 'blank fields have no problems' );
        $this->assertSame( [], MyNJILGA_Page_Invoice_Email::wording_problems( [ 'cc_emails' => '{nope}' ] ), 'only the three wording fields are checked' );
    }

    public function testTheTestBannerSaysWhoGotItAndWhatItUsed(): void {
        $sample = MyNJILGA_Page_Invoice_Email::test_banner( 'me@example.org', [ 'to' => 'owen.owner@examplefirm.test' ], true );
        $this->assertTrue( strpos( $sample, 'sent only to me@example.org. No firm received this.' ) !== false );
        $this->assertTrue( strpos( $sample, 'example firm and a placeholder payment link' ) !== false );

        $real = MyNJILGA_Page_Invoice_Email::test_banner( 'me@example.org', [ 'to' => 'owner@realfirm.test', 'placeholder' => false ], false );
        $this->assertTrue( strpos( $real, 'the real email would go to owner@realfirm.test' ) !== false );
        $this->assertFalse( strpos( $real, 'has not been created' ) !== false );

        $draft = MyNJILGA_Page_Invoice_Email::test_banner( 'me@example.org', [ 'to' => 'owner@realfirm.test', 'placeholder' => true ], false );
        $this->assertTrue( strpos( $draft, 'has not been created yet, so the payment link is a placeholder' ) !== false );
    }

    // ---- the example firm ----------------------------------------------------

    public function testTheExampleFirmIsShapedLikeARealInvoice(): void {
        $row = MyNJILGA_Invoice_Email::sample_row( 2027 );
        $this->assertSame( 2027, $row->dues_year );
        $this->assertSame( 'Example Firm LLP', MyNJILGA_Dues_Snapshot::company_name( $row ) );
        $this->assertSame( 'owen.owner@examplefirm.test', MyNJILGA_Dues_Snapshot::bill_to( $row )['email'] );
        $this->assertSame( 'Owen', MyNJILGA_Dues_Snapshot::bill_to( $row )['first_name'] );
        $this->assertSame( 2, count( MyNJILGA_Dues_Snapshot::members( $row ) ) );
        $this->assertSame( '', (string) $row->hosted_invoice_url, 'no payment link: compose() shows the flagged placeholder' );
        $this->assertSame( 'combined', MyNJILGA_Dues_Snapshot::invoice_kind( $row ) );
    }

    public function testTheExampleFirmsTotalMatchesItsMembers(): void {
        $row     = MyNJILGA_Invoice_Email::sample_row( 2027 );
        $members = MyNJILGA_Dues_Snapshot::members( $row );
        $sum     = 0;
        foreach ( $members as $m ) {
            $sum += (int) $m['dues_cents'] + (int) $m['assessment_cents'];
        }
        $this->assertSame( (int) $row->total_amount_cents, $sum, 'the invoice total is the sum of its members' );
        $covers = MyNJILGA_Dues_Roster::email_summary( $members, 2027, 'combined' );
        $this->assertTrue( strpos( $covers, 'This invoice covers 2 people' ) !== false );
        $this->assertTrue( strpos( $covers, 'Total: $200.00' ) !== false );
        $this->assertTrue( strpos( $covers, 'Ann Able' ) !== false && strpos( $covers, 'Bob Baker' ) !== false );
    }

    public function testTheExampleFirmsEmailsCannotBeRealAddresses(): void {
        $row = MyNJILGA_Invoice_Email::sample_row( 2027 );
        $emails = [ MyNJILGA_Dues_Snapshot::bill_to( $row )['email'] ];
        foreach ( MyNJILGA_Dues_Snapshot::members( $row ) as $m ) {
            $emails[] = (string) $m['email'];
        }
        foreach ( $emails as $e ) {
            $this->assertTrue( substr( $e, -strlen( '.test' ) ) === '.test', $e . ' must be on the reserved .test domain, so nothing can ever reach a real person' );
        }
    }
}
