<?php
/**
 * The Launch Wizard page (MyNJILGA_Page_Launch): the pure helpers, and the
 * shape of every admin-post handler.
 *
 * The handlers are the part that must never regress: WordPress passes an
 * argument-less action's callbacks an empty STRING, so a handler that
 * declares a typed parameter is a site-wide fatal (it was, once — 3.10.1).
 * They are checked by reflection here and by real WP_Hook dispatch in the
 * harness.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/launch/class-launch-schedule.php';
require_once dirname( __DIR__ ) . '/includes/launch/class-page-launch.php';

class LaunchPageTest extends NJILGA_TestCase {

    private function ny(): \DateTimeZone {
        return new \DateTimeZone( 'America/New_York' );
    }

    // ---- default start --------------------------------------------------

    public function testDefaultStartIsNextMorningWhenItIsAlreadyDaytime(): void {
        // Tue Nov 10 2026, 10:00 EST.
        $this->assertSame( '2026-11-11T09:00', MyNJILGA_Page_Launch::default_start( strtotime( '2026-11-10 15:00:00 UTC' ), $this->ny() ) );
    }

    public function testDefaultStartIsTodayWhenItIsEarlyEnough(): void {
        // Tue Nov 10 2026, 05:30 EST: 9 am today is more than an hour away.
        $this->assertSame( '2026-11-10T09:00', MyNJILGA_Page_Launch::default_start( strtotime( '2026-11-10 10:30:00 UTC' ), $this->ny() ) );
    }

    public function testDefaultStartSkipsTheWeekend(): void {
        // Fri Nov 13 10:00 EST → Monday.
        $this->assertSame( '2026-11-16T09:00', MyNJILGA_Page_Launch::default_start( strtotime( '2026-11-13 15:00:00 UTC' ), $this->ny() ) );
        // Sat Nov 14 07:00 EST → still Monday.
        $this->assertSame( '2026-11-16T09:00', MyNJILGA_Page_Launch::default_start( strtotime( '2026-11-14 12:00:00 UTC' ), $this->ny() ) );
    }

    public function testDefaultStartIsAlwaysAtLeastAnHourAway(): void {
        for ( $m = 0; $m < 24 * 60 * 3; $m += 37 ) {
            $now = strtotime( '2026-11-12 00:00:00 UTC' ) + $m * 60;
            $ts  = ( new \DateTimeImmutable( MyNJILGA_Page_Launch::default_start( $now, $this->ny() ), $this->ny() ) )->getTimestamp();
            $this->assertTrue( $ts >= $now + 3600, 'start must be at least an hour out (now=' . $now . ')' );
            $this->assertTrue( (int) ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->ny() )->format( 'N' ) < 6, 'start must be a weekday' );
        }
    }

    public function testDefaultStartAcrossTheSpringForwardGap(): void {
        // Sat Mar 13 2027 20:00 EST → Monday Mar 15, after DST began on Sunday: 9:00 EDT.
        $v  = MyNJILGA_Page_Launch::default_start( strtotime( '2027-03-14 01:00:00 UTC' ), $this->ny() );
        $this->assertSame( '2027-03-15T09:00', $v );
    }

    public function testFormatTimeUsesTheSiteZone(): void {
        $this->assertSame( 'Mon, Nov 16, 2026 at 9:00 am EST', MyNJILGA_Page_Launch::fmt_time( strtotime( '2026-11-16 14:00:00 UTC' ), $this->ny() ) );
        $this->assertSame( 'Mon, Jul 5, 2027 at 9:00 am EDT', MyNJILGA_Page_Launch::fmt_time( strtotime( '2027-07-05 13:00:00 UTC' ), $this->ny() ) );
    }

    // ---- input cleaning ---------------------------------------------------

    public function testCutoverAcceptsBlankAndMonthDayOnly(): void {
        $this->assertSame( '', MyNJILGA_Page_Launch::clean_cutover( '' ) );
        $this->assertSame( '', MyNJILGA_Page_Launch::clean_cutover( '   ' ) );
        $this->assertSame( '11-01', MyNJILGA_Page_Launch::clean_cutover( '11-01' ) );
        $this->assertSame( '11-01', MyNJILGA_Page_Launch::clean_cutover( ' 11-01 ' ) );
        $this->assertSame( '11-01', MyNJILGA_Page_Launch::clean_cutover( "11-01\n" ) ); // surrounding whitespace is trimmed, never stored
        $this->assertSame( '12-31', MyNJILGA_Page_Launch::clean_cutover( '12-31' ) );
    }

    public function testCutoverRejectsAnythingElse(): void {
        foreach ( [ '13-01', '00-10', '11-32', '11-00', '1-1', '2026-11-01', 'Nov 1', '11/01', '11-01x', '11-01-', '1101' ] as $bad ) {
            $this->assertSame( null, MyNJILGA_Page_Launch::clean_cutover( $bad ), 'should reject ' . json_encode( $bad ) );
        }
    }

    public function testParseIdsKeepsPositiveUniqueInts(): void {
        $this->assertSame( [ 3, 7, 12 ], MyNJILGA_Page_Launch::parse_ids( [ '3', 7, '7', 'x', 0, -4, '12', '' ] ) );
        $this->assertSame( [], MyNJILGA_Page_Launch::parse_ids( 'nope' ) );
        $this->assertSame( [], MyNJILGA_Page_Launch::parse_ids( null ) );
        $this->assertSame( [], MyNJILGA_Page_Launch::parse_ids( [] ) );
    }

    // ---- stepper pills ------------------------------------------------------

    private function review( array $over = [] ): array {
        return array_replace_recursive( [
            'total' => 0, 'ready' => 0, 'ready_cents' => 0, 'creating' => 0, 'created_unsent' => 0, 'sent' => 0, 'paid' => 0,
            'excluded' => 0, 'excluded_by' => [ 'no_owner' => 0, 'no_members' => 0, 'zero_total' => 0, 'other' => 0 ], 'errors' => 0,
        ], $over );
    }

    public function testStepTwoPillFollowsWhetherAPreviewExists(): void {
        $this->assertSame( [ 'Not generated', 'muted' ], MyNJILGA_Page_Launch::step_pill( 2, $this->review(), null ) );
        $this->assertSame( [ 'Generated', 'success' ], MyNJILGA_Page_Launch::step_pill( 2, $this->review( [ 'total' => 12 ] ), null ) );
    }

    public function testStepThreePillCountsWhatNeedsAPerson(): void {
        $this->assertSame( [ '', 'muted' ], MyNJILGA_Page_Launch::step_pill( 3, $this->review(), null ) );
        $this->assertSame( [ '9 ready', 'info' ], MyNJILGA_Page_Launch::step_pill( 3, $this->review( [ 'total' => 9, 'ready' => 9 ] ), null ) );
        $this->assertSame( [ '5 need attention', 'warning' ], MyNJILGA_Page_Launch::step_pill( 3, $this->review( [ 'total' => 9, 'ready' => 4, 'excluded_by' => [ 'no_owner' => 3, 'other' => 1 ], 'errors' => 1 ] ), null ) );
        // Empty or fully-exempt firms are not "attention".
        $this->assertSame( [ '9 ready', 'info' ], MyNJILGA_Page_Launch::step_pill( 3, $this->review( [ 'total' => 9, 'ready' => 9, 'excluded_by' => [ 'no_members' => 4, 'zero_total' => 2 ] ] ), null ) );
    }

    public function testStepFourPillCountsCreatedInvoices(): void {
        $this->assertSame( [ 'None yet', 'muted' ], MyNJILGA_Page_Launch::step_pill( 4, $this->review( [ 'total' => 9, 'ready' => 9 ] ), null ) );
        $this->assertSame( [ '6 created', 'success' ], MyNJILGA_Page_Launch::step_pill( 4, $this->review( [ 'created_unsent' => 3, 'sent' => 2, 'paid' => 1 ] ), null ) );
        $this->assertSame( [ 'Creating…', 'info' ], MyNJILGA_Page_Launch::step_pill( 4, $this->review( [ 'creating' => 4, 'created_unsent' => 3 ] ), null ) );
    }

    public function testStepFivePillShowsTheScheduleOrTheUnsentCount(): void {
        $this->assertSame( [ '—', 'muted' ], MyNJILGA_Page_Launch::step_pill( 5, $this->review(), null ) );
        $this->assertSame( [ '14 to send', 'info' ], MyNJILGA_Page_Launch::step_pill( 5, $this->review( [ 'created_unsent' => 14 ] ), null ) );
        $this->assertSame( [ 'Scheduled', 'info' ], MyNJILGA_Page_Launch::step_pill( 5, $this->review( [ 'created_unsent' => 14 ] ), [ 'status' => MyNJILGA_Launch_Schedule::ST_SCHEDULED ] ) );
        $this->assertSame( [ 'Halted — needs you', 'destructive' ], MyNJILGA_Page_Launch::step_pill( 5, $this->review(), [ 'status' => MyNJILGA_Launch_Schedule::ST_HALTED ] ) );
        $this->assertSame( [ 'Complete', 'success' ], MyNJILGA_Page_Launch::step_pill( 5, $this->review(), [ 'status' => MyNJILGA_Launch_Schedule::ST_COMPLETE ] ) );
        // A cancelled schedule is history: the step falls back to what is left to send.
        $this->assertSame( [ '3 to send', 'info' ], MyNJILGA_Page_Launch::step_pill( 5, $this->review( [ 'created_unsent' => 3 ] ), [ 'status' => MyNJILGA_Launch_Schedule::ST_CANCELLED ] ) );
    }

    public function testEveryScheduleStatusHasALabelAndAKnownPillVariant(): void {
        $known = [ 'success', 'info', 'warning', 'destructive', 'muted' ];
        foreach ( [ 'scheduled', 'running', 'paused', 'halted', 'complete', 'cancelled' ] as $st ) {
            $p = MyNJILGA_Page_Launch::status_pill( $st );
            $this->assertTrue( $p[0] !== '', $st . ' needs a label' );
            $this->assertTrue( in_array( $p[1], $known, true ), $st . ' needs a known variant' );
        }
        $this->assertSame( [ 'Mystery', 'muted' ], MyNJILGA_Page_Launch::status_pill( 'mystery' ) );
    }

    // ---- handler shape ------------------------------------------------------

    /** @return array<string,string> action constant name => handler method */
    private function handlers(): array {
        return [
            'ACTION_CUTOVER'  => 'handle_cutover',
            'ACTION_PREVIEW'  => 'handle_preview',
            'ACTION_CREATE'   => 'handle_create',
            'ACTION_MAILTEST' => 'handle_mailtest',
            'ACTION_SAMPLE'   => 'handle_sample',
            'ACTION_SEND_NOW' => 'handle_send_now',
            'ACTION_SCHEDULE' => 'handle_schedule',
            'ACTION_CONTROL'  => 'handle_control',
        ];
    }

    public function testEveryHandlerIsPublicStaticAndTakesNoParameters(): void {
        foreach ( $this->handlers() as $const => $method ) {
            $ref = new \ReflectionMethod( 'MyNJILGA_Page_Launch', $method );
            $this->assertTrue( $ref->isPublic() && $ref->isStatic(), $method . ' must be public static' );
            // admin-post passes '' to an argument-less action: a declared parameter is a fatal waiting to happen.
            $this->assertSame( 0, $ref->getNumberOfParameters(), $method . ' must take no parameters' );
        }
    }

    public function testEveryActionConstantIsUniqueAndHasAHandler(): void {
        $seen = [];
        $all  = ( new \ReflectionClass( 'MyNJILGA_Page_Launch' ) )->getConstants();
        foreach ( $this->handlers() as $const => $method ) {
            $this->assertTrue( isset( $all[ $const ] ), $const . ' must exist' );
            $this->assertTrue( strpos( (string) $all[ $const ], 'my_njilga_launch_' ) === 0, $const . ' is namespaced' );
            $this->assertTrue( ! isset( $seen[ $all[ $const ] ] ), $const . ' must be unique' );
            $seen[ $all[ $const ] ] = true;
        }
        // No ACTION_* constant without a registered handler.
        foreach ( array_keys( $all ) as $name ) {
            if ( strpos( $name, 'ACTION_' ) === 0 ) {
                $this->assertTrue( isset( $this->handlers()[ $name ] ), $name . ' has no handler listed' );
            }
        }
    }

    public function testStepsAreOneToFive(): void {
        $this->assertSame( [ 1, 2, 3, 4, 5 ], array_keys( MyNJILGA_Page_Launch::STEPS ) );
    }
}
