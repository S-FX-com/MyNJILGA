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

    /**
     * Webhook idempotency gate: insert a `received` row for this Stripe
     * event id. Returns true the first time an event id is seen (go ahead
     * and process it); returns false — not an error, just "already seen" —
     * on a duplicate delivery, which a later phase's receiver treats as
     * "nothing to do, ack the webhook and stop."
     */
    public static function record_received( string $eventId, string $type, bool $livemode, ?string $objectId = null ): bool {
        global $wpdb;
        $table = self::table_name();

        try {
            $inserted = $wpdb->insert(
                $table,
                [
                    'event_id'    => $eventId,
                    'type'        => $type,
                    'livemode'    => $livemode ? 1 : 0,
                    'object_id'   => $objectId,
                    'status'      => self::STATUS_RECEIVED,
                    'received_at' => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%d', '%s', '%s', '%s' ]
            );

            if ( $inserted ) {
                return true;
            }

            // A duplicate-key violation (MySQL 1062) on `event` is the
            // expected "already seen" case, not an error — anything else
            // is a genuine DB failure, which is also not worth processing
            // twice, so it's treated the same way (false = don't proceed).
            return false;
        } catch ( \Throwable $e ) {
            return false;
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
