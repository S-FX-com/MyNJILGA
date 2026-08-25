<?php
/**
 * `{$wpdb->prefix}njilga_membership_applications` — the enrollment gate's
 * review queue (spec §10). One row per submitted application.
 *
 * An applicant is a FluentCRM contact carrying the pending-approval tag
 * and NOT attached to any Company, so the invoicing pool never sees them
 * until a human approves. This table holds what the contact record
 * can't: the requested firm (existing Company id, or a new firm name),
 * the requested category, the applicant's message, and the decision.
 */
class MyNJILGA_Applications_Table {

    const OPTION_DB_VERSION = 'njilga_applications_db_version';
    const DB_VERSION        = '1.0.0';

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'njilga_membership_applications';
    }

    public static function maybe_upgrade(): void {
        if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            first_name VARCHAR(190) NOT NULL DEFAULT '',
            last_name VARCHAR(190) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(60) NOT NULL DEFAULT '',
            fluentcrm_contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            fluentcrm_company_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            new_company_name VARCHAR(255) NOT NULL DEFAULT '',
            category_key VARCHAR(60) NOT NULL DEFAULT '',
            message TEXT NULL,
            ip VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            decided_by BIGINT UNSIGNED NULL,
            decision_note TEXT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY email (email)
        ) $charset_collate;" );

        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
    }

    public static function get( int $id ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore
    }

    public static function get_pending_by_email( string $email ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email = %s AND status = %s ORDER BY id DESC", $email, self::STATUS_PENDING ) ); // phpcs:ignore
    }

    /**
     * @return array<int,object>
     */
    public static function get_pending(): array {
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status = %s ORDER BY created_at ASC", self::STATUS_PENDING ) ); // phpcs:ignore
    }

    /**
     * @return array<int,object>
     */
    public static function get_decided( int $limit = 50 ): array {
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status <> %s ORDER BY decided_at DESC, id DESC LIMIT %d", self::STATUS_PENDING, $limit ) ); // phpcs:ignore
    }

    public static function count_pending(): int {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", self::STATUS_PENDING ) ); // phpcs:ignore
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function insert( array $data ): int {
        global $wpdb;
        $wpdb->insert(
            self::table_name(),
            [
                'status'               => self::STATUS_PENDING,
                'first_name'           => (string) ( $data['first_name'] ?? '' ),
                'last_name'            => (string) ( $data['last_name'] ?? '' ),
                'email'                => (string) ( $data['email'] ?? '' ),
                'phone'                => (string) ( $data['phone'] ?? '' ),
                'fluentcrm_contact_id' => (int) ( $data['fluentcrm_contact_id'] ?? 0 ),
                'fluentcrm_company_id' => (int) ( $data['fluentcrm_company_id'] ?? 0 ),
                'new_company_name'     => (string) ( $data['new_company_name'] ?? '' ),
                'category_key'         => (string) ( $data['category_key'] ?? '' ),
                'message'              => (string) ( $data['message'] ?? '' ),
                'ip'                   => (string) ( $data['ip'] ?? '' ),
                'created_at'           => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Refresh a still-pending application with a re-submission.
     *
     * @param array<string,mixed> $data
     */
    public static function update_pending( int $id, array $data ): void {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            [
                'first_name'           => (string) ( $data['first_name'] ?? '' ),
                'last_name'            => (string) ( $data['last_name'] ?? '' ),
                'phone'                => (string) ( $data['phone'] ?? '' ),
                'fluentcrm_contact_id' => (int) ( $data['fluentcrm_contact_id'] ?? 0 ),
                'fluentcrm_company_id' => (int) ( $data['fluentcrm_company_id'] ?? 0 ),
                'new_company_name'     => (string) ( $data['new_company_name'] ?? '' ),
                'category_key'         => (string) ( $data['category_key'] ?? '' ),
                'message'              => (string) ( $data['message'] ?? '' ),
                'created_at'           => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    public static function set_decision( int $id, string $status, int $byUserId, string $note, int $companyId = 0, int $contactId = 0 ): void {
        global $wpdb;
        $data   = [ 'status' => $status, 'decided_at' => current_time( 'mysql' ), 'decided_by' => $byUserId, 'decision_note' => $note ];
        $format = [ '%s', '%s', '%d', '%s' ];
        if ( $companyId > 0 ) {
            $data['fluentcrm_company_id'] = $companyId;
            $format[] = '%d';
        }
        if ( $contactId > 0 ) {
            $data['fluentcrm_contact_id'] = $contactId;
            $format[] = '%d';
        }
        $wpdb->update( self::table_name(), $data, [ 'id' => $id ], $format, [ '%d' ] );
    }
}
