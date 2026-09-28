<?php
/**
 * `{$wpdb->prefix}njilga_join_orders` — one row per online join attempt
 * (the [njilga_join] shortcode): who is joining, as what, for which firm,
 * which colleagues they are paying for, what it costs, and where the
 * Stripe Checkout for it stands.
 *
 * Nothing about a join touches FluentCRM until the payment is confirmed —
 * this row is the whole record of an unpaid attempt, so an abandoned
 * checkout leaves no half-made firm, contact or tag behind.
 *
 * Statuses:
 *   pending     Checkout open, waiting for the applicant to pay
 *   processing  Checkout completed with an ACH debit still clearing
 *   paid        Payment confirmed; fulfillment not finished (or failed —
 *               last_error says why, and it is retried)
 *   fulfilling  Claimed by a fulfillment run (a stale claim is reclaimed)
 *   fulfilled   Membership applied to everyone on it — terminal
 *   review      Nothing to charge; waiting for staff Approve/Reject
 *   rejected    Staff rejected a review join — terminal
 *   expired     Checkout expired unpaid — terminal
 *   failed      ACH debit failed — terminal
 *   abandoned   The applicant started over — terminal
 */
class MyNJILGA_Join_Orders_Table {

    const OPTION_DB_VERSION = 'njilga_join_orders_db_version';
    const DB_VERSION        = '1.0.0';

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_PAID       = 'paid';
    const STATUS_FULFILLING = 'fulfilling';
    const STATUS_FULFILLED  = 'fulfilled';
    const STATUS_REVIEW     = 'review';
    const STATUS_REJECTED   = 'rejected';
    const STATUS_EXPIRED    = 'expired';
    const STATUS_FAILED     = 'failed';
    const STATUS_ABANDONED  = 'abandoned';

    /** A fulfillment claim older than this is presumed dead (fatal error, killed worker) and may be taken over. */
    const CLAIM_STALE_SECONDS = 600;

    const FORM_PROFESSIONAL = 'professional';
    const FORM_STUDENT      = 'student';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'njilga_join_orders';
    }

    public static function maybe_upgrade(): void {
        if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            category_key VARCHAR(60) NOT NULL DEFAULT '',
            form VARCHAR(20) NOT NULL DEFAULT 'professional',
            livemode TINYINT(1) NOT NULL DEFAULT 1,
            dues_year SMALLINT UNSIGNED NOT NULL,
            wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            email VARCHAR(190) NOT NULL DEFAULT '',
            first_name VARCHAR(190) NOT NULL DEFAULT '',
            last_name VARCHAR(190) NOT NULL DEFAULT '',
            company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            new_company_name VARCHAR(255) NOT NULL DEFAULT '',
            applicant LONGTEXT NULL,
            colleagues LONGTEXT NULL,
            priced LONGTEXT NULL,
            total_cents INT UNSIGNED NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT 'usd',
            attempt INT UNSIGNED NOT NULL DEFAULT 0,
            stripe_session_id VARCHAR(255) NULL,
            stripe_session_url TEXT NULL,
            stripe_customer_id VARCHAR(64) NULL,
            stripe_payment_intent_id VARCHAR(64) NULL,
            stripe_invoice_id VARCHAR(64) NULL,
            document_path VARCHAR(255) NOT NULL DEFAULT '',
            document_name VARCHAR(255) NOT NULL DEFAULT '',
            document_mime VARCHAR(100) NOT NULL DEFAULT '',
            source_url TEXT NULL,
            applicant_contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            invoice_row_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            progress LONGTEXT NULL,
            ip VARCHAR(64) NOT NULL DEFAULT '',
            last_error TEXT NULL,
            decided_by BIGINT UNSIGNED NULL,
            decision_note TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            fulfilled_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY email (email),
            KEY wp_user (wp_user_id),
            KEY session (stripe_session_id(64)),
            KEY payment_intent (stripe_payment_intent_id)
        ) $charset_collate;" );

        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    public static function get( int $id ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore
    }

    public static function get_by_session( string $sessionId ) {
        if ( $sessionId === '' ) {
            return null;
        }
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE stripe_session_id = %s ORDER BY id DESC", $sessionId ) ); // phpcs:ignore
    }

    public static function get_by_payment_intent( string $paymentIntentId ) {
        if ( $paymentIntentId === '' ) {
            return null;
        }
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE stripe_payment_intent_id = %s ORDER BY id DESC", $paymentIntentId ) ); // phpcs:ignore
    }

    /**
     * The one join this user still has open (awaiting payment, clearing,
     * or waiting on staff) — resumed rather than duplicated.
     */
    public static function get_open_for_user( int $userId ) {
        if ( $userId <= 0 ) {
            return null;
        }
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE wp_user_id = %d AND status IN (%s, %s, %s, %s, %s) ORDER BY id DESC",
            $userId,
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_PAID,
            self::STATUS_FULFILLING,
            self::STATUS_REVIEW
        ) );
    }

    /**
     * @param array<int,string> $statuses Empty = every status.
     * @return array<int,object>
     */
    public static function get_list( array $statuses = [], int $limit = 200 ): array {
        global $wpdb;
        $table  = self::table_name();
        $where  = '1=1';
        $params = [];
        if ( $statuses ) {
            $where  = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
            $params = $statuses;
        }
        $params[] = max( 1, $limit );
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT %d", $params ) ); // phpcs:ignore
    }

    /**
     * @return array<string,int> status => count
     */
    public static function counts_by_status(): array {
        global $wpdb;
        $table = self::table_name();
        $out   = [];
        foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM $table GROUP BY status" ) as $r ) { // phpcs:ignore
            $out[ (string) $r->status ] = (int) $r->c;
        }
        return $out;
    }

    /**
     * Joins the daily sweep should look at: every one still waiting on
     * Stripe, every paid one whose fulfillment hasn't finished, and any
     * fulfillment claim that has gone stale.
     *
     * Least recently looked at first — the sweep touch()es each row it
     * visits — so rows that can't move on their own (a payment held back
     * for review, a checkout in a Stripe mode no longer connected) go to
     * the back of the queue instead of filling every batch ahead of a
     * paid join whose webhook was missed. A pending join that never got
     * a checkout has nothing to check at Stripe and is left out.
     *
     * @return array<int,object>
     */
    public static function get_for_sweep(): array {
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE status IN (%s, %s, %s, %s) AND NOT ( status = %s AND ( stripe_session_id IS NULL OR stripe_session_id = '' ) ) ORDER BY updated_at ASC, id ASC LIMIT 200",
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_PAID,
            self::STATUS_FULFILLING,
            self::STATUS_PENDING
        ) );
    }

    /**
     * Joins for the year and mode whose money is committed — an ACH debit
     * clearing, or paid and not yet (or only partly) applied. Their people
     * carry no "Dues Paid" tag and no invoice row yet, so the preview asks
     * for these to keep from billing them a second time.
     *
     * @return array<int,object>
     */
    public static function get_committed_for_year( int $duesYear, bool $livemode ): array {
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE dues_year = %d AND livemode = %d AND status IN (%s, %s, %s) ORDER BY id ASC",
            $duesYear,
            $livemode ? 1 : 0,
            self::STATUS_PROCESSING,
            self::STATUS_PAID,
            self::STATUS_FULFILLING
        ) );
    }

    /**
     * Joins a person on staff needs to act on: $0 joins awaiting a
     * decision, paid joins not yet applied, and applied ones that left
     * someone off the firm, covered someone twice, or were disputed.
     */
    public static function needs_attention( object $join ): bool {
        if ( in_array( $join->status, [ self::STATUS_REVIEW, self::STATUS_PAID ], true ) ) {
            return true;
        }
        // A run that died part-way (fatal error, timeout) leaves its claim
        // behind; once stale, Retry applying finishes it.
        if ( $join->status === self::STATUS_FULFILLING ) {
            return strtotime( (string) $join->updated_at ) < (int) current_time( 'timestamp' ) - self::CLAIM_STALE_SECONDS;
        }
        if ( $join->status !== self::STATUS_FULFILLED ) {
            return false;
        }
        $p = self::json( $join, 'progress' );
        return ! empty( $p['held'] ) || ! empty( $p['already_current'] ) || ! empty( $p['checks'] ) || ! empty( $p['document_unverified'] ) || (string) ( $join->last_error ?? '' ) !== '';
    }

    public static function needs_attention_count(): int {
        global $wpdb;
        $table = self::table_name();
        // progress is wp_json_encode() output: a non-empty `held` map
        // encodes as an object, a non-empty `already_current` list as an
        // array of strings.
        return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
            "SELECT COUNT(*) FROM $table WHERE status IN (%s, %s) OR ( status = %s AND updated_at < %s ) OR ( status = %s AND ( progress LIKE %s OR progress LIKE %s OR progress LIKE %s OR progress LIKE %s OR ( last_error IS NOT NULL AND last_error <> '' ) ) )",
            self::STATUS_REVIEW,
            self::STATUS_PAID,
            self::STATUS_FULFILLING,
            gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - self::CLAIM_STALE_SECONDS ),
            self::STATUS_FULFILLED,
            '%' . $wpdb->esc_like( '"held":{' ) . '%',
            '%' . $wpdb->esc_like( '"already_current":["' ) . '%',
            '%' . $wpdb->esc_like( '"checks":["' ) . '%',
            '%' . $wpdb->esc_like( '"document_unverified":true' ) . '%'
        ) );
    }

    /**
     * Another join for the same year and mode whose money is committed —
     * an ACH debit clearing, or paid and being applied — naming this email
     * as its payer or as one of its colleagues. The "Dues Paid" tag only
     * lands once a join settles, so without this check a join clearing
     * for four days would let the same person be paid for twice.
     *
     * Deliberately NOT unpaid ('pending') joins: anyone can start one and
     * list any address, and blocking on those would let a stranger lock
     * people out of joining. Two unpaid joins that both end up paid are
     * caught at fulfillment instead (already_current()).
     *
     * @return object|null
     */
    public static function in_flight_mentioning( string $email, int $duesYear, bool $livemode, int $exceptJoinId = 0 ) {
        global $wpdb;
        $email = strtolower( trim( $email ) );
        if ( $email === '' ) {
            return null;
        }
        $table = self::table_name();
        // colleagues is JSON written by wp_json_encode(); an email is a
        // plain string value in it, so a quoted match is exact enough.
        $like = '%' . $wpdb->esc_like( '"email":"' . $email . '"' ) . '%';
        return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE id <> %d AND dues_year = %d AND livemode = %d AND status IN (%s, %s, %s) AND ( email = %s OR colleagues LIKE %s ) ORDER BY id DESC",
            $exceptJoinId,
            $duesYear,
            $livemode ? 1 : 0,
            self::STATUS_PROCESSING,
            self::STATUS_PAID,
            self::STATUS_FULFILLING,
            $email,
            $like
        ) );
    }

    /**
     * Closed joins whose uploaded student document should no longer be
     * kept — the applicant never became a member through them.
     *
     * @return array<int,object>
     */
    public static function get_documents_to_purge( int $olderThanDays ): array {
        global $wpdb;
        $table  = self::table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - $olderThanDays * 86400 );
        return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $table WHERE document_path <> '' AND status IN (%s, %s, %s, %s) AND updated_at < %s LIMIT 200",
            self::STATUS_EXPIRED,
            self::STATUS_ABANDONED,
            self::STATUS_FAILED,
            self::STATUS_REJECTED,
            $cutoff
        ) );
    }

    /**
     * JSON column decoded to an array ([] when empty/invalid).
     *
     * @return array<mixed>
     */
    public static function json( object $row, string $column ): array {
        $data = json_decode( (string) ( $row->$column ?? '' ), true );
        return is_array( $data ) ? $data : [];
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $data Any of the whitelisted columns.
     */
    public static function insert( array $data ): int {
        global $wpdb;
        $now  = current_time( 'mysql' );
        $data = array_merge( [ 'status' => self::STATUS_PENDING, 'created_at' => $now, 'updated_at' => $now ], $data );
        [ $clean, $format ] = self::whitelist( $data, true );
        $wpdb->insert( self::table_name(), $clean, $format );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $fields Any of the whitelisted columns; arrays are JSON-encoded.
     */
    public static function update( int $id, array $fields ): void {
        global $wpdb;
        $fields['updated_at'] = current_time( 'mysql' );
        [ $clean, $format ] = self::whitelist( $fields, false );
        if ( $clean ) {
            $wpdb->update( self::table_name(), $clean, [ 'id' => $id ], $format, [ '%d' ] );
        }
    }

    /**
     * Atomically take a join for fulfillment. Exactly one caller wins when
     * the webhook, the return page and the sweep all arrive at once — the
     * status test and the write are one UPDATE, so there is no window
     * between reading "not yet fulfilled" and saying "mine".
     *
     * @param array<int,string> $fromStatuses Statuses this caller may claim from; empty = only take over a stale claim.
     */
    public static function claim( int $id, array $fromStatuses ): bool {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql' );
        $stale = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - self::CLAIM_STALE_SECONDS );

        $in     = $fromStatuses ? 'status IN (' . implode( ',', array_fill( 0, count( $fromStatuses ), '%s' ) ) . ') OR ' : '';
        $params = array_merge( [ self::STATUS_FULFILLING, $now, $id ], $fromStatuses, [ self::STATUS_FULFILLING, $stale ] );
        $sql    = "UPDATE $table SET status = %s, updated_at = %s WHERE id = %d AND ( $in( status = %s AND updated_at < %s ) )";

        return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ) === 1; // phpcs:ignore
    }

    /**
     * Move a join between statuses only if it is still in one of
     * $fromStatuses — the same compare-and-set as claim(), for the plain
     * transitions (pending → processing, pending → expired, …).
     *
     * $where pins the row to more of what the caller read — above all the
     * Checkout Session it just checked: "expired" is only true of THAT
     * session, and a resume may have put a new, payable one on the join
     * while Stripe was answering.
     *
     * @param array<int,string>   $fromStatuses
     * @param array<string,mixed> $extra Other columns to write alongside.
     * @param array<string,mixed> $where Extra column => value equality conditions (null = IS NULL).
     */
    public static function transition( int $id, array $fromStatuses, string $toStatus, array $extra = [], array $where = [] ): bool {
        global $wpdb;
        $extra['status']     = $toStatus;
        $extra['updated_at'] = current_time( 'mysql' );
        [ $clean, $format ] = self::whitelist( $extra, false );

        $sets   = [];
        $params = [];
        foreach ( $clean as $col => $val ) {
            $fmt = array_shift( $format );
            if ( $val === null ) {
                $sets[] = "$col = NULL";
                continue;
            }
            $sets[]   = "$col = $fmt";
            $params[] = $val;
        }
        $table    = self::table_name();
        $in       = implode( ',', array_fill( 0, count( $fromStatuses ), '%s' ) );
        $params[] = $id;
        $params   = array_merge( $params, $fromStatuses );
        $sql      = "UPDATE $table SET " . implode( ', ', $sets ) . " WHERE id = %d AND status IN ($in)";

        // Column names come from the whitelist, never from the caller.
        [ $conds, $condFormat ] = self::whitelist( $where, false );
        foreach ( $conds as $col => $val ) {
            $fmt = array_shift( $condFormat );
            if ( $val === null ) {
                $sql .= " AND $col IS NULL";
                continue;
            }
            $sql     .= " AND $col = $fmt";
            $params[] = $val;
        }

        return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) ) === 1; // phpcs:ignore
    }

    /**
     * Mark a join as just looked at, without changing anything else — the
     * sweep does this to each row it visits, so it works through the
     * whole backlog in turn (see get_for_sweep()).
     */
    public static function touch( int $id ): void {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( $wpdb->prepare( // phpcs:ignore
            "UPDATE $table SET updated_at = %s WHERE id = %d AND status IN (%s, %s, %s, %s)",
            current_time( 'mysql' ),
            $id,
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_PAID,
            self::STATUS_FULFILLING
        ) );
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0:array<string,mixed>,1:array<int,string>}
     */
    private static function whitelist( array $data, bool $forInsert ): array {
        $columns = [
            'status'                   => '%s',
            'category_key'             => '%s',
            'form'                     => '%s',
            'livemode'                 => '%d',
            'dues_year'                => '%d',
            'wp_user_id'               => '%d',
            'email'                    => '%s',
            'first_name'               => '%s',
            'last_name'                => '%s',
            'company_id'               => '%d',
            'new_company_name'         => '%s',
            'applicant'                => '%s',
            'colleagues'               => '%s',
            'priced'                   => '%s',
            'total_cents'              => '%d',
            'currency'                 => '%s',
            'attempt'                  => '%d',
            'stripe_session_id'        => '%s',
            'stripe_session_url'       => '%s',
            'stripe_customer_id'       => '%s',
            'stripe_payment_intent_id' => '%s',
            'stripe_invoice_id'        => '%s',
            'document_path'            => '%s',
            'document_name'            => '%s',
            'document_mime'            => '%s',
            'source_url'               => '%s',
            'applicant_contact_id'     => '%d',
            'invoice_row_id'           => '%d',
            'progress'                 => '%s',
            'ip'                       => '%s',
            'last_error'               => '%s',
            'decided_by'               => '%d',
            'decision_note'            => '%s',
            'created_at'               => '%s',
            'updated_at'               => '%s',
            'paid_at'                  => '%s',
            'fulfilled_at'             => '%s',
        ];
        $clean  = [];
        $format = [];
        foreach ( $data as $key => $value ) {
            if ( ! isset( $columns[ $key ] ) || ( $forInsert && $value === null ) ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $value = (string) wp_json_encode( $value );
            } elseif ( $key === 'last_error' && is_string( $value ) ) {
                $value = mb_substr( $value, 0, 2000 );
            } elseif ( $value !== null && $columns[ $key ] === '%d' ) {
                $value = (int) $value;
            }
            $clean[ $key ] = $value;
            $format[]      = $columns[ $key ];
        }
        return [ $clean, $format ];
    }
}
