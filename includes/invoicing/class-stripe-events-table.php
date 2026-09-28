<?php
/**
 * Schema + CRUD for `{$wpdb->prefix}njilga_stripe_events` — the webhook
 * idempotency gate (spec: Stripe migration phase 1). Stripe can (and does)
 * deliver the same event more than once; a later phase's webhook receiver
 * calls `record_received()` before doing any processing, and only
 * continues if that returns true. The row is also the audit trail of what
 * happened while handling each event — `mark_processed()` records the
 * outcome once processing finishes. Two reads exist purely for that
 * audit-trail role: `recent()` (the Setup page's event list) and
 * `last_received_at()` (the Setup page's "has Stripe gone quiet?"
 * warning, via MyNJILGA_Stripe_Connection::health_warnings()).
 *
 * Schema history:
 *   1.0.0  initial table (Stripe migration phase 1)
 */
class MyNJILGA_Stripe_Events_Table {

    const OPTION_DB_VERSION = 'njilga_stripe_events_db_version';
    const DB_VERSION        = '1.0.0';

    const STATUS_RECEIVED  = 'received';
    const STATUS_PROCESSED = 'processed';
    const STATUS_IGNORED   = 'ignored';
    const STATUS_FAILED    = 'failed';

    // How long a row survives before the daily reconcile job prunes it
    // (MyNJILGA_Stripe_Reconciler::run_daily()). Named so the Setup page's
    // audit trail can tell staff how far back the table actually goes
    // instead of hard-coding the same number twice.
    const PRUNE_AFTER_DAYS = 180;

    // How many rows recent() hands back by default — the Setup page's
    // audit trail is a "what has Stripe sent us lately" read, never a
    // full-table browse.
    const RECENT_LIMIT = 25;

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'njilga_stripe_events';
    }

    /**
     * Creates/upgrades the table if the stored schema version is behind.
     * Called on `admin_init` (already-active sites picking this up via an
     * auto-update never fire the activation hook) and on activation.
     */
    public static function maybe_upgrade(): void {
        $current = (string) get_option( self::OPTION_DB_VERSION, '' );
        if ( $current === self::DB_VERSION ) {
            return;
        }
        self::create_or_upgrade_table( $current );
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
    }

    private static function create_or_upgrade_table( string $fromVersion ): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id VARCHAR(64) NOT NULL,
            type VARCHAR(64) NOT NULL,
            livemode TINYINT(1) NOT NULL,
            object_id VARCHAR(64) NULL,
            invoice_row_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT NULL,
            received_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event (event_id)
        ) $charset_collate;";

        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    public static function get( int $id ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore
    }

    public static function get_by_event_id( string $eventId ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE event_id = %s", $eventId ) ); // phpcs:ignore
    }

    /**
     * `received_at` of the newest event recorded for the given mode (the
     * event's own `livemode` flag as Stripe reported it), or null when
     * nothing has ever been recorded for that mode. This is the read
     * MyNJILGA_Stripe_Connection::health_warnings() uses to tell "was
     * receiving, then went quiet" from "connected five minutes ago and
     * nothing has happened yet" — so null must mean exactly "no row",
     * never "old row".
     *
     * Ordered by `id`, not `received_at`: ids are handed out in arrival
     * order, so the newest row is the highest id either way, and walking
     * the PRIMARY KEY backwards means MySQL never sorts a table that
     * carries no index on received_at.
     *
     * @param bool|null $livemode null = newest row in either mode.
     */
    public static function last_received_at( ?bool $livemode = null ): ?string {
        global $wpdb;
        $table = self::table_name();

        if ( $livemode === null ) {
            $value = $wpdb->get_var( "SELECT received_at FROM $table ORDER BY id DESC LIMIT 1" ); // phpcs:ignore
        } else {
            $value = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
                "SELECT received_at FROM $table WHERE livemode = %d ORDER BY id DESC LIMIT 1",
                $livemode ? 1 : 0
            ) );
        }

        return ( $value === null || (string) $value === '' ) ? null : (string) $value;
    }

    /**
     * The newest events, newest first — the Setup page's audit trail
     * (spec §5.4: "an audit trail staff can read without leaving
     * WordPress"). Deliberately capped rather than paginated: the table
     * is pruned to PRUNE_AFTER_DAYS by the daily reconcile job, so it is
     * never the long-term record, and the question staff ask of it is
     * "what has Stripe sent us lately", not "let me page through six
     * months". $limit is clamped so a caller can't turn this into a
     * whole-table read by accident.
     *
     * @param bool|null $livemode null = both modes.
     * @return array<int,object>
     */
    public static function recent( int $limit = self::RECENT_LIMIT, ?bool $livemode = null ): array {
        global $wpdb;
        $table = self::table_name();
        $limit = max( 1, min( 200, $limit ) );

        if ( $livemode === null ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
                "SELECT * FROM $table WHERE livemode = %d ORDER BY id DESC LIMIT %d",
                $livemode ? 1 : 0,
                $limit
            ) );
        }

        return is_array( $rows ) ? $rows : [];
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    // record() outcomes.
    const RECORD_NEW       = 'new';
    const RECORD_DUPLICATE = 'duplicate';
    const RECORD_ERROR     = 'error';

    /**
     * Webhook idempotency gate: insert a `received` row for this Stripe
     * event id. True the first time an event id is seen (go ahead and
     * process it); false for a duplicate delivery AND for a database
     * failure. Kept for callers that only need yes/no — the receiver uses
     * record() so it can tell the two apart.
     */
    public static function record_received( string $eventId, string $type, bool $livemode, ?string $objectId = null ): bool {
        return self::record( $eventId, $type, $livemode, $objectId ) === self::RECORD_NEW;
    }

    /**
     * The idempotency gate with its three outcomes. A duplicate is the
     * expected, quiet case (Stripe re-delivers routinely) and must not
     * log a database error; anything else that stops the row being
     * written is RECORD_ERROR, which the receiver answers with a 5xx so
     * Stripe retries — acknowledging it would lose the event for good,
     * since an acknowledged delivery is never resent.
     *
     * The UNIQUE key on event_id stays the arbiter between two
     * simultaneous deliveries: INSERT IGNORE lets exactly one of them
     * write the row, and the other finds it on the re-read.
     *
     * @return string One of the RECORD_* constants.
     */
    public static function record( string $eventId, string $type, bool $livemode, ?string $objectId = null ): string {
        global $wpdb;
        $table = self::table_name();

        try {
            if ( self::get_by_event_id( $eventId ) ) {
                return self::RECORD_DUPLICATE;
            }

            $sql = $objectId === null
                ? $wpdb->prepare(
                    "INSERT IGNORE INTO $table (event_id, type, livemode, object_id, status, received_at) VALUES (%s, %s, %d, NULL, %s, %s)", // phpcs:ignore
                    $eventId, $type, $livemode ? 1 : 0, self::STATUS_RECEIVED, current_time( 'mysql' )
                )
                : $wpdb->prepare(
                    "INSERT IGNORE INTO $table (event_id, type, livemode, object_id, status, received_at) VALUES (%s, %s, %d, %s, %s, %s)", // phpcs:ignore
                    $eventId, $type, $livemode ? 1 : 0, $objectId, self::STATUS_RECEIVED, current_time( 'mysql' )
                );
            $inserted = $wpdb->query( $sql ); // phpcs:ignore

            if ( $inserted === false ) {
                return self::RECORD_ERROR;
            }
            if ( (int) $inserted === 1 ) {
                return self::RECORD_NEW;
            }
            // Nothing written and no error: another delivery of the same
            // event won the UNIQUE key between the read and the insert.
            return self::get_by_event_id( $eventId ) ? self::RECORD_DUPLICATE : self::RECORD_ERROR;
        } catch ( \Throwable $e ) {
            return self::RECORD_ERROR;
        }
    }

    public static function mark_processed( int $id, string $status, string $message = '', ?int $invoiceRowId = null ): void {
        global $wpdb;
        $data   = [
            'status'       => $status,
            'message'      => $message !== '' ? mb_substr( $message, 0, 2000 ) : null,
            'processed_at' => current_time( 'mysql' ),
        ];
        $format = [ '%s', '%s', '%s' ];
        if ( $invoiceRowId !== null ) {
            $data['invoice_row_id'] = $invoiceRowId;
            $format[]                = '%d';
        }
        $wpdb->update( self::table_name(), $data, [ 'id' => $id ], $format, [ '%d' ] );
    }

    /**
     * Deletes events received more than `$days` ago. A later phase wires
     * this to a weekly cron; this method just does the deletion.
     *
     * @return int Rows deleted.
     */
    public static function prune_older_than( int $days = self::PRUNE_AFTER_DAYS ): int {
        global $wpdb;
        $table = self::table_name();
        // gmdate() on current_time('timestamp')'s site-offset-adjusted
        // value, same as current_time('mysql') does internally, so the
        // cutoff lines up with how received_at was written.
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, $days ) . ' days', current_time( 'timestamp' ) ) );
        return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE received_at < %s", $cutoff ) ); // phpcs:ignore
    }
}
