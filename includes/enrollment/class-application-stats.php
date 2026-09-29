<?php
/**
 * Application and online-join statistics for the Dashboard — the one place
 * that decides what "waiting on a person" means for the two intake queues,
 * so the landing page never grows a third number next to the menu bubble
 * and the Online joins tab.
 *
 * Same shape as MyNJILGA_Ledger_Totals and MyNJILGA_Invoice_Stats: the
 * rules live in PURE methods (rollup(), age_days(), attention_sentence(),
 * join_problem_for(), count_held() — no database, no WordPress, unit-tested
 * in tests/ApplicationStatsTest.php), and a thin layer of SQL/WordPress glue
 * feeds them. The glue is a handful of COUNTs; it never loads an
 * application or a join row (the one exception is the id/status/progress of
 * the PAID joins, which is how a held payment is told from one that
 * failed part-way — and is skipped when there are none).
 *
 * The queues, and what each figure means:
 *
 *   Applications  njilga_membership_applications. Only `pending` needs a
 *                 human (Approve / Reject). Approved-this-year counts
 *                 status = approved decided on or after Jan 1 of the year;
 *                 rejected and superseded ("Joined online") are different
 *                 outcomes and are not in it.
 *   Online joins  njilga_join_orders ([njilga_join]). Attention is
 *                 EXACTLY MyNJILGA_Join_Orders_Table::needs_attention_count()
 *                 — the menu bubble's SQL — read for one Stripe mode, and
 *                 split, never re-derived, into:
 *                   review   $0 joins waiting for Approve / Reject
 *                   paid     paid, not yet applied (Retry applying). Counted
 *                            as one figure; paid_held is the subset whose
 *                            checkout did not verify, told apart with
 *                            MyNJILGA_Join_Orders_Table::payment_settled()
 *                            (not settled = held for staff, who can only fix
 *                            it at Stripe — Retry applying will keep
 *                            refusing it). The rest of `paid` failed
 *                            part-way and is retried by the daily sweep.
 *                   other    a stale fulfilling claim, or an applied join
 *                            carrying a review flag = needs_attention −
 *                            review − paid, floored at 0. Valid because the
 *                            SQL counts every review and every paid row
 *                            unconditionally.
 *                 Not attention, reported as information: awaiting_payment
 *                 (`pending`, Checkout open), clearing (`processing`, an
 *                 ACH debit in flight — no action exists for it), and
 *                 failed (`failed`, terminal and ALL-TIME: the applicant was
 *                 emailed a retry link when it failed, so it never decays
 *                 — show it muted, not amber).
 *   total_attention   applications.pending + joins.needs_attention.
 *
 * Test mode. Joins carry `livemode` (a rehearsal or a Test-mode site writes
 * 0); applications do not (they are form submissions, not Stripe objects).
 * snapshot( $live ) scopes every JOIN figure to that mode and leaves the
 * application figures alone. The menu bubble is deliberately NOT scoped —
 * it is the unscoped needs_attention_count() plus count_pending(), and its
 * SQL is unchanged — so it always reads >= total_attention: a rehearsal $0
 * join that sits in `review` while the site is Live shows in the bubble and
 * not on the Dashboard (and, on a site switched to Test, the Live joins
 * still waiting show in the bubble and not here).
 *
 * Time. Every stored DATETIME here is a site-local wall-clock string
 * (current_time( 'mysql' )), so ages are `site-local now − stored`, both
 * read as wall-clock — never time(). oldest_days for applications is the
 * age of the oldest PENDING application's created_at, which a re-submission
 * by the same email rewrites, so it can read younger than the applicant has
 * really waited. oldest_clearing_days is a proxy (the move to `processing`
 * writes no timestamp of its own, so created_at is used) and reads a little
 * old, never young.
 *
 * Year. `year` is MyNJILGA_Invoicing::current_dues_year() (the UTC calendar
 * year, like every dues year in this plugin). joined_year counts joins
 * FULFILLED in that calendar year on the site clock (see
 * MyNJILGA_Join_Orders_Table::joined_count()) — when it was applied, not
 * the dues year it paid for, and joins, not people. For the few hours
 * around New Year when UTC is already in the new year and the site clock is
 * not, the new year's two counts simply read 0 until site midnight.
 */
class MyNJILGA_Application_Stats {

    /** Seconds in a day — the unit of every age here. */
    const DAY_SECONDS = 86400;

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Everything the Dashboard's Applications section shows, for the given
     * Stripe mode ($live: the ACTIVE mode, as the Dashboard's other cards
     * read it). Join figures are scoped to that mode; application figures
     * are not (see the class docblock).
     *
     * Never calls Stripe: join_problem is decided from options alone.
     *
     * @return array{
     *   year:int,
     *   applications:array{pending:int,oldest_days:?int,approved_year:int},
     *   joins:array{needs_attention:int,review:int,paid:int,paid_held:int,other_attention:int,awaiting_payment:int,clearing:int,oldest_clearing_days:?int,failed:int,joined_year:int},
     *   total_attention:int,
     *   join_problem:string
     * }
     */
    public static function snapshot( bool $live ): array {
        return self::build(
            $live,
            MyNJILGA_Invoicing::current_dues_year(),
            // The site's wall clock as a timestamp — what needs_attention()
            // compares a claim's updated_at against, and what every stored
            // DATETIME here was written with.
            (int) current_time( 'timestamp' ),
            self::join_problem( $live )
        );
    }

    /**
     * snapshot() with every WordPress-derived input (the year, the site
     * clock, the join-problem text) handed in, so the whole read path — each
     * query, the skip-when-zero shortcuts, the merge — runs against a
     * stubbed $wpdb in the tests. Calls no WordPress function.
     *
     * At most eight statements: applications by status, the oldest pending
     * (only when any is pending), approved this year, joins by status, the
     * attention count, the oldest clearing (only when any is clearing),
     * joined this year, and the paid joins' progress (only when any is
     * paid).
     *
     * @return array<string,mixed> See snapshot().
     */
    public static function build( bool $live, int $year, int $now, string $joinProblem = '' ): array {
        global $wpdb;

        $appCounts   = MyNJILGA_Applications_Table::counts_by_status();
        $joinCounts  = MyNJILGA_Join_Orders_Table::counts_by_status( $live );
        $pending     = (int) ( $appCounts[ MyNJILGA_Applications_Table::STATUS_PENDING ] ?? 0 );
        $paid        = (int) ( $joinCounts[ MyNJILGA_Join_Orders_Table::STATUS_PAID ] ?? 0 );
        $clearing    = (int) ( $joinCounts[ MyNJILGA_Join_Orders_Table::STATUS_PROCESSING ] ?? 0 );

        return self::rollup( [
            'year'                 => $year,
            'app_counts'           => $appCounts,
            'app_oldest_pending'   => $pending > 0 ? MyNJILGA_Applications_Table::oldest_pending_created() : null,
            'app_approved_year'    => MyNJILGA_Applications_Table::approved_since( self::year_start( $year ) ),
            'join_counts'          => $joinCounts,
            // The menu bubble's own statement, for one mode: this figure and
            // the bubble can only differ by the rows of the other mode.
            'join_needs_attention' => (int) $wpdb->get_var( MyNJILGA_Join_Orders_Table::needs_attention_sql( $live, $now ) ), // phpcs:ignore
            'join_oldest_clearing' => $clearing > 0 ? MyNJILGA_Join_Orders_Table::oldest_created( MyNJILGA_Join_Orders_Table::STATUS_PROCESSING, $live ) : null,
            'join_joined_year'     => MyNJILGA_Join_Orders_Table::joined_count( $year, $live ),
            'join_paid_held'       => $paid > 0 ? self::count_held( MyNJILGA_Join_Orders_Table::get_paid_progress( $live ) ) : 0,
            'join_problem'         => $joinProblem,
        ], $now );
    }

    // =========================================================================
    // Pure logic — no WordPress, no database
    // =========================================================================

    /**
     * The facts in, the snapshot out (see the class docblock for every rule).
     *
     * $facts — a missing key reads as 0, null or '':
     *   year                  int
     *   app_counts            array<string,int>  applications, status => n
     *   app_oldest_pending    ?string            MIN(created_at) of pending ones
     *   app_approved_year     int                approved on/after Jan 1 of year
     *   join_counts           array<string,int>  joins in ONE mode, status => n
     *   join_needs_attention  int                the menu bubble's count, same mode
     *   join_oldest_clearing  ?string            MIN(created_at) of processing ones
     *   join_joined_year      int                fulfilled within the calendar year
     *   join_paid_held        int                paid joins whose payment isn't settled
     *   join_problem          string             why joining is unavailable, or ''
     * $now is the site clock as current_time( 'timestamp' ) returns it.
     *
     * An age is only reported for a queue that has something in it, so a
     * stray timestamp beside a count of 0 can never print "oldest waiting".
     *
     * @param array<string,mixed> $facts
     * @return array<string,mixed> See snapshot().
     */
    public static function rollup( array $facts, int $now ): array {
        $A = 'MyNJILGA_Applications_Table';
        $J = 'MyNJILGA_Join_Orders_Table';

        $appCounts  = (array) ( $facts['app_counts'] ?? [] );
        $joinCounts = (array) ( $facts['join_counts'] ?? [] );

        $pending  = self::count_of( $appCounts, $A::STATUS_PENDING );
        $review   = self::count_of( $joinCounts, $J::STATUS_REVIEW );
        $paid     = self::count_of( $joinCounts, $J::STATUS_PAID );
        $clearing = self::count_of( $joinCounts, $J::STATUS_PROCESSING );
        $needs    = max( 0, (int) ( $facts['join_needs_attention'] ?? 0 ) );

        $joins = [
            'needs_attention'      => $needs,
            'review'               => $review,
            'paid'                 => $paid,
            // A subset of paid: never more than there are.
            'paid_held'            => min( $paid, max( 0, (int) ( $facts['join_paid_held'] ?? 0 ) ) ),
            // A stale claim or a flagged fulfilled join. Floored: the two
            // counts come from two statements, and a join that moved between
            // them must not print a negative.
            'other_attention'      => max( 0, $needs - $review - $paid ),
            'awaiting_payment'     => self::count_of( $joinCounts, $J::STATUS_PENDING ),
            'clearing'             => $clearing,
            'oldest_clearing_days' => $clearing > 0 ? self::age_days( self::text_or_null( $facts['join_oldest_clearing'] ?? null ), $now ) : null,
            'failed'               => self::count_of( $joinCounts, $J::STATUS_FAILED ),
            'joined_year'          => max( 0, (int) ( $facts['join_joined_year'] ?? 0 ) ),
        ];

        return [
            'year'            => (int) ( $facts['year'] ?? 0 ),
            'applications'    => [
                'pending'       => $pending,
                'oldest_days'   => $pending > 0 ? self::age_days( self::text_or_null( $facts['app_oldest_pending'] ?? null ), $now ) : null,
                'approved_year' => max( 0, (int) ( $facts['app_approved_year'] ?? 0 ) ),
            ],
            'joins'           => $joins,
            'total_attention' => $pending + $needs,
            'join_problem'    => (string) ( $facts['join_problem'] ?? '' ),
        ];
    }

    /**
     * Whole days from a site-local DATETIME ('Y-m-d H:i:s', or a bare
     * 'Y-m-d' = midnight) to $now, floored: 23h59m59s is 0 days, exactly 24h
     * is 1. Never negative — a timestamp in the future (a clock that moved,
     * a row written a moment after $now was read) is 0, not an error.
     *
     * Null when there is no real date to age: null, '', MySQL's zero date,
     * an impossible date, or anything that is not a date at all.
     *
     * $now is the site clock as current_time( 'timestamp' ) returns it — a
     * wall-clock reading held in a timestamp — and the stored string is
     * read the same way, as wall-clock, so neither the server's PHP timezone
     * nor the site's offset enters the sum. (A daylight-saving change in
     * between shifts an age by an hour, which only shows on a boundary.)
     */
    public static function age_days( ?string $datetime, int $now ): ?int {
        $then = self::wall_clock( $datetime );
        if ( $then === null ) {
            return null;
        }
        return max( 0, intdiv( $now - $then, self::DAY_SECONDS ) );
    }

    /**
     * The joins' attention breakdown as one line for the Dashboard, zero
     * terms omitted, '' when nothing needs a person:
     *
     *   "2 $0 joins awaiting your decision · 1 paid, not applied · 1 to review"
     *
     * Reads review, paid, paid_held (optional) and other_attention from
     * snapshot()['joins']. When some of the paid joins are held — the
     * checkout didn't verify, so Retry applying won't move them — the
     * paid term says so: "3 paid, not applied (1 held: checkout didn't
     * match)". Plain text; escape it where it is printed.
     *
     * @param array<string,mixed> $joins
     */
    public static function attention_sentence( array $joins ): string {
        $review = max( 0, (int) ( $joins['review'] ?? 0 ) );
        $paid   = max( 0, (int) ( $joins['paid'] ?? 0 ) );
        $held   = min( $paid, max( 0, (int) ( $joins['paid_held'] ?? 0 ) ) );
        $other  = max( 0, (int) ( $joins['other_attention'] ?? 0 ) );

        $terms = [];
        if ( $review > 0 ) {
            $terms[] = $review . ' $0 ' . ( $review === 1 ? 'join' : 'joins' ) . ' awaiting your decision';
        }
        if ( $paid > 0 ) {
            $terms[] = $paid . ' paid, not applied' . ( $held > 0 ? ' (' . $held . ' held: checkout didn\'t match)' : '' );
        }
        if ( $other > 0 ) {
            $terms[] = $other . ' to review';
        }
        return implode( ' · ', $terms );
    }

    /**
     * Why online joining can't take a payment right now, in a few plain
     * words — '' when it can. The first thing wrong wins: switched off in
     * Settings, then the Stripe mode not connected, then a key known not to
     * be allowed to create Checkout Sessions.
     *
     * $checkoutAccess is the LAST ANSWER on file (true / false), null when
     * none has been established — and null never raises a problem, the same
     * "let the real call report it" the join form applies.
     */
    public static function join_problem_for( bool $enabled, bool $connected, ?bool $checkoutAccess, bool $live ): string {
        $mode = $live ? 'Live' : 'Test';
        if ( ! $enabled ) {
            return 'Online joining is switched off in Settings.';
        }
        if ( ! $connected ) {
            return 'Stripe ' . $mode . ' mode isn\'t connected.';
        }
        if ( $checkoutAccess === false ) {
            return 'The Stripe ' . $mode . ' key can\'t create Checkout Sessions.';
        }
        return '';
    }

    /**
     * A stored Checkout-access answer for one mode: true, false, or null
     * when there is none. $stored is the option MyNJILGA_Stripe_Connection
     * keeps ({ mode: { state: 'yes'|'no'|'unknown', … } }), in whatever
     * shape it has come back — anything unexpected is "no answer".
     *
     * @param mixed $stored
     */
    public static function checkout_verdict( $stored, string $mode ): ?bool {
        if ( ! is_array( $stored ) || ! isset( $stored[ $mode ] ) || ! is_array( $stored[ $mode ] ) ) {
            return null;
        }
        $state = (string) ( $stored[ $mode ]['state'] ?? '' );
        return $state === 'yes' ? true : ( $state === 'no' ? false : null );
    }

    /**
     * How many of these paid joins are HELD — paid, but the payment isn't
     * settled (payment_settled(): it never passed verification, so nothing
     * has been applied and nothing will be until a person looks). Only rows
     * whose status is 'paid' are judged; payment_settled() answers false for
     * every other status and would count them all as held.
     *
     * @param array<int,object> $paidRows Rows with at least status and progress.
     */
    public static function count_held( array $paidRows ): int {
        $held = 0;
        foreach ( $paidRows as $row ) {
            if ( (string) ( $row->status ?? '' ) === MyNJILGA_Join_Orders_Table::STATUS_PAID && ! MyNJILGA_Join_Orders_Table::payment_settled( $row ) ) {
                $held++;
            }
        }
        return $held;
    }

    /** Midnight, Jan 1 of $year — the start of the calendar-year window. */
    public static function year_start( int $year ): string {
        return sprintf( '%04d-01-01 00:00:00', $year );
    }

    // =========================================================================
    // WordPress glue
    // =========================================================================

    /**
     * Why joining is unavailable in this mode, from options alone —
     * Settings' switch, the stored connection, the stored Checkout-access
     * answer. Deliberately NOT MyNJILGA_Stripe_Connection::checkout_access():
     * on an admin page load that may ask Stripe, and a Dashboard render must
     * never wait on the Stripe API. The stored answer is the one the public
     * form reads too, so the two agree.
     */
    public static function join_problem( bool $live ): string {
        $mode = $live ? MyNJILGA_Stripe_Connection::MODE_LIVE : MyNJILGA_Stripe_Connection::MODE_TEST;
        return self::join_problem_for(
            ! empty( MyNJILGA_Dues_Settings::general( 'join_enabled', true ) ),
            MyNJILGA_Stripe_Connection::is_connected( $mode ),
            self::checkout_verdict( get_option( MyNJILGA_Stripe_Connection::OPTION_CHECKOUT_ACCESS, [] ), $mode ),
            $live
        );
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * A status count, never negative, 0 when the status has no rows.
     *
     * @param array<string,mixed> $counts
     */
    private static function count_of( array $counts, string $status ): int {
        return max( 0, (int) ( $counts[ $status ] ?? 0 ) );
    }

    /** @param mixed $value */
    private static function text_or_null( $value ): ?string {
        return is_string( $value ) && $value !== '' ? $value : null;
    }

    /**
     * A 'Y-m-d H:i:s' (or 'Y-m-d') string as a wall-clock timestamp — the
     * digits read as UTC so the result is comparable with current_time(
     * 'timestamp' ) — or null when it is not a real date.
     */
    private static function wall_clock( ?string $datetime ): ?int {
        if ( $datetime === null || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/', trim( $datetime ), $m ) ) {
            return null;
        }
        $hour   = isset( $m[4] ) ? (int) $m[4] : 0;
        $minute = isset( $m[5] ) ? (int) $m[5] : 0;
        $second = isset( $m[6] ) ? (int) $m[6] : 0;
        if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $hour > 23 || $minute > 59 || $second > 59 ) {
            return null;
        }
        $ts = gmmktime( $hour, $minute, $second, (int) $m[2], (int) $m[3], (int) $m[1] );
        return $ts === false ? null : $ts;
    }
}
