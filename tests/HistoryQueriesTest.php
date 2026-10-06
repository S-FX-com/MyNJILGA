<?php
/**
 * SQL-level guards for the reads the Dues History tab and the Firm Renewal
 * Lookup added, run against the recording $wpdb stub (see
 * tests/bootstrap.php). Like tests/ModeIsolationTest.php, what is asserted
 * is the SQL a read EMITS — here, that every read of live invoices is
 * scoped to ONE Stripe mode, and that the history table's reads can't
 * match one contact's id against another's.
 */
declare( strict_types=1 );

// Loading the file is safe without WordPress; only clean_batch_id() is called.
require_once dirname( __DIR__ ) . '/includes/tools/class-page-tools.php';

class HistoryQueriesTest extends NJILGA_TestCase {

    private function wpdb(): NJILGA_Recording_Wpdb {
        return $GLOBALS['wpdb'];
    }

    private function sql_of( callable $read ): string {
        $wpdb = $this->wpdb();
        $wpdb->reset();
        $read();
        $this->assertCount( 1, $wpdb->queries, 'Expected the read to emit exactly one statement' );
        return $wpdb->last_query();
    }

    private function has( string $needle, string $sql ): void {
        $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected SQL to contain \"$needle\" — got: $sql" );
    }

    private function lacks( string $needle, string $sql ): void {
        $this->assertFalse( strpos( $sql, $needle ) !== false, "Expected SQL NOT to contain \"$needle\" — got: $sql" );
    }

    // -------------------------------------------------------------------
    // Live invoices: always one mode
    // -------------------------------------------------------------------

    /** The Dues History tab for a contact must never show the other mode's invoice. */
    public function testRowsInvolvingAContactAreScopedToTheModeAsked(): void {
        foreach ( [ true, false ] as $live ) {
            $sql = $this->sql_of( static function () use ( $live ): void {
                MyNJILGA_Dues_Invoice_Table::rows_involving_contact( 5, $live );
            } );
            $this->has( $live ? 'livemode = 1' : 'livemode = 0', $sql );
            $this->lacks( $live ? 'livemode = 0' : 'livemode = 1', $sql );
        }
    }

    public function testRowsInvolvingAContactFindTheBillToOwnerAndRosterAlike(): void {
        $sql = $this->sql_of( static function (): void {
            MyNJILGA_Dues_Invoice_Table::rows_involving_contact( 5, true );
        } );
        $this->has( 'bill_to_contact_id = 5', $sql );
        $this->has( 'fluentcrm_owner_contact_id = 5', $sql );
        $this->has( 'roster_snapshot LIKE', $sql );
        // esc_like() escapes the underscore; the trailing comma is what keeps 5 from matching 55.
        $this->has( '"contact\\_id":5,', $sql );
        $this->lacks( '"contact\\_id":5%', $sql );
    }

    public function testAContactWithNoIdReadsNothing(): void {
        $wpdb = $this->wpdb();
        $wpdb->reset();
        $this->assertSame( [], MyNJILGA_Dues_Invoice_Table::rows_involving_contact( 0, true ) );
        $this->assertCount( 0, $wpdb->queries, 'no query for an id of 0' );
    }

    /** The public lookup reads Live invoices only — a Test invoice can never be paid by a member. */
    public function testTheOpenInvoiceReadForTheLookupIsScopedAndLimitedToIssuedStatuses(): void {
        $sql = $this->sql_of( static function (): void {
            MyNJILGA_Dues_Invoice_Table::get_open_issued( true );
        } );
        $this->has( 'livemode = 1', $sql );
        $this->lacks( 'livemode = 0', $sql );
        $this->has( "status IN ('created', 'sent', 'processing')", $sql );
        foreach ( [ "'draft'", "'approved'", "'paid'", "'voided'", "'downgraded'", "'excluded'" ] as $never ) {
            $this->lacks( $never, $sql );
        }
    }

    public function testThePaymentsReadAsksForEveryInvoiceAtOnce(): void {
        $sql = $this->sql_of( static function (): void {
            MyNJILGA_Dues_Payments_Table::get_for_invoice_rows( [ 3, 4, 4, 0, -1 ] );
        } );
        $this->has( 'invoice_row_id IN (3,4)', $sql );

        $wpdb = $this->wpdb();
        $wpdb->reset();
        $this->assertSame( [], MyNJILGA_Dues_Payments_Table::get_for_invoice_rows( [] ) );
        $this->assertCount( 0, $wpdb->queries );
    }

    // -------------------------------------------------------------------
    // History table
    // -------------------------------------------------------------------

    public function testAHistoryReadForAContactMatchesWholeIdsOnly(): void {
        $sql = $this->sql_of( static function (): void {
            MyNJILGA_Dues_History_Table::for_contact( 5 );
        } );
        $this->has( 'contact_id = 5', $sql );
        $this->has( "member_ids LIKE '%,5,%'", $sql );
    }

    public function testAHistoryReadForFirmsDropsBadIdsAndReadsNothingForNone(): void {
        $sql = $this->sql_of( static function (): void {
            MyNJILGA_Dues_History_Table::for_companies( [ 42, 42, 0, -7 ] );
        } );
        $this->has( 'company_id IN (42)', $sql );

        $wpdb = $this->wpdb();
        $wpdb->reset();
        $this->assertSame( [], MyNJILGA_Dues_History_Table::for_companies( [ 0 ] ) );
        $this->assertSame( [], MyNJILGA_Dues_History_Table::for_contact( 0 ) );
        $this->assertCount( 0, $wpdb->queries );
    }

    public function testTheOnlyDeleteIsByBatch(): void {
        $sql = $this->sql_of( static function (): void {
            MyNJILGA_Dues_History_Table::delete_batch( 'pmpro-20260101-000000-ab12' );
        } );
        $this->has( "DELETE FROM wp_njilga_dues_history WHERE batch_id = 'pmpro-20260101-000000-ab12'", $sql );

        // An empty batch id must never become a delete of everything.
        $wpdb = $this->wpdb();
        $wpdb->reset();
        $this->assertSame( 0, MyNJILGA_Dues_History_Table::delete_batch( '' ) );
        $this->assertCount( 0, $wpdb->queries );
    }

    public function testAlreadyImportedRefsAreLookedUpPerSource(): void {
        $sql = $this->sql_of( static function (): void {
            MyNJILGA_Dues_History_Table::existing_refs( 'pmpro', [ '7', '8', '7', '' ] );
        } );
        $this->has( "source = 'pmpro'", $sql );
        $this->has( "source_ref IN ('7','8')", $sql );
    }

    public function testBatchIdsCannotCarryAnythingButLettersDigitsAndDashes(): void {
        $c = [ 'MyNJILGA_Page_Tools', 'clean_batch_id' ];
        $this->assertSame( 'pmpro-20260101-000000-ab12', $c( 'pmpro-20260101-000000-ab12' ) );
        $this->assertSame( 'xDROPTABLEy--', $c( "x'; DROP TABLE y;--" ) );
        $this->assertSame( 'OR11--', $c( "' OR 1=1 --" ), 'quotes, spaces and = are all dropped' );
        $this->assertSame( 48, strlen( $c( str_repeat( 'a', 100 ) ) ) );
        $this->assertSame( '', $c( '' ) );
    }
}
