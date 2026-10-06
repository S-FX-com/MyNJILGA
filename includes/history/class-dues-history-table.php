<?php
/**
 * Schema + CRUD for `{$wpdb->prefix}njilga_dues_history` — invoices that
 * did NOT come out of the Stripe invoicing flow: dues paid through
 * Paid Memberships Pro in earlier years (the PMPro Migrator) and rows
 * uploaded from a spreadsheet (Historical Invoice Import).
 *
 * Why a table of its own, rather than rows in njilga_dues_invoices:
 * that table drives live behaviour — the Invoicing workspace, the daily
 * Stripe reconciler, the downgrade sweep, the dashboard's invoiced /
 * collected figures and the Payments ledger all read it. A record kept
 * for reference must never be able to move any of those numbers or be
 * "synced" against Stripe, so history lives beside the live tables and
 * MyNJILGA_Dues_History merges the two for display only.
 *
 * One row per invoice. The single payment that settled it is carried on
 * the same row (paid_cents / paid_at / method / reference) — historical
 * sources record one payment per invoice, and a partly-paid imported
 * invoice simply has paid_cents below total_cents.
 *
 * `source` + `source_ref` is the idempotency key: re-running a migration
 * or re-uploading the same sheet can never create a second copy of an
 * invoice. `batch_id` groups everything one run created so it can be
 * undone as a unit.
 *
 * Schema history:
 *   1.0.0  initial table
 */
class MyNJILGA_Dues_History_Table {

    const OPTION_DB_VERSION = 'njilga_dues_history_db_version';
    const DB_VERSION        = '1.0.0';

    const SOURCE_PMPRO  = 'pmpro';
    const SOURCE_IMPORT = 'import';

    const STATUS_PAID = 'paid';
    const STATUS_OPEN = 'open';
    const STATUS_VOID = 'void';

    const ALL_STATUSES = [ self::STATUS_PAID, self::STATUS_OPEN, self::STATUS_VOID ];

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'njilga_dues_history';
    }

    /**
     * Creates/upgrades the table if the stored schema version is behind.
     * Called on `admin_init` (an auto-update never fires the activation
     * hook) and on activation, like every other table here.
     */
    public static function maybe_upgrade(): void {
        $current = (string) get_option( self::OPTION_DB_VERSION, '' );
        if ( $current === self::DB_VERSION ) {
            return;
        }
        self::create_or_upgrade_table();
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
    }

    private static function create_or_upgrade_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source VARCHAR(16) NOT NULL,
            source_ref VARCHAR(100) NOT NULL,
            batch_id VARCHAR(48) NOT NULL DEFAULT '',
            dues_year SMALLINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            company_name VARCHAR(190) NOT NULL DEFAULT '',
            contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            contact_name VARCHAR(190) NOT NULL DEFAULT '',
            contact_email VARCHAR(190) NOT NULL DEFAULT '',
            member_ids TEXT NULL,
            invoice_number VARCHAR(64) NOT NULL DEFAULT '',
            description VARCHAR(255) NOT NULL DEFAULT '',
            line_items LONGTEXT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'paid',
            total_cents INT NOT NULL DEFAULT 0,
            paid_cents INT NOT NULL DEFAULT 0,
            invoice_date DATE NULL,
            due_date DATE NULL,
            paid_at DATETIME NULL,
            method VARCHAR(24) NOT NULL DEFAULT '',
            method_detail VARCHAR(100) NOT NULL DEFAULT '',
            reference VARCHAR(100) NOT NULL DEFAULT '',
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_ref (source, source_ref),
            KEY company_idx (company_id),
            KEY contact_idx (contact_id),
            KEY batch_idx (batch_id),
            KEY year_idx (dues_year)
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

    /**
     * Every history row filed under any of these firms, newest year first.
     *
     * @param array<int,int> $companyIds
     * @return array<int,object>
     */
    public static function for_companies( array $companyIds ): array {
        global $wpdb;
        $companyIds = array_values( array_unique( array_filter( array_map( 'intval', $companyIds ), static function ( $id ) { return $id > 0; } ) ) );
        if ( ! $companyIds ) {
            return [];
        }
        $table        = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $companyIds ), '%d' ) );
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE company_id IN ($placeholders) ORDER BY dues_year DESC, id ASC",
            $companyIds
        ) );
    }

    /**
     * Every history row that names this contact — as the person it was
     * billed to or as one of the members it covers — newest year first.
     * member_ids is stored as ",5,9," so a LIKE on ",5," can't match 15.
     *
     * @return array<int,object>
     */
    public static function for_contact( int $contactId ): array {
        global $wpdb;
        if ( $contactId <= 0 ) {
            return [];
        }
        $table = self::table_name();
        $like  = '%' . $wpdb->esc_like( ',' . $contactId . ',' ) . '%';
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE contact_id = %d OR member_ids LIKE %s ORDER BY dues_year DESC, id ASC",
            $contactId,
            $like
        ) );
    }

    /**
     * Which of these (source, source_ref) pairs are already stored —
     * the set the importers use to say "already imported" instead of
     * trying (and failing) to insert them again.
     *
     * @param array<int,string> $refs
     * @return array<string,int> source_ref => row id
     */
    public static function existing_refs( string $source, array $refs ): array {
        global $wpdb;
        $refs = array_values( array_unique( array_filter( array_map( 'strval', $refs ), static function ( $r ) { return $r !== ''; } ) ) );
        if ( ! $refs ) {
            return [];
        }
        $table = self::table_name();
        $out   = [];
        // Chunked: a big sheet must not build one enormous IN ().
        foreach ( array_chunk( $refs, 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $rows         = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
                "SELECT id, source_ref FROM $table WHERE source = %s AND source_ref IN ($placeholders)",
                array_merge( [ $source ], $chunk )
            ) );
            foreach ( $rows as $row ) {
                $out[ (string) $row->source_ref ] = (int) $row->id;
            }
        }
        return $out;
    }

    /**
     * One line per import run — what it created and when — newest first.
     * The table is its own log: there is no separate record to drift out
     * of step with the rows it describes.
     *
     * @return array<int,object> batch_id, source, invoices, total_cents, first_year, last_year, created_at, created_by
     */
    public static function batches(): array {
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results( // phpcs:ignore
            "SELECT batch_id, source, COUNT(*) AS invoices, SUM(total_cents) AS total_cents, MIN(dues_year) AS first_year, MAX(dues_year) AS last_year, MIN(created_at) AS created_at, MAX(created_by) AS created_by
             FROM $table WHERE batch_id <> '' GROUP BY batch_id, source ORDER BY MIN(created_at) DESC, batch_id DESC"
        );
    }

    /**
     * @return array{invoices:int,total_cents:int,firms:int,contacts:int}
     */
    public static function totals(): array {
        global $wpdb;
        $table = self::table_name();
        $row   = $wpdb->get_row( // phpcs:ignore
            "SELECT COUNT(*) AS invoices, COALESCE(SUM(total_cents),0) AS total_cents, COUNT(DISTINCT NULLIF(company_id,0)) AS firms, COUNT(DISTINCT NULLIF(contact_id,0)) AS contacts FROM $table"
        );
        return [
            'invoices'    => $row ? (int) $row->invoices : 0,
            'total_cents' => $row ? (int) $row->total_cents : 0,
            'firms'       => $row ? (int) $row->firms : 0,
            'contacts'    => $row ? (int) $row->contacts : 0,
        ];
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Insert one history row. Returns the new id, or 0 when the row was
     * not written — a (source, source_ref) that already exists (the
     * idempotency case) or a genuine database error.
     *
     * @param array<string,mixed> $d Keys as the columns; see MyNJILGA_Dues_History::member_ids_column() for member_ids.
     */
    public static function insert( array $d ): int {
        global $wpdb;
        $ok = $wpdb->insert(
            self::table_name(),
            [
                'source'         => (string) $d['source'],
                'source_ref'     => (string) $d['source_ref'],
                'batch_id'       => (string) ( $d['batch_id'] ?? '' ),
                'dues_year'      => (int) $d['dues_year'],
                'company_id'     => (int) ( $d['company_id'] ?? 0 ),
                'company_name'   => mb_substr( (string) ( $d['company_name'] ?? '' ), 0, 190 ),
                'contact_id'     => (int) ( $d['contact_id'] ?? 0 ),
                'contact_name'   => mb_substr( (string) ( $d['contact_name'] ?? '' ), 0, 190 ),
                'contact_email'  => mb_substr( (string) ( $d['contact_email'] ?? '' ), 0, 190 ),
                'member_ids'     => (string) ( $d['member_ids'] ?? '' ),
                'invoice_number' => mb_substr( (string) ( $d['invoice_number'] ?? '' ), 0, 64 ),
                'description'    => mb_substr( (string) ( $d['description'] ?? '' ), 0, 255 ),
                'line_items'     => (string) ( $d['line_items'] ?? '[]' ),
                'status'         => in_array( $d['status'] ?? '', self::ALL_STATUSES, true ) ? (string) $d['status'] : self::STATUS_PAID,
                'total_cents'    => (int) ( $d['total_cents'] ?? 0 ),
                'paid_cents'     => (int) ( $d['paid_cents'] ?? 0 ),
                'invoice_date'   => self::nullable( $d['invoice_date'] ?? null ),
                'due_date'       => self::nullable( $d['due_date'] ?? null ),
                'paid_at'        => self::nullable( $d['paid_at'] ?? null ),
                'method'         => mb_substr( (string) ( $d['method'] ?? '' ), 0, 24 ),
                'method_detail'  => mb_substr( (string) ( $d['method_detail'] ?? '' ), 0, 100 ),
                'reference'      => mb_substr( (string) ( $d['reference'] ?? '' ), 0, 100 ),
                'notes'          => (string) ( $d['notes'] ?? '' ),
                'created_by'     => (int) ( $d['created_by'] ?? 0 ),
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Remove everything one run created. The only delete this class has,
     * and it is by batch — there is no way to wipe the whole table from
     * here, and nothing in the live invoicing tables is touched.
     *
     * @return int Rows deleted.
     */
    public static function delete_batch( string $batchId ): int {
        global $wpdb;
        if ( $batchId === '' ) {
            return 0;
        }
        $table = self::table_name();
        return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE batch_id = %s", $batchId ) ); // phpcs:ignore
    }

    /**
     * @param mixed $value
     * @return string|null '' / null → NULL, so a missing date is NULL, not 0000-00-00.
     */
    private static function nullable( $value ): ?string {
        $s = trim( (string) $value );
        return $s === '' ? null : $s;
    }
}
