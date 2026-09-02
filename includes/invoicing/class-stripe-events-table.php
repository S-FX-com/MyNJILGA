<?php
/**
 * Schema + CRUD for `{$wpdb->prefix}njilga_stripe_events` — the webhook
 * idempotency gate (spec: Stripe migration phase 1). Stripe can (and does)
 * deliver the same event more than once; a later phase's webhook receiver
 * calls `record_received()` before doing any processing, and only
 * continues if that returns true. The row is also the audit trail of what
 * happened while handling each event — `mark_processed()` records the
 * outcome once processing finishes.
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
    public static function prune_older_than( int $days = 180 ): int {
        global $wpdb;
        $table = self::table_name();
        // gmdate() on current_time('timestamp')'s site-offset-adjusted
        // value, same as current_time('mysql') does internally, so the
        // cutoff lines up with how received_at was written.
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, $days ) . ' days', current_time( 'timestamp' ) ) );
        return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE received_at < %s", $cutoff ) ); // phpcs:ignore
    }
}
