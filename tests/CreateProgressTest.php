<?php
/**
 * Progress of a background invoice-creation run
 * (MyNJILGA_Create_Progress::progress()), read from the run's rows as they
 * stand — what the Invoicing page's progress card shows while "Create 166
 * Invoices" works through its Action Scheduler chunks.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-creator.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-create-progress.php';

class CreateProgressTest extends NJILGA_TestCase {

    const NOW = '2027-01-05 10:00:00';

    private function row( int $id, string $status, array $over = [] ): object {
        return (object) ( $over + [ 'id' => $id, 'status' => $status, 'last_error' => null, 'queued_at' => null ] );
    }

    public function testCountsEachRowOnce(): void {
        $rows = [
            $this->row( 1, 'created' ),
            $this->row( 2, 'sent' ),
            $this->row( 3, 'approved', [ 'queued_at' => '2027-01-05 09:58:00' ] ),            // still queued
            $this->row( 4, 'approved', [ 'last_error' => 'Card declined' ] ),                // failed
            $this->row( 5, 'approved', [ 'queued_at' => '2027-01-05 07:00:00' ] ),            // job died
            $this->row( 6, 'draft', [ 'last_error' => 'Re-price: paid by online join' ] ),   // sent back
            $this->row( 7, 'created' ),                                                      // not in this run
        ];
        $p = MyNJILGA_Create_Progress::progress( $rows, [ 1, 2, 3, 4, 5, 6, 99 ], self::NOW );

        $this->assertSame( 6, $p['total'], 'a row deleted since (99) is left out' );
        $this->assertSame( 2, $p['done'] );
        $this->assertSame( 1, $p['pending'] );
        $this->assertSame( 2, $p['failed'] );
        $this->assertSame( 1, $p['stalled'] );
        $this->assertFalse( $p['finished'] );
    }

    public function testFinishedOnceNothingIsQueued(): void {
        $p = MyNJILGA_Create_Progress::progress( [ $this->row( 1, 'created' ), $this->row( 2, 'approved', [ 'last_error' => 'x' ] ) ], [ 1, 2 ], self::NOW );
        $this->assertTrue( $p['finished'] );
        $this->assertSame( '1 of 2 created · 1 failed', MyNJILGA_Create_Progress::counts( $p ) );
        $this->assertTrue( strpos( MyNJILGA_Create_Progress::summary( $p ), '1 could not be created' ) !== false );
    }

    public function testAnInvoiceAlreadyFurtherAlongCountsAsCreated(): void {
        $rows = [];
        foreach ( [ 'processing', 'paid', 'downgraded', 'voided', 'uncollectible' ] as $i => $status ) {
            $rows[] = $this->row( $i + 1, $status );
        }
        $p = MyNJILGA_Create_Progress::progress( $rows, [ 1, 2, 3, 4, 5 ], self::NOW );
        $this->assertSame( 5, $p['done'] );
        $this->assertSame( '5 of 5 created', MyNJILGA_Create_Progress::counts( $p ) );
    }
}
