<?php
/**
 * Mode isolation for MyNJILGA_Dues_Invoice_Table (spec: Stripe migration,
 * "ModeIsolationTest") — a test-mode row must be invisible to a live-mode
 * read, and the UNIQUE KEY must let both exist at once.
 *
 * Every read here is asserted at the SQL level, against the recording
 * $wpdb stub in tests/bootstrap.php: the point is not what rows come back
 * (the stub stores none) but whether the `livemode` predicate is in the
 * query AT ALL. That is exactly the failure mode this pins — a read path
 * that forgets the filter returns the other mode's rows silently, and the
 * only trace of the bug is the missing clause.
 *
 * Both directions are asserted every time. `livemode = 1` present is only
 * half the guarantee; the other half is that a test-mode read scopes to 0
 * and never to 1.
 */
declare( strict_types=1 );

class ModeIsolationTest extends NJILGA_TestCase {

    private const YEAR    = 2027;
    private const COMPANY = 42;

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function wpdb(): NJILGA_Recording_Wpdb {
        return $GLOBALS['wpdb'];
    }

    /**
     * Runs one read against the recording stub and hands back the single
     * SQL statement it emitted.
     */
    private function sql_of( callable $read ): string {
        $wpdb = $this->wpdb();
        $wpdb->reset();
        $read();
        $this->assertCount( 1, $wpdb->queries, 'Expected the read to emit exactly one statement' );
        return $wpdb->last_query();
    }

    private function assertSqlHas( string $needle, string $sql ): void {
        $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected SQL to contain \"$needle\" — got: $sql" );
    }

    private function assertSqlLacks( string $needle, string $sql ): void {
        $this->assertFalse( strpos( $sql, $needle ) !== false, "Expected SQL NOT to contain \"$needle\" — got: $sql" );
    }

    /**
     * Asserts a read scopes to exactly one mode: the predicate for the
     * mode asked for is present, and the other mode's is absent.
     */
    private function assertScopedToMode( bool $livemode, string $sql ): void {
        $this->assertSqlHas( $livemode ? 'livemode = 1' : 'livemode = 0', $sql );
        $this->assertSqlLacks( $livemode ? 'livemode = 0' : 'livemode = 1', $sql );
    }

    // -------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------

    /** find_row() — the upsert lookup: a live preview must never find the test-mode row for the same firm/year/kind/bill-to. */
    public function testFindRowScopesToTheModeAsked(): void {
        foreach ( [ true, false ] as $livemode ) {
            $sql = $this->sql_of( function () use ( $livemode ): void {
                MyNJILGA_Dues_Invoice_Table::find_row( self::COMPANY, self::YEAR, 'combined', 0, $livemode );
            } );

            $this->assertScopedToMode( $livemode, $sql );
            $this->assertSqlHas( 'fluentcrm_company_id = 42', $sql );
            $this->assertSqlHas( 'dues_year = 2027', $sql );
        }
    }

    /** get_by_year() with a mode: the year's rows in that mode only. */
    public function testGetByYearScopesToTheModeAsked(): void {
        foreach ( [ true, false ] as $livemode ) {
            $sql = $this->sql_of( function () use ( $livemode ): void {
                MyNJILGA_Dues_Invoice_Table::get_by_year( self::YEAR, [], $livemode );
            } );

            $this->assertScopedToMode( $livemode, $sql );
            $this->assertSqlHas( 'dues_year = 2027', $sql );
        }
    }

    /**
     * get_by_year() with a status filter still carries the mode — the
     * statuses are extra placeholders in the same prepared statement, and
     * the mode must not be lost among them.
     */
    public function testGetByYearKeepsTheModeAlongsideAStatusFilter(): void {
        $sql = $this->sql_of( function (): void {
            MyNJILGA_Dues_Invoice_Table::get_by_year(
                self::YEAR,
                [ MyNJILGA_Dues_Invoice_Table::STATUS_SENT, MyNJILGA_Dues_Invoice_Table::STATUS_PAID ],
                true
            );
        } );

        $this->assertScopedToMode( true, $sql );
        $this->assertSqlHas( "status IN ('sent','paid')", $sql );
    }

    /**
     * $livemode null is the documented "both modes" read — the filter is
     * genuinely absent, not silently defaulted to live. Only callers that
     * post-filter per row (the reconciler, the Payments ledger) use it.
     */
    public function testGetByYearWithNullModeReadsBothModes(): void {
        $sql = $this->sql_of( function (): void {
            MyNJILGA_Dues_Invoice_Table::get_by_year( self::YEAR, [], null );
        } );

        $this->assertSqlHas( 'dues_year = 2027', $sql );
        $this->assertSqlLacks( 'livemode', $sql );
    }

    /** get_unpaid_for_sweep() — this one tags contacts and removes roles, so a stale row from the other mode must never reach it. */
    public function testUnpaidSweepScopesToTheModeAsked(): void {
        foreach ( [ true, false ] as $livemode ) {
            $sql = $this->sql_of( function () use ( $livemode ): void {
                MyNJILGA_Dues_Invoice_Table::get_unpaid_for_sweep( self::YEAR, $livemode );
            } );

            $this->assertScopedToMode( $livemode, $sql );
            $this->assertSqlHas( 'dues_year = 2027', $sql );
            $this->assertSqlHas( "invoice_kind <> 'assessment'", $sql );
        }
    }

    /** get_for_companies() — the member-facing read: a firm must never be shown its test-mode invoice (or that invoice's unpayable link). */
    public function testGetForCompaniesScopesToTheModeAsked(): void {
        foreach ( [ true, false ] as $livemode ) {
            $sql = $this->sql_of( function () use ( $livemode ): void {
                MyNJILGA_Dues_Invoice_Table::get_for_companies( [ 11, 22 ], $livemode );
            } );

            $this->assertScopedToMode( $livemode, $sql );
            $this->assertSqlHas( 'fluentcrm_company_id IN (11,22)', $sql );
        }
    }

    /** A read for no companies at all short-circuits — no SQL, no accidental unscoped query. */
    public function testGetForCompaniesWithNoCompaniesEmitsNoSql(): void {
        $wpdb = $this->wpdb();
        $wpdb->reset();

        $this->assertCount( 0, MyNJILGA_Dues_Invoice_Table::get_for_companies( [], true ) );
        $this->assertCount( 0, $wpdb->queries );
    }

    /**
     * delete_stale_drafts() is the only DESTRUCTIVE path here: a preview
     * run in test mode must never delete live-mode drafts for the same
     * firm and year, and it must stay confined to draft/excluded rows.
     */
    public function testDeleteStaleDraftsNeverCrossesModes(): void {
        foreach ( [ true, false ] as $livemode ) {
            $sql = $this->sql_of( function () use ( $livemode ): void {
                MyNJILGA_Dues_Invoice_Table::delete_stale_drafts( self::COMPANY, self::YEAR, [ 7, 8 ], $livemode );
            } );

            $this->assertSqlHas( 'DELETE FROM', $sql );
            $this->assertScopedToMode( $livemode, $sql );
            $this->assertSqlHas( 'fluentcrm_company_id = 42', $sql );
            $this->assertSqlHas( 'dues_year = 2027', $sql );
            $this->assertSqlHas( "status IN ('draft', 'excluded')", $sql );
            $this->assertSqlHas( 'id NOT IN (7,8)', $sql );
        }
    }

    /** With nothing to keep, the delete still carries its mode and status scope — it just drops the NOT IN. */
    public function testDeleteStaleDraftsWithNothingToKeepStillScopesToMode(): void {
        $sql = $this->sql_of( function (): void {
            MyNJILGA_Dues_Invoice_Table::delete_stale_drafts( self::COMPANY, self::YEAR, [], false );
        } );

        $this->assertScopedToMode( false, $sql );
        $this->assertSqlHas( "status IN ('draft', 'excluded')", $sql );
        $this->assertSqlLacks( 'id NOT IN', $sql );
    }

    // -------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------

    /**
     * The UNIQUE KEY must include livemode, or a test-mode row and a
     * live-mode row for the same firm/year/kind/bill-to would collide on
     * insert — which is the whole reason both modes can be sitting there
     * at once for the reads above to tell apart. Read out of the class's
     * own CREATE TABLE rather than restated here, so the assertion can't
     * drift away from the schema it describes.
     */
    public function testUniqueKeyIncludesLivemodeSoBothModesCanCoexist(): void {
        $schema = $this->create_table_sql();

        $this->assertTrue(
            (bool) preg_match( '/livemode TINYINT\(1\) NOT NULL DEFAULT 1/', $schema ),
            'livemode column missing (or no longer defaulting to live)'
        );

        preg_match_all( '/UNIQUE KEY\s+(\w+)\s*\(([^)]*)\)/', $schema, $matches, PREG_SET_ORDER );
        $this->assertCount( 1, $matches, 'Expected exactly one UNIQUE KEY on the invoices table' );

        $columns = array_map( 'trim', explode( ',', $matches[0][2] ) );
        $this->assertSame( 'firm_year_kind_billto', $matches[0][1] );
        $this->assertSame(
            [ 'fluentcrm_company_id', 'dues_year', 'invoice_kind', 'bill_to_contact_id', 'livemode' ],
            $columns
        );
    }

    /**
     * The CREATE TABLE statement, read straight out of
     * MyNJILGA_Dues_Invoice_Table::create_or_upgrade_table(). Reflection
     * rather than a hard-coded path: the schema assertion follows the
     * method wherever it lives.
     */
    private function create_table_sql(): string {
        $method = new ReflectionMethod( 'MyNJILGA_Dues_Invoice_Table', 'create_or_upgrade_table' );
        $source = file( (string) $method->getFileName() );
        if ( ! $source ) {
            $this->fail( 'Could not read the invoice table class source' );
        }
        return implode( '', array_slice(
            $source,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ) );
    }
}
