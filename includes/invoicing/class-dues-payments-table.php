<?php
/**
 * Schema + CRUD for `{$wpdb->prefix}njilga_dues_payments` — the Stripe
 * migration's payment ledger (spec: Stripe migration phase 1). One row per
 * money EVENT against an invoice row in `njilga_dues_invoices`: a Stripe
 * charge/payment_intent succeeding, a refund, or a manually recorded
 * off-Stripe payment (check, cash, wire). Several rows can accumulate
 * against one invoice row (a partial payment followed by the balance, or a
 * payment followed by a later refund) — this table is the append-only log,
 * `njilga_dues_invoices.amount_paid_cents` etc. are the rolled-up totals a
 * later phase maintains from it.
 *
 * `record()` is the webhook-idempotency backstop a later phase's Stripe
 * webhook receiver depends on: the same Stripe event can be delivered more
 * than once, and re-inserting the same (stripe_object_id, kind) must be a
 * safe no-op rather than a duplicate row or a thrown error.
 *
 * Schema history:
 *   1.0.0  initial table (Stripe migration phase 1)
 */
class MyNJILGA_Dues_Payments_Table {

    const OPTION_DB_VERSION = 'njilga_dues_payments_db_version';
    const DB_VERSION        = '1.0.0';

    const KIND_PAYMENT = 'payment';
    const KIND_REFUND  = 'refund';
    const KIND_MANUAL  = 'manual';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'njilga_dues_payments';
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
            invoice_row_id BIGINT UNSIGNED NOT NULL,
            livemode TINYINT(1) NOT NULL DEFAULT 1,
            stripe_object_id VARCHAR(64) NULL,
            kind VARCHAR(20) NOT NULL,
            method VARCHAR(24) NOT NULL,
            amount_cents INT NOT NULL,
            status VARCHAR(24) NOT NULL,
            occurred_at DATETIME NOT NULL,
            recorded_by_user_id BIGINT UNSIGNED NULL,
            reference VARCHAR(64) NULL,
            card_brand VARCHAR(24) NULL,
            last4 VARCHAR(4) NULL,
            bank_name VARCHAR(64) NULL,
            failure_reason VARCHAR(255) NULL,
            receipt_url VARCHAR(512) NULL,
            raw LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY stripe_object (stripe_object_id, kind),
            KEY invoice_row (invoice_row_id),
            KEY occurred (occurred_at)
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
     * All payment/refund/manual rows for one invoice row, newest first.
     *
     * @return array<int,object>
     */
    public static function get_for_invoice_row( int $invoiceRowId ): array {
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE invoice_row_id = %d ORDER BY occurred_at DESC",
            $invoiceRowId
        ) );
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Duplicate-safe insert: attempt the INSERT and, if it collides on the
     * UNIQUE (stripe_object_id, kind) key, treat that as success and return
     * the existing row's id rather than throwing — this is the webhook-
     * idempotency backstop a later phase's Stripe event receiver depends
     * on (the same Stripe event can be delivered more than once).
     *
     * A NULL stripe_object_id (a pure manual/check/cash entry) does NOT
     * collide under this unique key: MySQL treats every NULL in a UNIQUE
     * column as distinct from every other NULL, so any number of manual
     * rows can all carry stripe_object_id = NULL without tripping the
     * duplicate path below — no special-casing needed for that case.
     *
     * @param array{invoice_row_id:int,livemode?:bool,stripe_object_id?:?string,kind:string,method:string,amount_cents:int,status:string,occurred_at?:string,recorded_by_user_id?:?int,reference?:?string,card_brand?:?string,last4?:?string,bank_name?:?string,failure_reason?:?string,receipt_url?:?string,raw?:?string} $data
     * @return int|null The row id (new or pre-existing), or null on a genuine DB error.
     */
    public static function record( array $data ): ?int {
        global $wpdb;
        $table = self::table_name();

        $stripeObjectId = isset( $data['stripe_object_id'] ) && $data['stripe_object_id'] !== ''
            ? (string) $data['stripe_object_id']
            : null;
        $kind = (string) $data['kind'];

        try {
            $inserted = $wpdb->insert(
                $table,
                [
                    'invoice_row_id'       => (int) $data['invoice_row_id'],
                    'livemode'             => ! empty( $data['livemode'] ?? true ) ? 1 : 0,
                    'stripe_object_id'     => $stripeObjectId,
                    'kind'                 => $kind,
                    'method'               => (string) $data['method'],
                    'amount_cents'         => (int) $data['amount_cents'],
                    'status'               => (string) $data['status'],
                    'occurred_at'          => (string) ( $data['occurred_at'] ?? current_time( 'mysql' ) ),
                    'recorded_by_user_id'  => isset( $data['recorded_by_user_id'] ) ? (int) $data['recorded_by_user_id'] : null,
                    'reference'            => isset( $data['reference'] ) ? (string) $data['reference'] : null,
                    'card_brand'           => isset( $data['card_brand'] ) ? (string) $data['card_brand'] : null,
                    'last4'                => isset( $data['last4'] ) ? (string) $data['last4'] : null,
                    'bank_name'            => isset( $data['bank_name'] ) ? (string) $data['bank_name'] : null,
                    'failure_reason'       => isset( $data['failure_reason'] ) ? (string) $data['failure_reason'] : null,
                    'receipt_url'          => isset( $data['receipt_url'] ) ? (string) $data['receipt_url'] : null,
                    'raw'                  => isset( $data['raw'] ) ? (string) $data['raw'] : null,
                    'created_at'           => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );

            if ( $inserted ) {
                return (int) $wpdb->insert_id;
            }

            // Duplicate-key violation (MySQL 1062, "Duplicate entry ...")
            // on the (stripe_object_id, kind) unique key is the idempotency
            // case — not an error. Any other failure is a genuine DB error
            // and reports null.
            if ( $stripeObjectId !== null && stripos( (string) $wpdb->last_error, 'duplicate' ) !== false ) {
                $existing = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
                    "SELECT id FROM $table WHERE stripe_object_id = %s AND kind = %s",
                    $stripeObjectId,
                    $kind
                ) );
                return $existing ? (int) $existing->id : null;
            }

            return null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }
}
