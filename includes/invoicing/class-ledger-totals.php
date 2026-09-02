<?php
/**
 * Payments-ledger arithmetic (Stripe migration phase 4) — a PURE class,
 * for the same reason MyNJILGA_Pricing_Engine is one. Ledger lines in,
 * plain totals out: no database, no WordPress, no globals, no other
 * plugin class. Everything it needs arrives as plain arrays, which is
 * what makes it unit-testable without a WordPress install
 * (see tests/LedgerTotalsTest.php).
 *
 * Input: the "line" arrays MyNJILGA_Page_Payments::to_line() builds, one
 * per njilga_dues_invoices row already scoped to the active Stripe mode.
 * Only these four keys are read here (the rest of the line is display
 * detail, and aging_buckets() carries whole lines through untouched):
 *   [ 'status'    => string, one of the STATUS_* values below,
 *     'paid'      => int, amount_paid_cents,
 *     'due'       => int, amount_due_cents (the balance still owed),
 *     'ageBucket' => string, '' | notyet | 0-30 | 31-60 | 61-90 | 90+ ]
 *
 * The five stat-card figures, and the rule each one encodes:
 *
 *   1. Outstanding — every line's still-unpaid balance EXCEPT the ones
 *      written off. A downgraded row's balance still counts: it was
 *      never collected and never formally closed out in Stripe.
 *   2. Collected — every line's amount_paid, whatever its status. A
 *      partly-paid invoice therefore lands in BOTH Collected (what came
 *      in) and Outstanding (what didn't), which is the point.
 *   3. In Flight — the balance on ACH-in-flight (processing) rows. A
 *      subset of Outstanding, not a separate pot.
 *   4. Past Due — the balance on rows that are past their due date.
 *      to_line() only ever sets a non-empty, non-'notyet' ageBucket on a
 *      row that is BOTH past due AND non-terminal, so the bucket alone
 *      is the whole past-due test.
 *   5. Written Off — voided/uncollectible rows, read as amount_due
 *      (the balance STILL outstanding at the moment of writing off), not
 *      total_amount. A firm that paid part of an invoice before it was
 *      voided already had that portion counted under Collected, and
 *      reading the total here would double-count it. amount_due is
 *      exactly the money actually lost. This is deliberate — a plausible
 *      "obvious" fix (reading the invoice total) silently breaks it.
 *
 * Aging works off the same lines: terminal rows are settled one way or
 * another and never age, everything else falls in the bucket to_line()
 * computed, and a row with no due date on file ('') is treated as not
 * yet due rather than dropped. Every bucket is always present in
 * AGING_BUCKET_LABELS order, empty ones included, so callers can render
 * a stable set of sections.
 */
class MyNJILGA_Ledger_Totals {

    /**
     * Status literals, inlined so this class depends on nothing. They
     * mirror MyNJILGA_Dues_Invoice_Table::STATUS_* exactly — a drift
     * guard in tests/LedgerTotalsTest.php pins the two together.
     */
    const STATUS_PAID          = 'paid';
    const STATUS_PROCESSING    = 'processing';
    const STATUS_VOIDED        = 'voided';
    const STATUS_UNCOLLECTIBLE = 'uncollectible';
    const STATUS_DOWNGRADED    = 'downgraded';

    /** Statuses where the balance is settled one way or another — nothing left to collect, ever. */
    const TERMINAL_STATUSES = [
        self::STATUS_PAID,
        self::STATUS_VOIDED,
        self::STATUS_UNCOLLECTIBLE,
        self::STATUS_DOWNGRADED,
    ];

    /** Written off in Stripe — distinct from a live, still-collectible balance. */
    const WRITEOFF_STATUSES = [
        self::STATUS_VOIDED,
        self::STATUS_UNCOLLECTIBLE,
    ];

    const AGING_BUCKET_LABELS = [
        'notyet' => 'Not Yet Due',
        '0-30'   => '0–30 Days',
        '31-60'  => '31–60 Days',
        '61-90'  => '61–90 Days',
        '90+'    => '90+ Days',
    ];

    /**
     * The five stat-card figures for a set of lines, in cents. Also
     * recomputed client-side by MyNJILGA_Page_Payments::scripts() from
     * the identical predicate, so a toolbar filter narrows the cards and
     * the table together — keep the two in step.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array{outstandingCents:int,collectedCents:int,inFlightCents:int,pastDueCents:int,writtenOffCents:int}
     */
    public static function stats( array $lines ): array {
        $outstanding = 0;
        $collected   = 0;
        $inFlight    = 0;
        $pastDue     = 0;
        $writtenOff  = 0;

        foreach ( $lines as $l ) {
            $status     = (string) ( $l['status'] ?? '' );
            $due        = (int) ( $l['due'] ?? 0 );
            $paid       = (int) ( $l['paid'] ?? 0 );
            $bucket     = (string) ( $l['ageBucket'] ?? '' );
            $isWriteOff = in_array( $status, self::WRITEOFF_STATUSES, true );

            if ( ! $isWriteOff ) {
                $outstanding += $due;
            }
            $collected += $paid;
            if ( $status === self::STATUS_PROCESSING ) {
                $inFlight += $due;
            }
            if ( $bucket !== '' && $bucket !== 'notyet' ) {
                $pastDue += $due;
            }
            if ( $isWriteOff ) {
                // amount_due, never the invoice total — see the class docblock.
                $writtenOff += $due;
            }
        }

        return [
            'outstandingCents' => $outstanding,
            'collectedCents'   => $collected,
            'inFlightCents'    => $inFlight,
            'pastDueCents'     => $pastDue,
            'writtenOffCents'  => $writtenOff,
        ];
    }

    /**
     * Outstanding lines (status NOT IN paid/voided/uncollectible/
     * downgraded) grouped into the fixed age buckets, each with its own
     * subtotal, plus the grand total across all of them.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array{buckets:array<string,array{label:string,lines:array<int,array<string,mixed>>,subtotalCents:int}>,grandTotalCents:int}
     */
    public static function aging_buckets( array $lines ): array {
        $buckets = [];
        foreach ( self::AGING_BUCKET_LABELS as $key => $label ) {
            $buckets[ $key ] = [ 'label' => $label, 'lines' => [], 'subtotalCents' => 0 ];
        }

        $grand = 0;
        foreach ( $lines as $l ) {
            if ( in_array( (string) ( $l['status'] ?? '' ), self::TERMINAL_STATUSES, true ) ) {
                continue; // Settled one way or another — not an aging concern.
            }
            $bucket = (string) ( $l['ageBucket'] ?? '' );
            if ( $bucket === '' || ! isset( $buckets[ $bucket ] ) ) {
                $bucket = 'notyet'; // No due date on file (or an unknown bucket) — not yet due rather than dropped.
            }
            $due = (int) ( $l['due'] ?? 0 );

            $buckets[ $bucket ]['lines'][]        = $l;
            $buckets[ $bucket ]['subtotalCents'] += $due;
            $grand                               += $due;
        }

        return [ 'buckets' => $buckets, 'grandTotalCents' => $grand ];
    }

    /**
     * How many lines aging_buckets() actually placed — the count behind
     * the Aging tab's badge.
     *
     * @param array{buckets:array<string,array<string,mixed>>,grandTotalCents:int} $aging
     */
    public static function outstanding_count( array $aging ): int {
        $n = 0;
        foreach ( (array) ( $aging['buckets'] ?? [] ) as $b ) {
            $n += count( (array) ( $b['lines'] ?? [] ) );
        }
        return $n;
    }
}
