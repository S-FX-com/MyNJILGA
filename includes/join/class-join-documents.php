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

    const MAX_BYTES     = 8388608; // 8 MB — our own ceiling; the server's may be lower (max_bytes()).
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
     * The largest document the form really accepts: our own ceiling, or
     * less where PHP's upload_max_filesize / post_max_size is lower (the
     * default 2 MB would otherwise refuse a phone photo the form said
     * was fine).
     */
    public static function max_bytes(): int {
        $server = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
        return $server > 0 ? min( self::MAX_BYTES, $server ) : self::MAX_BYTES;
    }

    /**
     * max_bytes() for people: "8 MB", "2 MB", "1.5 MB".
     */
    public static function max_label(): string {
        return self::size_label( self::max_bytes() );
    }

    /**
     * A byte count as the form states a limit — rounded down, so it
     * never promises more than is accepted.
     */
    public static function size_label( int $bytes ): string {
        if ( $bytes >= 1048576 ) {
            $mb = floor( $bytes / 1048576 * 10 ) / 10;
            return rtrim( rtrim( number_format( $mb, 1, '.', '' ), '0' ), '.' ) . ' MB';
        }
        return max( 1, (int) floor( $bytes / 1024 ) ) . ' KB';
    }

    /**
     * Validate and store one entry of $_FILES.
     *
     * @param array<string,mixed> $file
     * @return array{ok:bool,path?:string,name?:string,mime?:string,error?:string}
     */
    public static function store( array $file ): array {
        $err      = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
        $tooLarge = 'That file is too large — the limit is ' . self::max_label() . '. A photo of your student ID is usually much smaller than a scan.';
        if ( $err === UPLOAD_ERR_NO_FILE ) {
            return [ 'ok' => false, 'error' => 'Please upload your student ID or transcript.' ];
        }
        if ( $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ) {
            return [ 'ok' => false, 'error' => $tooLarge ];
        }
        if ( $err !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
            return [ 'ok' => false, 'error' => 'The upload did not come through — please try again.' ];
        }
        if ( (int) ( $file['size'] ?? 0 ) > self::max_bytes() ) {
            return [ 'ok' => false, 'error' => $tooLarge ];
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
        // Shown in the browser only where it renders natively; HEIC and
        // anything unrecognised download instead.
        $inline = in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'application/pdf' ], true );
        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( [ '"', '\\', "\r", "\n" ], '', (string) $join->document_name ) . '"' );
        readfile( $path ); // phpcs:ignore
        exit;
    }
}
