<?php
/**
 * My NJILGA → Settings → Dues & Billing (spec §3).
 *
 * One big server-rendered form, saved through admin-post. Sections:
 *   1. General switches (default category, inactive tag, evergreen tags,
 *      CC policy, downgrade behaviour, mid-year join policy, enrollment).
 *   2. Category mapping table — ORDERED (precedence), each row:
 *      tag → FluentCart product/variation → WP role → tier-eligible flag,
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

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        $s        = MyNJILGA_Dues_Settings::get();
        $gateway  = MyNJILGA_Invoicing::gateway();
        $products = $gateway->is_available() ? $gateway->list_products() : [];
        $tags     = MyNJILGA_Members_Data::fluentcrm_active() ? MyNJILGA_Tags::all_tags() : [];
        $roles    = self::wp_roles();

        MyNJILGA_Admin_UI::open(
            'Dues & Billing Settings',
            sprintf( 'FluentCRM tags are the source of truth for who owes what; WordPress roles are a downstream effect of payment, never an input to pricing. Prices below (in dollars) are what invoices charge; the mapped product/variation is what each line item points at in %s.', $gateway->name() )
        );

        if ( ! empty( $_GET['saved'] ) ) {
            MyNJILGA_Admin_UI::callout( 'Settings saved.', 'success' );
        }
        if ( ! empty( $_GET['reset'] ) ) {
            MyNJILGA_Admin_UI::callout( 'Settings reset to the seeded defaults.', 'success' );
        }

        if ( ! $gateway->is_available() ) {
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>%s is not active</strong> — product pickers are empty. Categories still price correctly; line items will be created as custom lines until products are mapped.', esc_html( $gateway->name() ) ), 'warning' );
        } elseif ( empty( $products ) ) {
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>No %s products found.</strong> Create the dues products first (Professional Membership with its three variations, Emerging Professional, Law Student, Past President (Exempt), Senior Trustee (Exempt), Trustee Dinner Assessment), then map them here.', esc_html( $gateway->name() ) ), 'warning' );
        }

        self::render_tag_datalist( $tags );

        printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
        printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION_SAVE ) );
        wp_nonce_field( self::ACTION_SAVE );

        self::render_general( $s, $tags );
        self::render_categories( $s, $products, $tags, $roles, $gateway );
        self::render_assessment( $s, $products, $tags, $gateway );
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

        MyNJILGA_Admin_UI::close();
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

    private static function render_categories( array $s, array $products, array $tags, array $roles, MyNJILGA_Invoice_Gateway $gateway ): void {
        MyNJILGA_Admin_UI::section(
            'Membership categories',
            'Rows are matched in <strong>Order</strong> — a contact carrying two category tags belongs to the first one listed (so exempt categories come before Professional). <strong>Tier-eligible</strong> categories are ranked alphabetically within the firm and priced by rank using the tier table; everything else is flat-priced and never occupies a paid slot. <strong>Role</strong> is granted on payment, best-effort.'
        );

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>
                <th style="width:70px">Order</th><th style="min-width:200px">Label</th><th style="min-width:180px">Tag slug</th><th style="min-width:260px">Product / variation</th><th style="width:100px">Price ($)</th><th>Role</th><th style="width:80px">Tier-eligible</th><th style="width:90px">Applicant may pick</th><th style="width:60px">Delete</th>
              </tr></thead><tbody>';

        $rows = $s['categories'];
        $rows[] = [ 'key' => '', 'label' => '', 'tag' => '', 'product_id' => 0, 'variation_id' => 0, 'price_cents' => 0, 'role' => '', 'tier_eligible' => false, 'applicant_selectable' => true, 'tiers' => [] ];
        foreach ( $rows as $i => $cat ) {
            $isNew = $cat['key'] === '';
            $n     = "categories[$i]";
            echo '<tr' . ( $isNew ? ' class="njilga-newrow"' : '' ) . '>';
            printf( '<td><input type="number" name="%s[order]" value="%d" min="0" class="njilga-input-sm" style="width:64px"></td>', $n, $isNew ? 999 : $i + 1 );
            printf( '<td><input type="text" name="%s[label]" value="%s" placeholder="%s" class="njilga-full">%s</td>', $n, esc_attr( $cat['label'] ), $isNew ? 'New category label…' : '', $isNew ? '' : sprintf( '<input type="hidden" name="%s[key]" value="%s"><div class="njilga-dim" style="font-size:11px;margin-top:4px">key: %s</div>', $n, esc_attr( $cat['key'] ), esc_html( $cat['key'] ) ) );
            printf( '<td><input type="text" list="njilga-tags" name="%s[tag]" value="%s" class="njilga-full">%s</td>', $n, esc_attr( $cat['tag'] ), self::tag_check( $cat['tag'] ) );
            printf( '<td>%s%s</td>', self::product_select( "{$n}[product]", (int) $cat['product_id'], (int) $cat['variation_id'], $products ), self::variation_check( $gateway, (int) $cat['product_id'], (int) $cat['variation_id'], (int) $cat['price_cents'] ) );
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
                    [ 'key' => 'first',  'label' => '1st Member',  'from' => 1, 'to' => 1, 'price_cents' => 0, 'variation_id' => 0 ],
                    [ 'key' => '2_to_5', 'label' => 'Members 2–5', 'from' => 2, 'to' => 5, 'price_cents' => 0, 'variation_id' => 0 ],
                    [ 'key' => '6_plus', 'label' => 'Members 6+',  'from' => 6, 'to' => 0, 'price_cents' => 0, 'variation_id' => 0 ],
                ];
            }
            $tiers[] = [ 'key' => '', 'label' => '', 'from' => 0, 'to' => 0, 'price_cents' => 0, 'variation_id' => 0 ];
            echo '<tr><td></td><td colspan="8" style="padding:0 14px 12px"><details class="njilga-details" style="margin:0"' . ( ! empty( $cat['tier_eligible'] ) ? ' open' : '' ) . '><summary>' . MyNJILGA_Admin_UI::icon( 'sliders' ) . ' Tier pricing by rank (used only when Tier-eligible is checked)</summary>';
            echo '<div class="njilga-card njilga-table-boxed" style="margin-top:8px;max-width:940px"><div class="njilga-tablewrap"><table class="njilga-table njilga-table-compact"><thead><tr><th>Tier label</th><th style="width:90px">From rank</th><th style="width:110px">To rank (0 = open)</th><th style="width:100px">Price ($)</th><th>Variation</th></tr></thead><tbody>';
            foreach ( $tiers as $j => $t ) {
                $tn = "{$n}[tiers][$j]";
                echo '<tr>';
                printf( '<td><input type="text" name="%s[label]" value="%s" class="njilga-full njilga-input-sm" placeholder="%s"><input type="hidden" name="%s[key]" value="%s"></td>', $tn, esc_attr( $t['label'] ), $t['key'] === '' ? 'Add a tier…' : '', $tn, esc_attr( $t['key'] ) );
                printf( '<td><input type="number" name="%s[from]" value="%d" min="0" class="njilga-input-sm" style="width:76px"></td>', $tn, (int) $t['from'] );
                printf( '<td><input type="number" name="%s[to]" value="%d" min="0" class="njilga-input-sm" style="width:76px"></td>', $tn, (int) $t['to'] );
                printf( '<td><input type="number" step="0.01" min="0" name="%s[price]" value="%s" class="njilga-input-sm" style="width:92px"></td>', $tn, esc_attr( number_format( $t['price_cents'] / 100, 2, '.', '' ) ) );
                printf( '<td>%s%s</td>', self::product_select( "{$tn}[product]", (int) $cat['product_id'], (int) $t['variation_id'], $products, true ), $t['key'] !== '' ? self::variation_check( $gateway, 0, (int) $t['variation_id'], (int) $t['price_cents'] ) : '' );
                echo '</tr>';
            }
            echo '</tbody></table></div></div></details></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_assessment( array $s, array $products, array $tags, MyNJILGA_Invoice_Gateway $gateway ): void {
        $a = $s['assessment'];
        MyNJILGA_Admin_UI::section(
            'Assessment',
            'One flat charge per qualifying ACTIVE contact, on top of their dues (an exempt Senior Trustee still owes it). Qualifying tags are matched in order; the first one a contact carries labels their line. Capped at one per person.'
        );
        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';
        self::text_row( 'assessment[label]', 'Label', $a['label'], 'Printed on the invoice line, e.g. "Trustee Dinner Assessment".' );
        echo '<tr><th scope="row">Product / variation</th><td>' . self::product_select( 'assessment[product]', (int) $a['product_id'], (int) $a['variation_id'], $products ) . self::variation_check( $gateway, (int) $a['product_id'], (int) $a['variation_id'], (int) $a['price_cents'] ) . '</td></tr>';
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

    private static function variation_check( MyNJILGA_Invoice_Gateway $gateway, int $productId, int $variationId, int $priceCents ): string {
        if ( $variationId <= 0 ) {
            return '<div class="njilga-note-warn">no product mapped — line will be created as a custom line</div>';
        }
        if ( ! $gateway->is_available() ) {
            return '';
        }
        $check = $gateway->check_variation( $productId, $variationId );
        if ( ! $check['ok'] ) {
            return sprintf( '<div class="njilga-note-bad">&#10007; %s</div>', esc_html( (string) ( $check['error'] ?? 'invalid' ) ) );
        }
        $out = sprintf( '<div class="njilga-note-ok">&#10003; %s (%s)</div>', esc_html( $check['label'] ), esc_html( MyNJILGA_Invoicing::money( (int) $check['price_cents'] ) ) );
        if ( (int) $check['price_cents'] !== $priceCents ) {
            $out .= sprintf( '<div class="njilga-note-warn">price differs from %s (%s) — the price here is what\'s charged</div>', esc_html( $gateway->name() ), esc_html( MyNJILGA_Invoicing::money( (int) $check['price_cents'] ) ) );
        }
        return $out;
    }

    /**
     * "product:variation" select. With $variationsOnlyForProduct, limits
     * options to the given product's variations (tier rows).
     */
    private static function product_select( string $name, int $productId, int $variationId, array $products, bool $variationsOnlyForProduct = false ): string {
        $html = sprintf( '<select name="%s" style="max-width:100%%"><option value="">— none —</option>', esc_attr( $name ) );
        $found = false;
        foreach ( $products as $p ) {
            if ( $variationsOnlyForProduct && $productId > 0 && (int) $p['id'] !== $productId ) {
                continue;
            }
            $status = $p['status'] !== 'publish' ? ' [' . $p['status'] . ']' : '';
            foreach ( $p['variations'] as $v ) {
                $val = $p['id'] . ':' . $v['id'];
                $sel = ( (int) $v['id'] === $variationId ) ? ' selected' : '';
                if ( $sel ) {
                    $found = true;
                }
                $html .= sprintf(
                    '<option value="%s"%s>%s — %s (%s)%s</option>',
                    esc_attr( $val ),
                    $sel,
                    esc_html( $p['title'] ),
                    esc_html( $v['title'] !== '' ? $v['title'] : 'default' ),
                    esc_html( MyNJILGA_Invoicing::money( (int) $v['price_cents'] ) ),
                    esc_html( $status )
                );
            }
        }
        if ( ! $found && $variationId > 0 ) {
            $html .= sprintf( '<option value="%d:%d" selected>(mapped: product #%d / variation #%d — not in list)</option>', $productId, $variationId, $productId, $variationId );
        }
        return $html . '</select>';
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

            [ $productId, $variationId ] = self::parse_product( (string) ( $row['product'] ?? '' ) );

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
                [ , $tVar ] = self::parse_product( (string) ( $t['product'] ?? '' ) );
                $tiers[] = [
                    'key'          => $tKey,
                    'label'        => $tLabel !== '' ? $tLabel : ( 'Rank ' . $from ),
                    'from'         => $from,
                    'to'           => max( 0, (int) ( $t['to'] ?? 0 ) ),
                    'price_cents'  => self::dollars_to_cents( $t['price'] ?? 0 ),
                    'variation_id' => $tVar,
                ];
            }

            $categories[] = [
                '_order'               => (int) ( $row['order'] ?? 999 ),
                'key'                  => $key,
                'label'                => $label !== '' ? $label : $key,
                'tag'                  => sanitize_title( (string) ( $row['tag'] ?? '' ) ),
                'product_id'           => $productId,
                'variation_id'         => $variationId,
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
        $a  = (array) ( $in['assessment'] ?? [] );
        [ $aProd, $aVar ] = self::parse_product( (string) ( $a['product'] ?? '' ) );
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
            'product_id'   => $aProd,
            'variation_id' => $aVar,
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

    /**
     * @return array{0:int,1:int} [product_id, variation_id]
     */
    private static function parse_product( string $value ): array {
        if ( $value === '' || strpos( $value, ':' ) === false ) {
            return [ 0, 0 ];
        }
        [ $p, $v ] = array_map( 'intval', explode( ':', $value, 2 ) );
        return [ max( 0, $p ), max( 0, $v ) ];
    }

    private static function dollars_to_cents( $value ): int {
        $value = str_replace( [ ',', '$', ' ' ], '', (string) $value );
        return max( 0, (int) round( (float) $value * 100 ) );
    }
}
