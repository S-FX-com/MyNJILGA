<?php
/**
 * `{$wpdb->prefix}njilga_join_invites` — one row per colleague a paying
 * member covered in an online join. The colleague's membership is already
 * paid and tagged when this row is written; the invite is only their way
 * in to create a website account.
 *
 * The token is never stored — only its SHA-256. The plaintext exists in
 * exactly one place, the email, so a database leak can't be turned into
 * account creation on someone else's paid membership.
 *
 * Statuses:
 *   sent              token emailed, not used yet
 *   claiming          an account is being created through it right now
 *                     (accepted_at holds when that began; a claim older
 *                     than CLAIM_STALE_SECONDS is presumed dead)
 *   accepted          they created their account through it
 *   existing_account  they already had an account with that email — the
 *                     email told them to log in; no token was issued
 */
class MyNJILGA_Join_Invites_Table {

    const OPTION_DB_VERSION = 'njilga_join_invites_db_version';
    const DB_VERSION        = '1.0.0';

    const STATUS_SENT             = 'sent';
    const STATUS_CLAIMING         = 'claiming';
    const STATUS_ACCEPTED         = 'accepted';
    const STATUS_EXISTING_ACCOUNT = 'existing_account';

    /** Creating an account takes seconds; a claim this old died with its request. */
    const CLAIM_STALE_SECONDS = 120;

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'njilga_join_invites';
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
            join_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            email VARCHAR(190) NOT NULL,
            first_name VARCHAR(190) NOT NULL DEFAULT '',
            last_name VARCHAR(190) NOT NULL DEFAULT '',
            token_hash CHAR(64) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            expires_at DATETIME NULL,
            sent_at DATETIME NULL,
            accepted_at DATETIME NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY join_email (join_id, email),
            KEY token_hash (token_hash)
        ) $charset_collate;" );

        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
    }

    public static function hash_token( string $token ): string {
        return hash( 'sha256', $token );
    }

    /**
     * A fresh URL-safe token (192 bits).
     */
    public static function new_token(): string {
        return bin2hex( random_bytes( 24 ) );
    }

    public static function get( int $id ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore
    }

    public static function get_by_token( string $token ) {
        if ( ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) {
            return null;
        }
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE token_hash = %s", self::hash_token( $token ) ) ); // phpcs:ignore
    }

    /**
     * @return array<int,object>
     */
    public static function get_for_join( int $joinId ): array {
        global $wpdb;
        $table = self::table_name();
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE join_id = %d ORDER BY id ASC", $joinId ) ); // phpcs:ignore
    }

    /**
     * Every invite for several joins in one query.
     *
     * @param array<int,int> $joinIds
     * @return array<int,array<int,object>> join id => its invites, oldest first
     */
    public static function get_for_joins( array $joinIds ): array {
        global $wpdb;
        $joinIds = array_values( array_unique( array_filter( array_map( 'intval', $joinIds ) ) ) );
        if ( ! $joinIds ) {
            return [];
        }
        $table        = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $joinIds ), '%d' ) );
        $out          = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE join_id IN ($placeholders) ORDER BY id ASC", $joinIds ) ) as $invite ) { // phpcs:ignore
            $out[ (int) $invite->join_id ][] = $invite;
        }
        return $out;
    }

    public static function find( int $joinId, string $email ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE join_id = %d AND email = %s", $joinId, $email ) ); // phpcs:ignore
    }

    /**
     * Create the invite for one colleague, or re-issue it — a fresh token
     * and a fresh expiry, which retires whatever link was emailed before.
     * An already-accepted invite is left exactly as it is, and so is one
     * whose account is being created this moment.
     *
     * @param array{contact_id:int,email:string,first_name:string,last_name:string} $person
     * @return array{id:int,status:string} status is 'accepted' or 'claiming' when nothing was (re)issued.
     */
    public static function issue( int $joinId, array $person, ?string $tokenHash, string $status, string $expiresAt ): array {
        global $wpdb;
        $table    = self::table_name();
        $now      = current_time( 'mysql' );
        $email    = strtolower( (string) $person['email'] );
        $existing = self::find( $joinId, $email );

        if ( $existing && ( $existing->status === self::STATUS_ACCEPTED || self::is_being_claimed( $existing ) ) ) {
            return [ 'id' => (int) $existing->id, 'status' => (string) $existing->status ];
        }

        $data = [
            'contact_id' => (int) $person['contact_id'],
            'first_name' => (string) $person['first_name'],
            'last_name'  => (string) $person['last_name'],
            'token_hash' => $tokenHash,
            'status'     => $status,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
            'sent_at'    => $now,
        ];

        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'id' => (int) $existing->id ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ], [ '%d' ] );
            return [ 'id' => (int) $existing->id, 'status' => $status ];
        }

        $data['join_id']    = $joinId;
        $data['email']      = $email;
        $data['created_at'] = $now;
        $wpdb->insert( $table, $data, [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ] );
        return [ 'id' => (int) $wpdb->insert_id, 'status' => $status ];
    }

    /**
     * Take the invite for one account creation BEFORE the account exists.
     * The status test and the write are one UPDATE, so of two submits at
     * once exactly one wins, and the loser never creates a user it would
     * then have to delete (which can take the colleague's FluentCRM
     * contact with it). A claim left by a request that died can be taken
     * over once stale.
     */
    public static function claim( int $id, string $tokenHash ): bool {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore
            "UPDATE $table SET status = %s, accepted_at = %s WHERE id = %d AND token_hash = %s AND ( status = %s OR ( status = %s AND ( accepted_at IS NULL OR accepted_at < %s ) ) )",
            self::STATUS_CLAIMING,
            current_time( 'mysql' ),
            $id,
            $tokenHash,
            self::STATUS_SENT,
            self::STATUS_CLAIMING,
            self::stale_before()
        ) ) === 1;
    }

    /**
     * Hand a claim back when the account couldn't be created (a taken
     * username, say), so the link works again.
     */
    public static function release_claim( int $id, string $tokenHash ): void {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( $wpdb->prepare( // phpcs:ignore
            "UPDATE $table SET status = %s, accepted_at = NULL WHERE id = %d AND token_hash = %s AND status = %s",
            self::STATUS_SENT,
            $id,
            $tokenHash,
            self::STATUS_CLAIMING
        ) );
    }

    /**
     * Single use: the token is cleared in the same write that marks the
     * claimed invite accepted, and only if it is still the one presented.
     */
    public static function mark_accepted( int $id, string $tokenHash, int $userId ): bool {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore
            "UPDATE $table SET status = %s, accepted_at = %s, user_id = %d, token_hash = NULL WHERE id = %d AND status = %s AND token_hash = %s",
            self::STATUS_ACCEPTED,
            current_time( 'mysql' ),
            $userId,
            $id,
            self::STATUS_CLAIMING,
            $tokenHash
        ) ) === 1;
    }

    /**
     * Whether an account is being created through this invite right now
     * (a claim that hasn't gone stale).
     */
    public static function is_being_claimed( object $invite ): bool {
        return $invite->status === self::STATUS_CLAIMING
            && ! empty( $invite->accepted_at )
            && (string) $invite->accepted_at >= self::stale_before();
    }

    private static function stale_before(): string {
        return gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - self::CLAIM_STALE_SECONDS );
    }

    public static function is_expired( object $invite ): bool {
        if ( empty( $invite->expires_at ) ) {
            return false;
        }
        $ts = strtotime( (string) $invite->expires_at );
        return $ts !== false && $ts < (int) current_time( 'timestamp' );
    }
}
