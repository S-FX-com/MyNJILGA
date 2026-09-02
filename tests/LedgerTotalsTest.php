<?php
/**
 * Unit tests for MyNJILGA_Ledger_Totals — the pure arithmetic behind the
 * Payments ledger's five stat cards and its Aging view (spec: Stripe
 * migration phase 4, "LedgerTotalsTest").
 *
 * The class is a pure function of the line arrays
 * MyNJILGA_Page_Payments::to_line() builds from njilga_dues_invoices
 * rows, so the fixtures below are exactly that shape — a row's
 * total_amount_cents / amount_paid_cents / amount_due_cents as
 * total/paid/due, plus the ageBucket to_line() derived from due_date.
 * Everything else on a real line is display detail the arithmetic never
 * reads, so it is left out.
 *
 * Money is in cents throughout, as it is everywhere in this plugin.
 */
declare( strict_types=1 );

class LedgerTotalsTest extends NJILGA_TestCase {

    // -------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------

    /**
     * One ledger line. Defaults are a zero-value, never-billed row; each
     * test overrides only the fields whose arithmetic it is pinning.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function line( array $overrides = [] ): array {
        return array_merge( [
            'id'        => 1,
            'status'    => MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
            'total'     => 0,
            'paid'      => 0,
            'due'       => 0,
            'ageBucket' => '',
        ], $overrides );
    }

    /** @return array<int,int> line ids in a bucket, in the order they were placed */
    private function bucket_ids( array $aging, string $bucket ): array {
        return array_map(
            static function ( array $l ): int {
                return (int) $l['id'];
            },
            $aging['buckets'][ $bucket ]['lines']
        );
    }

    // -------------------------------------------------------------------
    // Stat cards
    // -------------------------------------------------------------------

    /**
     * Every status that can reach the ledger, in one set: Outstanding
     * counts every balance that wasn't written off (the DOWNGRADED row's
     * included — never collected, never closed out in Stripe), Collected
     * counts money in whatever the status, In Flight is the ACH subset,
     * Past Due is whatever carries a real age bucket, and Written Off is
     * voided + uncollectible, read as the balance still owed.
     */
    public function testMixedStatusesAcrossOneSet(): void {
        $lines = [
            $this->line( [ 'id' => 1, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,       'total' => 12500, 'due' => 12500, 'ageBucket' => 'notyet' ] ),
            $this->line( [ 'id' => 2, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_SENT,          'total' => 20000, 'due' => 20000, 'ageBucket' => '31-60' ] ),
            $this->line( [ 'id' => 3, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,    'total' => 30000, 'due' => 30000, 'ageBucket' => '0-30' ] ),
            $this->line( [ 'id' => 4, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_PAID,          'total' => 42500, 'paid' => 42500 ] ),
            $this->line( [ 'id' => 5, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,        'total' => 50000, 'paid' => 10000, 'due' => 40000 ] ),
            $this->line( [ 'id' => 6, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE, 'total' => 15000, 'due' => 15000 ] ),
            $this->line( [ 'id' => 7, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED,    'total' => 7500,  'due' => 7500 ] ),
        ];

        $stats = MyNJILGA_Ledger_Totals::stats( $lines );

        $this->assertSame( 70000, $stats['outstandingCents'] ); // 12500 + 20000 + 30000 + 7500 — the two write-offs excluded
        $this->assertSame( 52500, $stats['collectedCents'] );   // 42500 paid + the 10000 collected before row 5 was voided
        $this->assertSame( 30000, $stats['inFlightCents'] );    // the processing row only
        $this->assertSame( 50000, $stats['pastDueCents'] );     // 31-60 + 0-30; 'notyet' and the bucketless rows don't count
        $this->assertSame( 55000, $stats['writtenOffCents'] );  // 40000 balance lost + 15000 — not row 5's 50000 total
    }

    /**
     * A partly paid invoice is in BOTH pots at once: the money that came
     * in under Collected, the balance that didn't under Outstanding —
     * never the whole invoice on one side.
     */
    public function testPartialPaymentCountsAsCollectedAndOutstanding(): void {
        $lines = [
            $this->line( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_SENT, 'total' => 42500, 'paid' => 20000, 'due' => 22500, 'ageBucket' => '0-30' ] ),
        ];

        $stats = MyNJILGA_Ledger_Totals::stats( $lines );

        $this->assertSame( 20000, $stats['collectedCents'] );
        $this->assertSame( 22500, $stats['outstandingCents'] );
        $this->assertSame( 22500, $stats['pastDueCents'] );
        $this->assertSame( 0, $stats['writtenOffCents'] );
        // The two halves account for the invoice exactly once between them.
        $this->assertSame( 42500, $stats['collectedCents'] + $stats['outstandingCents'] );
    }

    /**
     * A refund lands as a reduced amount_paid and a restored balance on
     * the row (the reconciler rewrites both from Stripe). Collected falls
     * by the refunded amount; Outstanding reads each row's OWN balance,
     * so a row refunded past its invoice total — an overpayment handed
     * back — contributes zero rather than a negative, and can't eat into
     * another firm's outstanding balance.
     */
    public function testRefundReducesCollectedWithoutNegativeOutstanding(): void {
        $lines = [
            // $500 invoice, paid in full, $200 refunded — back to a live balance.
            $this->line( [ 'id' => 1, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_SENT, 'total' => 50000, 'paid' => 30000, 'due' => 20000, 'ageBucket' => '0-30' ] ),
            // Fully paid, nothing refunded — settled.
            $this->line( [ 'id' => 2, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_PAID, 'total' => 50000, 'paid' => 50000 ] ),
            // Overpaid: more money came in than the invoice was ever for.
            $this->line( [ 'id' => 3, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_PAID, 'total' => 12500, 'paid' => 15000 ] ),
        ];

        $stats = MyNJILGA_Ledger_Totals::stats( $lines );

        $this->assertSame( 95000, $stats['collectedCents'] );
        // 20000 flat — NOT total-minus-paid summed, which the overpaid row
        // would drag down to 17500.
        $this->assertSame( 20000, $stats['outstandingCents'] );
        $this->assertSame( 0, $stats['writtenOffCents'] );
    }

    /**
     * THE DOUBLE-COUNT GUARD. An invoice partly paid and then voided
     * writes off only the balance that was still outstanding when it was
     * voided — reading total_amount_cents here would count the $200
     * already collected a second time. Written Off + Collected is the
     * invoice, once.
     */
    public function testVoidedAfterPartialPaymentWritesOffOnlyTheUnpaidBalance(): void {
        $lines = [
            $this->line( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED, 'total' => 50000, 'paid' => 20000, 'due' => 30000 ] ),
        ];

        $stats = MyNJILGA_Ledger_Totals::stats( $lines );

        $this->assertSame( 20000, $stats['collectedCents'] );
        $this->assertSame( 30000, $stats['writtenOffCents'] ); // the balance lost, not the 50000 invoice
        $this->assertSame( 0, $stats['outstandingCents'] );    // written off — nothing left to chase
        $this->assertSame( 50000, $stats['collectedCents'] + $stats['writtenOffCents'] );

        // An uncollectible row follows the same rule.
        $uncollectible = MyNJILGA_Ledger_Totals::stats( [
            $this->line( [ 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE, 'total' => 50000, 'paid' => 20000, 'due' => 30000 ] ),
        ] );
        $this->assertSame( 30000, $uncollectible['writtenOffCents'] );
    }

    // -------------------------------------------------------------------
    // Aging
    // -------------------------------------------------------------------

    /**
     * Every bucket populated: each keeps its own lines and subtotal, the
     * grand total is the sum of the five, a row with no due date on file
     * falls into "Not Yet Due" rather than being dropped, and terminal
     * rows (paid/voided/uncollectible/downgraded) never age at all.
     */
    public function testEveryAgingBucketSubtotalsAndGrandTotal(): void {
        $lines = [
            $this->line( [ 'id' => 1, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,       'total' => 10000, 'due' => 10000, 'ageBucket' => 'notyet' ] ),
            $this->line( [ 'id' => 2, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_SENT,          'total' => 20000, 'due' => 20000, 'ageBucket' => '0-30' ] ),
            $this->line( [ 'id' => 3, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,    'total' => 30000, 'due' => 30000, 'ageBucket' => '31-60' ] ),
            $this->line( [ 'id' => 4, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_SENT,          'total' => 40000, 'due' => 40000, 'ageBucket' => '61-90' ] ),
            $this->line( [ 'id' => 5, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_SENT,          'total' => 50000, 'due' => 50000, 'ageBucket' => '90+' ] ),
            $this->line( [ 'id' => 6, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,       'total' => 5000,  'due' => 5000 ] ), // no due date on file
            $this->line( [ 'id' => 7, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_PAID,          'total' => 60000, 'paid' => 60000 ] ),
            $this->line( [ 'id' => 8, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,        'total' => 70000, 'due' => 70000 ] ),
            $this->line( [ 'id' => 9, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE, 'total' => 80000, 'due' => 80000 ] ),
            $this->line( [ 'id' => 10, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED,   'total' => 90000, 'due' => 90000 ] ),
        ];

        $aging = MyNJILGA_Ledger_Totals::aging_buckets( $lines );

        // Fixed set, fixed order — the Aging view renders sections from it.
        $this->assertSame( [ 'notyet', '0-30', '31-60', '61-90', '90+' ], array_keys( $aging['buckets'] ) );
        $this->assertSame( 'Not Yet Due', $aging['buckets']['notyet']['label'] );
        $this->assertSame( '90+ Days', $aging['buckets']['90+']['label'] );

        $this->assertSame( 15000, $aging['buckets']['notyet']['subtotalCents'] ); // 10000 + the dateless 5000
        $this->assertSame( [ 1, 6 ], $this->bucket_ids( $aging, 'notyet' ) );
        $this->assertSame( 20000, $aging['buckets']['0-30']['subtotalCents'] );
        $this->assertSame( 30000, $aging['buckets']['31-60']['subtotalCents'] );
        $this->assertSame( 40000, $aging['buckets']['61-90']['subtotalCents'] );
        $this->assertSame( 50000, $aging['buckets']['90+']['subtotalCents'] );
        $this->assertCount( 1, $aging['buckets']['90+']['lines'] );

        $this->assertSame( 155000, $aging['grandTotalCents'] );
        $this->assertSame( 6, MyNJILGA_Ledger_Totals::outstanding_count( $aging ) );

        // The four terminal rows are in no bucket at all.
        $bucketed = [];
        foreach ( $aging['buckets'] as $b ) {
            foreach ( $b['lines'] as $l ) {
                $bucketed[] = (int) $l['id'];
            }
        }
        $this->assertSame( [ 1, 6, 2, 3, 4, 5 ], $bucketed );
    }

    /**
     * An unrecognised bucket key (a line built by an older version of
     * to_line(), say) is still counted — under "Not Yet Due" — rather
     * than silently dropped out of the grand total.
     */
    public function testUnknownBucketFallsBackToNotYetDue(): void {
        $aging = MyNJILGA_Ledger_Totals::aging_buckets( [
            $this->line( [ 'id' => 1, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_SENT, 'total' => 12500, 'due' => 12500, 'ageBucket' => '120+' ] ),
        ] );

        $this->assertSame( [ 'notyet', '0-30', '31-60', '61-90', '90+' ], array_keys( $aging['buckets'] ) );
        $this->assertSame( 12500, $aging['buckets']['notyet']['subtotalCents'] );
        $this->assertSame( 12500, $aging['grandTotalCents'] );
    }

    // -------------------------------------------------------------------
    // Degenerate input and the contract that keeps this class testable
    // -------------------------------------------------------------------

    /**
     * No lines is a real state (a fresh Stripe mode with nothing billed
     * yet): every figure is 0, every bucket is present and empty, and
     * nothing emits a PHP notice — including for a line array that is
     * missing keys entirely.
     */
    public function testEmptyInputIsAllZerosAndSilent(): void {
        set_error_handler( static function ( int $errno, string $errstr ): bool {
            throw new NJILGA_Assertion_Failed( 'PHP diagnostic raised: ' . $errstr );
        } );

        try {
            $stats = MyNJILGA_Ledger_Totals::stats( [] );
            $aging = MyNJILGA_Ledger_Totals::aging_buckets( [] );
            // A line with none of the keys the arithmetic reads.
            $bare  = MyNJILGA_Ledger_Totals::stats( [ [] ] );
        } finally {
            restore_error_handler();
        }

        $this->assertSame( [
            'outstandingCents' => 0,
            'collectedCents'   => 0,
            'inFlightCents'    => 0,
            'pastDueCents'     => 0,
            'writtenOffCents'  => 0,
        ], $stats );
        $this->assertSame( $stats, $bare );

        $this->assertSame( 0, $aging['grandTotalCents'] );
        $this->assertSame( 0, MyNJILGA_Ledger_Totals::outstanding_count( $aging ) );
        $this->assertCount( 5, $aging['buckets'] );
        foreach ( $aging['buckets'] as $key => $bucket ) {
            $this->assertCount( 0, $bucket['lines'], "Bucket $key should be empty" );
            $this->assertSame( 0, $bucket['subtotalCents'], "Bucket $key subtotal" );
        }
    }

    /** Neither entry point mutates its argument, and both are idempotent. */
    public function testIsPureAndIdempotent(): void {
        $lines = [
            $this->line( [ 'id' => 1, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_SENT,   'total' => 12500, 'paid' => 2500, 'due' => 10000, 'ageBucket' => '0-30' ] ),
            $this->line( [ 'id' => 2, 'status' => MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED, 'total' => 7500,  'due' => 7500 ] ),
        ];
        $copy = $lines;

        $this->assertSame( MyNJILGA_Ledger_Totals::stats( $lines ), MyNJILGA_Ledger_Totals::stats( $lines ) );
        $this->assertSame( MyNJILGA_Ledger_Totals::aging_buckets( $lines ), MyNJILGA_Ledger_Totals::aging_buckets( $lines ) );
        $this->assertSame( $copy, $lines );
    }

    /**
     * DRIFT GUARD. MyNJILGA_Ledger_Totals inlines its status literals so
     * it depends on no other class; this pins those literals to the
     * MyNJILGA_Dues_Invoice_Table::STATUS_* values the rows actually
     * carry, so renaming a status there can't quietly leave the ledger
     * arithmetic matching nothing.
     */
    public function testStatusSetsMatchTheInvoiceTablesStatuses(): void {
        $this->assertSame( MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING, MyNJILGA_Ledger_Totals::STATUS_PROCESSING );
        $this->assertSame( [
            MyNJILGA_Dues_Invoice_Table::STATUS_PAID,
            MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
            MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE,
            MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED,
        ], MyNJILGA_Ledger_Totals::TERMINAL_STATUSES );
        $this->assertSame( [
            MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
            MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE,
        ], MyNJILGA_Ledger_Totals::WRITEOFF_STATUSES );
    }
}
