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

        MyNJILGA_Admin_UI::open(
            'Setup',
            'Environment checks, the core tag checklist, and the Dues & Billing audit against this FluentCRM instance.'
        );

        if ( ! empty( $_GET['created'] ) ) {
            MyNJILGA_Admin_UI::callout(
                sprintf( 'Created tag <strong>%s</strong>.', esc_html( sanitize_text_field( wp_unslash( $_GET['created'] ) ) ) ),
                'success'
            );
        }
        if ( ! empty( $_GET['create_error'] ) ) {
            MyNJILGA_Admin_UI::callout(
                sprintf( 'Could not create tag: %s', esc_html( sanitize_text_field( wp_unslash( $_GET['create_error'] ) ) ) ),
                'error'
            );
        }

        self::render_environment_section();

        if ( MyNJILGA_Members_Data::fluentcrm_active() ) {
            self::render_tag_checklist();
            self::render_settings_tag_audit();
            self::render_product_audit();
            self::render_all_tags();
        }

        self::render_stripe_reconciliation_section();
        self::render_stripe_needs_attention();

        self::render_shortcodes();

        MyNJILGA_Admin_UI::close();
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
        MyNJILGA_Admin_UI::section( 'Environment', 'What this plugin needs, and whether it is present on this install.' );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table njilga-kv"><tbody>';

        $fcrm = MyNJILGA_Members_Data::fluentcrm_active();
        printf(
            '<tr><th>FluentCRM core</th><td>%s</td></tr>',
            $fcrm
                ? MyNJILGA_Admin_UI::pill( 'Active', 'success' )
                : MyNJILGA_Admin_UI::pill( 'Not detected', 'destructive' ) . ' <span class="njilga-dim">install and activate FluentCRM.</span>'
        );

        if ( $fcrm ) {
            $companies = MyNJILGA_Members_Data::companies_module_active();
            $company_count = $companies ? (int) \FluentCrm\App\Models\Company::count() : 0;
            printf(
                '<tr><th>FluentCRM Companies module</th><td>%s</td></tr>',
                $companies
                    ? MyNJILGA_Admin_UI::pill( 'Active', 'success' ) . sprintf( ' <span class="njilga-dim">%d compan%s</span>', $company_count, $company_count === 1 ? 'y' : 'ies' )
                    : MyNJILGA_Admin_UI::pill( 'Not detected', 'warning' ) . ' <span class="njilga-dim">enable Companies in FluentCRM → Settings → Modules.</span>'
            );
        }

        $gateway = MyNJILGA_Invoicing::gateway();
        $ready   = $gateway->is_available() ? $gateway->readiness_errors() : [];
        printf(
            '<tr><th>%s (invoice gateway)</th><td>%s</td></tr>',
            esc_html( $gateway->name() ),
            ! $gateway->is_available()
                ? MyNJILGA_Admin_UI::pill( 'Not detected', 'warning' ) . ' <span class="njilga-dim">needed to create invoices; previews still work.</span>'
                : ( $ready
                    ? MyNJILGA_Admin_UI::pill( 'Active, not ready', 'warning' ) . ' <span class="njilga-dim">' . esc_html( $ready[0] ) . '</span>'
                    : MyNJILGA_Admin_UI::pill( 'Active and ready', 'success' ) )
        );

        printf(
            '<tr><th>Action Scheduler (background invoice creation)</th><td>%s</td></tr>',
            function_exists( 'as_enqueue_async_action' )
                ? MyNJILGA_Admin_UI::pill( 'Available', 'success' ) . ' <span class="njilga-dim">bundled with FluentCart / FluentCRM</span>'
                : MyNJILGA_Admin_UI::pill( 'Not available', 'warning' ) . ' <span class="njilga-dim">invoices will be created inline in one request.</span>'
        );

        $roles = [];
        foreach ( MyNJILGA_Dues_Settings::categories() as $cat ) {
            if ( $cat['role'] !== '' ) {
                $roles[ $cat['role'] ] = get_role( $cat['role'] ) ? true : false;
            }
        }
        $roleCells = [];
        foreach ( $roles as $slug => $exists ) {
            $roleCells[] = sprintf(
                '<code>%s</code> %s',
                esc_html( $slug ),
                $exists
                    ? MyNJILGA_Admin_UI::validation( 'defined', true )
                    : MyNJILGA_Admin_UI::validation( 'not defined on this site (payment can\'t grant it)', false )
            );
        }
        printf( '<tr><th>WordPress roles mapped in Settings</th><td>%s</td></tr>', $roleCells ? implode( '<br>', $roleCells ) : MyNJILGA_Admin_UI::blank() );

        echo '</tbody></table></div></div>';
    }

    private static function render_tag_checklist(): void {
        MyNJILGA_Admin_UI::section( 'Core report tags', 'Used by the report pages. Looked up by slug first, then by exact title.' );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>
                <th>Status</th><th>Title</th><th>Slug</th><th>Required?</th><th class="njilga-col-num">Subscribers</th><th></th>
              </tr></thead><tbody>';

        foreach ( MyNJILGA_Tags::DEFINITIONS as $slug => $def ) {
            $tag_id = MyNJILGA_Tags::id_for( $slug );
            printf(
                '<tr><td>%s</td><td><strong>%s</strong></td><td><code>%s</code></td><td>%s</td><td class="njilga-col-num">%s</td><td>%s</td></tr>',
                $tag_id !== null ? MyNJILGA_Admin_UI::pill( 'Found', 'success' ) : MyNJILGA_Admin_UI::pill( 'Missing', 'destructive' ),
                esc_html( $def['title'] ),
                esc_html( $def['slug'] ),
                $def['required'] ? 'Yes' : '<span class="njilga-dim">Optional</span>',
                esc_html( self::subscriber_count( $tag_id ) ),
                $tag_id === null ? self::create_button( $slug ) : sprintf( '<span class="njilga-dim">id %d</span>', $tag_id )
            );
        }

        echo '</tbody></table></div></div>';
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

        MyNJILGA_Admin_UI::section(
            'Dues & Billing tag audit',
            sprintf( 'Every tag slug the <a href="%s">Dues &amp; Billing settings</a> refer to, checked against this FluentCRM instance. Pricing matches on these exact slugs (with an exact-title fallback) — a slug that doesn\'t resolve silently matches nobody.', esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) )
        );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Status</th><th>Configured slug</th><th>Resolves to</th><th class="njilga-col-num">Subscribers</th><th>Used for</th><th></th></tr></thead><tbody>';
        $missing = 0;
        foreach ( $refs as $slug => $info ) {
            $id  = MyNJILGA_Tags::resolve_slug( $slug );
            $tag = $id ? \FluentCrm\App\Models\Tag::find( $id ) : null;
            if ( ! $id ) {
                $missing++;
            }
            printf(
                '<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td class="njilga-col-num">%s</td><td>%s</td><td>%s</td></tr>',
                $id ? MyNJILGA_Admin_UI::pill( 'Found', 'success' ) : MyNJILGA_Admin_UI::pill( 'Missing', 'destructive' ),
                esc_html( $slug ),
                $tag ? sprintf( '<strong>%s</strong> <span class="njilga-dim">(slug <code>%s</code>, id %d)%s</span>', esc_html( $tag->title ), esc_html( $tag->slug ), (int) $tag->id, $tag->slug !== $slug ? ' — matched by title' : '' ) : MyNJILGA_Admin_UI::blank(),
                esc_html( self::subscriber_count( $id ) ),
                esc_html( implode( '; ', array_unique( $info['uses'] ) ) ),
                $id ? '' : self::create_button( $slug )
            );
        }
        echo '</tbody></table></div></div>';
        if ( $missing > 0 ) {
            MyNJILGA_Admin_UI::callout(
                sprintf( '<strong>%d configured tag%s missing.</strong> Either create %s here (titled from the slug), or change the slug in Settings to match an existing tag from the list at the bottom of this page.', $missing, $missing === 1 ? ' is' : 's are', $missing === 1 ? 'it' : 'them' ),
                'error'
            );
        }
    }

    private static function render_product_audit(): void {
        $gateway = MyNJILGA_Invoicing::gateway();
        $s       = MyNJILGA_Dues_Settings::get();

        MyNJILGA_Admin_UI::section( $gateway->name() . ' product mapping' );
        if ( ! $gateway->is_available() ) {
            MyNJILGA_Admin_UI::callout(
                sprintf( '%s is not active — line items will be created as custom lines until products are mapped.', esc_html( $gateway->name() ) ),
                'warning'
            );
            return;
        }

        // Mirrors render_settings_tag_audit()'s intro immediately above this
        // section: this table only ever READS njilga_dues_settings — it has
        // no pickers of its own. Say so and point at where the pickers
        // actually are, or every-row-unmapped on a fresh install reads as
        // broken instead of as "nobody has configured Settings yet."
        printf(
            '<p class="njilga-section-desc">Read-only — reflects the picks on the <a href="%s">Dues &amp; Billing settings</a> page. A row reading "Not mapped" means no product/variation has been chosen there yet, not that something is broken.</p>',
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) )
        );

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Fee</th><th class="njilga-col-num">Charges</th><th>Mapped to</th><th>Status</th></tr></thead><tbody>';
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
                $status = MyNJILGA_Admin_UI::validation( 'Not mapped — will be a custom line item', false );
                $mapped = MyNJILGA_Admin_UI::blank();
            } else {
                $check  = $gateway->check_variation( $pid, $vid );
                $mapped = esc_html( $check['label'] !== '' ? $check['label'] : "#$pid / #$vid" );
                if ( ! $check['ok'] ) {
                    $status = MyNJILGA_Admin_UI::validation( (string) ( $check['error'] ?? 'invalid' ), false );
                } elseif ( (int) $check['price_cents'] !== $cents ) {
                    $status = MyNJILGA_Admin_UI::validation( sprintf( 'Exists, but %s price is %s — invoices charge the Settings price', $gateway->name(), MyNJILGA_Invoicing::money( (int) $check['price_cents'] ) ), false );
                } else {
                    $status = MyNJILGA_Admin_UI::validation( 'OK', true );
                }
            }
            printf( '<tr><td>%s</td><td class="njilga-col-num">%s</td><td>%s</td><td>%s</td></tr>', esc_html( $label ), esc_html( MyNJILGA_Invoicing::money( $cents ) ), $mapped, $status );
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * Stripe migration phase 4 (reconciler) — connection health for the
     * active mode, plus a collapsed diagnostic log of recent Stripe API
     * requests. What the reconciler and the Invoicing page's "Sync with
     * Stripe" button rely on being healthy; the invoices that couldn't be
     * resolved automatically are their own section (render_stripe_needs_attention()).
     */
    private static function render_stripe_reconciliation_section(): void {
        MyNJILGA_Admin_UI::section(
            'Stripe reconciliation',
            'Connection health for the active Stripe mode, and a diagnostic log of recent Stripe API activity.'
        );

        $healthErrors = MyNJILGA_Stripe_Connection::health();
        if ( empty( $healthErrors ) ) {
            MyNJILGA_Admin_UI::callout( 'Stripe connection is healthy.', 'success' );
        } else {
            foreach ( $healthErrors as $err ) {
                MyNJILGA_Admin_UI::callout( esc_html( $err ), 'warning' );
            }
        }

        self::render_stripe_request_log();
    }

    private static function render_stripe_request_log(): void {
        $requests = array_slice( MyNJILGA_Stripe_Client::recent_requests(), 0, 20 );

        echo '<details class="njilga-details"><summary>' . MyNJILGA_Admin_UI::icon( 'refresh' ) . ' Recent Stripe API requests (last 100, showing 20)</summary>';
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table njilga-table-compact"><thead><tr>
                <th>Timestamp</th><th>Method</th><th>Path</th><th class="njilga-col-num">Status</th><th>Request id</th>
              </tr></thead><tbody>';

        if ( empty( $requests ) ) {
            echo '<tr class="njilga-emptyrow"><td colspan="5">No Stripe API requests recorded yet.</td></tr>';
        } else {
            foreach ( $requests as $r ) {
                $status = (int) ( $r['status'] ?? 0 );
                $ok     = $status >= 200 && $status < 300;
                printf(
                    '<tr><td class="njilga-nowrap">%s</td><td>%s</td><td><code>%s</code></td><td class="njilga-col-num">%s</td><td>%s</td></tr>',
                    esc_html( (string) ( $r['at'] ?? '' ) ),
                    esc_html( (string) ( $r['method'] ?? '' ) ),
                    esc_html( (string) ( $r['path'] ?? '' ) ),
                    $ok ? esc_html( (string) $status ) : MyNJILGA_Admin_UI::status( (string) $status, 'bad' ),
                    ( $r['request_id'] ?? '' ) !== '' ? esc_html( (string) $r['request_id'] ) : MyNJILGA_Admin_UI::blank()
                );
            }
        }

        echo '</tbody></table></div></div></details>';
    }

    /**
     * Every invoice row (any status except excluded, any year) in the
     * active mode currently carrying a last_error — set by the Stripe
     * webhook receiver, the reconciler, or a failed send. There is no
     * clean way to tell those sources apart from last_error alone, so
     * this simply lists everything flagged; the error text itself says
     * what went wrong.
     */
    private static function render_stripe_needs_attention(): void {
        MyNJILGA_Admin_UI::section(
            'Needs attention',
            'Every invoice — any status, any year, except excluded — currently carrying an error from a payment event, a failed send, or the reconciler.'
        );

        $livemode = ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );
        $rows     = MyNJILGA_Dues_Invoice_Table::get_flagged( $livemode );

        if ( empty( $rows ) ) {
            MyNJILGA_Admin_UI::callout( 'No invoices currently flagged for review.', 'success' );
            return;
        }

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>
                <th>Firm</th><th class="njilga-col-num">Dues year</th><th>Status</th><th>Error</th>
              </tr></thead><tbody>';
        foreach ( $rows as $row ) {
            printf(
                '<tr><td>%s</td><td class="njilga-col-num">%d</td><td>%s</td><td>%s</td></tr>',
                esc_html( MyNJILGA_Dues_Snapshot::company_name( $row ) ),
                (int) $row->dues_year,
                MyNJILGA_Admin_UI::pill( ucfirst( (string) $row->status ), 'muted' ),
                esc_html( (string) $row->last_error )
            );
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_all_tags(): void {
        $tags = MyNJILGA_Tags::all_tags();
        echo '<details class="njilga-details"><summary>' . MyNJILGA_Admin_UI::icon( 'tag' ) . ' All FluentCRM tags on this install (' . count( $tags ) . ') — for documenting the exact slugs</summary>';
        echo '<div class="njilga-card njilga-table-boxed" style="margin-top:10px;max-width:760px"><div class="njilga-tablewrap"><table class="njilga-table njilga-table-compact"><thead><tr><th>Title</th><th>Slug</th><th class="njilga-col-num">id</th></tr></thead><tbody>';
        foreach ( $tags as $t ) {
            printf( '<tr><td>%s</td><td><code>%s</code></td><td class="njilga-col-num njilga-dim">%d</td></tr>', esc_html( $t['title'] ), esc_html( $t['slug'] ), $t['id'] );
        }
        echo '</tbody></table></div></div></details>';
    }

    private static function render_shortcodes(): void {
        MyNJILGA_Admin_UI::section( 'Shortcodes', 'Drop these on any page to expose the public-facing parts of the plugin.' );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table njilga-kv"><tbody>';
        echo '<tr><th><code>[njilga_membership_application]</code></th><td>Public membership application form with firm autocomplete. Applicants land in <strong>My NJILGA → Applications</strong> and are never invoiced until approved.</td></tr>';
        echo '<tr><th><code>[njilga_firm_dues_status]</code></th><td>Member-facing dues status: logged-in member sees their firm\'s invoices, full roster, amounts and payment link.</td></tr>';
        echo '</tbody></table></div></div>';
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
        return MyNJILGA_Admin_UI::action_form(
            self::ACTION_CREATE_TAG,
            'Create',
            [ 'slug' => $slug ],
            'primary'
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
