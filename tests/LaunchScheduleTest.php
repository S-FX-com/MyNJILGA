<?php
/**
 * The Launch Wizard's scheduled invoice-email delivery
 * (MyNJILGA_Launch_Schedule).
 *
 * The runner is exercised against a simulated world — a clock, a lock, a
 * list of invoice rows, a send that can succeed or fail per invoice, an
 * Action Scheduler that records what was scheduled — because this is the code
 * that emails real firms, and its dangerous paths (overlapping runs, a mode
 * flip, repeated mail failure, a pause landing mid-batch, a stalled
 * scheduler) cannot be left to be discovered in production.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/launch/class-launch-schedule.php';

class LaunchScheduleTest extends NJILGA_TestCase {

    private const NOW = 1794000000; // 2026-11-05 ... a fixed "now" (UTC)

    private function ny(): DateTimeZone {
        return new DateTimeZone( 'America/New_York' );
    }

    // ======================================================================
    // Pure: options
    // ======================================================================

    private function input( array $over = [] ): array {
        return array_merge( [ 'start' => '', 'batch_size' => '25', 'interval_min' => '15' ], $over );
    }

    public function testBlankStartMeansAsSoonAsAllowed(): void {
        $r = MyNJILGA_Launch_Schedule::normalize_options( $this->input(), self::NOW, $this->ny() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( self::NOW + MyNJILGA_Launch_Schedule::MIN_LEAD, $r['options']['start_ts'] );
        $this->assertSame( 25, $r['options']['batch_size'] );
        $this->assertSame( 15, $r['options']['interval_min'] );
        $this->assertFalse( $r['options']['has_window'] );
    }

    public function testAStartTimeIsReadInTheSiteTimezone(): void {
        // 2026-11-16 09:00 in New York (EST, UTC-5) is 14:00 UTC.
        $r = MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'start' => '2026-11-16T09:00' ] ), strtotime( '2026-11-10 12:00:00 UTC' ), $this->ny() );
        $this->assertTrue( $r['ok'], $r['error'] );
        $this->assertSame( strtotime( '2026-11-16 14:00:00 UTC' ), $r['options']['start_ts'] );
    }

    public function testBadStartTimesAreRefused(): void {
        $now = strtotime( '2026-11-10 12:00:00 UTC' );
        foreach ( [ 'tomorrow', '2026-02-31T09:00', '2026-11-16T25:00', '16/11/2026 09:00', '2026-11-16' ] as $bad ) {
            $r = MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'start' => $bad ] ), $now, $this->ny() );
            $this->assertFalse( $r['ok'], "'$bad' must be refused" );
        }
    }

    /** The start must leave time to stop it, and not be absurdly far away. */
    public function testStartMustBeInTheFutureButWithinAYear(): void {
        $now = strtotime( '2026-11-10 12:00:00 UTC' );
        $this->assertFalse( MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'start' => '2026-11-10T07:00' ] ), $now, $this->ny() )['ok'], 'in the past' );
        $this->assertFalse( MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'start' => '2026-11-10T07:01' ] ), $now, $this->ny() )['ok'], 'inside the lead time' );
        $this->assertFalse( MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'start' => '2028-01-01T09:00' ] ), $now, $this->ny() )['ok'], 'too far' );
        $this->assertTrue( MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'start' => '2026-11-10T09:00' ] ), $now, $this->ny() )['ok'] );
    }

    public function testBatchSizeAndIntervalAreBounded(): void {
        foreach ( [ [ '0', '15' ], [ '201', '15' ], [ 'abc', '15' ], [ '25', '4' ], [ '25', '1441' ], [ '25', '' ] ] as $pair ) {
            $r = MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'batch_size' => $pair[0], 'interval_min' => $pair[1] ] ), self::NOW, $this->ny() );
            $this->assertFalse( $r['ok'], json_encode( $pair ) . ' must be refused' );
        }
        $this->assertTrue( MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'batch_size' => '200', 'interval_min' => '5' ] ), self::NOW, $this->ny() )['ok'] );
        $this->assertTrue( MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'batch_size' => '1', 'interval_min' => '1440' ] ), self::NOW, $this->ny() )['ok'] );
    }

    public function testTheSendingWindowIsValidated(): void {
        $ok = MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'window' => '1', 'window_start' => '9', 'window_end' => '17', 'weekdays_only' => '1' ] ), self::NOW, $this->ny() );
        $this->assertTrue( $ok['ok'] );
        $this->assertTrue( $ok['options']['has_window'] );
        $this->assertSame( 9, $ok['options']['window_start'] );
        $this->assertSame( 17, $ok['options']['window_end'] );
        $this->assertTrue( $ok['options']['weekdays_only'] );
        foreach ( [ [ '17', '9' ], [ '9', '9' ], [ '24', '24' ], [ '-1', '5' ], [ '9', '25' ], [ '9', '0' ] ] as $w ) {
            $r = MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'window' => '1', 'window_start' => $w[0], 'window_end' => $w[1] ] ), self::NOW, $this->ny() );
            $this->assertFalse( $r['ok'], json_encode( $w ) . ' must be refused' );
        }
        // Without the window box, stray hour fields are ignored.
        $r = MyNJILGA_Launch_Schedule::normalize_options( $this->input( [ 'window_start' => '30', 'window_end' => '2' ] ), self::NOW, $this->ny() );
        $this->assertTrue( $r['ok'] );
        $this->assertFalse( $r['options']['has_window'] );
    }

    // ======================================================================
    // Pure: when sending is allowed
    // ======================================================================

    private function opts( array $over = [] ): array {
        return array_merge( [ 'start_ts' => 0, 'batch_size' => 25, 'interval_min' => 15, 'weekdays_only' => false, 'has_window' => false, 'window_start' => 0, 'window_end' => 24 ], $over );
    }

    private function local( string $s ): int {
        return (int) ( new DateTimeImmutable( $s, $this->ny() ) )->getTimestamp();
    }

    private function fmt( int $ts ): string {
        return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->ny() )->format( 'D Y-m-d H:i T' );
    }

    public function testWithNoRestrictionsATimeIsUnchanged(): void {
        $ts = $this->local( '2026-11-14 23:30' ); // a Saturday night
        $this->assertSame( $ts, MyNJILGA_Launch_Schedule::align_to_window( $ts, $this->opts(), $this->ny() ) );
    }

    public function testAnAllowedTimeInsideTheWindowIsUnchanged(): void {
        $o  = $this->opts( [ 'weekdays_only' => true, 'has_window' => true, 'window_start' => 9, 'window_end' => 17 ] );
        $ts = $this->local( '2026-11-11 10:30' ); // Wednesday
        $this->assertSame( $ts, MyNJILGA_Launch_Schedule::align_to_window( $ts, $o, $this->ny() ) );
    }

    public function testBeforeTheWindowOpensWaitsForIt(): void {
        $o = $this->opts( [ 'has_window' => true, 'window_start' => 9, 'window_end' => 17 ] );
        $this->assertSame( 'Wed 2026-11-11 09:00 EST', $this->fmt( MyNJILGA_Launch_Schedule::align_to_window( $this->local( '2026-11-11 06:15' ), $o, $this->ny() ) ) );
    }

    public function testAfterTheWindowClosesWaitsForTomorrow(): void {
        $o = $this->opts( [ 'has_window' => true, 'window_start' => 9, 'window_end' => 17 ] );
        $this->assertSame( 'Thu 2026-11-12 09:00 EST', $this->fmt( MyNJILGA_Launch_Schedule::align_to_window( $this->local( '2026-11-11 17:00' ), $o, $this->ny() ) ), 'the end hour itself is already closed' );
        $this->assertSame( 'Wed 2026-11-11 16:59 EST', $this->fmt( MyNJILGA_Launch_Schedule::align_to_window( $this->local( '2026-11-11 16:59' ), $o, $this->ny() ) ), 'one minute before it is still open' );
    }

    public function testWeekendsAreSkippedWhenWeekdaysOnly(): void {
        $o = $this->opts( [ 'weekdays_only' => true, 'has_window' => true, 'window_start' => 9, 'window_end' => 17 ] );
        $this->assertSame( 'Mon 2026-11-16 09:00 EST', $this->fmt( MyNJILGA_Launch_Schedule::align_to_window( $this->local( '2026-11-14 11:00' ), $o, $this->ny() ) ), 'Saturday' );
        $this->assertSame( 'Mon 2026-11-16 09:00 EST', $this->fmt( MyNJILGA_Launch_Schedule::align_to_window( $this->local( '2026-11-15 20:00' ), $o, $this->ny() ) ), 'Sunday' );
        $this->assertSame( 'Mon 2026-11-16 09:00 EST', $this->fmt( MyNJILGA_Launch_Schedule::align_to_window( $this->local( '2026-11-13 17:30' ), $o, $this->ny() ) ), 'Friday after close' );
    }

    public function testWeekdaysOnlyWithoutAWindowStartsAtMidnightMonday(): void {
        $o = $this->opts( [ 'weekdays_only' => true ] );
        $this->assertSame( 'Mon 2026-11-16 00:00 EST', $this->fmt( MyNJILGA_Launch_Schedule::align_to_window( $this->local( '2026-11-14 11:00' ), $o, $this->ny() ) ) );
    }

    /** The clocks change on Sunday 14 March 2027 (EST → EDT): 9 am Monday is 13:00 UTC, not 14:00. */
    public function testTheWindowFollowsLocalTimeAcrossDaylightSavingChange(): void {
        $o  = $this->opts( [ 'weekdays_only' => true, 'has_window' => true, 'window_start' => 9, 'window_end' => 17 ] );
        $ts = MyNJILGA_Launch_Schedule::align_to_window( $this->local( '2027-03-12 17:30' ), $o, $this->ny() ); // Friday evening
        $this->assertSame( 'Mon 2027-03-15 09:00 EDT', $this->fmt( $ts ) );
        $this->assertSame( strtotime( '2027-03-15 13:00:00 UTC' ), $ts );
        // ...and back again in autumn (Sunday 7 November 2027).
        $ts = MyNJILGA_Launch_Schedule::align_to_window( $this->local( '2027-11-05 17:30' ), $o, $this->ny() );
        $this->assertSame( 'Mon 2027-11-08 09:00 EST', $this->fmt( $ts ) );
        $this->assertSame( strtotime( '2027-11-08 14:00:00 UTC' ), $ts );
    }

    public function testNextRunIsOneIntervalLaterThenAligned(): void {
        $this->assertSame( 'Wed 2026-11-11 10:15 EST', $this->fmt( MyNJILGA_Launch_Schedule::next_run_ts( $this->local( '2026-11-11 10:00' ), $this->opts(), $this->ny() ) ) );
        $o = $this->opts( [ 'has_window' => true, 'window_start' => 9, 'window_end' => 17 ] );
        $this->assertSame( 'Thu 2026-11-12 09:00 EST', $this->fmt( MyNJILGA_Launch_Schedule::next_run_ts( $this->local( '2026-11-11 16:50' ), $o, $this->ny() ) ), 'the next slot would be 17:05, outside the window' );
    }

    public function testEstimateCountsBatchesAndFindsTheLastOne(): void {
        $start = $this->local( '2026-11-11 10:00' );
        $e = MyNJILGA_Launch_Schedule::estimate( 100, $this->opts( [ 'start_ts' => $start, 'batch_size' => 25, 'interval_min' => 60 ] ), $this->ny() );
        $this->assertSame( 4, $e['batches'] );
        $this->assertSame( $start, $e['first_ts'] );
        $this->assertSame( 'Wed 2026-11-11 13:00 EST', $this->fmt( $e['last_ts'] ), 'four batches an hour apart: 10, 11, 12, 13' );
        $this->assertSame( 0, MyNJILGA_Launch_Schedule::estimate( 0, $this->opts( [ 'start_ts' => $start ] ), $this->ny() )['batches'] );
        $this->assertSame( 1, MyNJILGA_Launch_Schedule::estimate( 3, $this->opts( [ 'start_ts' => $start ] ), $this->ny() )['batches'] );
    }

    public function testEstimateWithAWindowSpillsOverToTheNextDay(): void {
        $start = $this->local( '2026-11-11 15:00' );
        // 9–17 window, 2 per batch every 60 minutes, 7 firms → 4 batches: 15:00, 16:00, (17:00 closed) next day 09:00, 10:00.
        $e = MyNJILGA_Launch_Schedule::estimate( 7, $this->opts( [ 'start_ts' => $start, 'batch_size' => 2, 'interval_min' => 60, 'has_window' => true, 'window_start' => 9, 'window_end' => 17 ] ), $this->ny() );
        $this->assertSame( 4, $e['batches'] );
        $this->assertSame( 'Thu 2026-11-12 10:00 EST', $this->fmt( $e['last_ts'] ) );
    }

    // ======================================================================
    // Pure: picking and classifying
    // ======================================================================

    private function row( int $id, string $status = 'created' ): object {
        return (object) [ 'id' => $id, 'status' => $status, 'invoice_kind' => 'combined', 'hosted_invoice_url' => 'https://pay.example/' . $id ];
    }

    public function testBatchesAreInIdOrderAndSkipInvoicesTriedTooOften(): void {
        $rows = [ $this->row( 30 ), $this->row( 10 ), $this->row( 20 ), $this->row( 40 ) ];
        $ids  = array_map( static function ( $r ) { return (int) $r->id; }, MyNJILGA_Launch_Schedule::pick_batch( $rows, [], 3 ) );
        $this->assertSame( [ 10, 20, 30 ], $ids );
        $ids = array_map( static function ( $r ) { return (int) $r->id; }, MyNJILGA_Launch_Schedule::pick_batch( $rows, [ 10 => MyNJILGA_Launch_Schedule::MAX_ATTEMPTS_PER_ROW, 20 => 1 ], 3 ) );
        $this->assertSame( [ 20, 30, 40 ], $ids, 'invoice 10 has used up its attempts; 20 has one left' );
    }

    public function testOnlyMailFailuresCountTowardHalting(): void {
        $this->assertTrue( MyNJILGA_Launch_Schedule::is_transport_failure( "wp_mail() failed — check the site's mail configuration." ) );
        $this->assertFalse( MyNJILGA_Launch_Schedule::is_transport_failure( 'Bill-to contact not found or has no email on file.' ) );
        $this->assertFalse( MyNJILGA_Launch_Schedule::is_transport_failure( 'No order on this row yet — create the invoice first.' ) );
    }

    // ======================================================================
    // The runner, in a simulated world
    // ======================================================================

    /**
     * A world: rows, a clock, a lock, a scheduler. Returns [ ports, &world ].
     * $world is by reference so tests can change it mid-run.
     */
    private function world( array $rowIds, array $opts = [], array $recOver = [] ): array {
        $w = (object) [
            'now'        => self::NOW,
            'micro'      => 0.0,
            'microStep'  => 0.0,
            'live'       => true,
            'ready'      => [],
            'rows'       => [],            // id => status
            'sendFail'   => [],            // id => error string
            'sendLog'    => [],
            'errors'     => [],            // id => message set on the row
            'scheduled'  => [],            // [ts, year]
            'scheduleOk' => true,
            'unscheduled'=> 0,
            'locked'     => false,
            'lockCalls'  => 0,
            'notified'   => [],
            'state'      => [],
            'onSend'     => null,
            'open'       => null,
        ];
        foreach ( $rowIds as $id ) {
            $w->rows[ $id ] = 'created';
        }
        $options = array_merge( [ 'start_ts' => self::NOW, 'batch_size' => 2, 'interval_min' => 15, 'weekdays_only' => false, 'has_window' => false, 'window_start' => 0, 'window_end' => 24 ], $opts );
        $w->state[2027] = array_merge( [
            'year' => 2027, 'status' => 'scheduled', 'livemode' => true, 'options' => $options,
            'start_ts' => $options['start_ts'], 'next_run_ts' => $options['start_ts'], 'created_by' => 1, 'created_at' => self::NOW - 600,
            'notify_email' => 'staff@example.org', 'unsent_at_schedule' => count( $rowIds ), 'sent' => 0, 'failed' => 0,
            'consecutive_failures' => 0, 'attempts' => [], 'failures' => [], 'last_run_ts' => 0, 'notes' => [],
        ], $recOver );

        $ports = [
            'now'   => static function () use ( $w ) { return $w->now; },
            'micro' => static function () use ( $w ) { $w->micro += $w->microStep; return $w->micro; },
            'tz'    => function () { return $this->ny(); },
            'live'  => static function () use ( $w ) { return $w->live; },
            'load'  => static function ( int $y ) use ( $w ) { return $w->state[ $y ] ?? null; },
            'save'  => static function ( int $y, array $rec ) use ( $w ) { $w->state[ $y ] = $rec; },
            'open'  => static function () use ( $w ) { return $w->open; },
            'ready_errors' => static function () use ( $w ) { return $w->ready; },
            'rows'  => function ( int $y, bool $live ) use ( $w ) {
                $out = [];
                foreach ( $w->rows as $id => $status ) {
                    if ( $status === 'created' ) {
                        $out[] = $this->row( (int) $id );
                    }
                }
                return $out;
            },
            'fresh' => function ( int $id ) use ( $w ) { return isset( $w->rows[ $id ] ) ? $this->row( $id, $w->rows[ $id ] ) : null; },
            'send'  => static function ( $row ) use ( $w ) {
                $id = (int) $row->id;
                $w->sendLog[] = $id;
                if ( $w->onSend ) {
                    ( $w->onSend )( $id, $w );
                }
                if ( isset( $w->sendFail[ $id ] ) ) {
                    return [ 'ok' => false, 'error' => $w->sendFail[ $id ] ];
                }
                $w->rows[ $id ] = 'sent';
                return [ 'ok' => true ];
            },
            'mark_error' => static function ( int $id, string $m ) use ( $w ) { $w->errors[ $id ] = $m; },
            'schedule'   => static function ( int $ts, int $y ) use ( $w ) { $w->scheduled[] = [ $ts, $y ]; return $w->scheduleOk; },
            'unschedule' => static function ( int $y ) use ( $w ) { $w->unscheduled++; },
            'lock'   => static function () use ( $w ) { $w->lockCalls++; if ( $w->locked ) { return false; } $w->locked = true; return true; },
            'unlock' => static function () use ( $w ) { $w->locked = false; },
            'notify' => static function ( string $to, string $s, string $b ) use ( $w ) { $w->notified[] = [ $to, $s, $b ]; },
        ];
        return [ $ports, $w ];
    }

    public function testASingleBatchThatSendsEverythingCompletes(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ] );
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'complete', $r['outcome'] );
        $this->assertSame( 2, $r['sent'] );
        $this->assertSame( [ 1, 2 ], $w->sendLog );
        $this->assertSame( 'complete', $w->state[2027]['status'] );
        $this->assertSame( 2, $w->state[2027]['sent'] );
        $this->assertCount( 1, $w->notified, 'staff are told once, when it finishes' );
        $this->assertSame( 'staff@example.org', $w->notified[0][0] );
        $this->assertTrue( $w->unscheduled >= 1, 'the successor armed before sending is withdrawn once nothing is left' );
        $this->assertFalse( $w->locked, 'the lock is released' );
    }

    public function testABigSendRunsInPacedBatchesUntilDone(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4, 5 ], [ 'batch_size' => 2, 'interval_min' => 15 ] );

        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'batch', $r['outcome'] );
        $this->assertSame( 2, $r['sent'] );
        $this->assertSame( 3, $r['remaining'] );
        $this->assertSame( [ [ self::NOW + 900, 2027 ] ], $w->scheduled, 'the next batch is one interval away' );
        $this->assertSame( 'running', $w->state[2027]['status'] );
        $this->assertSame( [], $w->notified );

        $w->now += 900; $w->scheduled = [];
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'batch', $r['outcome'] );
        $this->assertSame( 1, $r['remaining'] );

        $w->now += 900; $w->scheduled = [];
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'complete', $r['outcome'] );
        $this->assertSame( [ 1, 2, 3, 4, 5 ], $w->sendLog, 'each invoice emailed exactly once, in order' );
        $this->assertSame( 5, $w->state[2027]['sent'] );
        $this->assertCount( 1, $w->notified );
    }

    public function testItNeverSendsBeforeTheStartTime(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ], [ 'start_ts' => self::NOW + 3600 ] );
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'early', $r['outcome'] );
        $this->assertSame( [], $w->sendLog );
        $this->assertSame( [ [ self::NOW + 3600, 2027 ] ], $w->scheduled, 'put back where it belongs' );

        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p, true ); // the "send the next batch now" button
        $this->assertSame( 'complete', $r['outcome'] );
        $this->assertSame( [ 1, 2 ], $w->sendLog );
    }

    public function testAStrayFiringBetweenBatchesDoesNotSendAnExtraBatchAheadOfThePacing(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4, 5 ], [ 'batch_size' => 2, 'interval_min' => 15 ] );
        MyNJILGA_Launch_Schedule::run_batch( 2027, $p );        // 1 and 2 go; the next batch is due in 15 minutes
        $w->scheduled = [];

        $w->now += 60;                                          // a duplicate firing a minute later
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'early', $r['outcome'] );
        $this->assertSame( [ 1, 2 ], $w->sendLog, 'no extra batch' );
        $this->assertSame( [ [ self::NOW + 900, 2027 ] ], $w->scheduled, 'put back at the real due time' );

        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p, true ); // the staff button still sends now
        $this->assertSame( 'batch', $r['outcome'] );
        $this->assertSame( [ 1, 2, 3, 4 ], $w->sendLog );

        // And a firing at (or a moment before) the due time runs normally.
        [ $p2, $w2 ] = $this->world( [ 1, 2, 3 ], [ 'batch_size' => 2, 'interval_min' => 15 ] );
        MyNJILGA_Launch_Schedule::run_batch( 2027, $p2 );
        $w2->now = self::NOW + 900 - 20;
        $this->assertSame( 'complete', MyNJILGA_Launch_Schedule::run_batch( 2027, $p2 )['outcome'] );
    }

    // ---- review findings: the window holds at run time, the list is the confirmed list, the lock is real ----

    private function est( string $s ): int {
        return ( new DateTimeImmutable( $s, $this->ny() ) )->getTimestamp();
    }

    /** weekdays-only, 9–17, one batch due Friday 16:50 */
    private function windowWorld( array $rowIds, string $now ): array {
        [ $p, $w ] = $this->world( $rowIds, [ 'batch_size' => 2, 'interval_min' => 15, 'weekdays_only' => true, 'has_window' => true, 'window_start' => 9, 'window_end' => 17 ],
            [ 'start_ts' => $this->est( '2026-11-13 09:00' ), 'next_run_ts' => $this->est( '2026-11-13 16:50' ) ] );
        $w->now = $this->est( $now );
        return [ $p, $w ];
    }

    public function testALateFiringOutsideTheConfirmedHoursWaitsInsteadOfSending(): void {
        [ $p, $w ] = $this->windowWorld( [ 1, 2, 3 ], '2026-11-14 03:00' ); // the Friday 16:50 batch fires at 3 am Saturday
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'outside_window', $r['outcome'] );
        $this->assertSame( [], $w->sendLog, 'nothing is emailed on a Saturday night' );
        $this->assertSame( [ [ $this->est( '2026-11-16 09:00' ), 2027 ] ], $w->scheduled, 'it waits for Monday 9 am' );
        $this->assertSame( $this->est( '2026-11-16 09:00' ), $w->state[2027]['next_run_ts'], 'and the record says so' );

        $w->now = $this->est( '2026-11-16 09:00' ); $w->scheduled = [];
        $this->assertSame( 'batch', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'] );
        $this->assertSame( [ 1, 2 ], $w->sendLog, 'and sends when the window opens' );
    }

    public function testTheStaffButtonStillSendsOutsideTheWindow(): void {
        [ $p, $w ] = $this->windowWorld( [ 1, 2, 3 ], '2026-11-14 03:00' );
        $this->assertSame( 'batch', MyNJILGA_Launch_Schedule::run_batch( 2027, $p, true )['outcome'] );
        $this->assertSame( [ 1, 2 ], $w->sendLog, 'an explicit "send now" is the admin overriding the window' );
    }

    public function testRetriesAreAlignedToTheWindowToo(): void {
        // Stripe not ready at Fri 16:50: the 15-minute retry would be 17:05, which is outside the window.
        [ $p, $w ] = $this->windowWorld( [ 1, 2 ], '2026-11-13 16:50' );
        $w->ready = [ 'Stripe rejected this key.' ];
        $this->assertSame( 'delayed', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'] );
        $this->assertSame( [ [ $this->est( '2026-11-16 09:00' ), 2027 ] ], $w->scheduled );

        // A held lock at Fri 16:58: +5 minutes would be 17:03.
        [ $p, $w ] = $this->windowWorld( [ 1, 2 ], '2026-11-13 16:58' );
        $w->locked = true;
        $this->assertSame( 'locked', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'] );
        $this->assertSame( [ [ $this->est( '2026-11-16 09:00' ), 2027 ] ], $w->scheduled );
    }

    public function testWeekdaysOnlyWithNoWindowDoesNotSendAtMidnightSaturday(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ], [ 'batch_size' => 2, 'weekdays_only' => true, 'has_window' => false ],
            [ 'start_ts' => $this->est( '2026-11-13 09:00' ), 'next_run_ts' => $this->est( '2026-11-13 23:50' ) ] );
        $w->now = $this->est( '2026-11-14 00:05' );
        $this->assertSame( 'outside_window', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'] );
        $this->assertSame( [], $w->sendLog );
        $this->assertSame( [ [ $this->est( '2026-11-16 00:00' ), 2027 ] ], $w->scheduled );
    }

    public function testAFailureToRequeueAnEarlyOrLockedRunHaltsInsteadOfStalling(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ], [ 'start_ts' => self::NOW + 3600 ] );
        $w->scheduleOk = false;
        $this->assertSame( 'halted_schedule', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'] );
        $this->assertSame( 'halted', $w->state[2027]['status'] );
        $this->assertCount( 1, $w->notified );

        [ $p, $w ] = $this->world( [ 1, 2 ] );
        $w->locked = true; $w->scheduleOk = false;
        $this->assertSame( 'halted_schedule', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'] );
        $this->assertSame( 'halted', $w->state[2027]['status'] );
    }

    public function testOnlyTheInvoicesTheAdminConfirmedAreEverSent(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4 ], [ 'batch_size' => 10 ], [ 'row_ids' => [ 1, 2 ], 'unsent_at_schedule' => 2 ] );
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'complete', $r['outcome'], 'done when the CONFIRMED invoices are done' );
        $this->assertSame( [ 1, 2 ], $w->sendLog, 'invoices created after the confirmation (3 and 4) are not swept in' );
        $this->assertSame( 'created', $w->rows[3] );
        $this->assertSame( 'created', $w->rows[4] );
    }

    public function testTheConfirmedListAlsoDrivesWhatIsLeftToSend(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4, 5 ], [ 'batch_size' => 2 ], [ 'row_ids' => [ 1, 2, 3 ], 'unsent_at_schedule' => 3 ] );
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'batch', $r['outcome'] );
        $this->assertSame( 1, $r['remaining'], 'only invoice 3 of the confirmed three remains; 4 and 5 are not counted' );
    }

    public function testAFingerprintIdentifiesTheExactListNotItsLength(): void {
        $a = MyNJILGA_Launch_Schedule::list_fingerprint( [ 3, 1, 2 ] );
        $this->assertSame( $a, MyNJILGA_Launch_Schedule::list_fingerprint( [ '1', 2, 3, 3, 0, 'x' ] ) );
        $this->assertTrue( $a !== MyNJILGA_Launch_Schedule::list_fingerprint( [ 1, 2, 4 ] ), 'same length, different invoices' );
        $this->assertTrue( $a !== MyNJILGA_Launch_Schedule::list_fingerprint( [ 1, 2 ] ) );
    }

    public function testTheNextBatchIsArmedBeforeAnyEmailGoesOut(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4, 5 ], [ 'batch_size' => 2, 'interval_min' => 15 ] );
        $armedAtFirstSend = null;
        $w->onSend = static function ( int $id, $w ) use ( &$armedAtFirstSend ) { if ( $armedAtFirstSend === null ) { $armedAtFirstSend = count( $w->scheduled ); } };
        MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 1, $armedAtFirstSend, 'if this batch died now, the schedule would still have a successor' );
        $this->assertSame( [ [ self::NOW + 900, 2027 ] ], $w->scheduled, 'and it is the same time the batch records as next' );
        $this->assertSame( self::NOW + 900, $w->state[2027]['next_run_ts'] );
    }

    public function testStripeStayingNotReadyHaltsAndTellsStaffInsteadOfDelayingForever(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ] );
        $w->ready = [ 'Stripe rejected this key when checked just now.' ];
        $outcomes = [];
        for ( $i = 0; $i < MyNJILGA_Launch_Schedule::MAX_DELAYS; $i++ ) {
            $outcomes[] = MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'];
            $w->now += 900;
        }
        $this->assertSame( [ 'delayed', 'delayed', 'delayed', 'halted_not_ready' ], $outcomes );
        $this->assertSame( 'halted', $w->state[2027]['status'] );
        $this->assertCount( 1, $w->notified, 'staff are told' );
        $this->assertSame( [], $w->sendLog );

        // A healthy batch in between resets the count.
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4, 5, 6 ], [ 'batch_size' => 1 ] );
        $w->ready = [ 'blip' ];
        MyNJILGA_Launch_Schedule::run_batch( 2027, $p ); $w->now += 900;
        MyNJILGA_Launch_Schedule::run_batch( 2027, $p ); $w->now += 900;
        $this->assertSame( 2, $w->state[2027]['delays'] );
        $w->ready = [];
        MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 0, $w->state[2027]['delays'], 'a batch that ran clears the streak' );
    }

    // ---- the send lock (a fake options table behind $wpdb) ----

    private function withOptionsTable( callable $fn ): void {
        $db = new class {
            public $options = 'wp_options';
            public $rows = [];
            public function prepare( string $q, ...$args ): string {
                $i = 0;
                return (string) preg_replace_callback( '/%s/', static function () use ( &$i, $args ) { return "'" . addslashes( (string) $args[ $i++ ] ) . "'"; }, $q );
            }
            public function query( string $q ) {
                if ( preg_match( "/^INSERT IGNORE INTO wp_options .* VALUES \\( '([^']*)', '([^']*)', 'no' \\)$/", $q, $m ) ) {
                    if ( isset( $this->rows[ $m[1] ] ) ) { return 0; }
                    $this->rows[ $m[1] ] = $m[2];
                    return 1;
                }
                if ( preg_match( "/^UPDATE wp_options SET option_value = '([^']*)' WHERE option_name = '([^']*)' AND option_value = '([^']*)'$/", $q, $m ) ) {
                    if ( ( $this->rows[ $m[2] ] ?? null ) !== $m[3] ) { return 0; }
                    $this->rows[ $m[2] ] = $m[1];
                    return 1;
                }
                if ( preg_match( "/^DELETE FROM wp_options WHERE option_name = '([^']*)' AND option_value = '([^']*)'$/", $q, $m ) ) {
                    if ( ( $this->rows[ $m[1] ] ?? null ) !== $m[2] ) { return 0; }
                    unset( $this->rows[ $m[1] ] );
                    return 1;
                }
                throw new \RuntimeException( 'unexpected query: ' . $q );
            }
            public function get_var( string $q ) {
                if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '([^']*)'$/", $q, $m ) ) { return $this->rows[ $m[1] ] ?? null; }
                throw new \RuntimeException( 'unexpected query: ' . $q );
            }
        };
        $saved = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $db;
        try {
            $fn( $db );
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }

    public function testOnlyOneCallerCanHoldTheSendLock(): void {
        $this->withOptionsTable( function ( $db ) {
            $a = MyNJILGA_Launch_Schedule::acquire_lock();
            $this->assertTrue( $a !== '', 'the first caller gets it' );
            $this->assertSame( '', MyNJILGA_Launch_Schedule::acquire_lock(), 'the second does not' );
            MyNJILGA_Launch_Schedule::release_lock( 'someone-elses-token' );
            $this->assertSame( '', MyNJILGA_Launch_Schedule::acquire_lock(), 'a stranger cannot release it' );
            MyNJILGA_Launch_Schedule::release_lock( $a );
            $b = MyNJILGA_Launch_Schedule::acquire_lock();
            $this->assertTrue( $b !== '' && $b !== $a, 'after release it can be taken again, with a fresh token' );
        } );
    }

    public function testADeadRunsLockIsTakenOverExactlyOnce(): void {
        $this->withOptionsTable( function ( $db ) {
            $stale = ( time() - MyNJILGA_Launch_Schedule::LOCK_TTL - 30 ) . ':deadbeef';
            $db->rows[ MyNJILGA_Launch_Schedule::LOCK_OPTION ] = $stale;
            $first = MyNJILGA_Launch_Schedule::acquire_lock();
            $this->assertTrue( $first !== '', 'a stale lock is taken over' );
            $this->assertSame( '', MyNJILGA_Launch_Schedule::acquire_lock(), 'and the takeover is now the live lock' );

            // Two takers who both saw the same stale value cannot both win: the compare-and-set loses for the second.
            $db->rows[ MyNJILGA_Launch_Schedule::LOCK_OPTION ] = $stale;
            $won  = (int) $db->query( $db->prepare( "UPDATE wp_options SET option_value = %s WHERE option_name = %s AND option_value = %s", 'taker-1', MyNJILGA_Launch_Schedule::LOCK_OPTION, $stale ) );
            $lost = (int) $db->query( $db->prepare( "UPDATE wp_options SET option_value = %s WHERE option_name = %s AND option_value = %s", 'taker-2', MyNJILGA_Launch_Schedule::LOCK_OPTION, $stale ) );
            $this->assertSame( [ 1, 0 ], [ $won, $lost ] );
        } );
    }

    public function testTheRunnersLockPortReleasesOnlyItsOwnToken(): void {
        $this->withOptionsTable( function ( $db ) {
            $this->assertTrue( MyNJILGA_Launch_Schedule::take_lock() );
            $this->assertFalse( MyNJILGA_Launch_Schedule::take_lock(), 'a second batch cannot start' );
            // Someone takes over after a (simulated) timeout; the first run finishing late must not free THEIR lock.
            $db->rows[ MyNJILGA_Launch_Schedule::LOCK_OPTION ] = time() . ':other-run';
            MyNJILGA_Launch_Schedule::drop_lock();
            $this->assertTrue( isset( $db->rows[ MyNJILGA_Launch_Schedule::LOCK_OPTION ] ), 'the other run keeps its lock' );
        } );
    }

    public function testOnlyOneBatchRunsAtATime(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ] );
        $w->locked = true; // another batch is mid-flight
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'locked', $r['outcome'] );
        $this->assertSame( [], $w->sendLog );
        $this->assertSame( [ [ self::NOW + 300, 2027 ] ], $w->scheduled );
        $this->assertTrue( $w->locked, 'a lock this run did not take is not released by it' );
    }

    public function testASuccessfulBatchReleasesItsLockEvenWhenItThrows(): void {
        [ $p, $w ] = $this->world( [ 1 ] );
        $p['send'] = static function ( $row ) { throw new RuntimeException( 'boom' ); };
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertFalse( $w->locked );
        $this->assertSame( 1, $r['failed'], 'a throwing send is a failed send, not a crashed batch' );
    }

    /** Test mode would email real contacts a test-mode payment link. */
    public function testItHaltsIfStripeIsNoLongerInTheScheduledMode(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ] );
        $w->live = false;
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'halted_mode', $r['outcome'] );
        $this->assertSame( [], $w->sendLog );
        $this->assertSame( 'halted', $w->state[2027]['status'] );
        $this->assertCount( 1, $w->notified );
        $this->assertSame( [], $w->scheduled );
    }

    public function testAModeFlipPartWayThroughABatchStopsTheNextRow(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3 ], [ 'batch_size' => 3 ] );
        $w->onSend = static function ( int $id, $w ) { if ( $id === 1 ) { $w->live = false; } };
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( [ 1 ], $w->sendLog, 'row 2 is never sent' );
        $this->assertSame( 'halted_mode', $r['outcome'] );
        $this->assertSame( 'halted', $w->state[2027]['status'] );
        $this->assertSame( 1, $w->state[2027]['sent'], 'the one that did go is still counted' );
    }

    public function testStripeNotBeingReadyDelaysWithoutSendingOrHalting(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ] );
        $w->ready = [ 'Stripe rejected this key when checked just now.' ];
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'delayed', $r['outcome'] );
        $this->assertSame( [], $w->sendLog );
        $this->assertSame( [ [ self::NOW + 900, 2027 ] ], $w->scheduled );
        $this->assertSame( 'running', $w->state[2027]['status'], 'a blip is retried, not fatal' );
    }

    /** Something staff sent by hand, or that was paid, voided or sent by an earlier run, is never emailed again. */
    public function testAnInvoiceThatChangedSinceTheListWasReadIsLeftAlone(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3 ], [ 'batch_size' => 3 ] );
        $w->onSend = static function ( int $id, $w ) { if ( $id === 1 ) { $w->rows[2] = 'paid'; } }; // 2 gets paid while 1 is sending
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( [ 1, 3 ], $w->sendLog );
        $this->assertSame( 'complete', $r['outcome'] );
        $this->assertSame( 2, $w->state[2027]['sent'] );
    }

    /** A firm with no email on file is that firm's problem — it must not stop anyone else's invoice. */
    public function testAFirmWithNoEmailIsSetAsideAndDoesNotHaltTheRest(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4, 5, 6, 7 ], [ 'batch_size' => 10 ] );
        foreach ( [ 1, 2, 3, 4, 5, 6 ] as $id ) {
            $w->sendFail[ $id ] = 'Bill-to contact not found or has no email on file.';
        }
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( [ 1, 2, 3, 4, 5, 6, 7 ], $w->sendLog, 'six data failures in a row do not halt it' );
        $this->assertSame( 6, $r['failed'] );
        $this->assertSame( 1, $r['sent'] );
        $this->assertSame( 'running', $w->state[2027]['status'] );
        $this->assertSame( 6, $r['remaining'], 'they are still unsent, and have one attempt left' );

        $w->now += 900;
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p ); // the second and last attempt for each
        $this->assertSame( 'complete', $r['outcome'], 'invoices that used up their attempts no longer hold the send open' );
        $this->assertSame( 0, $r['remaining'] );
        $this->assertSame( 'skipped', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'], 'and a further run does nothing' );
        $this->assertSame( 12, count( array_filter( $w->sendLog, static function ( $id ) { return $id <= 6; } ) ), 'each was tried exactly twice, never more' );
        $this->assertSame( 6, count( $w->state[2027]['failures'] ), 'and each is listed for a person' );
    }

    public function testRepeatedMailFailuresHaltTheSchedule(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4, 5, 6, 7, 8 ], [ 'batch_size' => 10 ] );
        foreach ( [ 1, 2, 3, 4, 5, 6, 7, 8 ] as $id ) {
            $w->sendFail[ $id ] = "wp_mail() failed — check the site's mail configuration.";
        }
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'halted_failures', $r['outcome'] );
        $this->assertSame( [ 1, 2, 3, 4, 5 ], $w->sendLog, 'stops after the fifth failure in a row — it does not burn through every invoice' );
        $this->assertSame( 'halted', $w->state[2027]['status'] );
        $this->assertCount( 1, $w->notified );
        $this->assertTrue( $w->unscheduled >= 1, 'and the successor armed before sending is withdrawn' );
    }

    public function testASuccessBreaksTheRunOfMailFailures(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4, 5, 6, 7, 8 ], [ 'batch_size' => 10 ] );
        foreach ( [ 1, 2, 3, 4, 6, 7, 8 ] as $id ) {
            $w->sendFail[ $id ] = 'wp_mail() failed';
        }
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 8, count( $w->sendLog ), 'four failures, a success, then three more: never five in a row' );
        $this->assertTrue( $r['outcome'] !== 'halted_failures', 'not halted' );
    }

    /** Staff clicked Pause while a batch was running: honour it before the next row, and don't overwrite it. */
    public function testAPauseMidBatchStopsTheNextRowAndStaysPaused(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4 ], [ 'batch_size' => 4 ] );
        $w->onSend = static function ( int $id, $w ) { if ( $id === 2 ) { $w->state[2027]['status'] = 'paused'; } };
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( [ 1, 2 ], $w->sendLog, 'rows 3 and 4 are not sent' );
        $this->assertSame( 'interrupted', $r['outcome'] );
        $this->assertSame( 'paused', $w->state[2027]['status'], 'the batch must not revive what staff paused' );
        $this->assertSame( 2, $w->state[2027]['sent'] );
        // The successor armed before sending is still pending (a real pause() withdraws it), and does nothing when it fires.
        $this->assertSame( 'skipped', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'] );
        $this->assertSame( [ 1, 2 ], $w->sendLog );
    }

    public function testACancelledScheduleDoesNothing(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ], [], [ 'status' => 'cancelled' ] );
        $this->assertSame( 'skipped', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'] );
        $this->assertSame( [], $w->sendLog );
        [ $p, $w ] = $this->world( [ 1, 2 ], [], [ 'status' => 'paused' ] );
        $this->assertSame( 'skipped', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'] );
        [ $p, $w ] = $this->world( [ 1, 2 ], [], [ 'status' => 'complete' ] );
        $this->assertSame( 'skipped', MyNJILGA_Launch_Schedule::run_batch( 2027, $p )['outcome'] );
        $this->assertSame( 'skipped', MyNJILGA_Launch_Schedule::run_batch( 2099, $p )['outcome'], 'a year with no schedule' );
    }

    /** PHP's time limit must never kill it mid-row: out of time → stop starting sends. */
    public function testItStopsStartingSendsWhenTheTimeBudgetIsSpent(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4, 5, 6 ], [ 'batch_size' => 6 ] );
        $w->microStep = 8.0; // every clock read is 8 seconds later
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertTrue( count( $w->sendLog ) < 6, 'not all six were attempted in one run' );
        $this->assertTrue( count( $w->sendLog ) >= 1, 'but it made progress' );
        $this->assertSame( 'batch', $r['outcome'] );
        $this->assertSame( 6 - count( $w->sendLog ), $r['remaining'] );
        $this->assertCount( 1, $w->scheduled, 'the rest wait for the next batch' );
    }

    /** If the next batch can't be scheduled, the send must say so, not stall quietly. */
    public function testFailureToScheduleTheNextBatchHaltsVisibly(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3, 4, 5 ], [ 'batch_size' => 2 ] );
        $w->scheduleOk = false;
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'halted_schedule', $r['outcome'] );
        $this->assertSame( 'halted', $w->state[2027]['status'] );
        $this->assertCount( 1, $w->notified );
        $this->assertSame( [], $w->sendLog, 'nothing is emailed when the next batch cannot be armed first' );
    }

    public function testNoUnsentInvoicesCompletesImmediately(): void {
        [ $p, $w ] = $this->world( [] );
        $r = MyNJILGA_Launch_Schedule::run_batch( 2027, $p );
        $this->assertSame( 'complete', $r['outcome'] );
        $this->assertSame( [], $w->sendLog );
    }

    // ======================================================================
    // Controls
    // ======================================================================

    private function goodOptions(): array {
        return [ 'start_ts' => self::NOW + 3600, 'batch_size' => 25, 'interval_min' => 15, 'weekdays_only' => false, 'has_window' => false, 'window_start' => 0, 'window_end' => 24 ];
    }

    public function testCreatingASchedulePersistsItAndSchedulesTheFirstBatch(): void {
        [ $p, $w ] = $this->world( [] );
        $w->state = [];
        $r = MyNJILGA_Launch_Schedule::create( 2027, $this->goodOptions(), true, 7, 'me@example.org', range( 1, 120 ), $p );
        $this->assertTrue( $r['ok'], $r['error'] );
        $this->assertSame( [ [ self::NOW + 3600, 2027 ] ], $w->scheduled );
        $rec = $w->state[2027];
        $this->assertSame( 'scheduled', $rec['status'] );
        $this->assertSame( 120, $rec['unsent_at_schedule'] );
        $this->assertSame( range( 1, 120 ), $rec['row_ids'], 'the confirmed list is stored with the schedule' );
        $this->assertSame( 7, $rec['created_by'] );
        $this->assertSame( 'me@example.org', $rec['notify_email'] );
        $this->assertTrue( $rec['livemode'] );
    }

    public function testTheFirstBatchLandsInsideTheWindow(): void {
        [ $p, $w ] = $this->world( [] );
        $w->state = [];
        $o = $this->goodOptions();
        $o['start_ts']       = strtotime( '2026-11-14 16:00:00 UTC' ); // Saturday 11:00 New York
        $o['weekdays_only']  = true;
        $o['has_window']     = true;
        $o['window_start']   = 9;
        $o['window_end']     = 17;
        $o['batch_size']     = 25;
        MyNJILGA_Launch_Schedule::create( 2027, $o, true, 1, 'x@example.org', range( 1, 10 ), $p );
        $this->assertSame( 'Mon 2026-11-16 09:00 EST', $this->fmt( $w->scheduled[0][0] ) );
    }

    public function testOnlyOneScheduleCanBeOpenAtATime(): void {
        [ $p, $w ] = $this->world( [] );
        $w->open = [ 'year' => 2026, 'status' => 'paused' ];
        $r = MyNJILGA_Launch_Schedule::create( 2027, $this->goodOptions(), true, 1, 'x@example.org', range( 1, 10 ), $p );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( [], $w->scheduled );
    }

    public function testNothingToSendIsRefused(): void {
        [ $p, $w ] = $this->world( [] );
        $w->state = [];
        $this->assertFalse( MyNJILGA_Launch_Schedule::create( 2027, $this->goodOptions(), true, 1, 'x@example.org', [], $p )['ok'] );
    }

    public function testASchedulerThatRefusesLeavesNoRecordBehind(): void {
        [ $p, $w ] = $this->world( [] );
        $w->state = [];
        $w->scheduleOk = false;
        $r = MyNJILGA_Launch_Schedule::create( 2027, $this->goodOptions(), true, 1, 'x@example.org', range( 1, 10 ), $p );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( [], $w->state, 'nothing is recorded as scheduled when nothing is' );
    }

    public function testPauseResumeAndCancel(): void {
        [ $p, $w ] = $this->world( [ 1, 2, 3 ] );
        $this->assertTrue( MyNJILGA_Launch_Schedule::pause( 2027, $p )['ok'] );
        $this->assertSame( 'paused', $w->state[2027]['status'] );
        $this->assertSame( 1, $w->unscheduled, 'the pending batch is removed from the scheduler' );
        $this->assertFalse( MyNJILGA_Launch_Schedule::pause( 2027, $p )['ok'], 'already paused' );

        $w->scheduled = [];
        $this->assertTrue( MyNJILGA_Launch_Schedule::resume( 2027, $p )['ok'] );
        $this->assertSame( 'running', $w->state[2027]['status'] );
        $this->assertCount( 1, $w->scheduled );
        $this->assertTrue( $w->scheduled[0][0] >= self::NOW + MyNJILGA_Launch_Schedule::MIN_LEAD, 'never resumes into the past' );

        $this->assertTrue( MyNJILGA_Launch_Schedule::cancel( 2027, $p )['ok'] );
        $this->assertSame( 'cancelled', $w->state[2027]['status'] );
        $this->assertFalse( MyNJILGA_Launch_Schedule::cancel( 2027, $p )['ok'], 'nothing left to cancel' );
        $this->assertFalse( MyNJILGA_Launch_Schedule::resume( 2027, $p )['ok'], 'a cancelled send cannot be resumed' );
    }

    public function testAHaltedSendCanBeResumedOnceTheCauseIsFixed(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ], [], [ 'status' => 'halted', 'consecutive_failures' => 5 ] );
        $this->assertTrue( MyNJILGA_Launch_Schedule::resume( 2027, $p )['ok'] );
        $this->assertSame( 'running', $w->state[2027]['status'] );
        $this->assertSame( 0, $w->state[2027]['consecutive_failures'] );
    }

    public function testResumeIsRefusedInTheWrongStripeMode(): void {
        [ $p, $w ] = $this->world( [ 1, 2 ], [], [ 'status' => 'halted' ] );
        $w->live = false;
        $this->assertFalse( MyNJILGA_Launch_Schedule::resume( 2027, $p )['ok'] );
        $this->assertSame( 'halted', $w->state[2027]['status'] );
    }

    // ======================================================================
    // The notice, and the Action Scheduler entry point
    // ======================================================================

    public function testTheSummaryNoticesSayWhatHappened(): void {
        $done = MyNJILGA_Launch_Schedule::summary_message( [ 'year' => 2027, 'sent' => 118, 'failed' => 2 ], 'complete' );
        $this->assertTrue( strpos( $done['subject'], '2027' ) !== false );
        $this->assertTrue( strpos( $done['body'], '118' ) !== false );
        $this->assertTrue( strpos( $done['body'], '2 invoices' ) !== false );
        $halt = MyNJILGA_Launch_Schedule::summary_message( [ 'year' => 2027, 'sent' => 40, 'failed' => 0, 'notes' => [ [ 'ts' => 1, 'msg' => 'Mail broke.' ] ] ], 'halted' );
        $this->assertTrue( strpos( $halt['subject'], 'HALTED' ) !== false );
        $this->assertTrue( strpos( $halt['body'], 'Mail broke.' ) !== false );
    }

    /**
     * Action Scheduler (like WordPress) may hand the callback an int, a numeric
     * string, an empty string or nothing. It must take all of them without a
     * TypeError — a typed parameter here once took a whole site down.
     */
    public function testTheSchedulerCallbackAcceptsWhateverItIsGiven(): void {
        foreach ( [ [], [ '' ], [ null ], [ 2027 ], [ '2027' ], [ 0 ], [ 'abc' ], [ [ 2027 ] ], [ 3.9 ] ] as $args ) {
            MyNJILGA_Launch_Schedule::run_scheduled( ...$args );
        }
        $this->assertTrue( true );
        $m = new ReflectionMethod( 'MyNJILGA_Launch_Schedule', 'run_scheduled' );
        $this->assertSame( 0, $m->getNumberOfRequiredParameters() );
        $this->assertSame( null, $m->getParameters()[0]->getType(), 'untyped' );
    }

    /** What Invoicing shows as "Email scheduled": an open schedule's list in this mode, less invoices it gave up on. */
    public function testTheInvoicesAnOpenScheduleWillStillEmail(): void {
        $rec = [ 'status' => 'running', 'livemode' => true, 'row_ids' => [ 10, 20, 30 ], 'attempts' => [ 20 => 1, 30 => MyNJILGA_Launch_Schedule::MAX_ATTEMPTS_PER_ROW ] ];
        $this->assertSame( [ 10 => true, 20 => true ], MyNJILGA_Launch_Schedule::waiting_ids( $rec, true ), 'tried once: still waiting; tried too often: set aside' );
        $this->assertSame( [], MyNJILGA_Launch_Schedule::waiting_ids( $rec, false ), 'the other Stripe mode' );
        $this->assertSame( [], MyNJILGA_Launch_Schedule::waiting_ids( null, true ) );
        foreach ( [ 'complete', 'cancelled' ] as $closed ) {
            $this->assertSame( [], MyNJILGA_Launch_Schedule::waiting_ids( [ 'status' => $closed ] + $rec, true ), $closed );
        }
        foreach ( [ 'scheduled', 'paused', 'halted' ] as $open ) {
            $this->assertSame( [ 10 => true, 20 => true ], MyNJILGA_Launch_Schedule::waiting_ids( [ 'status' => $open ] + $rec, true ), $open );
        }
    }

    /** The finished / halted email points to Invoicing, where the status now shows. */
    public function testTheSummaryEmailPointsToInvoicing(): void {
        $m = MyNJILGA_Launch_Schedule::summary_message( [ 'year' => 2027, 'sent' => 5, 'failed' => 1 ], 'complete' );
        $this->assertTrue( strpos( $m['body'], 'Invoicing (2027)' ) !== false, $m['body'] );
        $this->assertTrue( strpos( $m['body'], 'Launch Wizard' ) === false, $m['body'] );
    }

    // ======================================================================
    // An invoice that moved to a new row stays in its scheduled send
    // ======================================================================

    /** @return array<string,mixed> */
    private function record( array $over = [] ): array {
        return $over + [ 'year' => 2027, 'status' => 'scheduled', 'row_ids' => [ 4, 9, 12 ], 'attempts' => [ 9 => 2, 12 => 1 ], 'failures' => [ 9 => 'wp_mail() failed' ], 'notes' => [] ];
    }

    public function testAMovedInvoiceTakesItsPlaceInTheScheduleInPlace(): void {
        $r = MyNJILGA_Launch_Schedule::with_row_replaced( $this->record(), 9, 31, self::NOW );
        $this->assertSame( [ 4, 31, 12 ], $r['row_ids'], 'swapped where it stood, the rest untouched' );
        $this->assertSame( [ 12 => 1 ], $r['attempts'], 'what had been tried belongs to the old row' );
        $this->assertSame( [], $r['failures'] );
        $this->assertCount( 1, $r['notes'], 'the schedule says what happened' );
        $this->assertTrue( strpos( json_encode( $r['notes'] ), 'row 9 was replaced by row 31' ) !== false );
    }

    public function testEveryOpenStatusFollowsTheMove(): void {
        foreach ( [ 'scheduled', 'running', 'paused', 'halted' ] as $status ) {
            $this->assertTrue( MyNJILGA_Launch_Schedule::with_row_replaced( $this->record( [ 'status' => $status ] ), 4, 31, self::NOW ) !== null, $status );
        }
    }

    public function testNothingChangesWhenThereIsNothingToMove(): void {
        foreach ( [ 'complete', 'cancelled' ] as $status ) {
            $this->assertSame( null, MyNJILGA_Launch_Schedule::with_row_replaced( $this->record( [ 'status' => $status ] ), 4, 31, self::NOW ), "a $status schedule is left alone" );
        }
        $this->assertSame( null, MyNJILGA_Launch_Schedule::with_row_replaced( $this->record(), 77, 31, self::NOW ), 'a row the schedule never listed' );
        $this->assertSame( null, MyNJILGA_Launch_Schedule::with_row_replaced( $this->record(), 4, 4, self::NOW ), 'the same row' );
        $this->assertSame( null, MyNJILGA_Launch_Schedule::with_row_replaced( $this->record(), 0, 31, self::NOW ), 'no old row' );
        $this->assertSame( null, MyNJILGA_Launch_Schedule::with_row_replaced( $this->record(), 4, 0, self::NOW ), 'no new row' );
        $this->assertSame( null, MyNJILGA_Launch_Schedule::with_row_replaced( [ 'status' => 'scheduled' ], 4, 31, self::NOW ), 'a record with no list' );
    }

    public function testARowAlreadyListedIsNotListedTwice(): void {
        $r = MyNJILGA_Launch_Schedule::with_row_replaced( $this->record(), 9, 12, self::NOW );
        $this->assertSame( [ 4, 12 ], $r['row_ids'], 'the old one is dropped, the new one stays once' );
    }

    /** The schedule then waits on the new row, not the old. */
    public function testTheScheduleWaitsOnTheNewRowAfterTheMove(): void {
        $r = MyNJILGA_Launch_Schedule::with_row_replaced( $this->record( [ 'livemode' => true, 'attempts' => [] ] ), 9, 31, self::NOW );
        $waiting = MyNJILGA_Launch_Schedule::waiting_ids( $r, true );
        $this->assertTrue( isset( $waiting[31] ) && ! isset( $waiting[9] ) );
    }

    // ======================================================================
    // The status as it stands now: failures staff have since cleared
    // ======================================================================

    /** A finished send, as the user reported it: 167 confirmed, some failed at send time, some then emailed by hand. */
    private function finished( array $over = [] ): array {
        return array_merge( [
            'status' => 'complete', 'unsent_at_schedule' => 6, 'sent' => 3, 'failed' => 9,
            'row_ids'  => [ 1, 2, 3, 4, 5, 6 ],
            'failures' => [ 4 => 'wp_mail() failed', 5 => 'wp_mail() failed', 6 => 'wp_mail() failed' ],
            'attempts' => [ 4 => 2, 5 => 2, 6 => 2 ],
        ], $over );
    }

    private function inv( string $status, string $sentAt = '' ): array {
        return [ 'status' => $status, 'sent_at' => $sentAt === '' ? null : $sentAt ];
    }

    public function testAFailureThatWasEmailedByHandIsNoLongerAFailure(): void {
        $rows = [
            1 => $this->inv( 'sent', '2026-11-16 05:00:00' ), 2 => $this->inv( 'sent', '2026-11-16 05:00:00' ), 3 => $this->inv( 'sent', '2026-11-16 05:00:00' ),
            4 => $this->inv( 'sent', '2026-11-17 09:00:00' ), // failed, then re-sent by hand
            5 => $this->inv( 'created' ),                     // still not sent
            6 => $this->inv( 'sent', '2026-11-17 09:01:00' ),
        ];
        $r = MyNJILGA_Launch_Schedule::reconcile( $this->finished(), $rows );
        $this->assertSame( [ 5 => 'wp_mail() failed' ], $r['unresolved'], 'only the one still waiting is listed' );
        $this->assertSame( 2, $r['resolved'] );
        $this->assertSame( 5, $r['sent'], 'the hand-sent ones count as sent: 5 of 6, not the 3 the schedule sent' );
        $this->assertSame( 6, $r['total'] );
    }

    public function testWhenEverythingIsSentNothingIsListed(): void {
        $rows = [];
        foreach ( [ 1, 2, 3, 4, 5, 6 ] as $id ) {
            $rows[ $id ] = $this->inv( 'sent', '2026-11-17 09:00:00' );
        }
        $r = MyNJILGA_Launch_Schedule::reconcile( $this->finished(), $rows );
        $this->assertSame( [], $r['unresolved'] );
        $this->assertSame( 3, $r['resolved'] );
        $this->assertSame( 6, $r['sent'] );
    }

    /** Paid, voided, replaced (a different row now) or deleted: nothing is left to send either. */
    public function testAnInvoiceThatLeftTheCreatedStateIsSettledWhateverBecameOfIt(): void {
        $rows = [ 4 => $this->inv( 'paid', '2026-11-17 09:00:00' ), 5 => $this->inv( 'voided' ) ]; // 6: no row any more
        $r    = MyNJILGA_Launch_Schedule::reconcile( $this->finished(), $rows );
        $this->assertSame( [], $r['unresolved'] );
        $this->assertSame( 3, $r['resolved'] );
    }

    public function testAnInvoiceStillCreatedStaysListedWithItsReason(): void {
        $rows = [ 4 => $this->inv( 'created' ), 5 => $this->inv( 'created' ), 6 => $this->inv( 'created' ) ];
        $rec  = $this->finished( [ 'failures' => [ 6 => 'No email on the Owner', 4 => 'wp_mail() failed', 5 => 'wp_mail() failed' ] ] );
        $r    = MyNJILGA_Launch_Schedule::reconcile( $rec, $rows );
        $this->assertSame( [ 4, 5, 6 ], array_keys( $r['unresolved'] ), 'in invoice order' );
        $this->assertSame( 'No email on the Owner', $r['unresolved'][6] );
        $this->assertSame( 0, $r['resolved'] );
    }

    /** The record's own `failed` adds up attempts (an invoice tried twice counts twice): the figure shown is the invoices. */
    public function testTheCountIsInvoicesNotAttempts(): void {
        $rows = [ 4 => $this->inv( 'created' ), 5 => $this->inv( 'created' ), 6 => $this->inv( 'created' ) ];
        $r    = MyNJILGA_Launch_Schedule::reconcile( $this->finished( [ 'failed' => 9 ] ), $rows );
        $this->assertSame( 3, count( $r['unresolved'] ), 'three invoices, though the record says nine failed tries' );
    }

    public function testARecordWithoutAConfirmedListKeepsItsOwnSentCount(): void {
        $r = MyNJILGA_Launch_Schedule::reconcile( [ 'status' => 'complete', 'unsent_at_schedule' => 10, 'sent' => 7, 'failures' => [] ], [] );
        $this->assertSame( 7, $r['sent'] );
        $this->assertSame( [], $r['unresolved'] );
        $r = MyNJILGA_Launch_Schedule::reconcile( [], [] );
        $this->assertSame( [ 0, 0, [], 0 ], [ $r['sent'], $r['total'], $r['unresolved'], $r['resolved'] ] );
    }

    public function testObjectRowsReadTheSameAsArrays(): void {
        $r = MyNJILGA_Launch_Schedule::reconcile( $this->finished(), [ 4 => (object) $this->inv( 'created' ), 5 => (object) $this->inv( 'sent', '2026-11-17 09:00:00' ) ] );
        $this->assertSame( [ 4 ], array_keys( $r['unresolved'] ) );
    }

    public function testDismissingClearsOnlyTheList(): void {
        $rec  = $this->finished( [ 'notes' => [ [ 'ts' => 1, 'msg' => 'Batch: 3 sent, 3 failed.' ] ] ] );
        $next = MyNJILGA_Launch_Schedule::without_failures( $rec, self::NOW, 'Shane' );
        $this->assertSame( [], $next['failures'] );
        $this->assertSame( $rec['row_ids'], $next['row_ids'], 'the confirmed list stays' );
        $this->assertSame( $rec['sent'], $next['sent'] );
        $this->assertSame( 'complete', $next['status'] );
        $this->assertSame( 'The list of 3 invoices that could not be sent was dismissed by Shane.', end( $next['notes'] )['msg'] );
        $this->assertSame( 2, count( $next['notes'] ), 'the log keeps what it had, and says what happened' );
        $one = MyNJILGA_Launch_Schedule::without_failures( $this->finished( [ 'failures' => [ 4 => 'x' ] ] ), self::NOW );
        $this->assertSame( 'The list of 1 invoice that could not be sent was dismissed.', end( $one['notes'] )['msg'] );
    }

    /** The log says what the screen listed: the record also holds failures emailed since, which staff never saw. */
    public function testTheLogCountsWhatWasListed(): void {
        $two = MyNJILGA_Launch_Schedule::without_failures( $this->finished(), self::NOW, 'Shane', 2 );
        $this->assertSame( [], $two['failures'], 'all of it goes, listed or not' );
        $this->assertSame( 'The list of 2 invoices that could not be sent was dismissed by Shane.', end( $two['notes'] )['msg'] );
        $none = MyNJILGA_Launch_Schedule::without_failures( $this->finished(), self::NOW, '', 0 );
        $this->assertSame( 'The record of earlier send failures was cleared; every one had since been settled.', end( $none['notes'] )['msg'] );
    }

    public function testThereIsNothingToDismissWithoutAList(): void {
        $this->assertSame( null, MyNJILGA_Launch_Schedule::without_failures( $this->finished( [ 'failures' => [] ] ), self::NOW ) );
        $this->assertSame( null, MyNJILGA_Launch_Schedule::without_failures( [], self::NOW ) );
    }

    // ======================================================================
    // Dismissing the log, and a finished send
    // ======================================================================

    private function logged( array $over = [] ): array {
        return array_merge( [
            'status' => 'complete',
            'notes'  => [
                [ 'ts' => 100, 'msg' => 'Scheduled: 6 invoices.' ], [ 'ts' => 200, 'msg' => 'Batch: 3 sent, 3 failed.' ], [ 'ts' => 300, 'msg' => 'Complete: every sendable invoice has been emailed.' ],
            ],
        ], $over );
    }

    public function testTheLogShowsNewestFirst(): void {
        $n = MyNJILGA_Launch_Schedule::visible_notes( $this->logged() );
        $this->assertSame( [ 300, 200, 100 ], array_column( $n, 'ts' ) );
        $this->assertSame( 'Complete: every sendable invoice has been emailed.', $n[0]['msg'] );
    }

    public function testTheLogIsCappedAtTheNewestTen(): void {
        $notes = [];
        for ( $i = 1; $i <= 15; $i++ ) {
            $notes[] = [ 'ts' => $i * 10, 'msg' => "n$i" ];
        }
        $n = MyNJILGA_Launch_Schedule::visible_notes( [ 'notes' => $notes ] );
        $this->assertCount( 10, $n );
        $this->assertSame( [ 'n15', 'n6' ], [ $n[0]['msg'], $n[9]['msg'] ] );
        $this->assertCount( 3, MyNJILGA_Launch_Schedule::visible_notes( [ 'notes' => $notes ], 3 ) );
    }

    public function testDismissingTheLogHidesWhatIsThereAndNotWhatComesAfter(): void {
        $rec = MyNJILGA_Launch_Schedule::with_log_cleared( $this->logged() );
        $this->assertSame( [], MyNJILGA_Launch_Schedule::visible_notes( $rec ), 'everything written so far is hidden' );
        $this->assertCount( 3, $rec['notes'], 'nothing is deleted from the record' );
        $rec['notes'][] = [ 'ts' => 400, 'msg' => 'Resumed.' ];
        $this->assertSame( [ 'Resumed.' ], array_column( MyNJILGA_Launch_Schedule::visible_notes( $rec ), 'msg' ), 'a later entry shows' );
    }

    /** The cut-off is the entry's number, not the clock: an entry written in the very same second still shows. */
    public function testAnEntryWrittenTheSameSecondAsTheDismissalStillShows(): void {
        $rec = MyNJILGA_Launch_Schedule::with_log_cleared( $this->logged() );
        $rec['notes'][] = [ 'ts' => 300, 'msg' => 'Resumed.', 'n' => 4 ]; // the same timestamp as the last entry hidden
        $this->assertSame( [ 'Resumed.' ], array_column( MyNJILGA_Launch_Schedule::visible_notes( $rec ), 'msg' ) );
    }

    public function testEntriesKeepCountingAfterTheLogIsTrimmed(): void {
        $ref  = new ReflectionMethod( 'MyNJILGA_Launch_Schedule', 'note' );
        $ref->setAccessible( true );
        $rec  = [ 'notes' => [] ];
        for ( $i = 1; $i <= 45; $i++ ) {
            $rec = $ref->invoke( null, $rec, 1000 + $i, "m$i" );
        }
        $this->assertCount( MyNJILGA_Launch_Schedule::NOTES_KEPT, $rec['notes'] );
        $this->assertSame( 45, $rec['note_seq'] );
        $this->assertSame( [ 45, 6 ], [ end( $rec['notes'] )['n'], $rec['notes'][0]['n'] ], 'numbers survive the trimming' );
        $cleared = MyNJILGA_Launch_Schedule::with_log_cleared( $rec );
        $this->assertSame( [], MyNJILGA_Launch_Schedule::visible_notes( $cleared ) );
        $cleared = $ref->invoke( null, $cleared, 2000, 'after' );
        $this->assertSame( [ 'after' ], array_column( MyNJILGA_Launch_Schedule::visible_notes( $cleared ), 'msg' ), 'and the next entry is the only one showing' );
    }

    public function testThereIsNothingToDismissWithoutALog(): void {
        $this->assertSame( null, MyNJILGA_Launch_Schedule::with_log_cleared( [ 'notes' => [] ] ) );
        $this->assertSame( null, MyNJILGA_Launch_Schedule::with_log_cleared( [] ) );
        $dismissed = MyNJILGA_Launch_Schedule::with_log_cleared( $this->logged() );
        $this->assertSame( null, MyNJILGA_Launch_Schedule::with_log_cleared( $dismissed ), 'already dismissed: nothing showing' );
    }

    public function testMalformedEntriesAreSkippedNotFatal(): void {
        $n = MyNJILGA_Launch_Schedule::visible_notes( [ 'notes' => [ [ 'msg' => 'no time' ], [ 'ts' => 50, 'msg' => 'ok' ] ] ] );
        $this->assertSame( [ 'ok' ], array_column( $n, 'msg' ) );
    }

    public function testOnlyAFinishedSendCanBeDismissed(): void {
        foreach ( [ 'complete', 'cancelled' ] as $status ) {
            $r = MyNJILGA_Launch_Schedule::with_send_dismissed( $this->logged( [ 'status' => $status ] ), 700 );
            $this->assertSame( 700, $r['dismissed_ts'], $status );
            $this->assertTrue( MyNJILGA_Launch_Schedule::is_dismissed( $r ), $status );
        }
        foreach ( [ 'scheduled', 'running', 'paused', 'halted' ] as $status ) {
            $this->assertSame( null, MyNJILGA_Launch_Schedule::with_send_dismissed( $this->logged( [ 'status' => $status ] ), 700 ), "$status is still doing something" );
        }
    }

    public function testDismissingTwiceIsRefusedAndAnOpenSendIsNeverReportedDismissed(): void {
        $r = MyNJILGA_Launch_Schedule::with_send_dismissed( $this->logged(), 700 );
        $this->assertSame( null, MyNJILGA_Launch_Schedule::with_send_dismissed( $r, 800 ) );
        $this->assertFalse( MyNJILGA_Launch_Schedule::is_dismissed( $this->logged() ), 'not dismissed unless it was' );
        // A record dismissed once, then something reopened it: it is live again, so it shows.
        $this->assertFalse( MyNJILGA_Launch_Schedule::is_dismissed( array_merge( $r, [ 'status' => 'paused' ] ) ) );
    }

    public function testDismissingTheSendLeavesTheRecordIntact(): void {
        $rec = $this->logged( [ 'sent' => 5, 'failures' => [ 4 => 'x' ], 'row_ids' => [ 1, 2 ] ] );
        $r   = MyNJILGA_Launch_Schedule::with_send_dismissed( $rec, 700 );
        unset( $r['dismissed_ts'] );
        $this->assertSame( $rec, $r, 'only the flag is added' );
    }
}
