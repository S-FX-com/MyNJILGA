<?php
/**
 * Schema + CRUD for `{$wpdb->prefix}njilga_dues_invoices` — one row per
 * firm per dues year. This table is the frozen roster/price snapshot the
 * whole invoicing flow reads from after generation, so the roster billed
 * and the roster credited on payment are always the same fixed list (see
 * the invoicing spec's "Why a Snapshot Table, Not a Live Re-Query").
 *
 * Statuses: draft | approved | created | sent | paid | downgraded | excluded
 * (excluded = no Company Owner assigned, so there's no bill-to contact).
 */
class MyNJILGA_Dues_Invoice_Table {

    const OPTION_DB_VERSION = 'njilga_dues_db_version';
    const DB_VERSION        = '1.0.0';

    const STATUS_DRAFT      = 'draft';
    const STATUS_APPROVED   = 'approved';
    const STATUS_CREATED    = 'created';
    const STATUS_SENT       = 'sent';
    const STATUS_PAID       = 'paid';
    const STATUS_DOWNGRADED = 'downgraded';
    const STATUS_EXCLUDED   = 'excluded';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'njilga_dues_invoices';
    }

    /**
     * Creates/updates the table if the stored schema version is behind.
     * Called on `admin_init` (covers sites where the plugin was already
     * active before this feature shipped — WordPress only fires the
     * activation hook on a fresh activation, never on an auto-update of
     * an already-active plugin) and on register_activation_hook (covers
     * a brand new install).
     */
    public static function maybe_upgrade(): void {
        if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
            return;
        }
        self::create_table();
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
    }

    private static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dues_year SMALLINT UNSIGNED NOT NULL,
            fluentcrm_company_id BIGINT UNSIGNED NOT NULL,
            fluentcrm_owner_contact_id BIGINT UNSIGNED NOT NULL,
            fluentcart_customer_id BIGINT UNSIGNED NULL,
            fluentcart_order_id BIGINT UNSIGNED NULL,
            fluentcart_order_uuid VARCHAR(64) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            total_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
            roster_snapshot LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            approved_at DATETIME NULL,
            sent_at DATETIME NULL,
            paid_at DATETIME NULL,
            downgraded_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY firm_year (fluentcrm_company_id, dues_year)
        ) $charset_collate;";

        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    public static function get( int $id ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    }

    public static function get_by_company_year( int $companyId, int $duesYear ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE fluentcrm_company_id = %d AND dues_year = %d",
            $companyId,
            $duesYear
        ) );
    }

    public static function get_by_order_id( int $orderId ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE fluentcart_order_id = %d", $orderId ) );
    }

    /**
     * @param array<int,string> $statuses Optional status filter (empty = all).
     * @return array<int,object>
     */
    public static function get_by_year( int $duesYear, array $statuses = [] ): array {
        global $wpdb;
        $table = self::table_name();

        if ( empty( $statuses ) ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $table WHERE dues_year = %d ORDER BY id ASC",
                $duesYear
            ) );
        }

        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $query        = "SELECT * FROM $table WHERE dues_year = %d AND status IN ($placeholders) ORDER BY id ASC";
        return $wpdb->get_results( $wpdb->prepare( $query, array_merge( [ $duesYear ], $statuses ) ) );
    }

    /**
     * Rows still owed for the year — anything that hasn't been paid,
     * downgraded already, or excluded for lack of an Owner. Used by the
     * downgrade sweep.
     *
     * @return array<int,object>
     */
    public static function get_unpaid_for_sweep( int $duesYear ): array {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE dues_year = %d AND status NOT IN (%s, %s, %s) ORDER BY id ASC",
            $duesYear,
            self::STATUS_PAID,
            self::STATUS_DOWNGRADED,
            self::STATUS_EXCLUDED
        ) );
    }

    /**
     * @return array<string,int> status => count, for every status that has
     *                            at least one row in the given year.
     */
    public static function counts_by_status( int $duesYear ): array {
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS c FROM $table WHERE dues_year = %d GROUP BY status",
            $duesYear
        ) );

        $counts = [];
        foreach ( $rows as $row ) {
            $counts[ $row->status ] = (int) $row->c;
        }
        return $counts;
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Inserts a fresh draft/excluded row for (company, year), or — if a
     * row already exists for that pair and is STILL a draft or excluded —
     * refreshes it with the newly computed roster. Rows that have moved
     * past draft (approved and beyond) are left completely untouched, so
     * re-running "Generate Preview" can never clobber an already-frozen,
     * already-billed roster.
     *
     * @param array{dues_year:int,fluentcrm_company_id:int,fluentcrm_owner_contact_id:int,status:string,total_amount_cents:int,roster_snapshot:string} $data
     * @return int|null The row id, or null if an existing non-draft row blocked the refresh.
     */
    public static function upsert_draft( array $data ): ?int {
        global $wpdb;
        $table    = self::table_name();
        $existing = self::get_by_company_year( $data['fluentcrm_company_id'], $data['dues_year'] );

        if ( $existing ) {
            if ( ! in_array( $existing->status, [ self::STATUS_DRAFT, self::STATUS_EXCLUDED ], true ) ) {
                return null; // Already approved/created/sent/paid/downgraded — never overwrite.
            }
            $wpdb->update(
                $table,
                [
                    'fluentcrm_owner_contact_id' => $data['fluentcrm_owner_contact_id'],
                    'status'                     => $data['status'],
                    'total_amount_cents'         => $data['total_amount_cents'],
                    'roster_snapshot'            => $data['roster_snapshot'],
                ],
                [ 'id' => $existing->id ],
                [ '%d', '%s', '%d', '%s' ],
                [ '%d' ]
            );
            return (int) $existing->id;
        }

        $wpdb->insert(
            $table,
            [
                'dues_year'                  => $data['dues_year'],
                'fluentcrm_company_id'       => $data['fluentcrm_company_id'],
                'fluentcrm_owner_contact_id' => $data['fluentcrm_owner_contact_id'],
                'status'                     => $data['status'],
                'total_amount_cents'         => $data['total_amount_cents'],
                'roster_snapshot'            => $data['roster_snapshot'],
                'created_at'                 => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%d', '%s', '%d', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<int,int> $ids
     */
    public static function mark_approved( array $ids ): void {
        self::bulk_set_status( $ids, self::STATUS_APPROVED, 'approved_at' );
    }

    public static function mark_created( int $id, ?int $customerId, int $orderId, string $orderUuid ): void {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            [
                'fluentcart_customer_id'  => $customerId,
                'fluentcart_order_id'     => $orderId,
                'fluentcart_order_uuid'   => $orderUuid,
                'status'                  => self::STATUS_CREATED,
            ],
            [ 'id' => $id ],
            [ '%d', '%d', '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * @param array<int,int> $ids
     */
    public static function mark_sent( array $ids ): void {
        self::bulk_set_status( $ids, self::STATUS_SENT, 'sent_at' );
    }

    public static function mark_paid( int $id ): void {
        self::bulk_set_status( [ $id ], self::STATUS_PAID, 'paid_at' );
    }

    /**
     * @param array<int,int> $ids
     */
    public static function mark_downgraded( array $ids ): void {
        self::bulk_set_status( $ids, self::STATUS_DOWNGRADED, 'downgraded_at' );
    }

    /**
     * @param array<int,int> $ids
     */
    private static function bulk_set_status( array $ids, string $status, string $timestamp_column ): void {
        global $wpdb;
        $ids = array_filter( array_map( 'intval', $ids ) );
        if ( empty( $ids ) ) {
            return;
        }
        $table = self::table_name();
        $now   = current_time( 'mysql' );

        foreach ( $ids as $id ) {
            $wpdb->update(
                $table,
                [ 'status' => $status, $timestamp_column => $now ],
                [ 'id' => $id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }
    }
}
