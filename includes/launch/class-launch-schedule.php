<?php
/**
 * Scheduled, paced delivery of the dues-invoice emails — the engine behind
 * the Launch Wizard's "Send" step.
 *
 * Invoices are CREATED in Stripe first (status `created`); this class then
 * emails them, a batch at a time, on a schedule staff choose: a start time,
 * how many per batch, how long between batches, and optionally only on
 * weekdays between set hours. Each batch is one Action Scheduler action that
 * sends through the same MyNJILGA_Invoice_Sender the manual Send button uses,
 * so an email scheduled here is the email Send would have produced.
 *
 * Why it is built the way it is — this sends real email to real firms:
 *
 *   - Every side effect (clock, locks, Stripe readiness, the row queries, the
 *     send itself, saving state, scheduling the next batch, notifying staff)
 *     sits behind an injectable "port". run_batch() is the whole decision
 *     procedure and is unit-tested without WordPress; default_ports() wires
 *     the real thing.
 *   - Only invoices that are still `created` (issued, not yet sent) are ever
 *     sent, re-read fresh just before each send, so anything staff sent, paid
 *     or voided in the meantime is skipped, and nothing is emailed twice.
 *   - Live mode only, and only the mode it was scheduled in: if Stripe is
 *     switched to Test while a schedule is open, it halts rather than email
 *     real contacts a test-mode payment link.
 *   - One batch at a time (a lock), a time budget per batch (so PHP's limit
 *     can't kill it mid-row), a per-invoice attempt cap (a bad address cannot
 *     loop forever), and a halt after repeated MAIL failures (a mail outage
 *     must not burn through every invoice).
 *   - Pausing, cancelling and a mode flip are honoured between rows, not only
 *     between batches.
 *   - Only the invoices staff CONFIRMED are sent: the schedule stores their
 *     ids, so invoices created afterwards are never swept in unconfirmed.
 *   - The window the admin chose (weekdays, hours) is enforced when a batch
 *     RUNS, not only when it is scheduled: Action Scheduler can fire late, and
 *     a late firing waits for the next allowed moment instead of sending.
 *   - The next batch is scheduled BEFORE any email goes out, so a batch that
 *     dies part-way (PHP time or memory limit) still has a successor; and if
 *     the next batch cannot be scheduled the schedule HALTS visibly — never a
 *     silent stall. Repeated "Stripe not ready" deferrals halt it too.
 *   - The Action Scheduler callback takes its argument untyped. WordPress
 *     hands a hook callback whatever it was given (an empty string for an
 *     argument-less action), and a typed parameter once took a whole site
 *     down; run_scheduled() coerces instead.
 */
class MyNJILGA_Launch_Schedule {

    /** The token of the send lock this request holds ('' = none). */
    private static $lockToken = '';

    const OPTION      = 'njilga_launch_schedules';
    const LOCK_OPTION = 'njilga_launch_send_lock';
    const HOOK_RUN    = 'njilga_launch_send_batch';
    const AS_GROUP    = 'njilga-dues';

    const ST_SCHEDULED = 'scheduled';
    const ST_RUNNING   = 'running';
    const ST_PAUSED    = 'paused';
    const ST_HALTED    = 'halted';
    const ST_COMPLETE  = 'complete';
    const ST_CANCELLED = 'cancelled';

    /** Seconds of the future a start time must be at least (time to review before anything goes out). */
    const MIN_LEAD = 120;
    const MAX_LEAD = 31536000; // a year
    const MIN_BATCH    = 1;
    const MAX_BATCH    = 200;
    const MIN_INTERVAL = 5;    // minutes
    const MAX_INTERVAL = 1440;

    /** A batch stops starting new sends after this many seconds, and the rest wait for the next. */
    const TIME_BUDGET = 20;
    /** Mail-transport failures in a row that halt the schedule. */
    const MAX_CONSECUTIVE_FAILURES = 5;
    /** Times one invoice is tried before it is set aside for a person. */
    const MAX_ATTEMPTS_PER_ROW = 2;
    /** A held lock older than this is treated as a dead run's. */
    const LOCK_TTL = 600;
    /** "Stripe not ready" deferrals in a row (15 minutes apart) before the schedule halts and tells staff. */
    const MAX_DELAYS = 4;
    const NOTES_KEPT = 40;
    const FAILURES_KEPT = 200;

    // -------------------------------------------------------------------------
    // Pure: options, time and batching
    // -------------------------------------------------------------------------

    /**
     * Validate what the Send step posted.
     *
     * @param array<string,mixed> $in
     * @return array{ok:bool,error:string,options:array<string,mixed>}
     */
    public static function normalize_options( array $in, int $now, \DateTimeZone $tz ): array {
        $fail = static function ( string $msg ): array {
            return [ 'ok' => false, 'error' => $msg, 'options' => [] ];
        };

        $startRaw = trim( (string) ( $in['start'] ?? '' ) );
        $startTs  = $startRaw === '' ? $now + self::MIN_LEAD : self::parse_local( $startRaw, $tz );
        if ( $startTs === null ) {
            return $fail( 'The start time isn\'t a valid date and time.' );
        }
        if ( $startTs < $now + self::MIN_LEAD ) {
            return $fail( 'The start time must be at least a couple of minutes from now, so there is time to stop it.' );
        }
        if ( $startTs > $now + self::MAX_LEAD ) {
            return $fail( 'The start time is more than a year away.' );
        }

        $batch = (int) ( $in['batch_size'] ?? 0 );
        if ( $batch < self::MIN_BATCH || $batch > self::MAX_BATCH ) {
            return $fail( sprintf( 'Batch size must be between %d and %d.', self::MIN_BATCH, self::MAX_BATCH ) );
        }
        $interval = (int) ( $in['interval_min'] ?? 0 );
        if ( $interval < self::MIN_INTERVAL || $interval > self::MAX_INTERVAL ) {
            return $fail( sprintf( 'Minutes between batches must be between %d and %d.', self::MIN_INTERVAL, self::MAX_INTERVAL ) );
        }

        $hasWindow = ! empty( $in['window'] );
        $ws = 0;
        $we = 24;
        if ( $hasWindow ) {
            $ws = (int) ( $in['window_start'] ?? 9 );
            $we = (int) ( $in['window_end'] ?? 17 );
            if ( $ws < 0 || $ws > 23 || $we < 1 || $we > 24 || $we <= $ws ) {
                return $fail( 'The sending window needs a start hour (0–23) before its end hour (1–24).' );
            }
        }

        return [
            'ok'      => true,
            'error'   => '',
            'options' => [
                'start_ts'      => $startTs,
                'batch_size'    => $batch,
                'interval_min'  => $interval,
                'weekdays_only' => ! empty( $in['weekdays_only'] ),
                'has_window'    => $hasWindow,
                'window_start'  => $ws,
                'window_end'    => $we,
            ],
        ];
    }

    /**
     * "2026-11-16T09:00" (a datetime-local value) or "2026-11-16 09:00" in the
     * given zone → a UTC timestamp, or null if it isn't a real date and time.
     */
    public static function parse_local( string $value, \DateTimeZone $tz ): ?int {
        $value = trim( $value );
        foreach ( [ 'Y-m-d\TH:i', 'Y-m-d H:i' ] as $format ) {
            $d = \DateTimeImmutable::createFromFormat( '!' . $format, $value, $tz );
            // Round-trip, so 2026-02-31 or 25:00 can't slide into a different day.
            if ( $d !== false && $d->format( $format ) === $value ) {
                return $d->getTimestamp();
            }
        }
        return null;
    }

    /**
     * Move a time forward to the next moment sending is allowed: not on a
     * weekend if weekdays_only, and inside [window_start, window_end) if a
     * window is set. A time that is already allowed is returned unchanged.
     *
     * @param array<string,mixed> $opts normalize_options()'s `options`.
     */
    public static function align_to_window( int $ts, array $opts, \DateTimeZone $tz ): int {
        $weekdaysOnly = ! empty( $opts['weekdays_only'] );
        $hasWindow    = ! empty( $opts['has_window'] );
        if ( ! $weekdaysOnly && ! $hasWindow ) {
            return $ts;
        }
        $ws = (int) ( $opts['window_start'] ?? 0 );
        $we = (int) ( $opts['window_end'] ?? 24 );

        $d = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
        // A fortnight of looking is more than any window needs; the bound is only a backstop.
        for ( $i = 0; $i < 15; $i++ ) {
            if ( $weekdaysOnly && (int) $d->format( 'N' ) >= 6 ) {
                $d = $d->modify( 'next monday' )->setTime( $hasWindow ? $ws : 0, 0 );
                continue;
            }
            if ( $hasWindow ) {
                $hour = (int) $d->format( 'G' );
                if ( $hour < $ws ) {
                    $d = $d->setTime( $ws, 0 );
                    continue;
                }
                if ( $hour >= $we ) {
                    $d = $d->modify( '+1 day' )->setTime( $ws, 0 );
                    continue;
                }
            }
            return $d->getTimestamp();
        }
        return $d->getTimestamp();
    }

    /**
     * When the batch after one that ran at $from should run.
     *
     * @param array<string,mixed> $opts
     */
    public static function next_run_ts( int $from, array $opts, \DateTimeZone $tz ): int {
        return self::align_to_window( $from + ( (int) ( $opts['interval_min'] ?? self::MIN_INTERVAL ) * 60 ), $opts, $tz );
    }

    /**
     * How long a send of $count invoices will take under these options.
     *
     * @param array<string,mixed> $opts
     * @return array{batches:int,first_ts:int,last_ts:int}
     */
    public static function estimate( int $count, array $opts, \DateTimeZone $tz ): array {
        $size    = max( 1, (int) ( $opts['batch_size'] ?? 1 ) );
        $batches = $count > 0 ? (int) ceil( $count / $size ) : 0;
        $first   = self::align_to_window( (int) ( $opts['start_ts'] ?? 0 ), $opts, $tz );
        $last    = $first;
        for ( $i = 1; $i < min( $batches, 5000 ); $i++ ) {
            $last = self::next_run_ts( $last, $opts, $tz );
        }
        return [ 'batches' => $batches, 'first_ts' => $first, 'last_ts' => $last ];
    }

    /**
     * The next batch: invoices in id order, skipping any already tried the
     * maximum number of times.
     *
     * @param array<int,object>  $rows     Sendable rows.
     * @param array<int|string,int> $attempts invoice id => times tried.
     * @return array<int,object>
     */
    public static function pick_batch( array $rows, array $attempts, int $max ): array {
        usort( $rows, static function ( $a, $b ) { return (int) $a->id <=> (int) $b->id; } );
        $out = [];
        foreach ( $rows as $row ) {
            if ( (int) ( $attempts[ (int) $row->id ] ?? 0 ) >= self::MAX_ATTEMPTS_PER_ROW ) {
                continue;
            }
            $out[] = $row;
            if ( count( $out ) >= $max ) {
                break;
            }
        }
        return $out;
    }

    /**
     * Whether a failed send says the MAIL SYSTEM is unhealthy (so repeated
     * ones halt the schedule) rather than that one invoice has a data problem
     * (no email on file) — which must not stop everyone else's.
     */
    public static function is_transport_failure( string $error ): bool {
        return stripos( $error, 'wp_mail' ) !== false || stripos( $error, 'mail configuration' ) !== false;
    }

    public static function is_open( string $status ): bool {
        return in_array( $status, [ self::ST_SCHEDULED, self::ST_RUNNING, self::ST_PAUSED, self::ST_HALTED ], true );
    }

    public static function is_active( string $status ): bool {
        return in_array( $status, [ self::ST_SCHEDULED, self::ST_RUNNING ], true );
    }

    /**
     * A short fingerprint of a list of invoice ids, so a confirmation can be
     * tied to the exact list the admin was shown (not merely its length).
     *
     * @param array<int,int|string> $ids
     */
    public static function list_fingerprint( array $ids ): string {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static function ( $n ) { return $n > 0; } ) ) );
        sort( $ids );
        return substr( sha1( implode( ',', $ids ) ), 0, 16 );
    }

    /**
     * Keep only the rows the admin confirmed. A record without a confirmed
     * list (none is created without one) is left unfiltered.
     *
     * @param array<int,object>   $rows
     * @param array<string,mixed> $rec
     * @return array<int,object>
     */
    private static function confirmed_rows( array $rows, array $rec ): array {
        if ( ! isset( $rec['row_ids'] ) || ! is_array( $rec['row_ids'] ) ) {
            return $rows;
        }
        $ok = array_flip( array_map( 'intval', $rec['row_ids'] ) );
        return array_values( array_filter( $rows, static function ( $r ) use ( $ok ) { return isset( $ok[ (int) $r->id ] ); } ) );
    }

    /**
     * The invoices that can be emailed: issued (`created`) and not yet sent,
     * with a payment link, excluding online joins (those were paid at
     * Checkout and have nothing to send). Id order.
     *
     * @return array<int,object>
     */
    public static function unsent_rows( int $year, bool $livemode ): array {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $year, [ MyNJILGA_Dues_Invoice_Table::STATUS_CREATED ], $livemode );
        $rows = array_values( array_filter( (array) $rows, static function ( $r ) {
            return (string) ( $r->invoice_kind ?? '' ) !== MyNJILGA_Dues_Snapshot::KIND_JOIN && (string) ( $r->hosted_invoice_url ?? '' ) !== '';
        } ) );
        usort( $rows, static function ( $a, $b ) { return (int) $a->id <=> (int) $b->id; } );
        return $rows;
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @return array<int|string,array<string,mixed>> year => record */
    public static function all(): array {
        $all = get_option( self::OPTION, [] );
        return is_array( $all ) ? $all : [];
    }

    /** @return array<string,mixed>|null */
    public static function get( int $year ): ?array {
        $all = self::all();
        return isset( $all[ $year ] ) && is_array( $all[ $year ] ) ? $all[ $year ] : null;
    }

    /** @param array<string,mixed> $record */
    public static function put( int $year, array $record ): void {
        $all          = self::all();
        $all[ $year ] = $record;
        update_option( self::OPTION, $all, false );
    }

    /** The one schedule that is still live (scheduled, running, paused or halted), if any. @return array<string,mixed>|null */
    public static function open_record(): ?array {
        foreach ( self::all() as $record ) {
            if ( is_array( $record ) && self::is_open( (string) ( $record['status'] ?? '' ) ) ) {
                return $record;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $rec
     * @return array<string,mixed>
     */
    private static function note( array $rec, int $now, string $message ): array {
        $notes   = (array) ( $rec['notes'] ?? [] );
        $notes[] = [ 'ts' => $now, 'msg' => $message ];
        $rec['notes'] = array_slice( $notes, -self::NOTES_KEPT );
        return $rec;
    }

    // -------------------------------------------------------------------------
    // Controls (called by the wizard's handlers)
    // -------------------------------------------------------------------------

    /**
     * Start a schedule for a year.
     *
     * @param array<string,mixed> $options  normalize_options()'s `options`.
     * @param array<int,int>      $rowIds   The invoices the admin confirmed — and the only ones this schedule will ever send.
     * @return array{ok:bool,error:string}
     */
    public static function create( int $year, array $options, bool $livemode, int $userId, string $notifyEmail, array $rowIds, ?array $ports = null ): array {
        $p      = self::ports( $ports );
        $now    = (int) $p['now']();
        $rowIds = array_values( array_unique( array_filter( array_map( 'intval', $rowIds ), static function ( $n ) { return $n > 0; } ) ) );
        $unsent = count( $rowIds );

        $open = $p['open']();
        if ( $open ) {
            return [ 'ok' => false, 'error' => sprintf( 'A send for %d is already %s. Cancel it first if you want to schedule a different one.', (int) $open['year'], (string) $open['status'] ) ];
        }
        if ( $unsent < 1 ) {
            return [ 'ok' => false, 'error' => 'There are no created, unsent invoices to send.' ];
        }
        $start = (int) $options['start_ts'];
        $tz    = $p['tz']();
        $first = self::align_to_window( $start, $options, $tz );

        $rec = [
            'year'                 => $year,
            'status'               => self::ST_SCHEDULED,
            'livemode'             => $livemode,
            'options'              => $options,
            'start_ts'             => $first,
            'next_run_ts'          => $first,
            'created_by'           => $userId,
            'created_at'           => $now,
            'notify_email'         => $notifyEmail,
            'unsent_at_schedule'   => $unsent,
            'row_ids'              => $rowIds,
            'sent'                 => 0,
            'failed'               => 0,
            'consecutive_failures' => 0,
            'attempts'             => [],
            'failures'             => [],
            'last_run_ts'          => 0,
            'notes'                => [],
        ];
        $rec = self::note( $rec, $now, sprintf( 'Scheduled: %d invoice%s, %d per batch every %d minutes, starting %s.', $unsent, $unsent === 1 ? '' : 's', (int) $options['batch_size'], (int) $options['interval_min'], gmdate( 'Y-m-d H:i', $first ) . ' UTC' ) );

        if ( ! $p['schedule']( $first, $year ) ) {
            return [ 'ok' => false, 'error' => 'Action Scheduler would not accept the schedule, so nothing was scheduled. Check that FluentCRM (which bundles it) is active, then try again.' ];
        }
        $p['save']( $year, $rec );
        return [ 'ok' => true, 'error' => '' ];
    }

    /** @return array{ok:bool,error:string} */
    public static function pause( int $year, ?array $ports = null ): array {
        $p   = self::ports( $ports );
        $rec = $p['load']( $year );
        if ( ! $rec || ! self::is_active( (string) $rec['status'] ) ) {
            return [ 'ok' => false, 'error' => 'There is no running schedule to pause.' ];
        }
        $rec['status'] = self::ST_PAUSED;
        $p['save']( $year, self::note( $rec, (int) $p['now'](), 'Paused.' ) );
        $p['unschedule']( $year );
        return [ 'ok' => true, 'error' => '' ];
    }

    /** @return array{ok:bool,error:string} */
    public static function resume( int $year, ?array $ports = null ): array {
        $p   = self::ports( $ports );
        $rec = $p['load']( $year );
        if ( ! $rec || ! in_array( (string) $rec['status'], [ self::ST_PAUSED, self::ST_HALTED ], true ) ) {
            return [ 'ok' => false, 'error' => 'There is nothing paused to resume.' ];
        }
        if ( $p['live']() !== (bool) $rec['livemode'] ) {
            return [ 'ok' => false, 'error' => 'Stripe is no longer in the mode this was scheduled in. Switch it back (Settings → Payments), or cancel and schedule again.' ];
        }
        $now  = (int) $p['now']();
        $next = self::align_to_window( max( $now + self::MIN_LEAD, (int) ( $rec['next_run_ts'] ?? 0 ) ), (array) $rec['options'], $p['tz']() );
        if ( ! $p['schedule']( $next, $year ) ) {
            return [ 'ok' => false, 'error' => 'Action Scheduler would not accept the schedule.' ];
        }
        $rec['status']               = self::ST_RUNNING;
        $rec['next_run_ts']          = $next;
        $rec['consecutive_failures'] = 0;
        $p['save']( $year, self::note( $rec, $now, 'Resumed.' ) );
        return [ 'ok' => true, 'error' => '' ];
    }

    /** @return array{ok:bool,error:string} */
    public static function cancel( int $year, ?array $ports = null ): array {
        $p   = self::ports( $ports );
        $rec = $p['load']( $year );
        if ( ! $rec || ! self::is_open( (string) $rec['status'] ) ) {
            return [ 'ok' => false, 'error' => 'There is no open schedule to cancel.' ];
        }
        $rec['status'] = self::ST_CANCELLED;
        $p['save']( $year, self::note( $rec, (int) $p['now'](), 'Cancelled.' ) );
        $p['unschedule']( $year );
        return [ 'ok' => true, 'error' => '' ];
    }

    // -------------------------------------------------------------------------
    // The Action Scheduler entry point
    // -------------------------------------------------------------------------

    public static function register(): void {
        // Accepted args 1, and run_scheduled() takes its argument untyped.
        add_action( self::HOOK_RUN, [ __CLASS__, 'run_scheduled' ], 10, 1 );
    }

    /**
     * Action Scheduler callback. UNTYPED on purpose — see the class docblock.
     *
     * @param mixed $year The year, as stored in the action's args.
     */
    public static function run_scheduled( $year = 0 ): void {
        $year = is_scalar( $year ) ? (int) $year : 0;
        if ( $year < 2000 || $year > 2100 ) {
            return;
        }
        try {
            self::run_batch( $year );
        } catch ( \Throwable $e ) {
            // Never let a failure here take down the Action Scheduler worker,
            // and never leave the schedule looking healthy after one.
            try {
                $rec = self::get( $year );
                if ( $rec && self::is_active( (string) $rec['status'] ) ) {
                    $rec['status'] = self::ST_HALTED;
                    $rec           = self::note( $rec, time(), 'Halted after an unexpected error: ' . $e->getMessage() );
                    self::put( $year, $rec );
                    $p = self::ports( null );
                    $p['unschedule']( $year );
                    self::notify( $p, $rec, 'halted' );
                }
            } catch ( \Throwable $inner ) {
                // Nothing more can be done from here.
            }
        }
    }

    // -------------------------------------------------------------------------
    // The batch
    // -------------------------------------------------------------------------

    /**
     * Run one batch.
     *
     * @param array<string,callable>|null $ports Overrides for default_ports() (tests).
     * @param bool                        $force Run even before the start time (the "send the next batch now" button).
     * @return array{outcome:string,sent:int,failed:int,remaining:int}
     */
    public static function run_batch( int $year, ?array $ports = null, bool $force = false ): array {
        $p      = self::ports( $ports );
        $result = static function ( string $outcome, int $sent = 0, int $failed = 0, int $remaining = 0 ): array {
            return [ 'outcome' => $outcome, 'sent' => $sent, 'failed' => $failed, 'remaining' => $remaining ];
        };

        $rec = $p['load']( $year );
        if ( ! $rec || ! self::is_active( (string) $rec['status'] ) ) {
            return $result( 'skipped' );
        }
        $now  = (int) $p['now']();
        $opts = (array) $rec['options'];
        $tz   = $p['tz']();

        // Not before the start, and not before the next batch is due: a duplicate or stray
        // firing of the hook between batches must not send an extra batch ahead of the pacing.
        $due = (int) ( $rec['next_run_ts'] ?? 0 ) ?: (int) $rec['start_ts'];
        if ( ! $force && $now < $due - 30 ) {
            // Fired early (or twice): put the next run where it belongs and do nothing now.
            return self::requeue( $p, $year, $now, $due, $result( 'early' ) );
        }

        // The hours and days the admin confirmed are enforced NOW, not only when the batch
        // was scheduled: Action Scheduler fires late on a quiet site, and a retry can land
        // anywhere. Outside them, wait for the next allowed moment instead of sending.
        if ( ! $force ) {
            $allowed = self::align_to_window( $now, $opts, $tz );
            if ( $allowed > $now ) {
                self::commit( $p, $year, $now, [ 'next_run_ts' => $allowed ] );
                return self::requeue( $p, $year, $now, $allowed, $result( 'outside_window' ) );
            }
        }

        if ( ! $p['lock']() ) {
            return self::requeue( $p, $year, $now, self::align_to_window( $now + 300, $opts, $tz ), $result( 'locked' ) );
        }

        try {
            // Re-read under the lock: a pause or cancel may have landed while we waited.
            $rec = $p['load']( $year );
            if ( ! $rec || ! self::is_active( (string) $rec['status'] ) ) {
                return $result( 'skipped' );
            }

            if ( $p['live']() !== (bool) $rec['livemode'] ) {
                return self::halt( $p, $year, $now, 'Stripe is no longer in the mode this send was scheduled in — halted so nobody is emailed a payment link from the wrong mode.', $result( 'halted_mode' ) );
            }

            $errors = (array) $p['ready_errors']();
            if ( $errors ) {
                $delays = (int) ( $rec['delays'] ?? 0 ) + 1;
                if ( $delays >= self::MAX_DELAYS ) {
                    return self::halt( $p, $year, $now, sprintf( 'Stripe has not been ready for %d checks in a row (%s) — halted. Fix the connection (Settings → Payments), then resume.', $delays, (string) reset( $errors ) ), $result( 'halted_not_ready' ), [ 'delays' => $delays ] );
                }
                $retry = self::align_to_window( $now + 900, $opts, $tz );
                self::commit( $p, $year, $now, [ 'note' => 'Stripe is not ready (' . (string) reset( $errors ) . ') — will try again in 15 minutes.', 'next_run_ts' => $retry, 'status' => self::ST_RUNNING, 'delays' => $delays ] );
                if ( ! $p['schedule']( $retry, $year ) ) {
                    return self::halt( $p, $year, $now, 'Stripe was not ready and the retry could not be scheduled.', $result( 'halted_schedule' ) );
                }
                return $result( 'delayed' );
            }

            // Arm the NEXT batch before any email goes out. If this one dies part-way (PHP's
            // time or memory limit skips every cleanup), the schedule still has a successor
            // instead of sitting "running" with nothing pending. If it can't be armed, send nothing.
            $next = self::next_run_ts( $now, $opts, $tz );
            if ( ! $p['schedule']( $next, $year ) ) {
                return self::halt( $p, $year, $now, 'The next batch could not be scheduled with Action Scheduler — halted before sending anything, so it cannot stall silently.', $result( 'halted_schedule' ) );
            }

            $livemode = (bool) $rec['livemode'];
            $batch    = self::pick_batch( self::confirmed_rows( (array) $p['rows']( $year, $livemode ), $rec ), (array) $rec['attempts'], (int) $rec['options']['batch_size'] );

            $sent        = 0;
            $failed      = 0;
            $consecutive = (int) ( $rec['consecutive_failures'] ?? 0 );
            $attempts    = [];
            $failures    = [];
            $stopped     = '';
            $started     = (float) $p['micro']();

            foreach ( $batch as $row ) {
                if ( (float) $p['micro']() - $started > self::TIME_BUDGET ) {
                    break; // Out of time: the rest wait for the next batch.
                }
                // A pause, cancel or mode flip between rows is honoured now, not at the next batch.
                $latest = $p['load']( $year );
                if ( ! $latest || ! self::is_active( (string) $latest['status'] ) ) {
                    $stopped = 'interrupted';
                    break;
                }
                if ( $p['live']() !== $livemode ) {
                    $stopped = 'mode';
                    break;
                }

                $id    = (int) $row->id;
                $fresh = $p['fresh']( $id );
                if ( ! $fresh || (string) $fresh->status !== 'created' ) {
                    continue; // Sent, paid or voided since the list was read: not ours to send.
                }

                $attempts[ $id ] = (int) ( ( (array) $rec['attempts'] )[ $id ] ?? 0 ) + 1;
                try {
                    $res = (array) $p['send']( $fresh );
                } catch ( \Throwable $e ) {
                    $res = [ 'ok' => false, 'error' => $e->getMessage() ];
                }

                if ( ! empty( $res['ok'] ) ) {
                    $sent++;
                    $consecutive = 0;
                } else {
                    $failed++;
                    $message = (string) ( $res['error'] ?? 'Unknown error' );
                    $failures[ $id ] = mb_substr( $message, 0, 300 );
                    $p['mark_error']( $id, $message );
                    // Only a MAIL problem counts toward halting; a firm with no
                    // email on file must not stop everyone else's invoice.
                    $consecutive = self::is_transport_failure( $message ) ? $consecutive + 1 : 0;
                    if ( $consecutive >= self::MAX_CONSECUTIVE_FAILURES ) {
                        $stopped = 'failures';
                        break;
                    }
                }
            }

            $remaining = count( self::pick_batch( self::confirmed_rows( (array) $p['rows']( $year, $livemode ), $rec ), array_replace( (array) $rec['attempts'], $attempts ), PHP_INT_MAX ) );

            $delta = [ 'sent' => $sent, 'failed' => $failed, 'attempts' => $attempts, 'failures' => $failures, 'consecutive_failures' => $consecutive, 'last_run_ts' => $now, 'delays' => 0 ];

            if ( $stopped === 'failures' ) {
                $delta['status'] = self::ST_HALTED;
                $delta['note']   = sprintf( 'Halted: %d emails in a row could not be sent by the mail system. Check the site\'s mail configuration, send yourself a test email, then resume.', self::MAX_CONSECUTIVE_FAILURES );
                $final = self::commit( $p, $year, $now, $delta );
                $p['unschedule']( $year );
                self::notify( $p, $final, 'halted' );
                return $result( 'halted_failures', $sent, $failed, $remaining );
            }
            if ( $stopped === 'mode' ) {
                return self::halt( $p, $year, $now, 'Stripe changed mode part-way through a batch — halted.', $result( 'halted_mode', $sent, $failed, $remaining ), $delta );
            }
            if ( $stopped === 'interrupted' ) {
                self::commit( $p, $year, $now, $delta );
                return $result( 'interrupted', $sent, $failed, $remaining );
            }

            if ( $remaining === 0 ) {
                $delta['status']      = self::ST_COMPLETE;
                $delta['finished_ts'] = $now;
                $delta['note']        = 'Complete: every sendable invoice has been emailed.';
                $final = self::commit( $p, $year, $now, $delta );
                $p['unschedule']( $year ); // the successor armed above has nothing left to do
                self::notify( $p, $final, 'complete' );
                return $result( 'complete', $sent, $failed, 0 );
            }

            // The successor was armed before sending (at $next); record when it is due.
            $delta['status']      = self::ST_RUNNING;
            $delta['next_run_ts'] = $next;
            self::commit( $p, $year, $now, $delta );
            return $result( 'batch', $sent, $failed, $remaining );
        } finally {
            $p['unlock']();
        }
    }

    /**
     * Fold a batch's outcome into the CURRENT record (re-read, so a pause or
     * cancel that landed meanwhile is never overwritten), and return it.
     *
     * @param array<string,callable> $p
     * @param array<string,mixed>    $delta
     * @return array<string,mixed>
     */
    private static function commit( array $p, int $year, int $now, array $delta ): array {
        $rec = $p['load']( $year );
        if ( ! $rec ) {
            return [];
        }
        $rec['sent']   = (int) ( $rec['sent'] ?? 0 ) + (int) ( $delta['sent'] ?? 0 );
        $rec['failed'] = (int) ( $rec['failed'] ?? 0 ) + (int) ( $delta['failed'] ?? 0 );
        if ( isset( $delta['attempts'] ) ) {
            $rec['attempts'] = array_replace( (array) $rec['attempts'], (array) $delta['attempts'] );
        }
        if ( ! empty( $delta['failures'] ) ) {
            $rec['failures'] = array_slice( array_replace( (array) $rec['failures'], (array) $delta['failures'] ), -self::FAILURES_KEPT, null, true );
        }
        foreach ( [ 'consecutive_failures', 'last_run_ts', 'next_run_ts', 'finished_ts', 'delays' ] as $k ) {
            if ( isset( $delta[ $k ] ) ) {
                $rec[ $k ] = $delta[ $k ];
            }
        }
        // A status change applies only while the schedule is still live: it must never revive one
        // that staff paused or cancelled while this batch was running.
        if ( isset( $delta['status'] ) && self::is_active( (string) $rec['status'] ) ) {
            $rec['status'] = $delta['status'];
        }
        if ( ! empty( $delta['note'] ) ) {
            $rec = self::note( $rec, $now, (string) $delta['note'] );
        } elseif ( ( $delta['sent'] ?? 0 ) || ( $delta['failed'] ?? 0 ) ) {
            $rec = self::note( $rec, $now, sprintf( 'Batch: %d sent, %d failed.', (int) ( $delta['sent'] ?? 0 ), (int) ( $delta['failed'] ?? 0 ) ) );
        }
        $p['save']( $year, $rec );
        return $rec;
    }

    /**
     * Halt the schedule, tell staff, and return the given outcome.
     *
     * @param array<string,callable> $p
     * @param array<string,mixed>    $outcome
     * @param array<string,mixed>    $delta   Progress made before halting, to keep.
     * @return array<string,mixed>
     */
    private static function halt( array $p, int $year, int $now, string $reason, array $outcome, array $delta = [] ): array {
        $delta['status'] = self::ST_HALTED;
        $delta['note']   = $reason;
        $final = self::commit( $p, $year, $now, $delta );
        $p['unschedule']( $year );
        self::notify( $p, $final, 'halted' );
        return $outcome;
    }

    /**
     * Put the next run at $ts. If Action Scheduler won't take it the schedule
     * halts (visibly) rather than sit "scheduled" with nothing pending.
     *
     * @param array<string,callable> $p
     * @param array<string,mixed>    $outcome What to return when it worked.
     * @return array<string,mixed>
     */
    private static function requeue( array $p, int $year, int $now, int $ts, array $outcome ): array {
        if ( $p['schedule']( $ts, $year ) ) {
            return $outcome;
        }
        return self::halt( $p, $year, $now, 'The next batch could not be scheduled with Action Scheduler — halted so it does not stall silently.', [ 'outcome' => 'halted_schedule', 'sent' => 0, 'failed' => 0, 'remaining' => 0 ] );
    }

    /**
     * Email the person who scheduled it.
     *
     * @param array<string,callable> $p
     * @param array<string,mixed>    $rec
     */
    private static function notify( array $p, array $rec, string $kind ): void {
        $to = (string) ( $rec['notify_email'] ?? '' );
        if ( $to === '' ) {
            return;
        }
        $msg = self::summary_message( $rec, $kind );
        try {
            $p['notify']( $to, $msg['subject'], $msg['body'] );
        } catch ( \Throwable $e ) {
            // A failed notice must not undo the halt or completion it reports.
        }
    }

    /**
     * The staff notice.
     *
     * @param array<string,mixed> $rec
     * @return array{subject:string,body:string}
     */
    public static function summary_message( array $rec, string $kind ): array {
        $year   = (int) ( $rec['year'] ?? 0 );
        $sent   = (int) ( $rec['sent'] ?? 0 );
        $failed = (int) ( $rec['failed'] ?? 0 );
        $lines  = [];
        if ( $kind === 'complete' ) {
            $subject = sprintf( 'My NJILGA: the %d dues invoice emails have all been sent', $year );
            $lines[] = sprintf( 'The scheduled send is complete: %d email%s sent.', $sent, $sent === 1 ? '' : 's' );
        } else {
            $subject = sprintf( 'My NJILGA: the %d dues invoice send was HALTED', $year );
            $notes   = (array) ( $rec['notes'] ?? [] );
            $last    = $notes ? (array) end( $notes ) : [];
            $lines[] = sprintf( 'The scheduled send stopped after %d email%s. Nothing more will go out until you resume or cancel it.', $sent, $sent === 1 ? '' : 's' );
            if ( ! empty( $last['msg'] ) ) {
                $lines[] = 'Why: ' . (string) $last['msg'];
            }
        }
        if ( $failed > 0 ) {
            $lines[] = sprintf( '%d invoice%s could not be sent and need%s a person — see the failures listed on the Launch Wizard.', $failed, $failed === 1 ? '' : 's', $failed === 1 ? 's' : '' );
        }
        $lines[] = 'Open My NJILGA → Launch Wizard → Send to see the details.';
        return [ 'subject' => $subject, 'body' => implode( "\n\n", $lines ) ];
    }

    // -------------------------------------------------------------------------
    // The send lock
    // -------------------------------------------------------------------------

    /**
     * Claim the send lock. Returns a token to release it with, or '' when
     * another batch (or a manual send) holds it.
     *
     * The claim is a single INSERT IGNORE on the options table, so exactly one
     * caller can create the row — add_option() is not enough, because it checks
     * and then inserts with ON DUPLICATE KEY UPDATE, which lets two racing
     * callers both succeed. A dead run's stale lock is taken over with a
     * compare-and-set on the value we saw, so two takers cannot both win.
     * Each holder releases only ITS OWN token.
     */
    public static function acquire_lock(): string {
        global $wpdb;
        $name = self::LOCK_OPTION;
        $now  = time();
        try {
            $token = $now . ':' . bin2hex( random_bytes( 6 ) );
        } catch ( \Throwable $e ) {
            $token = $now . ':' . mt_rand();
        }
        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            if ( (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $name, $token ) ) === 1 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return $token;
            }
            $held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $held === '' ) {
                continue; // released between our two statements: try the insert again
            }
            if ( (int) $held > 0 && $now - (int) $held < self::LOCK_TTL ) {
                return '';
            }
            // A dead run's lock: take it over only if it is still exactly what we saw.
            return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $held ) ) === 1 ? $token : ''; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        return '';
    }

    /** Release a lock taken with acquire_lock(): only if it is still ours. */
    public static function release_lock( string $token ): void {
        global $wpdb;
        if ( $token === '' ) {
            return;
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** run_batch()'s lock port: remembers the token for drop_lock(). */
    public static function take_lock(): bool {
        $token = self::acquire_lock();
        if ( $token === '' ) {
            return false;
        }
        self::$lockToken = $token;
        return true;
    }

    public static function drop_lock(): void {
        self::release_lock( self::$lockToken );
        self::$lockToken = '';
    }

    // -------------------------------------------------------------------------
    // Ports
    // -------------------------------------------------------------------------

    /**
     * @param array<string,callable>|null $overrides
     * @return array<string,callable>
     */
    private static function ports( ?array $overrides ): array {
        return $overrides === null ? self::default_ports() : array_replace( self::default_ports(), $overrides );
    }

    /**
     * The real wiring. Closures, so none of it runs (or needs WordPress) until a port is called.
     *
     * @return array<string,callable>
     */
    public static function default_ports(): array {
        return [
            'now'   => static function (): int { return time(); },
            'micro' => static function (): float { return microtime( true ); },
            'tz'    => static function (): \DateTimeZone { return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' ); },
            'live'  => static function (): bool { return MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE; },

            'load'  => static function ( int $year ) { return MyNJILGA_Launch_Schedule::get( $year ); },
            'save'  => static function ( int $year, array $rec ): void { MyNJILGA_Launch_Schedule::put( $year, $rec ); },
            'open'  => static function () { return MyNJILGA_Launch_Schedule::open_record(); },

            'ready_errors' => static function (): array {
                $gateway = MyNJILGA_Invoicing::gateway();
                if ( ! $gateway->is_available() ) {
                    return [ $gateway->name() . ' is not active.' ];
                }
                return (array) $gateway->readiness_errors();
            },
            'rows' => static function ( int $year, bool $livemode ): array { return MyNJILGA_Launch_Schedule::unsent_rows( $year, $livemode ); },
            'fresh'      => static function ( int $id ) { return MyNJILGA_Dues_Invoice_Table::get( $id ); },
            'send'       => static function ( $row ): array { return MyNJILGA_Invoice_Sender::send_for_row( $row ); },
            'mark_error' => static function ( int $id, string $message ): void { MyNJILGA_Dues_Invoice_Table::set_error( $id, $message ); },

            'schedule' => static function ( int $ts, int $year ): bool {
                if ( ! function_exists( 'as_schedule_single_action' ) ) {
                    return false;
                }
                // One pending batch per year: replace, never stack.
                if ( function_exists( 'as_unschedule_all_actions' ) ) {
                    as_unschedule_all_actions( MyNJILGA_Launch_Schedule::HOOK_RUN, [ $year ], MyNJILGA_Launch_Schedule::AS_GROUP );
                }
                return (int) as_schedule_single_action( $ts, MyNJILGA_Launch_Schedule::HOOK_RUN, [ $year ], MyNJILGA_Launch_Schedule::AS_GROUP ) > 0;
            },
            'unschedule' => static function ( int $year ): void {
                if ( function_exists( 'as_unschedule_all_actions' ) ) {
                    as_unschedule_all_actions( MyNJILGA_Launch_Schedule::HOOK_RUN, [ $year ], MyNJILGA_Launch_Schedule::AS_GROUP );
                }
            },

            'lock'   => static function (): bool { return MyNJILGA_Launch_Schedule::take_lock(); },
            'unlock' => static function (): void { MyNJILGA_Launch_Schedule::drop_lock(); },

            'notify' => static function ( string $to, string $subject, string $body ): void { wp_mail( $to, $subject, $body ); },
        ];
    }
}
