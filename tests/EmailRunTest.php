<?php
/**
 * Emailing invoices from Invoicing's "Generate emails" (MyNJILGA_Email_Run):
 * which invoices can go, that none is ever emailed twice, and the progress
 * the page shows while the batch sends in the background.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-creator.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-email-run.php';

class EmailRunTest extends NJILGA_TestCase {

    const SINCE = '2026-11-20 09:00:00';
    const NOW   = '2026-11-20 09:05:00';

    private function row( int $id, string $status, array $over = [] ): object {
        return (object) ( $over + [
            'id' => $id, 'dues_year' => 2027, 'livemode' => 1, 'invoice_kind' => 'combined', 'status' => $status,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/' . $id, 'sent_at' => null, 'queued_at' => null, 'last_error' => null,
            'total_amount_cents' => 12500,
        ] );
    }

    public function testOnlyCreatedOrSentInvoicesOfThisYearAndModeWithALinkCanBeEmailed(): void {
        $this->assertSame( '', MyNJILGA_Email_Run::refusal( $this->row( 1, 'created' ), 2027, true ) );
        $this->assertSame( '', MyNJILGA_Email_Run::refusal( $this->row( 2, 'sent' ), 2027, true ) );
        $cases = [
            'paid'             => $this->row( 3, 'paid' ),
            'a draft'          => $this->row( 4, 'draft', [ 'hosted_invoice_url' => '' ] ),
            'voided'           => $this->row( 5, 'voided' ),
            'no payment link'  => $this->row( 6, 'created', [ 'hosted_invoice_url' => '' ] ),
            'an online join'   => $this->row( 7, 'paid', [ 'invoice_kind' => 'join' ] ),
            'another year'     => $this->row( 8, 'created', [ 'dues_year' => 2026 ] ),
            'the other mode'   => $this->row( 9, 'created', [ 'livemode' => 0 ] ),
        ];
        foreach ( $cases as $name => $row ) {
            $this->assertTrue( MyNJILGA_Email_Run::refusal( $row, 2027, true ) !== '', $name );
        }
    }

    public function testASelectionIsSortedForTheReviewScreen(): void {
        $sel = MyNJILGA_Email_Run::sort_selection( [ $this->row( 1, 'created' ), $this->row( 2, 'sent' ), $this->row( 3, 'paid' ) ], 2027, true );
        $this->assertCount( 1, $sel['unsent'] );
        $this->assertCount( 1, $sel['sent'] );
        $this->assertCount( 1, $sel['refused'] );
        $this->assertSame( 3, (int) $sel['refused'][0]['row']->id );
    }

    /** Never twice: an emailed invoice goes again only if ticked, and only if it hasn't gone since the run started. */
    public function testShouldSend(): void {
        $this->assertTrue( MyNJILGA_Email_Run::should_send( $this->row( 1, 'created' ), [], self::SINCE ) );

        $sentBefore = $this->row( 2, 'sent', [ 'sent_at' => '2026-11-01 10:00:00' ] );
        $this->assertFalse( MyNJILGA_Email_Run::should_send( $sentBefore, [], self::SINCE ), 'not ticked to resend' );
        $this->assertTrue( MyNJILGA_Email_Run::should_send( $sentBefore, [ 2 ], self::SINCE ), 'ticked to resend' );

        $sentDuringRun = $this->row( 3, 'sent', [ 'sent_at' => '2026-11-20 09:01:00' ] );
        $this->assertFalse( MyNJILGA_Email_Run::should_send( $sentDuringRun, [ 3 ], self::SINCE ), 'a job that runs twice sends once' );

        $this->assertFalse( MyNJILGA_Email_Run::should_send( $this->row( 4, 'paid' ), [ 4 ], self::SINCE ), 'paid before its turn' );
        $this->assertFalse( MyNJILGA_Email_Run::should_send( $this->row( 5, 'created', [ 'hosted_invoice_url' => '' ] ), [], self::SINCE ) );
    }

    /**
     * The claim is a compare-and-set on what the sender read, so of two
     * senders that read the same row at once only one UPDATE matches.
     * (The recording $wpdb reports 0 rows changed, i.e. "someone else won".)
     */
    public function testClaimingAnInvoiceIsACompareAndSet(): void {
        global $wpdb;

        $wpdb->reset();
        $this->assertSame( null, MyNJILGA_Dues_Invoice_Table::claim_for_email( $this->row( 1, 'created' ), self::NOW ), 'lost the race: do not send' );
        $sql = $wpdb->last_query();
        foreach ( [ "SET status = 'sent'", 'WHERE id = 1', "AND status = 'created'" ] as $needle ) {
            $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected \"$needle\" in: $sql" );
        }

        $wpdb->reset();
        MyNJILGA_Dues_Invoice_Table::claim_for_email( $this->row( 2, 'sent', [ 'sent_at' => '2026-11-01 10:00:00' ] ), self::NOW );
        $sql = $wpdb->last_query();
        foreach ( [ 'WHERE id = 2', "AND status = 'sent'", "AND sent_at = '2026-11-01 10:00:00'" ] as $needle ) {
            $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected \"$needle\" in: $sql" );
        }

        $wpdb->reset();
        MyNJILGA_Dues_Invoice_Table::claim_for_email( $this->row( 3, 'sent' ), self::NOW );
        $this->assertTrue( strpos( $wpdb->last_query(), 'AND sent_at IS NULL' ) !== false );

        $wpdb->reset();
        $this->assertSame( null, MyNJILGA_Dues_Invoice_Table::claim_for_email( $this->row( 4, 'paid' ), self::NOW ) );
        $this->assertCount( 0, $wpdb->queries, 'a paid invoice is never claimed for an email' );
    }

    /** A database failure while claiming is an error to report — never mistaken for another sender having the invoice. */
    public function testADatabaseFailureWhileClaimingIsAnError(): void {
        $saved           = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class extends NJILGA_Recording_Wpdb {
            public $last_error = 'Deadlock found when trying to get lock';
            public function query( string $query ) {
                $this->queries[] = $query;
                return false;
            }
        };
        try {
            MyNJILGA_Dues_Invoice_Table::claim_for_email( $this->row( 1, 'created' ), self::NOW );
            $this->assertTrue( false, 'expected an exception' );
        } catch ( \RuntimeException $e ) {
            $this->assertTrue( strpos( $e->getMessage(), 'Deadlock' ) !== false, $e->getMessage() );
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }

    /** A failed send hands the invoice back exactly as it was — only if nothing has moved it on since. */
    public function testReleasingAClaimRestoresWhatWasRead(): void {
        global $wpdb;
        $wpdb->reset();
        MyNJILGA_Dues_Invoice_Table::release_email_claim( $this->row( 1, 'created' ), '2026-11-20 09:01:00' );
        $sql = $wpdb->last_query();
        foreach ( [ "SET status = 'created', sent_at = NULL", 'WHERE id = 1', "AND status = 'sent'", "AND sent_at = '2026-11-20 09:01:00'" ] as $needle ) {
            $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected \"$needle\" in: $sql" );
        }
    }

    public function testProgressIsReadFromTheRows(): void {
        $rows = [
            $this->row( 1, 'sent', [ 'sent_at' => '2026-11-20 09:02:00' ] ),                                       // sent by the run
            $this->row( 2, 'created', [ 'queued_at' => self::SINCE ] ),                                           // waiting its turn
            $this->row( 3, 'created', [ 'last_error' => 'Email not sent: wp_mail() failed' ] ),                    // failed
            $this->row( 4, 'paid' ),                                                                              // skipped: paid first
            $this->row( 5, 'created', [ 'queued_at' => '2026-11-20 07:00:00' ] ),                                  // job died
            $this->row( 6, 'sent', [ 'sent_at' => '2026-11-20 09:03:00', 'queued_at' => self::SINCE ] ),           // sent, job not finished with it yet
        ];
        $p = MyNJILGA_Email_Run::progress( $rows, [ 1, 2, 3, 4, 5, 6, 99 ], self::SINCE, self::NOW );
        $this->assertSame( 6, $p['total'] );
        $this->assertSame( 1, $p['done'] );
        $this->assertSame( 2, $p['pending'] );
        $this->assertSame( 1, $p['failed'] );
        $this->assertSame( 1, $p['skipped'] );
        $this->assertSame( 1, $p['stalled'] );
        $this->assertFalse( $p['finished'] );

        $done = MyNJILGA_Email_Run::progress( [ $this->row( 1, 'sent', [ 'sent_at' => '2026-11-20 09:02:00' ] ) ], [ 1 ], self::SINCE, self::NOW );
        $this->assertTrue( $done['finished'] );
        $this->assertSame( 'Finished emailing invoices: 1 of 1 sent.', MyNJILGA_Email_Run::summary( $done ) );
    }
}
