<?php
/**
 * Firm Renewal Lookup — a public way to find a firm's open dues invoice
 * and pay it, for whoever didn't see (or can't find) the invoice email.
 *
 *   [njilga_firm_renewal_lookup]
 *   [njilga_firm_renewal_lookup verify="email" title="Pay your firm's dues"]
 *
 * It creates nothing and settles nothing. The visitor searches by firm
 * name; every firm that has an OPEN invoice and matches is listed with a
 * "Pay" button that goes to that invoice's own Stripe-hosted payment page —
 * the same link the invoice email carries. Paying it fires Stripe's
 * invoice.paid webhook, which is the one path that grants tags and
 * WordPress roles (MyNJILGA_Payment_Listener), and it settles every member
 * on the invoice's frozen roster. So "the payment applies to the whole
 * firm" is not something this class does: it is what paying the invoice
 * already does.
 *
 * It is on the public internet, so it is deliberately small:
 *   • Only firms with an open, issued invoice are searchable — a firm that
 *     doesn't exist and one that has already paid get the same answer, so
 *     the lookup can't be used to learn who exists or who owes.
 *   • Results show the firm, dues year, amount, "billed to Ann B." (first
 *     name and last initial) and a member count. Never an email address or
 *     the roster's names. BUT the Stripe page the Pay button opens is the
 *     same page the firm was emailed, and it shows the invoice's bill-to
 *     name and email and every line ("Ann Brown — 2027 Professional
 *     Membership"). So anyone who can find a firm can read that — the
 *     lookup's own page is minimal, the payment page is not. verify="email"
 *     is the control for that (below); without it, the firm name is the
 *     only gate, which is what an anonymous "pay without the email" page
 *     means.
 *   • A search needs at least three letters, returns at most eight firms,
 *     and is rate-limited per visitor (a short and a daily window).
 *   • Searches are POSTs: never cached by a page cache, and the firm name
 *     never lands in a URL or an access log.
 *   • verify="email" tightens it further: a firm — and so its Stripe
 *     page — is shown only to someone who also types an email address that
 *     is on that firm's invoice.
 *   • Always Live-mode invoices, whichever way staff have set the admin
 *     Test/Live toggle — the same rule as [njilga_firm_dues_status].
 *
 * The matching, selection and presentation rules are PURE and unit tested
 * in tests/FirmRenewalLookupTest.php.
 */
class MyNJILGA_Firm_Renewal_Lookup {

    const SHORTCODE = 'njilga_firm_renewal_lookup';

    const FIELD_QUERY = 'njilga_firm_q';
    const FIELD_EMAIL = 'njilga_firm_email';
    const FIELD_TRAP  = 'njilga_firm_site'; // Honeypot: a person never fills it.

    /** Fewest letters/digits a search needs. */
    const MIN_QUERY = 3;

    /** Most firms one search lists. */
    const MAX_FIRMS = 8;

    /** Searches allowed per visitor: [ count, window in seconds ]. */
    const LIMIT_SHORT = [ 10, 600 ];
    const LIMIT_DAY   = [ 60, 86400 ];

    public static function register(): void {
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'render' ] );
    }

    // -------------------------------------------------------------------------
    // Page
    // -------------------------------------------------------------------------

    /**
     * @param array<string,string>|string $atts
     */
    public static function render( $atts = [] ): string {
        $atts = shortcode_atts( [
            'verify' => 'none',
            'title'  => 'Find your firm\'s renewal invoice',
        ], is_array( $atts ) ? $atts : [], self::SHORTCODE );
        $verifyEmail = strtolower( trim( (string) $atts['verify'] ) ) === 'email';

        $searched = isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST[ self::FIELD_QUERY ] ); // phpcs:ignore WordPress.Security.NonceVerification -- a read-only search; see the class comment.
        $query    = $searched ? self::clean( wp_unslash( (string) $_POST[ self::FIELD_QUERY ] ), 100 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $email    = ( $searched && $verifyEmail && isset( $_POST[ self::FIELD_EMAIL ] ) ) ? strtolower( self::clean( wp_unslash( (string) $_POST[ self::FIELD_EMAIL ] ), 190 ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        // !== '' rather than !empty(): empty('0') is true, so a bot posting "0" would pass.
        $trapped  = $searched && isset( $_POST[ self::FIELD_TRAP ] ) && trim( (string) wp_unslash( $_POST[ self::FIELD_TRAP ] ) ) !== ''; // phpcs:ignore WordPress.Security.NonceVerification

        $message = '';
        $result  = null;
        if ( $trapped ) {
            // A person never fills the hidden field — but autofill sometimes
            // does, and a silent blank page would leave them stuck.
            $message = 'Something went wrong with that search. Please reload the page and try again.';
        } elseif ( $searched ) {
            if ( ! self::query_ok( $query ) ) {
                $message = 'Please type at least ' . self::MIN_QUERY . ' letters of your firm\'s name.';
            } elseif ( $verifyEmail && ! is_email( $email ) ) {
                $message = 'Please also enter your email address, so we can confirm you are with the firm.';
            } elseif ( ! self::within_limits( MyNJILGA_Join_Form::client_ip() ) ) {
                $message = 'Too many searches from your connection — please wait a few minutes and try again.';
            } else {
                $rows   = MyNJILGA_Dues_Invoice_Table::get_open_issued( true );
                $result = self::select( $rows, $query, $verifyEmail, $email );
            }
        }

        MyNJILGA_Front_Style::enqueue_fonts();
        ob_start();
        self::styles();
        echo '<div class="njilga-renew">';
        printf( '<h2 class="njilga-renew__title">%s</h2>', esc_html( (string) $atts['title'] ) );
        echo '<p class="njilga-renew__intro">Didn\'t receive your invoice email, or can\'t find it? Search for your firm below to find its open dues invoice and pay it online.</p>';
        self::form( $query, $email, $verifyEmail );
        echo '<div id="njilga-renew-results" class="njilga-renew__results" aria-live="polite">';
        if ( $message !== '' ) {
            printf( '<p class="njilga-renew__note">%s</p>', esc_html( $message ) );
        } elseif ( $result !== null ) {
            self::results( $result, $query );
        }
        echo '</div></div>';
        return (string) ob_get_clean();
    }

    private static function form( string $query, string $email, bool $verifyEmail ): void {
        printf( '<form class="njilga-renew__form" method="post" action="%s" role="search">', esc_url( self::page_url() . '#njilga-renew-results' ) );
        printf(
            '<div class="njilga-renew__field"><label for="njilga-renew-q">Firm name</label><input type="text" id="njilga-renew-q" name="%s" value="%s" maxlength="100" autocomplete="organization" placeholder="e.g. Smith &amp; Jones" required></div>',
            esc_attr( self::FIELD_QUERY ),
            esc_attr( $query )
        );
        if ( $verifyEmail ) {
            printf(
                '<div class="njilga-renew__field"><label for="njilga-renew-email">Your email address</label><input type="email" id="njilga-renew-email" name="%s" value="%s" maxlength="190" autocomplete="email" required><span class="njilga-renew__hint">The address NJILGA has on file for you or your firm.</span></div>',
                esc_attr( self::FIELD_EMAIL ),
                esc_attr( $email )
            );
        }
        // Honeypot: off-screen, not focusable, not announced.
        printf(
            '<div class="njilga-renew__trap" aria-hidden="true"><label>Leave this empty<input type="text" name="%s" value="" tabindex="-1" autocomplete="off"></label></div>',
            esc_attr( self::FIELD_TRAP )
        );
        echo '<button type="submit" class="njilga-renew__go">Find my invoice</button></form>';
    }

    /**
     * @param array{firms:array<int,array<string,mixed>>,total:int} $result
     */
    private static function results( array $result, string $query ): void {
        if ( ! $result['firms'] ) {
            // The same words whether the firm doesn't exist, has nothing
            // open, or has paid — never a hint which.
            printf(
                '<p class="njilga-renew__note">We couldn\'t find an open renewal invoice for &ldquo;%s&rdquo;. It may already be paid, or the firm may be listed under a slightly different name — try fewer words. If you need help, please contact NJILGA.</p>',
                esc_html( $query )
            );
            return;
        }
        if ( $result['total'] > count( $result['firms'] ) ) {
            printf( '<p class="njilga-renew__note">%d firms match — showing the first %d. Add more of the name to narrow it down.</p>', (int) $result['total'], count( $result['firms'] ) );
        }

        foreach ( $result['firms'] as $firm ) {
            echo '<div class="njilga-renew__card">';
            printf( '<h3 class="njilga-renew__firm">%s</h3>', esc_html( (string) $firm['name'] ) );
            foreach ( $firm['invoices'] as $inv ) {
                self::invoice( $inv );
            }
            echo '</div>';
        }
        echo '<p class="njilga-renew__fine">Anyone at the firm can pay. Payment marks every member listed on the invoice as current, exactly as if the invoice email had been used. You\'ll be taken to NJILGA\'s secure Stripe payment page.</p>';
    }

    /**
     * @param array<string,mixed> $inv From present().
     */
    private static function invoice( array $inv ): void {
        $bits = [ (int) $inv['members'] . ( (int) $inv['members'] === 1 ? ' member' : ' members' ) . ' covered' ];
        if ( $inv['billed_to'] !== '' ) {
            $bits[] = 'billed to ' . $inv['billed_to'];
        }
        if ( $inv['kind'] === MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT ) {
            $bits[] = 'Trustee Dinner assessment';
        }
        echo '<div class="njilga-renew__inv">';
        printf(
            '<div><div class="njilga-renew__yr">%d dues</div><div class="njilga-renew__meta">%s</div></div>',
            (int) $inv['year'],
            esc_html( implode( ' · ', $bits ) )
        );
        if ( $inv['state'] === 'pay' ) {
            printf(
                '<div class="njilga-renew__act"><span class="njilga-renew__amt">%s</span><a class="njilga-renew__pay" href="%s" rel="noopener">Pay now</a></div>',
                esc_html( MyNJILGA_Invoicing::money( (int) $inv['amount'] ) ),
                esc_url( (string) $inv['url'] )
            );
        } else {
            echo '<div class="njilga-renew__act"><span class="njilga-renew__clearing">A payment is already being processed &mdash; bank transfers can take a few business days. Nothing more to pay.</span></div>';
        }
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // PURE — what a search finds
    // -------------------------------------------------------------------------

    /**
     * Pick the firms a search should list from the open-invoice rows.
     *
     * @param array<int,object> $rows         njilga_dues_invoices rows (Live, issued, open).
     * @param bool              $requireEmail Show a firm only when $email is on its invoice.
     * @return array{firms:array<int,array<string,mixed>>,total:int}
     */
    public static function select( array $rows, string $query, bool $requireEmail = false, string $email = '' ): array {
        $tokens = self::tokens( $query );
        if ( ! self::query_ok( $query ) || ! $tokens ) {
            return [ 'firms' => [], 'total' => 0 ];
        }

        $byCompany = [];
        foreach ( $rows as $row ) {
            $state = self::row_state( $row );
            if ( $state === '' ) {
                continue;
            }
            $name = MyNJILGA_Dues_Snapshot::company_name( $row );
            if ( ! self::name_matches( $name, $tokens ) ) {
                continue;
            }
            if ( $requireEmail && ! self::roster_has_email( $row, $email ) ) {
                continue;
            }
            $cid = (int) $row->fluentcrm_company_id;
            if ( ! isset( $byCompany[ $cid ] ) ) {
                $byCompany[ $cid ] = [ 'id' => $cid, 'name' => $name, 'rank' => self::rank( $name, $query ), 'invoices' => [] ];
            }
            $byCompany[ $cid ]['invoices'][] = self::present( $row, $state );
        }

        $firms = array_values( $byCompany );
        usort( $firms, static function ( $a, $b ) {
            return $a['rank'] !== $b['rank'] ? $a['rank'] <=> $b['rank'] : strcasecmp( (string) $a['name'], (string) $b['name'] );
        } );
        foreach ( $firms as &$f ) {
            usort( $f['invoices'], static function ( $a, $b ) {
                return $a['year'] !== $b['year'] ? $a['year'] <=> $b['year'] : $a['id'] <=> $b['id'];
            } );
        }
        unset( $f );

        return [ 'firms' => array_slice( $firms, 0, self::MAX_FIRMS ), 'total' => count( $firms ) ];
    }

    /**
     * 'pay' for an invoice that can be paid right now (issued, with an
     * https hosted payment page), 'clearing' for one whose ACH payment is
     * already in flight, '' for anything else.
     *
     * @param object $row
     */
    public static function row_state( $row ): string {
        $status = (string) $row->status;
        $url    = (string) ( $row->hosted_invoice_url ?? '' );
        if ( $status === MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING ) {
            return 'clearing';
        }
        $payable = $status === MyNJILGA_Dues_Invoice_Table::STATUS_CREATED || $status === MyNJILGA_Dues_Invoice_Table::STATUS_SENT;
        return ( $payable && stripos( $url, 'https://' ) === 0 ) ? 'pay' : '';
    }

    /**
     * What a result card shows of one invoice — and no more.
     *
     * @param object $row
     * @return array{id:int,year:int,state:string,amount:int,url:string,members:int,billed_to:string,kind:string}
     */
    public static function present( $row, string $state ): array {
        $snapshot = MyNJILGA_Dues_Snapshot::decode( $row );
        $due      = (int) $row->amount_due_cents;
        return [
            'id'        => (int) $row->id,
            'year'      => (int) $row->dues_year,
            'state'     => $state,
            'amount'    => $due > 0 ? $due : (int) $row->total_amount_cents,
            'url'       => $state === 'pay' ? (string) $row->hosted_invoice_url : '',
            'members'   => count( (array) $snapshot['members'] ),
            'billed_to' => self::short_name( MyNJILGA_Dues_Snapshot::bill_to( $row ) ),
            'kind'      => MyNJILGA_Dues_Snapshot::invoice_kind( $row ),
        ];
    }

    /**
     * "Ann B." — first name and last initial, enough to tell two
     * invoices at one firm apart and not enough to identify anyone to a
     * stranger.
     *
     * @param array{name:string,first_name:string,last_name:string,email:string} $person
     */
    public static function short_name( array $person ): string {
        $first = trim( (string) $person['first_name'] );
        $last  = trim( (string) $person['last_name'] );
        if ( $first === '' && $last === '' ) {
            $parts = preg_split( '/\s+/', trim( (string) $person['name'] ) ) ?: [];
            if ( count( $parts ) > 1 && strpos( (string) $person['name'], '@' ) === false ) {
                $first = (string) $parts[0];
                $last  = (string) end( $parts );
            } else {
                return ''; // A lone word or an email address: say nothing.
            }
        }
        $initial = $last !== '' ? self::upper_initial( $last ) . '.' : '';
        return trim( $first . ' ' . $initial );
    }

    /**
     * The first letter of a name, upper-cased, as a whole character — a
     * byte slice would cut "Álvarez" in half and print invalid UTF-8.
     */
    private static function upper_initial( string $name ): string {
        if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( mb_substr( $name, 0, 1, 'UTF-8' ), 'UTF-8' );
        }
        return strtoupper( substr( $name, 0, 1 ) );
    }

    /**
     * Whether an email address is on the invoice: its bill-to, its
     * Owner, or any listed member.
     *
     * @param object $row
     */
    public static function roster_has_email( $row, string $email ): bool {
        $email = strtolower( trim( $email ) );
        if ( $email === '' ) {
            return false;
        }
        $snapshot = MyNJILGA_Dues_Snapshot::decode( $row );
        $people   = array_merge(
            [ MyNJILGA_Dues_Snapshot::bill_to( $row ), MyNJILGA_Dues_Snapshot::owner( $row ) ],
            (array) $snapshot['members']
        );
        foreach ( $people as $p ) {
            if ( is_array( $p ) && strtolower( trim( (string) ( $p['email'] ?? '' ) ) ) === $email ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The search words: the query reduced the way firm names are
     * ("Smith & Jones, LLP" → smith, jones), minus "and" and single
     * letters, which would match nearly every firm.
     *
     * @return array<int,string>
     */
    public static function tokens( string $query ): array {
        $key = MyNJILGA_Historical_Import::firm_key( $query );
        $out = [];
        foreach ( explode( ' ', $key ) as $t ) {
            if ( strlen( $t ) >= 2 && $t !== 'and' ) {
                $out[ $t ] = $t;
            }
        }
        return array_values( $out );
    }

    /**
     * A search must carry a real search word of at least MIN_QUERY letters
     * or digits — judged on the words that will actually be searched for
     * (see tokens()), not the raw text, so "a bc" (which would search for
     * the two letters "bc" across every firm) and one or two letters can't
     * be used to page through the firms.
     */
    public static function query_ok( string $query ): bool {
        $longest = 0;
        foreach ( self::tokens( $query ) as $t ) {
            $longest = max( $longest, strlen( $t ) );
        }
        return $longest >= self::MIN_QUERY;
    }

    /**
     * Every search word appears in the firm's name (reduced the same way).
     *
     * @param array<int,string> $tokens
     */
    public static function name_matches( string $name, array $tokens ): bool {
        if ( ! $tokens ) {
            return false;
        }
        $key = MyNJILGA_Historical_Import::firm_key( $name );
        foreach ( $tokens as $t ) {
            if ( strpos( $key, $t ) === false ) {
                return false;
            }
        }
        return true;
    }

    /**
     * 0 = the name is exactly what was typed, 1 = it starts with it,
     * 2 = it contains it — so the likeliest firm is listed first.
     */
    public static function rank( string $name, string $query ): int {
        $key = MyNJILGA_Historical_Import::firm_key( $name );
        $q   = MyNJILGA_Historical_Import::firm_key( $query );
        if ( $q === '' ) {
            return 2;
        }
        if ( $key === $q ) {
            return 0;
        }
        return strpos( $key, $q ) === 0 ? 1 : 2;
    }

    /**
     * Whether a visitor with this many searches already used in the
     * short and daily windows may search again.
     */
    public static function limits_allow( int $shortUsed, int $dayUsed ): bool {
        return $shortUsed < self::LIMIT_SHORT[0] && $dayUsed < self::LIMIT_DAY[0];
    }

    /**
     * @param mixed $value
     */
    private static function clean( $value, int $max ): string {
        $s = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
    }

    // -------------------------------------------------------------------------
    // Rate limiting (per visitor)
    // -------------------------------------------------------------------------

    /**
     * Who a search is counted against. An IPv4 address is its own visitor;
     * an IPv6 address is its /64 — one subscriber's whole allocation — since
     * a single connection can rotate through billions of addresses in it. A
     * missing address shares one "unknown" bucket rather than skipping the
     * limit. PURE.
     */
    public static function ip_bucket( string $ip ): string {
        $ip = trim( $ip );
        if ( $ip === '' ) {
            return 'unknown';
        }
        $bin = @inet_pton( $ip );
        if ( $bin === false ) {
            return 'raw:' . strtolower( $ip );
        }
        if ( strlen( $bin ) === 16 ) {
            // ::ffff:203.0.113.9 is the IPv4 address 203.0.113.9.
            if ( substr( $bin, 0, 12 ) === "\0\0\0\0\0\0\0\0\0\0\xff\xff" ) {
                return 'v4:' . implode( '.', array_map( 'ord', str_split( substr( $bin, 12 ) ) ) );
            }
            return 'v6:' . bin2hex( substr( $bin, 0, 8 ) );
        }
        return 'v4:' . implode( '.', array_map( 'ord', str_split( $bin ) ) );
    }

    /**
     * Count this search against the visitor's two windows; false when
     * either is spent.
     *
     * With a persistent object cache the count is an atomic increment, so a
     * burst of parallel requests can't each read "0". Without one, the
     * counts are transients — read, then written — which a burst of
     * parallel requests can overshoot by about its own size; it never
     * leaves the limit open. A transient is only written for an allowed
     * search, so a blocked visitor's retries don't extend their own wait.
     */
    private static function within_limits( string $ip ): bool {
        $bucket = self::ip_bucket( $ip );

        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            $short = self::cache_bump( 'short', $bucket, self::LIMIT_SHORT[1] );
            $day   = self::cache_bump( 'day', $bucket, self::LIMIT_DAY[1] );
            if ( $short !== null && $day !== null ) {
                return self::limits_allow( $short - 1, $day - 1 );
            }
        }

        $short = (int) get_transient( self::key( 'short', $bucket ) );
        $day   = (int) get_transient( self::key( 'day', $bucket ) );
        if ( ! self::limits_allow( $short, $day ) ) {
            return false;
        }
        set_transient( self::key( 'short', $bucket ), $short + 1, self::LIMIT_SHORT[1] );
        set_transient( self::key( 'day', $bucket ), $day + 1, self::LIMIT_DAY[1] );
        return true;
    }

    /**
     * Atomically add one to a counter in the object cache and return the
     * new value (the expiry is set when the counter is first created and
     * not refreshed), or null if the cache can't do it.
     */
    private static function cache_bump( string $window, string $bucket, int $ttl ): ?int {
        $key = self::key( $window, $bucket );
        wp_cache_add( $key, 0, 'njilga_renew', $ttl );
        $n = wp_cache_incr( $key, 1, 'njilga_renew' );
        return $n === false ? null : (int) $n;
    }

    private static function key( string $bucket, string $who ): string {
        return 'njilga_renew_' . $bucket . '_' . md5( $who );
    }

    // -------------------------------------------------------------------------
    // Look
    // -------------------------------------------------------------------------

    /**
     * The page's own URL, free of this site's query args, for the form.
     */
    private static function page_url(): string {
        $id  = (int) get_queried_object_id();
        $url = $id > 0 ? (string) get_permalink( $id ) : '';
        return $url !== '' ? $url : home_url( '/' );
    }

    /**
     * The same type and colours as the other public forms
     * (MyNJILGA_Front_Style): navy buttons, Playfair headings.
     */
    private static function styles(): void {
        echo '<style>' . MyNJILGA_Front_Style::tokens( '.njilga-renew' ) . '
            .njilga-renew{max-width:760px;-webkit-font-smoothing:antialiased}
            .njilga-renew *{box-sizing:border-box}
            .njilga-renew__title{font-family:var(--nj-font-head);font-size:30px;line-height:1.25;font-weight:700;color:var(--nj-ink);margin:0 0 10px;letter-spacing:0;text-transform:none}
            .njilga-renew__intro{margin:0 0 22px;color:var(--nj-text)}
            .njilga-renew__form{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;margin:0 0 26px;position:relative}
            .njilga-renew__field{flex:1 1 260px;display:flex;flex-direction:column;gap:6px}
            .njilga-renew__field label{font-family:var(--nj-font-ui);font-size:13px;font-weight:600;color:var(--nj-ink)}
            .njilga-renew__field input{width:100%;min-height:48px;padding:10px 14px;border:1px solid var(--nj-field);border-radius:var(--nj-radius-sm);font:inherit;font-size:16px;background:#fff;color:var(--nj-ink)}
            .njilga-renew__field input:focus{outline:2px solid var(--nj-blue);outline-offset:1px;border-color:var(--nj-blue)}
            .njilga-renew__hint{font-size:13px;color:var(--nj-muted)}
            .njilga-renew__trap{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
            .njilga-renew__go,.njilga-renew__pay{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:10px 24px;border-radius:var(--nj-radius-sm);border:1px solid var(--nj-navy);background:var(--nj-navy);color:#fff!important;font-family:var(--nj-font-ui);font-size:15px;font-weight:600;text-decoration:none!important;cursor:pointer}
            .njilga-renew__go:hover,.njilga-renew__pay:hover{background:var(--nj-blue);border-color:var(--nj-blue)}
            .njilga-renew__go:focus-visible,.njilga-renew__pay:focus-visible{outline:2px solid var(--nj-blue);outline-offset:2px}
            .njilga-renew__note{margin:0 0 16px;padding:14px 18px;background:var(--nj-soft);border:1px solid var(--nj-line);border-radius:var(--nj-radius)}
            .njilga-renew__card{border:1px solid var(--nj-line);border-radius:var(--nj-radius);padding:24px 28px;margin:0 0 18px;background:#fff;box-shadow:0 1px 2px rgba(16,24,40,.04),0 4px 16px rgba(16,24,40,.04)}
            .njilga-renew__firm{font-family:var(--nj-font-head);font-size:21px;font-weight:700;color:var(--nj-ink);margin:0 0 6px;letter-spacing:0;text-transform:none}
            .njilga-renew__inv{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:14px 20px;padding:16px 0;border-top:1px solid var(--nj-line)}
            .njilga-renew__inv:first-of-type{border-top:0}
            .njilga-renew__yr{font-family:var(--nj-font-ui);font-weight:600;color:var(--nj-ink)}
            .njilga-renew__meta{font-size:14px;color:var(--nj-muted);margin-top:2px}
            .njilga-renew__act{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
            .njilga-renew__amt{font-family:var(--nj-font-head);font-size:24px;font-weight:700;color:var(--nj-ink)}
            .njilga-renew__clearing{font-size:14px;color:var(--nj-warn);background:var(--nj-warn-bg);padding:8px 12px;border-radius:var(--nj-radius-sm);max-width:420px}
            .njilga-renew__fine{font-size:14px;color:var(--nj-muted);margin:0}
            @media (max-width:640px){.njilga-renew__card{padding:18px}.njilga-renew__go{width:100%}.njilga-renew__act{width:100%;justify-content:space-between}}
        </style>';
    }
}
