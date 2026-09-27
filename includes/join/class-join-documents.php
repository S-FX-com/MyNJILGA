<?php
/**
 * Student ID / transcript uploads from the join form.
 *
 * These are identity documents, so they never go in the Media Library
 * (whose files are public by URL and listed to every author). They land
 * in a private directory under uploads — deny-all .htaccess for Apache,
 * a silent index.php, and an unguessable 128-bit filename for servers
 * such as nginx that ignore .htaccess — and are only ever read back
 * through stream(), which checks the viewer is staff. Define
 * NJILGA_PRIVATE_DIR in wp-config.php to keep them outside the web root
 * altogether.
 */
class MyNJILGA_Join_Documents {

    const MAX_BYTES     = 8388608; // 8 MB
    const ACTION_VIEW   = 'my_njilga_join_document';

    /** extension => mime — what a phone photo of an ID card or a PDF transcript is. */
    const ALLOWED = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'pdf'  => 'application/pdf',
    ];

    public static function register(): void {
        add_action( 'admin_post_' . self::ACTION_VIEW, [ __CLASS__, 'handle_view' ] );
    }

    public static function dir(): string {
        if ( defined( 'NJILGA_PRIVATE_DIR' ) && is_string( NJILGA_PRIVATE_DIR ) && NJILGA_PRIVATE_DIR !== '' ) {
            return rtrim( NJILGA_PRIVATE_DIR, '/\\' ) . '/join-documents';
        }
        $uploads = wp_upload_dir( null, false );
        return rtrim( (string) $uploads['basedir'], '/\\' ) . '/njilga-private/join-documents';
    }

    private static function ensure_dir(): bool {
        $dir = self::dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }
        // Belt and braces, re-written if anyone deletes them.
        foreach ( [ $dir, dirname( $dir ) ] as $d ) {
            if ( ! file_exists( $d . '/.htaccess' ) ) {
                @file_put_contents( $d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
            }
            if ( ! file_exists( $d . '/index.php' ) ) {
                @file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
            }
        }
        return true;
    }

    /**
     * Validate and store one entry of $_FILES.
     *
     * @param array<string,mixed> $file
     * @return array{ok:bool,path?:string,name?:string,mime?:string,error?:string}
     */
    public static function store( array $file ): array {
        $err = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
        if ( $err === UPLOAD_ERR_NO_FILE ) {
            return [ 'ok' => false, 'error' => 'Please upload your student ID or transcript.' ];
        }
        if ( $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ) {
            return [ 'ok' => false, 'error' => 'That file is too large — the limit is 8 MB.' ];
        }
        if ( $err !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
            return [ 'ok' => false, 'error' => 'The upload did not come through — please try again.' ];
        }
        if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
            return [ 'ok' => false, 'error' => 'That file is too large — the limit is 8 MB.' ];
        }

        $name  = sanitize_file_name( (string) ( $file['name'] ?? 'document' ) );
        // Checks the real content type, not just the extension the
        // browser claims — a script renamed .pdf is refused here.
        $check = wp_check_filetype_and_ext( (string) $file['tmp_name'], $name, self::ALLOWED );
        $ext   = strtolower( (string) ( $check['ext'] ?? '' ) );
        $mime  = (string) ( $check['type'] ?? '' );
        if ( $ext === '' || $mime === '' || ! isset( self::ALLOWED[ $ext ] ) ) {
            return [ 'ok' => false, 'error' => 'Please upload a PDF or a photo (JPG, PNG, WEBP or HEIC).' ];
        }

        if ( ! self::ensure_dir() ) {
            return [ 'ok' => false, 'error' => 'The document could not be saved. Please try again or contact NJILGA.' ];
        }
        $stored = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
        if ( ! @move_uploaded_file( (string) $file['tmp_name'], self::dir() . '/' . $stored ) ) { // phpcs:ignore
            return [ 'ok' => false, 'error' => 'The document could not be saved. Please try again or contact NJILGA.' ];
        }

        return [ 'ok' => true, 'path' => $stored, 'name' => $name, 'mime' => $mime ];
    }

    /**
     * Absolute path for a stored name, or '' when it isn't one of ours.
     */
    public static function absolute( string $stored ): string {
        if ( ! preg_match( '/^[a-f0-9]{32}\.[a-z]{3,4}$/', $stored ) ) {
            return ''; // Never a path a caller built — only names store() made.
        }
        $path = self::dir() . '/' . $stored;
        return is_file( $path ) ? $path : '';
    }

    public static function delete( string $stored ): void {
        $path = self::absolute( $stored );
        if ( $path !== '' ) {
            @unlink( $path ); // phpcs:ignore
        }
    }

    public static function view_url( int $joinId ): string {
        return wp_nonce_url( add_query_arg( [ 'action' => self::ACTION_VIEW, 'join' => $joinId ], admin_url( 'admin-post.php' ) ), self::ACTION_VIEW . '_' . $joinId );
    }

    public static function handle_view(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        $joinId = (int) ( $_GET['join'] ?? 0 );
        check_admin_referer( self::ACTION_VIEW . '_' . $joinId );

        $join = MyNJILGA_Join_Orders_Table::get( $joinId );
        $path = $join ? self::absolute( (string) $join->document_path ) : '';
        if ( $path === '' ) {
            wp_die( 'That document is no longer on file.' );
        }

        $mime = in_array( (string) $join->document_mime, self::ALLOWED, true ) ? (string) $join->document_mime : 'application/octet-stream';
        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: inline; filename="' . str_replace( [ '"', "\r", "\n" ], '', (string) $join->document_name ) . '"' );
        readfile( $path ); // phpcs:ignore
        exit;
    }
}
