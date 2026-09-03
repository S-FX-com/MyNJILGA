<?php
/**
 * My NJILGA → Settings → Dues & Billing (spec §3).
 *
 * One big server-rendered form, saved through admin-post. Sections:
 *   1. General switches (default category, inactive tag, evergreen tags,
 *      CC policy, downgrade behaviour, mid-year join policy, enrollment).
 *   2. Category mapping table — ORDERED (precedence), each row:
 *      tag → price → WP role → tier-eligible flag,
 *      with the per-rank tier table for tier-eligible categories.
 *   3. Assessment mapping — one product, ordered qualifying tags.
 *   4. Per-firm billing-mode overrides.
 *
 * Next to every mapped tag and product the page shows a live check
 * (does the tag exist? does the variation exist, is it published, does
 * its price match?), so the "confirm the exact slugs against the live
 * instance" setup step is a glance at this page, not a database query.
 */
class MyNJILGA_Page_Settings {

    const ACTION_SAVE  = 'my_njilga_settings_save';
    const ACTION_RESET = 'my_njilga_settings_reset';

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
     * Tab switcher at the very top of the page (spec: mirrors
     * MyNJILGA_Page_Firms::render_scope_tabs() — a URL query arg,
     * MyNJILGA_Admin_UI::nav_tabs()). ?tab=dues (default) is the existing
     * Dues & Billing form; ?tab=payments is the Stripe connection screen.
     * The two tabs render entirely different content/forms, never both.
     */
    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        $tab = self::current_tab();

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';

        if ( $tab === 'payments' ) {
            MyNJILGA_Admin_UI::page_header( 'Payments', 'Connect Stripe, choose which mode is active, and set the defaults every Stripe invoice uses.' );
            self::render_settings_tabs( $tab );

            $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : '';
            if ( $view === 'switch-mode' ) {
                self::render_switch_mode_confirm();
            } else {
                self::render_payments_tab();
            }

            echo '</div>';
            return;
        }

        MyNJILGA_Admin_UI::page_header(
            'Dues & Billing Settings',
            'FluentCRM tags are the source of truth for who owes what; WordPress roles are a downstream effect of payment, never an input to pricing. Prices below (in dollars) are exactly what the Stripe invoice charges — each one becomes an inline line item.'
        );
        self::render_settings_tabs( $tab );
        self::render_dues_tab();
        echo '</div>';
    }

    private static function current_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
        return $tab === 'payments' ? 'payments' : 'dues';
    }

    private static function render_settings_tabs( string $active ): void {
        $base = MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS );
        MyNJILGA_Admin_UI::nav_tabs( [
            [ 'label' => 'Dues & Billing', 'url' => add_query_arg( 'tab', 'dues', $base ), 'active' => $active === 'dues' ],
            [ 'label' => 'Payments',       'url' => add_query_arg( 'tab', 'payments', $base ), 'active' => $active === 'payments' ],
        ] );
    }

    private static function render_dues_tab(): void {
        $s     = MyNJILGA_Dues_Settings::get();
        $tags  = MyNJILGA_Members_Data::fluentcrm_active() ? MyNJILGA_Tags::all_tags() : [];
        $roles = self::wp_roles();

        if ( ! empty( $_GET['saved'] ) ) {
            MyNJILGA_Admin_UI::callout( 'Settings saved.', 'success' );
        }
        if ( ! empty( $_GET['reset'] ) ) {
            MyNJILGA_Admin_UI::callout( 'Settings reset to the seeded defaults.', 'success' );
        }

        self::render_tag_datalist( $tags );

        printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
        printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION_SAVE ) );
        wp_nonce_field( self::ACTION_SAVE );

        self::render_general( $s, $tags );
        self::render_categories( $s, $tags, $roles );
        self::render_assessment( $s, $tags );
        self::render_firm_overrides( $s );

        echo '<div class="njilga-actions" style="margin-top:24px"><button type="submit" class="njilga-btn njilga-btn-primary njilga-btn-lg">Save Settings</button></div>';
        echo '</form>';

        echo '<div class="njilga-danger-card" style="max-width:640px">';
        echo '<div class="njilga-danger-head">' . MyNJILGA_Admin_UI::icon( 'alert' ) . '<h2>Reset settings</h2></div>';
        echo '<p>Restores every Dues &amp; Billing setting to the seeded defaults. Per-firm billing overrides are cleared too. Invoices already generated are not touched.</p>';
        echo MyNJILGA_Admin_UI::action_form(
            self::ACTION_RESET,
            'Reset to defaults',
            [],
            'danger',
            '',
            'Reset every Dues & Billing setting to the seeded defaults? Firm overrides are cleared too.'
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

        if ( ! MyNJILGA_Stripe_Connection::encryption_active() ) {
            self::render_encryption_warning();
        }

        echo '<div class="njilga-cols-2">';
        self::render_stripe_mode_card( MyNJILGA_Stripe_Connection::MODE_TEST, $s, $activeMode );
        self::render_stripe_mode_card( MyNJILGA_Stripe_Connection::MODE_LIVE, $s, $activeMode );
        echo '</div>';

        self::render_mode_switch_banner( $activeMode );
        self::render_payment_flat_settings( $s );
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
        if ( ! empty( $get['pmt_saved'] ) ) {
            MyNJILGA_Admin_UI::callout( 'Payment settings saved.', 'success' );
        }
        if ( ! empty( $get['switched'] ) ) {
            $mode = ( $get['switched'] === MyNJILGA_Stripe_Connection::MODE_LIVE ) ? 'Live' : 'Test';
            MyNJILGA_Admin_UI::callout( sprintf( 'Now billing in <strong>%s mode</strong>.', esc_html( $mode ) ), 'success' );
        }
    }

    private static function render_encryption_warning(): void {
        echo '<div class="njilga-callout njilga-callout-warning">';
        echo '<p><strong>Stripe keys are stored in plaintext.</strong> Define <code>NJILGA_STRIPE_KEY</code> in wp-config.php to encrypt the secret key and webhook secret at rest.</p>';
        echo '<p>1. Generate a key once, at a terminal: <code>php -r "echo bin2hex(random_bytes(32));"</code></p>';
        echo '<p>2. Paste the resulting 64-character hex string into wp-config.php: <code>define( \'NJILGA_STRIPE_KEY\', \'&lt;paste the hex string here&gt;\' );</code></p>';
        echo '</div>';
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
        echo '<div class="njilga-banner-desc">Every new invoice bills through this mode&rsquo;s Stripe account. Switching modes never moves existing invoice rows or Stripe objects between modes.</div>';
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

    private static function render_general( array $s, array $tags ): void {
        $g = $s['general'];
        MyNJILGA_Admin_UI::section( 'General', 'Global switches: the fallback category, the evergreen and per-year tags, email policy, and enrollment.' );
        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';

        // Default category.
        echo '<tr><th scope="row"><label for="g-default_category">Default category</label></th><td>';
        echo '<select id="g-default_category" name="general[default_category]" class="njilga-select"><option value="">— Not billed (list as an exception) —</option>';
        foreach ( $s['categories'] as $cat ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $cat['key'] ), selected( $g['default_category'], $cat['key'], false ), esc_html( $cat['label'] ) );
        }
        echo '</select><p class="njilga-help">Contacts carrying none of the category tags below fall into this category. Seeded to Professional so an untagged roster bills the way it does today.</p></td></tr>';

        self::text_row( 'general[inactive_tag]', 'Inactive override tag', $g['inactive_tag'], 'Tag slug. A contact carrying it is billed nothing this cycle — no dues, no assessment — whatever else they carry.', $tags );
        self::text_row( 'general[paid_tag]', 'Evergreen "paid" tag', $g['paid_tag'], 'Applied to every member of a paid dues invoice; read by every report in this plugin.', $tags );
        self::text_row( 'general[unpaid_tag]', 'Evergreen "unpaid" tag', $g['unpaid_tag'], 'Applied by the downgrade sweep; removed on payment.', $tags );
        self::text_row( 'general[year_paid_tag_pattern]', 'Year paid tag pattern', $g['year_paid_tag_pattern'], 'Created on demand. {year} is replaced, e.g. "Dues Paid 2027".' );
        self::text_row( 'general[year_unpaid_tag_pattern]', 'Year unpaid tag pattern', $g['year_unpaid_tag_pattern'], 'Applied by the downgrade sweep.' );
        self::text_row( 'general[assessment_paid_pattern]', 'Assessment paid tag pattern', $g['assessment_paid_pattern'], 'Applied when an assessment-only invoice (split-assessment billing) is paid.' );

        // CC mode.
        echo '<tr><th scope="row">Invoice email recipients</th><td><div class="njilga-radio-list">';
        foreach ( MyNJILGA_Dues_Settings::cc_mode_labels() as $val => $label ) {
            printf( '<label class="njilga-check-label"><input type="radio" name="general[send_cc_mode]" value="%s"%s> <span>%s</span></label>', esc_attr( $val ), checked( $g['send_cc_mode'], $val, false ), esc_html( $label ) );
        }
        echo '</div>';
        printf( '<div class="njilga-field"><label for="g-cc">Fixed CC list</label><textarea id="g-cc" name="general[send_cc_emails]" rows="2" class="large-text" placeholder="treasurer@njilga.org, admin@njilga.org">%s</textarea></div>', esc_textarea( (string) $g['send_cc_emails'] ) );
        printf( '<div class="njilga-field"><label for="g-replyto">Reply-To</label><input type="email" id="g-replyto" name="general[send_reply_to]" value="%s" class="regular-text" placeholder="dues@njilga.org"></div>', esc_attr( (string) $g['send_reply_to'] ) );
        echo '</td></tr>';

        // Downgrade.
        echo '<tr><th scope="row">Downgrade sweep</th><td>';
        printf( '<label class="njilga-check-label"><input type="checkbox" name="general[downgrade_remove_roles]" value="1"%s> <span>Remove the category\'s WordPress role from members of invoices that were never paid</span></label>', checked( ! empty( $g['downgrade_remove_roles'] ), true, false ) );
        echo '<p class="njilga-help">Tags are always applied; this only controls the role. Runs manually, behind a confirmation screen, from the Invoicing page.</p></td></tr>';

        // Mid-year join policy.
        echo '<tr><th scope="row">Mid-year join policy</th><td><div class="njilga-radio-list">';
        foreach ( MyNJILGA_Dues_Settings::join_policy_labels() as $val => $label ) {
            printf( '<label class="njilga-check-label"><input type="radio" name="general[mid_year_join_policy]" value="%s"%s> <span>%s</span></label>', esc_attr( $val ), checked( $g['mid_year_join_policy'], $val, false ), esc_html( $label ) );
        }
        echo '</div><p class="njilga-help">Applied when an application is approved (Applications page). Final policy is still to be confirmed by NJILGA (spec §3.5) — the default is "free until next cycle".</p></td></tr>';

        // Enrollment.
        self::text_row( 'general[pending_tag]', 'Pending-approval tag', $g['pending_tag'], 'Applied to an applicant on submission; removed on approval/rejection. Contacts carrying it are never invoiced.', $tags );
        self::text_row( 'general[rejected_tag]', 'Rejected tag', $g['rejected_tag'], 'Applied when an application is rejected.', $tags );
        self::text_row( 'general[application_notify_email]', 'Notify staff on new application', $g['application_notify_email'], 'Comma-separated. Blank = the site admin email.' );
        echo '<tr><th scope="row"><label for="g-success">Application success message</label></th><td>';
        printf( '<textarea id="g-success" name="general[application_success_text]" rows="2" class="large-text">%s</textarea>', esc_textarea( (string) $g['application_success_text'] ) );
        echo '<p class="njilga-help">Shown to the applicant after the <code>[njilga_membership_application]</code> form is submitted.</p></td></tr>';

        self::text_row( 'general[batch_size]', 'Invoice creation batch size', (string) (int) $g['batch_size'], 'Invoices created per background job (Action Scheduler). Default 25.' );

        echo '</tbody></table></div>';
    }

    private static function render_categories( array $s, array $tags, array $roles ): void {
        MyNJILGA_Admin_UI::section(
            'Membership categories',
            'Rows are matched in <strong>Order</strong> — a contact carrying two category tags belongs to the first one listed (so exempt categories come before Professional). <strong>Tier-eligible</strong> categories are ranked alphabetically within the firm and priced by rank using the tier table; everything else is flat-priced and never occupies a paid slot. <strong>Role</strong> is granted on payment, best-effort.'
        );

        // Stripe bills inline line items, so the Price column IS the
        // charge — there is no catalog anywhere that could disagree.
        MyNJILGA_Admin_UI::callout( 'Prices are billed as inline line items on the Stripe invoice — the amounts below are exactly what the firm is charged.', 'info' );

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>
                <th style="width:70px">Order</th><th style="min-width:200px">Label</th><th style="min-width:180px">Tag slug</th><th style="width:100px">Price ($)</th><th>Role</th><th style="width:80px">Tier-eligible</th><th style="width:90px">Applicant may pick</th><th style="width:60px">Delete</th>
              </tr></thead><tbody>';

        $rows = $s['categories'];
        $rows[] = [ 'key' => '', 'label' => '', 'tag' => '', 'price_cents' => 0, 'role' => '', 'tier_eligible' => false, 'applicant_selectable' => true, 'tiers' => [] ];
        foreach ( $rows as $i => $cat ) {
            $isNew = $cat['key'] === '';
            $n     = "categories[$i]";
            echo '<tr' . ( $isNew ? ' class="njilga-newrow"' : '' ) . '>';
            printf( '<td><input type="number" name="%s[order]" value="%d" min="0" class="njilga-input-sm" style="width:64px"></td>', $n, $isNew ? 999 : $i + 1 );
            printf( '<td><input type="text" name="%s[label]" value="%s" placeholder="%s" class="njilga-full">%s</td>', $n, esc_attr( $cat['label'] ), $isNew ? 'New category label…' : '', $isNew ? '' : sprintf( '<input type="hidden" name="%s[key]" value="%s"><div class="njilga-dim" style="font-size:11px;margin-top:4px">key: %s</div>', $n, esc_attr( $cat['key'] ), esc_html( $cat['key'] ) ) );
            printf( '<td><input type="text" list="njilga-tags" name="%s[tag]" value="%s" class="njilga-full">%s</td>', $n, esc_attr( $cat['tag'] ), self::tag_check( $cat['tag'] ) );
            printf( '<td><input type="number" step="0.01" min="0" name="%s[price]" value="%s" style="width:92px"></td>', $n, esc_attr( number_format( $cat['price_cents'] / 100, 2, '.', '' ) ) );
            printf( '<td>%s</td>', self::role_select( "{$n}[role]", $cat['role'], $roles ) );
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
            echo '<tr><td></td><td colspan="7" style="padding:0 14px 12px"><details class="njilga-details" style="margin:0"' . ( ! empty( $cat['tier_eligible'] ) ? ' open' : '' ) . '><summary>' . MyNJILGA_Admin_UI::icon( 'sliders' ) . ' Tier pricing by rank (used only when Tier-eligible is checked)</summary>';
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
        $html = sprintf( '<select name="%s"><option value="">— no role —</option>', esc_attr( $name ) );
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

    public static function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_SAVE );

        $current  = MyNJILGA_Dues_Settings::get();
        $defaults = MyNJILGA_Dues_Settings::defaults();
        $in       = wp_unslash( $_POST );

        // --- General
        $g    = (array) ( $in['general'] ?? [] );
        $gen  = $current['general'];
        foreach ( [ 'default_category', 'inactive_tag', 'paid_tag', 'unpaid_tag', 'pending_tag', 'rejected_tag' ] as $k ) {
            $gen[ $k ] = sanitize_title( (string) ( $g[ $k ] ?? '' ) );
        }
        foreach ( [ 'year_paid_tag_pattern', 'year_unpaid_tag_pattern', 'assessment_paid_pattern', 'application_notify_email', 'send_cc_emails' ] as $k ) {
            $gen[ $k ] = sanitize_text_field( (string) ( $g[ $k ] ?? '' ) );
        }
        $gen['application_success_text'] = sanitize_textarea_field( (string) ( $g['application_success_text'] ?? $defaults['general']['application_success_text'] ) );
        $gen['send_reply_to']            = sanitize_email( (string) ( $g['send_reply_to'] ?? '' ) );
        $gen['send_cc_mode']             = array_key_exists( (string) ( $g['send_cc_mode'] ?? '' ), MyNJILGA_Dues_Settings::cc_mode_labels() ) ? (string) $g['send_cc_mode'] : MyNJILGA_Dues_Settings::CC_OWNER_ONLY;
        $gen['mid_year_join_policy']     = array_key_exists( (string) ( $g['mid_year_join_policy'] ?? '' ), MyNJILGA_Dues_Settings::join_policy_labels() ) ? (string) $g['mid_year_join_policy'] : MyNJILGA_Dues_Settings::JOIN_FREE_UNTIL_NEXT_CYCLE;
        $gen['downgrade_remove_roles']   = ! empty( $g['downgrade_remove_roles'] );
        $gen['batch_size']               = max( 1, min( 200, (int) ( $g['batch_size'] ?? 25 ) ) );
        foreach ( [ 'year_paid_tag_pattern', 'year_unpaid_tag_pattern', 'assessment_paid_pattern' ] as $k ) {
            if ( $gen[ $k ] === '' ) {
                $gen[ $k ] = $defaults['general'][ $k ];
            }
        }

        // --- Categories
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
        if ( empty( $categories ) ) {
            $categories = $defaults['categories'];
        }
        if ( $gen['default_category'] !== '' && ! in_array( $gen['default_category'], array_column( $categories, 'key' ), true ) ) {
            $gen['default_category'] = '';
        }

        // --- Assessment
        $a = (array) ( $in['assessment'] ?? [] );
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
        $assessment = [
            'key'          => 'trustee_dinner',
            'label'        => sanitize_text_field( (string) ( $a['label'] ?? '' ) ) ?: $defaults['assessment']['label'],
            'price_cents'  => self::dollars_to_cents( $a['price'] ?? 0 ),
            'qualifiers'   => $qualifiers,
        ];

        // --- Firm overrides
        $overrides = [];
        foreach ( (array) ( $in['firm_overrides'] ?? [] ) as $row ) {
            $row = (array) $row;
            $cid = (int) ( $row['company_id'] ?? 0 );
            $md  = (string) ( $row['mode'] ?? '' );
            if ( $cid > 0 && in_array( $md, [ MyNJILGA_Dues_Settings::MODE_INDIVIDUAL, MyNJILGA_Dues_Settings::MODE_SPLIT_ASSESSMENT ], true ) ) {
                $overrides[ $cid ] = $md;
            }
        }

        MyNJILGA_Dues_Settings::save( [
            'general'        => $gen,
            'categories'     => $categories,
            'assessment'     => $assessment,
            'firm_overrides' => $overrides,
        ] );

        wp_safe_redirect( add_query_arg( 'saved', '1', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) );
        exit;
    }

    public static function handle_reset(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_RESET );
        MyNJILGA_Dues_Settings::reset_to_defaults();
        wp_safe_redirect( add_query_arg( 'reset', '1', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) );
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
