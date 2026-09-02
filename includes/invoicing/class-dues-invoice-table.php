<?php
/**
 * Schema + CRUD for `{$wpdb->prefix}njilga_dues_invoices` (spec §5.1) —
 * one row per INVOICE per dues year. In the default firm billing mode
 * that is one row per firm; the individual / split-assessment modes
 * (spec §3.4) produce several rows per firm, told apart by
 * `invoice_kind` + `bill_to_contact_id`.
 *
 * The row's `roster_snapshot` (see MyNJILGA_Dues_Snapshot for the shape)
 * is the frozen roster/price list every later step reads — the FluentCart
 * order, the payment hook, the downgrade sweep, the Company Note — so the
 * roster billed and the roster credited on payment are always the same
 * fixed list.
 *
 * Statuses:
 *   draft → approved → created → sent → paid
 *                                  ↘ processing (ACH in flight — Stripe webhook only)
 *                                  ↘ downgraded (sweep, never paid)
 *                                  ↘ voided / uncollectible (Stripe webhook only)
 *   excluded  (no Owner / no members / nothing to bill — never invoiced)
 *
 * Schema history:
 *   1.0.0  firm-level rows, UNIQUE (company, year)
 *   1.1.0  + bill_to_contact_id, billing_mode, invoice_kind, last_error,
 *            queued_at; UNIQUE (company, year, invoice_kind, bill_to)
 *   1.2.0  Stripe migration: fluentcart_customer_id/order_id/order_uuid
 *            renamed (+ widened to VARCHAR) to gateway_customer_id/
 *            gateway_invoice_id/gateway_invoice_number; + gateway,
 *            livemode, hosted_invoice_url, invoice_pdf_url,
 *            amount_paid_cents, amount_due_cents, amount_refunded_cents,
 *            paid_off_stripe_cents, primary_method, due_date,
 *            finalized_at, processing_at, voided_at, last_synced_at,
 *            stripe_status; UNIQUE (company, year, invoice_kind, bill_to,
 *            livemode) — a test-mode and a live-mode row for the same
 *            firm/year must not collide.
 */
class MyNJILGA_Dues_Invoice_Table {

    const OPTION_DB_VERSION = 'njilga_dues_db_version';
    const DB_VERSION        = '1.2.0';

    const STATUS_DRAFT         = 'draft';
    const STATUS_APPROVED      = 'approved';
    const STATUS_CREATED       = 'created';
    const STATUS_SENT          = 'sent';
    // ACH-in-flight (spec: Stripe migration phase 3) — set only by the
    // payment_intent.processing webhook event; never settles membership.
    const STATUS_PROCESSING    = 'processing';
    const STATUS_PAID          = 'paid';
    const STATUS_DOWNGRADED    = 'downgraded';
    // Terminal, Stripe-driven outcomes (spec: Stripe migration phase 3) —
    // set only by the invoice.voided / invoice.marked_uncollectible
    // webhook events.
    const STATUS_VOIDED        = 'voided';
    const STATUS_UNCOLLECTIBLE = 'uncollectible';
    const STATUS_EXCLUDED      = 'excluded';

    const ALL_STATUSES = [
        self::STATUS_EXCLUDED,
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_CREATED,
        self::STATUS_SENT,
        self::STATUS_PROCESSING,
        self::STATUS_PAID,
        self::STATUS_DOWNGRADED,
        self::STATUS_VOIDED,
        self::STATUS_UNCOLLECTIBLE,
    ];

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'njilga_dues_invoices';
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

        // dbDelta adds columns and indexes but never drops an index, so the
        // 1.0.0 UNIQUE (company, year) — which would block the multi-row
        // billing modes — has to go explicitly before the new key lands.
        if ( $fromVersion !== '' && version_compare( $fromVersion, '1.1.0', '<' ) ) {
            $hasOld = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
                $table,
                'firm_year'
            ) );
            if ( (int) $hasOld > 0 ) {
                $wpdb->query( "ALTER TABLE $table DROP INDEX firm_year" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        }

        // 1.2.0 (Stripe migration): same reasoning, twice over — the old
        // 4-column UNIQUE would collide a test-mode and a live-mode row for
        // the same firm/year, and the old order_id KEY names the column
        // being renamed below. dbDelta only ever ADDS a missing index; a
        // same-named index that already exists (even with different
        // columns) is left alone, so both have to be dropped explicitly
        // before dbDelta can lay the new ones down under those names.
        // Column renames (dbDelta cannot rename or retype a column) are
        // guarded the same way, on the OLD column name's presence, so a
        // second run of this block is a safe no-op.
        if ( $fromVersion !== '' && version_compare( $fromVersion, '1.2.0', '<' ) ) {
            foreach ( [ 'firm_year_kind_billto', 'order_id' ] as $oldIndex ) {
                $hasOldIndex = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
                    $table,
                    $oldIndex
                ) );
                if ( (int) $hasOldIndex > 0 ) {
                    $wpdb->query( "ALTER TABLE $table DROP INDEX $oldIndex" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                }
            }

            // old column => "new name + definition" for CHANGE COLUMN. A
            // widening numeric-to-string CHANGE (e.g. BIGINT 1234 ->
            // VARCHAR '1234') preserves existing values, so no separate
            // data-copy UPDATE is needed for the rename itself.
            $renames = [
                'fluentcart_customer_id' => 'gateway_customer_id VARCHAR(64) NULL',
                'fluentcart_order_id'    => 'gateway_invoice_id VARCHAR(64) NULL',
                'fluentcart_order_uuid'  => 'gateway_invoice_number VARCHAR(64) NULL',
            ];
            foreach ( $renames as $oldColumn => $newDefinition ) {
                $hasOldColumn = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
                    $table,
                    $oldColumn
                ) );
                if ( (int) $hasOldColumn > 0 ) {
                    $wpdb->query( "ALTER TABLE $table CHANGE COLUMN $oldColumn $newDefinition" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                }
            }
        }

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dues_year SMALLINT UNSIGNED NOT NULL,
            fluentcrm_company_id BIGINT UNSIGNED NOT NULL,
            fluentcrm_owner_contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            bill_to_contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            billing_mode VARCHAR(20) NOT NULL DEFAULT 'firm',
            invoice_kind VARCHAR(20) NOT NULL DEFAULT 'combined',
            gateway VARCHAR(20) NOT NULL DEFAULT 'stripe',
            livemode TINYINT(1) NOT NULL DEFAULT 1,
            gateway_customer_id VARCHAR(64) NULL,
            gateway_invoice_id VARCHAR(64) NULL,
            gateway_invoice_number VARCHAR(64) NULL,
            hosted_invoice_url VARCHAR(512) NULL,
            invoice_pdf_url VARCHAR(512) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            total_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
            amount_paid_cents INT UNSIGNED NOT NULL DEFAULT 0,
            amount_due_cents INT UNSIGNED NOT NULL DEFAULT 0,
            amount_refunded_cents INT UNSIGNED NOT NULL DEFAULT 0,
            paid_off_stripe_cents INT UNSIGNED NOT NULL DEFAULT 0,
            primary_method VARCHAR(24) NULL,
            stripe_status VARCHAR(24) NULL,
            roster_snapshot LONGTEXT NOT NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            approved_at DATETIME NULL,
            queued_at DATETIME NULL,
            sent_at DATETIME NULL,
            due_date DATE NULL,
            finalized_at DATETIME NULL,
            processing_at DATETIME NULL,
            paid_at DATETIME NULL,
            downgraded_at DATETIME NULL,
            voided_at DATETIME NULL,
            last_synced_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY firm_year_kind_billto (fluentcrm_company_id, dues_year, invoice_kind, bill_to_contact_id, livemode),
            KEY gateway_invoice (gateway_invoice_id),
            KEY year_status (dues_year, status),
            KEY year_mode_status (dues_year, livemode, status)
        ) $charset_collate;";

        dbDelta( $sql );

        // 1.0.0 rows: the firm invoice was billed to the Owner.
        if ( $fromVersion !== '' && version_compare( $fromVersion, '1.1.0', '<' ) ) {
            $wpdb->query( "UPDATE $table SET bill_to_contact_id = fluentcrm_owner_contact_id WHERE bill_to_contact_id = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // 1.2.0 backfill: every pre-migration row was created in what
        // amounts to a single "live" world, so make that explicit rather
        // than relying only on the column default. And only a row that
        // actually got a real order created under FluentCart is a legacy
        // fluentcart row — a draft/approved/excluded row that never had an
        // order created keeps the new column's 'stripe' default, since if
        // it's ever actually created going forward it goes through the new
        // Stripe gateway.
        if ( $fromVersion !== '' && version_compare( $fromVersion, '1.2.0', '<' ) ) {
            $wpdb->query( "UPDATE $table SET livemode = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "UPDATE $table SET gateway = 'fluentcart' WHERE gateway_invoice_id IS NOT NULL AND gateway_invoice_id != ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    public static function get( int $id ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore
    }

    public static function get_by_order_id( string $invoiceId ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE gateway_invoice_id = %s", $invoiceId ) ); // phpcs:ignore
    }

    /**
     * @return object|null
     */
    public static function find_row( int $companyId, int $duesYear, string $invoiceKind, int $billToContactId, bool $livemode ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE fluentcrm_company_id = %d AND dues_year = %d AND invoice_kind = %s AND bill_to_contact_id = %d AND livemode = %d",
            $companyId,
            $duesYear,
            $invoiceKind,
            $billToContactId,
            $livemode ? 1 : 0
        ) );
    }

    /**
     * $livemode null = every row for the year regardless of mode (only
     * ever correct for a caller that post-filters itself, e.g. the
     * reconciler and the Payments ledger, which already scope explicitly
     * per row) — every OTHER caller must pass the active mode so a
     * test-mode row can never leak into a live-mode read, or vice versa.
     *
     * @param array<int,string> $statuses Optional status filter (empty = all).
     * @return array<int,object>
     */
    public static function get_by_year( int $duesYear, array $statuses = [], ?bool $livemode = null ): array {
        global $wpdb;
        $table = self::table_name();

        $where  = [ 'dues_year = %d' ];
        $params = [ $duesYear ];
        if ( ! empty( $statuses ) ) {
            $where[]  = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
            $params   = array_merge( $params, $statuses );
        }
        if ( $livemode !== null ) {
            $where[]  = 'livemode = %d';
            $params[] = $livemode ? 1 : 0;
        }

        $query = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';
        return (array) $wpdb->get_results( $wpdb->prepare( $query, $params ) ); // phpcs:ignore
    }

    /**
     * Every invoice row, any year, for the given companies — newest year
     * first. Backs the member-facing firm status page.
     *
     * @param array<int,int> $companyIds
     * @return array<int,object>
     */
    public static function get_for_companies( array $companyIds ): array {
        global $wpdb;
        $companyIds = array_values( array_filter( array_map( 'intval', $companyIds ) ) );
        if ( empty( $companyIds ) ) {
            return [];
        }
        $table        = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $companyIds ), '%d' ) );
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE fluentcrm_company_id IN ($placeholders) ORDER BY dues_year DESC, id ASC",
            $companyIds
        ) );
    }

    /**
     * Rows still owed for the year that would settle MEMBERSHIP if paid —
     * anything not yet paid / downgraded / excluded, excluding
     * assessment-only invoices (an unpaid dinner assessment doesn't lapse
     * a membership). Used by the downgrade sweep.
     *
     * Also excludes STATUS_VOIDED and STATUS_UNCOLLECTIBLE (staff already
     * closed these out — sweeping them again is meaningless) and
     * STATUS_PROCESSING (an ACH payment in flight is not an unpaid firm;
     * it settles, or doesn't, on the eventual invoice.paid/payment_failed
     * outcome, never by lapsing mid-clearing).
     *
     * $livemode is required, not optional — this sweep tags real FluentCRM
     * contacts and can remove real WordPress roles; it must never act on a
     * stale row from the other Stripe mode.
     *
     * @return array<int,object>
     */
    public static function get_unpaid_for_sweep( int $duesYear, bool $livemode ): array {
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE dues_year = %d AND livemode = %d AND status NOT IN (%s, %s, %s, %s, %s, %s) AND invoice_kind <> %s ORDER BY id ASC",
            $duesYear,
            $livemode ? 1 : 0,
            self::STATUS_PAID,
            self::STATUS_DOWNGRADED,
            self::STATUS_EXCLUDED,
            self::STATUS_VOIDED,
            self::STATUS_UNCOLLECTIBLE,
            self::STATUS_PROCESSING,
            MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT
        ) );
    }

    /**
     * @return array<string,int> status => count (only statuses present).
     */
    public static function counts_by_status( int $duesYear, ?bool $livemode = null ): array {
        global $wpdb;
        $table  = self::table_name();
        $where  = [ 'dues_year = %d' ];
        $params = [ $duesYear ];
        if ( $livemode !== null ) {
            $where[]  = 'livemode = %d';
            $params[] = $livemode ? 1 : 0;
        }
        $rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            'SELECT status, COUNT(*) AS c FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' GROUP BY status',
            $params
        ) );

        $counts = [];
        foreach ( $rows as $row ) {
            $counts[ $row->status ] = (int) $row->c;
        }
        return $counts;
    }

    /**
     * Batch totals for the year, in cents, by status.
     *
     * @return array<string,int> status => sum(total_amount_cents)
     */
    public static function totals_by_status( int $duesYear, ?bool $livemode = null ): array {
        global $wpdb;
        $table  = self::table_name();
        $where  = [ 'dues_year = %d' ];
        $params = [ $duesYear ];
        if ( $livemode !== null ) {
            $where[]  = 'livemode = %d';
            $params[] = $livemode ? 1 : 0;
        }
        $rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            'SELECT status, SUM(total_amount_cents) AS t FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' GROUP BY status',
            $params
        ) );
        $totals = [];
        foreach ( $rows as $row ) {
            $totals[ $row->status ] = (int) $row->t;
        }
        return $totals;
    }

    /**
     * Ids of rows that carry a last_error (creation/send failures), for
     * the dashboard's "needs attention" list.
     *
     * @return array<int,object>
     */
    public static function get_with_errors( int $duesYear ): array {
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE dues_year = %d AND last_error IS NOT NULL AND last_error <> '' ORDER BY id ASC",
            $duesYear
        ) );
    }

    /**
     * Every row, across every dues year, in the given mode, carrying a
     * last_error and not excluded — the Setup page's Stripe reconciliation
     * "Needs attention" panel. Unlike get_with_errors(), not scoped to one
     * dues year: a payment-event or reconciler flag belongs on that list
     * regardless of which year or stage the row is in.
     *
     * @return array<int,object>
     */
    public static function get_flagged( bool $livemode ): array {
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE livemode = %d AND status <> %s AND last_error IS NOT NULL AND last_error <> '' ORDER BY dues_year DESC, id ASC",
            $livemode ? 1 : 0,
            self::STATUS_EXCLUDED
        ) );
    }

    /**
     * @return array<int,int> Distinct dues years present, newest first.
     */
    public static function years(): array {
        global $wpdb;
        $table = self::table_name();
        return array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT dues_year FROM $table ORDER BY dues_year DESC" ) ); // phpcs:ignore
    }

    /**
     * Distinct dues years, newest first, that have at least one row in the
     * given statuses and mode — e.g. "which years still have anything
     * non-terminal in Stripe's active mode". Used by the reconciler's
     * daily sweep (MyNJILGA_Stripe_Reconciler::run_daily()) to know which
     * years are even worth checking, without re-fetching every paid/
     * terminal row every day.
     *
     * @param array<int,string> $statuses
     * @return array<int,int>
     */
    public static function years_with_status( array $statuses, bool $livemode ): array {
        global $wpdb;
        if ( empty( $statuses ) ) {
            return [];
        }
        $table        = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $query        = "SELECT DISTINCT dues_year FROM $table WHERE livemode = %d AND status IN ($placeholders) ORDER BY dues_year DESC";
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
            $query,
            array_merge( [ $livemode ? 1 : 0 ], $statuses )
        ) ) );
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Insert a fresh draft/excluded row, or — if one exists for the same
     * (company, year, kind, bill-to, livemode) and is STILL draft/excluded
     * — refresh it with the newly computed snapshot. Rows past draft are
     * left untouched: re-running "Generate Preview" can never clobber a
     * roster that's already been approved or billed.
     *
     * $data['livemode'] fixes which Stripe mode this row belongs to for
     * its whole lifetime, from the moment the draft is generated — a
     * preview run in Test mode and one in Live mode for the same firm/year
     * are two distinct rows (the UNIQUE KEY includes livemode precisely so
     * they don't collide); find_row() below only ever looks up a row in
     * the SAME mode, so switching modes and re-running Generate Preview
     * inserts a fresh row rather than reusing the other mode's.
     *
     * @param array{dues_year:int,fluentcrm_company_id:int,fluentcrm_owner_contact_id:int,bill_to_contact_id:int,billing_mode:string,invoice_kind:string,status:string,total_amount_cents:int,roster_snapshot:string,livemode:bool} $data
     * @return int|null The row id, or null if an existing non-draft row blocked the refresh.
     */
    public static function upsert_draft( array $data ): ?int {
        global $wpdb;
        $table    = self::table_name();
        $livemode = (bool) ( $data['livemode'] ?? true );
        $existing = self::find_row(
            (int) $data['fluentcrm_company_id'],
            (int) $data['dues_year'],
            (string) $data['invoice_kind'],
            (int) $data['bill_to_contact_id'],
            $livemode
        );

        if ( $existing ) {
            if ( ! in_array( $existing->status, [ self::STATUS_DRAFT, self::STATUS_EXCLUDED ], true ) ) {
                return null;
            }
            $wpdb->update(
                $table,
                [
                    'fluentcrm_owner_contact_id' => (int) $data['fluentcrm_owner_contact_id'],
                    'billing_mode'               => (string) $data['billing_mode'],
                    'status'                     => (string) $data['status'],
                    'total_amount_cents'         => (int) $data['total_amount_cents'],
                    'roster_snapshot'            => (string) $data['roster_snapshot'],
                    'last_error'                 => null,
                ],
                [ 'id' => $existing->id ],
                [ '%d', '%s', '%s', '%d', '%s', '%s' ],
                [ '%d' ]
            );
            return (int) $existing->id;
        }

        $wpdb->insert(
            $table,
            [
                'dues_year'                  => (int) $data['dues_year'],
                'fluentcrm_company_id'       => (int) $data['fluentcrm_company_id'],
                'fluentcrm_owner_contact_id' => (int) $data['fluentcrm_owner_contact_id'],
                'bill_to_contact_id'         => (int) $data['bill_to_contact_id'],
                'billing_mode'               => (string) $data['billing_mode'],
                'invoice_kind'               => (string) $data['invoice_kind'],
                'status'                     => (string) $data['status'],
                'total_amount_cents'         => (int) $data['total_amount_cents'],
                'roster_snapshot'            => (string) $data['roster_snapshot'],
                'livemode'                   => $livemode ? 1 : 0,
                'created_at'                 => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * After a preview run, drop draft/excluded rows for (company, year)
     * that the run did NOT produce — e.g. the firm's billing mode changed
     * from individual back to firm, so last time's per-member drafts are
     * now stale. Never touches approved-or-later rows.
     *
     * @param array<int,int> $keepIds
     * @return int Rows deleted.
     */
    public static function delete_stale_drafts( int $companyId, int $duesYear, array $keepIds, bool $livemode ): int {
        global $wpdb;
        $table   = self::table_name();
        $keepIds = array_values( array_filter( array_map( 'intval', $keepIds ) ) );

        // Scoped to $livemode: a preview run in one mode must never delete
        // the OTHER mode's stale drafts for this firm/year — they weren't
        // (and can't be) reproduced by this run's $keepIds at all.
        $sql  = "DELETE FROM $table WHERE fluentcrm_company_id = %d AND dues_year = %d AND livemode = %d AND status IN (%s, %s)";
        $args = [ $companyId, $duesYear, $livemode ? 1 : 0, self::STATUS_DRAFT, self::STATUS_EXCLUDED ];
        if ( $keepIds ) {
            $sql   .= ' AND id NOT IN (' . implode( ',', array_fill( 0, count( $keepIds ), '%d' ) ) . ')';
            $args   = array_merge( $args, $keepIds );
        }
        return (int) $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
    }

    /**
     * @param array<int,int> $ids
     * @return int Rows actually moved to approved (only drafts qualify).
     */
    public static function mark_approved( array $ids ): int {
        return self::bulk_set_status( $ids, self::STATUS_APPROVED, 'approved_at', [ self::STATUS_DRAFT ] );
    }

    /**
     * Stamp a row as created against the gateway. The base fields
     * (customer/invoice id+number, status, cleared error/queued_at) are
     * always written; $extra optionally carries whatever of these the
     * gateway's create_order() response provided — each written only
     * when the KEY is present in $extra (array_key_exists, not just
     * truthy), so omitting a key never clobbers that column with null:
     *   'hosted_invoice_url' => string
     *   'invoice_pdf_url'    => string
     *   'due_date'           => string ('Y-m-d', or '' for NULL)
     *   'finalized_at'       => string (mysql datetime)
     *   'stripe_status'      => string
     *   'amount_due_cents'   => int
     *   'gateway'            => string (defaults to 'stripe' when absent
     *                            — a row only reaches mark_created()
     *                            through an active gateway's create_order())
     *
     * @param array<string,mixed> $extra
     */
    public static function mark_created( int $id, ?string $customerId, string $invoiceId, string $invoiceNumber, array $extra = [] ): void {
        global $wpdb;

        $data = [
            'gateway_customer_id'    => $customerId,
            'gateway_invoice_id'     => $invoiceId,
            'gateway_invoice_number' => $invoiceNumber,
            'status'                 => self::STATUS_CREATED,
            'last_error'             => null,
            'queued_at'              => null,
            'gateway'                => array_key_exists( 'gateway', $extra ) ? (string) $extra['gateway'] : 'stripe',
        ];
        $format = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

        $optional = [
            'hosted_invoice_url' => '%s',
            'invoice_pdf_url'    => '%s',
            'due_date'           => '%s',
            'finalized_at'       => '%s',
            'stripe_status'      => '%s',
            'amount_due_cents'   => '%d',
        ];
        foreach ( $optional as $key => $fmt ) {
            if ( ! array_key_exists( $key, $extra ) ) {
                continue;
            }
            if ( $key === 'due_date' ) {
                // '' means "no due date" (NULL), not the literal string.
                $data[ $key ] = ( (string) $extra[ $key ] === '' ) ? null : (string) $extra[ $key ];
            } elseif ( $fmt === '%d' ) {
                $data[ $key ] = (int) $extra[ $key ];
            } else {
                $data[ $key ] = (string) $extra[ $key ];
            }
            $format[] = $fmt;
        }

        $wpdb->update(
            self::table_name(),
            $data,
            [ 'id' => $id ],
            $format,
            [ '%d' ]
        );
    }

    /**
     * @param array<int,int> $ids
     */
    public static function mark_sent( array $ids ): int {
        return self::bulk_set_status( $ids, self::STATUS_SENT, 'sent_at', [ self::STATUS_CREATED, self::STATUS_SENT ] );
    }

    public static function mark_paid( int $id ): void {
        self::bulk_set_status( [ $id ], self::STATUS_PAID, 'paid_at', [] );
    }

    /**
     * @param array<int,int> $ids
     */
    public static function mark_downgraded( array $ids ): int {
        return self::bulk_set_status( $ids, self::STATUS_DOWNGRADED, 'downgraded_at', [] );
    }

    /**
     * Flag rows as queued for background creation.
     *
     * @param array<int,int> $ids
     */
    public static function mark_queued( array $ids ): void {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql' );
        foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
            $wpdb->update( $table, [ 'queued_at' => $now, 'last_error' => null ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
        }
    }

    public static function set_error( int $id, string $message ): void {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            [ 'last_error' => mb_substr( $message, 0, 2000 ), 'queued_at' => null ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    public static function clear_error( int $id ): void {
        global $wpdb;
        $wpdb->update( self::table_name(), [ 'last_error' => null ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
    }

    /**
     * Generic partial-field update over a fixed whitelist of columns —
     * this class owns all SQL against this table, so a caller (the
     * Stripe webhook receiver's several event handlers, a later phase's
     * reconciler) that needs to refresh a handful of gateway-driven
     * fields at once (status, stripe_status/last_synced_at, amounts,
     * timestamps, ...) comes through here rather than hand-rolling its
     * own $wpdb call. Only whitelisted keys in $fields are written; an
     * unrecognized key is silently ignored (a caller bug, not user
     * input needing to be guarded against) and an empty/all-unrecognized
     * $fields is a no-op.
     *
     * @param array<string,mixed> $fields
     */
    public static function update_gateway_fields( int $id, array $fields ): void {
        global $wpdb;

        $allowed = [
            'status'                 => '%s',
            'primary_method'         => '%s',
            'stripe_status'          => '%s',
            'last_synced_at'         => '%s',
            'amount_paid_cents'      => '%d',
            'amount_due_cents'       => '%d',
            'amount_refunded_cents'  => '%d',
            'paid_off_stripe_cents'  => '%d',
            'hosted_invoice_url'     => '%s',
            'invoice_pdf_url'        => '%s',
            'gateway_invoice_number' => '%s',
            'gateway_invoice_id'     => '%s',
            'gateway_customer_id'    => '%s',
            'processing_at'          => '%s',
            'voided_at'              => '%s',
            'finalized_at'           => '%s',
            'last_error'             => '%s',
            'due_date'               => '%s',
        ];

        $data   = [];
        $format = [];
        foreach ( $fields as $key => $value ) {
            if ( ! array_key_exists( $key, $allowed ) ) {
                continue;
            }
            $data[ $key ] = ( $allowed[ $key ] === '%d' ) ? (int) $value : $value;
            $format[]     = $allowed[ $key ];
        }

        if ( empty( $data ) ) {
            return;
        }

        $wpdb->update( self::table_name(), $data, [ 'id' => $id ], $format, [ '%d' ] );
    }

    /**
     * @param array<int,int>    $ids
     * @param array<int,string> $onlyFrom Restrict to rows currently in these statuses (empty = any).
     * @return int Rows updated.
     */
    private static function bulk_set_status( array $ids, string $status, string $timestamp_column, array $onlyFrom ): int {
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( empty( $ids ) ) {
            return 0;
        }
        $table   = self::table_name();
        $now     = current_time( 'mysql' );
        $updated = 0;

        foreach ( $ids as $id ) {
            $where = [ 'id' => $id ];
            if ( $onlyFrom ) {
                $row = self::get( $id );
                if ( ! $row || ! in_array( $row->status, $onlyFrom, true ) ) {
                    continue;
                }
            }
            $r = $wpdb->update(
                $table,
                [ 'status' => $status, $timestamp_column => $now ],
                $where,
                [ '%s', '%s' ],
                [ '%d' ]
            );
            if ( $r ) {
                $updated++;
            }
        }
        return $updated;
    }
}
