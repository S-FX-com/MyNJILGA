<?php
/**
 * My NJILGA → Settings (spec §3).
 *
 * One page, one tab per concern (?tab=…). Each tab is its own small form,
 * saved through admin-post, and a tab saves ONLY what it shows — every other
 * setting is carried over untouched (see TAB_KEYS and handle_save()):
 *
 *   categories    The category table (ORDERED: precedence; tag → price → WP
 *                 role → tier-eligible, with the per-rank tier table), the
 *                 default category, and the assessment.
 *   billing       The tags billing reads and writes (inactive/paid/unpaid and
 *                 the per-year patterns) and per-firm billing-mode overrides.
 *   email         The invoice email: recipients, wording, preview, send test
 *                 (MyNJILGA_Page_Invoice_Email owns the form and its handler).
 *   joining       Online joining ([njilga_join]).
 *   applications  The application form and the mid-year join policy.
 *   payments      The Stripe connection (its own handlers, below).
 *   advanced      Invoice batch size, the downgrade sweep, and Reset.
 *
 * Next to every mapped tag the page shows a live check (does the tag exist in
 * FluentCRM?), so the "confirm the exact slugs against the live instance"
 * setup step is a glance at this page, not a database query.
 */
class MyNJILGA_Page_Settings {

    const ACTION_SAVE  = 'my_njilga_settings_save';
    const ACTION_RESET = 'my_njilga_settings_reset';

    /** The tabs, in order: slug => label. */
    const TABS = [
        'categories'   => 'Categories & Pricing',
        'billing'      => 'Tags & Billing',
        'email'        => 'Invoice Email',
        'joining'      => 'Online Joining',
        'applications' => 'Applications',
        'payments'     => 'Payments',
        'advanced'     => 'Advanced',
    ];

    /** What each tab's page header says. */
    const TAB_BLURBS = [
        'categories'   => 'Who belongs to which membership category, what each is charged, and the assessment. FluentCRM tags decide the category; WordPress roles follow payment and are never an input to pricing. Prices are exactly what the Stripe invoice charges.',
        'billing'      => 'The FluentCRM tags billing reads and writes, and any firm that is billed differently from the default.',
        'email'        => 'Who receives the dues invoice email, what it says, and a way to send yourself a test of exactly what you have typed.',
        'joining'      => 'Joining and paying online through the [njilga_join] shortcode.',
        'applications' => 'The membership application form, and what happens when one is approved.',
        'payments'     => 'Connect Stripe, choose which mode is active, and set the defaults every Stripe invoice uses.',
        'advanced'     => 'How many invoices are created per background job, what the downgrade sweep removes, and resetting everything.',
    ];

    /**
     * The `general` settings each tab owns, so a tab saves exactly its own and
     * leaves every other tab's values alone. The categories tab also owns the
     * `categories` and `assessment` structures and `default_category`; the
     * billing tab owns `firm_overrides`; the email tab's keys live in
     * MyNJILGA_Page_Invoice_Email::KEYS. tests/SettingsTabsTest.php asserts
     * that every general setting belongs to exactly one tab, so a new setting
     * can't be added without a home (or saved by two tabs).
     */
    const TAB_KEYS = [
        'categories'   => [ 'default_category' ],
        'billing'      => [ 'inactive_tag', 'paid_tag', 'unpaid_tag', 'year_paid_tag_pattern', 'year_unpaid_tag_pattern', 'assessment_paid_pattern' ],
        'joining'      => [
            'join_enabled', 'join_notify_email', 'join_max_colleagues', 'join_firm_match', 'join_next_year_from', 'join_allow_ach',
            'join_stripe_invoice', 'join_invite_expiry_days', 'join_prelaw_tag', 'join_municipalities', 'join_success_text',
            'cf_attorney_id', 'cf_bar_admission_date', 'cf_municipality', 'cf_nj_county', 'cf_school', 'cf_student_status', 'cf_mailing_phone',
        ],
        'applications' => [ 'mid_year_join_policy', 'pending_tag', 'rejected_tag', 'application_notify_email', 'application_success_text' ],
        'advanced'     => [ 'downgrade_remove_roles', 'batch_size' ],
    ];

    /** The tabs handle_save() serves (email and payments have their own handlers). */
    const SAVE_TABS = [ 'categories', 'billing', 'joining', 'applications', 'advanced' ];

    // Payments tab (Stripe connection/credential layer — see
    // includes/invoicing/class-stripe-connection.php).
    const ACTION_PAYMENTS_SAVE       = 'my_njilga_stripe_settings_save';
    const ACTION_STRIPE_CONNECT      = 'my_njilga_stripe_connect';
    const ACTION_STRIPE_WEBHOOK_SAVE = 'my_njilga_stripe_webhook_secret_save';
    const ACTION_STRIPE_SWITCH_MODE  = 'my_njilga_stripe_switch_mode';

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    /**
     * The tab strip is MyNJILGA_Admin_UI::nav_tabs() over a ?tab= query arg
     * (the mechanism MyNJILGA_Page_Firms::render_scope_tabs() also uses): each
     * tab renders an entirely different form, never two at once. A link from
     * before the tabs existed (?tab=dues) lands on the first tab.
     */
    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        $tab = self::current_tab();

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        MyNJILGA_Admin_UI::page_header( 'Settings', self::TAB_BLURBS[ $tab ] );
        self::render_settings_tabs( $tab );

        if ( $tab === 'payments' ) {
            $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : '';
            if ( $view === 'switch-mode' ) {
                self::render_switch_mode_confirm();
            } else {
                self::render_payments_tab();
            }
        } elseif ( $tab === 'email' ) {
            MyNJILGA_Page_Invoice_Email::render_tab();
        } else {
            self::render_dues_tab( $tab );
        }

        echo '</div>';
    }

    private static function current_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
        if ( $tab === 'dues' ) {
            return 'categories'; // the one tab this used to have
        }
        return isset( self::TABS[ $tab ] ) ? $tab : 'categories';
    }

    /** Where a tab lives. @param array<string,mixed> $args */
    public static function tab_url( string $tab, array $args = [] ): string {
        $args['page'] = MyNJILGA_Admin_Menu::SLUG_SETTINGS;
        $args['tab']  = $tab;
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    private static function render_settings_tabs( string $active ): void {
        $tabs = [];
        foreach ( self::TABS as $slug => $label ) {
            $tabs[] = [ 'label' => $label, 'url' => self::tab_url( $slug ), 'active' => $active === $slug ];
        }
        MyNJILGA_Admin_UI::nav_tabs( $tabs );
    }

    private static function render_notices(): void {
        if ( ! empty( $_GET['stale'] ) ) {
            MyNJILGA_Admin_UI::callout( '<strong>Nothing was saved.</strong> That form is out of date (this page was reorganised into tabs while it was open). Make your change again here.', 'warning' );
        }
        if ( ! empty( $_GET['saved'] ) ) {
            MyNJILGA_Admin_UI::callout( 'Settings saved.', 'success' );
        }
        if ( ! empty( $_GET['reset'] ) ) {
            MyNJILGA_Admin_UI::callout( 'Settings reset to the seeded defaults.', 'success' );
        }
        if ( ! empty( $_GET['member_tag_rejected'] ) ) {
            $refused = array_filter( array_map( 'sanitize_title', explode( ',', (string) wp_unslash( $_GET['member_tag_rejected'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            MyNJILGA_Admin_UI::callout(
                sprintf(
                    '<strong>Member tag not saved:</strong> %s belongs to a <em>different</em> category, or is a paid/unpaid or assessment tag. Handing it out on payment would change who is charged what. A category&rsquo;s <em>own</em> tag is fine; use that or a separate tag. The rest of your changes were saved.',
                    implode( ', ', array_map( static function ( $t ) { return '<code>' . esc_html( $t ) . '</code>'; }, $refused ) )
                ),
                'warning'
            );
        }
        if ( isset( $_GET['role_sync'] ) ) {
            $n = (int) $_GET['role_sync'];
            MyNJILGA_Admin_UI::callout(
                sprintf(
                    '<strong>Role mapping changed</strong> — role sync started for %d paid member%s. Progress and results are on <a href="%s">Setup → WordPress role sync</a>.',
                    $n,
                    $n === 1 ? '' : 's',
                    esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
                ),
                'info'
            );
        }
    }

    /** One of the five Dues & Billing tabs that save through handle_save(). */
    private static function render_dues_tab( string $tab ): void {
        $s     = MyNJILGA_Dues_Settings::get();
        $tags  = MyNJILGA_Members_Data::fluentcrm_active() ? MyNJILGA_Tags::all_tags() : [];

        self::render_notices();
        self::render_tag_datalist( $tags );

        printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
        printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION_SAVE ) );
        printf( '<input type="hidden" name="tab" value="%s">', esc_attr( $tab ) );
        wp_nonce_field( self::ACTION_SAVE );

        switch ( $tab ) {
            case 'billing':
                self::render_tag_settings( $s, $tags );
                self::render_firm_overrides( $s );
                break;
            case 'joining':
                self::render_joining( $s, $tags );
                break;
            case 'applications':
                self::render_applications( $s, $tags );
                break;
            case 'advanced':
                self::render_advanced( $s );
                break;
            default: // categories
                self::render_default_category( $s );
                self::render_categories( $s, $tags, self::wp_roles() );
                self::render_assessment( $s, $tags );
        }

        echo '<div class="njilga-actions"><button type="submit" class="njilga-btn njilga-btn-primary njilga-btn-lg">Save changes</button></div>';
        echo '</form>';

        if ( $tab === 'advanced' ) {
            self::render_reset_card();
        }
    }

    private static function render_reset_card(): void {
        echo '<div class="njilga-danger-card" style="max-width:640px">';
        echo '<div class="njilga-danger-head">' . MyNJILGA_Admin_UI::icon( 'alert' ) . '<h2>Reset settings</h2></div>';
        echo '<p>Restores every setting on the Categories &amp; Pricing, Tags &amp; Billing, Invoice Email, Online Joining, Applications and Advanced tabs to the seeded defaults. Per-firm billing overrides and your email wording are cleared too. The Stripe connection (Payments) and invoices already generated are not touched.</p>';
        echo MyNJILGA_Admin_UI::action_form(
            self::ACTION_RESET,
            'Reset to defaults',
            [],
            'danger',
            '',
            'Reset every Dues & Billing setting (all tabs except Payments) to the seeded defaults? Firm overrides and the email wording are cleared too.'
        );
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Payments tab (Stripe)
    // -------------------------------------------------------------------------

    private static function payments_url( array $args = [] ): string {
        $args['page'] = MyNJILGA_Admin_Menu::SLUG_SETTINGS;
        $args['tab']  = 'payments';
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    private static function render_payments_tab(): void {
        $s          = MyNJILGA_Stripe_Connection::get();
        $activeMode = (string) $s['mode'];

        self::render_payments_notices();

        if ( $activeMode === MyNJILGA_Stripe_Connection::MODE_TEST ) {
            MyNJILGA_Admin_UI::callout( '<strong>Test mode takes online joins too.</strong> Anyone joining on a Membership page pays with a Stripe test card and no real money moves — but their membership, account, FluentCRM tags and firm changes are real. Right for a staging copy; on the live site, switch back to Live as soon as you\'re done trying something.', 'warning' );
        }

        echo '<div class="njilga-cols-2">';
        self::render_stripe_mode_card( MyNJILGA_Stripe_Connection::MODE_TEST, $s, $activeMode );
        self::render_stripe_mode_card( MyNJILGA_Stripe_Connection::MODE_LIVE, $s, $activeMode );
        echo '</div>';

        self::render_mode_switch_banner( $activeMode );
        self::render_payment_flat_settings( $s );

        if ( ! MyNJILGA_Stripe_Connection::encryption_active() ) {
            self::render_encryption_note();
        }
    }

    private static function render_payments_notices(): void {
        $get = wp_unslash( $_GET );

        if ( ! empty( $get['connect_error'] ) ) {
            MyNJILGA_Admin_UI::callout( '<strong>Could not connect:</strong> ' . esc_html( (string) $get['connect_error'] ), 'error' );
        }
        if ( ! empty( $get['connected'] ) ) {
            $mode = ( ( $get['mode'] ?? '' ) === MyNJILGA_Stripe_Connection::MODE_LIVE ) ? 'Live' : 'Test';
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>%s mode connected.</strong>', esc_html( $mode ) ), 'success' );
        }
        if ( ! empty( $get['used_sk'] ) ) {
            MyNJILGA_Admin_UI::callout( 'That key connected, but it&rsquo;s a full secret key. A <strong>restricted key</strong> (starting <code>rk_</code>) limited to the permissions listed below is strongly preferred — replace it when convenient.', 'warning' );
        }
        if ( ! empty( $get['connect_warning'] ) ) {
            MyNJILGA_Admin_UI::callout( esc_html( (string) $get['connect_warning'] ), 'warning' );
        }
        if ( ! empty( $get['webhook_saved'] ) ) {
            MyNJILGA_Admin_UI::callout( 'Webhook secret saved.', 'success' );
        }
        if ( ! empty( $get['webhook_invalid'] ) ) {
            MyNJILGA_Admin_UI::callout( 'That doesn&rsquo;t look like a Stripe webhook signing secret (it starts <code>whsec_</code>), so nothing was changed. Copy it from Stripe Dashboard &rarr; Developers &rarr; Webhooks &rarr; the endpoint &rarr; Signing secret.', 'warning' );
        }
        if ( ! empty( $get['pmt_saved'] ) ) {
            MyNJILGA_Admin_UI::callout( 'Payment settings saved.', 'success' );
        }
        if ( ! empty( $get['switched'] ) ) {
            $mode = ( $get['switched'] === MyNJILGA_Stripe_Connection::MODE_LIVE ) ? 'Live' : 'Test';
            MyNJILGA_Admin_UI::callout( sprintf( 'Now billing in <strong>%s mode</strong>.', esc_html( $mode ) ), 'success' );
        }
    }

    /**
     * A collapsed, low-key footnote at the bottom of the Payments tab
     * (not a warning callout at the top) for the optional at-rest
     * encryption of the stored Stripe secrets. The Stripe keys
     * themselves always come from this tab; NJILGA_STRIPE_KEY is only a
     * site-owner passphrase that encrypts them before they hit the
     * options table.
     */
    private static function render_encryption_note(): void {
        echo '<details class="njilga-details">';
        printf( '<summary>%s Optional: encrypt the stored Stripe keys at rest</summary>', MyNJILGA_Admin_UI::icon( 'lock' ) );
        echo '<p class="njilga-help">The keys you paste on this tab are saved to the WordPress database as entered. If you want them encrypted there, add a passphrase to <code>wp-config.php</code> on the server — nothing changes on this tab, and the keys still come only from here.</p>';
        echo '<p class="njilga-help">1. Generate a passphrase once, at a terminal: <code>php -r "echo bin2hex(random_bytes(32));"</code></p>';
        echo '<p class="njilga-help">2. Add the resulting 64-character hex string to wp-config.php: <code>define( \'NJILGA_STRIPE_KEY\', \'&lt;paste the hex string here&gt;\' );</code></p>';
        echo '<p class="njilga-help">3. Re-paste the key(s) on this tab so they are stored encrypted. Keep the passphrase: without it, encrypted keys cannot be read.</p>';
        echo '</details>';
    }

    /**
     * @param array<string,mixed> $s MyNJILGA_Stripe_Connection::get()
     */
    private static function render_stripe_mode_card( string $mode, array $s, string $activeMode ): void {
        $block     = $s[ $mode ];
        $connected = MyNJILGA_Stripe_Connection::is_connected( $mode );
        $label     = ( $mode === MyNJILGA_Stripe_Connection::MODE_LIVE ) ? 'Live mode' : 'Test mode';

        echo '<div class="njilga-card njilga-card-pad">';
        echo '<div class="njilga-row-between">';
        printf( '<h3 class="njilga-card-title">%s</h3>', esc_html( $label ) );
        echo '<span>';
        echo $connected ? MyNJILGA_Admin_UI::pill( 'Connected', 'success' ) : MyNJILGA_Admin_UI::pill( 'Not connected', 'muted' );
        if ( $mode === $activeMode ) {
            echo ' ' . MyNJILGA_Admin_UI::pill( 'Active', 'info' );
        }
        echo '</span>';
        echo '</div>';

        if ( $connected ) {
            echo '<div class="njilga-tablewrap"><table class="njilga-table njilga-kv njilga-table-compact"><tbody>';
            printf( '<tr><th>Account</th><td>%s</td></tr>', esc_html( $block['account_name'] !== '' ? $block['account_name'] : $block['account_id'] ) );
            printf( '<tr><th>Key</th><td><code>%s</code></td></tr>', esc_html( MyNJILGA_Stripe_Connection::masked_key( $mode ) ) );
            printf( '<tr><th>Last verified</th><td>%s</td></tr>', esc_html( $block['last_verified_at'] !== '' ? $block['last_verified_at'] : '—' ) );
            echo '</tbody></table></div>';

            if ( $block['webhook_id'] === '' || $block['webhook_secret'] === '' ) {
                self::render_manual_webhook_fallback( $mode );
            }

            printf(
                '<details class="njilga-details"><summary>%s Replace key</summary>%s</details>',
                MyNJILGA_Admin_UI::icon( 'refresh' ),
                self::connect_form_html( $mode )
            );
        } else {
            echo self::connect_form_html( $mode );
        }

        echo '</div>';
    }

    private static function connect_form_html( string $mode ): string {
        $out = sprintf(
            '<p class="njilga-help">Paste a key from <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener noreferrer">dashboard.stripe.com/apikeys</a>. Restricted keys (<code>rk_%1$s_&hellip;</code>) are preferred; full secret keys (<code>sk_%1$s_&hellip;</code>) are accepted too.</p><ul class="njilga-list">',
            esc_html( $mode )
        );
        foreach ( self::stripe_permission_checklist() as $perm ) {
            $out .= '<li>' . esc_html( $perm ) . '</li>';
        }
        $out .= '</ul>';

        $out .= sprintf(
            '<form method="post" action="%s" class="njilga-field">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="mode" value="%s">
                %s
                <input type="text" name="secret_key" class="njilga-full" autocomplete="off" spellcheck="false" placeholder="rk_%s_&hellip; or sk_%s_&hellip;">
                <div class="njilga-actions"><button type="submit" class="njilga-btn njilga-btn-primary">Verify &amp; Connect</button></div>
            </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_STRIPE_CONNECT ),
            esc_attr( $mode ),
            wp_nonce_field( self::ACTION_STRIPE_CONNECT, '_wpnonce', true, false ),
            esc_attr( $mode ),
            esc_attr( $mode )
        );

        return $out;
    }

    /**
     * @return array<int,string>
     */
    private static function stripe_permission_checklist(): array {
        return [
            'Customers: Write',
            'Charges: Read',
            'PaymentIntents: Read',
            'Products/Prices: Write (only needed if inline-line-item mode is ever changed)',
            'Invoices: Write',
            'Credit notes: Read',
            'Webhook Endpoints: Write',
            'Checkout Sessions: Write (online joining — [njilga_join])',
        ];
    }

    private static function render_manual_webhook_fallback( string $mode ): void {
        $webhookUrl = rest_url( 'njilga/v1/stripe-webhook' );

        echo '<div class="njilga-callout njilga-callout-warning">';
        echo '<p><strong>No webhook secret on file for this mode.</strong> Add a webhook endpoint in the Stripe Dashboard (Developers &rarr; Webhooks) pointing at:</p>';
        printf( '<p><code>%s</code></p>', esc_html( $webhookUrl ) );
        printf(
            '<form method="post" action="%s" class="njilga-field">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="mode" value="%s">
                %s
                <input type="text" name="webhook_secret" class="njilga-full" autocomplete="off" spellcheck="false" placeholder="whsec_&hellip;">
                <div class="njilga-actions"><button type="submit" class="njilga-btn njilga-btn-outline njilga-btn-sm">Save webhook secret</button></div>
            </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_STRIPE_WEBHOOK_SAVE ),
            esc_attr( $mode ),
            wp_nonce_field( self::ACTION_STRIPE_WEBHOOK_SAVE, '_wpnonce', true, false )
        );
        echo '</div>';
    }

    private static function render_mode_switch_banner( string $activeMode ): void {
        $target = ( $activeMode === MyNJILGA_Stripe_Connection::MODE_LIVE ) ? MyNJILGA_Stripe_Connection::MODE_TEST : MyNJILGA_Stripe_Connection::MODE_LIVE;

        echo '<div class="njilga-banner">';
        echo '<div>';
        printf( '<div class="njilga-banner-title">Active mode: %s</div>', esc_html( ucfirst( $activeMode ) ) );
        echo '<div class="njilga-banner-desc">Every new invoice and online join bills through this mode&rsquo;s Stripe account. Switching modes never moves existing invoice rows or Stripe objects between modes.</div>';
        echo '</div>';
        printf(
            '<a class="njilga-btn njilga-btn-outline" href="%s">Switch to %s mode</a>',
            esc_url( self::payments_url( [ 'view' => 'switch-mode', 'target' => $target ] ) ),
            esc_html( ucfirst( $target ) )
        );
        echo '</div>';
    }

    /**
     * The confirmation screen for switching the active mode — mirrors
     * MyNJILGA_Page_Invoicing::render_downgrade_confirm() exactly:
     * a distinct ?view=switch-mode URL, njilga-danger-card styling, an
     * explicit acknowledgement checkbox, a real POST to complete it.
     */
    private static function render_switch_mode_confirm(): void {
        $target = isset( $_GET['target'] ) ? sanitize_key( wp_unslash( (string) $_GET['target'] ) ) : '';

        if ( $target !== MyNJILGA_Stripe_Connection::MODE_TEST && $target !== MyNJILGA_Stripe_Connection::MODE_LIVE ) {
            MyNJILGA_Admin_UI::callout( 'Nothing to confirm — choose Switch to Test/Live mode from the Payments tab.', 'warning' );
            return;
        }

        $current = MyNJILGA_Stripe_Connection::active_mode();
        if ( $target === $current ) {
            MyNJILGA_Admin_UI::callout( sprintf( 'Already in %s mode.', esc_html( ucfirst( $current ) ) ), 'info' );
            return;
        }

        printf( '<p class="njilga-back"><a href="%s">&larr; Back to Payments</a></p>', esc_url( self::payments_url() ) );
        printf( '<h1 class="njilga-title njilga-title-danger">Confirm switching to %s mode</h1>', esc_html( ucfirst( $target ) ) );

        echo '<div class="njilga-danger-card"><p><strong>What switching modes means:</strong></p><ul class="njilga-list">';
        printf( '<li>Every new dues invoice bills through the %s Stripe account from now on.</li>', esc_html( ucfirst( $target ) ) );
        if ( $target === MyNJILGA_Stripe_Connection::MODE_TEST ) {
            echo '<li><strong>Online joins switch too:</strong> visitors on a Membership page pay with a Stripe test card and no real money moves, but their membership, account and FluentCRM tags are real.</li>';
        } else {
            echo '<li><strong>Online joins switch too:</strong> visitors on a Membership page pay real money again. A join already started in Test finishes in Test.</li>';
        }
        printf( '<li>Invoice rows created while in %s mode will be hidden from the Invoicing and Payments workspaces until you switch back to %s mode.</li>', esc_html( ucfirst( $current ) ), esc_html( ucfirst( $current ) ) );
        printf(
            '<li>Stripe objects — customers, invoices, payment intents — do <strong>not</strong> carry over between Test and Live. Nothing already created in %s mode exists in %s mode.</li>',
            esc_html( ucfirst( $current ) ),
            esc_html( ucfirst( $target ) )
        );
        if ( ! MyNJILGA_Stripe_Connection::is_connected( $target ) ) {
            printf( '<li><strong>%s mode is not connected yet</strong> — invoicing will not work in this mode until you connect it on the Payments tab.</li>', esc_html( ucfirst( $target ) ) );
        }
        echo '</ul></div>';

        printf(
            '<form method="post" action="%s" class="njilga-confirm-form">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="target" value="%s">
                %s
                <label class="njilga-ack"><input type="checkbox" name="acknowledge" value="1" required> I understand invoice visibility and Stripe objects do not carry over between modes, and want to switch to %s mode.</label>
                <div class="njilga-confirm-actions">
                    <button type="submit" class="njilga-btn njilga-btn-danger">Switch to %s mode</button>
                    <a class="njilga-btn njilga-btn-outline" href="%s">Cancel</a>
                </div>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_STRIPE_SWITCH_MODE ),
            esc_attr( $target ),
            wp_nonce_field( self::ACTION_STRIPE_SWITCH_MODE, '_wpnonce', true, false ),
            esc_html( ucfirst( $target ) ),
            esc_html( ucfirst( $target ) ),
            esc_url( self::payments_url() )
        );
    }

    /**
     * @param array<string,mixed> $s MyNJILGA_Stripe_Connection::get()
     */
    private static function render_payment_flat_settings( array $s ): void {
        MyNJILGA_Admin_UI::section( 'Invoice defaults', 'Applied to every Stripe invoice this plugin creates, in either mode.' );

        printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
        printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION_PAYMENTS_SAVE ) );
        wp_nonce_field( self::ACTION_PAYMENTS_SAVE );

        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';

        echo '<tr><th scope="row">Currency</th><td>';
        printf( '<input type="text" value="%s" class="njilga-input-sm" readonly>', esc_attr( strtoupper( (string) $s['currency'] ) ) );
        echo '<p class="njilga-help">Fixed to USD — this organization bills in US dollars only.</p></td></tr>';

        printf(
            '<tr><th scope="row"><label for="pmt-days">Minimum days until due</label></th><td><input type="number" min="1" max="365" id="pmt-days" name="days_until_due" value="%d" class="njilga-input-sm"><p class="njilga-help">Dues invoices fall due on <strong>31 December of the year they are raised in</strong>, so a firm reads one deadline: pay before the membership year starts. This is only the safety floor — an invoice raised so late in December that year-end is nearer than this many days gets that many days instead.</p></td></tr>',
            (int) $s['days_until_due']
        );

        printf(
            '<tr><th scope="row"><label for="pmt-footer">Footer text</label></th><td><textarea id="pmt-footer" name="footer" rows="3" class="large-text">%s</textarea><p class="njilga-help">Printed on every Stripe invoice.</p></td></tr>',
            esc_textarea( (string) $s['footer'] )
        );

        printf(
            '<tr><th scope="row"><label for="pmt-remittance">Remittance address (for check payments)</label></th><td><textarea id="pmt-remittance" name="remittance_address" rows="3" class="large-text">%s</textarea><p class="njilga-help">Printed in the unpaid-invoice email so staff can edit it without a deploy.</p></td></tr>',
            esc_textarea( (string) $s['remittance_address'] )
        );

        echo '</tbody></table></div>';
        echo '<div class="njilga-actions"><button type="submit" class="njilga-btn njilga-btn-primary">Save Payment Settings</button></div>';
        echo '</form>';
    }

    // -------------------------------------------------------------------------
    // Sections
    // -------------------------------------------------------------------------

    private static function render_default_category( array $s ): void {
        $g = $s['general'];
        MyNJILGA_Admin_UI::section( 'Default category', 'Who is billed when a contact carries none of the category tags in the table below.' );
        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';
        echo '<tr><th scope="row"><label for="g-default_category">Default category</label></th><td>';
        echo '<select id="g-default_category" name="general[default_category]" class="njilga-select"><option value="">— Not billed (list as an exception) —</option>';
        foreach ( $s['categories'] as $cat ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $cat['key'] ), selected( $g['default_category'], $cat['key'], false ), esc_html( $cat['label'] ) );
        }
        echo '</select><p class="njilga-help">Contacts carrying none of the category tags below fall into this category. Seeded to Professional so an untagged roster bills the way it does today.</p></td></tr>';
        echo '</tbody></table></div>';
    }

    private static function render_tag_settings( array $s, array $tags ): void {
        $g = $s['general'];
        MyNJILGA_Admin_UI::section( 'Tags', 'The FluentCRM tags billing reads (inactive) and writes (paid, unpaid and the per-year ones).' );
        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';
        self::text_row( 'general[inactive_tag]', 'Inactive override tag', $g['inactive_tag'], 'Tag slug. A contact carrying it is billed nothing this cycle — no dues, no assessment — whatever else they carry.', $tags );
        self::text_row( 'general[paid_tag]', 'Evergreen "paid" tag', $g['paid_tag'], 'Applied to every member of a paid dues invoice; read by every report in this plugin.', $tags );
        self::text_row( 'general[unpaid_tag]', 'Evergreen "unpaid" tag', $g['unpaid_tag'], 'Applied by the downgrade sweep; removed on payment.', $tags );
        self::text_row( 'general[year_paid_tag_pattern]', 'Year paid tag pattern', $g['year_paid_tag_pattern'], 'Created on demand. {year} is replaced, e.g. "Dues Paid 2027".' );
        self::text_row( 'general[year_unpaid_tag_pattern]', 'Year unpaid tag pattern', $g['year_unpaid_tag_pattern'], 'Applied by the downgrade sweep.' );
        self::text_row( 'general[assessment_paid_pattern]', 'Assessment paid tag pattern', $g['assessment_paid_pattern'], 'Applied when an assessment-only invoice (split-assessment billing) is paid.' );
        echo '</tbody></table></div>';
    }

    private static function render_applications( array $s, array $tags ): void {
        $g = $s['general'];
        MyNJILGA_Admin_UI::section( 'Applications', 'The <code>[njilga_membership_application]</code> form and the review queue under Applications.' );
        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';

        echo '<tr><th scope="row">Mid-year join policy</th><td><div class="njilga-radio-list">';
        foreach ( MyNJILGA_Dues_Settings::join_policy_labels() as $val => $label ) {
            printf( '<label class="njilga-check-label"><input type="radio" name="general[mid_year_join_policy]" value="%s"%s> <span>%s</span></label>', esc_attr( $val ), checked( $g['mid_year_join_policy'], $val, false ), esc_html( $label ) );
        }
        echo '</div><p class="njilga-help">Applied when an application is approved (Applications page). Final policy is still to be confirmed by NJILGA (spec §3.5) — the default is "free until next cycle".</p></td></tr>';

        self::text_row( 'general[pending_tag]', 'Pending-approval tag', $g['pending_tag'], 'Applied to an applicant on submission; removed on approval/rejection. Contacts carrying it are never invoiced.', $tags );
        self::text_row( 'general[rejected_tag]', 'Rejected tag', $g['rejected_tag'], 'Applied when an application is rejected.', $tags );
        self::text_row( 'general[application_notify_email]', 'Notify staff on new application', $g['application_notify_email'], 'Comma-separated. Blank = the site admin email.' );
        echo '<tr><th scope="row"><label for="g-success">Application success message</label></th><td>';
        printf( '<textarea id="g-success" name="general[application_success_text]" rows="2" class="large-text">%s</textarea>', esc_textarea( (string) $g['application_success_text'] ) );
        echo '<p class="njilga-help">Shown to the applicant after the <code>[njilga_membership_application]</code> form is submitted.</p></td></tr>';

        echo '</tbody></table></div>';
    }

    private static function render_advanced( array $s ): void {
        $g = $s['general'];
        MyNJILGA_Admin_UI::section( 'Invoice creation and the downgrade sweep' );
        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';
        self::text_row( 'general[batch_size]', 'Invoice creation batch size', (string) (int) $g['batch_size'], 'Invoices created per background job (Action Scheduler). Default 25; 10 is a safer start on slow hosting.' );

        echo '<tr><th scope="row">Downgrade sweep</th><td>';
        printf( '<label class="njilga-check-label"><input type="checkbox" name="general[downgrade_remove_roles]" value="1"%s> <span>Remove WordPress membership roles (every role mapped on Categories &amp; Pricing, now or before) from members of invoices that were never paid</span></label>', checked( ! empty( $g['downgrade_remove_roles'] ), true, false ) );
        echo '<p class="njilga-help">Tags are always applied; this only controls the role. Runs manually, behind a confirmation screen, from the Invoicing page.</p></td></tr>';
        echo '</tbody></table></div>';
    }

    private static function render_joining( array $s, array $tags ): void {
        $g = $s['general'];
        MyNJILGA_Admin_UI::section(
            'Online joining',
            'The <code>[njilga_join category="…"]</code> shortcode on each Membership page: join and pay through Stripe Checkout, with the firm upsell and colleague invitations. See <a href="' . esc_url( add_query_arg( 'tab', 'joins', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) ) ) . '">Applications → Online joins</a>.'
        );
        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';

        echo '<tr><th scope="row">Online joining</th><td>';
        printf( '<label class="njilga-check-label"><input type="checkbox" name="general[join_enabled]" value="1"%s> <span>Accept online joins</span></label>', checked( ! empty( $g['join_enabled'] ), true, false ) );
        echo '<p class="njilga-help">A paid join is a membership as soon as Stripe confirms the payment — no review. A join that comes to $0 (a free category) waits for staff on Online joins. Joins pay in the Stripe mode active under Payments, the same one invoices use; while that\'s Live, staff can rehearse in Test by adding <code>?njilga_test=1</code> to a Membership page\'s address.</p></td></tr>';

        self::text_row( 'general[join_notify_email]', 'Notify staff of online joins', $g['join_notify_email'], 'Comma-separated. Blank = the application notification address above, then the site admin email.' );
        self::text_row( 'general[join_max_colleagues]', 'Most colleagues per join', (string) (int) $g['join_max_colleagues'], 'The firm upsell (tier-eligible categories only): how many colleagues one payer can add. Seats beyond the free tier cost nothing, so keep this modest; 0 turns the upsell off.' );

        echo '<tr><th scope="row">Joining an existing firm</th><td><div class="njilga-radio-list">';
        foreach ( [
            'domain' => 'Attach automatically only when the joiner\'s email domain is already at the firm; otherwise hold for staff to confirm (recommended)',
            'always' => 'Always attach, and flag it for staff to review afterwards',
        ] as $val => $label ) {
            printf( '<label class="njilga-check-label"><input type="radio" name="general[join_firm_match]" value="%s"%s> <span>%s</span></label>', esc_attr( $val ), checked( (string) $g['join_firm_match'], $val, false ), esc_html( $label ) );
        }
        echo '</div><p class="njilga-help">Free-mail addresses (gmail.com and the like) never count as a match. A joiner is never made Owner of a firm that already exists, and colleagues who already belong to another firm, carry another category, or are marked inactive always wait for staff.</p></td></tr>';

        self::text_row( 'general[join_next_year_from]', 'Joins cover next year from', (string) $g['join_next_year_from'], 'MM-DD, e.g. 10-01. From this date a join pays next year\'s dues and covers the rest of this year. Blank = only once next year\'s invoices have been created — from then on it always does, so a late joiner is never missing from next year\'s already-frozen invoices.' );

        echo '<tr><th scope="row">Payment</th><td>';
        printf( '<label class="njilga-check-label"><input type="checkbox" name="general[join_allow_ach]" value="1"%s> <span>Offer US bank account (ACH) as well as card</span></label>', checked( ! empty( $g['join_allow_ach'] ), true, false ) );
        printf( '<label class="njilga-check-label"><input type="checkbox" name="general[join_stripe_invoice]" value="1"%s> <span>Have Stripe issue a paid invoice (PDF) for each join</span></label>', checked( ! empty( $g['join_stripe_invoice'] ), true, false ) );
        echo '<p class="njilga-help">A bank payment takes about four business days to clear; membership starts when it does. Stripe charges a small per-invoice fee for post-payment invoices — turn it off to rely on Stripe\'s payment receipt instead.</p></td></tr>';

        self::text_row( 'general[join_invite_expiry_days]', 'Colleague invitations last', (string) (int) $g['join_invite_expiry_days'], 'Days. An expired link can be re-sent from Online joins.' );
        self::text_row( 'general[join_prelaw_tag]', 'Pre-law tag', (string) $g['join_prelaw_tag'], 'Added (with the student category) for undergraduates who aspire to law school.', $tags );

        echo '<tr><th scope="row"><label for="g-join-success">Welcome message</label></th><td>';
        printf( '<textarea id="g-join-success" name="general[join_success_text]" rows="2" class="large-text">%s</textarea>', esc_textarea( (string) $g['join_success_text'] ) );
        echo '<p class="njilga-help">Shown after a successful online join.</p></td></tr>';

        echo '<tr><th scope="row"><label for="g-join-muni">Municipality choices</label></th><td>';
        printf( '<textarea id="g-join-muni" name="general[join_municipalities]" rows="5" class="large-text" placeholder="One per line">%s</textarea>', esc_textarea( (string) $g['join_municipalities'] ) );
        echo '<p class="njilga-help">The Municipality select offers the options of the FluentCRM field mapped below. This list is only used when that field has none (one per line); blank as well = a free-text field.</p></td></tr>';

        // FluentCRM custom fields.
        $known = MyNJILGA_Members_Data::fluentcrm_active() ? MyNJILGA_Join_Fulfillment::fluentcrm_custom_field_slugs() : [];
        foreach ( [
            'cf_attorney_id'        => 'NJ Attorney ID',
            'cf_bar_admission_date' => 'Date of admission to the NJ Bar',
            'cf_nj_county'          => 'NJ County',
            'cf_municipality'       => 'Municipality',
            'cf_mailing_phone'      => 'Mailing-address phone (when different)',
            'cf_school'             => 'Student\'s school',
            'cf_student_status'     => 'Student\'s enrollment status',
        ] as $key => $label ) {
            $slug = (string) ( $g[ $key ] ?? '' );
            $id   = 'f-' . $key;
            $note = $slug === '' ? '' : ( in_array( $slug, $known, true ) ? '<div class="njilga-note-ok">&#10003; custom field exists</div>' : '<div class="njilga-note-warn">No FluentCRM custom field with this slug — answers are kept on the join record only. Add it under FluentCRM → Settings → Custom Fields.</div>' );
            // The two selects take their choices from these fields.
            if ( $slug !== '' && in_array( $key, [ 'cf_nj_county', 'cf_municipality' ], true ) && in_array( $slug, $known, true ) ) {
                $choices = count( MyNJILGA_Dues_Settings::crm_field_options( $slug ) );
                $note   .= $choices > 0
                    ? sprintf( '<div class="njilga-note-ok">&#10003; the form offers its %d options</div>', $choices )
                    : ( $key === 'cf_nj_county'
                        ? '<div class="njilga-note-warn">This field has no options, so the form leaves NJ County out. Make it a select field with the counties as options.</div>'
                        : '<div class="njilga-note-warn">This field has no options, so the form uses the Municipality choices above, or free text.</div>' );
            }
            printf(
                '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="general[%3$s]" value="%4$s" class="regular-text">%5$s<p class="njilga-help">FluentCRM contact custom field slug. Blank = don\'t store it on the contact.</p></td></tr>',
                esc_attr( $id ),
                esc_html( $label ),
                esc_attr( $key ),
                esc_attr( $slug ),
                $note // Trusted markup built above.
            );
        }

        echo '</tbody></table></div>';
    }

    private static function render_categories( array $s, array $tags, array $roles ): void {
        MyNJILGA_Admin_UI::section(
            'Membership categories',
            'Rows are matched in <strong>Order</strong> — a contact carrying two category tags belongs to the first one listed (so exempt categories come before Professional). <strong>Tier-eligible</strong> categories are ranked alphabetically within the firm and priced by rank using the tier table; everything else is flat-priced and never occupies a paid slot. <strong>Role</strong> follows the category: granted on payment, and swapped when a paid member\'s category tag or this mapping changes (Setup → WordPress role sync). <strong>Member tag</strong> (optional) is the FluentCRM tag added to a member of the category when they pay, and removed by the downgrade sweep — the Tag slug says who is in the category, the Member tag marks who has paid. A category\'s own tag is fine (it is added after payment if they don\'t already have it, and is never removed); anything else must not be another category\'s tag or a paid/unpaid or assessment tag.'
        );

        // Stripe bills inline line items, so the Price column IS the
        // charge — there is no catalog anywhere that could disagree.
        MyNJILGA_Admin_UI::callout( 'Prices are billed as inline line items on the Stripe invoice — the amounts below are exactly what the firm is charged.', 'info' );

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>
                <th style="width:70px">Order</th><th style="min-width:200px">Label</th><th style="min-width:180px">Tag slug</th><th style="width:100px">Price ($)</th><th>Role</th><th class="njilga-col-wide">Member tag</th><th style="width:80px">Tier-eligible</th><th style="width:90px">Applicant may pick</th><th style="width:60px">Delete</th>
              </tr></thead><tbody>';

        $rows = $s['categories'];
        $rows[] = [ 'key' => '', 'label' => '', 'tag' => '', 'price_cents' => 0, 'role' => '', 'member_tag' => '', 'tier_eligible' => false, 'applicant_selectable' => true, 'tiers' => [] ];
        foreach ( $rows as $i => $cat ) {
            $isNew = $cat['key'] === '';
            $n     = "categories[$i]";
            echo '<tr' . ( $isNew ? ' class="njilga-newrow"' : '' ) . '>';
            printf( '<td><input type="number" name="%s[order]" value="%d" min="0" class="njilga-input-sm" style="width:64px"></td>', $n, $isNew ? 999 : $i + 1 );
            printf( '<td><input type="text" name="%s[label]" value="%s" placeholder="%s" class="njilga-full">%s</td>', $n, esc_attr( $cat['label'] ), $isNew ? 'New category label…' : '', $isNew ? '' : sprintf( '<input type="hidden" name="%s[key]" value="%s"><div class="njilga-dim" style="font-size:11px;margin-top:4px">key: %s</div>', $n, esc_attr( $cat['key'] ), esc_html( $cat['key'] ) ) );
            printf( '<td><input type="text" list="njilga-tags" name="%s[tag]" value="%s" class="njilga-full">%s</td>', $n, esc_attr( $cat['tag'] ), self::tag_check( $cat['tag'] ) );
            printf( '<td><input type="number" step="0.01" min="0" name="%s[price]" value="%s" style="width:92px"></td>', $n, esc_attr( number_format( $cat['price_cents'] / 100, 2, '.', '' ) ) );
            printf( '<td>%s</td>', self::role_select( "{$n}[role]", $cat['role'], $roles ) );
            $memberTag = (string) ( $cat['member_tag'] ?? '' );
            printf( '<td><input type="text" list="njilga-tags" name="%s[member_tag]" value="%s" class="njilga-full" placeholder="— none —">%s</td>', $n, esc_attr( $memberTag ), self::tag_check( $memberTag ) );
            printf( '<td class="njilga-col-center"><input type="checkbox" name="%s[tier_eligible]" value="1"%s></td>', $n, checked( ! empty( $cat['tier_eligible'] ), true, false ) );
            printf( '<td class="njilga-col-center"><input type="checkbox" name="%s[applicant_selectable]" value="1"%s></td>', $n, checked( ! empty( $cat['applicant_selectable'] ), true, false ) );
            printf( '<td class="njilga-col-center">%s</td>', $isNew ? '' : sprintf( '<input type="checkbox" name="%s[delete]" value="1">', $n ) );
            echo '</tr>';

            // Tier table (used only when tier-eligible).
            $tiers = $cat['tiers'];
            if ( empty( $tiers ) ) {
                $tiers = [
                    [ 'key' => 'first',  'label' => '1st Member',  'from' => 1, 'to' => 1, 'price_cents' => 0 ],
                    [ 'key' => '2_to_5', 'label' => 'Members 2–5', 'from' => 2, 'to' => 5, 'price_cents' => 0 ],
                    [ 'key' => '6_plus', 'label' => 'Members 6+',  'from' => 6, 'to' => 0, 'price_cents' => 0 ],
                ];
            }
            $tiers[] = [ 'key' => '', 'label' => '', 'from' => 0, 'to' => 0, 'price_cents' => 0 ];
            echo '<tr><td></td><td colspan="8" style="padding:0 14px 12px"><details class="njilga-details" style="margin:0"' . ( ! empty( $cat['tier_eligible'] ) ? ' open' : '' ) . '><summary>' . MyNJILGA_Admin_UI::icon( 'sliders' ) . ' Tier pricing by rank (used only when Tier-eligible is checked)</summary>';
            echo '<div class="njilga-card njilga-table-boxed" style="margin-top:8px;max-width:940px"><div class="njilga-tablewrap"><table class="njilga-table njilga-table-compact"><thead><tr><th>Tier label</th><th style="width:90px">From rank</th><th style="width:110px">To rank (0 = open)</th><th style="width:100px">Price ($)</th></tr></thead><tbody>';
            foreach ( $tiers as $j => $t ) {
                $tn = "{$n}[tiers][$j]";
                echo '<tr>';
                printf( '<td><input type="text" name="%s[label]" value="%s" class="njilga-full njilga-input-sm" placeholder="%s"><input type="hidden" name="%s[key]" value="%s"></td>', $tn, esc_attr( $t['label'] ), $t['key'] === '' ? 'Add a tier…' : '', $tn, esc_attr( $t['key'] ) );
                printf( '<td><input type="number" name="%s[from]" value="%d" min="0" class="njilga-input-sm" style="width:76px"></td>', $tn, (int) $t['from'] );
                printf( '<td><input type="number" name="%s[to]" value="%d" min="0" class="njilga-input-sm" style="width:76px"></td>', $tn, (int) $t['to'] );
                printf( '<td><input type="number" step="0.01" min="0" name="%s[price]" value="%s" class="njilga-input-sm" style="width:92px"></td>', $tn, esc_attr( number_format( $t['price_cents'] / 100, 2, '.', '' ) ) );
                echo '</tr>';
            }
            echo '</tbody></table></div></div></details></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_assessment( array $s, array $tags ): void {
        $a = $s['assessment'];
        MyNJILGA_Admin_UI::section(
            'Assessment',
            'One flat charge per qualifying ACTIVE contact, on top of their dues (an exempt Senior Trustee still owes it). Qualifying tags are matched in order; the first one a contact carries labels their line. Capped at one per person.'
        );

        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';
        self::text_row( 'assessment[label]', 'Label', $a['label'], 'Printed on the invoice line, e.g. "Trustee Dinner Assessment".' );
        printf( '<tr><th scope="row"><label for="a-price">Price ($)</label></th><td><input type="number" step="0.01" min="0" id="a-price" name="assessment[price]" value="%s" style="width:110px"></td></tr>', esc_attr( number_format( $a['price_cents'] / 100, 2, '.', '' ) ) );
        echo '</tbody></table></div>';

        echo '<div class="njilga-card njilga-table-boxed" style="max-width:780px"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th style="width:70px">Order</th><th>Qualifying tag slug</th><th>Label on invoice</th><th style="width:60px">Delete</th></tr></thead><tbody>';
        $qs   = $a['qualifiers'];
        $qs[] = [ 'tag' => '', 'label' => '' ];
        foreach ( $qs as $i => $q ) {
            $n     = "assessment[qualifiers][$i]";
            $isNew = $q['tag'] === '';
            echo '<tr' . ( $isNew ? ' class="njilga-newrow"' : '' ) . '>';
            printf( '<td><input type="number" name="%s[order]" value="%d" min="0" class="njilga-input-sm" style="width:64px"></td>', $n, $isNew ? 999 : $i + 1 );
            printf( '<td><input type="text" list="njilga-tags" name="%s[tag]" value="%s" class="njilga-full" placeholder="%s">%s</td>', $n, esc_attr( $q['tag'] ), $isNew ? 'Add a qualifying tag…' : '', self::tag_check( $q['tag'] ) );
            printf( '<td><input type="text" name="%s[label]" value="%s" class="njilga-full"></td>', $n, esc_attr( $q['label'] ) );
            printf( '<td class="njilga-col-center">%s</td>', $isNew ? '' : sprintf( '<input type="checkbox" name="%s[delete]" value="1">', $n ) );
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_firm_overrides( array $s ): void {
        MyNJILGA_Admin_UI::section(
            'Per-firm billing mode',
            'Every firm gets one invoice to its Owner unless overridden here. Overrides take effect the next time a preview is generated (firms already invoiced for a year keep their rows).'
        );

        $companies = [];
        if ( MyNJILGA_Members_Data::companies_module_active() ) {
            foreach ( \FluentCrm\App\Models\Company::orderBy( 'name', 'asc' )->get() as $c ) {
                $companies[ (int) $c->id ] = (string) $c->name;
            }
        }
        $modes = MyNJILGA_Dues_Settings::billing_mode_labels();

        echo '<div class="njilga-card njilga-table-boxed" style="max-width:940px"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Firm</th><th>Billing mode</th></tr></thead><tbody>';
        foreach ( $s['firm_overrides'] as $companyId => $mode ) {
            printf( '<tr><td>%s <span class="njilga-dim">#%d</span><input type="hidden" name="firm_overrides[%d][company_id]" value="%d"></td><td><select name="firm_overrides[%d][mode]" class="njilga-select">', esc_html( $companies[ $companyId ] ?? 'Company' ), $companyId, $companyId, $companyId, $companyId );
            foreach ( $modes as $val => $label ) {
                printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $mode, $val, false ), esc_html( $label ) );
            }
            echo '</select></td></tr>';
        }
        // Add row.
        echo '<tr class="njilga-newrow"><td><select name="firm_overrides[new][company_id]" class="njilga-select"><option value="0">— Add a firm override —</option>';
        foreach ( $companies as $id => $name ) {
            if ( isset( $s['firm_overrides'][ $id ] ) ) {
                continue;
            }
            printf( '<option value="%d">%s</option>', $id, esc_html( $name ) );
        }
        echo '</select></td><td><select name="firm_overrides[new][mode]" class="njilga-select">';
        foreach ( $modes as $val => $label ) {
            if ( $val === MyNJILGA_Dues_Settings::MODE_FIRM ) {
                continue;
            }
            printf( '<option value="%s">%s</option>', esc_attr( $val ), esc_html( $label ) );
        }
        echo '</select></td></tr></tbody></table></div></div>';
    }

    // -------------------------------------------------------------------------
    // Field helpers
    // -------------------------------------------------------------------------

    private static function text_row( string $name, string $label, $value, string $help, array $tags = [] ): void {
        $id = 'f-' . sanitize_key( str_replace( [ '[', ']' ], '-', $name ) );
        printf(
            '<tr><th scope="row"><label for="%s">%s</label></th><td><input type="text" id="%s" name="%s" value="%s" class="regular-text"%s>%s<p class="njilga-help">%s</p></td></tr>',
            esc_attr( $id ),
            esc_html( $label ),
            esc_attr( $id ),
            esc_attr( $name ),
            esc_attr( (string) $value ),
            $tags ? ' list="njilga-tags"' : '',
            $tags ? self::tag_check( (string) $value ) : '',
            esc_html( $help )
        );
    }

    private static function render_tag_datalist( array $tags ): void {
        echo '<datalist id="njilga-tags">';
        foreach ( $tags as $t ) {
            printf( '<option value="%s">%s</option>', esc_attr( $t['slug'] ), esc_html( $t['title'] ) );
        }
        echo '</datalist>';
    }

    private static function tag_check( string $slug ): string {
        if ( $slug === '' || ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return '';
        }
        $id = MyNJILGA_Tags::resolve_slug( $slug );
        return $id
            ? sprintf( '<div class="njilga-note-ok">&#10003; tag #%d</div>', $id )
            : '<div class="njilga-note-bad">&#10007; no such tag — create it in FluentCRM or on the Setup page</div>';
    }

    private static function role_select( string $name, string $current, array $roles ): string {
        $html = sprintf( '<select name="%s"><option value="">— no membership role (removes any) —</option>', esc_attr( $name ) );
        $found = false;
        foreach ( $roles as $slug => $label ) {
            $sel = $slug === $current ? ' selected' : '';
            if ( $sel ) {
                $found = true;
            }
            $html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), $sel, esc_html( $label ) );
        }
        if ( ! $found && $current !== '' ) {
            $html .= sprintf( '<option value="%s" selected>%s (role not defined on this site)</option>', esc_attr( $current ), esc_html( $current ) );
        }
        return $html . '</select>';
    }

    /**
     * @return array<string,string> slug => display name
     */
    private static function wp_roles(): array {
        $out = [];
        foreach ( wp_roles()->roles as $slug => $role ) {
            $out[ $slug ] = (string) ( $role['name'] ?? $slug );
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // admin-post handlers
    // -------------------------------------------------------------------------

    /**
     * Save ONE tab. Each tab's form posts a hidden `tab`, and only that tab's
     * settings are read from the post — everything else (the other tabs'
     * general keys, and the categories / assessment / firm-override structures
     * it doesn't show) is carried over from what is stored, so saving the
     * billing tab can never wipe the category table.
     *
     * A post whose tab is unknown, or that lacks the structure its tab edits
     * (a form from before the tabs existed, or a tampered one), saves nothing.
     */
    public static function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_SAVE );

        $in  = wp_unslash( $_POST );
        $tab = sanitize_key( (string) ( $in['tab'] ?? '' ) );
        if ( ! in_array( $tab, self::SAVE_TABS, true )
            || ( $tab === 'categories' && ! isset( $in['categories'], $in['assessment'] ) )
            || ( $tab === 'billing' && ! isset( $in['firm_overrides'] ) )
        ) {
            self::back( 'categories', [ 'stale' => '1' ] );
        }

        $current       = MyNJILGA_Dues_Settings::get();
        $defaults      = MyNJILGA_Dues_Settings::defaults();
        $roleSignature = MyNJILGA_Role_Sync::current_signature();

        $g          = (array) ( $in['general'] ?? [] );
        $gen        = $current['general'];
        $categories = $current['categories'];
        $assessment = $current['assessment'];
        $overrides  = $current['firm_overrides'];

        switch ( $tab ) {
            case 'categories':
                $categories              = self::read_categories( $in, $defaults );
                $assessment              = self::read_assessment( $in, $defaults );
                $gen['default_category'] = sanitize_title( (string) ( $g['default_category'] ?? '' ) );
                break;

            case 'billing':
                foreach ( [ 'inactive_tag', 'paid_tag', 'unpaid_tag' ] as $k ) {
                    $gen[ $k ] = sanitize_title( (string) ( $g[ $k ] ?? '' ) );
                }
                foreach ( [ 'year_paid_tag_pattern', 'year_unpaid_tag_pattern', 'assessment_paid_pattern' ] as $k ) {
                    $gen[ $k ] = sanitize_text_field( (string) ( $g[ $k ] ?? '' ) );
                    if ( $gen[ $k ] === '' ) {
                        $gen[ $k ] = $defaults['general'][ $k ];
                    }
                }
                $overrides = self::read_overrides( $in );
                break;

            case 'applications':
                foreach ( [ 'pending_tag', 'rejected_tag' ] as $k ) {
                    $gen[ $k ] = sanitize_title( (string) ( $g[ $k ] ?? '' ) );
                }
                $gen['application_notify_email'] = sanitize_text_field( (string) ( $g['application_notify_email'] ?? '' ) );
                $gen['application_success_text'] = sanitize_textarea_field( (string) ( $g['application_success_text'] ?? $defaults['general']['application_success_text'] ) );
                $gen['mid_year_join_policy']     = array_key_exists( (string) ( $g['mid_year_join_policy'] ?? '' ), MyNJILGA_Dues_Settings::join_policy_labels() ) ? (string) $g['mid_year_join_policy'] : MyNJILGA_Dues_Settings::JOIN_FREE_UNTIL_NEXT_CYCLE;
                break;

            case 'advanced':
                $gen['batch_size']             = max( 1, min( 200, (int) ( $g['batch_size'] ?? 25 ) ) );
                $gen['downgrade_remove_roles'] = ! empty( $g['downgrade_remove_roles'] );
                break;

            case 'joining':
                $gen['join_enabled']            = ! empty( $g['join_enabled'] );
                $gen['join_allow_ach']          = ! empty( $g['join_allow_ach'] );
                $gen['join_stripe_invoice']     = ! empty( $g['join_stripe_invoice'] );
                $gen['join_notify_email']       = sanitize_text_field( (string) ( $g['join_notify_email'] ?? '' ) );
                $gen['join_max_colleagues']     = max( 0, min( 99, (int) ( $g['join_max_colleagues'] ?? 10 ) ) );
                $gen['join_invite_expiry_days'] = max( 1, min( 365, (int) ( $g['join_invite_expiry_days'] ?? 30 ) ) );
                $gen['join_firm_match']         = ( (string) ( $g['join_firm_match'] ?? '' ) ) === 'always' ? 'always' : 'domain';
                $cut                            = trim( (string) ( $g['join_next_year_from'] ?? '' ) );
                $gen['join_next_year_from']     = preg_match( '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $cut ) ? $cut : '';
                $gen['join_prelaw_tag']         = sanitize_title( (string) ( $g['join_prelaw_tag'] ?? '' ) );
                $gen['join_success_text']       = sanitize_textarea_field( (string) ( $g['join_success_text'] ?? $defaults['general']['join_success_text'] ) );
                $gen['join_municipalities']     = implode( "\n", array_filter( array_map( 'sanitize_text_field', (array) preg_split( '/\r\n|\r|\n/', (string) ( $g['join_municipalities'] ?? '' ) ) ), 'strlen' ) );
                foreach ( [ 'cf_attorney_id', 'cf_bar_admission_date', 'cf_municipality', 'cf_nj_county', 'cf_school', 'cf_student_status', 'cf_mailing_phone' ] as $k ) {
                    $gen[ $k ] = sanitize_key( (string) ( $g[ $k ] ?? '' ) );
                }
                break;
        }

        // The default category must still exist after the categories changed.
        if ( $gen['default_category'] !== '' && ! in_array( $gen['default_category'], array_column( $categories, 'key' ), true ) ) {
            $gen['default_category'] = '';
        }

        // A member tag is something paying GRANTS; it must not be a tag that
        // pricing, billing or the role logic already READS (a category's own
        // tag, paid/unpaid, an assessment qualifier…), or paying would change
        // who is charged what. Checked against the FINAL state on every save —
        // changing the paid tag on one tab can newly collide with a member tag
        // saved on another. Refused ones are blanked and reported.
        $screened   = MyNJILGA_Member_Tags::screen( $categories, MyNJILGA_Member_Tags::reserved_slugs( $categories, $gen, (array) ( $assessment['qualifiers'] ?? [] ) ) );
        $categories = $screened['categories'];

        MyNJILGA_Dues_Settings::save( [
            'general'        => $gen,
            'categories'     => $categories,
            'assessment'     => $assessment,
            'firm_overrides' => $overrides,
        ] );

        // Which tags mean paid / unpaid / inactive, the year-tag pattern and the
        // categories all feed the cached membership figures.
        MyNJILGA_Membership_Stats::flush();

        $args = [ 'saved' => '1' ];
        if ( $screened['rejected'] ) {
            $args['member_tag_rejected'] = implode( ',', $screened['rejected'] );
        }
        $queued = MyNJILGA_Role_Sync::after_settings_change( $current['categories'], $roleSignature );
        if ( $queued !== null ) {
            $args['role_sync'] = $queued;
        }
        self::back( $tab, $args );
    }

    /**
     * The category table, as posted.
     *
     * @param array<string,mixed> $in
     * @param array<string,mixed> $defaults
     * @return array<int,array<string,mixed>>
     */
    private static function read_categories( array $in, array $defaults ): array {
        $categories = [];
        $usedKeys   = [];
        foreach ( (array) ( $in['categories'] ?? [] ) as $row ) {
            $row = (array) $row;
            if ( ! empty( $row['delete'] ) ) {
                continue;
            }
            $label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
            $key   = sanitize_key( (string) ( $row['key'] ?? '' ) );
            if ( $key === '' ) {
                if ( $label === '' ) {
                    continue; // The blank "add" row.
                }
                $key = sanitize_key( str_replace( [ ' ', '-' ], '_', strtolower( $label ) ) );
            }
            if ( $key === '' || isset( $usedKeys[ $key ] ) ) {
                continue;
            }
            $usedKeys[ $key ] = true;

            $tiers = [];
            foreach ( (array) ( $row['tiers'] ?? [] ) as $t ) {
                $t      = (array) $t;
                $tLabel = sanitize_text_field( (string) ( $t['label'] ?? '' ) );
                $tKey   = sanitize_key( (string) ( $t['key'] ?? '' ) );
                $from   = (int) ( $t['from'] ?? 0 );
                if ( $tLabel === '' && $tKey === '' ) {
                    continue;
                }
                if ( $from <= 0 ) {
                    continue;
                }
                if ( $tKey === '' ) {
                    $tKey = sanitize_key( str_replace( [ ' ', '-', '+' ], '_', strtolower( $tLabel ) ) ) ?: ( 'tier_' . $from );
                }
                $tiers[] = [
                    'key'         => $tKey,
                    'label'       => $tLabel !== '' ? $tLabel : ( 'Rank ' . $from ),
                    'from'        => $from,
                    'to'          => max( 0, (int) ( $t['to'] ?? 0 ) ),
                    'price_cents' => self::dollars_to_cents( $t['price'] ?? 0 ),
                ];
            }

            $categories[] = [
                '_order'               => (int) ( $row['order'] ?? 999 ),
                'key'                  => $key,
                'label'                => $label !== '' ? $label : $key,
                'tag'                  => sanitize_title( (string) ( $row['tag'] ?? '' ) ),
                'price_cents'          => self::dollars_to_cents( $row['price'] ?? 0 ),
                'role'                 => sanitize_key( (string) ( $row['role'] ?? '' ) ),
                'member_tag'           => sanitize_title( (string) ( $row['member_tag'] ?? '' ) ),
                'tier_eligible'        => ! empty( $row['tier_eligible'] ),
                'applicant_selectable' => ! empty( $row['applicant_selectable'] ),
                'tiers'                => $tiers,
            ];
        }
        usort( $categories, static function ( $a, $b ) { return $a['_order'] <=> $b['_order']; } );
        foreach ( $categories as &$c ) {
            unset( $c['_order'] );
            $c = MyNJILGA_Dues_Settings::normalize_category( $c );
        }
        unset( $c );
        return empty( $categories ) ? $defaults['categories'] : $categories;
    }

    /**
     * The assessment, as posted.
     *
     * @param array<string,mixed> $in
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private static function read_assessment( array $in, array $defaults ): array {
        $a          = (array) ( $in['assessment'] ?? [] );
        $qualifiers = [];
        foreach ( (array) ( $a['qualifiers'] ?? [] ) as $q ) {
            $q   = (array) $q;
            $tag = sanitize_title( (string) ( $q['tag'] ?? '' ) );
            if ( $tag === '' || ! empty( $q['delete'] ) ) {
                continue;
            }
            $qualifiers[] = [
                '_order' => (int) ( $q['order'] ?? 999 ),
                'tag'    => $tag,
                'label'  => sanitize_text_field( (string) ( $q['label'] ?? '' ) ) ?: MyNJILGA_Tags::title_for_slug( $tag ),
            ];
        }
        usort( $qualifiers, static function ( $x, $y ) { return $x['_order'] <=> $y['_order']; } );
        foreach ( $qualifiers as &$q ) {
            unset( $q['_order'] );
        }
        unset( $q );
        return [
            'key'         => 'trustee_dinner',
            'label'       => sanitize_text_field( (string) ( $a['label'] ?? '' ) ) ?: $defaults['assessment']['label'],
            'price_cents' => self::dollars_to_cents( $a['price'] ?? 0 ),
            'qualifiers'  => $qualifiers,
        ];
    }

    /**
     * Per-firm billing-mode overrides, as posted.
     *
     * @param array<string,mixed> $in
     * @return array<int,string>
     */
    private static function read_overrides( array $in ): array {
        $overrides = [];
        foreach ( (array) ( $in['firm_overrides'] ?? [] ) as $row ) {
            $row = (array) $row;
            $cid = (int) ( $row['company_id'] ?? 0 );
            $md  = (string) ( $row['mode'] ?? '' );
            if ( $cid > 0 && in_array( $md, [ MyNJILGA_Dues_Settings::MODE_INDIVIDUAL, MyNJILGA_Dues_Settings::MODE_SPLIT_ASSESSMENT ], true ) ) {
                $overrides[ $cid ] = $md;
            }
        }
        return $overrides;
    }

    public static function handle_reset(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_RESET );
        $oldCategories = MyNJILGA_Dues_Settings::categories();
        $roleSignature = MyNJILGA_Role_Sync::current_signature();
        MyNJILGA_Dues_Settings::reset_to_defaults();
        MyNJILGA_Membership_Stats::flush();
        $args   = [ 'reset' => '1' ];
        $queued = MyNJILGA_Role_Sync::after_settings_change( $oldCategories, $roleSignature );
        if ( $queued !== null ) {
            $args['role_sync'] = $queued;
        }
        self::back( 'advanced', $args );
    }

    /** Redirect to a tab (with notice args) and stop. @param array<string,mixed> $args */
    private static function back( string $tab, array $args = [] ): void {
        wp_safe_redirect( self::tab_url( $tab, $args ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Payments tab admin-post handlers
    // -------------------------------------------------------------------------

    private static function guard( string $action ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( $action );
    }

    public static function handle_payments_save(): void {
        self::guard( self::ACTION_PAYMENTS_SAVE );

        $in = wp_unslash( $_POST );
        $s  = MyNJILGA_Stripe_Connection::get();

        $s['days_until_due']     = max( 1, min( 365, (int) ( $in['days_until_due'] ?? 30 ) ) );
        $s['footer']             = sanitize_textarea_field( (string) ( $in['footer'] ?? '' ) );
        $s['remittance_address'] = sanitize_textarea_field( (string) ( $in['remittance_address'] ?? '' ) );

        MyNJILGA_Stripe_Connection::save( $s );

        wp_safe_redirect( self::payments_url( [ 'pmt_saved' => '1' ] ) );
        exit;
    }

    public static function handle_connect(): void {
        self::guard( self::ACTION_STRIPE_CONNECT );

        $in   = wp_unslash( $_POST );
        $mode = sanitize_key( (string) ( $in['mode'] ?? '' ) );
        $key  = trim( (string) ( $in['secret_key'] ?? '' ) );

        $result = MyNJILGA_Stripe_Connection::verify_and_connect( $mode, $key );

        // add_query_arg()/build_query() do NOT urlencode values (WordPress
        // calls _http_build_query() with $urlencode = false there), so a
        // free-form Stripe error/warning message — which can contain
        // spaces, "&", "=", etc. — has to be encoded here or it would
        // corrupt the redirect URL's query string. PHP url-decodes $_GET
        // automatically on the next request, so render_payments_notices()
        // needs no matching decode step.
        if ( ! $result['ok'] ) {
            wp_safe_redirect( self::payments_url( [ 'connect_error' => rawurlencode( $result['error'] ) ] ) );
            exit;
        }

        $args = [ 'connected' => '1', 'mode' => $mode ];
        if ( strpos( $key, 'sk_' ) === 0 ) {
            $args['used_sk'] = '1';
        }
        if ( ! empty( $result['warning'] ) ) {
            $args['connect_warning'] = rawurlencode( $result['warning'] );
        }

        wp_safe_redirect( self::payments_url( $args ) );
        exit;
    }

    public static function handle_webhook_save(): void {
        self::guard( self::ACTION_STRIPE_WEBHOOK_SAVE );

        $in     = wp_unslash( $_POST );
        $mode   = sanitize_key( (string) ( $in['mode'] ?? '' ) );
        $secret = trim( (string) ( $in['webhook_secret'] ?? '' ) );

        if ( $mode === MyNJILGA_Stripe_Connection::MODE_TEST || $mode === MyNJILGA_Stripe_Connection::MODE_LIVE ) {
            // A blank or malformed value must never replace a working
            // secret: a stored secret that isn't Stripe's makes the receiver
            // reject every delivery, and a blank one wipes the good one.
            if ( preg_match( '/^whsec_[A-Za-z0-9]+\z/', $secret ) !== 1 ) {
                wp_safe_redirect( self::payments_url( [ 'webhook_invalid' => '1' ] ) );
                exit;
            }
            MyNJILGA_Stripe_Connection::save_manual_webhook_secret( $mode, $secret );
        }

        wp_safe_redirect( self::payments_url( [ 'webhook_saved' => '1' ] ) );
        exit;
    }

    public static function handle_switch_mode(): void {
        self::guard( self::ACTION_STRIPE_SWITCH_MODE );

        $in     = wp_unslash( $_POST );
        $target = sanitize_key( (string) ( $in['target'] ?? '' ) );
        $valid  = ( $target === MyNJILGA_Stripe_Connection::MODE_TEST || $target === MyNJILGA_Stripe_Connection::MODE_LIVE );

        if ( ! $valid || empty( $in['acknowledge'] ) ) {
            wp_safe_redirect( self::payments_url( [ 'view' => 'switch-mode', 'target' => $target ] ) );
            exit;
        }

        MyNJILGA_Stripe_Connection::switch_mode( $target );

        wp_safe_redirect( self::payments_url( [ 'switched' => $target ] ) );
        exit;
    }

    private static function dollars_to_cents( $value ): int {
        $value = str_replace( [ ',', '$', ' ' ], '', (string) $value );
        return max( 0, (int) round( (float) $value * 100 ) );
    }
}
