<?php
/**
 * Short links to a firm's Stripe invoice.
 *
 * Stripe's own invoice address is long (https://invoice.stripe.com/i/acct_…/live_…)
 * and Stripe offers no shortener, so the site makes one:
 *
 *     https://njilga.org/?njilga-inv=7-3f9a1c0b2d
 *
 * The link names the invoice's row here and carries a signature, so only
 * links this plugin made work (a visitor can't walk through row numbers).
 * It does NOT hold the Stripe address: opening it looks up the row's
 * CURRENT hosted invoice URL and sends the visitor there. So a link that
 * was copied and pasted into an email keeps working after the invoice is
 * updated, and a row that has no invoice (or was reset to a draft) says so
 * instead of going anywhere.
 *
 * No rewrite rule is involved (nothing to flush after an auto-update): it
 * is a query variable read on `init`, before WordPress works out which page
 * was asked for.
 */
class MyNJILGA_Invoice_Short_Link {

    const QUERY_VAR = 'njilga-inv';

    /** Hex characters of signature kept: 40 bits, plenty to stop guessing. */
    const SIG_LENGTH = 10;

    public static function register(): void {
        add_action( 'init', [ __CLASS__, 'maybe_redirect' ], 1 );
    }

    // -------------------------------------------------------------------------
    // Making a link
    // -------------------------------------------------------------------------

    /** The short link for this invoice row, or '' when it has no Stripe invoice to point at. */
    public static function url( object $row ): string {
        if ( (string) ( $row->hosted_invoice_url ?? '' ) === '' || (int) ( $row->id ?? 0 ) <= 0 ) {
            return '';
        }
        return add_query_arg( self::QUERY_VAR, self::token( (int) $row->id ), home_url( '/' ) );
    }

    public static function token( int $rowId ): string {
        return self::make_token( $rowId, self::secret() );
    }

    /** PURE: "<row id in base 36>-<signature>". */
    public static function make_token( int $rowId, string $secret ): string {
        return base_convert( (string) $rowId, 10, 36 ) . '-' . self::signature( $rowId, $secret );
    }

    /**
     * PURE: the row a token names, or 0 when it is malformed or its
     * signature isn't one this site made.
     */
    public static function parse_token( string $token, string $secret ): int {
        if ( ! preg_match( '/^([0-9a-z]{1,12})-([0-9a-f]{' . self::SIG_LENGTH . '})$/', $token, $m ) ) {
            return 0;
        }
        $rowId = (int) base_convert( $m[1], 36, 10 );
        if ( $rowId <= 0 ) {
            return 0;
        }
        return hash_equals( self::signature( $rowId, $secret ), $m[2] ) ? $rowId : 0;
    }

    private static function signature( int $rowId, string $secret ): string {
        return substr( hash_hmac( 'sha256', 'njilga-inv|' . $rowId, $secret ), 0, self::SIG_LENGTH );
    }

    private static function secret(): string {
        return wp_salt( 'auth' );
    }

    // -------------------------------------------------------------------------
    // Following a link
    // -------------------------------------------------------------------------

    /**
     * PURE: where a row's link goes — its hosted Stripe invoice — or ''
     * when there is nowhere safe to send the visitor. Only https is
     * followed, whatever the column holds.
     */
    public static function destination( ?object $row ): string {
        if ( ! $row ) {
            return '';
        }
        $url = (string) ( $row->hosted_invoice_url ?? '' );
        return stripos( $url, 'https://' ) === 0 ? $url : '';
    }

    public static function maybe_redirect(): void {
        if ( ! isset( $_GET[ self::QUERY_VAR ] ) || is_array( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public link, authenticated by its own signature
            return;
        }
        $rowId = self::parse_token( sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) ), self::secret() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $dest  = $rowId > 0 ? self::destination( MyNJILGA_Dues_Invoice_Table::get( $rowId ) ) : '';

        nocache_headers();
        if ( $dest === '' ) {
            wp_die(
                esc_html__( 'This invoice link is not valid, or the invoice is no longer available. Please contact NJILGA for a new one.', 'my-njilga' ),
                esc_html__( 'Invoice not found', 'my-njilga' ),
                [ 'response' => 404 ]
            );
        }
        header( 'Referrer-Policy: no-referrer' );
        wp_redirect( $dest, 302, 'My NJILGA' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe's own host, not this site
        exit;
    }
}
