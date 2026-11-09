<?php
/**
 * When a row's "queued" mark stops meaning "a job has this"
 * (MyNJILGA_Invoice_Creator::is_actively_queued).
 *
 * Action Scheduler marks a chunk that dies mid-run as failed and never
 * retries it. The rows it hadn't reached kept queued_at forever: the
 * Invoicing page showed "Creating…" with no button and schedule() skipped
 * them as already queued, so they could never be created. A queued mark
 * older than an hour is treated as dropped.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-creator.php';

class InvoiceQueueTest extends NJILGA_TestCase {

    private const NOW = '2026-11-09 15:00:00';

    public function testNeverQueuedIsNotQueued(): void {
        $this->assertFalse( MyNJILGA_Invoice_Creator::is_actively_queued( '', self::NOW ) );
        $this->assertFalse( MyNJILGA_Invoice_Creator::is_actively_queued( '0000-00-00 00:00:00', self::NOW ) );
    }

    public function testJustQueuedIsQueued(): void {
        $this->assertTrue( MyNJILGA_Invoice_Creator::is_actively_queued( '2026-11-09 14:59:30', self::NOW ) );
    }

    /** A long bulk run: the last chunk legitimately waits behind the others, so a few minutes must not look dropped. */
    public function testASlowButLiveBulkRunIsStillQueued(): void {
        $this->assertTrue( MyNJILGA_Invoice_Creator::is_actively_queued( '2026-11-09 14:20:00', self::NOW ) );
        $this->assertTrue( MyNJILGA_Invoice_Creator::is_actively_queued( '2026-11-09 14:00:01', self::NOW ) );
    }

    /** The bug: a chunk was killed hours ago and the row has said "Creating…" ever since. */
    public function testAnOldQueuedMarkIsTreatedAsDropped(): void {
        $this->assertFalse( MyNJILGA_Invoice_Creator::is_actively_queued( '2026-11-09 14:00:00', self::NOW ), 'exactly an hour' );
        $this->assertFalse( MyNJILGA_Invoice_Creator::is_actively_queued( '2026-11-09 09:00:00', self::NOW ) );
        $this->assertFalse( MyNJILGA_Invoice_Creator::is_actively_queued( '2026-11-02 09:00:00', self::NOW ) );
    }

    /** An unreadable timestamp is left alone — better a stuck label than a double-queued row. */
    public function testAnUnreadableTimestampIsLeftAlone(): void {
        $this->assertTrue( MyNJILGA_Invoice_Creator::is_actively_queued( 'not a date', self::NOW ) );
    }
}
