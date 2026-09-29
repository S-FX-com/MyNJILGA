<?php
/**
 * Unit tests for MyNJILGA_Invoice_Stats — the numbers behind the
 * Dashboard's invoice cards (Invoiced, Collected, Outstanding, Past due,
 * ACH in flight, Written off, Paid %, pipeline, blocked, flags).
 *
 * Four things are pinned here:
 *
 *   1. The pure rollup(): fixtures for every row shape that has ever made a
 *      dollar figure wrong (online joins, assessment-only invoices, excluded
 *      rows with a non-zero total, drafts, ACH in flight, partial payments,
 *      voided-after-partial, overpaid, refunded, downgraded-never-invoiced,
 *      uncollectible, the past-due boundary, the empty table).
 *   2. PARITY with the pages that already show the same figure: the same
 *      invoice rows are fed to MyNJILGA_Ledger_Totals::stats() — as
 *      MyNJILGA_Page_Payments::to_line() builds its lines — and to the
 *      rollup, and every all-years figure must agree. The Dashboard must not
 *      grow a fourth "Outstanding".
 *   3. MODE ISOLATION at the SQL level, in the style of
 *      tests/ModeIsolationTest.php: every statement the class emits carries
 *      the predicate for the mode asked for and never the other one.
 *   4. dashboard_year()'s fallback.
 *
 * The database is a $wpdb double that answers the class's GROUP BY from an
 * in-memory list of invoice rows. aggregate() is that double's PHP model of
 * the SQL; it is the one thing here that is not checked by running the SQL
 * itself, so the SQL text is pinned separately (the *Sql tests) and any
 * change to it should be re-checked against a real engine.
 *
 * Money is in cents throughout, as it is everywhere in this plugin.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-dues-preview.php';
require_once dirname( __DIR__ ) . '/includes/class-page-payments.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-stats.php';

class InvoiceStatsTest extends NJILGA_TestCase {

    private const TODAY = '2026-09-29';
    private const YEAR  = 2027;

    // -------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------

    /**
     * One GROUP BY tuple. Defaults are an empty group; each test overrides
     * only what the rule under test reads.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function group( array $overrides = [] ): array {
        return array_merge( [
            'status'            => MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
            'invoice_kind'      => 'combined',
            'n'                 => 0,
            'total'             => 0,
            'paid'              => 0,
            'due'               => 0,
            'refunded'          => 0,
            'off_stripe'        => 0,
            'flagged'           => 0,
            'creating'          => 0,
            'ready_n'           => 0,
            'ready_total'       => 0,
            'unbilled_n'        => 0,
            'unbilled_total'    => 0,
            'past_due_cents'    => 0,
            'past_due_n'        => 0,
            'oldest_processing' => null,
        ], $overrides );
    }

    /** @param array<int,array<string,mixed>> $groups */
    private function rollup( array $groups ): array {
        return MyNJILGA_Invoice_Stats::rollup( $groups, self::TODAY );
    }

    /**
     * The all-zero form of one section — what an empty table reads, and
     * the base every expectation below overrides. array_merge keeps the
     * key order, so assertSame on the whole section also pins its shape.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function zero( string $section, array $overrides = [] ): array {
        $zeros = [
            'annual'      => [
                'invoices' => 0, 'paid_invoices' => 0, 'invoiced_cents' => 0, 'collected_cents' => 0,
                'outstanding_cents' => 0, 'refunded_cents' => 0, 'paid_pct' => 0, 'batch_cents' => 0,
                'off_stripe_cents' => 0,
            ],
            'joins'       => [ 'count' => 0, 'cents' => 0 ],
            'receivables' => [
                'outstanding_cents' => 0, 'open_count' => 0, 'past_due_cents' => 0, 'past_due_count' => 0,
                'in_flight_cents' => 0, 'in_flight_count' => 0, 'oldest_processing_days' => null,
                'downgraded_cents' => 0, 'written_off_cents' => 0, 'collected_cents' => 0,
            ],
            'pipeline'    => [
                'ready_count' => 0, 'ready_cents' => 0, 'creating' => 0, 'error' => 0,
                'blocked_no_owner_count' => 0, 'blocked_no_owner_cents' => 0,
            ],
            'flags'       => [ 'open' => 0, 'review' => 0 ],
        ];
        return array_merge( $zeros[ $section ], $overrides );
    }

    /**
     * One invoice ROW (not a group) — what the fake database aggregates and
     * what a Payments line is built from. `exclusion_reason` stands in for
     * the "exclusion_reason" key inside roster_snapshot, which the real
     * query finds with a LIKE.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function row( array $overrides = [] ): array {
        return array_merge( [
            'dues_year'             => self::YEAR,
            'livemode'              => 1,
            'invoice_kind'          => 'combined',
            'status'                => MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
            'gateway_invoice_id'    => 'in_1',
            'total_amount_cents'    => 0,
            'amount_paid_cents'     => 0,
            'amount_due_cents'      => 0,
            'amount_refunded_cents' => 0,
            'paid_off_stripe_cents' => 0,
            'last_error'            => '',
            'queued_at'             => null,
            'due_date'              => null,
            'processing_at'         => null,
            'exclusion_reason'      => '',
        ], $overrides );
    }

    /**
     * A realistic table, both modes and several years side by side. Public
     * (and not a test) so a real-SQL check can load the very same rows.
     *
     * Live, dues year 2027 unless noted; today is 2026-09-29.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fixtureRows(): array {
        $paid = MyNJILGA_Dues_Invoice_Table::STATUS_PAID;
        $sent = MyNJILGA_Dues_Invoice_Table::STATUS_SENT;
        $proc = MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING;

        return [
            // ---- 2025 --------------------------------------------------
            $this->row( [ 'dues_year' => 2025, 'status' => $paid, 'total_amount_cents' => 20000, 'amount_paid_cents' => 20000, 'due_date' => '2025-01-31' ] ),
            // ---- 2026 --------------------------------------------------
            $this->row( [ 'dues_year' => 2026, 'status' => $paid, 'total_amount_cents' => 50000, 'amount_paid_cents' => 50000, 'due_date' => '2026-01-31' ] ),
            // Paid, then $100 refunded: still paid, flagged for review, paid stays gross.
            $this->row( [ 'dues_year' => 2026, 'status' => $paid, 'total_amount_cents' => 30000, 'amount_paid_cents' => 30000, 'amount_refunded_cents' => 10000, 'due_date' => '2026-01-31', 'last_error' => 'Refunded $100.00 — review membership status.' ] ),
            // Sent and 45 days late.
            $this->row( [ 'dues_year' => 2026, 'status' => $sent, 'total_amount_cents' => 40000, 'amount_due_cents' => 40000, 'due_date' => '2026-08-15' ] ),
            // Written off after $50 of $250 came in.
            $this->row( [ 'dues_year' => 2026, 'invoice_kind' => 'dues', 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE, 'total_amount_cents' => 25000, 'amount_paid_cents' => 5000, 'amount_due_cents' => 20000, 'due_date' => '2026-03-01' ] ),
            // Voided after $100 of $600 came in; the void note is a review flag.
            $this->row( [ 'dues_year' => 2026, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED, 'total_amount_cents' => 60000, 'amount_paid_cents' => 10000, 'amount_due_cents' => 50000, 'due_date' => '2026-02-01', 'last_error' => 'Voided in Stripe.' ] ),
            // Downgraded with a real, unpaid Stripe invoice behind it.
            $this->row( [ 'dues_year' => 2026, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED, 'total_amount_cents' => 15000, 'amount_due_cents' => 15000, 'due_date' => '2026-01-31' ] ),
            // Downgraded straight from an unsent draft: no Stripe invoice ever existed.
            $this->row( [ 'dues_year' => 2026, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED, 'total_amount_cents' => 18000, 'gateway_invoice_id' => null ] ),
            // ---- 2027 --------------------------------------------------
            // Created, not yet due, with an open send failure.
            $this->row( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_CREATED, 'total_amount_cents' => 70000, 'amount_due_cents' => 70000, 'due_date' => '2026-12-31', 'last_error' => 'Send failed.' ] ),
            // Part-paid, due TODAY — past due on its due date.
            $this->row( [ 'status' => $sent, 'total_amount_cents' => 80000, 'amount_paid_cents' => 30000, 'amount_due_cents' => 50000, 'due_date' => self::TODAY ] ),
            // ACH clearing since the 20th, due yesterday.
            $this->row( [ 'status' => $proc, 'total_amount_cents' => 45000, 'amount_due_cents' => 45000, 'due_date' => '2026-09-28', 'processing_at' => '2026-09-20 10:00:00' ] ),
            // ACH clearing since the 25th, no due date on file.
            $this->row( [ 'status' => $proc, 'total_amount_cents' => 20000, 'amount_due_cents' => 20000, 'processing_at' => '2026-09-25 08:30:00' ] ),
            // Assessment-only invoices: real Stripe invoices, one open (due tomorrow), one paid outside Stripe.
            $this->row( [ 'invoice_kind' => 'assessment', 'status' => $sent, 'total_amount_cents' => 20000, 'amount_due_cents' => 20000, 'due_date' => '2026-09-30' ] ),
            $this->row( [ 'invoice_kind' => 'assessment', 'status' => $paid, 'total_amount_cents' => 20000, 'amount_paid_cents' => 20000, 'paid_off_stripe_cents' => 20000, 'due_date' => '2026-09-01' ] ),
            // Online joins: paid at Checkout, no annual invoice behind them; the second carries a join-apply flag.
            $this->row( [ 'invoice_kind' => 'join', 'status' => $paid, 'total_amount_cents' => 12500, 'amount_paid_cents' => 12500 ] ),
            $this->row( [ 'invoice_kind' => 'join', 'status' => $paid, 'total_amount_cents' => 7500, 'amount_paid_cents' => 7500, 'last_error' => 'Join could not be applied.' ] ),
            // Overpaid: $150 came in on a $125 invoice.
            $this->row( [ 'status' => $paid, 'total_amount_cents' => 12500, 'amount_paid_cents' => 15000, 'due_date' => '2026-09-01' ] ),
            // No due date at all.
            $this->row( [ 'status' => $sent, 'total_amount_cents' => 10000, 'amount_due_cents' => 10000 ] ),
            // Pipeline: two ready drafts, one approved-and-queued, one approved with an error.
            $this->row( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, 'total_amount_cents' => 33000, 'gateway_invoice_id' => null ] ),
            $this->row( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, 'total_amount_cents' => 12000, 'gateway_invoice_id' => null ] ),
            $this->row( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED, 'total_amount_cents' => 44000, 'gateway_invoice_id' => null, 'queued_at' => '2026-09-28 09:00:00' ] ),
            $this->row( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED, 'total_amount_cents' => 21000, 'gateway_invoice_id' => null, 'last_error' => 'Create failed.' ] ),
            // Excluded: two firms with no Owner (priced, non-zero), one with no members, one $0.
            $this->row( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED, 'total_amount_cents' => 55000, 'gateway_invoice_id' => null, 'exclusion_reason' => MyNJILGA_Dues_Preview::EXCLUDED_NO_OWNER ] ),
            $this->row( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED, 'total_amount_cents' => 5000, 'gateway_invoice_id' => null, 'exclusion_reason' => MyNJILGA_Dues_Preview::EXCLUDED_NO_OWNER, 'last_error' => 'not a flag: excluded rows are never flagged' ] ),
            $this->row( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED, 'gateway_invoice_id' => null, 'exclusion_reason' => MyNJILGA_Dues_Preview::EXCLUDED_NO_MEMBERS ] ),
            $this->row( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED, 'gateway_invoice_id' => null, 'exclusion_reason' => MyNJILGA_Dues_Preview::EXCLUDED_ZERO_TOTAL ] ),
            // ---- 2028 --------------------------------------------------
            $this->row( [ 'dues_year' => 2028, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, 'total_amount_cents' => 100, 'gateway_invoice_id' => null ] ),
            // ---- TEST-mode rows: huge, so any leak into a live figure is unmissable ----
            $this->row( [ 'livemode' => 0, 'status' => $sent, 'total_amount_cents' => 999999, 'amount_due_cents' => 999999, 'due_date' => '2026-01-01', 'last_error' => 'test-mode flag' ] ),
            $this->row( [ 'livemode' => 0, 'invoice_kind' => 'join', 'status' => $paid, 'total_amount_cents' => 5000, 'amount_paid_cents' => 5000 ] ),
            $this->row( [ 'livemode' => 0, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, 'total_amount_cents' => 88888, 'gateway_invoice_id' => null ] ),
            $this->row( [ 'livemode' => 0, 'dues_year' => 2026, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED, 'total_amount_cents' => 1000, 'gateway_invoice_id' => null, 'exclusion_reason' => MyNJILGA_Dues_Preview::EXCLUDED_NO_OWNER ] ),
        ];
    }

    // -------------------------------------------------------------------
    // The fake database
    // -------------------------------------------------------------------

    /**
     * A PHP model of the statements MyNJILGA_Invoice_Stats sends: what
     * `GROUP BY status, invoice_kind` returns over $rows for one mode (and
     * one year, when the statement has one). Returns the tuples exactly as
     * MySQL would hand them over — one per (status, kind).
     *
     * Mirrors the SQL rule for rule: "no error" is empty, "ready" is
     * draft/approved with no error and not queued, "past due" is an open
     * status with a due date on or BEFORE today, and an unbilled downgrade is
     * a downgraded row with no gateway invoice id. Also folds the exclusion
     * reasons onto the excluded tuples the way load_groups() does when a
     * year is given.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public function aggregate( array $rows, ?int $year, bool $live, string $today ): array {
        $T      = 'MyNJILGA_Dues_Invoice_Table';
        $open   = [ $T::STATUS_CREATED, $T::STATUS_SENT, $T::STATUS_PROCESSING ];
        $groups = [];

        foreach ( $rows as $r ) {
            if ( (int) $r['livemode'] !== ( $live ? 1 : 0 ) ) {
                continue;
            }
            if ( $year !== null && (int) $r['dues_year'] !== $year ) {
                continue;
            }
            $status = (string) $r['status'];
            $kind   = (string) $r['invoice_kind'];
            $key    = $status . '|' . $kind;
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = $this->group( [ 'status' => $status, 'invoice_kind' => $kind ] );
            }
            $g = &$groups[ $key ];

            $hasError = (string) $r['last_error'] !== '';
            $queued   = $status === $T::STATUS_APPROVED && $r['queued_at'] !== null;
            $ready    = in_array( $status, [ $T::STATUS_DRAFT, $T::STATUS_APPROVED ], true ) && ! $hasError && ! $queued;
            $unbilled = $status === $T::STATUS_DOWNGRADED && ( $r['gateway_invoice_id'] === null || $r['gateway_invoice_id'] === '' );
            $pastDue  = in_array( $status, $open, true ) && $r['due_date'] !== null && $r['due_date'] <= $today;

            $g['n']              += 1;
            $g['total']          += $r['total_amount_cents'];
            $g['paid']           += $r['amount_paid_cents'];
            $g['due']            += $r['amount_due_cents'];
            $g['refunded']       += $r['amount_refunded_cents'];
            $g['off_stripe']     += $r['paid_off_stripe_cents'];
            $g['flagged']        += $hasError ? 1 : 0;
            $g['creating']       += ( $queued && ! $hasError ) ? 1 : 0;
            $g['ready_n']        += $ready ? 1 : 0;
            $g['ready_total']    += $ready ? $r['total_amount_cents'] : 0;
            $g['unbilled_n']     += $unbilled ? 1 : 0;
            $g['unbilled_total'] += $unbilled ? $r['total_amount_cents'] : 0;
            $g['past_due_cents'] += $pastDue ? $r['amount_due_cents'] : 0;
            $g['past_due_n']     += $pastDue ? 1 : 0;
            if ( $status === $T::STATUS_PROCESSING && $r['processing_at'] !== null ) {
                $g['oldest_processing'] = $g['oldest_processing'] === null ? $r['processing_at'] : min( $g['oldest_processing'], $r['processing_at'] );
            }
            if ( $year !== null && $status === $T::STATUS_EXCLUDED ) {
                foreach ( [ 'no_members' => MyNJILGA_Dues_Preview::EXCLUDED_NO_MEMBERS, 'zero_total' => MyNJILGA_Dues_Preview::EXCLUDED_ZERO_TOTAL ] as $prefix => $reason ) {
                    $g[ $prefix . '_n' ]     = ( $g[ $prefix . '_n' ] ?? 0 ) + ( $r['exclusion_reason'] === $reason ? 1 : 0 );
                    $g[ $prefix . '_total' ] = ( $g[ $prefix . '_total' ] ?? 0 ) + ( $r['exclusion_reason'] === $reason ? $r['total_amount_cents'] : 0 );
                }
            }
            unset( $g );
        }

        return array_values( $groups );
    }

    /**
     * A $wpdb double that answers each statement the class emits from
     * $rows, the way MySQL would: the mode, the year and "today" are read
     * back out of the SQL text, so a statement that lost its predicate
     * would return the other mode's rows and the assertion on the numbers
     * (and on the SQL) would fail. Numbers come back as STRINGS, like the
     * real $wpdb hands them over.
     *
     * Its prepare() also insists that placeholders and arguments line up —
     * the real one only warns.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function db( array $rows ): NJILGA_Recording_Wpdb {
        $model = function ( ?int $year, bool $live, string $today ) use ( $rows ): array {
            return $this->aggregate( $rows, $year, $live, $today );
        };
        return new class( $rows, $model ) extends NJILGA_Recording_Wpdb {
            /** @var array<int,array<string,mixed>> */
            private $rows;
            /** @var callable */
            private $model;

            public function __construct( array $rows, callable $model ) {
                $this->rows  = $rows;
                $this->model = $model;
            }

            public function esc_like( string $text ): string {
                return addcslashes( $text, '_%\\' );
            }

            public function prepare( string $query, ...$args ): string {
                $given = ( count( $args ) === 1 && is_array( $args[0] ) ) ? count( $args[0] ) : count( $args );
                $need  = preg_match_all( '/%[ds]/', $query );
                if ( $need !== $given ) {
                    throw new NJILGA_Assertion_Failed( "prepare(): $need placeholders but $given arguments in: $query" );
                }
                return parent::prepare( $query, ...$args );
            }

            public function get_results( string $query ): array {
                $this->queries[] = $query;
                $live = strpos( $query, 'livemode = 1' ) !== false;
                if ( strpos( $query, 'livemode = ' ) === false ) {
                    throw new NJILGA_Assertion_Failed( 'statement without a livemode predicate: ' . $query );
                }
                $year = preg_match( '/dues_year = (\d+)/', $query, $m ) ? (int) $m[1] : null;

                // Query C: the reason split, one row per kind, excluded rows only.
                if ( strpos( $query, 'GROUP BY invoice_kind' ) !== false ) {
                    $out = [];
                    foreach ( ( $this->model )( $year, $live, '9999-12-31' ) as $g ) {
                        if ( $g['status'] === 'excluded' ) {
                            $out[] = (object) [
                                'invoice_kind'     => $g['invoice_kind'],
                                'no_members_n'     => (string) $g['no_members_n'],
                                'no_members_total' => (string) $g['no_members_total'],
                                'zero_total_n'     => (string) $g['zero_total_n'],
                                'zero_total_total' => (string) $g['zero_total_total'],
                            ];
                        }
                    }
                    return $out;
                }

                // Query A/B.
                $today = preg_match( "/due_date <= '(\d{4}-\d{2}-\d{2})'/", $query, $m ) ? $m[1] : '0000-00-00';
                $out   = [];
                foreach ( ( $this->model )( $year, $live, $today ) as $g ) {
                    unset( $g['no_members_n'], $g['no_members_total'], $g['zero_total_n'], $g['zero_total_total'] );
                    $row = [];
                    foreach ( $g as $k => $v ) {
                        $row[ $k ] = is_int( $v ) ? (string) $v : $v;
                    }
                    $out[] = (object) $row;
                }
                return $out;
            }

            public function get_var( string $query ) {
                $this->queries[] = $query;
                if ( strpos( $query, 'livemode = ' ) === false ) {
                    throw new NJILGA_Assertion_Failed( 'statement without a livemode predicate: ' . $query );
                }
                $live = strpos( $query, 'livemode = 1' ) !== false;
                preg_match( '/dues_year = (\d+)/', $query, $m );
                foreach ( $this->rows as $r ) {
                    if ( (int) $r['livemode'] === ( $live ? 1 : 0 ) && (int) $r['dues_year'] === (int) $m[1] && $r['invoice_kind'] !== 'join' ) {
                        return '1';
                    }
                }
                return null;
            }
        };
    }

    /**
     * Runs $fn with $db installed as the global $wpdb, and puts the
     * previous one back whatever happens.
     *
     * @return mixed
     */
    private function with_db( NJILGA_Recording_Wpdb $db, callable $fn ) {
        $saved           = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $db;
        try {
            return $fn();
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }

    private function assertSqlHas( string $needle, string $sql ): void {
        $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected SQL to contain \"$needle\" — got: $sql" );
    }

    private function assertSqlLacks( string $needle, string $sql ): void {
        $this->assertFalse( strpos( $sql, $needle ) !== false, "Expected SQL NOT to contain \"$needle\" — got: $sql" );
    }

    private function assertScopedToMode( bool $live, string $sql ): void {
        $this->assertSqlHas( $live ? 'livemode = 1' : 'livemode = 0', $sql );
        $this->assertSqlLacks( $live ? 'livemode = 0' : 'livemode = 1', $sql );
    }

    // -------------------------------------------------------------------
    // Rollup: shape and the empty table
    // -------------------------------------------------------------------

    /**
     * A brand-new install: no groups at all. Every figure is zero, Paid %
     * is 0 rather than a division by zero, nothing is "oldest", and the
     * whole shape — every section, every key, in order — is fixed, so the
     * Dashboard can always read it without an isset().
     */
    public function testEmptyTableIsAllZerosAndPaidPercentIsNotADivisionError(): void {
        $this->assertSame( [
            'annual'      => $this->zero( 'annual' ),
            'joins'       => $this->zero( 'joins' ),
            'receivables' => $this->zero( 'receivables' ),
            'pipeline'    => $this->zero( 'pipeline' ),
            'flags'       => $this->zero( 'flags' ),
        ], $this->rollup( [] ) );
    }

    // -------------------------------------------------------------------
    // Rollup: each row shape
    // -------------------------------------------------------------------

    /**
     * ONLINE JOINS. Paid at Checkout before any row existed, so they are
     * not annual dues: kept out of Invoiced, Collected, Paid % and the
     * batch (Invoicing does the same), and reported as their own line. The
     * ALL-years Collected is Payments' card and DOES include them.
     */
    public function testJoinRowsAreTheirOwnLineNotAnnualDues(): void {
        $r = $this->rollup( [
            // What was PAID at Checkout (25000), not the price list (27000): a discount code is no revenue.
            $this->group( [ 'status' => 'paid', 'invoice_kind' => 'join', 'n' => 2, 'total' => 27000, 'paid' => 25000 ] ),
            // A join row that is somehow not paid (never written that way) is not a paid join, and not annual dues or pipeline either.
            $this->group( [ 'status' => 'draft', 'invoice_kind' => 'join', 'n' => 1, 'total' => 9000, 'ready_n' => 1, 'ready_total' => 9000 ] ),
            $this->group( [ 'status' => 'paid', 'invoice_kind' => 'combined', 'n' => 1, 'total' => 50000, 'paid' => 50000 ] ),
        ] );

        $this->assertSame( [ 'count' => 2, 'cents' => 25000 ], $r['joins'] );
        $this->assertSame( $this->zero( 'annual', [
            'invoices' => 1, 'paid_invoices' => 1, 'invoiced_cents' => 50000, 'collected_cents' => 50000,
            'paid_pct' => 100, 'batch_cents' => 50000,
        ] ), $r['annual'] );
        $this->assertSame( $this->zero( 'pipeline' ), $r['pipeline'] );
        $this->assertSame( 75000, $r['receivables']['collected_cents'], 'Payments counts the joins in Collected' );
        $this->assertSame( 0, $r['receivables']['outstanding_cents'] );
    }

    /**
     * ASSESSMENT-ONLY invoices are real Stripe invoices with their own
     * paid and due, so they stay in the dollar figures on Invoicing and
     * Payments alike (the dashboard never infers membership from them).
     */
    public function testAssessmentOnlyInvoicesStayInTheDollarFigures(): void {
        $r = $this->rollup( [
            $this->group( [ 'status' => 'sent', 'invoice_kind' => 'assessment', 'n' => 2, 'total' => 40000, 'paid' => 10000, 'due' => 30000 ] ),
            $this->group( [ 'status' => 'sent', 'invoice_kind' => 'dues', 'n' => 1, 'total' => 100000, 'due' => 100000 ] ),
        ] );

        $this->assertSame( 3, $r['annual']['invoices'] );
        $this->assertSame( 140000, $r['annual']['invoiced_cents'] );
        $this->assertSame( 10000, $r['annual']['collected_cents'] );
        $this->assertSame( 130000, $r['annual']['outstanding_cents'] );
        $this->assertSame( 0, $r['annual']['paid_invoices'] );
        $this->assertSame( 0, $r['joins']['count'] );
    }

    /**
     * EXCLUDED rows keep a non-zero total when the firm is priced but has
     * no Owner. That would-be amount is reported as Blocked and NOTHING
     * else: it never reaches Invoiced, the batch, or the ready pipeline.
     * Rows excluded for having no members or nothing to bill are not
     * blocked (informational), and a row with no recorded reason counts as
     * no-owner, as Invoicing classifies it.
     */
    public function testExcludedTotalsAreOnlyEverBlockedNeverInvoicedBatchOrPipeline(): void {
        $excluded = $this->group( [
            'status' => 'excluded', 'n' => 4, 'total' => 90000,
            'no_members_n' => 1, 'no_members_total' => 0, 'zero_total_n' => 1, 'zero_total_total' => 0,
        ] );
        $draft    = $this->group( [ 'status' => 'draft', 'n' => 1, 'total' => 20000, 'ready_n' => 1, 'ready_total' => 20000 ] );

        $r = $this->rollup( [ $excluded, $draft ] );

        $this->assertSame( 2, $r['pipeline']['blocked_no_owner_count'] );
        $this->assertSame( 90000, $r['pipeline']['blocked_no_owner_cents'] );
        $this->assertSame( 0, $r['annual']['invoiced_cents'] );
        $this->assertSame( 20000, $r['annual']['batch_cents'], 'the excluded 90000 is not part of the batch' );
        $this->assertSame( 1, $r['pipeline']['ready_count'] );
        $this->assertSame( 20000, $r['pipeline']['ready_cents'] );
        $this->assertSame( 0, $r['receivables']['outstanding_cents'] );

        // No reasons known at all: every excluded row is a no-owner row.
        $bare = $this->rollup( [ $this->group( [ 'status' => 'excluded', 'n' => 4, 'total' => 90000 ] ) ] );
        $this->assertSame( 4, $bare['pipeline']['blocked_no_owner_count'] );
        $this->assertSame( 90000, $bare['pipeline']['blocked_no_owner_cents'] );

        // The informational reasons never count as blocked money either,
        // even if such a row carried a total: 90000 less 12000 and 3000.
        $priced = $this->rollup( [ $this->group( [
            'status' => 'excluded', 'n' => 4, 'total' => 90000,
            'no_members_n' => 1, 'no_members_total' => 12000, 'zero_total_n' => 1, 'zero_total_total' => 3000,
        ] ) ] );
        $this->assertSame( 2, $priced['pipeline']['blocked_no_owner_count'] );
        $this->assertSame( 75000, $priced['pipeline']['blocked_no_owner_cents'] );
    }

    /**
     * DRAFTS are the pipeline and the batch, and nothing else: their total
     * is an estimate, and their money columns are zero, so they add
     * nothing to Invoiced, Collected or Outstanding.
     */
    public function testDraftsAreTheBatchAndPipelineButNotInvoiced(): void {
        $r = $this->rollup( [
            $this->group( [ 'status' => 'draft', 'n' => 3, 'total' => 60000, 'ready_n' => 3, 'ready_total' => 60000 ] ),
        ] );

        $this->assertSame( $this->zero( 'annual', [ 'batch_cents' => 60000 ] ), $r['annual'] );
        $this->assertSame( $this->zero( 'pipeline', [ 'ready_count' => 3, 'ready_cents' => 60000 ] ), $r['pipeline'] );
        $this->assertSame( $this->zero( 'receivables' ), $r['receivables'] );
    }

    /**
     * APPROVED rows split three ways and never overlap: ready to create,
     * creating (queued, no error), error (last_error, queued or not). Ready
     * is only the first; the batch still counts all of them.
     */
    public function testApprovedRowsSplitIntoReadyCreatingAndError(): void {
        $r = $this->rollup( [
            // 5 approved at 20000: 3 ready, 1 queued, 1 with an error.
            $this->group( [ 'status' => 'approved', 'n' => 5, 'total' => 100000, 'ready_n' => 3, 'ready_total' => 60000, 'creating' => 1, 'flagged' => 1 ] ),
            // 2 drafts at 15000: 1 ready, 1 with an error.
            $this->group( [ 'status' => 'draft', 'n' => 2, 'total' => 30000, 'ready_n' => 1, 'ready_total' => 15000, 'flagged' => 1 ] ),
        ] );

        $this->assertSame( 4, $r['pipeline']['ready_count'] );
        $this->assertSame( 75000, $r['pipeline']['ready_cents'] );
        $this->assertSame( 1, $r['pipeline']['creating'] );
        $this->assertSame( 2, $r['pipeline']['error'] );
        $this->assertSame( 130000, $r['annual']['batch_cents'] );
        $this->assertSame( 0, $r['annual']['invoiced_cents'] );
        $this->assertSame( 2, $r['flags']['open'], 'a draft/approved error is an OPEN flag' );
    }

    /**
     * ACH IN FLIGHT: a processing invoice is Invoiced and Outstanding like
     * any open one, and In Flight is a SUBSET of Outstanding — 45000 of the
     * 55000 outstanding, never 100000. The oldest one ages in whole days.
     */
    public function testProcessingIsInOutstandingAndInFlightButNeverAdditive(): void {
        $r = $this->rollup( [
            $this->group( [ 'status' => 'processing', 'n' => 2, 'total' => 45000, 'due' => 45000, 'oldest_processing' => '2026-09-20' ] ),
            $this->group( [ 'status' => 'sent', 'n' => 1, 'total' => 10000, 'due' => 10000 ] ),
        ] );

        $this->assertSame( 55000, $r['receivables']['outstanding_cents'] );
        $this->assertSame( 45000, $r['receivables']['in_flight_cents'] );
        $this->assertSame( 2, $r['receivables']['in_flight_count'] );
        $this->assertSame( 3, $r['receivables']['open_count'] );
        $this->assertSame( 9, $r['receivables']['oldest_processing_days'] );
        $this->assertSame( 3, $r['annual']['invoices'] );
        $this->assertSame( 55000, $r['annual']['invoiced_cents'] );
        $this->assertSame( 55000, $r['annual']['outstanding_cents'] );
    }

    /** The oldest ACH is the earliest processing date across groups; a date-time is read by its date; nothing usable means null, and a date in the future never reads negative. */
    public function testOldestProcessingIsTheEarliestDateAndDegradesToNull(): void {
        $p = static function ( $oldest ): array {
            return [ 'status' => 'processing', 'n' => 1, 'total' => 100, 'due' => 100, 'oldest_processing' => $oldest ];
        };

        $r = $this->rollup( [ $this->group( $p( '2026-09-25 08:30:00' ) ), $this->group( $p( '2026-09-20 10:00:00' ) ), $this->group( $p( null ) ) ] );
        $this->assertSame( 9, $r['receivables']['oldest_processing_days'] );

        $r = $this->rollup( [ $this->group( $p( self::TODAY . ' 23:59:59' ) ) ] );
        $this->assertSame( 0, $r['receivables']['oldest_processing_days'], 'submitted today is 0 days, not null' );

        foreach ( [ null, '', '0000-00-00 00:00:00', 'garbage' ] as $nothing ) {
            $r = $this->rollup( [ $this->group( $p( $nothing ) ) ] );
            $this->assertSame( null, $r['receivables']['oldest_processing_days'] );
            $this->assertSame( 1, $r['receivables']['in_flight_count'], 'still in flight without a date' );
        }

        $r = $this->rollup( [ $this->group( $p( '2030-01-01' ) ) ] );
        $this->assertSame( 0, $r['receivables']['oldest_processing_days'], 'clock skew never goes negative' );

        // Only PROCESSING rows have an oldest date.
        $r = $this->rollup( [ $this->group( [ 'status' => 'sent', 'n' => 1, 'oldest_processing' => '2020-01-01' ] ) ] );
        $this->assertSame( null, $r['receivables']['oldest_processing_days'] );
    }

    /**
     * PARTIAL PAYMENT on an open invoice: the $200 that came in is
     * Collected AND the $300 still owed is Outstanding — both, by design —
     * and the invoice is not a "paid invoice". Paid % is dollars.
     */
    public function testPartialPaymentOnAnOpenInvoiceIsInBothCollectedAndOutstanding(): void {
        $r = $this->rollup( [
            $this->group( [ 'status' => 'sent', 'n' => 1, 'total' => 50000, 'paid' => 20000, 'due' => 30000 ] ),
        ] );

        $this->assertSame( 20000, $r['annual']['collected_cents'] );
        $this->assertSame( 30000, $r['annual']['outstanding_cents'] );
        $this->assertSame( 50000, $r['annual']['collected_cents'] + $r['annual']['outstanding_cents'], 'the invoice, once' );
        $this->assertSame( 50000, $r['annual']['invoiced_cents'] );
        $this->assertSame( 0, $r['annual']['paid_invoices'] );
        $this->assertSame( 40, $r['annual']['paid_pct'] );
        $this->assertSame( 20000, $r['receivables']['collected_cents'] );
        $this->assertSame( 30000, $r['receivables']['outstanding_cents'] );
    }

    /**
     * VOIDED AFTER A PARTIAL PAYMENT — THE DOUBLE-COUNT GUARD. Written Off
     * is the DUE (the balance actually lost), never the total, because the
     * part that was paid is already in Collected. Written Off + Collected
     * is the invoice once. A voided invoice was never owed, so it is not
     * Invoiced and not Outstanding; an uncollectible one was, so it stays in
     * Invoiced, and follows the same write-off rule.
     */
    public function testVoidedAfterPartialWritesOffTheDueNotTheTotal(): void {
        $voided = $this->rollup( [
            $this->group( [ 'status' => 'voided', 'n' => 1, 'total' => 50000, 'paid' => 20000, 'due' => 30000 ] ),
        ] );

        $this->assertSame( 30000, $voided['receivables']['written_off_cents'] );
        $this->assertSame( 20000, $voided['receivables']['collected_cents'] );
        $this->assertSame( 50000, $voided['receivables']['collected_cents'] + $voided['receivables']['written_off_cents'] );
        $this->assertSame( 0, $voided['receivables']['outstanding_cents'] );
        $this->assertSame( 0, $voided['annual']['outstanding_cents'] );
        $this->assertSame( 0, $voided['annual']['invoiced_cents'], 'never owed' );
        $this->assertSame( 0, $voided['annual']['invoices'] );
        $this->assertSame( 20000, $voided['annual']['collected_cents'], 'the money that did arrive is still collected' );

        $uncollectible = $this->rollup( [
            $this->group( [ 'status' => 'uncollectible', 'n' => 1, 'total' => 50000, 'paid' => 20000, 'due' => 30000 ] ),
        ] );
        $this->assertSame( 30000, $uncollectible['receivables']['written_off_cents'] );
        $this->assertSame( 0, $uncollectible['receivables']['outstanding_cents'] );
        $this->assertSame( 50000, $uncollectible['annual']['invoiced_cents'] );
        $this->assertSame( 1, $uncollectible['annual']['invoices'] );
        $this->assertSame( 40, $uncollectible['annual']['paid_pct'], 'a write-off stays in the denominator' );
    }

    /**
     * OVERPAID: $150 came in on a $125 invoice. Collected is what came in
     * (gross), Outstanding reads the row's own balance (0 — never a
     * negative total-minus-paid that would eat another firm's balance), and
     * Paid % is clamped at 100.
     */
    public function testOverpaidInvoiceReadsItsDueAndClampsPaidPercent(): void {
        $r = $this->rollup( [
            $this->group( [ 'status' => 'paid', 'n' => 1, 'total' => 12500, 'paid' => 15000, 'due' => 0 ] ),
            $this->group( [ 'status' => 'sent', 'n' => 1, 'total' => 1000, 'due' => 1000 ] ),
        ] );

        $this->assertSame( 15000, $r['annual']['collected_cents'] );
        $this->assertSame( 1000, $r['annual']['outstanding_cents'], 'not total - paid, which would be -1500' );
        $this->assertSame( 100, $r['annual']['paid_pct'] );
    }

    /**
     * REFUNDS are reported beside Collected, never taken out of it: a
     * charge refund writes amount_refunded_cents and leaves amount_paid and
     * the status alone, so the invoice is still a paid invoice and
     * Collected stays gross (Payments' card does too).
     */
    public function testRefundsAreReportedSeparatelyAndCollectedStaysGross(): void {
        $r = $this->rollup( [
            $this->group( [ 'status' => 'paid', 'n' => 1, 'total' => 50000, 'paid' => 50000, 'refunded' => 20000 ] ),
        ] );

        $this->assertSame( 50000, $r['annual']['collected_cents'] );
        $this->assertSame( 20000, $r['annual']['refunded_cents'] );
        $this->assertSame( 1, $r['annual']['paid_invoices'] );
        $this->assertSame( 0, $r['annual']['outstanding_cents'] );
        $this->assertSame( 50000, $r['receivables']['collected_cents'] );
    }

    /** Money marked paid outside Stripe is already INSIDE amount_paid: it is a sub-line of Collected, never added to it. */
    public function testOffStripeMoneyIsASubsetOfCollected(): void {
        $r = $this->rollup( [
            $this->group( [ 'status' => 'paid', 'n' => 1, 'total' => 50000, 'paid' => 50000, 'off_stripe' => 50000 ] ),
        ] );

        $this->assertSame( 50000, $r['annual']['collected_cents'] );
        $this->assertSame( 50000, $r['annual']['off_stripe_cents'] );
    }

    /**
     * DOWNGRADED NEVER INVOICED. The year-end sweep also downgrades drafts
     * that were never sent. Those have no Stripe invoice, so they are not
     * Invoiced (nor in the batch), while a downgrade that HAD an invoice
     * keeps its total and its unpaid balance (Outstanding includes it, and
     * it is reported on its own so Outstanding reconciles with Aging).
     */
    public function testDowngradedNeverInvoicedRowsAreNotInvoiced(): void {
        $r = $this->rollup( [
            // One real unpaid invoice ($300) and two swept drafts (an estimated $300 each, never sent).
            $this->group( [ 'status' => 'downgraded', 'n' => 3, 'total' => 90000, 'due' => 30000, 'unbilled_n' => 2, 'unbilled_total' => 60000 ] ),
        ] );

        $this->assertSame( 1, $r['annual']['invoices'] );
        $this->assertSame( 30000, $r['annual']['invoiced_cents'] );
        $this->assertSame( 30000, $r['annual']['batch_cents'] );
        $this->assertSame( 30000, $r['receivables']['outstanding_cents'] );
        $this->assertSame( 30000, $r['receivables']['downgraded_cents'] );
        $this->assertSame( 0, $r['receivables']['past_due_cents'], 'terminal rows are never past due' );
    }

    /**
     * Payments' Aging total and By-firm "Total Outstanding" drop the
     * downgraded balance; the stat card keeps it. Reporting the downgraded
     * balance on its own is what lets the two be reconciled.
     */
    public function testDowngradedBalanceReconcilesOutstandingWithAging(): void {
        $r = $this->rollup( [
            $this->group( [ 'status' => 'sent', 'n' => 1, 'total' => 40000, 'due' => 40000 ] ),
            $this->group( [ 'status' => 'downgraded', 'n' => 1, 'total' => 15000, 'due' => 15000 ] ),
        ] );

        $this->assertSame( 55000, $r['receivables']['outstanding_cents'] );
        $this->assertSame( 15000, $r['receivables']['downgraded_cents'] );
        $this->assertSame( 40000, $r['receivables']['outstanding_cents'] - $r['receivables']['downgraded_cents'] );
    }

    /**
     * PAST DUE. The tuples arrive with past-due already worked out by the
     * database (see testPastDueIsInclusiveOfTheDueDate for the boundary);
     * the rollup sums them for the OPEN statuses only, and a stray past-due
     * figure on a terminal row is ignored, not added.
     */
    public function testPastDueSumsOpenGroupsOnly(): void {
        $r = $this->rollup( [
            $this->group( [ 'status' => 'created', 'n' => 2, 'due' => 30000, 'past_due_cents' => 10000, 'past_due_n' => 1 ] ),
            $this->group( [ 'status' => 'processing', 'n' => 1, 'due' => 5000, 'past_due_cents' => 5000, 'past_due_n' => 1 ] ),
            $this->group( [ 'status' => 'paid', 'n' => 1, 'past_due_cents' => 99999, 'past_due_n' => 9 ] ),
            $this->group( [ 'status' => 'voided', 'n' => 1, 'due' => 7000, 'past_due_cents' => 99999, 'past_due_n' => 9 ] ),
        ] );

        $this->assertSame( 15000, $r['receivables']['past_due_cents'] );
        $this->assertSame( 2, $r['receivables']['past_due_count'] );
    }

    // -------------------------------------------------------------------
    // Paid %
    // -------------------------------------------------------------------

    /**
     * Paid % is dollars collected over dollars invoiced, rounded DOWN: 100
     * must mean nothing is left, so $299 of $300 reads 99, not 100. 29 of
     * 100 reads 29 (a float floor gives 28), a third reads 33, no invoices
     * reads 0, and more collected than invoiced clamps to 100.
     */
    public function testPaidPercentFloorsClampsAndSurvivesNothingInvoiced(): void {
        $this->assertSame( 99, MyNJILGA_Invoice_Stats::paid_pct( 29900, 30000 ) );
        $this->assertSame( 29, MyNJILGA_Invoice_Stats::paid_pct( 29, 100 ) );
        $this->assertSame( 33, MyNJILGA_Invoice_Stats::paid_pct( 1, 3 ) );
        $this->assertSame( 100, MyNJILGA_Invoice_Stats::paid_pct( 30000, 30000 ) );
        $this->assertSame( 100, MyNJILGA_Invoice_Stats::paid_pct( 45000, 30000 ) );
        $this->assertSame( 0, MyNJILGA_Invoice_Stats::paid_pct( 500, 0 ) );
        $this->assertSame( 0, MyNJILGA_Invoice_Stats::paid_pct( 0, 30000 ) );
        $this->assertSame( 0, MyNJILGA_Invoice_Stats::paid_pct( 0, 0 ) );
    }

    /**
     * "Today" is only ever a site-local Y-m-d. Anything else (an empty
     * option, a datetime, a hostile string) is reduced to its date or
     * replaced by the UTC date — it never reaches the SQL as written, and
     * never turns the oldest-ACH age into an error.
     */
    public function testATodayThatIsNotADateIsReplacedNotEmbedded(): void {
        $processing = [ $this->group( [ 'status' => 'processing', 'n' => 1, 'due' => 100, 'oldest_processing' => '2020-01-01' ] ) ];

        $r = MyNJILGA_Invoice_Stats::rollup( $processing, "x' OR 1=1 --" );
        $this->assertTrue( $r['receivables']['oldest_processing_days'] > 2000, 'aged against the real date, not a garbage one' );

        $r = MyNJILGA_Invoice_Stats::rollup( $processing, '2020-01-11 23:59:59' );
        $this->assertSame( 10, $r['receivables']['oldest_processing_days'], 'a datetime is read by its date' );

        $this->with_db( $this->db( [] ), function (): void {
            $sql = MyNJILGA_Invoice_Stats::groups_sql( self::YEAR, true, "x' OR 1=1 --" );
            $this->assertSqlLacks( 'OR 1=1', $sql );
            $this->assertSqlHas( "due_date <= '" . gmdate( 'Y-m-d' ) . "'", $sql );
        } );
    }

    // -------------------------------------------------------------------
    // Flags
    // -------------------------------------------------------------------

    /**
     * FLAGS are never one number. A last_error on a row still in play is
     * OPEN (actionable); one on a paid/downgraded/voided/uncollectible row
     * is REVIEW — nothing ever clears it, so lumped together the count
     * would never return to zero. Excluded rows are not flagged. Joins are:
     * a join-apply failure is a real flag on a paid row.
     */
    public function testFlagsSplitIntoOpenAndReviewAndSkipExcluded(): void {
        $r = $this->rollup( [
            $this->group( [ 'status' => 'draft', 'n' => 3, 'flagged' => 1 ] ),
            $this->group( [ 'status' => 'approved', 'n' => 3, 'flagged' => 1 ] ),
            $this->group( [ 'status' => 'created', 'n' => 3, 'flagged' => 2 ] ),
            $this->group( [ 'status' => 'sent', 'n' => 3, 'flagged' => 1 ] ),
            $this->group( [ 'status' => 'processing', 'n' => 3, 'flagged' => 1 ] ),
            $this->group( [ 'status' => 'paid', 'n' => 5, 'flagged' => 3 ] ),
            $this->group( [ 'status' => 'paid', 'invoice_kind' => 'join', 'n' => 5, 'flagged' => 1 ] ),
            $this->group( [ 'status' => 'downgraded', 'n' => 2, 'flagged' => 1 ] ),
            $this->group( [ 'status' => 'voided', 'n' => 2, 'flagged' => 1 ] ),
            $this->group( [ 'status' => 'uncollectible', 'n' => 2, 'flagged' => 1 ] ),
            $this->group( [ 'status' => 'excluded', 'n' => 9, 'flagged' => 9 ] ),
        ] );

        $this->assertSame( [ 'open' => 6, 'review' => 7 ], $r['flags'] );
    }

    // -------------------------------------------------------------------
    // Past-due boundary, through the database model
    // -------------------------------------------------------------------

    /**
     * An invoice is past due ON its due date, not the day after: Payments'
     * aging puts age 0 in its first bucket. Due yesterday and due today are
     * past due; due tomorrow, with no due date, or terminal (paid/voided)
     * are not; ACH in flight ages like any open row.
     */
    public function testPastDueIsInclusiveOfTheDueDate(): void {
        $sent = MyNJILGA_Dues_Invoice_Table::STATUS_SENT;
        $rows = [
            $this->row( [ 'status' => $sent, 'amount_due_cents' => 10000, 'due_date' => '2026-09-28' ] ),                         // before  — past due
            $this->row( [ 'status' => $sent, 'amount_due_cents' => 20000, 'due_date' => self::TODAY ] ),                          // ON      — past due
            $this->row( [ 'status' => $sent, 'amount_due_cents' => 40000, 'due_date' => '2026-09-30' ] ),                         // after   — not yet
            $this->row( [ 'status' => $sent, 'amount_due_cents' => 80000 ] ),                                                     // no date — never
            $this->row( [ 'status' => 'processing', 'amount_due_cents' => 160000, 'due_date' => '2026-09-01' ] ),                 // ACH    — past due
            $this->row( [ 'status' => 'paid', 'amount_paid_cents' => 500, 'due_date' => '2026-01-01' ] ),                         // paid   — never
            $this->row( [ 'status' => 'voided', 'amount_due_cents' => 5000, 'due_date' => '2026-01-01' ] ),                       // voided — never
            $this->row( [ 'status' => 'downgraded', 'amount_due_cents' => 7000, 'due_date' => '2026-01-01' ] ),                   // swept  — never
        ];

        $today = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $rows, null, true, self::TODAY ), self::TODAY );
        $this->assertSame( 190000, $today['receivables']['past_due_cents'] );
        $this->assertSame( 3, $today['receivables']['past_due_count'] );

        // A day later the row due on the 30th is past due on its own due date.
        $tomorrow = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $rows, null, true, '2026-09-30' ), '2026-09-30' );
        $this->assertSame( 230000, $tomorrow['receivables']['past_due_cents'] );
        $this->assertSame( 4, $tomorrow['receivables']['past_due_count'] );

        // Two days earlier the rows due on the 28th and the 29th are not past due yet.
        $yesterday = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $rows, null, true, '2026-09-27' ), '2026-09-27' );
        $this->assertSame( 160000, $yesterday['receivables']['past_due_cents'] );
        $this->assertSame( 1, $yesterday['receivables']['past_due_count'] );
    }

    // -------------------------------------------------------------------
    // Parity with the Payments ledger
    // -------------------------------------------------------------------

    /**
     * The lines MyNJILGA_Page_Payments::to_line() would build from these
     * rows: only the ledger statuses, in this mode, each with the age
     * bucket derived from its due date the way to_line() derives it (a
     * terminal row or one with no due date gets none; age 0 is the first
     * bucket).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function payments_lines( array $rows, bool $live, string $today, ?int $year = null ): array {
        $lines = [];
        foreach ( $rows as $r ) {
            if ( (int) $r['livemode'] !== ( $live ? 1 : 0 ) || ! in_array( $r['status'], MyNJILGA_Page_Payments::RELEVANT_STATUSES, true ) ) {
                continue;
            }
            if ( $year !== null && (int) $r['dues_year'] !== $year ) {
                continue;
            }
            $bucket = '';
            if ( ! in_array( $r['status'], MyNJILGA_Page_Payments::TERMINAL_STATUSES, true ) && $r['due_date'] !== null ) {
                $age = intdiv( (int) strtotime( $today . ' 00:00:00 UTC' ) - (int) strtotime( $r['due_date'] . ' 00:00:00 UTC' ), 86400 );
                if ( $age < 0 ) {
                    $bucket = 'notyet';
                } elseif ( $age <= 30 ) {
                    $bucket = '0-30';
                } elseif ( $age <= 60 ) {
                    $bucket = '31-60';
                } elseif ( $age <= 90 ) {
                    $bucket = '61-90';
                } else {
                    $bucket = '90+';
                }
            }
            $lines[] = [
                'status'    => $r['status'],
                'year'      => (int) $r['dues_year'],
                'total'     => (int) $r['total_amount_cents'],
                'paid'      => (int) $r['amount_paid_cents'],
                'due'       => (int) $r['amount_due_cents'],
                'ageBucket' => $bucket,
            ];
        }
        return $lines;
    }

    /**
     * THE PARITY TEST. Same rows in, two calculators: the Payments ledger's
     * (MyNJILGA_Ledger_Totals over lines) and the Dashboard's (rollup over
     * GROUP BY tuples). Every figure they share must be identical over
     * every year — Collected, Outstanding, In Flight, Past Due, Written
     * Off — and the open-invoice count must equal the Aging tab's badge.
     * Outstanding less the downgraded balance is the Aging grand total.
     */
    public function testAllYearsReceivablesAgreeWithThePaymentsLedger(): void {
        $rows   = $this->fixtureRows();
        $ledger = MyNJILGA_Ledger_Totals::stats( $this->payments_lines( $rows, true, self::TODAY ) );
        $aging  = MyNJILGA_Ledger_Totals::aging_buckets( $this->payments_lines( $rows, true, self::TODAY ) );
        $mine   = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $rows, null, true, self::TODAY ), self::TODAY )['receivables'];

        $this->assertSame( $ledger['collectedCents'], $mine['collected_cents'], 'Collected' );
        $this->assertSame( $ledger['outstandingCents'], $mine['outstanding_cents'], 'Outstanding' );
        $this->assertSame( $ledger['inFlightCents'], $mine['in_flight_cents'], 'In Flight' );
        $this->assertSame( $ledger['pastDueCents'], $mine['past_due_cents'], 'Past Due' );
        $this->assertSame( $ledger['writtenOffCents'], $mine['written_off_cents'], 'Written Off' );
        $this->assertSame( MyNJILGA_Ledger_Totals::outstanding_count( $aging ), $mine['open_count'], 'the Aging tab badge' );
        $this->assertSame( $aging['grandTotalCents'], $mine['outstanding_cents'] - $mine['downgraded_cents'], 'the Aging total' );

        // And the same again for the test-mode ledger, which shares nothing with live.
        $testLedger = MyNJILGA_Ledger_Totals::stats( $this->payments_lines( $rows, false, self::TODAY ) );
        $testMine   = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $rows, null, false, self::TODAY ), self::TODAY )['receivables'];
        $this->assertSame( $testLedger['outstandingCents'], $testMine['outstanding_cents'] );
        $this->assertSame( 999999, $testMine['outstanding_cents'], 'the test-mode rows really are in play here' );
        $this->assertSame( $testLedger['collectedCents'], $testMine['collected_cents'] );
        $this->assertSame( $testLedger['pastDueCents'], $testMine['past_due_cents'] );
    }

    /**
     * Filtering the Payments page to one year (its client-side Years
     * filter) reads: Collected = the Dashboard's annual Collected PLUS the
     * online joins; Outstanding, In Flight and Past Due equal the annual
     * ones (a join is paid, so it has none). That is the reconciliation an
     * auditor would try first.
     */
    public function testAnnualFiguresReconcileWithPaymentsFilteredToTheYear(): void {
        $rows   = $this->fixtureRows();
        $ledger = MyNJILGA_Ledger_Totals::stats( $this->payments_lines( $rows, true, self::TODAY, self::YEAR ) );
        $r      = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $rows, self::YEAR, true, self::TODAY ), self::TODAY );

        $this->assertSame( $ledger['collectedCents'], $r['annual']['collected_cents'] + $r['joins']['cents'] );
        $this->assertSame( $ledger['outstandingCents'], $r['annual']['outstanding_cents'] );
        $this->assertSame( $ledger['inFlightCents'], $r['receivables']['in_flight_cents'] );
        $this->assertSame( $ledger['pastDueCents'], $r['receivables']['past_due_cents'] );
    }

    /**
     * Where Invoicing's summary line and the Dashboard show the same figure
     * they agree. In the ordinary case (draft, approved, created, sent and
     * paid rows, each paid row paid in full, no partials) Invoicing's Batch,
     * Collected and Outstanding are the Dashboard's. Invoicing's formulas
     * are copied here from MyNJILGA_Page_Invoicing::render_summary().
     */
    public function testAnnualFiguresAgreeWithInvoicingInTheOrdinaryCase(): void {
        $T    = 'MyNJILGA_Dues_Invoice_Table';
        $rows = [
            $this->row( [ 'status' => $T::STATUS_DRAFT, 'total_amount_cents' => 10000 ] ),
            $this->row( [ 'status' => $T::STATUS_APPROVED, 'total_amount_cents' => 20000 ] ),
            $this->row( [ 'status' => $T::STATUS_CREATED, 'total_amount_cents' => 40000, 'amount_due_cents' => 40000 ] ),
            $this->row( [ 'status' => $T::STATUS_SENT, 'total_amount_cents' => 80000, 'amount_due_cents' => 80000 ] ),
            $this->row( [ 'status' => $T::STATUS_PAID, 'total_amount_cents' => 160000, 'amount_paid_cents' => 160000 ] ),
            $this->row( [ 'status' => $T::STATUS_PAID, 'invoice_kind' => 'join', 'total_amount_cents' => 5000, 'amount_paid_cents' => 5000 ] ),
        ];

        // Invoicing: status => sum(total), annual kinds only (annual_totals()).
        $totals = [];
        foreach ( $rows as $r ) {
            if ( $r['invoice_kind'] !== 'join' ) {
                $totals[ $r['status'] ] = ( $totals[ $r['status'] ] ?? 0 ) + $r['total_amount_cents'];
            }
        }
        $batch       = ( $totals[ $T::STATUS_DRAFT ] ?? 0 ) + ( $totals[ $T::STATUS_APPROVED ] ?? 0 ) + ( $totals[ $T::STATUS_CREATED ] ?? 0 ) + ( $totals[ $T::STATUS_SENT ] ?? 0 ) + ( $totals[ $T::STATUS_PAID ] ?? 0 ) + ( $totals[ $T::STATUS_DOWNGRADED ] ?? 0 );
        $collected   = $totals[ $T::STATUS_PAID ] ?? 0;
        $outstanding = ( $totals[ $T::STATUS_CREATED ] ?? 0 ) + ( $totals[ $T::STATUS_SENT ] ?? 0 );

        $a = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $rows, self::YEAR, true, self::TODAY ), self::TODAY )['annual'];

        $this->assertSame( $batch, $a['batch_cents'], 'Batch' );
        $this->assertSame( $collected, $a['collected_cents'], 'Collected' );
        $this->assertSame( $outstanding, $a['outstanding_cents'], 'Outstanding' );
    }

    /**
     * The two places the Dashboard deliberately differs from Invoicing's
     * summary line, pinned so nobody "fixes" them back: an ACH invoice still
     * clearing (which Invoicing's formulas omit, so it vanishes from all
     * three) IS batch, invoiced and outstanding here, and an uncollectible
     * invoice IS in the batch.
     */
    public function testProcessingAndUncollectibleAreCountedWhereInvoicingOmitsThem(): void {
        $T    = 'MyNJILGA_Dues_Invoice_Table';
        $rows = [
            $this->row( [ 'status' => $T::STATUS_SENT, 'total_amount_cents' => 80000, 'amount_due_cents' => 80000 ] ),
            $this->row( [ 'status' => $T::STATUS_PROCESSING, 'total_amount_cents' => 45000, 'amount_due_cents' => 45000 ] ),
            $this->row( [ 'status' => $T::STATUS_UNCOLLECTIBLE, 'total_amount_cents' => 25000, 'amount_due_cents' => 25000 ] ),
        ];
        $a = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $rows, self::YEAR, true, self::TODAY ), self::TODAY )['annual'];

        $this->assertSame( 150000, $a['batch_cents'] );        // Invoicing's formula: 80000
        $this->assertSame( 125000, $a['outstanding_cents'] );  // Invoicing's formula: 80000 — it omits the 45000 clearing; the uncollectible 25000 is written off, in neither
        $this->assertSame( 150000, $a['invoiced_cents'] );
    }

    /**
     * Flags agree with Setup → Needs attention: open + review is exactly
     * the count MyNJILGA_Dues_Invoice_Table::get_flagged() lists (any year,
     * this mode, any status but excluded, non-empty last_error).
     */
    public function testFlagsTotalEqualsWhatSetupNeedsAttentionLists(): void {
        $rows     = $this->fixtureRows();
        $expected = 0;
        foreach ( $rows as $r ) {
            if ( (int) $r['livemode'] === 1 && $r['status'] !== MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED && $r['last_error'] !== '' ) {
                $expected++;
            }
        }

        $flags = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $rows, null, true, self::TODAY ), self::TODAY )['flags'];

        $this->assertTrue( $expected > 0 );
        $this->assertSame( $expected, $flags['open'] + $flags['review'] );
    }

    // -------------------------------------------------------------------
    // The whole fixture, with the numbers a treasurer would check by hand
    // -------------------------------------------------------------------

    /**
     * The fixture table read for 2027 in live mode, against numbers worked
     * out on paper from the rows above (2028, 2026, 2025 and every test-mode
     * row must contribute nothing to the annual side).
     */
    public function testFixtureAnnualSectionsForTheYear(): void {
        $r = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $this->fixtureRows(), self::YEAR, true, self::TODAY ), self::TODAY );

        // 8 annual invoices: created 70000, sent 80000, 2 x processing 45000+20000, assessment 20000 sent and 20000 paid, overpaid 12500, no-due-date 10000.
        $this->assertSame( $this->zero( 'annual', [
            'invoices'          => 8,
            'paid_invoices'     => 2,
            'invoiced_cents'    => 277500,
            'collected_cents'   => 65000,   // 30000 part-paid + 20000 assessment + 15000 overpaid
            'outstanding_cents' => 215000,  // 70000 + 50000 + 45000 + 20000 + 20000 + 10000
            'refunded_cents'    => 0,
            'paid_pct'          => 23,      // 65000 / 277500 = 23.4%
            'batch_cents'       => 387500,  // 277500 + 110000 of draft/approved estimates
            'off_stripe_cents'  => 20000,
        ] ), $r['annual'] );
        $this->assertSame( [ 'count' => 2, 'cents' => 20000 ], $r['joins'] );
        $this->assertSame( $this->zero( 'pipeline', [
            'ready_count' => 2, 'ready_cents' => 45000, 'creating' => 1, 'error' => 1,
            'blocked_no_owner_count' => 2, 'blocked_no_owner_cents' => 60000,
        ] ), $r['pipeline'] );
        $this->assertTrue( MyNJILGA_Invoice_Stats::has_annual_rows( $this->aggregate( $this->fixtureRows(), self::YEAR, true, self::TODAY ) ) );
    }

    /** The same table across every year: the receivables strip and the flags. */
    public function testFixtureAllYearsReceivablesAndFlags(): void {
        $r = MyNJILGA_Invoice_Stats::rollup( $this->aggregate( $this->fixtureRows(), null, true, self::TODAY ), self::TODAY );

        $this->assertSame( [
            'outstanding_cents'      => 270000, // 40000 (2026 sent) + 15000 (downgraded) + 215000 (2027)
            'open_count'             => 7,
            'past_due_cents'         => 135000, // 40000 (Aug) + 50000 (due today) + 45000 (due yesterday)
            'past_due_count'         => 3,
            'in_flight_cents'        => 65000,
            'in_flight_count'        => 2,
            'oldest_processing_days' => 9,      // since 2026-09-20
            'downgraded_cents'       => 15000,
            'written_off_cents'      => 70000,  // 20000 uncollectible + 50000 voided — the DUE, not the totals
            'collected_cents'        => 200000, // 20000 (2025) + 95000 (2026) + 65000 + 20000 of joins (2027)
        ], $r['receivables'] );
        $this->assertSame( [ 'open' => 2, 'review' => 3 ], $r['flags'] );
    }

    // -------------------------------------------------------------------
    // Mode isolation — every statement
    // -------------------------------------------------------------------

    /**
     * The GROUP BY, for one year and for every year, in both modes: the
     * mode's predicate is present, the other's is absent, the year is there
     * only when asked for, "today" is bound (twice: count and cents) with an
     * INCLUSIVE comparison, and it never reads a snapshot or SELECT *.
     */
    public function testGroupsSqlScopesToTheModeAsked(): void {
        $this->with_db( $this->db( [] ), function (): void {
            foreach ( [ true, false ] as $live ) {
                $year = MyNJILGA_Invoice_Stats::groups_sql( self::YEAR, $live, self::TODAY );
                $all  = MyNJILGA_Invoice_Stats::groups_sql( null, $live, self::TODAY );

                foreach ( [ $year, $all ] as $sql ) {
                    $this->assertScopedToMode( $live, $sql );
                    $this->assertSqlHas( 'GROUP BY status, invoice_kind', $sql );
                    $this->assertSqlLacks( 'roster_snapshot', $sql );
                    $this->assertSqlLacks( 'SELECT *', $sql );
                    $this->assertSqlHas( "status IN ('created', 'sent', 'processing') AND due_date IS NOT NULL AND due_date <= '2026-09-29'", $sql );
                    $this->assertSame( 2, substr_count( $sql, "due_date <= '2026-09-29'" ) );
                    $this->assertSqlLacks( 'due_date < ', $sql );
                }
                $this->assertSqlHas( 'dues_year = 2027', $year );
                $this->assertSqlLacks( 'dues_year', $all );
            }
        } );
    }

    /**
     * The GROUP BY's CASE expressions restate the rules that the PHP model
     * (aggregate()) only imitates, so each is pinned by its text: the
     * clause a rule turns on must be present. A "no error" row is NULL OR
     * empty; "creating" needs the queue stamp AND no error (an errored row
     * is an Error, never also Creating); "ready" excludes both; an unbilled
     * downgrade has no Stripe invoice id; the processing date is a MIN over
     * processing rows only. And the statement does no arithmetic — the
     * amount columns are INT UNSIGNED, where a subtraction that goes
     * negative is an error in strict MySQL.
     */
    public function testGroupsSqlEncodesTheRules(): void {
        $this->with_db( $this->db( [] ), function (): void {
            $sql = MyNJILGA_Invoice_Stats::groups_sql( self::YEAR, true, self::TODAY );

            $this->assertSqlHas( "SUM(CASE WHEN (last_error IS NOT NULL AND last_error <> '') THEN 1 ELSE 0 END) AS flagged", $sql );
            $this->assertSqlHas( "SUM(CASE WHEN status = 'approved' AND queued_at IS NOT NULL AND (last_error IS NULL OR last_error = '') THEN 1 ELSE 0 END) AS creating", $sql );
            $this->assertSqlHas( "status IN ('draft', 'approved') AND (last_error IS NULL OR last_error = '') AND NOT ( status = 'approved' AND queued_at IS NOT NULL )", $sql );
            $this->assertSqlHas( "status = 'downgraded' AND (gateway_invoice_id IS NULL OR gateway_invoice_id = '')", $sql );
            $this->assertSqlHas( "MIN(CASE WHEN status = 'processing' THEN processing_at END) AS oldest_processing", $sql );
            $this->assertSqlHas( 'SUM(amount_due_cents) AS due', $sql );
            $this->assertSqlLacks( ' - ', $sql );
            $this->assertSqlLacks( 'GREATEST', $sql );
        } );
    }

    /** The exclusion-reason query reads ONLY excluded rows (the only ones whose snapshot it touches), in one mode and one year. */
    public function testExcludedReasonsSqlScopesToTheModeAndOnlyReadsExcludedRows(): void {
        $this->with_db( $this->db( [] ), function (): void {
            foreach ( [ true, false ] as $live ) {
                $sql = MyNJILGA_Invoice_Stats::excluded_reasons_sql( self::YEAR, $live );

                $this->assertScopedToMode( $live, $sql );
                $this->assertSqlHas( 'dues_year = 2027', $sql );
                $this->assertSqlHas( "status = 'excluded'", $sql );
                $this->assertSqlHas( 'GROUP BY invoice_kind', $sql );
                // wp_json_encode writes no spaces; the underscores are LIKE wildcards, so escaped.
                $this->assertSqlHas( 'LIKE \'%"exclusion\\_reason":"excluded\\_no\\_members"%\'', $sql );
                $this->assertSqlHas( 'LIKE \'%"exclusion\\_reason":"excluded\\_zero\\_total"%\'', $sql );
                $this->assertSqlLacks( 'excluded\\_no\\_owner', $sql );
            }
        } );
    }

    /** The year probe is mode-scoped, asks only for annual (non-join) rows, and reads nothing. */
    public function testAnnualProbeSqlScopesToTheModeAndIgnoresJoins(): void {
        $this->with_db( $this->db( [] ), function (): void {
            foreach ( [ true, false ] as $live ) {
                $sql = MyNJILGA_Invoice_Stats::annual_probe_sql( self::YEAR, $live );

                $this->assertScopedToMode( $live, $sql );
                $this->assertSqlHas( 'dues_year = 2027', $sql );
                $this->assertSqlHas( "invoice_kind <> 'join'", $sql );
                $this->assertSqlHas( 'LIMIT 1', $sql );
                $this->assertSqlLacks( 'SELECT *', $sql );
            }
        } );
    }

    /**
     * Every statement build() sends — the probe, the year's GROUP BY, the
     * reason split and the all-years GROUP BY — carries the mode asked for.
     * The fake database throws on a statement with no livemode predicate,
     * and returns only that mode's rows, so a leak would also show up in the
     * numbers below (test mode holds a 999999 invoice live must never see).
     */
    public function testEveryStatementBuildEmitsIsScopedToTheModeAsked(): void {
        foreach ( [ true, false ] as $live ) {
            $db = $this->db( $this->fixtureRows() );
            $s  = $this->with_db( $db, function () use ( $live ): array {
                // No override, so the probe runs too: 4 statements when the year has excluded rows.
                return MyNJILGA_Invoice_Stats::build( $live, true, self::TODAY, null );
            } );
            // The fixture has rows in dues years 2025-2028 only, so the default year (or the
            // fallback) may be empty — what matters is that every statement is scoped.
            $this->assertTrue( count( $db->queries ) >= 3, 'probe + year rollup + all-years rollup at least' );
            foreach ( $db->queries as $sql ) {
                $this->assertScopedToMode( $live, $sql );
            }
            $this->assertSame( $live, $s['live'] );
            $this->assertSame( $live ? 'live' : 'test', $s['mode'] );
            if ( $live ) {
                $this->assertSame( 270000, $s['receivables']['outstanding_cents'] );
            } else {
                $this->assertSame( 999999, $s['receivables']['outstanding_cents'] );
            }
        }
    }

    // -------------------------------------------------------------------
    // build(): the whole path, end to end on the fake database
    // -------------------------------------------------------------------

    /**
     * build() over the fixture for an explicit year: annual, joins and
     * pipeline come from THAT year; receivables and flags from every year.
     * (A build that fed the year's tuples to the receivables would report
     * the 2027 outstanding, not the all-years one.) The numbers are the
     * hand-worked ones from the tests above, arriving as the strings a real
     * $wpdb returns.
     */
    public function testBuildAssemblesYearSectionsAndAllYearsSections(): void {
        $db = $this->db( $this->fixtureRows() );
        $s  = $this->with_db( $db, function (): array {
            return MyNJILGA_Invoice_Stats::build( true, true, self::TODAY, self::YEAR );
        } );

        $this->assertSame( [ 'live', 'mode', 'connected', 'year', 'year_has_rows', 'annual', 'joins', 'receivables', 'pipeline', 'flags' ], array_keys( $s ) );
        $this->assertSame( true, $s['live'] );
        $this->assertSame( 'live', $s['mode'] );
        $this->assertSame( true, $s['connected'] );
        $this->assertSame( self::YEAR, $s['year'] );
        $this->assertSame( true, $s['year_has_rows'] );

        $this->assertSame( 277500, $s['annual']['invoiced_cents'] );
        $this->assertSame( 23, $s['annual']['paid_pct'] );
        $this->assertSame( [ 'count' => 2, 'cents' => 20000 ], $s['joins'] );
        $this->assertSame( 2, $s['pipeline']['ready_count'] );
        $this->assertSame( 2, $s['pipeline']['blocked_no_owner_count'], 'the reason split is merged in' );
        $this->assertSame( 60000, $s['pipeline']['blocked_no_owner_cents'] );

        $this->assertSame( 270000, $s['receivables']['outstanding_cents'], 'all years, not 215000' );
        $this->assertSame( 9, $s['receivables']['oldest_processing_days'] );
        $this->assertSame( [ 'open' => 2, 'review' => 3 ], $s['flags'] );

        // Override year given: no probe. Year A, reason split C, all-years B.
        $this->assertCount( 3, $db->queries );
        $this->assertSqlHas( 'dues_year = 2027', $db->queries[0] );
        $this->assertSqlHas( 'GROUP BY invoice_kind', $db->queries[1] );
        $this->assertSqlLacks( 'dues_year', $db->queries[2] );
    }

    /** Test mode is its own world: one draft, one excluded row the live figures must never include. */
    public function testBuildInTestModeSeesOnlyTestRows(): void {
        $s = $this->with_db( $this->db( $this->fixtureRows() ), function (): array {
            return MyNJILGA_Invoice_Stats::build( false, false, self::TODAY, self::YEAR );
        } );

        $this->assertSame( false, $s['live'] );
        $this->assertSame( 'test', $s['mode'] );
        $this->assertSame( false, $s['connected'] );
        $this->assertSame( 999999, $s['annual']['invoiced_cents'] );
        $this->assertSame( 88888 + 999999, $s['annual']['batch_cents'] );
        $this->assertSame( [ 'count' => 1, 'cents' => 5000 ], $s['joins'] );
        $this->assertSame( 999999, $s['receivables']['outstanding_cents'] );
        $this->assertSame( [ 'open' => 1, 'review' => 0 ], $s['flags'] );
        // The only excluded test row is in 2026, not the year asked for.
        $this->assertSame( 0, $s['pipeline']['blocked_no_owner_count'] );
    }

    /** With no excluded row in the year, the reason query is not run at all. */
    public function testReasonQueryIsSkippedWhenNothingIsExcluded(): void {
        $db = $this->db( [ $this->row( [ 'status' => 'sent', 'total_amount_cents' => 100, 'amount_due_cents' => 100 ] ) ] );
        $this->with_db( $db, function (): void {
            MyNJILGA_Invoice_Stats::build( true, true, self::TODAY, self::YEAR );
        } );

        $this->assertCount( 2, $db->queries );
        foreach ( $db->queries as $sql ) {
            $this->assertSqlLacks( 'roster_snapshot', $sql );
        }
    }

    /** The empty database — no table rows at all — is a snapshot of zeros with year_has_rows false, not an error. */
    public function testBuildOnAnEmptyDatabaseIsAllZeros(): void {
        $s = $this->with_db( $this->db( [] ), function (): array {
            return MyNJILGA_Invoice_Stats::build( true, false, self::TODAY, null );
        } );

        $this->assertSame( MyNJILGA_Invoicing::current_dues_year(), $s['year'], 'nothing in the default year, so the current one' );
        $this->assertSame( false, $s['year_has_rows'] );
        $this->assertSame( $this->zero( 'annual' ), $s['annual'] );
        $this->assertSame( $this->zero( 'joins' ), $s['joins'] );
        $this->assertSame( $this->zero( 'receivables' ), $s['receivables'] );
        $this->assertSame( $this->zero( 'pipeline' ), $s['pipeline'] );
        $this->assertSame( $this->zero( 'flags' ), $s['flags'] );
    }

    /** A year holding only online joins is EMPTY to Invoicing, so year_has_rows is false — and the joins are still reported. */
    public function testAYearOfOnlyJoinsHasNoRowsButStillReportsTheJoins(): void {
        $rows = [ $this->row( [ 'invoice_kind' => 'join', 'status' => 'paid', 'total_amount_cents' => 12500, 'amount_paid_cents' => 12500 ] ) ];
        $s    = $this->with_db( $this->db( $rows ), function (): array {
            return MyNJILGA_Invoice_Stats::build( true, true, self::TODAY, self::YEAR );
        } );

        $this->assertSame( false, $s['year_has_rows'] );
        $this->assertSame( [ 'count' => 1, 'cents' => 12500 ], $s['joins'] );
        $this->assertSame( 0, $s['annual']['invoices'] );
    }

    // -------------------------------------------------------------------
    // dashboard_year()
    // -------------------------------------------------------------------

    /** A ?dues_year= inside 2000..2100 wins outright and costs no query. */
    public function testDashboardYearHonoursAValidOverrideWithoutAQuery(): void {
        $db = $this->db( [] );
        $this->with_db( $db, function () use ( $db ): void {
            $this->assertSame( 2031, MyNJILGA_Invoice_Stats::dashboard_year( true, 2031 ) );
            $this->assertSame( 2000, MyNJILGA_Invoice_Stats::dashboard_year( false, 2000 ) );
            $this->assertSame( 2100, MyNJILGA_Invoice_Stats::dashboard_year( true, 2100 ) );
            $this->assertCount( 0, $db->queries );
        } );
    }

    /** An override outside Invoicing's window is ignored, exactly as Invoicing ignores it. */
    public function testDashboardYearIgnoresAnOutOfRangeOverride(): void {
        $db = $this->db( [] );
        $this->with_db( $db, function (): void {
            foreach ( [ 1999, 2101, 0, -2027, 27 ] as $bad ) {
                $this->assertSame( MyNJILGA_Invoicing::current_dues_year(), MyNJILGA_Invoice_Stats::dashboard_year( true, $bad ), "override $bad" );
            }
        } );
        $this->assertCount( 5, $db->queries, 'each fell through to the probe' );
    }

    /** Next year is the default, but only once it holds an annual row in THIS mode. */
    public function testDashboardYearDefaultsToNextYearOnceItHasRows(): void {
        $next = MyNJILGA_Invoicing::default_dues_year();
        $rows = [ $this->row( [ 'dues_year' => $next, 'status' => 'draft', 'gateway_invoice_id' => null ] ) ];

        $this->with_db( $this->db( $rows ), function () use ( $next ): void {
            $this->assertSame( $next, MyNJILGA_Invoice_Stats::dashboard_year( true, null ) );
        } );
    }

    /** No annual rows in next year — none at all, or only online joins, or only the OTHER mode's — falls back to the current year. */
    public function testDashboardYearFallsBackToTheCurrentYear(): void {
        $next    = MyNJILGA_Invoicing::default_dues_year();
        $current = MyNJILGA_Invoicing::current_dues_year();

        $cases = [
            'empty table'         => [],
            'only online joins'   => [ $this->row( [ 'dues_year' => $next, 'invoice_kind' => 'join', 'status' => 'paid' ] ) ],
            'only the other mode' => [ $this->row( [ 'dues_year' => $next, 'livemode' => 0 ] ) ],
            'only another year'   => [ $this->row( [ 'dues_year' => $next + 1 ] ) ],
        ];
        foreach ( $cases as $name => $rows ) {
            $this->with_db( $this->db( $rows ), function () use ( $name, $current ): void {
                $this->assertSame( $current, MyNJILGA_Invoice_Stats::dashboard_year( true, null ), $name );
            } );
        }

        // And the same rows in the mode that DOES have the row give next year.
        $this->with_db( $this->db( $cases['only the other mode'] ), function () use ( $next ): void {
            $this->assertSame( $next, MyNJILGA_Invoice_Stats::dashboard_year( false, null ) );
        } );
    }

    /** Drafts and excluded rows are still a batch to show, so they count as "has rows". */
    public function testDashboardYearCountsDraftAndExcludedRowsAsData(): void {
        $next = MyNJILGA_Invoicing::default_dues_year();
        foreach ( [ 'draft', 'excluded' ] as $status ) {
            $rows = [ $this->row( [ 'dues_year' => $next, 'status' => $status, 'gateway_invoice_id' => null ] ) ];
            $this->with_db( $this->db( $rows ), function () use ( $next, $status ): void {
                $this->assertSame( $next, MyNJILGA_Invoice_Stats::dashboard_year( true, null ), $status );
            } );
        }
    }

    /** valid_year(): Invoicing's own window, inclusive at both ends. */
    public function testValidYearWindow(): void {
        $this->assertSame( 2000, MyNJILGA_Invoice_Stats::valid_year( 2000 ) );
        $this->assertSame( 2100, MyNJILGA_Invoice_Stats::valid_year( 2100 ) );
        $this->assertSame( null, MyNJILGA_Invoice_Stats::valid_year( 1999 ) );
        $this->assertSame( null, MyNJILGA_Invoice_Stats::valid_year( 2101 ) );
        $this->assertSame( null, MyNJILGA_Invoice_Stats::valid_year( null ) );
    }

    // -------------------------------------------------------------------
    // Drift guards
    // -------------------------------------------------------------------

    /**
     * The status sets are derived from ONE definition of what the ledger
     * and Aging mean. The ledger scope must stay Payments' own; "invoiced"
     * is that minus voided; "open" is that minus the ledger's terminal
     * statuses; and every status the table can hold is classified for flags
     * exactly once (excluded is never flagged), so adding a status to the
     * table without deciding where its errors go fails here.
     */
    public function testStatusSetsCannotDriftFromThePagesTheyMirror(): void {
        $sorted = static function ( array $a ): array {
            sort( $a );
            return $a;
        };
        $T = 'MyNJILGA_Dues_Invoice_Table';

        $this->assertSame( $sorted( MyNJILGA_Page_Payments::RELEVANT_STATUSES ), $sorted( MyNJILGA_Invoice_Stats::LEDGER_STATUSES ) );
        $this->assertSame(
            $sorted( array_values( array_diff( MyNJILGA_Invoice_Stats::LEDGER_STATUSES, [ $T::STATUS_VOIDED ] ) ) ),
            $sorted( MyNJILGA_Invoice_Stats::INVOICED_STATUSES )
        );
        $this->assertSame(
            $sorted( array_values( array_diff( MyNJILGA_Invoice_Stats::LEDGER_STATUSES, MyNJILGA_Ledger_Totals::TERMINAL_STATUSES ) ) ),
            $sorted( MyNJILGA_Invoice_Stats::OPEN_STATUSES )
        );

        $flagged = array_merge( MyNJILGA_Invoice_Stats::FLAG_OPEN_STATUSES, MyNJILGA_Invoice_Stats::FLAG_REVIEW_STATUSES );
        $this->assertSame( $sorted( array_values( array_diff( $T::ALL_STATUSES, [ $T::STATUS_EXCLUDED ] ) ) ), $sorted( $flagged ) );
        $this->assertSame( count( $flagged ), count( array_unique( $flagged ) ), 'no status is both an open and a review flag' );
    }
}
