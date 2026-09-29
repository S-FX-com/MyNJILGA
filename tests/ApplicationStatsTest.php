<?php
/**
 * Unit tests for MyNJILGA_Application_Stats — the numbers behind the
 * Dashboard's Applications section (applications waiting, joins needing a
 * person and why, ACH clearing, joined this year) — and for the additive
 * table helpers it stands on.
 *
 * Five things are pinned here:
 *
 *   1. SQL EMISSION, in the style of tests/ModeIsolationTest.php, against
 *      the recording $wpdb stub: every join read carries its `livemode`
 *      predicate exactly when a bool is passed and never otherwise, and the
 *      zero-argument reads the menu bubble and the Online joins tab make are
 *      pinned to the exact statement they have always emitted (the attention
 *      one down to the byte).
 *   2. The PURE half: the age maths (0 days, exactly 1 day, the future,
 *      null, non-dates, independence from PHP's timezone), the breakdown
 *      sentence, the join-problem wording, the held-payment count, and the
 *      rollup's arithmetic (other_attention floors at 0, an age only for a
 *      non-empty queue, paid_held never above paid).
 *   3. END TO END: snapshot()'s read path (build()) runs against a $wpdb
 *      double that answers every statement from fixture rows. Test-mode
 *      rows sit beside live ones on every OR-branch of the attention
 *      predicate and must not leak into a Live read (and the reverse).
 *   4. PARITY of the attention predicate's SQL with its PHP twin,
 *      MyNJILGA_Join_Orders_Table::needs_attention(), row by row.
 *   5. The menu bubble is the unscoped sum, so it never reads below the
 *      Dashboard.
 *
 * The double's SQL evaluator (ApplicationStatsFakeWpdb) is a small
 * recursive-descent reader for exactly the WHERE clauses this class emits:
 * comparisons, IN, LIKE (with its backslash escapes), IS NOT NULL, AND / OR
 * and parentheses, with SQL's precedence. It EXECUTES the text — so a
 * missing parenthesis around the OR-branches, the classic way a mode filter
 * leaks, changes the answer — and throws on anything it does not
 * recognise, so a rewritten query fails loudly instead of quietly matching.
 * It is a model of MySQL, not MySQL: change any of this SQL and re-check it
 * against a real engine.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/join/class-join-orders-table.php';
require_once dirname( __DIR__ ) . '/includes/enrollment/class-applications-table.php';
require_once dirname( __DIR__ ) . '/includes/enrollment/class-application-stats.php';

/**
 * A $wpdb that keeps njilga_membership_applications and njilga_join_orders
 * rows in memory and answers the handful of statements
 * MyNJILGA_Application_Stats reads, by evaluating their WHERE clauses over
 * those rows. Every statement is recorded, as the recording stub does.
 *
 * Counts come back as strings, the way $wpdb hands them over, so the
 * (int) casts in the code under test are exercised.
 */
class ApplicationStatsFakeWpdb extends NJILGA_Recording_Wpdb {

    /** @var array<int,array<string,mixed>> Keyed by id once loaded. */
    public $applications = [];

    /** @var array<int,array<string,mixed>> Keyed by id once loaded. */
    public $joins = [];

    /** @param array<int,array<string,mixed>> $rows */
    public function load_applications( array $rows ): void {
        $this->applications = $this->keyed( $rows );
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function load_joins( array $rows ): void {
        $this->joins = $this->keyed( $rows );
    }

    public function esc_like( string $text ): string {
        return addcslashes( $text, '_%\\' );
    }

    /** @return array<int,object> */
    public function get_results( string $query ): array {
        $this->queries[] = $query;
        [ $select, $rows, $tail ] = $this->read( $query );

        if ( $select === 'status, COUNT(*) AS c' && $tail === ' GROUP BY status' ) {
            $groups = [];
            foreach ( $rows as $row ) {
                $groups[ (string) $row['status'] ] = ( $groups[ (string) $row['status'] ] ?? 0 ) + 1;
            }
            $out = [];
            foreach ( $groups as $status => $n ) {
                $out[] = (object) [ 'status' => $status, 'c' => (string) $n ];
            }
            return $out;
        }
        if ( $select === 'id, status, progress' && $tail === ' ORDER BY id ASC' ) {
            ksort( $rows );
            $out = [];
            foreach ( $rows as $id => $row ) {
                $out[] = (object) [ 'id' => (string) $id, 'status' => $row['status'], 'progress' => $row['progress'] ?? null ];
            }
            return $out;
        }
        throw new RuntimeException( 'ApplicationStatsFakeWpdb does not answer: ' . $query );
    }

    /** @return string|null */
    public function get_var( string $query ) {
        $this->queries[] = $query;
        [ $select, $rows, $tail ] = $this->read( $query );

        if ( $tail !== '' ) {
            throw new RuntimeException( 'ApplicationStatsFakeWpdb does not answer: ' . $query );
        }
        if ( $select === 'COUNT(*)' ) {
            return (string) count( $rows );
        }
        if ( $select === 'MIN(created_at)' ) {
            $min = null;
            foreach ( $rows as $row ) {
                if ( $min === null || strcmp( (string) $row['created_at'], $min ) < 0 ) {
                    $min = (string) $row['created_at'];
                }
            }
            return $min;
        }
        throw new RuntimeException( 'ApplicationStatsFakeWpdb does not answer: ' . $query );
    }

    /** Whether one row satisfies a WHERE clause (the text after WHERE). */
    public function evaluate( string $where, array $row ): bool {
        $tokens = $this->tokenize( $where );
        $pos    = 0;
        $test   = $this->parse_or( $tokens, $pos );
        if ( $pos !== count( $tokens ) ) {
            throw new RuntimeException( 'Trailing SQL in WHERE: ' . $where );
        }
        return $test( $row );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function keyed( array $rows ): array {
        $out = [];
        $id  = 0;
        foreach ( $rows as $row ) {
            $id++;
            $out[ $id ] = $row + [ 'id' => $id ];
        }
        return $out;
    }

    /** @return array{0:string,1:array<int,array<string,mixed>>,2:string} select list, matching rows, GROUP BY / ORDER BY tail */
    private function read( string $query ): array {
        if ( ! preg_match( '/^SELECT (.+?) FROM (\S+)(?: WHERE (.+?))?( GROUP BY status| ORDER BY id ASC)?$/s', $query, $m ) ) {
            throw new RuntimeException( 'ApplicationStatsFakeWpdb cannot read: ' . $query );
        }
        $tables = [
            $this->prefix . 'njilga_membership_applications' => $this->applications,
            $this->prefix . 'njilga_join_orders'             => $this->joins,
        ];
        if ( ! isset( $tables[ $m[2] ] ) ) {
            throw new RuntimeException( 'Unknown table in: ' . $query );
        }
        $rows = [];
        foreach ( $tables[ $m[2] ] as $id => $row ) {
            if ( ( $m[3] ?? '' ) === '' || $this->evaluate( $m[3], $row ) ) {
                $rows[ $id ] = $row;
            }
        }
        return [ $m[1], $rows, $m[4] ?? '' ];
    }

    /** @return array<int,array<string,mixed>> */
    private function tokenize( string $sql ): array {
        $quoted   = '\'(?:[^\'\\\\]|\\\\.)*\'';
        $patterns = [
            'lparen'  => '~\G\(~',
            'rparen'  => '~\G\)~',
            'and'     => '~\GAND\b~',
            'or'      => '~\GOR\b~',
            'in'      => '~\G(\w+) IN \(((?:' . $quoted . '(?:, )?)+)\)~',
            'like'    => '~\G(\w+) LIKE (' . $quoted . ')~',
            'notnull' => '~\G(\w+) IS NOT NULL~',
            'cmp'     => '~\G(\w+) (<>|>=|<=|=|<|>) (' . $quoted . '|-?\d+)~',
        ];
        $tokens = [];
        $offset = 0;
        $length = strlen( $sql );
        while ( $offset < $length ) {
            if ( $sql[ $offset ] === ' ' ) {
                $offset++;
                continue;
            }
            $matched = false;
            foreach ( $patterns as $type => $re ) {
                if ( preg_match( $re, $sql, $m, 0, $offset ) ) {
                    $tokens[] = [ 'type' => $type, 'm' => $m ];
                    $offset  += strlen( $m[0] );
                    $matched  = true;
                    break;
                }
            }
            if ( ! $matched ) {
                throw new RuntimeException( 'Unrecognised SQL at: ' . substr( $sql, $offset ) );
            }
        }
        return $tokens;
    }

    /** @param array<int,array<string,mixed>> $tokens */
    private function parse_or( array $tokens, int &$pos ): callable {
        $terms = [ $this->parse_and( $tokens, $pos ) ];
        while ( isset( $tokens[ $pos ] ) && $tokens[ $pos ]['type'] === 'or' ) {
            $pos++;
            $terms[] = $this->parse_and( $tokens, $pos );
        }
        return static function ( array $row ) use ( $terms ): bool {
            foreach ( $terms as $t ) {
                if ( $t( $row ) ) {
                    return true;
                }
            }
            return false;
        };
    }

    /** @param array<int,array<string,mixed>> $tokens */
    private function parse_and( array $tokens, int &$pos ): callable {
        $factors = [ $this->parse_factor( $tokens, $pos ) ];
        while ( isset( $tokens[ $pos ] ) && $tokens[ $pos ]['type'] === 'and' ) {
            $pos++;
            $factors[] = $this->parse_factor( $tokens, $pos );
        }
        return static function ( array $row ) use ( $factors ): bool {
            foreach ( $factors as $f ) {
                if ( ! $f( $row ) ) {
                    return false;
                }
            }
            return true;
        };
    }

    /** @param array<int,array<string,mixed>> $tokens */
    private function parse_factor( array $tokens, int &$pos ): callable {
        if ( ! isset( $tokens[ $pos ] ) ) {
            throw new RuntimeException( 'Unexpected end of WHERE clause' );
        }
        $token = $tokens[ $pos ];
        $pos++;
        $m = $token['m'];

        switch ( $token['type'] ) {
            case 'lparen':
                $inner = $this->parse_or( $tokens, $pos );
                if ( ! isset( $tokens[ $pos ] ) || $tokens[ $pos ]['type'] !== 'rparen' ) {
                    throw new RuntimeException( 'Unbalanced parentheses in WHERE clause' );
                }
                $pos++;
                return $inner;

            case 'in':
                preg_match_all( '~\'((?:[^\'\\\\]|\\\\.)*)\'~', $m[2], $lits );
                $set = array_map( [ $this, 'unquote' ], $lits[1] );
                $col = $m[1];
                return static function ( array $row ) use ( $col, $set ): bool {
                    return isset( $row[ $col ] ) && in_array( (string) $row[ $col ], $set, true );
                };

            case 'like':
                $col = $m[1];
                $re  = $this->like_regex( substr( $m[2], 1, -1 ) );
                return static function ( array $row ) use ( $col, $re ): bool {
                    return isset( $row[ $col ] ) && preg_match( $re, (string) $row[ $col ] ) === 1;
                };

            case 'notnull':
                $col = $m[1];
                return static function ( array $row ) use ( $col ): bool {
                    return isset( $row[ $col ] );
                };

            case 'cmp':
                $col = $m[1];
                $op  = $m[2];
                $lit = $m[3];
                $num = $lit[0] !== '\'';
                $val = $num ? (int) $lit : $this->unquote( substr( $lit, 1, -1 ) );
                return static function ( array $row ) use ( $col, $op, $num, $val ): bool {
                    if ( ! isset( $row[ $col ] ) ) {
                        return false; // SQL: a comparison with NULL is never true
                    }
                    $cmp = $num ? ( (int) $row[ $col ] <=> $val ) : strcmp( (string) $row[ $col ], (string) $val );
                    switch ( $op ) {
                        case '=':
                            return $cmp === 0;
                        case '<>':
                            return $cmp !== 0;
                        case '>=':
                            return $cmp >= 0;
                        case '<=':
                            return $cmp <= 0;
                        case '<':
                            return $cmp < 0;
                        default:
                            return $cmp > 0;
                    }
                };
        }
        throw new RuntimeException( 'Unexpected token in WHERE clause: ' . $token['type'] );
    }

    /** A quoted SQL literal's body, backslash escapes resolved. */
    private function unquote( string $body ): string {
        return (string) preg_replace( '/\\\\(.)/s', '$1', $body );
    }

    /** An SQL LIKE pattern (backslash-escaped %, _ and \) as a case-insensitive regex. */
    private function like_regex( string $pattern ): string {
        $re  = '';
        $len = strlen( $pattern );
        for ( $i = 0; $i < $len; $i++ ) {
            $c = $pattern[ $i ];
            if ( $c === '\\' && $i + 1 < $len ) {
                $i++;
                $re .= preg_quote( $pattern[ $i ], '~' );
            } elseif ( $c === '%' ) {
                $re .= '.*';
            } elseif ( $c === '_' ) {
                $re .= '.';
            } else {
                $re .= preg_quote( $c, '~' );
            }
        }
        return '~^' . $re . '$~is';
    }
}

class ApplicationStatsTest extends NJILGA_TestCase {

    private const YEAR = 2026;

    /** A site clock reading whose stale-claim cutoff is the '2026-09-21 14:03:20' the golden SQL below carries. */
    private const GOLDEN_NOW = 1790000000;

    private const APPS  = 'wp_njilga_membership_applications';
    private const JOINS = 'wp_njilga_join_orders';

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /** The site clock the end-to-end fixtures are written against: 2026-09-29 12:00:00. */
    private function now(): int {
        return gmmktime( 12, 0, 0, 9, 29, 2026 );
    }

    /** The recording stub, with esc_like() — which the attention statement calls. */
    private function recorder(): NJILGA_Recording_Wpdb {
        return new class extends NJILGA_Recording_Wpdb {
            public function esc_like( string $text ): string {
                return addcslashes( $text, '_%\\' );
            }
        };
    }

    /**
     * Runs $fn with $db as the global $wpdb, then puts the old one back.
     *
     * @return mixed Whatever $fn returns.
     */
    private function with_db( object $db, callable $fn ) {
        $saved           = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = $db;
        try {
            return $fn();
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }

    /**
     * Runs one read against the recording stub and hands back the single
     * statement it emitted.
     */
    private function sql_of( callable $read ): string {
        $db = $this->recorder();
        $this->with_db( $db, $read );
        $this->assertCount( 1, $db->queries, 'Expected the read to emit exactly one statement' );
        return $db->last_query();
    }

    /** The attention statement for a mode, as built (it is returned, not sent, so the recorder never sees it). */
    private function attention_sql( ?bool $live ): string {
        return (string) $this->with_db( $this->recorder(), static function () use ( $live ): string {
            return MyNJILGA_Join_Orders_Table::needs_attention_sql( $live, self::GOLDEN_NOW );
        } );
    }

    private function assertSqlHas( string $needle, string $sql ): void {
        $this->assertTrue( strpos( $sql, $needle ) !== false, "Expected SQL to contain \"$needle\" — got: $sql" );
    }

    private function assertSqlLacks( string $needle, string $sql ): void {
        $this->assertFalse( strpos( $sql, $needle ) !== false, "Expected SQL NOT to contain \"$needle\" — got: $sql" );
    }

    /** One application row for the fake database. */
    private function app( string $status, string $created, ?string $decided = null ): array {
        return [ 'status' => $status, 'created_at' => $created, 'decided_at' => $decided ];
    }

    /**
     * One join row for the fake database. Only the columns the statements
     * under test read.
     *
     * @param array<string,mixed> $o
     */
    private function join( string $status, int $livemode, array $o = [] ): array {
        return $o + [
            'status'       => $status,
            'livemode'     => $livemode,
            'created_at'   => '2026-09-01 10:00:00',
            'updated_at'   => '2026-09-01 10:00:00',
            'fulfilled_at' => null,
            'progress'     => null,
            'last_error'   => null,
        ];
    }

    /**
     * The applications and joins the end-to-end tests read. Expectations
     * (site clock 2026-09-29 12:00:00, so a fulfilling claim is stale when
     * updated before 11:50:00):
     *
     *   applications  2 pending (oldest submitted 09-20 09:00 = 9 days ago),
     *                 approved this year: 2 (03-05 and Jan 1 00:00:00 count;
     *                 2025-12-31 23:59:59 does not), plus a rejected and a
     *                 superseded one that are not approvals.
     *   LIVE joins    2 review, 2 paid (1 held, 1 settled), 1 stale + 1 fresh
     *                 fulfilling, 6 fulfilled (2 flagged; 4 fulfilled in
     *                 2026, the 2025-12-31 23:59:59 and 2027-01-01 00:00:00
     *                 ones outside it), 3 pending, 2 processing (oldest 09-24
     *                 08:00 = 5 days), 1 failed, and one each expired /
     *                 abandoned / rejected. needs_attention = 2+2+1+2 = 7.
     *   TEST joins    1 review, 1 paid (held), 1 stale fulfilling, 2
     *                 fulfilled (1 flagged, both in 2026), 1 processing (08-01
     *                 = 59 days), 1 pending, 1 failed. needs_attention =
     *                 1+1+1+1 = 4 — a Test row on EVERY branch of the
     *                 attention predicate, so a leaking mode filter shows.
     */
    private function fixture_db(): ApplicationStatsFakeWpdb {
        $db = new ApplicationStatsFakeWpdb();
        $db->load_applications( [
            $this->app( 'pending', '2026-09-20 09:00:00' ),
            $this->app( 'pending', '2026-09-27 18:30:00' ),
            $this->app( 'approved', '2026-03-01 08:00:00', '2026-03-05 10:00:00' ),
            $this->app( 'approved', '2025-12-01 08:00:00', '2025-12-31 23:59:59' ),
            $this->app( 'approved', '2025-12-20 08:00:00', '2026-01-01 00:00:00' ),
            $this->app( 'rejected', '2026-03-30 08:00:00', '2026-04-01 09:00:00' ),
            $this->app( 'superseded', '2026-04-30 08:00:00', '2026-05-01 09:00:00' ),
        ] );

        $flagged = json_encode( [ 'checks' => [ 'a@example.com' ] ] );
        $db->load_joins( [
            // Live.
            $this->join( 'review', 1, [ 'created_at' => '2026-09-25 10:00:00' ] ),
            $this->join( 'review', 1, [ 'created_at' => '2026-09-28 10:00:00' ] ),
            $this->join( 'paid', 1 ),
            $this->join( 'paid', 1, [ 'progress' => json_encode( [ 'payment_confirmed' => true ] ) ] ),
            $this->join( 'fulfilling', 1, [ 'updated_at' => '2026-09-29 11:00:00' ] ),
            $this->join( 'fulfilling', 1, [ 'updated_at' => '2026-09-29 11:55:00' ] ),
            $this->join( 'fulfilled', 1, [ 'progress' => $flagged, 'fulfilled_at' => '2026-06-01 10:00:00' ] ),
            $this->join( 'fulfilled', 1, [ 'last_error' => 'Something to look at', 'fulfilled_at' => '2026-07-07 10:00:00' ] ),
            $this->join( 'fulfilled', 1, [ 'fulfilled_at' => '2026-02-02 10:00:00' ] ),
            $this->join( 'fulfilled', 1, [ 'fulfilled_at' => '2025-12-31 23:59:59' ] ),
            $this->join( 'fulfilled', 1, [ 'fulfilled_at' => '2026-01-01 00:00:00' ] ),
            $this->join( 'fulfilled', 1, [ 'fulfilled_at' => '2027-01-01 00:00:00' ] ),
            $this->join( 'pending', 1 ),
            $this->join( 'pending', 1 ),
            $this->join( 'pending', 1 ),
            $this->join( 'processing', 1, [ 'created_at' => '2026-09-24 08:00:00' ] ),
            $this->join( 'processing', 1, [ 'created_at' => '2026-09-28 08:00:00' ] ),
            $this->join( 'failed', 1 ),
            $this->join( 'expired', 1 ),
            $this->join( 'abandoned', 1 ),
            $this->join( 'rejected', 1 ),
            // Test-mode rehearsals — older than anything live where age matters.
            $this->join( 'review', 0, [ 'created_at' => '2026-09-01 10:00:00' ] ),
            $this->join( 'paid', 0 ),
            $this->join( 'fulfilling', 0, [ 'updated_at' => '2026-09-29 11:00:00' ] ),
            $this->join( 'fulfilled', 0, [ 'progress' => json_encode( [ 'held' => [ 5 => 'x' ] ] ), 'fulfilled_at' => '2026-05-05 10:00:00' ] ),
            $this->join( 'fulfilled', 0, [ 'fulfilled_at' => '2026-05-06 10:00:00' ] ),
            $this->join( 'processing', 0, [ 'created_at' => '2026-08-01 00:00:00' ] ),
            $this->join( 'pending', 0 ),
            $this->join( 'failed', 0 ),
        ] );
        return $db;
    }

    /**
     * The section a queue-free database reads as — and the base the
     * expectations below override. array_merge keeps the key order, so
     * assertSame on the whole thing also pins the shape.
     *
     * @param array<string,mixed> $overrides Keyed by 'applications', 'joins', or a top-level key.
     * @return array<string,mixed>
     */
    private function zero( array $overrides = [] ): array {
        $zero = [
            'year'            => self::YEAR,
            'applications'    => [ 'pending' => 0, 'oldest_days' => null, 'approved_year' => 0 ],
            'joins'           => [
                'needs_attention'      => 0,
                'review'               => 0,
                'paid'                 => 0,
                'paid_held'            => 0,
                'other_attention'      => 0,
                'awaiting_payment'     => 0,
                'clearing'             => 0,
                'oldest_clearing_days' => null,
                'failed'               => 0,
                'joined_year'          => 0,
            ],
            'total_attention' => 0,
            'join_problem'    => '',
        ];
        foreach ( $overrides as $key => $value ) {
            $zero[ $key ] = is_array( $value ) && isset( $zero[ $key ] ) && is_array( $zero[ $key ] ) ? array_merge( $zero[ $key ], $value ) : $value;
        }
        return $zero;
    }

    // -------------------------------------------------------------------
    // SQL emission — the join table
    // -------------------------------------------------------------------

    /** The zero-argument read is the statement the Online joins tab has always sent: unscoped and not prepared. */
    public function testJoinCountsWithNoModeAreTheOldUnscopedStatement(): void {
        $db = $this->recorder();
        $this->with_db( $db, function (): void {
            MyNJILGA_Join_Orders_Table::counts_by_status();
        } );

        $this->assertSame( [ 'SELECT status, COUNT(*) AS c FROM ' . self::JOINS . ' GROUP BY status' ], $db->queries );
        $this->assertSame( [], $db->prepared, 'The zero-argument read has no placeholder to prepare' );
    }

    /** An explicit null is the same "every row" read, not a default to live. */
    public function testJoinCountsWithAnExplicitNullAreTheSameStatement(): void {
        $sql = $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::counts_by_status( null );
        } );
        $this->assertSame( 'SELECT status, COUNT(*) AS c FROM ' . self::JOINS . ' GROUP BY status', $sql );
    }

    public function testJoinCountsScopeToTheModeAsked(): void {
        foreach ( [ true, false ] as $live ) {
            $sql = $this->sql_of( function () use ( $live ): void {
                MyNJILGA_Join_Orders_Table::counts_by_status( $live );
            } );

            $this->assertSame( 'SELECT status, COUNT(*) AS c FROM ' . self::JOINS . ' WHERE livemode = ' . ( $live ? '1' : '0' ) . ' GROUP BY status', $sql );
            $this->assertSqlLacks( $live ? 'livemode = 0' : 'livemode = 1', $sql );
        }
    }

    /**
     * The attention statement with no mode: pinned to the byte. This is the
     * SQL behind the menu bubble and the Online joins tab, both of which
     * call needs_attention_count() with no argument — it must not move.
     * (Checked against the pre-change class when it was written.)
     */
    public function testAttentionSqlWithNoModeIsTheOldStatementByteForByte(): void {
        $sql = $this->attention_sql( null );

        $this->assertSame(
            "SELECT COUNT(*) FROM wp_njilga_join_orders WHERE status IN ('review', 'paid') OR ( status = 'fulfilling' AND updated_at < '2026-09-21 14:03:20' ) OR ( status = 'fulfilled' AND ( progress LIKE '%\"held\":{%' OR progress LIKE '%\"already\\_current\":[\"%' OR progress LIKE '%\"checks\":[\"%' OR progress LIKE '%\"document\\_unverified\":true%' OR ( last_error IS NOT NULL AND last_error <> '' ) ) )",
            $sql
        );
        $this->assertSqlLacks( 'livemode', $sql );
    }

    /**
     * With a mode the WHOLE predicate sits inside parentheses behind the
     * mode test. Without them `livemode = 1 AND status IN (...) OR ...`
     * would let every OR-branch through for the other mode too.
     */
    public function testAttentionSqlWithAModeWrapsTheWholePredicateBehindIt(): void {
        $bare      = $this->attention_sql( null );
        $predicate = substr( $bare, strlen( 'SELECT COUNT(*) FROM ' . self::JOINS . ' WHERE ' ) );

        foreach ( [ true, false ] as $live ) {
            $sql = $this->attention_sql( $live );

            $this->assertSame(
                'SELECT COUNT(*) FROM ' . self::JOINS . ' WHERE livemode = ' . ( $live ? '1' : '0' ) . ' AND ( ' . $predicate . ' )',
                $sql
            );
            $this->assertSame( 1, substr_count( $sql, 'livemode' ), 'One mode test, in front' );
            $this->assertSqlLacks( $live ? 'livemode = 0' : 'livemode = 1', $sql );
        }
    }

    public function testOldestCreatedIsScopedToTheModeOnlyWhenAskedAndAlwaysToTheStatus(): void {
        $base = 'SELECT MIN(created_at) FROM ' . self::JOINS . " WHERE status = 'processing'";

        $this->assertSame( $base, $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::oldest_created( 'processing' );
        } ) );
        $this->assertSame( $base, $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::oldest_created( 'processing', null );
        } ) );
        $this->assertSame( $base . ' AND livemode = 1', $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::oldest_created( 'processing', true );
        } ) );
        $this->assertSame( $base . ' AND livemode = 0', $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::oldest_created( 'processing', false );
        } ) );
    }

    /** None waiting reads as null however the database says it (NULL, '', a string that is not there). */
    public function testOldestCreatedIsNullWhenThereIsNone(): void {
        $stub = new class extends NJILGA_Recording_Wpdb {
            /** @var mixed */
            public $answer = null;

            public function get_var( string $query ) {
                $this->queries[] = $query;
                return $this->answer;
            }
        };
        $this->with_db( $stub, function () use ( $stub ): void {
            foreach ( [ null, '' ] as $none ) {
                $stub->answer = $none;
                $this->assertSame( null, MyNJILGA_Join_Orders_Table::oldest_created( 'review', true ) );
                $this->assertSame( null, MyNJILGA_Applications_Table::oldest_pending_created() );
            }
            $stub->answer = '2026-09-20 09:00:00';
            $this->assertSame( '2026-09-20 09:00:00', MyNJILGA_Join_Orders_Table::oldest_created( 'review', true ) );
            $this->assertSame( '2026-09-20 09:00:00', MyNJILGA_Applications_Table::oldest_pending_created() );
        } );
    }

    /**
     * Joined-this-year is a real COUNT of fulfilled joins in the calendar
     * year: [Jan 1, Jan 1 next year) on fulfilled_at, the mode only when
     * asked for.
     */
    public function testJoinedCountIsACalendarYearWindowOverFulfilledAt(): void {
        $base = 'SELECT COUNT(*) FROM ' . self::JOINS . " WHERE status = 'fulfilled' AND fulfilled_at >= '2026-01-01 00:00:00' AND fulfilled_at < '2027-01-01 00:00:00'";

        $this->assertSame( $base, $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::joined_count( 2026, null );
        } ) );
        $this->assertSame( $base . ' AND livemode = 1', $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::joined_count( 2026, true );
        } ) );
        $this->assertSame( $base . ' AND livemode = 0', $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::joined_count( 2026, false );
        } ) );
    }

    /** Neither the dues year nor a row limit: a join that pays for NEXT year still counts when it was applied. */
    public function testJoinedCountNeverReadsTheDuesYearOrLoadsRows(): void {
        $sql = $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::joined_count( 2026, true );
        } );
        $this->assertSqlLacks( 'dues_year', $sql );
        $this->assertSqlLacks( 'LIMIT', $sql );
        $this->assertSqlLacks( '*', str_replace( 'COUNT(*)', 'COUNT()', $sql ) );
    }

    /** The paid-joins read is only what payment_settled() needs, by mode when asked. */
    public function testPaidProgressReadsOnlyWhatPaymentSettledNeeds(): void {
        $base = 'SELECT id, status, progress FROM ' . self::JOINS . " WHERE status = 'paid'";

        $this->assertSame( $base . ' ORDER BY id ASC', $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::get_paid_progress();
        } ) );
        $this->assertSame( $base . ' AND livemode = 1 ORDER BY id ASC', $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::get_paid_progress( true );
        } ) );
        $this->assertSame( $base . ' AND livemode = 0 ORDER BY id ASC', $this->sql_of( function (): void {
            MyNJILGA_Join_Orders_Table::get_paid_progress( false );
        } ) );
    }

    // -------------------------------------------------------------------
    // SQL emission — the applications table
    // -------------------------------------------------------------------

    public function testApplicationReadsAreModeLessAndNarrow(): void {
        $this->assertSame( 'SELECT status, COUNT(*) AS c FROM ' . self::APPS . ' GROUP BY status', $this->sql_of( function (): void {
            MyNJILGA_Applications_Table::counts_by_status();
        } ) );
        $this->assertSame( 'SELECT MIN(created_at) FROM ' . self::APPS . " WHERE status = 'pending'", $this->sql_of( function (): void {
            MyNJILGA_Applications_Table::oldest_pending_created();
        } ) );
        $this->assertSame(
            'SELECT COUNT(*) FROM ' . self::APPS . " WHERE status = 'approved' AND decided_at >= '2026-01-01 00:00:00'",
            $this->sql_of( function (): void {
                MyNJILGA_Applications_Table::approved_since( '2026-01-01 00:00:00' );
            } )
        );
    }

    // -------------------------------------------------------------------
    // Pure: age
    // -------------------------------------------------------------------

    public function testAgeIsWholeDaysFlooredAtTheBoundaries(): void {
        $now = $this->now(); // 2026-09-29 12:00:00

        $cases = [
            '2026-09-29 12:00:00' => 0, // the same instant
            '2026-09-28 12:00:01' => 0, // 23h59m59s ago
            '2026-09-28 12:00:00' => 1, // exactly one day
            '2026-09-27 12:00:01' => 1, // 1d 23h59m59s
            '2026-09-27 12:00:00' => 2, // exactly two days
            '2026-09-28'          => 1, // a bare date is midnight: 36 hours ago
            '2026-09-29'          => 0,
            '2025-09-29 12:00:00' => 365,
        ];
        foreach ( $cases as $stored => $days ) {
            $this->assertSame( $days, MyNJILGA_Application_Stats::age_days( $stored, $now ), $stored );
        }
    }

    /** A clock that moved, or a row stamped a moment after $now was read: 0 days, never a negative and never null. */
    public function testAgeOfAFutureTimestampIsZeroNotNegative(): void {
        $now = $this->now();
        foreach ( [ '2026-09-29 12:00:01', '2026-09-30 12:00:00', '2027-01-01 00:00:00', '2999-12-31 23:59:59' ] as $stored ) {
            $this->assertSame( 0, MyNJILGA_Application_Stats::age_days( $stored, $now ), $stored );
        }
    }

    public function testAgeIsNullWhenThereIsNoRealDateToAge(): void {
        $now = $this->now();
        foreach ( [ null, '', '   ', '0000-00-00 00:00:00', '0000-00-00', 'not a date', '2026-02-30 10:00:00', '2026-13-01 00:00:00', '2026-09-29 24:00:00', '2026-09-29 12:60:00', '2026-09-29 12:00:60', '29/09/2026' ] as $bad ) {
            $this->assertSame( null, MyNJILGA_Application_Stats::age_days( $bad, $now ), var_export( $bad, true ) );
        }
    }

    /**
     * Stored DATETIMEs and current_time( 'timestamp' ) are both wall clock,
     * so the answer must not move with PHP's default timezone (a strtotime()
     * of the stored string would).
     */
    public function testAgeDoesNotDependOnPhpsTimezone(): void {
        $saved = date_default_timezone_get();
        try {
            foreach ( [ 'UTC', 'America/New_York', 'Pacific/Auckland' ] as $zone ) {
                date_default_timezone_set( $zone );
                $this->assertSame( 1, MyNJILGA_Application_Stats::age_days( '2026-09-28 12:00:00', $this->now() ), $zone );
                $this->assertSame( 0, MyNJILGA_Application_Stats::age_days( '2026-09-28 12:00:01', $this->now() ), $zone );
            }
        } finally {
            date_default_timezone_set( $saved );
        }
    }

    public function testYearStartIsMidnightOnJanFirst(): void {
        $this->assertSame( '2026-01-01 00:00:00', MyNJILGA_Application_Stats::year_start( 2026 ) );
        $this->assertSame( '0999-01-01 00:00:00', MyNJILGA_Application_Stats::year_start( 999 ) );
    }

    // -------------------------------------------------------------------
    // Pure: the breakdown sentence
    // -------------------------------------------------------------------

    public function testSentenceIsEmptyWhenNothingNeedsAPerson(): void {
        $this->assertSame( '', MyNJILGA_Application_Stats::attention_sentence( [] ) );
        $this->assertSame( '', MyNJILGA_Application_Stats::attention_sentence( [ 'review' => 0, 'paid' => 0, 'paid_held' => 0, 'other_attention' => 0 ] ) );
        // Not attention, so not in the sentence however many there are.
        $this->assertSame( '', MyNJILGA_Application_Stats::attention_sentence( [ 'awaiting_payment' => 4, 'clearing' => 3, 'failed' => 2, 'joined_year' => 50 ] ) );
    }

    public function testSentenceReadsTheDocumentedExample(): void {
        $this->assertSame(
            '2 $0 joins awaiting your decision · 1 paid, not applied · 1 to review',
            MyNJILGA_Application_Stats::attention_sentence( [ 'review' => 2, 'paid' => 1, 'other_attention' => 1 ] )
        );
    }

    public function testSentenceOmitsZeroTermsAndAgreesInNumber(): void {
        $s = 'MyNJILGA_Application_Stats::attention_sentence';
        $this->assertSame( '1 $0 join awaiting your decision', $s( [ 'review' => 1 ] ) );
        $this->assertSame( '3 $0 joins awaiting your decision', $s( [ 'review' => 3 ] ) );
        $this->assertSame( '1 paid, not applied', $s( [ 'paid' => 1 ] ) );
        $this->assertSame( '4 paid, not applied', $s( [ 'paid' => 4 ] ) );
        $this->assertSame( '1 to review', $s( [ 'other_attention' => 1 ] ) );
        $this->assertSame( '1 $0 join awaiting your decision · 2 to review', $s( [ 'review' => 1, 'paid' => 0, 'other_attention' => 2 ] ) );
        $this->assertSame( '3 paid, not applied · 1 to review', $s( [ 'review' => 0, 'paid' => 3, 'other_attention' => 1 ] ) );
    }

    /** Held payments are called out on the paid term — Retry applying can't move them — and never beyond the paid count. */
    public function testSentenceSaysWhenPaidJoinsAreHeld(): void {
        $s = 'MyNJILGA_Application_Stats::attention_sentence';
        $this->assertSame( '3 paid, not applied (1 held: checkout didn\'t match)', $s( [ 'paid' => 3, 'paid_held' => 1 ] ) );
        $this->assertSame( '2 paid, not applied (2 held: checkout didn\'t match)', $s( [ 'paid' => 2, 'paid_held' => 9 ] ), 'held is a subset of paid' );
        $this->assertSame( '2 paid, not applied', $s( [ 'paid' => 2, 'paid_held' => 0 ] ) );
        $this->assertSame( '', $s( [ 'paid' => 0, 'paid_held' => 4 ] ), 'held without paid is nothing' );
    }

    public function testSentenceTreatsNegativesAndJunkAsZero(): void {
        $this->assertSame( '', MyNJILGA_Application_Stats::attention_sentence( [ 'review' => -2, 'paid' => -1, 'other_attention' => -7, 'paid_held' => -1 ] ) );
        $this->assertSame( '2 to review', MyNJILGA_Application_Stats::attention_sentence( [ 'review' => 'x', 'paid' => null, 'other_attention' => '2' ] ) );
    }

    // -------------------------------------------------------------------
    // Pure: join problem
    // -------------------------------------------------------------------

    public function testNoJoinProblemWhenEnabledConnectedAndAllowed(): void {
        foreach ( [ true, false ] as $live ) {
            $this->assertSame( '', MyNJILGA_Application_Stats::join_problem_for( true, true, true, $live ) );
            // No answer on file never raises a problem.
            $this->assertSame( '', MyNJILGA_Application_Stats::join_problem_for( true, true, null, $live ) );
        }
    }

    public function testJoinProblemNamesTheFirstThingWrong(): void {
        $p = 'MyNJILGA_Application_Stats::join_problem_for';

        $this->assertSame( 'Online joining is switched off in Settings.', $p( false, true, true, true ) );
        $this->assertSame( 'Stripe Live mode isn\'t connected.', $p( true, false, null, true ) );
        $this->assertSame( 'Stripe Test mode isn\'t connected.', $p( true, false, null, false ) );
        $this->assertSame( 'The Stripe Live key can\'t create Checkout Sessions.', $p( true, true, false, true ) );
        $this->assertSame( 'The Stripe Test key can\'t create Checkout Sessions.', $p( true, true, false, false ) );

        // Precedence: switched off, then not connected, then no Checkout permission.
        $this->assertSame( 'Online joining is switched off in Settings.', $p( false, false, false, true ) );
        $this->assertSame( 'Stripe Live mode isn\'t connected.', $p( true, false, false, true ) );
    }

    public function testCheckoutVerdictReadsTheStoredAnswerPerMode(): void {
        $stored = [
            'live' => [ 'state' => 'yes', 'next_check' => 5, 'checked_at' => 1 ],
            'test' => [ 'state' => 'no' ],
        ];
        $this->assertSame( true, MyNJILGA_Application_Stats::checkout_verdict( $stored, 'live' ) );
        $this->assertSame( false, MyNJILGA_Application_Stats::checkout_verdict( $stored, 'test' ) );

        // No answer: an unknown state, a missing mode, or an option that isn't there / isn't an array.
        $this->assertSame( null, MyNJILGA_Application_Stats::checkout_verdict( [ 'live' => [ 'state' => 'unknown' ] ], 'live' ) );
        $this->assertSame( null, MyNJILGA_Application_Stats::checkout_verdict( [ 'live' => [ 'state' => 'yes' ] ], 'test' ) );
        $this->assertSame( null, MyNJILGA_Application_Stats::checkout_verdict( [ 'live' => 'yes' ], 'live' ) );
        $this->assertSame( null, MyNJILGA_Application_Stats::checkout_verdict( [ 'live' => [] ], 'live' ) );
        $this->assertSame( null, MyNJILGA_Application_Stats::checkout_verdict( [], 'live' ) );
        $this->assertSame( null, MyNJILGA_Application_Stats::checkout_verdict( '', 'live' ) );
        $this->assertSame( null, MyNJILGA_Application_Stats::checkout_verdict( false, 'live' ) );
    }

    // -------------------------------------------------------------------
    // Pure: held payments
    // -------------------------------------------------------------------

    public function testHeldMeansPaidButNotSettled(): void {
        $paid = static function ( $progress ): object {
            return (object) [ 'status' => 'paid', 'progress' => $progress ];
        };
        $rows = [
            $paid( null ),                                                   // held: never verified
            $paid( '' ),                                                     // held
            $paid( 'not json' ),                                             // held: nothing usable recorded
            $paid( json_encode( [ 'contact_id' => 0 ] ) ),                   // held: an empty contact id proves nothing
            $paid( json_encode( [ 'payment_confirmed' => true ] ) ),         // settled: verified, applying failed part-way
            $paid( json_encode( [ 'contact_id' => 41 ] ) ),                  // settled: got as far as the payer's contact
            (object) [ 'status' => 'pending', 'progress' => null ],          // not paid: never judged
            (object) [ 'status' => 'processing', 'progress' => '{}' ],
        ];

        $this->assertSame( 4, MyNJILGA_Application_Stats::count_held( $rows ) );
        $this->assertSame( 0, MyNJILGA_Application_Stats::count_held( [] ) );
        $this->assertSame( 0, MyNJILGA_Application_Stats::count_held( array_slice( $rows, 4, 2 ) ) );
    }

    // -------------------------------------------------------------------
    // Pure: the rollup
    // -------------------------------------------------------------------

    /** Nothing at all reads as zeros and nulls, in the documented shape. */
    public function testRollupOfNothingIsTheZeroSnapshot(): void {
        $this->assertSame( $this->zero( [ 'year' => 0 ] ), MyNJILGA_Application_Stats::rollup( [], $this->now() ) );
    }

    public function testRollupKeysAreTheContract(): void {
        $s = MyNJILGA_Application_Stats::rollup( [ 'year' => self::YEAR ], $this->now() );

        $this->assertSame( [ 'year', 'applications', 'joins', 'total_attention', 'join_problem' ], array_keys( $s ) );
        $this->assertSame( [ 'pending', 'oldest_days', 'approved_year' ], array_keys( $s['applications'] ) );
        $this->assertSame(
            [ 'needs_attention', 'review', 'paid', 'paid_held', 'other_attention', 'awaiting_payment', 'clearing', 'oldest_clearing_days', 'failed', 'joined_year' ],
            array_keys( $s['joins'] )
        );
    }

    public function testRollupSplitsAttentionIntoReviewPaidAndOther(): void {
        $s = MyNJILGA_Application_Stats::rollup( [
            'year'                 => self::YEAR,
            'app_counts'           => [ 'pending' => 3 ],
            'join_counts'          => [ 'review' => 2, 'paid' => 2 ],
            'join_needs_attention' => 7,
        ], $this->now() );

        $this->assertSame( 7, $s['joins']['needs_attention'] );
        $this->assertSame( 2, $s['joins']['review'] );
        $this->assertSame( 2, $s['joins']['paid'] );
        $this->assertSame( 3, $s['joins']['other_attention'], '7 - 2 review - 2 paid' );
        $this->assertSame( 10, $s['total_attention'], '3 applications + 7 joins' );
    }

    /**
     * Two statements, two moments: a join that moved between them must not
     * print a negative — and the reported needs_attention stays as read.
     */
    public function testOtherAttentionIsFlooredAtZero(): void {
        $s = MyNJILGA_Application_Stats::rollup( [
            'join_counts'          => [ 'review' => 2, 'paid' => 2 ],
            'join_needs_attention' => 1,
        ], $this->now() );

        $this->assertSame( 0, $s['joins']['other_attention'] );
        $this->assertSame( 1, $s['joins']['needs_attention'] );
        $this->assertSame( 1, $s['total_attention'] );

        $exact = MyNJILGA_Application_Stats::rollup( [ 'join_counts' => [ 'review' => 2, 'paid' => 2 ], 'join_needs_attention' => 4 ], $this->now() );
        $this->assertSame( 0, $exact['joins']['other_attention'], 'review + paid is all of it' );
    }

    /** Only review, paid, pending, processing and failed are read; the terminal and applied statuses are none of it. */
    public function testOnlyTheNamedStatusesFeedTheFigures(): void {
        $s = MyNJILGA_Application_Stats::rollup( [
            'app_counts'  => [ 'approved' => 40, 'rejected' => 9, 'superseded' => 5 ],
            'join_counts' => [ 'fulfilled' => 60, 'fulfilling' => 3, 'expired' => 8, 'abandoned' => 11, 'rejected' => 2 ],
        ], $this->now() );

        $this->assertSame( $this->zero( [ 'year' => 0 ] ), $s );
    }

    public function testPendingProcessingAndFailedAreInformationNotAttention(): void {
        $s = MyNJILGA_Application_Stats::rollup( [
            'join_counts'          => [ 'pending' => 6, 'processing' => 4, 'failed' => 9 ],
            'join_needs_attention' => 0,
        ], $this->now() );

        $this->assertSame( 6, $s['joins']['awaiting_payment'] );
        $this->assertSame( 4, $s['joins']['clearing'] );
        $this->assertSame( 9, $s['joins']['failed'] );
        $this->assertSame( 0, $s['joins']['needs_attention'] );
        $this->assertSame( 0, $s['total_attention'] );
    }

    public function testPaidHeldIsASubsetOfPaid(): void {
        $held = static function ( int $paid, int $held ): int {
            return MyNJILGA_Application_Stats::rollup( [ 'join_counts' => [ 'paid' => $paid ], 'join_paid_held' => $held ], 1790000000 )['joins']['paid_held'];
        };
        $this->assertSame( 1, $held( 3, 1 ) );
        $this->assertSame( 3, $held( 3, 3 ) );
        $this->assertSame( 1, $held( 1, 5 ) );
        $this->assertSame( 0, $held( 0, 3 ) );
        $this->assertSame( 0, $held( 3, -2 ) );
    }

    /** An age is printed only for a queue with something in it. */
    public function testAgesAreOnlyReportedForANonEmptyQueue(): void {
        $now  = $this->now();
        $with = MyNJILGA_Application_Stats::rollup( [
            'app_counts'           => [ 'pending' => 1 ],
            'app_oldest_pending'   => '2026-09-20 09:00:00',
            'join_counts'          => [ 'processing' => 1 ],
            'join_oldest_clearing' => '2026-09-24 08:00:00',
        ], $now );
        $this->assertSame( 9, $with['applications']['oldest_days'] );
        $this->assertSame( 5, $with['joins']['oldest_clearing_days'] );

        $stale = MyNJILGA_Application_Stats::rollup( [
            'app_counts'           => [ 'pending' => 0 ],
            'app_oldest_pending'   => '2026-09-20 09:00:00',
            'join_counts'          => [ 'processing' => 0 ],
            'join_oldest_clearing' => '2026-09-24 08:00:00',
        ], $now );
        $this->assertSame( null, $stale['applications']['oldest_days'] );
        $this->assertSame( null, $stale['joins']['oldest_clearing_days'] );

        // Something is waiting but its date is unusable: no age, not a made-up 0.
        $bad = MyNJILGA_Application_Stats::rollup( [
            'app_counts'           => [ 'pending' => 2 ],
            'app_oldest_pending'   => '0000-00-00 00:00:00',
            'join_counts'          => [ 'processing' => 2 ],
            'join_oldest_clearing' => null,
        ], $now );
        $this->assertSame( null, $bad['applications']['oldest_days'] );
        $this->assertSame( null, $bad['joins']['oldest_clearing_days'] );
    }

    public function testRollupCarriesYearJoinedApprovedAndTheProblemThrough(): void {
        $s = MyNJILGA_Application_Stats::rollup( [
            'year'              => 2031,
            'app_approved_year' => 12,
            'join_joined_year'  => 34,
            'join_problem'      => 'Stripe Live mode isn\'t connected.',
        ], $this->now() );

        $this->assertSame( 2031, $s['year'] );
        $this->assertSame( 12, $s['applications']['approved_year'] );
        $this->assertSame( 34, $s['joins']['joined_year'] );
        $this->assertSame( 'Stripe Live mode isn\'t connected.', $s['join_problem'] );
    }

    // -------------------------------------------------------------------
    // End to end: build() over a fake database
    // -------------------------------------------------------------------

    /** LIVE: every application, but only the live joins — every Test row on every branch stays out. */
    public function testALiveSnapshotCountsLiveJoinsOnly(): void {
        $db = $this->fixture_db();
        $s  = $this->with_db( $db, function (): array {
            return MyNJILGA_Application_Stats::build( true, self::YEAR, $this->now() );
        } );

        $this->assertSame( $this->zero( [
            'applications'    => [ 'pending' => 2, 'oldest_days' => 9, 'approved_year' => 2 ],
            'joins'           => [
                'needs_attention'      => 7,
                'review'               => 2,
                'paid'                 => 2,
                'paid_held'            => 1,
                'other_attention'      => 3,
                'awaiting_payment'     => 3,
                'clearing'             => 2,
                'oldest_clearing_days' => 5,
                'failed'               => 1,
                'joined_year'          => 4,
            ],
            'total_attention' => 9,
        ] ), $s );
    }

    /** TEST: the mirror image — the rehearsal rows, none of the live ones; the applications are the same. */
    public function testATestModeSnapshotCountsTestJoinsOnly(): void {
        $db = $this->fixture_db();
        $s  = $this->with_db( $db, function (): array {
            return MyNJILGA_Application_Stats::build( false, self::YEAR, $this->now() );
        } );

        $this->assertSame( $this->zero( [
            'applications'    => [ 'pending' => 2, 'oldest_days' => 9, 'approved_year' => 2 ],
            'joins'           => [
                'needs_attention'      => 4,
                'review'               => 1,
                'paid'                 => 1,
                'paid_held'            => 1,
                'other_attention'      => 2,
                'awaiting_payment'     => 1,
                'clearing'             => 1,
                'oldest_clearing_days' => 59,
                'failed'               => 1,
                'joined_year'          => 2,
            ],
            'total_attention' => 6,
        ] ), $s );
    }

    /** A Test-mode row on each branch of the attention predicate: none reaches a Live count, and vice versa. */
    public function testEachModeSeesNoneOfTheOthersAttentionRows(): void {
        $db = $this->fixture_db();
        $this->with_db( $db, function (): void {
            $live = MyNJILGA_Application_Stats::build( true, self::YEAR, $this->now() )['joins'];
            $test = MyNJILGA_Application_Stats::build( false, self::YEAR, $this->now() )['joins'];

            // 7 live + 4 test attention rows in the table; each mode reads only its own.
            $this->assertSame( 7, $live['needs_attention'] );
            $this->assertSame( 4, $test['needs_attention'] );
            // The stale-fulfilling and flagged-fulfilled branches, where a missing parenthesis leaks.
            $this->assertSame( 3, $live['other_attention'] );
            $this->assertSame( 2, $test['other_attention'] );
        } );
    }

    /** The year window is [Jan 1, Jan 1): fulfilled 2025-12-31 23:59:59 and 2027-01-01 00:00:00 are out, 2026-01-01 00:00:00 is in. */
    public function testJoinedAndApprovedRespectTheCalendarYearEdges(): void {
        $db = $this->fixture_db();
        $this->with_db( $db, function (): void {
            $this->assertSame( 4, MyNJILGA_Join_Orders_Table::joined_count( 2026, true ) );
            $this->assertSame( 2, MyNJILGA_Join_Orders_Table::joined_count( 2026, false ) );
            $this->assertSame( 6, MyNJILGA_Join_Orders_Table::joined_count( 2026, null ), 'no mode = both' );
            $this->assertSame( 1, MyNJILGA_Join_Orders_Table::joined_count( 2025, true ) );
            $this->assertSame( 1, MyNJILGA_Join_Orders_Table::joined_count( 2027, true ) );
            $this->assertSame( 0, MyNJILGA_Join_Orders_Table::joined_count( 2024, true ) );

            $this->assertSame( 2, MyNJILGA_Applications_Table::approved_since( '2026-01-01 00:00:00' ) );
            $this->assertSame( 3, MyNJILGA_Applications_Table::approved_since( '2025-01-01 00:00:00' ) );
            $this->assertSame( 0, MyNJILGA_Applications_Table::approved_since( '2026-03-05 10:00:01' ) );
        } );
    }

    /** "A handful of COUNTs": eight statements at most, and never a row body. */
    public function testASnapshotIsAHandfulOfCountsAndLoadsNoRowBodies(): void {
        $db = $this->fixture_db();
        $this->with_db( $db, function (): void {
            MyNJILGA_Application_Stats::build( true, self::YEAR, $this->now() );
        } );

        $this->assertCount( 8, $db->queries );
        foreach ( $db->queries as $sql ) {
            $this->assertSqlLacks( 'SELECT *', $sql );
            $this->assertSqlLacks( 'applicant', $sql );
            $this->assertSqlLacks( 'message', $sql );
        }
        // The one non-aggregate read is the paid joins' id, status and progress.
        $nonAggregate = array_values( array_filter( $db->queries, static function ( string $sql ): bool {
            return strpos( $sql, 'COUNT(' ) === false && strpos( $sql, 'MIN(' ) === false;
        } ) );
        $this->assertCount( 1, $nonAggregate );
        $this->assertSqlHas( 'SELECT id, status, progress FROM', $nonAggregate[0] );
    }

    /** No join read in a snapshot is unscoped: each carries its mode and never the other. */
    public function testEveryJoinStatementInASnapshotCarriesItsMode(): void {
        foreach ( [ true, false ] as $live ) {
            $db = $this->fixture_db();
            $this->with_db( $db, function () use ( $live ): void {
                MyNJILGA_Application_Stats::build( $live, self::YEAR, $this->now() );
            } );

            $joinStatements = 0;
            foreach ( $db->queries as $sql ) {
                if ( strpos( $sql, self::JOINS ) === false ) {
                    $this->assertSqlLacks( 'livemode', $sql ); // applications have no mode
                    continue;
                }
                $joinStatements++;
                $this->assertSqlHas( $live ? 'livemode = 1' : 'livemode = 0', $sql );
                $this->assertSqlLacks( $live ? 'livemode = 0' : 'livemode = 1', $sql );
            }
            $this->assertSame( 5, $joinStatements, 'counts, attention, oldest clearing, joined, paid progress' );
        }
    }

    /** An empty database: all zeros, and the three "only when there is something" reads are skipped. */
    public function testAnEmptyDatabaseIsAllZerosInFiveStatements(): void {
        $db = new ApplicationStatsFakeWpdb();
        $s  = $this->with_db( $db, function (): array {
            return MyNJILGA_Application_Stats::build( true, self::YEAR, $this->now() );
        } );

        $this->assertSame( $this->zero(), $s );
        $this->assertCount( 5, $db->queries, 'no oldest-pending, oldest-clearing or paid-progress read when there is nothing to age or judge' );
    }

    public function testTheJoinProblemPassesThroughUntouched(): void {
        $db = new ApplicationStatsFakeWpdb();
        $s  = $this->with_db( $db, function (): array {
            return MyNJILGA_Application_Stats::build( true, self::YEAR, $this->now(), 'Online joining is switched off in Settings.' );
        } );
        $this->assertSame( 'Online joining is switched off in Settings.', $s['join_problem'] );
    }

    /**
     * The menu bubble is count_pending() plus the UNSCOPED attention count,
     * so it can only ever read at or above the Dashboard — by exactly the
     * other mode's attention rows, here the Test rehearsals (4) a Live
     * Dashboard leaves out.
     */
    public function testTheMenuBubbleIsTheUnscopedSumAndNeverBelowTheDashboard(): void {
        $db = $this->fixture_db();
        $this->with_db( $db, function () use ( $db ): void {
            $badge = MyNJILGA_Applications_Table::count_pending()
                + (int) $db->get_var( MyNJILGA_Join_Orders_Table::needs_attention_sql( null, $this->now() ) );

            $live = MyNJILGA_Application_Stats::build( true, self::YEAR, $this->now() );
            $test = MyNJILGA_Application_Stats::build( false, self::YEAR, $this->now() );

            $this->assertSame( 13, $badge, '2 pending + 11 attention joins across both modes' );
            $this->assertSame( 9, $live['total_attention'] );
            $this->assertSame( 6, $test['total_attention'] );
            $this->assertTrue( $badge >= $live['total_attention'] && $badge >= $test['total_attention'] );
            $this->assertSame( 4, $badge - $live['total_attention'], 'a Live Dashboard omits exactly the Test rows' );
            $this->assertSame( 7, $badge - $test['total_attention'], 'and a Test one exactly the Live rows' );
            // Applications are mode-less, so the snapshot's figure IS the badge's application half.
            $this->assertSame( MyNJILGA_Applications_Table::count_pending(), $live['applications']['pending'] );
        } );
    }

    // -------------------------------------------------------------------
    // Parity: the SQL predicate and its PHP twin
    // -------------------------------------------------------------------

    /**
     * The attention SQL and MyNJILGA_Join_Orders_Table::needs_attention()
     * are two spellings of one rule (the SQL matches substrings of
     * wp_json_encode output, the PHP tests ! empty()), and agree only while
     * `held` encodes as an object and the lists hold strings. Run the SQL's
     * predicate over each row shape and compare it with the twin. A claim
     * still being worked (fulfilling) is left to the twin's clock and is
     * covered by the counts above.
     */
    public function testTheAttentionSqlAgreesWithNeedsAttentionRowByRow(): void {
        $sql   = $this->attention_sql( null );
        $where = substr( $sql, strlen( 'SELECT COUNT(*) FROM ' . self::JOINS . ' WHERE ' ) );
        $db    = new ApplicationStatsFakeWpdb();

        $rows = [
            'review'                         => $this->join( 'review', 1 ),
            'paid'                           => $this->join( 'paid', 1 ),
            'pending'                        => $this->join( 'pending', 1 ),
            'processing'                     => $this->join( 'processing', 1 ),
            'failed'                         => $this->join( 'failed', 1 ),
            'expired'                        => $this->join( 'expired', 1 ),
            'abandoned'                      => $this->join( 'abandoned', 1 ),
            'rejected'                       => $this->join( 'rejected', 1 ),
            'fulfilled, clean'               => $this->join( 'fulfilled', 1 ),
            'fulfilled, empty error'         => $this->join( 'fulfilled', 1, [ 'last_error' => '' ] ),
            'fulfilled, error text'          => $this->join( 'fulfilled', 1, [ 'last_error' => 'boom' ] ),
            'fulfilled, held people'         => $this->join( 'fulfilled', 1, [ 'progress' => json_encode( [ 'held' => [ 7 => 'Jo Bloggs' ] ] ) ] ),
            'fulfilled, held empty list'     => $this->join( 'fulfilled', 1, [ 'progress' => json_encode( [ 'held' => [] ] ) ] ),
            'fulfilled, already current'     => $this->join( 'fulfilled', 1, [ 'progress' => json_encode( [ 'already_current' => [ 'a@example.com' ] ] ) ] ),
            'fulfilled, already empty list'  => $this->join( 'fulfilled', 1, [ 'progress' => json_encode( [ 'already_current' => [] ] ) ] ),
            'fulfilled, checks'              => $this->join( 'fulfilled', 1, [ 'progress' => json_encode( [ 'checks' => [ 'b@example.com' ] ] ) ] ),
            'fulfilled, checks empty list'   => $this->join( 'fulfilled', 1, [ 'progress' => json_encode( [ 'checks' => [] ] ) ] ),
            'fulfilled, document unverified' => $this->join( 'fulfilled', 1, [ 'progress' => json_encode( [ 'document_unverified' => true ] ) ] ),
            'fulfilled, document verified'   => $this->join( 'fulfilled', 1, [ 'progress' => json_encode( [ 'document_unverified' => false ] ) ] ),
            'fulfilled, unrelated progress'  => $this->join( 'fulfilled', 1, [ 'progress' => json_encode( [ 'contact_id' => 9, 'payment_confirmed' => true ] ) ] ),
        ];
        foreach ( $rows as $label => $row ) {
            $this->assertSame(
                MyNJILGA_Join_Orders_Table::needs_attention( (object) $row ),
                $db->evaluate( $where, $row ),
                'SQL and PHP disagree on: ' . $label
            );
        }
        // Sanity: the list is not all one answer.
        $yes = array_filter( $rows, static function ( array $row ): bool {
            return MyNJILGA_Join_Orders_Table::needs_attention( (object) $row );
        } );
        $this->assertSame( 7, count( $yes ) );
    }

    /** The evaluator itself: precedence and NULL, so the tests above mean something. */
    public function testTheFakeEvaluatesAndOrPrecedenceAndNull(): void {
        $db  = new ApplicationStatsFakeWpdb();
        $row = [ 'status' => 'paid', 'livemode' => 0, 'last_error' => null ];

        // AND binds tighter than OR: false AND true OR true = true; the parenthesised form is false.
        $this->assertTrue( $db->evaluate( "livemode = 1 AND status = 'review' OR status = 'paid'", $row ) );
        $this->assertFalse( $db->evaluate( "livemode = 1 AND ( status = 'review' OR status = 'paid' )", $row ) );
        // A comparison with NULL is never true.
        $this->assertFalse( $db->evaluate( "last_error <> ''", $row ) );
        $this->assertFalse( $db->evaluate( 'last_error IS NOT NULL', $row ) );
        $this->assertTrue( $db->evaluate( "status IN ('review', 'paid') AND livemode = 0", $row ) );
        $this->assertTrue( $db->evaluate( "status LIKE 'p%'", $row ) );
        $this->assertFalse( $db->evaluate( "status LIKE 'p\\_%'", $row ), 'an escaped underscore is a literal one' );
    }
}
