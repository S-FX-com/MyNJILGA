<?php
/**
 * The online join — `[njilga_join category="professional"]`, pasted on
 * each Membership page (category = a Settings category key an applicant
 * may pick: professional, law_student, emerging_professional).
 *
 *   Professional / Emerging: firm (type-ahead over FluentCRM Companies,
 *     or "add your firm") → account, attorney details, mailing address →
 *     for a tier-eligible category, the firm upsell (colleagues 2–5 at
 *     the tier price, beyond 5 free) → review → Stripe Checkout.
 *   Law Student: enrolled law student or aspiring undergrad → account,
 *     contact details, school, and for an enrolled student their ID or
 *     transcript → review → Stripe Checkout.
 *
 * This class is the controller: the POST is handled on template_redirect
 * (early enough to redirect to Stripe), and the shortcode renders the
 * outcome through MyNJILGA_Join_View. Nothing is written to FluentCRM
 * here — the join record holds the answers until the payment is
 * confirmed, and MyNJILGA_Join_Fulfillment applies them.
 *
 * The same shortcode also serves a colleague's invitation link
 * (?njilga_invite=…): their "create your account" form.
 */
class MyNJILGA_Join_Form {

    const SHORTCODE    = 'njilga_join';
    const NONCE_ACTION = 'njilga_join';
    const NONCE_FIELD  = '_njilga_join_nonce';
    const ACTION_FIELD = 'njilga_join_action';

    /**
     * Submissions per IP per 10 minutes; new accounts per verified email,
     * and (looser — an office or a proxy can put many people behind one
     * address) per IP, per hour.
     */
    const RATE_SUBMITS     = 8;
    const RATE_ACCOUNTS    = 3;
    const RATE_ACCOUNTS_IP = 10;

    /**
     * "Colleague N can't be added" answers per IP and per payer per hour.
     * Each one says something about other people's records, so repeating
     * them is limited much harder than an ordinary submit.
     */
    const RATE_CONFLICTS = 5;

    const AJAX_SEND_CODE  = 'njilga_join_send_code';
    const AJAX_CHECK_CODE = 'njilga_join_check_code';
    const CODE_TTL        = 900;  // 15 minutes
    const CODE_MAX_TRIES  = 5;

    /**
     * How to get another code. The button is the way with JavaScript (an
     * empty code box is never sent then); without it the button stays
     * hidden, and an empty box is what asks for a new code — so a posted
     * form's answer adds NO_JS_CODE_HINT.
     */
    const NEW_CODE_HINT   = 'use "Send a new code" to get another.';
    const NO_JS_CODE_HINT = ' (Without JavaScript: empty the code box and send the form again.)';
    const INVITE_COOKIE   = 'njilga_invite';

    /**
     * The logged-out visitor's own secret: a random value in a SameSite
     * cookie, which the nonce of every logged-out join form is minted for
     * (visitor_nonce_uid()). The nonce in the form is then a double-submit
     * token — the cookie's value, signed — so no extra field is needed.
     */
    const VISITOR_COOKIE = 'njilga_join_visitor';

    /** The form actions a logged-out visitor posts (their nonces are minted for VISITOR_COOKIE). */
    const VISITOR_ACTIONS = [ 'submit', 'accept_invite', 'clear_invite' ];

    /** @var array<string,mixed> What the POST/return handling decided, for render(). */
    private static $state = [];

    /** @var string|null This request's visitor token, once visitor_token() has settled it. */
    private static $visitor = null;

    /** @var string|null The visitor token a nonce is being verified for (nonce_owner()); null = this request's own. */
    private static $nonceFor = null;

    public static function register(): void {
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'render' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_request' ] );
        add_filter( 'nonce_user_logged_out', [ __CLASS__, 'visitor_nonce_uid' ], 10, 2 );
        foreach ( [ self::AJAX_SEND_CODE => 'ajax_send_code', self::AJAX_CHECK_CODE => 'ajax_check_code' ] as $action => $method ) {
            add_action( 'wp_ajax_nopriv_' . $action, [ __CLASS__, $method ] );
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $method ] );
        }
    }

    // -------------------------------------------------------------------------
    // Availability
    // -------------------------------------------------------------------------

    /**
     * Why joining online can't happen right now for this visitor, or ''.
     * The message is for the visitor; staff get the specific reason.
     */
    public static function unavailable_reason( ?array $category ): string {
        $staff = current_user_can( 'manage_options' );
        $why   = '';
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            $why = 'FluentCRM is not active.';
        } elseif ( empty( MyNJILGA_Dues_Settings::general( 'join_enabled', true ) ) ) {
            $why = 'Online joining is switched off in My NJILGA → Settings.';
        } elseif ( ! $category || empty( $category['applicant_selectable'] ) || (string) $category['tag'] === '' ) {
            $why = 'The shortcode names a category that doesn\'t exist, isn\'t open to applicants, or has no tag (Settings → categories).';
        } elseif ( ! MyNJILGA_Join_Fulfillment::checkout_gateway() ) {
            $why = 'The active payment gateway cannot take checkouts.';
        } else {
            $mode = self::join_mode();
            if ( ! MyNJILGA_Stripe_Connection::is_connected( $mode ) || MyNJILGA_Stripe_Connection::client_for_mode( $mode ) === null ) {
                $why = $mode === MyNJILGA_Stripe_Connection::MODE_LIVE
                    ? 'Stripe Live mode is not connected (Settings → Payments). Staff can rehearse the flow in Test mode by adding ?njilga_test=1 to this page\'s address.'
                    : 'Stripe Test mode is not connected (Settings → Payments).';
            } elseif ( MyNJILGA_Stripe_Connection::checkout_access( $mode ) === false ) {
                $why = 'The connected Stripe key can\'t create Checkout Sessions — give the restricted key "Checkout Sessions: Write" in the Stripe Dashboard (Developers → API keys).';
            }
        }
        if ( $why === '' ) {
            return '';
        }
        return $staff ? 'Online joining is unavailable: ' . $why : 'Online joining is temporarily unavailable. Please contact NJILGA to join.';
    }

    /**
     * The Stripe mode this visitor's join runs in. Everyone joins in Live,
     * whichever mode staff have the admin toggle on — flipping to Test to
     * try an invoice must never take membership sign-ups offline (the
     * member-facing firm status page is pinned Live for the same reason).
     * Staff rehearse the flow in Test by adding ?njilga_test=1.
     */
    public static function join_mode(): string {
        $flag = ! empty( $_GET['njilga_test'] ) || ! empty( $_POST['njilga_test'] ); // phpcs:ignore WordPress.Security.NonceVerification
        return ( $flag && current_user_can( 'manage_options' ) ) ? MyNJILGA_Stripe_Connection::MODE_TEST : MyNJILGA_Stripe_Connection::MODE_LIVE;
    }

    public static function is_test_mode(): bool {
        return self::join_mode() === MyNJILGA_Stripe_Connection::MODE_TEST;
    }

    /**
     * The dues year a join made today pays for. Normally this calendar
     * year (the mid-year rule). But once next year's invoices have gone
     * out — or from the cutover date in Settings — a joiner would pay for
     * a year that's nearly over and be missing from next year's already-
     * frozen invoices; from then on a join pays for NEXT year, and covers
     * the rest of this one too.
     */
    public static function dues_year( bool $livemode ): int {
        // The year and the cutover's month-day must come from one clock.
        // The dues year is the UTC calendar year everywhere else
        // (current_dues_year(), fulfillment's "covers the rest of"), so
        // the month-day is UTC too: a UTC year with a site-local date
        // charged for the year after next on New Year's Eve.
        $year = MyNJILGA_Invoicing::current_dues_year();
        $cut  = trim( (string) MyNJILGA_Dues_Settings::general( 'join_next_year_from', '' ) );
        if ( self::past_cutover( gmdate( 'm-d' ), $cut ) ) {
            return $year + 1;
        }
        $counts = MyNJILGA_Dues_Invoice_Table::counts_by_status( $year + 1, $livemode );
        foreach ( [ 'approved', 'created', 'sent', 'processing', 'paid', 'downgraded' ] as $status ) {
            if ( ! empty( $counts[ $status ] ) ) {
                return $year + 1;
            }
        }
        return $year;
    }

    /**
     * Whether $monthDay ('MM-DD') is on or after the Settings cutover
     * (also 'MM-DD'; anything else means no cutover).
     */
    public static function past_cutover( string $monthDay, string $cutover ): bool {
        return (bool) preg_match( '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $cutover ) && $monthDay >= $cutover;
    }

    /**
     * A shortcode's category="" — a category key (law_student) or, as
     * people will type it, its tag (law-student).
     */
    public static function category_from_att( string $value ): ?array {
        $value = strtolower( trim( $value ) );
        $cat   = MyNJILGA_Dues_Settings::category( sanitize_key( str_replace( '-', '_', $value ) ) ) ?? MyNJILGA_Dues_Settings::category( sanitize_key( $value ) );
        if ( $cat ) {
            return $cat;
        }
        foreach ( MyNJILGA_Dues_Settings::categories() as $c ) {
            if ( (string) $c['tag'] === sanitize_title( $value ) ) {
                return $c;
            }
        }
        return null;
    }

    /**
     * The category (and form) a POST is for, as signed into the form when
     * it was rendered — a crafted POST can't pick a category the page
     * doesn't sell, and nothing depends on re-reading the page's content
     * (the shortcode may live in a reusable block or a builder widget).
     *
     * @return array<string,mixed>|null The category row plus '_form'.
     */
    private static function posted_category(): ?array {
        $key  = sanitize_key( wp_unslash( (string) ( $_POST['category'] ?? '' ) ) );
        $form = sanitize_key( wp_unslash( (string) ( $_POST['form'] ?? '' ) ) );
        $sig  = sanitize_text_field( wp_unslash( (string) ( $_POST['category_sig'] ?? '' ) ) );
        if ( $key === '' || ! hash_equals( self::category_sig( $key, $form ), $sig ) ) {
            return null;
        }
        $cat = MyNJILGA_Dues_Settings::category( $key );
        if ( $cat ) {
            $cat['_form'] = $form;
        }
        return $cat;
    }

    public static function category_sig( string $key, string $form ): string {
        return hash_hmac( 'sha256', 'njilga-join|' . $key . '|' . $form, wp_salt( 'nonce' ) );
    }

    /**
     * The form a category uses: 'student' for a student category,
     * 'professional' (firm + attorney details) for everything else. The
     * shortcode's form="" attribute can override it.
     */
    public static function form_for( array $category, string $override = '' ): string {
        if ( in_array( $override, [ MyNJILGA_Join_Orders_Table::FORM_STUDENT, MyNJILGA_Join_Orders_Table::FORM_PROFESSIONAL ], true ) ) {
            return $override;
        }
        return strpos( (string) $category['key'], 'student' ) !== false ? MyNJILGA_Join_Orders_Table::FORM_STUDENT : MyNJILGA_Join_Orders_Table::FORM_PROFESSIONAL;
    }

    /**
     * Colleagues are offered only on a tier-eligible category — the firm
     * ladder is what makes "members 2–5 at $75" true.
     */
    public static function offers_colleagues( array $category, string $form ): bool {
        return $form === MyNJILGA_Join_Orders_Table::FORM_PROFESSIONAL
            && ! empty( $category['tier_eligible'] )
            && (int) MyNJILGA_Dues_Settings::general( 'join_max_colleagues', 10 ) > 0;
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    /**
     * @param array<string,string>|string $atts
     */
    public static function render( $atts = [] ): string {
        $atts = shortcode_atts( [ 'category' => 'professional', 'form' => '' ], is_array( $atts ) ? $atts : [], self::SHORTCODE );

        // Nothing this prints may be cached for the next visitor — an
        // invitation names its invitee and their firm, and every form
        // carries per-visitor tokens. handle_request() said so in the
        // headers if it recognised the page; this covers a shortcode it
        // couldn't see (a reusable block, a builder widget), for page
        // caches that check at output time, and — where the headers are
        // still open, as under a block theme — in the headers too.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        if ( ! headers_sent() ) {
            nocache_headers();
        }

        // An invitation link works on any join page, whatever its category.
        // handle_request() moved the token out of the URL into a cookie.
        $token = self::invite_token();
        if ( ! empty( self::$state['invite_done'] ) ) {
            return MyNJILGA_Join_View::invite_done();
        }
        if ( $token !== '' ) {
            if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
                return MyNJILGA_Join_View::message( 'This page is temporarily unavailable. Please try again shortly.', 'error' );
            }
            $found = MyNJILGA_Join_Invites::lookup( $token );
            if ( ! $found['invite'] ) {
                return MyNJILGA_Join_View::message( $found['error'], 'error' );
            }
            if ( is_user_logged_in() ) {
                return MyNJILGA_Join_View::message( 'You\'re signed in to an existing account. To use this invitation, sign out first — or, if this is your account, there\'s nothing more to do.', 'info' );
            }
            return MyNJILGA_Join_View::invite_form( $found['invite'], $found['join'], self::$state );
        }

        $category = self::category_from_att( (string) $atts['category'] );
        $why      = self::unavailable_reason( $category );
        if ( $why !== '' ) {
            return MyNJILGA_Join_View::message( $why, 'info' );
        }
        $formAtt = sanitize_key( (string) $atts['form'] );
        $form    = self::form_for( $category, $formAtt );
        $year    = self::dues_year( ! self::is_test_mode() );

        // Result of a Stripe return / a review submission / a cancel.
        if ( ! empty( self::$state['result'] ) ) {
            return MyNJILGA_Join_View::result( self::$state['result'], $category );
        }

        $user = wp_get_current_user();
        if ( $user && $user->ID ) {
            $open = MyNJILGA_Join_Orders_Table::get_open_for_user( (int) $user->ID );
            if ( $open && empty( self::$state['errors'] ) && empty( self::$state['error'] ) ) {
                return MyNJILGA_Join_View::open_join( $open, self::page_url(), self::$state );
            }
            if ( empty( self::$state['errors'] ) && self::contact_is_current( self::user_contact( $user ), $year ) ) {
                return MyNJILGA_Join_View::message( sprintf( 'You\'re already an NJILGA member for %d — thank you! There\'s nothing to pay.', $year ), 'success' );
            }
        }

        return MyNJILGA_Join_View::join_form( [
            'category'       => $category,
            'form'           => $form,
            'colleagues'     => self::offers_colleagues( $category, $form ),
            'max_colleagues' => (int) MyNJILGA_Dues_Settings::general( 'join_max_colleagues', 10 ),
            'ladder'         => MyNJILGA_Join_Pricing::ladder( $category ),
            'year'           => $year,
            'current_year'   => MyNJILGA_Invoicing::current_dues_year(),
            'form_att'       => $formAtt,
            'user'           => ( $user && $user->ID ) ? $user : null,
            'municipalities' => MyNJILGA_Dues_Settings::municipality_options(),
            'test_mode'      => self::is_test_mode(),
            'action_url'     => self::page_url(),
            'errors'         => (array) ( self::$state['errors'] ?? [] ),
            'error'          => (string) ( self::$state['error'] ?? '' ),
            'old'            => (array) ( self::$state['old'] ?? [] ),
            'needs_code'     => ! empty( self::$state['needs_code'] ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Request handling (template_redirect)
    // -------------------------------------------------------------------------

    public static function handle_request(): void {
        // A parked invitation counts too: the page it shows on is reached
        // by a plain URL (the token left the address bar), and may carry
        // the shortcode where has_shortcode() can't see it.
        $ours = isset( $_POST[ self::ACTION_FIELD ] ) || isset( $_GET['njilga_join'] ) || isset( $_GET[ MyNJILGA_Join_Invites::QUERY_ARG ] ) // phpcs:ignore WordPress.Security.NonceVerification
            || self::invite_token() !== '' || self::post_was_too_large();
        if ( ! $ours && ! self::page_has_shortcode() ) {
            return;
        }
        // Every render carries a nonce and per-visitor state — a cached
        // copy served to the next visitor would be wrong for them.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
        // Keep ?session_id= and invite links out of third-party Referer headers.
        header( 'Referrer-Policy: same-origin' );

        // A posted nonce is judged against the cookie this request
        // arrived with, before visitor_token() mints one for a first-time
        // visitor (whose re-shown form then carries a nonce for it).
        $arrived = self::arrived_visitor_token();
        self::visitor_token();

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

        // An invitation token must not sit in the address bar, where
        // analytics and browser history would keep it: park it in a
        // short-lived cookie and reload the page without it.
        if ( $method === 'GET' && isset( $_GET[ MyNJILGA_Join_Invites::QUERY_ARG ] ) ) {
            $token = sanitize_text_field( wp_unslash( (string) $_GET[ MyNJILGA_Join_Invites::QUERY_ARG ] ) );
            if ( preg_match( '/^[a-f0-9]{48}$/', $token ) ) {
                setcookie( self::INVITE_COOKIE, $token, [ 'expires' => time() + 1800, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
                $_COOKIE[ self::INVITE_COOKIE ] = $token;
            }
            wp_safe_redirect( self::page_url() );
            exit;
        }

        // PHP throws away the whole body of a POST over post_max_size — a
        // big scan of a student ID does it — so there is no action to act
        // on. Say what happened rather than showing a blank form.
        if ( $method === 'POST' && self::post_was_too_large() ) {
            self::$state['error'] = sprintf( 'Your form didn\'t come through — the file you attached is too large for this website (the limit is %s). Please fill in the form again with a smaller file, such as a photo of your student ID rather than a scan.', MyNJILGA_Join_Documents::max_label() );
            return;
        }

        if ( $method === 'POST' && isset( $_POST[ self::ACTION_FIELD ] ) ) {
            $action = sanitize_key( wp_unslash( (string) $_POST[ self::ACTION_FIELD ] ) );
            $nonce  = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ) : '';
            $owner  = self::nonce_owner( $nonce, self::NONCE_ACTION . '_' . $action, $arrived );
            if ( $owner === '' ) {
                self::$state['error'] = 'This form expired before it was sent. Please check your details and try again.';
                self::$state['old']   = self::old_from_post();
                return;
            }
            // A nonce minted for nobody in particular — the page went out
            // before this browser had its visitor cookie, or came from a
            // cache — is the same for every visitor, so on its own it
            // can't stop another site posting this form (and logging the
            // victim in to an attacker's account). For those, the browser
            // has to say the form came from here.
            if ( $owner === 'shared' && ! self::same_origin() ) {
                self::$state['error'] = 'This form can only be sent from this website. Please check your details and try again.';
                self::$state['old']   = self::old_from_post();
                return;
            }
            switch ( $action ) {
                case 'submit':
                    self::handle_submit();
                    return;
                case 'resume':
                    self::handle_resume();
                    return;
                case 'restart':
                    self::handle_restart();
                    return;
                case 'accept_invite':
                    self::handle_accept_invite();
                    return;
                case 'clear_invite':
                    self::handle_clear_invite();
                    return;
            }
            return;
        }

        $step = isset( $_GET['njilga_join'] ) ? sanitize_key( wp_unslash( (string) $_GET['njilga_join'] ) ) : '';
        if ( $step === 'return' ) {
            self::handle_return();
        } elseif ( $step === 'cancel' ) {
            self::$state['result'] = [ 'kind' => 'cancelled' ];
        }
    }

    public static function invite_token(): string {
        $token = isset( $_COOKIE[ self::INVITE_COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::INVITE_COOKIE ] ) ) : '';
        return preg_match( '/^[a-f0-9]{48}$/', $token ) ? $token : '';
    }

    private static function clear_invite_cookie(): void {
        setcookie( self::INVITE_COOKIE, '', [ 'expires' => time() - 3600, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
        unset( $_COOKIE[ self::INVITE_COOKIE ] );
    }

    /**
     * The fallback for a logged-out POST whose nonce isn't bound to this
     * visitor: true only when the browser says the form came from this
     * site, or says nothing at all (older browsers send no Origin on a
     * form POST). "null" counts as foreign — it is exactly what another
     * site gets from a sandboxed frame or a cross-site redirect chain.
     * The one honest sender of "null", a page under Referrer-Policy:
     * no-referrer, is normally carrying a visitor-bound nonce instead.
     */
    private static function same_origin(): bool {
        $home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        foreach ( [ 'HTTP_ORIGIN', 'HTTP_REFERER' ] as $h ) {
            if ( empty( $_SERVER[ $h ] ) ) {
                continue;
            }
            $value = sanitize_text_field( wp_unslash( (string) $_SERVER[ $h ] ) );
            if ( $value === 'null' ) {
                return false;
            }
            $host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
            return $host !== '' && $host === $home;
        }
        return true;
    }

    /**
     * This logged-out visitor's secret: a random value kept in a
     * SameSite=Lax cookie, set here while the headers are still open
     * (template_redirect, or a block theme's render). Another site can
     * make a browser post our form, but can't make it send that cookie
     * along, or read a page minted for it to copy its nonce. '' when
     * signed in (the nonce is per-user then), or when no cookie exists
     * and none can be set any more (headers sent) — that page's forms
     * then carry a shared nonce, and same_origin() stands in.
     */
    public static function visitor_token(): string {
        if ( is_user_logged_in() ) {
            return '';
        }
        if ( self::$visitor !== null ) {
            return self::$visitor;
        }
        $token = self::arrived_visitor_token();
        if ( $token === '' ) {
            $token = headers_sent() ? '' : bin2hex( random_bytes( 16 ) );
        }
        if ( $token !== '' && ! headers_sent() ) {
            // Re-sent on every join page, so it outlives any form left open.
            setcookie( self::VISITOR_COOKIE, $token, [ 'expires' => time() + 2 * DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
            $_COOKIE[ self::VISITOR_COOKIE ] = $token;
        }
        self::$visitor = $token;
        return $token;
    }

    /**
     * The visitor cookie as this request brought it, or '' (none, or not
     * one of ours).
     */
    private static function arrived_visitor_token(): string {
        $token = isset( $_COOKIE[ self::VISITOR_COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::VISITOR_COOKIE ] ) : '';
        return preg_match( '/^[a-f0-9]{32}$/', $token ) ? $token : '';
    }

    /**
     * nonce_user_logged_out: a logged-out visitor's join-form nonce is
     * minted for their visitor token, not for "user 0" — which every
     * visitor shares, and anyone can copy off the public page. Other
     * nonces (the code AJAX calls included) are left alone.
     *
     * @param int|string $uid
     * @param mixed      $action
     * @return int|string
     */
    public static function visitor_nonce_uid( $uid, $action ) {
        $prefix = self::NONCE_ACTION . '_';
        if ( ! is_string( $action ) || strpos( $action, $prefix ) !== 0 || ! in_array( substr( $action, strlen( $prefix ) ), self::VISITOR_ACTIONS, true ) ) {
            return $uid;
        }
        $token = self::$nonceFor ?? self::visitor_token();
        return $token !== '' ? 'njilga-visitor:' . $token : $uid;
    }

    /**
     * Whose nonce was posted: 'user' (signed in — theirs alone), 'visitor'
     * (minted for the visitor cookie this browser sent), 'shared' (a
     * logged-out nonce minted for nobody in particular), or '' (not valid).
     */
    private static function nonce_owner( string $nonce, string $action, string $arrived ): string {
        if ( is_user_logged_in() ) {
            return wp_verify_nonce( $nonce, $action ) ? 'user' : '';
        }
        try {
            if ( $arrived !== '' ) {
                self::$nonceFor = $arrived;
                if ( wp_verify_nonce( $nonce, $action ) ) {
                    return 'visitor';
                }
            }
            self::$nonceFor = '';
            return wp_verify_nonce( $nonce, $action ) ? 'shared' : '';
        } finally {
            self::$nonceFor = null;
        }
    }

    /**
     * Whether this request is a POST whose body PHP discarded for being
     * over post_max_size ($_POST and $_FILES both come back empty).
     */
    private static function post_was_too_large(): bool {
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
        return self::body_exceeded_limit(
            $method,
            empty( $_POST ) && empty( $_FILES ), // phpcs:ignore WordPress.Security.NonceVerification
            isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0,
            (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) )
        );
    }

    /**
     * The pure test behind post_was_too_large(). A limit of 0 means PHP
     * has none.
     */
    public static function body_exceeded_limit( string $method, bool $bodyEmpty, int $contentLength, int $postMaxBytes ): bool {
        return $method === 'POST' && $bodyEmpty && $postMaxBytes > 0 && $contentLength > $postMaxBytes;
    }

    /**
     * The visitor's IP, for the rate limits. REMOTE_ADDR is the only
     * address a client can't forge, so it is the default. Behind a reverse
     * proxy or load balancer that doesn't restore the client's address,
     * though, every visitor shares the proxy's and one busy hour locks
     * everybody out — such a site hooks `my_njilga_client_ip` to return
     * the address its own proxy passes on (e.g. from X-Forwarded-For).
     */
    public static function client_ip(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
        return trim( (string) apply_filters( 'my_njilga_client_ip', $ip ) );
    }

    private static function page_has_shortcode(): bool {
        if ( ! is_singular() ) {
            return false;
        }
        $post = get_post();
        return $post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
    }

    /**
     * The join page's own URL, free of this flow's query args.
     */
    public static function page_url(): string {
        $id  = (int) get_queried_object_id();
        $url = $id > 0 ? (string) get_permalink( $id ) : '';
        if ( $url === '' ) {
            $url = home_url( '/' );
        }
        $url = remove_query_arg( [ 'njilga_join', 'session_id', MyNJILGA_Join_Invites::QUERY_ARG, 'njilga_test' ], $url );
        return self::is_test_mode() ? add_query_arg( 'njilga_test', '1', $url ) : $url;
    }

    // -------------------------------------------------------------------------
    // Submit
    // -------------------------------------------------------------------------

    private static function handle_submit(): void {
        // Whoever posts the join form isn't using an invitation — and one
        // planted by someone else's link mustn't take over the page they
        // come back to.
        if ( self::invite_token() !== '' ) {
            self::clear_invite_cookie();
        }

        $old = self::old_from_post();
        self::$state['old'] = $old;

        if ( ! empty( $_POST['njilga_website'] ) ) {
            self::$state['error'] = 'Something went wrong. Please try again.';
            return; // Honeypot.
        }

        $category = self::posted_category();
        if ( ! $category ) {
            self::$state['error'] = 'This form is out of date — please reload the page and try again.';
            return;
        }
        $why = self::unavailable_reason( $category );
        if ( $why !== '' ) {
            self::$state['error'] = $why;
            return;
        }
        $form      = self::form_for( $category, (string) ( $category['_form'] ?? '' ) );
        $isStudent = $form === MyNJILGA_Join_Orders_Table::FORM_STUDENT;
        $year      = self::dues_year( ! self::is_test_mode() );

        $ip = self::client_ip();
        if ( ! self::rate_ok( 'submit', $ip, self::RATE_SUBMITS, 600 ) ) {
            self::$state['error'] = 'Too many attempts from your connection — please wait a few minutes and try again.';
            return;
        }

        $user     = wp_get_current_user();
        $loggedIn = $user && $user->ID;
        $errors   = self::validate( $old, $form, $category, $loggedIn ? $user : null );

        // Colleagues (the upsell).
        $colleagues = [];
        if ( self::offers_colleagues( $category, $form ) && ( $old['add_colleagues'] ?? '' ) === 'yes' ) {
            $colleagues = self::validate_colleagues( $old, $loggedIn ? (string) $user->user_email : (string) $old['email'], $errors );
        }

        // The student ID — validated with everything else, stored only
        // once the rest has passed.
        $needsDoc = $isStudent && ( $old['student_status'] ?? '' ) === 'enrolled';
        if ( $needsDoc && ( ! isset( $_FILES['student_document'] ) || (int) ( $_FILES['student_document']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) ) {
            $errors['student_document'] = 'Please upload your student ID or a transcript.';
        }

        if ( $errors ) {
            self::$state['errors'] = $errors;
            if ( ! $loggedIn ) {
                self::keep_code( $old );
            }
            return;
        }

        // Passwords and files never survive a round trip back to this
        // page — say so, or the next submit fails on exactly that.
        $again = $needsDoc ? ' (Please re-enter your password, and attach your student ID or transcript again.)' : ' (Please re-enter your password too.)';

        // Proof the applicant owns the email, before an account exists
        // for it: an account on someone else's address would later
        // inherit that person's membership, role and firm records. Checked
        // (not yet spent) here, so fixing a colleague below doesn't need a
        // new code; spent once everything has passed.
        $code = '';
        if ( ! $loggedIn ) {
            $code = (string) preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['email_code'] ?? '' ) );
            if ( $code === '' ) {
                // The same words whether or not the address already has an
                // account (that owner is emailed how to log in instead), so
                // this can't be used to find out who has one.
                $sent = self::send_code( (string) $old['email'], $ip, self::page_url() );
                self::$state['needs_code'] = true;
                self::$state['errors']     = [ 'email_code' => $sent['ok'] ? self::code_sent_message( (string) $old['email'] ) . $again : $sent['error'] ];
                return;
            }
            $check = self::check_code( (string) $old['email'], $code, false );
            if ( ! $check['ok'] ) {
                self::$state['needs_code'] = true;
                self::$state['errors']     = [ 'email_code' => $check['error'] . ( ! empty( $check['spent'] ) ? self::NO_JS_CODE_HINT : '' ) . $again ];
                return;
            }
        }

        // Who is already covered — only asked once the visitor has proved
        // their own address (or is signed in). The payer's own answer can
        // be specific; a colleague's never says which of their records
        // it came from, and asking again and again is limited hard, so the
        // form can't be used to look up other people's membership or
        // whether their firm owes dues.
        $payerEmail = $loggedIn ? (string) $user->user_email : (string) ( $old['email'] ?? '' );
        $conflicts  = self::membership_conflicts( $payerEmail, $colleagues, $year, ! self::is_test_mode(), $loggedIn ? (int) $user->ID : 0 );
        if ( $conflicts ) {
            if ( ! $loggedIn ) {
                self::$state['needs_code'] = true; // Keep the (still valid) code box on screen.
            }
            $aboutColleagues = preg_grep( '/^colleague_/', array_keys( $conflicts ) );
            if ( $aboutColleagues && ( ! self::rate_ok( 'conflict_ip', $ip, self::RATE_CONFLICTS, 3600 ) || ! self::rate_ok( 'conflict_email', strtolower( $payerEmail ), self::RATE_CONFLICTS, 3600 ) ) ) {
                self::$state['error'] = 'Too many attempts — please wait an hour and try again, or contact NJILGA to add your colleagues.';
                return;
            }
            // Removing a colleague and sending again needs the password
            // again — said once, on the first colleague's message (every
            // message is also listed at the top of the form).
            if ( ! $loggedIn && $aboutColleagues ) {
                $first               = (string) reset( $aboutColleagues );
                $conflicts[ $first ] .= $again;
            }
            self::$state['errors'] = $conflicts;
            return;
        }

        if ( ! $loggedIn ) {
            // Only reachable if an account appeared for this address after
            // its code went out — send_code() mails an existing account's
            // owner a way to log in, never a code.
            if ( email_exists( (string) $old['email'] ) ) {
                self::$state['errors'] = [ 'email' => 'An account with this email already exists — please log in first, then come back to this page.' ];
                return;
            }
            // Keyed on the verified address as well as the connection: an
            // office, or a proxy that hides visitors' addresses, can put a
            // whole membership drive behind one IP.
            if ( ! self::rate_ok( 'account_ip', $ip, self::RATE_ACCOUNTS_IP, 3600 ) || ! self::rate_ok( 'account_email', (string) $old['email'], self::RATE_ACCOUNTS, 3600 ) ) {
                self::$state['needs_code'] = true;
                self::$state['error']      = 'Too many new accounts from your connection — please wait and try again, or contact NJILGA.';
                return;
            }
        }

        // Stored before the code is spent, so a refused file (the wrong
        // type, too large) doesn't also cost the applicant their code.
        $doc = [ 'path' => '', 'name' => '', 'mime' => '' ];
        if ( $needsDoc ) {
            $stored = MyNJILGA_Join_Documents::store( (array) $_FILES['student_document'] );
            if ( ! $stored['ok'] ) {
                self::$state['errors'] = [ 'student_document' => (string) $stored['error'] . ( $loggedIn ? '' : $again ) ];
                if ( ! $loggedIn ) {
                    self::$state['needs_code'] = true;
                }
                return;
            }
            $doc = [ 'path' => $stored['path'], 'name' => $stored['name'], 'mime' => $stored['mime'] ];
        }

        if ( ! $loggedIn && ! self::check_code( (string) $old['email'], $code, true )['ok'] ) {
            MyNJILGA_Join_Documents::delete( $doc['path'] );
            self::$state['needs_code'] = true;
            self::$state['errors']     = [ 'email_code' => 'That code has expired — ' . self::NEW_CODE_HINT . self::NO_JS_CODE_HINT . $again ];
            return;
        }

        // The account. Created before the payment so the applicant chooses
        // their username now and can come back to finish paying; it carries
        // no membership and no role until the payment is confirmed.
        if ( ! $loggedIn ) {
            $userId = wp_insert_user( [
                'user_login'   => (string) $old['username'],
                'user_pass'    => (string) wp_unslash( $_POST['password'] ?? '' ),
                'user_email'   => (string) $old['email'],
                'first_name'   => (string) $old['first_name'],
                'last_name'    => (string) $old['last_name'],
                'display_name' => trim( $old['first_name'] . ' ' . $old['last_name'] ),
                // Never the site's default role: that may be a member role,
                // and this account has paid for nothing yet.
                'role'         => MyNJILGA_Join_Invites::new_account_role(),
            ] );
            if ( is_wp_error( $userId ) ) {
                MyNJILGA_Join_Documents::delete( $doc['path'] );
                self::$state['errors'] = [ 'username' => $userId->get_error_message() ];
                return;
            }
            update_user_meta( (int) $userId, 'njilga_join_created', current_time( 'mysql' ) );
            self::log_in( (int) $userId );
            $user = get_user_by( 'id', (int) $userId );
        }
        $userId = (int) $user->ID;

        // One open join per person: an earlier unpaid one is retired first
        // — and if it turns out to have been paid in the meantime, that
        // one is finished instead of charging again.
        $open = MyNJILGA_Join_Orders_Table::get_open_for_user( $userId );
        if ( $open ) {
            $retired = self::retire( $open );
            if ( $retired !== '' ) {
                MyNJILGA_Join_Documents::delete( $doc['path'] );
                self::$state['result'] = [ 'kind' => 'join', 'join' => MyNJILGA_Join_Orders_Table::get( (int) $open->id ), 'message' => $retired ];
                return;
            }
        }

        $applicant = self::applicant_answers( $old, $form, $user );
        $people    = array_merge( [ [ 'first_name' => $applicant['first_name'], 'last_name' => $applicant['last_name'], 'email' => $applicant['email'] ] ], $colleagues );
        $quote     = MyNJILGA_Join_Pricing::quote( (string) $category['key'], $people, $year );
        if ( ! $quote ) {
            MyNJILGA_Join_Documents::delete( $doc['path'] );
            self::$state['error'] = 'That membership category is not available.';
            return;
        }
        $total = (int) $quote['totals']['total_cents'];

        $companyId = 0; $newCompany = '';
        if ( ! $isStudent ) {
            [ $companyId, $newCompany ] = self::resolve_firm_choice( (int) ( $old['company_id'] ?? 0 ), (string) ( $old['firm_name'] ?? '' ) );
        }

        $joinId = MyNJILGA_Join_Orders_Table::insert( [
            'status'           => $total > 0 ? MyNJILGA_Join_Orders_Table::STATUS_PENDING : MyNJILGA_Join_Orders_Table::STATUS_REVIEW,
            'category_key'     => (string) $category['key'],
            'form'             => $form,
            'livemode'         => self::is_test_mode() ? 0 : 1,
            'dues_year'        => $year,
            'wp_user_id'       => $userId,
            'email'            => $applicant['email'],
            'first_name'       => $applicant['first_name'],
            'last_name'        => $applicant['last_name'],
            'company_id'       => $companyId,
            'new_company_name' => $newCompany,
            'applicant'        => $applicant,
            'colleagues'       => $colleagues,
            'priced'           => [ 'members' => $quote['members'], 'totals' => $quote['totals'] ],
            'total_cents'      => $total,
            'currency'         => strtolower( (string) MyNJILGA_Stripe_Connection::setting( 'currency', 'usd' ) ),
            'document_path'    => $doc['path'],
            'document_name'    => $doc['name'],
            'document_mime'    => $doc['mime'],
            'source_url'       => self::page_url(),
            'ip'               => $ip,
        ] );
        if ( ! $joinId ) {
            MyNJILGA_Join_Documents::delete( $doc['path'] );
            self::$state['error'] = 'Something went wrong saving your details. Please try again or contact NJILGA.';
            return;
        }

        if ( $total <= 0 ) {
            self::notify_review( MyNJILGA_Join_Orders_Table::get( $joinId ) );
            self::$state['result'] = [ 'kind' => 'review' ];
            return;
        }

        $started = self::start_checkout( MyNJILGA_Join_Orders_Table::get( $joinId ), $quote['lines'] );
        if ( $started !== '' ) {
            self::$state['error'] = $started;
            return;
        }
        exit; // start_checkout() redirected to Stripe.
    }

    /**
     * Create a Checkout Session for the join and redirect to it.
     * Returns an error message, or never returns.
     *
     * @param array<int,array<string,mixed>> $lines
     */
    private static function start_checkout( object $join, array $lines ): string {
        $gateway = MyNJILGA_Join_Fulfillment::checkout_gateway();
        if ( ! $gateway ) {
            return 'Online payment is unavailable right now. Please contact NJILGA.';
        }
        $attempt  = (int) $join->attempt + 1;
        $mode     = ! empty( $join->livemode ) ? MyNJILGA_Stripe_Connection::MODE_LIVE : MyNJILGA_Stripe_Connection::MODE_TEST;
        $category = MyNJILGA_Dues_Settings::category( (string) $join->category_key );
        $firm     = (string) $join->new_company_name;
        if ( $firm === '' && (int) $join->company_id > 0 && MyNJILGA_Members_Data::companies_module_active() ) {
            $c    = \FluentCrm\App\Models\Company::find( (int) $join->company_id );
            $firm = $c ? (string) $c->name : '';
        }
        $base = (string) $join->source_url;

        $r = $gateway->create_checkout( $lines, [
            'join_id'        => (int) $join->id,
            'attempt'        => $attempt,
            'mode'           => $mode,
            'dues_year'      => (int) $join->dues_year,
            'customer_email' => (string) $join->email,
            // Stripe fills {CHECKOUT_SESSION_ID} in itself; add_query_arg
            // leaves the braces alone.
            'success_url'    => add_query_arg( [ 'njilga_join' => 'return', 'session_id' => '{CHECKOUT_SESSION_ID}' ], $base ),
            'cancel_url'     => add_query_arg( [ 'njilga_join' => 'cancel' ], $base ),
            'description'    => sprintf(
                '%d NJILGA %s — %s %s%s',
                (int) $join->dues_year,
                $category ? $category['label'] : 'Membership',
                $join->first_name,
                $join->last_name,
                (int) $join->dues_year > MyNJILGA_Invoicing::current_dues_year() ? sprintf( ' (covers the rest of %d too)', MyNJILGA_Invoicing::current_dues_year() ) : ''
            ),
            'company_name'   => $firm,
            'allow_ach'      => ! empty( MyNJILGA_Dues_Settings::general( 'join_allow_ach', true ) ),
            'create_invoice' => ! empty( MyNJILGA_Dues_Settings::general( 'join_stripe_invoice', true ) ),
        ] );

        if ( empty( $r['ok'] ) ) {
            MyNJILGA_Join_Orders_Table::update( (int) $join->id, [ 'attempt' => $attempt, 'last_error' => (string) ( $r['error'] ?? '' ) ] );
            return 'We couldn\'t open the secure payment page just now. Please try again in a moment, or contact NJILGA.'
                . ( current_user_can( 'manage_options' ) ? ' (Stripe said: ' . (string) ( $r['error'] ?? '' ) . ')' : '' );
        }

        // Only onto a join that is still waiting for payment. If "Start
        // over", the sweep or staff closed it while Stripe was answering,
        // writing it back to pending would revive it — and a closed join
        // whose new checkout gets paid takes the money and applies
        // nothing. Close that checkout instead.
        $open = MyNJILGA_Join_Orders_Table::transition( (int) $join->id, [ MyNJILGA_Join_Orders_Table::STATUS_PENDING ], MyNJILGA_Join_Orders_Table::STATUS_PENDING, [
            'attempt'            => $attempt,
            'stripe_session_id'  => (string) $r['session_id'],
            'stripe_session_url' => (string) $r['url'],
            'last_error'         => null,
        ] );
        if ( ! $open ) {
            $gateway->expire_checkout( (string) $r['session_id'], $mode );
            return 'This application was closed before the payment page opened, so nothing was charged. Please reload the page to see where it stands.';
        }

        // wp_redirect, not wp_safe_redirect: checkout.stripe.com is off-site
        // by design. The URL came from Stripe's API response, never from
        // the request.
        wp_redirect( (string) $r['url'], 303 ); // phpcs:ignore WordPress.Security.SafeRedirect
        exit;
    }

    /**
     * Retire an earlier open join before a new one starts. '' = retired;
     * otherwise the reason the new one must not start (it was paid, or is
     * clearing, or waiting on staff).
     */
    private static function retire( object $open ): string {
        $T = 'MyNJILGA_Join_Orders_Table';
        if ( in_array( $open->status, [ $T::STATUS_PROCESSING, $T::STATUS_PAID, $T::STATUS_FULFILLING ], true ) ) {
            return 'Your earlier membership payment is already being processed, so there\'s nothing more to pay.';
        }
        if ( $open->status === $T::STATUS_REVIEW ) {
            MyNJILGA_Join_Orders_Table::transition( (int) $open->id, [ $T::STATUS_REVIEW ], $T::STATUS_ABANDONED );
            MyNJILGA_Join_Documents::delete( (string) $open->document_path );
            return '';
        }
        // Pending: make sure its checkout can never be paid, THEN retire.
        if ( (string) $open->stripe_session_id !== '' ) {
            $gateway = MyNJILGA_Join_Fulfillment::checkout_gateway();
            $mode    = ! empty( $open->livemode ) ? MyNJILGA_Stripe_Connection::MODE_LIVE : MyNJILGA_Stripe_Connection::MODE_TEST;
            $r       = $gateway ? $gateway->expire_checkout( (string) $open->stripe_session_id, $mode ) : [ 'ok' => false, 'status' => '' ];
            if ( ! $r['ok'] && ( $r['status'] ?? '' ) !== 'expired' ) {
                // Not expirable: completed (maybe paid), or Stripe
                // unreachable. Either way, don't risk a second charge.
                $sync = MyNJILGA_Join_Fulfillment::sync( (int) $open->id, 'restart check' );
                if ( in_array( $sync['status'], [ $T::STATUS_FULFILLED, $T::STATUS_PROCESSING, $T::STATUS_PAID ], true ) ) {
                    return 'Your earlier payment went through, so there\'s nothing more to pay.';
                }
                return 'We couldn\'t close your earlier checkout just now. Please try again in a moment.';
            }
        }
        MyNJILGA_Join_Orders_Table::transition( (int) $open->id, [ $T::STATUS_PENDING ], $T::STATUS_ABANDONED );
        MyNJILGA_Join_Documents::delete( (string) $open->document_path );
        return '';
    }

    private static function handle_resume(): void {
        $user = wp_get_current_user();
        $join = ( $user && $user->ID ) ? MyNJILGA_Join_Orders_Table::get_open_for_user( (int) $user->ID ) : null;
        if ( ! $join || $join->status !== MyNJILGA_Join_Orders_Table::STATUS_PENDING ) {
            return;
        }
        $gateway = MyNJILGA_Join_Fulfillment::checkout_gateway();
        $mode    = ! empty( $join->livemode ) ? MyNJILGA_Stripe_Connection::MODE_LIVE : MyNJILGA_Stripe_Connection::MODE_TEST;
        $session = ( $gateway && (string) $join->stripe_session_id !== '' ) ? $gateway->fetch_checkout( (string) $join->stripe_session_id, $mode ) : null;

        if ( $session && $session['status'] === 'complete' ) {
            $r = MyNJILGA_Join_Fulfillment::sync( (int) $join->id, 'resume' );
            self::$state['result'] = [ 'kind' => 'join', 'join' => MyNJILGA_Join_Orders_Table::get( (int) $join->id ), 'message' => $r['message'] ];
            return;
        }
        if ( ! $session && (string) $join->stripe_session_id !== '' ) {
            self::$state['error'] = 'We couldn\'t reach the payment page just now. Please try again in a moment.';
            return;
        }

        // Not paid yet — its checkout still open, expired, or never
        // opened. The year, price and people were settled when the
        // application was made, which may be weeks ago: if joins now pay
        // for a different dues year (an application left over New Year),
        // or someone on it has become covered since, paying it as it
        // stands would buy the wrong thing. Close it (retire() makes sure
        // an open checkout can't be paid) and start the applicant on a
        // fresh form — nothing has been charged.
        $livemode = ! empty( $join->livemode );
        $nowYear  = self::dues_year( $livemode );
        $changed  = (int) $join->dues_year !== $nowYear
            || self::membership_conflicts( (string) $join->email, MyNJILGA_Join_Orders_Table::json( $join, 'colleagues' ), (int) $join->dues_year, $livemode, (int) $user->ID );
        if ( $changed ) {
            $why = self::retire( $join );
            if ( $why !== '' ) {
                self::$state['result'] = [ 'kind' => 'join', 'join' => MyNJILGA_Join_Orders_Table::get( (int) $join->id ), 'message' => $why ];
                return;
            }
            self::$state['error'] = (int) $join->dues_year !== $nowYear
                ? sprintf( 'Your application was for %1$d membership, but joining now pays for %2$d — so it has been closed, and nothing was charged. Please fill in the form again.', (int) $join->dues_year, $nowYear )
                : 'Something has changed since you started your application (someone on it may already be covered now), so it has been closed, and nothing was charged. Please fill in the form again.';
            return;
        }

        // Still as agreed: back to its open checkout, or a fresh one at
        // that price for the same people.
        if ( $session && $session['status'] === 'open' && $session['url'] !== '' ) {
            wp_redirect( (string) $session['url'], 303 ); // phpcs:ignore WordPress.Security.SafeRedirect
            exit;
        }
        $priced = MyNJILGA_Join_Orders_Table::json( $join, 'priced' );
        $lines  = MyNJILGA_Dues_Roster::line_items( (array) ( $priced['members'] ?? [] ), (int) $join->dues_year, MyNJILGA_Dues_Snapshot::KIND_JOIN );
        $err    = self::start_checkout( $join, $lines );
        if ( $err !== '' ) {
            self::$state['error'] = $err;
        }
    }

    private static function handle_restart(): void {
        $user = wp_get_current_user();
        $join = ( $user && $user->ID ) ? MyNJILGA_Join_Orders_Table::get_open_for_user( (int) $user->ID ) : null;
        if ( ! $join ) {
            return;
        }
        $why = self::retire( $join );
        if ( $why !== '' ) {
            self::$state['result'] = [ 'kind' => 'join', 'join' => MyNJILGA_Join_Orders_Table::get( (int) $join->id ), 'message' => $why ];
        }
    }

    /**
     * Back from Stripe. Fulfill right now if the payment is in (the
     * webhook may not have landed yet), then show where things stand.
     * Only the join's own applicant sees its details.
     */
    private static function handle_return(): void {
        $sessionId = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['session_id'] ) ) : '';
        $join      = MyNJILGA_Join_Orders_Table::get_by_session( $sessionId );
        $user      = wp_get_current_user();
        if ( ! $join || ! $user || (int) $user->ID !== (int) $join->wp_user_id ) {
            self::$state['result'] = [ 'kind' => 'thanks' ];
            return;
        }
        MyNJILGA_Join_Fulfillment::sync( (int) $join->id, 'return from checkout' );
        self::$state['result'] = [ 'kind' => 'join', 'join' => MyNJILGA_Join_Orders_Table::get( (int) $join->id ) ];
    }

    private static function notify_review( object $join ): void {
        $to = (string) MyNJILGA_Dues_Settings::general( 'join_notify_email', '' );
        if ( trim( $to ) === '' ) {
            $to = (string) MyNJILGA_Dues_Settings::general( 'application_notify_email', '' );
        }
        $recipients = array_filter( array_map( 'sanitize_email', (array) preg_split( '/[\s,;]+/', $to ) ) );
        if ( ! $recipients ) {
            $recipients = [ (string) get_option( 'admin_email' ) ];
        }
        $category = MyNJILGA_Dues_Settings::category( (string) $join->category_key );
        MyNJILGA_Join_Fulfillment::join_mail(
            $join,
            $recipients,
            sprintf( 'Online join needs review: %s %s', $join->first_name, $join->last_name ),
            sprintf(
                "%s %s <%s> asked to join online as %s, which comes to no charge — so it waits for a staff decision rather than becoming a membership automatically.\n\nReview it here: %s",
                $join->first_name,
                $join->last_name,
                $join->email,
                $category ? $category['label'] : $join->category_key,
                add_query_arg( 'tab', 'joins', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) )
            ),
            true
        );
    }

    // -------------------------------------------------------------------------
    // Invite acceptance
    // -------------------------------------------------------------------------

    private static function handle_accept_invite(): void {
        $token = self::invite_token();
        $old   = self::old_from_post();
        self::$state['old'] = $old;

        $found = MyNJILGA_Join_Invites::lookup( $token );
        if ( ! $found['invite'] ) {
            self::$state['error'] = $found['error'];
            return;
        }
        $ip = self::client_ip();
        if ( ! self::rate_ok( 'submit', $ip, self::RATE_SUBMITS, 600 ) ) {
            self::$state['error'] = 'Too many attempts from your connection — please wait a few minutes and try again.';
            return;
        }

        $errors = [];
        self::validate_account( $old, $errors, (string) $found['invite']->email, false );
        self::require_fields( $old, [ 'first_name' => 'First name', 'last_name' => 'Last name', 'phone' => 'Phone', 'address_1' => 'Address', 'city' => 'City' ], $errors );
        self::validate_phone( $old, 'phone', $errors );
        self::validate_address( $old, $errors );
        foreach ( [ 'first_name' => 'First name', 'last_name' => 'Last name' ] as $k => $label ) {
            $bad = self::plain_text_problem( (string) ( $old[ $k ] ?? '' ), 80 );
            if ( $bad !== '' && ! isset( $errors[ $k ] ) ) {
                $errors[ $k ] = $label . ' ' . $bad;
            }
        }
        // Colleagues are only ever added on the firm (Professional) ladder,
        // so they give the same professional details the payer did.
        self::validate_professional_details( $old, $errors, true );
        if ( $errors ) {
            self::$state['errors'] = $errors;
            return;
        }

        $outside = ! empty( $old['outside_us'] );
        $in = [
            'username'           => (string) $old['username'],
            'password'           => (string) wp_unslash( $_POST['password'] ?? '' ),
            'first_name'         => (string) $old['first_name'],
            'last_name'          => (string) $old['last_name'],
            'phone'              => (string) ( $old['phone'] ?? '' ),
            'attorney_id'        => (string) ( $old['attorney_id'] ?? '' ),
            'bar_admission_date' => (string) ( $old['bar_admission_date'] ?? '' ),
            'municipality'       => (string) ( $old['municipality'] ?? '' ),
            'address_line_1'     => (string) ( $old['address_1'] ?? '' ),
            'address_line_2'     => (string) ( $old['address_2'] ?? '' ),
            'city'               => (string) ( $old['city'] ?? '' ),
            'state'              => $outside ? (string) ( $old['region'] ?? '' ) : (string) ( $old['state'] ?? '' ),
            'postal_code'        => (string) ( $old['postal_code'] ?? '' ),
            'country'            => $outside ? (string) ( $old['country'] ?? '' ) : 'US',
        ];
        $r = MyNJILGA_Join_Invites::accept( $found['invite'], $token, $in );
        if ( ! $r['ok'] ) {
            self::$state['error'] = $r['error'];
            return;
        }
        self::log_in( (int) $r['user_id'] );
        self::clear_invite_cookie();
        self::$state['invite_done'] = true;
    }

    /**
     * "This isn't me": drop the invitation parked in this browser — a
     * link meant for someone else, or one planted by another person's
     * link — and show the page's ordinary form again.
     */
    private static function handle_clear_invite(): void {
        self::clear_invite_cookie();
        wp_safe_redirect( self::page_url(), 303 );
        exit;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Every posted answer, sanitized — the form's "old" values on an error
     * and the source of the join's applicant record. Passwords are never
     * kept here.
     *
     * @return array<string,mixed>
     */
    private static function old_from_post(): array {
        $text = static function ( string $k ): string {
            return isset( $_POST[ $k ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $k ] ) ) ) : '';
        };
        $old = [];
        foreach ( [ 'category', 'username', 'first_name', 'last_name', 'phone', 'mailing_phone', 'firm_name', 'municipality', 'attorney_id', 'bar_admission_date', 'address_1', 'address_2', 'city', 'state', 'region', 'postal_code', 'country', 'student_status', 'school', 'add_colleagues' ] as $k ) {
            $old[ $k ] = $text( $k );
        }
        $old['email']         = strtolower( sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) ) );
        $old['email_confirm'] = strtolower( sanitize_email( wp_unslash( (string) ( $_POST['email_confirm'] ?? '' ) ) ) );
        $old['company_id']    = (int) ( $_POST['company_id'] ?? 0 );
        $old['email_code']    = substr( (string) preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['email_code'] ?? '' ) ), 0, 6 );
        $old['outside_us']    = ! empty( $_POST['outside_us'] ) ? '1' : '';

        $old['colleagues'] = [];
        $firsts = isset( $_POST['colleague_first'] ) ? (array) wp_unslash( $_POST['colleague_first'] ) : [];
        $lasts  = isset( $_POST['colleague_last'] ) ? (array) wp_unslash( $_POST['colleague_last'] ) : [];
        $emails = isset( $_POST['colleague_email'] ) ? (array) wp_unslash( $_POST['colleague_email'] ) : [];
        $n      = min( 100, max( count( $firsts ), count( $lasts ), count( $emails ) ) );
        for ( $i = 0; $i < $n; $i++ ) {
            $row = [
                'first_name' => trim( sanitize_text_field( (string) ( $firsts[ $i ] ?? '' ) ) ),
                'last_name'  => trim( sanitize_text_field( (string) ( $lasts[ $i ] ?? '' ) ) ),
                'email'      => strtolower( sanitize_email( (string) ( $emails[ $i ] ?? '' ) ) ),
                'raw_email'  => trim( sanitize_text_field( (string) ( $emails[ $i ] ?? '' ) ) ),
            ];
            if ( $row['first_name'] !== '' || $row['last_name'] !== '' || $row['raw_email'] !== '' ) {
                $old['colleagues'][] = $row;
            }
        }
        return $old;
    }

    /**
     * @param array<string,mixed> $old
     * @return array<string,string> field => message
     */
    private static function validate( array $old, string $form, array $category, ?WP_User $user ): array {
        $errors = [];
        if ( $user ) {
            // Signed in: the account (and its email) is who is joining.
        } else {
            self::validate_account( $old, $errors, '', true );
        }
        self::require_fields( $old, [ 'first_name' => 'First name', 'last_name' => 'Last name', 'phone' => 'Phone', 'address_1' => 'Address', 'city' => 'City' ], $errors );
        self::validate_phone( $old, 'phone', $errors );
        foreach ( [ 'first_name' => 'First name', 'last_name' => 'Last name', 'firm_name' => 'Firm name', 'school' => 'School' ] as $k => $label ) {
            $bad = self::plain_text_problem( (string) ( $old[ $k ] ?? '' ), $k === 'firm_name' || $k === 'school' ? 150 : 80 );
            if ( $bad !== '' && ! isset( $errors[ $k ] ) ) {
                $errors[ $k ] = $label . ' ' . $bad;
            }
        }

        self::validate_address( $old, $errors );

        if ( $form === MyNJILGA_Join_Orders_Table::FORM_STUDENT ) {
            if ( ! in_array( $old['student_status'] ?? '', [ 'enrolled', 'undergrad' ], true ) ) {
                $errors['student_status'] = 'Please tell us whether you are currently enrolled in law school.';
            }
            self::require_fields( $old, [ 'school' => 'School' ], $errors );
        } else {
            if ( (int) ( $old['company_id'] ?? 0 ) <= 0 && (string) ( $old['firm_name'] ?? '' ) === '' ) {
                $errors['firm_name'] = 'Please choose your firm, or add it.';
            } elseif ( (int) ( $old['company_id'] ?? 0 ) > 0 && ( ! MyNJILGA_Members_Data::companies_module_active() || ! \FluentCrm\App\Models\Company::find( (int) $old['company_id'] ) ) ) {
                $errors['firm_name'] = 'That firm could not be found — please choose it again.';
            } elseif ( mb_strlen( (string) ( $old['firm_name'] ?? '' ) ) > 190 ) {
                $errors['firm_name'] = 'Please shorten the firm name.';
            }
            self::validate_professional_details( $old, $errors, true );
            self::require_fields( $old, [ 'mailing_phone' => 'Phone' ], $errors );
            self::validate_phone( $old, 'mailing_phone', $errors );
        }
        return $errors;
    }

    /**
     * @param array<string,mixed>  $old
     * @param array<string,string> $errors
     */
    private static function validate_address( array $old, array &$errors ): void {
        if ( ! empty( $old['outside_us'] ) ) {
            self::require_fields( $old, [ 'country' => 'Country' ], $errors );
            return;
        }
        if ( ! isset( MyNJILGA_Join_View::us_states()[ (string) ( $old['state'] ?? '' ) ] ) ) {
            $errors['state'] = 'Please choose a state.';
        }
        if ( ! preg_match( '/^\d{5}(-\d{4})?$/', (string) ( $old['postal_code'] ?? '' ) ) ) {
            $errors['postal_code'] = 'Please enter a 5-digit ZIP code.';
        }
    }

    /**
     * @param array<string,mixed>  $old
     * @param array<string,string> $errors
     */
    private static function validate_account( array $old, array &$errors, string $fixedEmail, bool $withEmail ): void {
        $raw = (string) ( $old['username'] ?? '' );
        if ( $raw === '' ) {
            $errors['username'] = 'Please choose a username.';
        } elseif ( sanitize_user( $raw, true ) !== $raw || ! validate_username( $raw ) || mb_strlen( $raw ) < 3 || mb_strlen( $raw ) > 60 ) {
            $errors['username'] = 'Usernames can use letters, numbers, spaces and . - _ @ (3–60 characters).';
        } elseif ( username_exists( $raw ) || in_array( strtolower( $raw ), array_map( 'strtolower', (array) apply_filters( 'illegal_user_logins', [] ) ), true ) ) {
            $errors['username'] = 'That username is taken — please choose another.';
        }

        $password = (string) wp_unslash( $_POST['password'] ?? '' );
        if ( strlen( $password ) < 8 ) {
            $errors['password'] = 'Please use at least 8 characters.';
        } elseif ( $password !== (string) wp_unslash( $_POST['password_confirm'] ?? '' ) ) {
            $errors['password_confirm'] = 'The passwords don\'t match.';
        }

        if ( $withEmail ) {
            // Whether the address already has an account is NOT answered
            // here: this runs before the address is proved, so it would
            // tell anyone who has an account on the site. send_code()
            // emails that owner how to log in instead of a code.
            $email = (string) ( $old['email'] ?? '' );
            if ( ! is_email( $email ) ) {
                $errors['email'] = 'Please enter a valid email address.';
            } elseif ( $email !== (string) ( $old['email_confirm'] ?? '' ) ) {
                $errors['email_confirm'] = 'The email addresses don\'t match.';
            }
        } elseif ( $fixedEmail !== '' && email_exists( $fixedEmail ) ) {
            $errors['username'] = 'An account with your email already exists — please log in instead.';
        }
    }

    /**
     * @param array<string,mixed>  $old
     * @param array<string,string> $errors
     */
    private static function validate_professional_details( array $old, array &$errors, bool $required ): void {
        $id = (string) ( $old['attorney_id'] ?? '' );
        if ( $id === '' ) {
            if ( $required ) {
                $errors['attorney_id'] = 'Please enter your NJ Attorney ID number.';
            }
        } elseif ( ! preg_match( '/^[A-Za-z0-9\-]{3,20}$/', $id ) ) {
            $errors['attorney_id'] = 'Please check your NJ Attorney ID number.';
        }

        $date = (string) ( $old['bar_admission_date'] ?? '' );
        if ( $date === '' ) {
            if ( $required ) {
                $errors['bar_admission_date'] = 'Please enter the date you were admitted to the New Jersey Bar.';
            }
        } else {
            $d = DateTime::createFromFormat( '!Y-m-d', $date );
            if ( ! $d || $d->format( 'Y-m-d' ) !== $date || $date < '1900-01-01' || $d->getTimestamp() > time() + 86400 ) {
                $errors['bar_admission_date'] = 'Please enter a valid date (not in the future).';
            }
        }

        $options = MyNJILGA_Dues_Settings::municipality_options();
        $muni    = (string) ( $old['municipality'] ?? '' );
        if ( $muni !== '' && $options && ! in_array( $muni, $options, true ) ) {
            $errors['municipality'] = 'Please choose a municipality from the list.';
        }
    }

    /**
     * @param array<string,mixed>  $old
     * @param array<string,string> $errors
     * @return array<int,array{first_name:string,last_name:string,email:string}>
     */
    private static function validate_colleagues( array $old, string $payerEmail, array &$errors ): array {
        $rows = (array) ( $old['colleagues'] ?? [] );
        $max  = max( 0, (int) MyNJILGA_Dues_Settings::general( 'join_max_colleagues', 10 ) );
        if ( ! $rows ) {
            $errors['colleagues'] = 'Add at least one colleague, or choose "No, just me".';
            return [];
        }
        if ( count( $rows ) > $max ) {
            $errors['colleagues'] = sprintf( 'You can add up to %d colleagues online — contact NJILGA to add more.', $max );
            return [];
        }
        $seen = [ self::mailbox_key( $payerEmail ) => true ];
        $out  = [];
        foreach ( $rows as $i => $r ) {
            $label = sprintf( 'Colleague %d', $i + 1 );
            if ( $r['first_name'] === '' || $r['last_name'] === '' ) {
                $errors[ 'colleague_' . $i ] = $label . ': please give a first and last name.';
                continue;
            }
            $bad = self::plain_text_problem( $r['first_name'], 80 ) ?: self::plain_text_problem( $r['last_name'], 80 );
            if ( $bad !== '' ) {
                $errors[ 'colleague_' . $i ] = $label . ': the name ' . $bad;
                continue;
            }
            if ( ! is_email( $r['email'] ) ) {
                $errors[ 'colleague_' . $i ] = $label . ': please enter a valid email address.';
                continue;
            }
            // "ann+1@firm.com" and "ann+2@firm.com" are one inbox, not two seats.
            $key = self::mailbox_key( $r['email'] );
            if ( isset( $seen[ $key ] ) ) {
                $errors[ 'colleague_' . $i ] = $label . ': that email is already on this form.';
                continue;
            }
            $seen[ $key ] = true;
            $out[] = [ 'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'email' => $r['email'] ];
        }
        return $out;
    }

    /**
     * Anyone on this join who is already covered for the year: current
     * already, on an open firm invoice that covers them, or on another join
     * whose money is committed. Keyed like the form's errors.
     *
     * The payer proved their own address (or is signed in to it), so
     * their answer says which it is. A colleague's never does — the same
     * words whether they're a member, on their firm's unpaid invoice, or
     * on someone else's join — or this would tell anyone those things
     * about any address they type.
     *
     * @param array<int,array{first_name:string,last_name:string,email:string}> $colleagues
     * @param int $userId The signed-in payer's account, or 0.
     * @return array<string,string>
     */
    private static function membership_conflicts( string $payerEmail, array $colleagues, int $year, bool $livemode, int $userId ): array {
        $errors = [];
        $own    = $userId > 0 ? MyNJILGA_Join_Orders_Table::get_open_for_user( $userId ) : null;
        $except = $own ? (int) $own->id : 0;

        // A signed-in payer is found through their account's link to the
        // contact first: staff may have changed the contact's email in
        // FluentCRM without touching the WordPress account.
        $payer  = $userId > 0 ? self::user_contact_by_id( $userId, $payerEmail ) : self::contact_by_email( $payerEmail );
        $emails = array_unique( array_filter( [ strtolower( $payerEmail ), $payer ? strtolower( (string) $payer->email ) : '' ] ) );
        $paying = false;
        foreach ( $emails as $e ) {
            $paying = $paying || (bool) MyNJILGA_Join_Orders_Table::in_flight_mentioning( $e, $year, $livemode, $except );
        }
        if ( self::contact_is_current( $payer, $year ) ) {
            $errors['email'] = sprintf( 'This email already belongs to an NJILGA member for %d — there\'s nothing to pay.', $year );
        } elseif ( self::contact_on_open_invoice( $payer, $year, $livemode ) ) {
            $errors['email'] = sprintf( 'You\'re already on your firm\'s %d dues invoice, which covers your membership once it\'s paid — there\'s nothing to pay here. Contact NJILGA if that looks wrong.', $year );
        } elseif ( $paying ) {
            $errors['email'] = 'A membership for this email is already being paid for. Once it clears there\'s nothing more to do — contact NJILGA if that looks wrong.';
        }

        foreach ( $colleagues as $i => $c ) {
            $contact = self::contact_by_email( (string) $c['email'] );
            if ( self::contact_is_current( $contact, $year )
                || self::contact_on_open_invoice( $contact, $year, $livemode )
                || MyNJILGA_Join_Orders_Table::in_flight_mentioning( (string) $c['email'], $year, $livemode, $except ) ) {
                $errors[ 'colleague_' . $i ] = sprintf( 'Colleague %1$d (%2$s %3$s) can\'t be added online — they may already be covered for %4$d. Remove them to continue, or contact NJILGA.', $i + 1, $c['first_name'], $c['last_name'], $year );
            }
        }
        return $errors;
    }

    /**
     * @param array<string,mixed>  $old
     * @param array<string,string> $fields  field => label
     * @param array<string,string> $errors
     */
    private static function require_fields( array $old, array $fields, array &$errors ): void {
        foreach ( $fields as $k => $label ) {
            if ( ! isset( $errors[ $k ] ) && trim( (string) ( $old[ $k ] ?? '' ) ) === '' ) {
                $errors[ $k ] = $label . ' is required.';
            }
        }
    }

    /**
     * @param array<string,mixed>  $old
     * @param array<string,string> $errors
     */
    private static function validate_phone( array $old, string $key, array &$errors ): void {
        $v = (string) ( $old[ $key ] ?? '' );
        if ( $v !== '' && ! isset( $errors[ $key ] ) && strlen( (string) preg_replace( '/\D+/', '', $v ) ) < 7 ) {
            $errors[ $key ] = 'Please enter a phone number we can reach you on.';
        }
    }

    /**
     * The answers the join record keeps — everything fulfillment needs to
     * write to FluentCRM later.
     *
     * @param array<string,mixed> $old
     * @return array<string,string>
     */
    private static function applicant_answers( array $old, string $form, WP_User $user ): array {
        $outside = ! empty( $old['outside_us'] );
        $a = [
            'email'       => strtolower( (string) $user->user_email ),
            'first_name'  => (string) $old['first_name'],
            'last_name'   => (string) $old['last_name'],
            'phone'       => (string) $old['phone'],
            'address_1'   => (string) $old['address_1'],
            'address_2'   => (string) $old['address_2'],
            'city'        => (string) $old['city'],
            'state'       => $outside ? (string) $old['region'] : (string) $old['state'],
            'postal_code' => (string) $old['postal_code'],
            'country'     => $outside ? (string) $old['country'] : 'US',
        ];
        if ( $form === MyNJILGA_Join_Orders_Table::FORM_STUDENT ) {
            $a['student_status'] = (string) $old['student_status'];
            $a['school']         = (string) $old['school'];
        } else {
            $a['mailing_phone']      = (string) $old['mailing_phone'];
            $a['attorney_id']        = (string) $old['attorney_id'];
            $a['bar_admission_date'] = (string) $old['bar_admission_date'];
            $a['municipality']       = (string) $old['municipality'];
        }
        return $a;
    }

    /**
     * @return array{0:int,1:string} [existing company id, or 0 + the new firm's name]
     */
    private static function resolve_firm_choice( int $companyId, string $name ): array {
        if ( $companyId > 0 && MyNJILGA_Members_Data::companies_module_active() && \FluentCrm\App\Models\Company::find( $companyId ) ) {
            return [ $companyId, '' ];
        }
        if ( MyNJILGA_Members_Data::companies_module_active() && $name !== '' ) {
            $match = \FluentCrm\App\Models\Company::where( 'name', $name )->first();
            if ( $match ) {
                return [ (int) $match->id, '' ];
            }
        }
        return [ 0, $name ];
    }

    /**
     * Why a name/firm answer can't be used as-is ('' = fine). These end up
     * in emails NJILGA sends to other people and in staff spreadsheets, so
     * no links, no addresses, nothing a spreadsheet would run as a formula.
     */
    private static function plain_text_problem( string $v, int $max ): string {
        if ( $v === '' ) {
            return '';
        }
        if ( mb_strlen( $v ) > $max ) {
            return sprintf( 'is too long (%d characters at most).', $max );
        }
        if ( preg_match( '#(https?:|://|www\.|@)#i', $v ) ) {
            return 'can\'t contain a web or email address.';
        }
        if ( preg_match( '/^[=+\-@\t\r]/', $v ) ) {
            return 'can\'t start with that character.';
        }
        return '';
    }

    /**
     * An email reduced to its mailbox: lower-cased, "+tag" dropped.
     */
    private static function mailbox_key( string $email ): string {
        $email = strtolower( trim( $email ) );
        $at    = strrpos( $email, '@' );
        if ( $at === false ) {
            return $email;
        }
        $local = substr( $email, 0, $at );
        $plus  = strpos( $local, '+' );
        return ( $plus === false ? $local : substr( $local, 0, $plus ) ) . substr( $email, $at );
    }

    /**
     * Sign the new account in for the rest of this request too: nonces
     * minted later in this same response (a re-shown form after a Stripe
     * hiccup) are tied to the login cookie's session token, which
     * wp_set_auth_cookie() sends but doesn't put in $_COOKIE.
     */
    private static function log_in( int $userId ): void {
        $capture = static function ( $cookie ): void {
            $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
        };
        add_action( 'set_logged_in_cookie', $capture );
        wp_set_current_user( $userId );
        wp_set_auth_cookie( $userId, true, is_ssl() );
        remove_action( 'set_logged_in_cookie', $capture );
    }

    /**
     * Whether this contact is on a firm dues invoice for the year that
     * isn't paid yet (drafted, or out and unpaid) — the firm's invoice
     * already covers them.
     *
     * @param object|null $contact
     */
    private static function contact_on_open_invoice( $contact, int $year, bool $livemode ): bool {
        return $contact ? (bool) MyNJILGA_Dues_Invoice_Table::open_rows_listing_contact( (int) $contact->id, $year, $livemode ) : false;
    }

    /**
     * @return object|null The FluentCRM contact with this email.
     */
    private static function contact_by_email( string $email ) {
        if ( ! is_email( $email ) || ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
            return null;
        }
        return \FluentCrm\App\Models\Subscriber::where( 'email', strtolower( $email ) )->first();
    }

    /**
     * The contact a signed-in visitor is: through the account's own link
     * first (so a contact whose email staff changed in FluentCRM is still
     * found), else by the account's email — and only a contact no other
     * account owns. A read, never a re-link.
     *
     * @return object|null
     */
    private static function user_contact( WP_User $user ) {
        return self::user_contact_by_id( (int) $user->ID, (string) $user->user_email );
    }

    /**
     * @return object|null
     */
    private static function user_contact_by_id( int $userId, string $email ) {
        if ( $userId <= 0 || ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
            return null;
        }
        return MyNJILGA_Join_Fulfillment::contact_for_user( $userId, $email );
    }

    // -------------------------------------------------------------------------
    // Email verification codes
    // -------------------------------------------------------------------------

    /**
     * Each address's code lives in its own options row (autoload off),
     * read and written straight through $wpdb — never get_option(), whose
     * cache would go stale behind the conditional writes check_code()
     * depends on. That keeps the attempt counter atomic on every site,
     * with or without a persistent object cache.
     */
    private static function code_key( string $email ): string {
        return 'njilga_join_code_' . md5( strtolower( trim( $email ) ) );
    }

    private static function code_hash( string $email, string $code ): string {
        return hash_hmac( 'sha256', strtolower( trim( $email ) ) . '|' . $code, wp_salt( 'nonce' ) );
    }

    /**
     * The code row as stored (for a conditional write) and decoded.
     *
     * @return array{raw:string,entry:array<string,mixed>}|null
     */
    private static function code_row( string $key ): ?array {
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore
        if ( ! is_string( $raw ) ) {
            return null;
        }
        $entry = json_decode( $raw, true );
        return is_array( $entry ) && isset( $entry['hash'], $entry['tries'], $entry['expires'] ) ? [ 'raw' => $raw, 'entry' => $entry ] : null;
    }

    /**
     * Replace a code row, but only if it still holds exactly $from. False
     * when another request changed it first.
     *
     * @param array<string,mixed> $entry
     */
    private static function swap_code( string $key, string $from, array $entry ): bool {
        global $wpdb;
        return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) wp_json_encode( $entry ), $key, $from ) ) === 1; // phpcs:ignore
    }

    /**
     * Delete a code row, but only while it still holds exactly $only.
     * False when another request changed or removed it first.
     */
    private static function delete_code( string $key, string $only ): bool {
        global $wpdb;
        return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $only ) ) === 1; // phpcs:ignore
    }

    /**
     * Drop a few codes nobody came back for. The rows are tiny, but
     * nothing else would ever remove them.
     */
    private static function purge_stale_codes(): void {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 20", $wpdb->esc_like( 'njilga_join_code_' ) . '%' ) ); // phpcs:ignore
        foreach ( $rows as $r ) {
            $entry = json_decode( (string) $r->option_value, true );
            if ( ! is_array( $entry ) || (int) ( $entry['expires'] ?? 0 ) < time() ) {
                self::delete_code( (string) $r->option_name, (string) $r->option_value );
            }
        }
    }

    /**
     * What the applicant is told once send_code() says yes — the same
     * words whether a code or a "you already have an account" note went.
     */
    public static function code_sent_message( string $email ): string {
        return sprintf( 'We\'ve sent an email to %s — enter the 6-digit code from it to continue. (If that address already has an NJILGA account, the email tells you how to log in instead.)', $email );
    }

    /**
     * Email a fresh 6-digit code — or, when the address already has an
     * account, a note telling its owner how to log in. The answer is the
     * same either way, so this can't be used to find out who has an
     * account here. Throttled per connection before anything is looked
     * up, then per address (one a minute, five an hour).
     *
     * @param string $returnTo The join page, for the log-in note's links.
     * @return array{ok:bool,error:string}
     */
    public static function send_code( string $email, string $ip, string $returnTo = '' ): array {
        if ( ! self::rate_ok( 'code_ip', $ip, 20, 3600 ) ) {
            return [ 'ok' => false, 'error' => 'Too many codes requested — please wait a while and try again.' ];
        }
        $email = strtolower( trim( $email ) );
        if ( ! is_email( $email ) ) {
            return [ 'ok' => false, 'error' => 'Please enter a valid email address first.' ];
        }
        if ( ! self::rate_ok( 'code_minute', $email, 1, 60 ) ) {
            return [ 'ok' => true, 'error' => '' ]; // One is already on its way.
        }
        if ( ! self::rate_ok( 'code_email', $email, 5, 3600 ) ) {
            return [ 'ok' => false, 'error' => 'Too many codes requested — please wait a while and try again.' ];
        }

        if ( email_exists( $email ) ) {
            MyNJILGA_Join_Fulfillment::mail(
                $email,
                'Your NJILGA website account',
                sprintf(
                    "Someone — hopefully you — started joining NJILGA online with this email address. There's already an NJILGA website account for it, so there's no code to enter: log in instead, then come back to the membership page.\n\nLog in: %s\nForgotten your password? Reset it here: %s\n\nIf you didn't ask for this, you can ignore this email.\n\nNJILGA",
                    wp_login_url( $returnTo ),
                    wp_lostpassword_url( $returnTo )
                )
            );
            return [ 'ok' => true, 'error' => '' ];
        }

        global $wpdb;
        $code  = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        // The expiry is fixed here, inside the entry: nothing a guess does
        // can keep a code alive longer.
        $entry = [ 'hash' => self::code_hash( $email, $code ), 'tries' => 0, 'sent' => time(), 'expires' => time() + self::CODE_TTL ];
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", self::code_key( $email ), (string) wp_json_encode( $entry ) ) ); // phpcs:ignore
        self::purge_stale_codes();
        MyNJILGA_Join_Fulfillment::mail(
            $email,
            sprintf( 'Your NJILGA verification code: %s', $code ),
            sprintf( "Your code to confirm this email address for your NJILGA membership is:\n\n    %s\n\nIt expires in 15 minutes. If you didn't ask for this, you can ignore this email.\n\nNJILGA", $code )
        );
        return [ 'ok' => true, 'error' => '' ];
    }

    /**
     * Check a code. Every check spends one of the code's attempts BEFORE
     * comparing, through a write that only lands if nobody else's did
     * first — so a burst of parallel guesses can't all read "no tries
     * yet" and each get a free one. A right code gives its attempt back,
     * or — when $consume — is deleted by a write that is just as
     * conditional, so two submits carrying one code can't both spend it.
     *
     * @return array{ok:bool,error:string,spent?:bool} spent: the code is gone (expired, or too many
     *                                                  wrong tries) and only a new one will do.
     */
    public static function check_code( string $email, string $code, bool $consume ): array {
        $key = self::code_key( $email );
        for ( $race = 0; $race < 5; $race++ ) {
            $row = self::code_row( $key );
            if ( ! $row || (int) $row['entry']['expires'] < time() ) {
                if ( $row ) {
                    self::delete_code( $key, $row['raw'] );
                }
                return [ 'ok' => false, 'error' => 'That code has expired — ' . self::NEW_CODE_HINT, 'spent' => true ];
            }
            $entry = $row['entry'];
            if ( (int) $entry['tries'] >= self::CODE_MAX_TRIES ) {
                self::delete_code( $key, $row['raw'] );
                return [ 'ok' => false, 'error' => 'Too many wrong codes — ' . self::NEW_CODE_HINT, 'spent' => true ];
            }
            $entry['tries'] = (int) $entry['tries'] + 1;
            if ( ! self::swap_code( $key, $row['raw'], $entry ) ) {
                continue; // Another check landed first — read the count it left.
            }
            if ( ! preg_match( '/^\d{6}$/', $code ) || ! hash_equals( (string) $entry['hash'], self::code_hash( $email, $code ) ) ) {
                return [ 'ok' => false, 'error' => 'That code isn\'t right — check the email and try again.' ];
            }
            if ( $consume ) {
                if ( ! self::delete_code( $key, (string) wp_json_encode( $entry ) ) ) {
                    continue; // Changed under us — spent by a twin submit, or tried again: look again.
                }
            } else {
                $back          = $entry;
                $back['tries'] = (int) $entry['tries'] - 1;
                self::swap_code( $key, (string) wp_json_encode( $entry ), $back );
            }
            return [ 'ok' => true, 'error' => '' ];
        }
        return [ 'ok' => false, 'error' => 'We couldn\'t check the code just now — please try again.' ];
    }

    /**
     * After some other error on a logged-out submit: if the code it
     * carried still checks out, keep the code box open with the code in
     * it (the view echoes it back) instead of making the applicant fetch
     * a new one. Checked, never spent, here.
     *
     * @param array<string,mixed> $old
     */
    private static function keep_code( array $old ): void {
        $code  = (string) ( $old['email_code'] ?? '' );
        $email = (string) ( $old['email'] ?? '' );
        if ( strlen( $code ) === 6 && is_email( $email ) && self::check_code( $email, $code, false )['ok'] ) {
            self::$state['needs_code'] = true;
        }
    }

    public static function ajax_send_code(): void {
        check_ajax_referer( self::NONCE_ACTION . '_code', '_nonce' );
        $email = strtolower( sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) ) );
        // The log-in note links back to the page that asked (a local URL
        // only — wp_get_referer() validates it).
        $r = self::send_code( $email, self::client_ip(), (string) wp_get_referer() );
        $r['ok'] ? wp_send_json_success( [ 'message' => self::code_sent_message( $email ) ] ) : wp_send_json_error( $r['error'] );
    }

    public static function ajax_check_code(): void {
        check_ajax_referer( self::NONCE_ACTION . '_code', '_nonce' );
        $email = strtolower( sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) ) );
        // Each code has its own attempt limit; this caps how fast anyone
        // can work through code after code, per connection and per address.
        if ( ! self::rate_ok( 'check_ip', self::client_ip(), 20, 600 ) || ! self::rate_ok( 'check_email', $email, 10, 600 ) ) {
            wp_send_json_error( 'Too many tries — please wait a few minutes and try again.' );
        }
        $code = preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['code'] ?? '' ) );
        $r    = self::check_code( $email, (string) $code, false );
        $r['ok'] ? wp_send_json_success() : wp_send_json_error( $r['error'] );
    }

    /**
     * Whether the FluentCRM contact with this email is paid up for the
     * year (carries "Dues Paid {year}").
     */
    public static function is_current( string $email, int $year ): bool {
        return self::contact_is_current( self::contact_by_email( $email ), $year );
    }

    /**
     * @param object|null $contact
     */
    private static function contact_is_current( $contact, int $year ): bool {
        return $contact ? MyNJILGA_Tags::has_title( $contact, MyNJILGA_Dues_Settings::year_tag( 'year_paid_tag_pattern', $year ) ) : false;
    }

    /**
     * A simple sliding counter per bucket and key — an IP (client_ip())
     * or an email address. True = under the limit (and counted).
     */
    private static function rate_ok( string $bucket, string $who, int $limit, int $window ): bool {
        if ( $who === '' ) {
            return true;
        }
        $key   = 'njilga_join_' . $bucket . '_' . md5( $who );
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return false;
        }
        set_transient( $key, $count + 1, $window );
        return true;
    }
}
