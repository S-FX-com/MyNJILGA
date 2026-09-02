<?php
/**
 * Stripe connection/credential layer (spec: Stripe migration phase 1).
 *
 * Owns the plugin's Stripe credentials and connection state — a SEPARATE
 * WordPress option from `njilga_dues_settings` (different sensitivity,
 * different lifecycle): `njilga_stripe_settings`, autoload = false.
 *
 * Holds one block per mode ('test' and 'live'), each with its own secret
 * key, account identity, and webhook endpoint — plus which mode is
 * currently ACTIVE and the flat invoice-shaping settings (currency, days
 * until due, collection method, footer, auto-advance, whether Stripe
 * itself emails the invoice, and the remittance address printed in the
 * unpaid-invoice email for check payments).
 *
 * Secrets at rest: `secret_key` and `webhook_secret` are encrypted with
 * sodium_crypto_secretbox when NJILGA_STRIPE_KEY is defined in
 * wp-config.php (see encrypt_value()/decrypt_value()), and stored as
 * plaintext — not silently dropped — when it isn't. Nothing in this
 * class ever hands a caller the decrypted secret key except
 * decrypted_secret_key()/decrypted_webhook_secret(), used only at the
 * moment a MyNJILGA_Stripe_Client is built or a webhook signature is
 * checked. Every other reader (get(), is_connected(), account_name(),
 * masked_key()) works off the stored — possibly still-encrypted —
 * string, so a page that (incorrectly) echoed a get() value straight
 * into markup still could not leak the real key.
 *
 * A later phase builds the Stripe invoice gateway and webhook receiver
 * on top of this; this class only knows credentials, connection health,
 * and which mode is active — never an invoice, order, or line item.
 */
class MyNJILGA_Stripe_Connection {

    const OPTION = 'njilga_stripe_settings';

    const MODE_TEST = 'test';
    const MODE_LIVE = 'live';

    /**
     * Webhook events this migration relies on. Public so a later phase's
     * webhook receiver subscribes to exactly the same list this class
     * provisions in Stripe.
     */
    const WEBHOOK_EVENTS = [
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
        'invoice.finalized',
        'invoice.voided',
        'invoice.marked_uncollectible',
        'invoice.sent',
        'invoice.overpaid',
        'charge.refunded',
        'credit_note.created',
        'payment_intent.processing',
        'customer.deleted',
    ];

    // How long a health() account check is trusted before health() hits
    // Stripe again for that mode. Plain int (not a WordPress time
    // constant) so this file stays loadable without WordPress.
    const HEALTH_CACHE_TTL_SECONDS = 300;

    const HEALTH_TRANSIENT_PREFIX = 'njilga_stripe_health_';

    // The spec's soft "at least one relevant event received in the last
    // 90 days" window, used by health_warnings(). Plain int for the same
    // reason as HEALTH_CACHE_TTL_SECONDS above.
    const EVENT_SILENCE_DAYS = 90;

    /** @var array<string,mixed>|null */
    private static $cache = null;

    // -------------------------------------------------------------------------
    // Defaults + read (merge-over-defaults, same pattern as
    // MyNJILGA_Dues_Settings::get()/defaults())
    // -------------------------------------------------------------------------

    /**
     * @return array{secret_key:string,account_id:string,account_name:string,webhook_id:string,webhook_secret:string,connected_at:string,last_verified_at:string}
     */
    private static function mode_defaults(): array {
        return [
            'secret_key'       => '',
            'account_id'       => '',
            'account_name'     => '',
            'webhook_id'       => '',
            'webhook_secret'   => '',
            'connected_at'     => '',
            'last_verified_at' => '',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array {
        return [
            'mode'               => self::MODE_TEST,
            'test'               => self::mode_defaults(),
            'live'               => self::mode_defaults(),
            'currency'           => 'usd',
            'days_until_due'     => 30,
            'collection_method'  => 'send_invoice',
            'footer'             => '',
            'auto_advance'       => false,
            'stripe_sends_email' => false,
            'remittance_address' => '',
        ];
    }

    /**
     * Full settings, merged over defaults. Secret fields are returned
     * exactly as stored (still encrypted when encryption is active) —
     * use decrypted_secret_key()/decrypted_webhook_secret() to get the
     * plaintext value for an actual Stripe call.
     *
     * @return array<string,mixed>
     */
    public static function get(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }
        $stored   = get_option( self::OPTION, [] );
        $defaults = self::defaults();

        if ( ! is_array( $stored ) || empty( $stored ) ) {
            self::$cache = $defaults;
            return self::$cache;
        }

        $merged         = $defaults;
        $merged['mode'] = in_array( $stored['mode'] ?? '', [ self::MODE_TEST, self::MODE_LIVE ], true )
            ? $stored['mode']
            : $defaults['mode'];

        foreach ( [ self::MODE_TEST, self::MODE_LIVE ] as $m ) {
            $merged[ $m ] = array_merge( $defaults[ $m ], is_array( $stored[ $m ] ?? null ) ? $stored[ $m ] : [] );
        }

        foreach ( [ 'currency', 'days_until_due', 'collection_method', 'footer', 'auto_advance', 'stripe_sends_email', 'remittance_address' ] as $k ) {
            if ( array_key_exists( $k, $stored ) ) {
                $merged[ $k ] = $stored[ $k ];
            }
        }

        self::$cache = $merged;
        return self::$cache;
    }

    /**
     * Persist a full settings array (already shaped/sanitized by the
     * caller — secrets should already be run through encrypt_value()
     * before reaching here, see verify_and_connect()/
     * save_manual_webhook_secret()).
     *
     * @param array<string,mixed> $settings
     */
    public static function save( array $settings ): void {
        update_option( self::OPTION, $settings, false );
        self::$cache = null;
    }

    // -------------------------------------------------------------------------
    // Typed readers
    // -------------------------------------------------------------------------

    public static function active_mode(): string {
        return (string) self::get()['mode'];
    }

    public static function is_connected( ?string $mode = null ): bool {
        $block = self::get()[ self::normalize_mode( $mode ) ];
        return $block['secret_key'] !== '' && $block['account_id'] !== '';
    }

    public static function account_name( ?string $mode = null ): string {
        return (string) self::get()[ self::normalize_mode( $mode ) ]['account_name'];
    }

    /**
     * Flat currency/days_until_due/collection_method/footer/auto_advance/
     * stripe_sends_email settings.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function setting( string $key, $default = null ) {
        $s = self::get();
        return array_key_exists( $key, $s ) ? $s[ $key ] : $default;
    }

    /**
     * 'test' or 'live' if given and valid, otherwise the active mode.
     */
    private static function normalize_mode( ?string $mode ): string {
        return ( $mode === self::MODE_TEST || $mode === self::MODE_LIVE ) ? $mode : self::active_mode();
    }

    // -------------------------------------------------------------------------
    // Encryption at rest (sodium_crypto_secretbox, keyed off NJILGA_STRIPE_KEY)
    // -------------------------------------------------------------------------

    /**
     * True when NJILGA_STRIPE_KEY is defined in wp-config.php, i.e.
     * secrets are actually being encrypted at rest. The Settings page
     * renders a persistent warning when this is false.
     */
    public static function encryption_active(): bool {
        return defined( 'NJILGA_STRIPE_KEY' ) && (string) NJILGA_STRIPE_KEY !== '';
    }

    /**
     * Derives a fixed 32-byte secretbox key from whatever the site owner
     * pasted into NJILGA_STRIPE_KEY (hex string, base64 string, or raw
     * bytes all work — hashing normalizes the length/encoding instead of
     * requiring the constant to be exactly 32 raw bytes).
     */
    private static function encryption_key_bytes(): string {
        return hash( 'sha256', (string) NJILGA_STRIPE_KEY, true );
    }

    /**
     * Encrypts a secret for storage, or returns it unchanged (plaintext)
     * when encryption isn't active — this must keep working even without
     * NJILGA_STRIPE_KEY, per spec: never silently fail to store the key.
     */
    private static function encrypt_value( string $plain ): string {
        if ( $plain === '' || ! self::encryption_active() ) {
            return $plain;
        }
        $nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $ciphertext = sodium_crypto_secretbox( $plain, $nonce, self::encryption_key_bytes() );
        return base64_encode( $nonce . $ciphertext );
    }

    /**
     * Decrypts a stored secret. Returns '' for "nothing stored", the
     * plaintext string on success, or null when a value IS stored but
     * cannot be decrypted (wrong/rotated NJILGA_STRIPE_KEY, corrupt
     * data) — callers (health()) treat null as its own failure mode,
     * distinct from "not connected".
     */
    private static function decrypt_value( string $stored ): ?string {
        if ( $stored === '' ) {
            return '';
        }
        if ( ! self::encryption_active() ) {
            // Encryption isn't on, so whatever's stored is plaintext —
            // including a value that was written back when encryption
            // WAS active and NJILGA_STRIPE_KEY has since been removed;
            // that legitimately can't be recovered, but we can't tell
            // the difference here without trying, so we hand it back as
            // given rather than guess.
            return $stored;
        }

        $raw = base64_decode( $stored, true );
        if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
            return null;
        }
        $nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $plain      = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::encryption_key_bytes() );
        return ( $plain === false ) ? null : $plain;
    }

    /**
     * The real secret key, decrypted, for the moment it's needed to
     * construct a MyNJILGA_Stripe_Client. '' = nothing stored, null =
     * stored but undecryptable.
     */
    public static function decrypted_secret_key( ?string $mode = null ): ?string {
        $block = self::get()[ self::normalize_mode( $mode ) ];
        return self::decrypt_value( (string) $block['secret_key'] );
    }

    /**
     * The real webhook signing secret, decrypted, for the moment a
     * later phase's webhook receiver needs it to verify a signature.
     */
    public static function decrypted_webhook_secret( ?string $mode = null ): ?string {
        $block = self::get()[ self::normalize_mode( $mode ) ];
        return self::decrypt_value( (string) $block['webhook_secret'] );
    }

    /**
     * The one place the Stripe invoice gateway gets its HTTP transport
     * from: a ready-to-use client for the given (or active) mode, or null
     * when there's nothing usable — not connected in that mode, or the
     * stored key won't decrypt (wrong/rotated NJILGA_STRIPE_KEY). Callers
     * must treat null as "can't talk to Stripe right now", not throw.
     */
    public static function client_for_mode( ?string $mode = null ): ?MyNJILGA_Stripe_Client {
        $mode = self::normalize_mode( $mode );
        if ( ! self::is_connected( $mode ) ) {
            return null;
        }
        $secretKey = self::decrypted_secret_key( $mode );
        if ( $secretKey === null || $secretKey === '' ) {
            return null;
        }
        return new MyNJILGA_Stripe_Client( $secretKey );
    }

    /**
     * Display-safe masked key: "rk_live_••••••••4a9f" — first 8 chars,
     * a fixed bullet run, last 4. Never returns the real key. Returns ''
     * when nothing is stored, and a short placeholder when the stored
     * value can't be decrypted.
     */
    public static function masked_key( string $mode ): string {
        $key = self::decrypted_secret_key( $mode );
        if ( $key === null ) {
            return '(unable to decrypt)';
        }
        if ( $key === '' ) {
            return '';
        }
        if ( strlen( $key ) <= 12 ) {
            return str_repeat( '•', max( 0, strlen( $key ) - 4 ) ) . substr( $key, -4 );
        }
        return substr( $key, 0, 8 ) . '••••••••' . substr( $key, -4 );
    }

    // -------------------------------------------------------------------------
    // Connect flow
    // -------------------------------------------------------------------------

    /**
     * Verifies a pasted Stripe key against Stripe, and — on success —
     * stores it (encrypted at rest) into the given mode's block,
     * auto-provisioning this site's webhook endpoint along the way.
     *
     * @return array{ok:bool,error:string,account_name:string,warning?:string}
     */
    public static function verify_and_connect( string $mode, string $pastedKey ): array {
        if ( $mode !== self::MODE_TEST && $mode !== self::MODE_LIVE ) {
            return [ 'ok' => false, 'error' => 'Invalid mode.', 'account_name' => '' ];
        }

        $pastedKey = trim( $pastedKey );
        if ( $pastedKey === '' ) {
            return [ 'ok' => false, 'error' => 'Paste a Stripe secret or restricted key first.', 'account_name' => '' ];
        }

        // Step b: pure string check on the key prefix, before any network
        // call — a test key can only go into the test slot and vice versa.
        $looksTest = ( strpos( $pastedKey, '_test_' ) !== false );
        $looksLive = ( strpos( $pastedKey, '_live_' ) !== false );
        if ( $looksTest && $mode !== self::MODE_TEST ) {
            return [ 'ok' => false, 'error' => 'That key looks like a TEST key — paste it into the Test card, not Live.', 'account_name' => '' ];
        }
        if ( $looksLive && $mode !== self::MODE_LIVE ) {
            return [ 'ok' => false, 'error' => 'That key looks like a LIVE key — paste it into the Live card, not Test.', 'account_name' => '' ];
        }

        $client       = new MyNJILGA_Stripe_Client( $pastedKey );
        $accountResp  = $client->request( 'GET', '/account' );
        if ( ! $accountResp['ok'] ) {
            return [
                'ok'           => false,
                'error'        => $accountResp['error'] !== '' ? $accountResp['error'] : 'Could not verify this key with Stripe.',
                'account_name' => '',
            ];
        }
        $account = $accountResp['body'];

        // Step d: defense in depth — the account's own livemode flag must
        // agree with the slot we're connecting it into.
        $wantLive = ( $mode === self::MODE_LIVE );
        if ( array_key_exists( 'livemode', $account ) && (bool) $account['livemode'] !== $wantLive ) {
            return [
                'ok'           => false,
                'error'        => sprintf(
                    'This key\'s account reports %s mode, which does not match the %s slot you\'re connecting it to.',
                    ! empty( $account['livemode'] ) ? 'live' : 'test',
                    $mode
                ),
                'account_name' => '',
            ];
        }

        // Step e: live accounts must actually be able to accept charges.
        if ( $wantLive && empty( $account['charges_enabled'] ) ) {
            return [
                'ok'           => false,
                'error'        => 'This Stripe account is not yet able to accept charges — finish its onboarding in the Stripe Dashboard before connecting it here.',
                'account_name' => '',
            ];
        }

        // Step f: identity.
        $accountId = (string) ( $account['id'] ?? '' );
        if ( ! empty( $account['business_profile']['name'] ) ) {
            $accountName = (string) $account['business_profile']['name'];
        } elseif ( ! empty( $account['settings']['dashboard']['display_name'] ) ) {
            $accountName = (string) $account['settings']['dashboard']['display_name'];
        } else {
            $accountName = $accountId;
        }

        // Step g/h: webhook auto-provisioning, with graceful degradation.
        [ $webhookId, $webhookSecret, $warning ] = self::provision_webhook( $client, $mode );

        $now    = current_time( 'mysql' );
        $stored = self::get();

        $stored[ $mode ] = [
            'secret_key'       => self::encrypt_value( $pastedKey ),
            'account_id'       => $accountId,
            'account_name'     => $accountName,
            'webhook_id'       => $webhookId,
            'webhook_secret'   => self::encrypt_value( $webhookSecret ),
            'connected_at'     => $now,
            'last_verified_at' => $now,
        ];
        self::save( $stored );

        $result = [ 'ok' => true, 'error' => '', 'account_name' => $accountName ];
        if ( $warning !== '' ) {
            $result['warning'] = $warning;
        }
        return $result;
    }

    /**
     * Step g/h of verify_and_connect(): find-or-create this site's
     * webhook endpoint. Never throws / never fails the outer connect —
     * every failure path here degrades to an empty id/secret plus a
     * human-readable warning for the manual-fallback UI.
     *
     * @return array{0:string,1:string,2:string} [webhook_id, webhook_secret, warning]
     */
    private static function provision_webhook( MyNJILGA_Stripe_Client $client, string $mode ): array {
        $webhookUrl = rest_url( 'njilga/v1/stripe-webhook' );

        $listResp = $client->request( 'GET', '/webhook_endpoints', [ 'limit' => 100 ] );
        if ( ! $listResp['ok'] ) {
            return [ '', '', self::webhook_permission_warning( $webhookUrl ) ];
        }

        $match = null;
        foreach ( (array) ( $listResp['body']['data'] ?? [] ) as $ep ) {
            if ( is_array( $ep ) && (string) ( $ep['url'] ?? '' ) === $webhookUrl ) {
                $match = $ep;
                break;
            }
        }

        if ( $match !== null ) {
            $webhookId     = (string) ( $match['id'] ?? '' );
            $currentEvents = array_map( 'strval', (array) ( $match['enabled_events'] ?? [] ) );
            $missingEvents = array_values( array_diff( self::WEBHOOK_EVENTS, $currentEvents ) );

            if ( ! empty( $missingEvents ) ) {
                $mergedEvents = array_values( array_unique( array_merge( $currentEvents, self::WEBHOOK_EVENTS ) ) );
                // A failed PATCH doesn't fail the connect — the endpoint
                // still exists and fires for whatever events it already had.
                $client->request( 'POST', '/webhook_endpoints/' . rawurlencode( $webhookId ), [ 'enabled_events' => $mergedEvents ] );
            }

            // Reused endpoints never return 'secret' in list/get responses.
            // Keep whatever this mode already has on file for it — but
            // only if that stored secret actually belongs to THIS
            // endpoint id; a mismatched id means the stored secret is for
            // a different (e.g. deleted-and-recreated) endpoint and must
            // not be reused, since webhook secrets are per-endpoint.
            $prior         = self::get()[ $mode ];
            $webhookSecret = ( (string) $prior['webhook_id'] === $webhookId ) ? (string) self::decrypted_webhook_secret( $mode ) : '';

            $warning = '';
            if ( $webhookSecret === '' ) {
                $warning = 'A webhook endpoint for this site already existed in Stripe (from a previous setup), but Stripe only reveals its signing secret at creation time. Paste it manually below — find it in the Stripe Dashboard under Developers → Webhooks — or delete that endpoint in Stripe and reconnect to let this plugin create a fresh one.';
            }

            return [ $webhookId, $webhookSecret, $warning ];
        }

        $createResp = $client->request( 'POST', '/webhook_endpoints', [
            'url'            => $webhookUrl,
            'enabled_events' => self::WEBHOOK_EVENTS,
            'api_version'    => MyNJILGA_Stripe_Client::API_VERSION,
        ] );

        if ( ! $createResp['ok'] ) {
            return [ '', '', self::webhook_permission_warning( $webhookUrl ) ];
        }

        return [
            (string) ( $createResp['body']['id'] ?? '' ),
            (string) ( $createResp['body']['secret'] ?? '' ),
            '',
        ];
    }

    private static function webhook_permission_warning( string $webhookUrl ): string {
        return sprintf(
            'The Stripe account connected, but automatic webhook setup failed — the key is likely missing the "Webhook Endpoints: Write" permission. Add a webhook endpoint manually in the Stripe Dashboard pointing at %s, subscribed to this plugin\'s events, then paste its signing secret below.',
            $webhookUrl
        );
    }

    /**
     * Writes just the webhook_secret for one mode — the manual-fallback
     * "Save webhook secret" action (spec Part B) when auto-provisioning
     * couldn't retrieve one.
     */
    public static function save_manual_webhook_secret( string $mode, string $secret ): void {
        $mode          = ( $mode === self::MODE_LIVE ) ? self::MODE_LIVE : self::MODE_TEST;
        $s             = self::get();
        $s[ $mode ]['webhook_secret'] = self::encrypt_value( trim( $secret ) );
        self::save( $s );
    }

    // -------------------------------------------------------------------------
    // Mode switching (the confirm-screen gate lives in the Settings page)
    // -------------------------------------------------------------------------

    public static function switch_mode( string $newMode ): void {
        if ( $newMode !== self::MODE_TEST && $newMode !== self::MODE_LIVE ) {
            return;
        }
        $s         = self::get();
        $s['mode'] = $newMode;
        self::save( $s );
    }

    // -------------------------------------------------------------------------
    // Health
    // -------------------------------------------------------------------------

    /**
     * Problem descriptions for the given (or active) mode — empty array
     * means healthy. Shape matches MyNJILGA_Invoice_Gateway::readiness_errors()
     * exactly (a plain list of strings) so a later phase's gateway and the
     * Invoicing/Setup pages can call this the same way.
     *
     * @return array<int,string>
     */
    public static function health( ?string $mode = null ): array {
        $mode = self::normalize_mode( $mode );

        if ( ! self::is_connected( $mode ) ) {
            return [ 'Stripe is not connected — open Settings > Payments to connect.' ];
        }

        $secretKey = self::decrypted_secret_key( $mode );
        if ( $secretKey === null ) {
            return [ 'Stored Stripe key could not be decrypted — check NJILGA_STRIPE_KEY in wp-config.php.' ];
        }

        $check = self::cached_account_check( $mode, $secretKey );
        if ( ! $check['ok'] ) {
            return [ trim( 'Stripe rejected this key when checked just now. ' . $check['error'] ) ];
        }

        if ( $mode === self::MODE_LIVE && ! $check['charges_enabled'] ) {
            return [ 'The connected Stripe account is not able to accept charges yet — finish its onboarding in the Stripe Dashboard.' ];
        }

        $block     = self::get()[ $mode ];
        $webhookId = (string) $block['webhook_id'];
        if ( $webhookId === '' ) {
            return [ 'No webhook endpoint on file — payments will not update automatically until one is configured.' ];
        }

        $epResp = ( new MyNJILGA_Stripe_Client( $secretKey ) )->request( 'GET', '/webhook_endpoints/' . rawurlencode( $webhookId ) );
        if ( ! $epResp['ok'] ) {
            return [ trim( 'The webhook endpoint on file could not be checked with Stripe. ' . $epResp['error'] ) ];
        }
        if ( (string) ( $epResp['body']['status'] ?? '' ) !== 'enabled' ) {
            return [ 'The webhook endpoint on file is not enabled in Stripe — check Developers → Webhooks in the Stripe Dashboard.' ];
        }

        // The spec's "at least one relevant event received in the last 90
        // days" check is NOT in this list — it lives in
        // health_warnings(), together with the ACH-availability check.
        // Everything returned from here travels on through
        // MyNJILGA_Stripe_Invoice_Gateway::readiness_errors() and BLOCKS
        // invoice creation (MyNJILGA_Invoice_Creator, MyNJILGA_Page_Invoicing),
        // which is exactly wrong for a soft warning: a quiet webhook or an
        // account without ACH must never stop staff invoicing.

        return [];
    }

    /**
     * SOFT, advisory findings for the given (or active) mode — same shape
     * as health() (a plain list of strings) and rendered the same way by
     * the Setup page, but deliberately kept OUT of health() because
     * nothing here is a reason to stop creating invoices. Empty array
     * means "nothing worth mentioning".
     *
     * Both checks are one-way: each warns only on a positive finding and
     * stays silent on anything it cannot establish — a connection with no
     * events yet because it was made this morning, an API call that failed
     * or wasn't permitted — so a healthy-but-unknowable connection never
     * raises a false alarm.
     *
     * @return array<int,string>
     */
    public static function health_warnings( ?string $mode = null ): array {
        $mode = self::normalize_mode( $mode );

        // Nothing advisory to add about a connection health() already has
        // something louder to say about.
        if ( ! self::is_connected( $mode ) ) {
            return [];
        }
        $secretKey = self::decrypted_secret_key( $mode );
        if ( $secretKey === null || $secretKey === '' ) {
            return [];
        }

        $warnings = [];

        $silence = self::event_silence_warning( $mode );
        if ( $silence !== '' ) {
            $warnings[] = $silence;
        }

        if ( self::cached_ach_check( $mode, $secretKey )['state'] === 'off' ) {
            $warnings[] = 'ACH Direct Debit is switched off in this account\'s Stripe payment method settings, but every invoice this plugin creates asks for card AND ACH — so firms may only ever see the card option on the hosted invoice page. Check Settings → Payment methods in the Stripe Dashboard. Invoices can still be created.';
        }

        return $warnings;
    }

    /**
     * The "has Stripe gone quiet?" half of health_warnings(): warn when
     * nothing has landed in njilga_stripe_events for this mode in the last
     * EVENT_SILENCE_DAYS days. '' = nothing to say.
     *
     * A mode with no events at all is the normal state of a connection
     * made five minutes ago, so that case warns only once the CONNECTION
     * itself is older than the window — and a mode whose connected_at was
     * never recorded proves nothing either way, so it stays silent rather
     * than guess.
     *
     * The cutoff is built exactly the way
     * MyNJILGA_Stripe_Events_Table::prune_older_than() builds its own —
     * strtotime() over current_time( 'timestamp' ) — so it lines up with
     * how received_at (and connected_at) were written in the first place.
     */
    private static function event_silence_warning( string $mode ): string {
        $cutoff = strtotime( '-' . self::EVENT_SILENCE_DAYS . ' days', current_time( 'timestamp' ) );
        $lastAt = MyNJILGA_Stripe_Events_Table::last_received_at( $mode === self::MODE_LIVE );

        if ( $lastAt === null ) {
            $connectedAt = (string) self::get()[ $mode ]['connected_at'];
            $connectedTs = ( $connectedAt !== '' ) ? strtotime( $connectedAt ) : false;
            if ( $connectedTs === false || $connectedTs >= $cutoff ) {
                return '';
            }
            return sprintf(
                'No Stripe webhook event has ever been received in %s mode, and this key has been connected since %s. Payments may not be updating automatically — check the endpoint under Developers → Webhooks in the Stripe Dashboard. Invoices can still be created.',
                $mode,
                $connectedAt
            );
        }

        $lastTs = strtotime( $lastAt );
        if ( $lastTs === false || $lastTs >= $cutoff ) {
            return '';
        }

        return sprintf(
            'No Stripe webhook event has arrived in the last %d days — the most recent was %s. Payments may not be updating automatically — check the endpoint under Developers → Webhooks in the Stripe Dashboard. Invoices can still be created.',
            self::EVENT_SILENCE_DAYS,
            $lastAt
        );
    }

    /**
     * GET /v1/account, cached for HEALTH_CACHE_TTL_SECONDS per mode so
     * health() stays cheap to call often.
     *
     * @return array{ok:bool,charges_enabled:bool,error:string}
     */
    private static function cached_account_check( string $mode, string $secretKey ): array {
        $transientKey = self::HEALTH_TRANSIENT_PREFIX . $mode;
        $cached       = get_transient( $transientKey );
        if ( is_array( $cached ) && isset( $cached['ok'] ) ) {
            return $cached;
        }

        $resp   = ( new MyNJILGA_Stripe_Client( $secretKey ) )->request( 'GET', '/account' );
        $result = [
            'ok'              => (bool) $resp['ok'],
            'charges_enabled' => $resp['ok'] ? ! empty( $resp['body']['charges_enabled'] ) : false,
            'error'           => $resp['ok'] ? '' : (string) $resp['error'],
        ];
        set_transient( $transientKey, $result, self::HEALTH_CACHE_TTL_SECONDS );
        return $result;
    }

    /**
     * GET /v1/payment_method_configurations — is `us_bank_account` (ACH)
     * actually available on this account? MyNJILGA_Stripe_Invoice_Gateway
     * asks Stripe for card AND us_bank_account on every invoice it
     * creates, so an account without ACH quietly serves a card-only
     * hosted invoice page with nothing said anywhere.
     *
     * Cached the same way cached_account_check() caches its own probe —
     * one transient per mode under HEALTH_TRANSIENT_PREFIX, same
     * HEALTH_CACHE_TTL_SECONDS, failures cached too — so
     * health_warnings() stays as cheap to call on every page render as
     * health() is. The 'ach_' segment only keeps the two keys apart;
     * there is deliberately no second caching mechanism here.
     *
     * @return array{state:string,error:string} state: 'on' | 'off' | 'unknown'
     */
    private static function cached_ach_check( string $mode, string $secretKey ): array {
        $transientKey = self::HEALTH_TRANSIENT_PREFIX . 'ach_' . $mode;
        $cached       = get_transient( $transientKey );
        if ( is_array( $cached ) && isset( $cached['state'] ) ) {
            return $cached;
        }

        $resp   = ( new MyNJILGA_Stripe_Client( $secretKey ) )->request( 'GET', '/payment_method_configurations' );
        $result = [
            // A call that failed says NOTHING about whether ACH is on —
            // a restricted key without read access to this resource, a
            // network blip and a genuinely ACH-less account must not look
            // alike. 'unknown' never warns.
            'state' => $resp['ok'] ? self::ach_state_from_body( (array) $resp['body'] ) : 'unknown',
            'error' => $resp['ok'] ? '' : (string) $resp['error'],
        ];
        set_transient( $transientKey, $result, self::HEALTH_CACHE_TTL_SECONDS );
        return $result;
    }

    /**
     * Reads 'on' / 'off' / 'unknown' out of a
     * /v1/payment_method_configurations response. Each configuration
     * carries one entry per payment method — `available` (can this
     * account offer it at all) plus `display_preference.value` (is it
     * switched on) — and an account may have several configurations, so
     * ACH being live in ANY of them is enough for the invoices this
     * plugin creates.
     *
     * Everything unrecognizable is 'unknown', never 'off': an account
     * with no configurations at all falls back to Stripe's own defaults
     * (nothing to read here), and a payload that doesn't mention
     * us_bank_account is API drift rather than evidence ACH is missing.
     *
     * @param array<string,mixed> $body
     */
    private static function ach_state_from_body( array $body ): string {
        $configs = ( isset( $body['data'] ) && is_array( $body['data'] ) ) ? $body['data'] : [];
        if ( empty( $configs ) ) {
            return 'unknown';
        }

        $sawKey = false;
        foreach ( $configs as $config ) {
            if ( ! is_array( $config ) || ! isset( $config['us_bank_account'] ) || ! is_array( $config['us_bank_account'] ) ) {
                continue;
            }
            $sawKey     = true;
            $entry      = $config['us_bank_account'];
            $switchedOn = ( (string) ( $entry['display_preference']['value'] ?? '' ) === 'on' );
            // `available` is absent on some payload shapes; treat only an
            // explicit false as "the account can't offer this".
            $available  = ! array_key_exists( 'available', $entry ) || ! empty( $entry['available'] );
            if ( $switchedOn && $available ) {
                return 'on';
            }
        }

        return $sawKey ? 'off' : 'unknown';
    }
}
