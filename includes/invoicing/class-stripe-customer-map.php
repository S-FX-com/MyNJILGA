<?php
/**
 * Schema + CRUD for `{$wpdb->prefix}njilga_stripe_customers` — maps one
 * FluentCRM Company to its Stripe Customer id, per mode (spec: Stripe
 * migration phase 1). Test mode and live mode are entirely separate
 * Stripe object graphs (a test-mode customer id is meaningless against the
 * live API and vice versa), so the mapping is keyed on (company_id, mode)
 * rather than company_id alone — a firm gets its own Stripe Customer in
 * each mode it's ever been billed under.
 *
 * Schema history:
 *   1.0.0  initial table (Stripe migration phase 1)
 */
class MyNJILGA_Stripe_Customer_Map {

    const OPTION_DB_VERSION = 'njilga_stripe_customers_db_version';
    const DB_VERSION        = '1.0.0';

    const MODE_TEST = 'test';
    const MODE_LIVE = 'live';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'njilga_stripe_customers';
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
            company_id BIGINT UNSIGNED NOT NULL,
            mode VARCHAR(4) NOT NULL,
            stripe_customer_id VARCHAR(64) NOT NULL,
            synced_at DATETIME NOT NULL,
            PRIMARY KEY  (company_id, mode)
        ) $charset_collate;";

        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    public static function get( int $companyId, string $mode ): ?string {
        global $wpdb;
        $table = self::table_name();
        $id    = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
            "SELECT stripe_customer_id FROM $table WHERE company_id = %d AND mode = %s",
            $companyId,
            $mode
        ) );
        return ( $id !== null && $id !== '' ) ? (string) $id : null;
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Upsert the (company_id, mode) -> stripe_customer_id mapping.
     */
    public static function set( int $companyId, string $mode, string $stripeCustomerId ): void {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql' );

        $existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
            "SELECT company_id FROM $table WHERE company_id = %d AND mode = %s",
            $companyId,
            $mode
        ) );

        if ( $existing !== null ) {
            $wpdb->update(
                $table,
                [ 'stripe_customer_id' => $stripeCustomerId, 'synced_at' => $now ],
                [ 'company_id' => $companyId, 'mode' => $mode ],
                [ '%s', '%s' ],
                [ '%d', '%s' ]
            );
            return;
        }

        $wpdb->insert(
            $table,
            [
                'company_id'         => $companyId,
                'mode'               => $mode,
                'stripe_customer_id' => $stripeCustomerId,
                'synced_at'          => $now,
            ],
            [ '%d', '%s', '%s', '%s' ]
        );
    }

    public static function delete( int $companyId, string $mode ): void {
        global $wpdb;
        $wpdb->delete( self::table_name(), [ 'company_id' => $companyId, 'mode' => $mode ], [ '%d', '%s' ] );
    }
}
