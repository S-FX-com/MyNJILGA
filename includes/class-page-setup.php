<?php
/**
 * Setup — environment checks, the plugin's core tag checklist, and the
 * Dues & Billing audit: every tag slug the settings refer to (does it
 * exist on THIS FluentCRM instance?) and every mapped FluentCart product
 * (does it exist, is it published, does the price match?). This is the
 * "confirm and document the exact FluentCRM tag slugs against the live
 * instance" setup step (spec §3.3) made into a page.
 */
class MyNJILGA_Page_Setup {

    const ACTION_CREATE_TAG = 'my_njilga_create_tag';

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        echo '<div class="wrap"><h1>My NJILGA — Setup</h1>';

        if ( ! empty( $_GET['created'] ) ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>Created tag <strong>%s</strong>.</p></div>',
                esc_html( sanitize_text_field( wp_unslash( $_GET['created'] ) ) )
            );
        }
        if ( ! empty( $_GET['create_error'] ) ) {
            printf(
                '<div class="notice notice-error"><p>Could not create tag: %s</p></div>',
                esc_html( sanitize_text_field( wp_unslash( $_GET['create_error'] ) ) )
            );
        }

        self::render_environment_section();

        if ( MyNJILGA_Members_Data::fluentcrm_active() ) {
            self::render_tag_checklist();
            self::render_settings_tag_audit();
            self::render_product_audit();
            self::render_all_tags();
        }

        self::render_shortcodes();

        echo '</div>';
    }

    /**
     * admin-post handler: create a tag via the FluentCRM Tags API. Accepts
     * the plugin's core slugs AND any slug the Dues & Billing settings
     * refer to.
     */
    public static function handle_create_tag(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_CREATE_TAG );

        $slug   = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
        $defs   = MyNJILGA_Tags::DEFINITIONS;
        $return = MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP );

        if ( ! MyNJILGA_Members_Data::fluentcrm_active() || ! function_exists( 'FluentCrmApi' ) ) {
            wp_safe_redirect( add_query_arg( 'create_error', 'FluentCRM not active', $return ) );
            exit;
        }

        if ( isset( $defs[ $slug ] ) ) {
            $tag = MyNJILGA_Tags::create( $slug );
            if ( ! $tag ) {
                wp_safe_redirect( add_query_arg( 'create_error', 'creation failed', $return ) );
                exit;
            }
            wp_safe_redirect( add_query_arg( 'created', $defs[ $slug ]['title'], $return ) );
            exit;
        }

        if ( in_array( $slug, MyNJILGA_Dues_Settings::referenced_tag_slugs(), true ) ) {
            $title = self::settings_title_for( $slug );
            $id    = MyNJILGA_Tags::get_or_create_by_title( $title, $slug );
            MyNJILGA_Tags::flush_cache();
            if ( ! $id ) {
                wp_safe_redirect( add_query_arg( 'create_error', 'creation failed', $return ) );
                exit;
            }
            wp_safe_redirect( add_query_arg( 'created', $title, $return ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( 'create_error', 'unknown tag', $return ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    private static function render_environment_section(): void {
        echo '<h2>Environment</h2>';
        echo '<table class="widefat striped" style="max-width:760px"><tbody>';

        $fcrm = MyNJILGA_Members_Data::fluentcrm_active();
        printf(
            '<tr><td style="width:280px">FluentCRM core</td><td>%s</td></tr>',
            $fcrm
                ? '<strong style="color:#1d6f42">Active</strong>'
                : '<strong style="color:#d63638">Not detected</strong> — install and activate FluentCRM.'
        );

        if ( $fcrm ) {
            $companies = MyNJILGA_Members_Data::companies_module_active();
            $company_count = $companies ? (int) \FluentCrm\App\Models\Company::count() : 0;
            printf(
                '<tr><td>FluentCRM Companies module</td><td>%s</td></tr>',
                $companies
                    ? sprintf( '<strong style="color:#1d6f42">Active</strong> <span style="color:#888">(%d compan%s)</span>', $company_count, $company_count === 1 ? 'y' : 'ies' )
                    : '<strong style="color:#b26200">Not detected</strong> — enable Companies in FluentCRM → Settings → Modules.'
            );
        }

        $gateway = MyNJILGA_Invoicing::gateway();
        $ready   = $gateway->is_available() ? $gateway->readiness_errors() : [];
        printf(
            '<tr><td>%s (invoice gateway)</td><td>%s</td></tr>',
            esc_html( $gateway->name() ),
            ! $gateway->is_available()
                ? '<strong style="color:#b26200">Not detected</strong> — needed to create invoices; preview/approve still work.'
                : ( $ready ? '<strong style="color:#b26200">Active, not ready:</strong> ' . esc_html( $ready[0] ) : '<strong style="color:#1d6f42">Active and ready</strong>' )
        );

        printf(
            '<tr><td>Action Scheduler (background invoice creation)</td><td>%s</td></tr>',
            function_exists( 'as_enqueue_async_action' )
                ? '<strong style="color:#1d6f42">Available</strong> <span style="color:#888">(bundled with FluentCart / FluentCRM)</span>'
                : '<strong style="color:#b26200">Not available</strong> — invoices will be created inline in one request.'
        );

        $roles = [];
        foreach ( MyNJILGA_Dues_Settings::categories() as $cat ) {
            if ( $cat['role'] !== '' ) {
                $roles[ $cat['role'] ] = get_role( $cat['role'] ) ? true : false;
            }
        }
        $roleCells = [];
        foreach ( $roles as $slug => $exists ) {
            $roleCells[] = sprintf( '<code>%s</code> %s', esc_html( $slug ), $exists ? '<span style="color:#1d6f42">✓</span>' : '<span style="color:#d63638">✗ not defined on this site (payment can\'t grant it)</span>' );
        }
        printf( '<tr><td>WordPress roles mapped in Settings</td><td>%s</td></tr>', $roleCells ? implode( '<br>', $roleCells ) : '<span style="color:#888">none</span>' );

        echo '</tbody></table>';
    }

    private static function render_tag_checklist(): void {
        echo '<h2 style="margin-top:24px">Core report tags</h2>';
        echo '<p style="color:#646970">Used by the report pages. Looked up by slug first, then by exact title.</p>';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>
                <th>Status</th><th>Title</th><th>Slug</th><th>Required?</th><th>Subscribers</th><th></th>
              </tr></thead><tbody>';

        foreach ( MyNJILGA_Tags::DEFINITIONS as $slug => $def ) {
            $tag_id = MyNJILGA_Tags::id_for( $slug );
            printf(
                '<tr><td>%s</td><td><strong>%s</strong></td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $tag_id !== null ? '<strong style="color:#1d6f42">✓ Found</strong>' : '<strong style="color:#d63638">✗ Missing</strong>',
                esc_html( $def['title'] ),
                esc_html( $def['slug'] ),
                $def['required'] ? 'Yes' : 'Optional',
                esc_html( self::subscriber_count( $tag_id ) ),
                $tag_id === null ? self::create_button( $slug ) : sprintf( '<span style="color:#888">id %d</span>', $tag_id )
            );
        }

        echo '</tbody></table>';
    }

    /**
     * Every slug the Dues & Billing settings refer to, resolved against
     * the live instance — the setup-step-2 audit.
     */
    private static function render_settings_tag_audit(): void {
        $s = MyNJILGA_Dues_Settings::get();
        $refs = [];
        $add = static function ( string $slug, string $use ) use ( &$refs ) {
            if ( $slug === '' ) {
                return;
            }
            $refs[ $slug ]['uses'][] = $use;
        };
        $add( (string) $s['general']['inactive_tag'], 'Inactive override' );
        $add( (string) $s['general']['paid_tag'], 'Evergreen paid' );
        $add( (string) $s['general']['unpaid_tag'], 'Evergreen unpaid' );
        $add( (string) $s['general']['pending_tag'], 'Application pending' );
        $add( (string) $s['general']['rejected_tag'], 'Application rejected' );
        foreach ( $s['categories'] as $cat ) {
            $add( (string) $cat['tag'], 'Category: ' . $cat['label'] );
        }
        foreach ( $s['assessment']['qualifiers'] as $q ) {
            $add( (string) $q['tag'], 'Assessment qualifier: ' . $q['label'] );
        }

        printf( '<h2 style="margin-top:24px">Dues &amp; Billing tag audit</h2><p style="color:#646970">Every tag slug the <a href="%s">Dues &amp; Billing settings</a> refer to, checked against this FluentCRM instance. Pricing matches on these exact slugs (with an exact-title fallback) — a slug that doesn\'t resolve silently matches nobody.</p>', esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) );
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Status</th><th>Configured slug</th><th>Resolves to</th><th>Subscribers</th><th>Used for</th><th></th></tr></thead><tbody>';
        $missing = 0;
        foreach ( $refs as $slug => $info ) {
            $id  = MyNJILGA_Tags::resolve_slug( $slug );
            $tag = $id ? \FluentCrm\App\Models\Tag::find( $id ) : null;
            if ( ! $id ) {
                $missing++;
            }
            printf(
                '<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $id ? '<strong style="color:#1d6f42">✓ Found</strong>' : '<strong style="color:#d63638">✗ Missing</strong>',
                esc_html( $slug ),
                $tag ? sprintf( '<strong>%s</strong> <span style="color:#888">(slug <code>%s</code>, id %d)%s</span>', esc_html( $tag->title ), esc_html( $tag->slug ), (int) $tag->id, $tag->slug !== $slug ? ' — matched by title' : '' ) : '—',
                esc_html( self::subscriber_count( $id ) ),
                esc_html( implode( '; ', array_unique( $info['uses'] ) ) ),
                $id ? '' : self::create_button( $slug )
            );
        }
        echo '</tbody></table>';
        if ( $missing > 0 ) {
            printf( '<p style="color:#d63638"><strong>%d configured tag%s missing.</strong> Either create %s here (titled from the slug), or change the slug in Settings to match an existing tag from the list at the bottom of this page.</p>', $missing, $missing === 1 ? ' is' : 's are', $missing === 1 ? 'it' : 'them' );
        }
    }

    private static function render_product_audit(): void {
        $gateway = MyNJILGA_Invoicing::gateway();
        $s       = MyNJILGA_Dues_Settings::get();

        printf( '<h2 style="margin-top:24px">%s product mapping</h2>', esc_html( $gateway->name() ) );
        if ( ! $gateway->is_available() ) {
            printf( '<p style="color:#b26200">%s is not active — line items will be created as custom lines until products are mapped.</p>', esc_html( $gateway->name() ) );
            return;
        }

        // Mirrors render_settings_tag_audit()'s intro immediately above this
        // section: this table only ever READS njilga_dues_settings — it has
        // no pickers of its own. Say so and point at where the pickers
        // actually are, or every-row-unmapped on a fresh install reads as
        // broken instead of as "nobody has configured Settings yet."
        printf(
            '<p style="color:#646970">Read-only — reflects the picks on the <a href="%s">Dues &amp; Billing settings</a> page. A row reading "Not mapped" means no product/variation has been chosen there yet, not that something is broken.</p>',
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) )
        );

        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Fee</th><th>Charges</th><th>Mapped to</th><th>Status</th></tr></thead><tbody>';
        $rows = [];
        foreach ( $s['categories'] as $cat ) {
            if ( ! empty( $cat['tier_eligible'] ) && ! empty( $cat['tiers'] ) ) {
                foreach ( $cat['tiers'] as $t ) {
                    $rows[] = [ $cat['label'] . ' — ' . $t['label'], (int) $t['price_cents'], (int) $cat['product_id'], (int) ( $t['variation_id'] ?: $cat['variation_id'] ) ];
                }
            } else {
                $rows[] = [ $cat['label'], (int) $cat['price_cents'], (int) $cat['product_id'], (int) $cat['variation_id'] ];
            }
        }
        $rows[] = [ $s['assessment']['label'], (int) $s['assessment']['price_cents'], (int) $s['assessment']['product_id'], (int) $s['assessment']['variation_id'] ];

        foreach ( $rows as [ $label, $cents, $pid, $vid ] ) {
            if ( $vid <= 0 ) {
                $status = '<span style="color:#b26200">Not mapped — will be a custom line item</span>';
                $mapped = '—';
            } else {
                $check  = $gateway->check_variation( $pid, $vid );
                $mapped = esc_html( $check['label'] !== '' ? $check['label'] : "#$pid / #$vid" );
                if ( ! $check['ok'] ) {
                    $status = '<span style="color:#d63638">✗ ' . esc_html( (string) ( $check['error'] ?? 'invalid' ) ) . '</span>';
                } elseif ( (int) $check['price_cents'] !== $cents ) {
                    $status = sprintf( '<span style="color:#b26200">✓ exists, but %s price is %s — invoices charge the Settings price</span>', esc_html( $gateway->name() ), esc_html( MyNJILGA_Invoicing::money( (int) $check['price_cents'] ) ) );
                } else {
                    $status = '<span style="color:#1d6f42">✓ OK</span>';
                }
            }
            printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $label ), esc_html( MyNJILGA_Invoicing::money( $cents ) ), $mapped, $status );
        }
        echo '</tbody></table>';
    }

    private static function render_all_tags(): void {
        $tags = MyNJILGA_Tags::all_tags();
        echo '<details style="margin-top:24px"><summary style="cursor:pointer;font-size:14px;font-weight:600">All FluentCRM tags on this install (' . count( $tags ) . ') — for documenting the exact slugs</summary>';
        echo '<table class="widefat striped" style="max-width:700px;margin-top:8px"><thead><tr><th>Title</th><th>Slug</th><th>id</th></tr></thead><tbody>';
        foreach ( $tags as $t ) {
            printf( '<tr><td>%s</td><td><code>%s</code></td><td style="color:#888">%d</td></tr>', esc_html( $t['title'] ), esc_html( $t['slug'] ), $t['id'] );
        }
        echo '</tbody></table></details>';
    }

    private static function render_shortcodes(): void {
        echo '<h2 style="margin-top:24px">Shortcodes</h2>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><td style="width:320px"><code>[njilga_membership_application]</code></td><td>Public membership application form with firm autocomplete. Applicants land in <strong>My NJILGA → Applications</strong> and are never invoiced until approved.</td></tr>';
        echo '<tr><td><code>[njilga_firm_dues_status]</code></td><td>Member-facing dues status: logged-in member sees their firm\'s invoices, full roster, amounts and payment link.</td></tr>';
        echo '</tbody></table>';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function subscriber_count( ?int $tagId ): string {
        if ( ! $tagId ) {
            return '—';
        }
        $tag = \FluentCrm\App\Models\Tag::find( $tagId );
        if ( $tag && method_exists( $tag, 'subscribers' ) ) {
            return (string) (int) $tag->subscribers()->count();
        }
        return '—';
    }

    private static function create_button( string $slug ): string {
        return sprintf(
            '<form method="post" action="%s" style="margin:0">%s<input type="hidden" name="action" value="%s"><input type="hidden" name="slug" value="%s"><button type="submit" class="button button-primary">Create</button></form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            wp_nonce_field( self::ACTION_CREATE_TAG, '_wpnonce', true, false ),
            esc_attr( self::ACTION_CREATE_TAG ),
            esc_attr( $slug )
        );
    }

    /**
     * Best title for a settings-referenced slug: the category/qualifier
     * label when the slug is one of theirs, else derived from the slug.
     */
    private static function settings_title_for( string $slug ): string {
        foreach ( MyNJILGA_Dues_Settings::assessment()['qualifiers'] as $q ) {
            if ( $q['tag'] === $slug && $q['label'] !== '' ) {
                return $q['label'];
            }
        }
        return MyNJILGA_Tags::title_for_slug( $slug );
    }
}
