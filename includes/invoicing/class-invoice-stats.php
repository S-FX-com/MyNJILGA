<?php
/**
 * Invoice statistics for the Dashboard — one place that defines what
 * "Invoiced", "Collected", "Outstanding", "Past due" and friends MEAN, so
 * the landing page never adds a fourth "Outstanding" to the three the
 * Invoicing page, the Payments ledger and the Aging tab already show.
 *
 * Same shape as MyNJILGA_Ledger_Totals: the rules live in a PURE
 * rollup() that turns aggregated rows into plain numbers (no database, no
 * WordPress, unit-tested in tests/InvoiceStatsTest.php), and a thin layer
 * of SQL/WordPress glue feeds it. The glue never loads an invoice row: it
 * asks the database for one GROUP BY per scope and never reads the
 * roster_snapshot LONGTEXT of a non-excluded row.
 *
 * INPUT to rollup(): "group" tuples, one per (status, invoice_kind), each
 *   [ 'status' => string, 'invoice_kind' => string, 'n' => int,
 *     'total' => SUM(total_amount_cents), 'paid' => SUM(amount_paid_cents),
 *     'due' => SUM(amount_due_cents), 'refunded' => SUM(amount_refunded_cents),
 *     'off_stripe' => SUM(paid_off_stripe_cents),
 *     'flagged' => rows with a non-empty last_error,
 *     'creating' => approved rows already queued for creation, no error,
 *     'ready_n' / 'ready_total' => draft/approved rows awaiting review
 *                   (no error, not queued) and their estimated total,
 *     'unbilled_n' / 'unbilled_total' => downgraded rows that never got a
 *                   Stripe invoice (a swept draft) and their estimated total,
 *     'past_due_cents' / 'past_due_n' => open rows due on or before today,
 *     'oldest_processing' => MIN(processing_at) of processing rows, or null ]
 * plus, on the 'excluded' tuples only and only when the caller has asked
 * the database which reason each excluded row carries,
 *     'no_members_n' / 'no_members_total' / 'zero_total_n' / 'zero_total_total'.
 * A missing key reads as 0.
 *
 * The rules, and the page each one agrees with:
 *
 *   1. ANNUAL figures (one dues year, one Stripe mode) exclude online-join
 *      rows (invoice_kind 'join'). A join is one person's Checkout payment
 *      written straight to 'paid': counted here it would read as Collected
 *      before a single firm invoice went out, which is exactly why
 *      Invoicing leaves it out. Joins are reported as their own line.
 *      Assessment-only rows stay in: they are real Stripe invoices with
 *      their own paid/due, on Invoicing and Payments alike.
 *   2. Invoiced = total_amount_cents of created/sent/processing/paid/
 *      downgraded/uncollectible rows. Voided rows were cancelled, never
 *      owed, so they are not invoiced; draft/approved/excluded rows are
 *      not in Stripe yet. A downgraded row that never got a Stripe invoice
 *      (the year-end sweep also downgrades unsent drafts) was never
 *      invoiced either, so it is taken back out.
 *   3. Collected = amount_paid_cents of every ledger row, whatever its
 *      status — exactly Payments' "Collected" card (Ledger_Totals rule 2).
 *      A partly-paid open invoice is in BOTH Collected and Outstanding, and
 *      so is money that came in on an invoice later voided. It is GROSS of
 *      refunds: the charge.refunded handler writes amount_refunded_cents
 *      and leaves amount_paid_cents (and the status) alone, and the
 *      reconciler never re-reads a paid row, so Refunded is reported beside
 *      Collected, not netted out of it. Payments' Collected for one year =
 *      annual Collected + joins.cents.
 *   4. Outstanding = amount_due_cents of every ledger row except voided/
 *      uncollectible (the ones written off) — Payments' "Outstanding"
 *      card, processing and downgraded balances included. It is NEVER
 *      total - paid: an overpaid row would go negative. Payments' Aging
 *      total and By-firm total are this minus the downgraded balance, so
 *      that balance is reported on its own to make the numbers reconcile.
 *   5. Past due = open (created/sent/processing) rows with a due_date on
 *      or before today, read as amount_due. The due date is INCLUSIVE — an
 *      invoice is past due on its due date, not the day after — because
 *      Payments' aging puts age 0 in its first bucket. A row with no due
 *      date is never past due (Aging files it under "Not Yet Due").
 *   6. ACH in flight = processing rows. A SUBSET of Outstanding, never
 *      added to it.
 *   7. Written off = amount_due of voided/uncollectible rows, never the
 *      total: whatever was paid before the write-off is already in
 *      Collected (Ledger_Totals rule 5).
 *   8. Paid % = Collected / Invoiced in dollars, rounded DOWN and clamped
 *      to 0..100, so 100 means everything invoiced has come in and 99.6%
 *      never reads as "done".
 *   9. Pipeline = draft/approved rows awaiting review with NO error that
 *      are not already queued for creation (Invoicing's "Ready"), with the
 *      estimate they will bill; drafts re-price on every refresh, so it is
 *      an estimate. Creating = approved and queued. Error = draft/approved
 *      carrying a last_error. Blocked = excluded for having no firm Owner:
 *      the only exclusion staff can act on (no_members and zero_total are
 *      informational). An excluded row's total is a would-be amount and
 *      NEVER enters Invoiced, the batch or the pipeline.
 *  10. Flags = rows carrying a last_error, split — never one number —
 *      into OPEN (on a draft/approved/created/sent/processing row: still
 *      actionable) and REVIEW (on a paid/downgraded/voided/uncollectible
 *      row). Nothing clears a flag on a terminal row, so a refund or
 *      dispute flag on a paid invoice stays for good; lumped together the
 *      count would never return to zero. open + review is exactly what
 *      Setup → Needs attention lists (get_flagged()).
 *
 * Every read is scoped to ONE Stripe mode. test and live rows sit side by
 * side in the same table, so an unscoped SUM would double-count a firm
 * that exists in both; nothing here ever passes "both modes".
 */
class MyNJILGA_Invoice_Stats {

    /**
     * Rows that exist in Stripe — Payments' scope, and the scope of every
     * all-years figure. A drift test pins it to
     * MyNJILGA_Page_Payments::RELEVANT_STATUSES.
     */
    const LEDGER_STATUSES = [
        MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,
        MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
        MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
        MyNJILGA_Dues_Invoice_Table::STATUS_PAID,
        MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
        MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE,
        MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED,
    ];

    /** Ledger statuses minus voided (cancelled, never owed) — what counts as "invoiced". */
    const INVOICED_STATUSES = [
        MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,
        MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
        MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
        MyNJILGA_Dues_Invoice_Table::STATUS_PAID,
        MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED,
        MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE,
    ];

    /** Ledger statuses that are not terminal — the rows Aging ages, and the only ones that can be past due. */
    const OPEN_STATUSES = [
        MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,
        MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
        MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
    ];

    /** Not in Stripe yet: total_amount_cents is an estimate and the money columns are all 0. */
    const ESTIMATE_STATUSES = [
        MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT,
        MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED,
    ];

    /** A last_error here is still actionable. */
    const FLAG_OPEN_STATUSES = [
        MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT,
        MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED,
        MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,
        MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
        MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
    ];

    /** A last_error here is a permanent post-payment / post-close note: nothing clears it. */
    const FLAG_REVIEW_STATUSES = [
        MyNJILGA_Dues_Invoice_Table::STATUS_PAID,
        MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED,
        MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
        MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE,
    ];

    /** The dues-year range Invoicing's ?dues_year= accepts. */
    const MIN_YEAR = 2000;
    const MAX_YEAR = 2100;

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Everything the Dashboard's invoice cards show, for the ACTIVE Stripe
     * mode. The year is the one dashboard_year() picks; ?dues_year= is
     * validated by the caller AND again here.
     *
     * The annual figures, joins and pipeline are read for that one year;
     * the receivables strip and the flags are read across every year, so a
     * prior-year balance is never hidden behind the year selector (they
     * equal Payments' cards and Setup → Needs attention). annual
     * outstanding_cents and receivables outstanding_cents are ONE
     * definition at two scopes — Payments' Outstanding card filtered to the
     * year, and the card itself — never two different numbers.
     *
     * @return array{
     *   live:bool, mode:string, connected:bool, year:int, year_has_rows:bool,
     *   annual:array<string,int>, joins:array<string,int>,
     *   receivables:array<string,int|null>, pipeline:array<string,int>, flags:array<string,int>
     * }
     */
    public static function snapshot( ?int $requestedYear = null ): array {
        $mode = MyNJILGA_Stripe_Connection::active_mode();
        $live = ( $mode === MyNJILGA_Stripe_Connection::MODE_LIVE );

        return self::build(
            $live,
            MyNJILGA_Stripe_Connection::is_connected( $mode ),
            // Site-local today: Payments' aging compares due dates against the
            // site's clock, not UTC, and a due date is a calendar date.
            (string) current_time( 'Y-m-d' ),
            $requestedYear
        );
    }

    /**
     * snapshot() with the WordPress-derived inputs (mode, connection, the
     * site's "today") handed in, so the whole read path — year choice, every
     * query, the merge into sections — runs against a stubbed $wpdb in the
     * tests. Calls no WordPress function.
     *
     * @return array<string,mixed> See snapshot().
     */
    public static function build( bool $live, bool $connected, string $today, ?int $requestedYear = null ): array {
        $today = self::normalize_date( $today );
        $year  = self::dashboard_year( $live, $requestedYear );

        $yearGroups = self::load_groups( $year, $live, $today );
        $allGroups  = self::load_groups( null, $live, $today );
        $forYear    = self::rollup( $yearGroups, $today );
        $allYears   = self::rollup( $allGroups, $today );

        return [
            'live'          => $live,
            'mode'          => $live ? 'live' : 'test',
            'connected'     => $connected,
            'year'          => $year,
            'year_has_rows' => self::has_annual_rows( $yearGroups ),
            'annual'        => $forYear['annual'],
            'joins'         => $forYear['joins'],
            'receivables'   => $allYears['receivables'],
            'pipeline'      => $forYear['pipeline'],
            'flags'         => $allYears['flags'],
        ];
    }

    /**
     * The pure half: group tuples in, every section out (see the class
     * docblock for the tuple and the rules). Which scope the tuples cover is
     * the caller's call — snapshot() feeds one year's tuples for the annual,
     * joins and pipeline sections and every year's for receivables and flags
     * — so each section is a function of the tuples given, whatever they
     * span. No database, no WordPress.
     *
     * @param array<int,array<string,mixed>> $groups
     * @param string                         $today  Site-local Y-m-d, used only to age the oldest ACH.
     * @return array{annual:array<string,int>,joins:array<string,int>,receivables:array<string,int|null>,pipeline:array<string,int>,flags:array<string,int>}
     */
    public static function rollup( array $groups, string $today ): array {
        $today    = self::normalize_date( $today );
        $joinKind = MyNJILGA_Dues_Snapshot::KIND_JOIN;

        $annual = [
            'invoices'          => 0,
            'paid_invoices'     => 0,
            'invoiced_cents'    => 0,
            'collected_cents'   => 0,
            'outstanding_cents' => 0,
            'refunded_cents'    => 0,
            'paid_pct'          => 0,
            'batch_cents'       => 0,
            'off_stripe_cents'  => 0,
        ];
        $joins       = [ 'count' => 0, 'cents' => 0 ];
        $receivables = [
            'outstanding_cents'      => 0,
            'open_count'             => 0,
            'past_due_cents'         => 0,
            'past_due_count'         => 0,
            'in_flight_cents'        => 0,
            'in_flight_count'        => 0,
            'oldest_processing_days' => null,
            'downgraded_cents'       => 0,
            'written_off_cents'      => 0,
            'collected_cents'        => 0,
        ];
        $pipeline = [
            'ready_count'            => 0,
            'ready_cents'            => 0,
            'creating'               => 0,
            'error'                  => 0,
            'blocked_no_owner_count' => 0,
            'blocked_no_owner_cents' => 0,
        ];
        $flags = [ 'open' => 0, 'review' => 0 ];

        $estimates = 0;    // draft + approved totals, annual kinds only
        $oldest    = null; // earliest processing date across every processing row

        foreach ( $groups as $group ) {
            $g          = self::tuple( (array) $group );
            $status     = $g['status'];
            $isJoin     = ( $g['invoice_kind'] === $joinKind );
            $inLedger   = in_array( $status, self::LEDGER_STATUSES, true );
            $isWriteOff = in_array( $status, MyNJILGA_Ledger_Totals::WRITEOFF_STATUSES, true );
            $isOpen     = in_array( $status, self::OPEN_STATUSES, true );

            // Every kind, joins and assessments included — Payments' cards
            // are built over all of them, so these are the figures that agree
            // with it.
            if ( $inLedger ) {
                $receivables['collected_cents'] += $g['paid'];
                if ( $isWriteOff ) {
                    $receivables['written_off_cents'] += $g['due']; // the balance lost, not the invoice total
                } else {
                    $receivables['outstanding_cents'] += $g['due'];
                }
                if ( $isOpen ) {
                    $receivables['open_count']     += $g['n'];
                    $receivables['past_due_cents'] += $g['past_due_cents'];
                    $receivables['past_due_count'] += $g['past_due_n'];
                }
                if ( $status === MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING ) {
                    $receivables['in_flight_cents'] += $g['due'];
                    $receivables['in_flight_count'] += $g['n'];
                    if ( $g['oldest_processing'] !== null && ( $oldest === null || $g['oldest_processing'] < $oldest ) ) {
                        $oldest = $g['oldest_processing'];
                    }
                }
                if ( $status === MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED ) {
                    $receivables['downgraded_cents'] += $g['due'];
                }
            }

            // Flags follow get_flagged(): any kind, any status but excluded.
            if ( in_array( $status, self::FLAG_OPEN_STATUSES, true ) ) {
                $flags['open'] += $g['flagged'];
            } elseif ( in_array( $status, self::FLAG_REVIEW_STATUSES, true ) ) {
                $flags['review'] += $g['flagged'];
            }

            if ( $isJoin ) {
                // Only ever written as paid (money confirmed first); its own line.
                if ( $status === MyNJILGA_Dues_Invoice_Table::STATUS_PAID ) {
                    $joins['count'] += $g['n'];
                    $joins['cents'] += $g['paid'];
                }
                continue;
            }

            if ( $inLedger ) {
                $annual['collected_cents']  += $g['paid'];
                $annual['refunded_cents']   += $g['refunded'];
                $annual['off_stripe_cents'] += $g['off_stripe'];
                if ( ! $isWriteOff ) {
                    $annual['outstanding_cents'] += $g['due'];
                }
            }
            if ( in_array( $status, self::INVOICED_STATUSES, true ) ) {
                $annual['invoices']       += $g['n'];
                $annual['invoiced_cents'] += $g['total'];
                if ( $status === MyNJILGA_Dues_Invoice_Table::STATUS_PAID ) {
                    $annual['paid_invoices'] += $g['n'];
                }
                if ( $status === MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED ) {
                    // Swept before it was ever sent — a draft, not an invoice.
                    $annual['invoices']       -= $g['unbilled_n'];
                    $annual['invoiced_cents'] -= $g['unbilled_total'];
                }
            }

            if ( in_array( $status, self::ESTIMATE_STATUSES, true ) ) {
                $estimates               += $g['total'];
                $pipeline['ready_count'] += $g['ready_n'];
                $pipeline['ready_cents'] += $g['ready_total'];
                $pipeline['creating']    += $g['creating'];
                $pipeline['error']       += $g['flagged'];
            }

            if ( $status === MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED ) {
                // A row with no recorded reason is a no-owner exclusion — the
                // same default Invoicing's classify() applies.
                $pipeline['blocked_no_owner_count'] += max( 0, $g['n'] - $g['no_members_n'] - $g['zero_total_n'] );
                $pipeline['blocked_no_owner_cents'] += max( 0, $g['total'] - $g['no_members_total'] - $g['zero_total_total'] );
            }
        }

        $annual['batch_cents'] = $annual['invoiced_cents'] + $estimates;
        $annual['paid_pct']    = self::paid_pct( $annual['collected_cents'], $annual['invoiced_cents'] );

        if ( $oldest !== null ) {
            $receivables['oldest_processing_days'] = max( 0, self::days_between( $oldest, $today ) );
        }

        return [
            'annual'      => $annual,
            'joins'       => $joins,
            'receivables' => $receivables,
            'pipeline'    => $pipeline,
            'flags'       => $flags,
        ];
    }

    /**
     * The year the Dashboard reads. An explicit ?dues_year= wins when it is
     * inside Invoicing's 2000..2100 window; otherwise next year (Invoicing's
     * own default — batches are generated ahead) IF it has at least one
     * annual row in this mode, else the current year, so the page is not a
     * wall of zeros after New Year, or before next year's preview exists.
     *
     * "Has a row" ignores online joins, and counts draft and excluded rows:
     * a year holding only joins is exactly what Invoicing calls empty
     * ("No invoices generated yet"), and a year that is only previewed still
     * has a batch to show. years() cannot answer this — it spans both modes.
     */
    public static function dashboard_year( bool $live, ?int $requested ): int {
        $valid = self::valid_year( $requested );
        if ( $valid !== null ) {
            return $valid;
        }
        $default = MyNJILGA_Invoicing::default_dues_year();
        return self::year_has_annual_rows( $default, $live ) ? $default : MyNJILGA_Invoicing::current_dues_year();
    }

    /** $year when it is inside the accepted 2000..2100 window, otherwise null. */
    public static function valid_year( ?int $year ): ?int {
        return ( $year !== null && $year >= self::MIN_YEAR && $year <= self::MAX_YEAR ) ? $year : null;
    }

    /**
     * Paid % — collected over invoiced dollars, rounded DOWN, clamped to
     * 0..100. Whole-number integer division, deliberately: rounding would
     * print 100 with a balance still open, and a float floor() turns 29/100
     * into 28 (0.29 * 100 is 28.999…).
     */
    public static function paid_pct( int $collectedCents, int $invoicedCents ): int {
        if ( $invoicedCents <= 0 || $collectedCents <= 0 ) {
            return 0;
        }
        return (int) min( 100, intdiv( $collectedCents * 100, $invoicedCents ) );
    }

    /**
     * Whether the tuples hold at least one annual (non-join) invoice row of
     * any status — Invoicing treats a year with only online joins as empty.
     *
     * @param array<int,array<string,mixed>> $groups
     */
    public static function has_annual_rows( array $groups ): bool {
        foreach ( $groups as $group ) {
            $g = self::tuple( (array) $group );
            if ( $g['invoice_kind'] !== MyNJILGA_Dues_Snapshot::KIND_JOIN && $g['n'] > 0 ) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // SQL / database glue — every statement is scoped to ONE mode
    // =========================================================================

    /**
     * Does this year hold at least one annual (non-join) row in this mode?
     */
    public static function year_has_annual_rows( int $year, bool $live ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( self::annual_probe_sql( $year, $live ) ) === 1; // phpcs:ignore
    }

    /** The probe behind year_has_annual_rows(): existence only, no rows read. */
    public static function annual_probe_sql( int $year, bool $live ): string {
        global $wpdb;
        $table = MyNJILGA_Dues_Invoice_Table::table_name();
        return (string) $wpdb->prepare( // phpcs:ignore
            "SELECT 1 FROM $table WHERE dues_year = %d AND livemode = %d AND invoice_kind <> %s LIMIT 1",
            $year,
            $live ? 1 : 0,
            MyNJILGA_Dues_Snapshot::KIND_JOIN
        );
    }

    /**
     * Runs the GROUP BY for one scope (one year, or every year when $year
     * is null) and normalises the result to the tuples rollup() takes,
     * every number cast to int — $wpdb hands SUMs back as strings.
     *
     * For a year with excluded rows, also asks which reason each carries
     * and folds that onto the excluded tuples. That is a second query, run
     * only when there is an excluded row to explain and reading only those
     * rows' snapshots; the all-years scope never needs it (the blocked
     * figure is year-scoped).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function load_groups( ?int $year, bool $live, string $today ): array {
        global $wpdb;

        $groups = [];
        foreach ( (array) $wpdb->get_results( self::groups_sql( $year, $live, $today ) ) as $row ) { // phpcs:ignore
            $groups[] = self::tuple( (array) $row );
        }

        if ( $year === null || ! self::has_excluded( $groups ) ) {
            return $groups;
        }

        $reasons = [];
        foreach ( (array) $wpdb->get_results( self::excluded_reasons_sql( $year, $live ) ) as $row ) { // phpcs:ignore
            $r = (array) $row;
            $reasons[ (string) ( $r['invoice_kind'] ?? '' ) ] = $r;
        }
        foreach ( $groups as $i => $g ) {
            if ( $g['status'] === MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED && isset( $reasons[ $g['invoice_kind'] ] ) ) {
                $groups[ $i ] = self::tuple( array_merge( $g, $reasons[ $g['invoice_kind'] ] ) );
            }
        }
        return $groups;
    }

    /**
     * Query A/B: one status x invoice_kind rollup for the scope. Never
     * selects a snapshot, and only ever adds and counts (no subtraction, so
     * strict-mode MySQL cannot raise an UNSIGNED out-of-range error).
     * Covered by the year_mode_status key for a year scope; the all-years
     * scope scans the table, which is one row per invoice.
     *
     * The CASE expressions restate the rules in the class docblock:
     * "no error" is NULL or empty, "ready" is Invoicing's Ready bucket, and
     * "past due" is open AND has a due date AND due_date <= today —
     * inclusive of the due date.
     */
    public static function groups_sql( ?int $year, bool $live, string $today ): string {
        global $wpdb;
        $table    = MyNJILGA_Dues_Invoice_Table::table_name();
        $today    = self::normalize_date( $today );
        $noError  = "(last_error IS NULL OR last_error = '')";
        $hasErr   = "(last_error IS NOT NULL AND last_error <> '')";
        $queued   = "status = '" . MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED . "' AND queued_at IS NOT NULL";
        $ready    = 'status IN (' . self::quote_list( self::ESTIMATE_STATUSES ) . ") AND $noError AND NOT ( $queued )";
        $pastDue  = 'status IN (' . self::quote_list( self::OPEN_STATUSES ) . ') AND due_date IS NOT NULL AND due_date <= %s';
        $unbilled = "status = '" . MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED . "' AND (gateway_invoice_id IS NULL OR gateway_invoice_id = '')";

        $select = implode( ' ', [
            'SELECT status, invoice_kind,',
            'COUNT(*) AS n,',
            'SUM(total_amount_cents) AS total,',
            'SUM(amount_paid_cents) AS paid,',
            'SUM(amount_due_cents) AS due,',
            'SUM(amount_refunded_cents) AS refunded,',
            'SUM(paid_off_stripe_cents) AS off_stripe,',
            "SUM(CASE WHEN $hasErr THEN 1 ELSE 0 END) AS flagged,",
            "SUM(CASE WHEN $queued AND $noError THEN 1 ELSE 0 END) AS creating,",
            "SUM(CASE WHEN $ready THEN 1 ELSE 0 END) AS ready_n,",
            "SUM(CASE WHEN $ready THEN total_amount_cents ELSE 0 END) AS ready_total,",
            "SUM(CASE WHEN $unbilled THEN 1 ELSE 0 END) AS unbilled_n,",
            "SUM(CASE WHEN $unbilled THEN total_amount_cents ELSE 0 END) AS unbilled_total,",
            "SUM(CASE WHEN $pastDue THEN amount_due_cents ELSE 0 END) AS past_due_cents,",
            "SUM(CASE WHEN $pastDue THEN 1 ELSE 0 END) AS past_due_n,",
            "MIN(CASE WHEN status = '" . MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING . "' THEN processing_at END) AS oldest_processing",
        ] );

        // One %s per past-due CASE above (count and cents), then the scope.
        $params = [ $today, $today ];
        if ( $year !== null ) {
            $where    = 'dues_year = %d AND livemode = %d';
            $params[] = $year;
        } else {
            $where = 'livemode = %d';
        }
        $params[] = $live ? 1 : 0;

        return (string) $wpdb->prepare( // phpcs:ignore
            "$select FROM $table WHERE $where GROUP BY status, invoice_kind",
            $params
        );
    }

    /**
     * Query C: which exclusion reason each excluded row carries. The reason
     * is a key inside the roster_snapshot JSON, so it is a LIKE — the same
     * trick MyNJILGA_Dues_Invoice_Table::rows_listing_member() uses:
     * wp_json_encode writes no spaces, so the literal fragment matches. Only
     * status = excluded rows are touched, so the LONGTEXT scan is tiny.
     *
     * Only no_members and zero_total are looked for. The remainder — the
     * rows naming no_owner, and any row with no reason at all — is the
     * no-owner count by subtraction, exactly as Invoicing classifies it.
     */
    public static function excluded_reasons_sql( int $year, bool $live ): string {
        global $wpdb;
        $table = MyNJILGA_Dues_Invoice_Table::table_name();
        $like  = static function ( string $reason ) use ( $wpdb ): string {
            return '%' . $wpdb->esc_like( '"exclusion_reason":"' . $reason . '"' ) . '%';
        };
        $noMembers = $like( MyNJILGA_Dues_Preview::EXCLUDED_NO_MEMBERS );
        $zeroTotal = $like( MyNJILGA_Dues_Preview::EXCLUDED_ZERO_TOTAL );

        return (string) $wpdb->prepare( // phpcs:ignore
            "SELECT invoice_kind,"
            . " SUM(CASE WHEN roster_snapshot LIKE %s THEN 1 ELSE 0 END) AS no_members_n,"
            . " SUM(CASE WHEN roster_snapshot LIKE %s THEN total_amount_cents ELSE 0 END) AS no_members_total,"
            . " SUM(CASE WHEN roster_snapshot LIKE %s THEN 1 ELSE 0 END) AS zero_total_n,"
            . " SUM(CASE WHEN roster_snapshot LIKE %s THEN total_amount_cents ELSE 0 END) AS zero_total_total"
            . " FROM $table WHERE dues_year = %d AND livemode = %d AND status = %s GROUP BY invoice_kind",
            [ $noMembers, $noMembers, $zeroTotal, $zeroTotal, $year, $live ? 1 : 0, MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED ]
        );
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * One group in the shape rollup() reads: every number an int (a missing
     * key is 0), and the processing date reduced to a plain Y-m-d or null.
     * Accepts the $wpdb row cast to array.
     *
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    private static function tuple( array $g ): array {
        $out = [
            'status'            => (string) ( $g['status'] ?? '' ),
            'invoice_kind'      => (string) ( $g['invoice_kind'] ?? '' ),
            'oldest_processing' => self::clean_date( $g['oldest_processing'] ?? null ),
        ];
        foreach ( [
            'n', 'total', 'paid', 'due', 'refunded', 'off_stripe', 'flagged', 'creating',
            'ready_n', 'ready_total', 'unbilled_n', 'unbilled_total', 'past_due_cents', 'past_due_n',
            'no_members_n', 'no_members_total', 'zero_total_n', 'zero_total_total',
        ] as $key ) {
            $out[ $key ] = (int) ( $g[ $key ] ?? 0 );
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $groups */
    private static function has_excluded( array $groups ): bool {
        foreach ( $groups as $g ) {
            if ( $g['status'] === MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED && $g['n'] > 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 'a', 'b' — for the fixed status constants only, never for input.
     *
     * @param array<int,string> $values
     */
    private static function quote_list( array $values ): string {
        return "'" . implode( "', '", $values ) . "'";
    }

    /**
     * The Y-m-d at the start of a date or datetime string, or null when
     * there is no real date there (empty, NULL, MySQL's zero date).
     *
     * @param mixed $value
     */
    private static function clean_date( $value ): ?string {
        if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
            return null;
        }
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? substr( $value, 0, 10 ) : null;
    }

    /** A usable Y-m-d: the given one, or the UTC date if it isn't one. */
    private static function normalize_date( string $date ): string {
        return self::clean_date( $date ) ?? gmdate( 'Y-m-d' );
    }

    /** Whole calendar days from $from to $to (both Y-m-d); negative when $to is earlier. */
    private static function days_between( string $from, string $to ): int {
        return intdiv( (int) strtotime( $to . ' 00:00:00 UTC' ) - (int) strtotime( $from . ' 00:00:00 UTC' ), 86400 );
    }
}
