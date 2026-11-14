<?php
/**
 * Which invoice rows a firm member sees on [njilga_firm_dues_status]
 * (MyNJILGA_Firm_Status_Page::visible_rows).
 *
 * Generate Preview writes a DRAFT row for every firm. The page filtered out
 * only "excluded", so the moment a preview existed every logged-in member
 * saw an "In preparation" card for the new year: an unreviewed total and the
 * whole roster with per-person amounts. My Membership has never shown
 * drafts; neither does this page now.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/class-firm-status-page.php';

class FirmStatusRowsTest extends NJILGA_TestCase {

    private function rows( array $statuses ): array {
        $out = [];
        foreach ( $statuses as $i => $status ) {
            $out[] = (object) [ 'id' => $i + 1, 'status' => $status, 'sent_at' => '2026-02-01 09:00:00' ];
        }
        return $out;
    }

    private function ids( array $rows ): array {
        return array_map( static function ( $r ) { return (int) $r->id; }, $rows );
    }

    public function testDraftAndExcludedRowsAreHidden(): void {
        $visible = MyNJILGA_Firm_Status_Page::visible_rows( $this->rows( [ 'draft', 'excluded' ] ) );
        $this->assertSame( [], $visible );
    }

    /** Everything a member can act on, or needs to see the outcome of, stays. */
    public function testIssuedAndSettledRowsStayVisible(): void {
        $statuses = [ 'approved', 'created', 'sent', 'processing', 'paid', 'downgraded', 'voided', 'uncollectible' ];
        $visible  = MyNJILGA_Firm_Status_Page::visible_rows( $this->rows( $statuses ) );
        $this->assertSame( [ 1, 2, 3, 4, 5, 6, 7, 8 ], $this->ids( $visible ) );
    }

    /** Voided before it was ever emailed: withdrawn before it reached the firm, so not shown. */
    public function testAnInvoiceVoidedBeforeItWasSentIsHidden(): void {
        $rows = [
            (object) [ 'id' => 1, 'status' => 'voided', 'sent_at' => null ],
            (object) [ 'id' => 2, 'status' => 'voided', 'sent_at' => '' ],
            (object) [ 'id' => 3, 'status' => 'voided', 'sent_at' => '2026-02-01 09:00:00' ],
            (object) [ 'id' => 4, 'status' => 'created', 'sent_at' => null ],
        ];
        $this->assertSame( [ 3, 4 ], $this->ids( MyNJILGA_Firm_Status_Page::visible_rows( $rows ) ) );
    }

    public function testOrderIsPreservedAndKeysAreReindexed(): void {
        $visible = MyNJILGA_Firm_Status_Page::visible_rows( $this->rows( [ 'paid', 'draft', 'sent', 'excluded', 'created' ] ) );
        $this->assertSame( [ 1, 3, 5 ], $this->ids( $visible ) );
        $this->assertSame( [ 0, 1, 2 ], array_keys( $visible ) );
    }
}
