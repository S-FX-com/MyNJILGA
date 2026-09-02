<?php
/**
 * Test bootstrap — loads the plugin's PURE classes (no WordPress
 * dependency) and a minimal assertion base class.
 *
 * MyNJILGA_Dues_Settings::defaults() is pure and is loaded for its seed
 * data; its other methods call WordPress functions and are not exercised
 * here. MyNJILGA_Pricing_Engine and MyNJILGA_Ledger_Totals have no
 * external dependencies at all.
 *
 * MyNJILGA_Dues_Invoice_Table is the one exception: it talks to $wpdb, so
 * it is loaded alongside the recording stub at the bottom of this file,
 * which lets tests/ModeIsolationTest.php assert on the SQL each read
 * emits without a database. Loading the file itself is safe without
 * WordPress — PHP only resolves what a method body references when the
 * method actually runs. Its get_unpaid_for_sweep() names
 * MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT, so the snapshot class (pure)
 * is loaded first.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-dues-settings.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-pricing-engine.php';
// Loaded for year_end_due_timestamp() only — gateway() is the sole method
// here that touches WordPress, and no test calls it.
require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoicing.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-ledger-totals.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-dues-snapshot.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-dues-invoice-table.php';

class NJILGA_Assertion_Failed extends Exception {}

abstract class NJILGA_TestCase {

    protected function assertSame( $expected, $actual, string $message = '' ): void {
        if ( $expected !== $actual ) {
            throw new NJILGA_Assertion_Failed( sprintf(
                "%sExpected %s, got %s",
                $message !== '' ? $message . ' — ' : '',
                $this->export( $expected ),
                $this->export( $actual )
            ) );
        }
    }

    protected function assertTrue( $actual, string $message = '' ): void {
        $this->assertSame( true, $actual, $message !== '' ? $message : 'Expected true' );
    }

    protected function assertFalse( $actual, string $message = '' ): void {
        $this->assertSame( false, $actual, $message !== '' ? $message : 'Expected false' );
    }

    protected function assertCount( int $expected, $actual, string $message = '' ): void {
        $this->assertSame( $expected, count( $actual ), $message !== '' ? $message : 'Count mismatch' );
    }

    protected function fail( string $message ): void {
        throw new NJILGA_Assertion_Failed( $message );
    }

    private function export( $value ): string {
        $s = var_export( $value, true );
        $s = preg_replace( '/\s+/', ' ', $s );
        return strlen( $s ) > 300 ? substr( $s, 0, 297 ) . '...' : $s;
    }
}

/**
 * A RECORDING STUB for WordPress's $wpdb — deliberately not a database.
 * It never stores, matches or returns a row: every read/write records the
 * SQL string it was handed and answers empty (null / [] / 0), so a test
 * can assert on the SQL a class EMITS. That is exactly the shape of the
 * bug tests/ModeIsolationTest.php guards: a read path that forgets its
 * `livemode` predicate returns the wrong rows on a real database, but is
 * visible here as a missing clause in the SQL.
 *
 * Only the handful of methods MyNJILGA_Dues_Invoice_Table's reads use are
 * implemented ($prefix, prepare, get_row, get_results, get_var, get_col,
 * query). Its write paths need WordPress functions (current_time(),
 * dbDelta(), get_option()) and are out of scope for this runner, so no
 * function shims are defined.
 */
class NJILGA_Recording_Wpdb {

    /** WordPress's table prefix — table_name() reads this. */
    public $prefix = 'wp_';

    /** @var array<int,string> Every SQL string handed to a read/write method, oldest first. */
    public $queries = [];

    /** @var array<int,string> Every string prepare() produced, oldest first. */
    public $prepared = [];

    /**
     * Interpolates like $wpdb->prepare(): %d becomes a bare integer, %s a
     * single-quoted string. Accepts either variadic args or one array of
     * them, the same two calling conventions the real prepare() takes.
     *
     * @param mixed ...$args
     */
    public function prepare( string $query, ...$args ): string {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        $args = array_values( $args );

        $i    = 0;
        $sql  = (string) preg_replace_callback(
            '/%[ds]/',
            static function ( array $m ) use ( &$i, $args ): string {
                $value = $args[ $i ] ?? null;
                $i++;
                return $m[0] === '%d'
                    ? (string) (int) $value
                    : "'" . str_replace( "'", "\\'", (string) $value ) . "'";
            },
            $query
        );

        $this->prepared[] = $sql;
        return $sql;
    }

    /** @return null Always — nothing is stored. */
    public function get_row( string $query ) {
        $this->queries[] = $query;
        return null;
    }

    /** @return array<int,object> Always empty — nothing is stored. */
    public function get_results( string $query ): array {
        $this->queries[] = $query;
        return [];
    }

    /** @return null Always — nothing is stored. */
    public function get_var( string $query ) {
        $this->queries[] = $query;
        return null;
    }

    /** @return array<int,string> Always empty — nothing is stored. */
    public function get_col( string $query ): array {
        $this->queries[] = $query;
        return [];
    }

    /** @return int Always 0 rows affected — nothing is stored. */
    public function query( string $query ): int {
        $this->queries[] = $query;
        return 0;
    }

    /** The SQL from the most recent read/write, or '' if none. */
    public function last_query(): string {
        return $this->queries ? (string) end( $this->queries ) : '';
    }

    /** Forget everything recorded so far — call it at the top of a test. */
    public function reset(): void {
        $this->queries  = [];
        $this->prepared = [];
    }
}

$GLOBALS['wpdb'] = new NJILGA_Recording_Wpdb();
