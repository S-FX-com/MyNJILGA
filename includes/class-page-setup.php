<?php
/**
 * Setup — environment checks, the plugin's core tag checklist, the
 * Dues & Billing tag audit (every tag slug the settings refer to — does
 * it exist on THIS FluentCRM instance?), and Stripe connection health.
 * This is the "confirm and document the exact FluentCRM tag slugs
 * against the live instance" setup step (spec §3.3) made into a page.
 */
class MyNJILGA_Page_Setup {

    const ACTION_CREATE_TAG = 'my_njilga_create_tag';

    // Nonce action (per mode) for the Online joining table's Re-check link.
    const ACTION_RECHECK_CHECKOUT = 'my_njilga_recheck_checkout';

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
        self::maybe_recheck_checkout();

        self::render_environment_section();

        if ( MyNJILGA_Members_Data::fluentcrm_active() ) {
            self::render_tag_checklist();
            self::render_settings_tag_audit();
            self::render_all_tags();
        }

        self::render_stripe_reconciliation_section();
        self::render_stripe_events();
        self::render_stripe_needs_attention();
        self::render_stripe_orphans();

        self::render_online_joining();
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

    /**
     * The Online joining table's Re-check link: ask Stripe again whether
     * a mode's key may create Checkout Sessions, now, instead of waiting
     * out the stored answer — the way back after staff give the key
     * "Checkout Sessions: Write". A nonce'd GET on this page, since all it
     * does is refresh a cached answer; handled before anything renders,
     * so the table below already shows the new one.
     */
    private static function maybe_recheck_checkout(): void {
        $mode = isset( $_GET['recheck_checkout'] ) ? sanitize_key( wp_unslash( $_GET['recheck_checkout'] ) ) : '';
        if ( ! in_array( $mode, [ MyNJILGA_Stripe_Connection::MODE_LIVE, MyNJILGA_Stripe_Connection::MODE_TEST ], true ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::ACTION_RECHECK_CHECKOUT . '_' . $mode ) ) {
            MyNJILGA_Admin_UI::callout( 'That Re-check link has expired — use the Re-check button below again.', 'warning' );
            return;
        }
        if ( ! MyNJILGA_Stripe_Connection::is_connected( $mode ) ) {
            return;
        }
        $access = MyNJILGA_Stripe_Connection::recheck_checkout_access( $mode );
        if ( $access === true ) {
            MyNJILGA_Admin_UI::callout( sprintf( 'Re-checked: the %s key can create Checkout Sessions.', esc_html( ucfirst( $mode ) ) ), 'success' );
        } elseif ( $access === false ) {
            MyNJILGA_Admin_UI::callout( sprintf( 'Re-checked: the %s key still can\'t create Checkout Sessions — give it "Checkout Sessions: Write" in the Stripe Dashboard (Developers → API keys), then re-check.', esc_html( ucfirst( $mode ) ) ), 'warning' );
        } else {
            MyNJILGA_Admin_UI::callout( 'Stripe didn\'t answer just now — try the Re-check again in a few minutes.', 'warning' );
        }
    }

    private static function recheck_checkout_link( string $mode ): string {
        $url = wp_nonce_url(
            add_query_arg( 'recheck_checkout', $mode, MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) ),
            self::ACTION_RECHECK_CHECKOUT . '_' . $mode
        );
        return sprintf( ' <a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s">%s Re-check</a>', esc_url( $url ), MyNJILGA_Admin_UI::icon( 'refresh' ) );
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
                ? MyNJILGA_Admin_UI::pill( 'Available', 'success' ) . ' <span class="njilga-dim">bundled with FluentCRM</span>'
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
        $add( (string) $s['general']['join_prelaw_tag'], 'Online join: undergraduate (pre-law)' );
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

    /**
     * Stripe migration phase 4 (reconciler) — connection health for the
     * active mode, plus a collapsed diagnostic log of recent Stripe API
     * requests. What the reconciler and the Invoicing page's "Sync with
     * Stripe" button rely on being healthy; what Stripe has sent back the
     * other way is the next section (render_stripe_events()), and the
     * invoices that couldn't be resolved automatically the one after that
     * (render_stripe_needs_attention()).
     *
     * Two lists, both amber: health() is the hard one — anything in it
     * also blocks invoice creation through the gateway's
     * readiness_errors() — and health_warnings() the soft one.
     */
    private static function render_stripe_reconciliation_section(): void {
        MyNJILGA_Admin_UI::section(
            'Stripe reconciliation',
            'Connection health for the active Stripe mode, and a diagnostic log of recent Stripe API activity.'
        );

        $healthErrors   = MyNJILGA_Stripe_Connection::health();
        $healthWarnings = MyNJILGA_Stripe_Connection::health_warnings();

        if ( empty( $healthErrors ) && empty( $healthWarnings ) ) {
            MyNJILGA_Admin_UI::callout( 'Stripe connection is healthy.', 'success' );
        }
        foreach ( $healthErrors as $err ) {
            MyNJILGA_Admin_UI::callout( esc_html( $err ), 'warning' );
        }
        // The soft findings — a webhook endpoint that has gone quiet, ACH
        // not enabled on the account. Amber like the errors above (both
        // want a human), but unlike health() these never block invoice
        // creation, and each one says so in its own text.
        foreach ( $healthWarnings as $warning ) {
            MyNJILGA_Admin_UI::callout( esc_html( $warning ), 'warning' );
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
     * The njilga_stripe_events audit trail (spec §5.4) — what Stripe has
     * actually told this site, readable without leaving WordPress. The
     * request log above is the outbound half (calls we made); this is the
     * inbound half (events Stripe delivered), so it is a plain visible
     * table rather than a collapsed diagnostic: it is the section staff
     * come here to read.
     *
     * Scoped to the active mode, like every other Stripe read surface in
     * this plugin, and capped rather than paginated — the table only ever
     * holds the last PRUNE_AFTER_DAYS days anyway.
     */
    private static function render_stripe_events(): void {
        $livemode = ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );
        $events   = MyNJILGA_Stripe_Events_Table::recent( MyNJILGA_Stripe_Events_Table::RECENT_LIMIT, $livemode );

        MyNJILGA_Admin_UI::section(
            'Stripe webhook events',
            sprintf(
                'Every webhook event Stripe has delivered to this site in the active mode, newest first — what arrived, whether it was processed, and which invoice it resolved to. Showing the most recent %d; rows older than %d days are pruned by the daily reconcile job.',
                MyNJILGA_Stripe_Events_Table::RECENT_LIMIT,
                MyNJILGA_Stripe_Events_Table::PRUNE_AFTER_DAYS
            )
        );

        if ( empty( $events ) ) {
            self::render_stripe_events_empty();
            return;
        }

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table njilga-table-compact"><thead><tr>
                <th>Received</th><th>Event</th><th>Outcome</th><th>Invoice</th><th>Message</th>
              </tr></thead><tbody>';

        foreach ( $events as $event ) {
            $message = (string) ( $event->message ?? '' );
            printf(
                '<tr><td class="njilga-nowrap">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( (string) $event->received_at ),
                sprintf(
                    '<code>%s</code><span class="njilga-subline">%s</span>',
                    esc_html( (string) $event->type ),
                    esc_html( (string) $event->event_id )
                ),
                self::stripe_event_outcome( $event ),
                self::stripe_event_invoice( $event ),
                $message !== '' ? esc_html( $message ) : MyNJILGA_Admin_UI::blank()
            );
        }

        echo '</tbody></table></div></div>';
    }

    private static function render_stripe_events_empty(): void {
        echo '<div class="njilga-card njilga-empty">';
        echo '<div class="njilga-empty-icon">' . MyNJILGA_Admin_UI::icon( 'inbox' ) . '</div>';
        echo '<h2 class="njilga-empty-title">No webhook events yet</h2>';
        printf(
            '<p class="njilga-empty-text">Stripe has not delivered a webhook event to this site in the active mode. They start arriving as soon as an invoice is finalized, sent or paid &mdash; if that has already happened, check the endpoint on the <a href="%s">Payments settings</a> tab and under Developers &rarr; Webhooks in the Stripe Dashboard.</p>',
            esc_url( add_query_arg( 'tab', 'payments', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) )
        );
        echo '</div>';
    }

    /**
     * What happened to one event: `received` means the row was accepted
     * but processing hasn't finished (the async Action Scheduler job
     * hasn't run yet, or died mid-flight) — `processed`, `ignored` and
     * `failed` are the outcomes MyNJILGA_Stripe_Webhook writes once it
     * has, with the time it finished underneath.
     *
     * @param object $event One njilga_stripe_events row.
     */
    private static function stripe_event_outcome( $event ): string {
        $status   = (string) $event->status;
        $variants = [
            MyNJILGA_Stripe_Events_Table::STATUS_PROCESSED => 'success',
            MyNJILGA_Stripe_Events_Table::STATUS_RECEIVED  => 'info',
            MyNJILGA_Stripe_Events_Table::STATUS_IGNORED   => 'muted',
            MyNJILGA_Stripe_Events_Table::STATUS_FAILED    => 'destructive',
        ];

        $out         = MyNJILGA_Admin_UI::pill( ucfirst( $status ), $variants[ $status ] ?? 'muted' );
        $processedAt = (string) ( $event->processed_at ?? '' );
        if ( $processedAt !== '' ) {
            $out .= sprintf( '<span class="njilga-subline">%s</span>', esc_html( $processedAt ) );
        }
        return $out;
    }

    /**
     * The invoice row an event resolved to, if any. njilga_stripe_events
     * stores only the row id, so each one is looked up in the invoice
     * table — memoized, because several events routinely name the same
     * invoice and the list is capped at RECENT_LIMIT rows either way.
     * An event that resolved to nothing (an unhandled type, a Stripe
     * object belonging to no invoice of ours, a row since pruned) falls
     * back to the raw Stripe object id, which staff can still paste
     * straight into the Stripe Dashboard.
     *
     * @param object $event One njilga_stripe_events row.
     */
    private static function stripe_event_invoice( $event ): string {
        static $rows = [];

        $rowId = (int) ( $event->invoice_row_id ?? 0 );
        if ( $rowId > 0 ) {
            if ( ! array_key_exists( $rowId, $rows ) ) {
                $rows[ $rowId ] = MyNJILGA_Dues_Invoice_Table::get( $rowId );
            }
            if ( $rows[ $rowId ] ) {
                return sprintf(
                    '<strong>%s</strong><span class="njilga-subline">Dues year %d</span>',
                    esc_html( MyNJILGA_Dues_Snapshot::company_name( $rows[ $rowId ] ) ),
                    (int) $rows[ $rowId ]->dues_year
                );
            }
        }

        $objectId = (string) ( $event->object_id ?? '' );
        return $objectId !== '' ? sprintf( '<code>%s</code>', esc_html( $objectId ) ) : MyNJILGA_Admin_UI::blank();
    }

    /**
     * The other direction of the same drift check: invoices that exist in
     * STRIPE with no row here at all, found by
     * MyNJILGA_Stripe_Reconciler::scan_for_orphans() (the daily job, and
     * the Invoicing page's full "Sync with Stripe"). Needs attention above
     * can only ever list rows we have; these have none, which is why they
     * get their own section and their own storage.
     *
     * This is the dangerous direction: a firm can pay one of these and
     * nothing here would ever notice.
     */
    private static function render_stripe_orphans(): void {
        $report = MyNJILGA_Stripe_Reconciler::orphan_report();
        $years  = $report['years'];

        MyNJILGA_Admin_UI::section(
            'In Stripe, not here',
            'Invoices Stripe holds for this plugin in the active mode with no matching record on this site — usually an invoice created at Stripe whose local write then failed, or one made by hand in the Stripe Dashboard. Checked by the daily reconcile job and by "Sync with Stripe" on the Invoicing page.'
        );

        if ( $report['checked_at'] === '' ) {
            MyNJILGA_Admin_UI::callout( 'Not checked yet — run "Sync with Stripe" on the Invoicing page, or wait for the daily reconcile job.', 'info' );
            return;
        }

        if ( empty( $years ) ) {
            MyNJILGA_Admin_UI::callout(
                sprintf( 'Every invoice Stripe holds for this plugin has a record here. Last checked %s.', esc_html( $report['checked_at'] ) ),
                'success'
            );
            return;
        }

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>
                <th class="njilga-col-num">Dues year</th><th>Invoice</th><th>Customer</th><th>Status</th><th class="njilga-col-num">Total</th><th class="njilga-col-num">Paid</th><th></th>
              </tr></thead><tbody>';

        ksort( $years );
        foreach ( $years as $year => $orphans ) {
            foreach ( $orphans as $o ) {
                $number = (string) ( $o['number'] ?? '' );
                $link   = (string) ( $o['hosted_url'] ?? '' );
                printf(
                    '<tr><td class="njilga-col-num">%d</td><td>%s<span class="njilga-subline">%s</span></td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td><td class="njilga-col-num">%s</td><td>%s</td></tr>',
                    (int) $year,
                    esc_html( $number !== '' ? $number : '—' ),
                    esc_html( (string) ( $o['id'] ?? '' ) ),
                    esc_html( (string) ( $o['customer'] ?? '' ) ?: '—' ),
                    MyNJILGA_Admin_UI::pill( ucfirst( (string) ( $o['status'] ?? '' ) ), ( (string) ( $o['status'] ?? '' ) === 'paid' ) ? 'destructive' : 'warning' ),
                    esc_html( MyNJILGA_Invoicing::money( (int) ( $o['total_cents'] ?? 0 ) ) ),
                    esc_html( MyNJILGA_Invoicing::money( (int) ( $o['paid_cents'] ?? 0 ) ) ),
                    $link !== '' ? sprintf( '<a href="%s" target="_blank" rel="noopener">Open in Stripe</a>', esc_url( $link ) ) : MyNJILGA_Admin_UI::blank()
                );
            }
        }
        echo '</tbody></table></div></div>';

        // A PAID orphan is the worst case in this table: money collected
        // that no membership was ever granted for, so it is called out
        // rather than left for someone to spot in the Status column.
        $paid = 0;
        foreach ( $years as $orphans ) {
            foreach ( $orphans as $o ) {
                if ( (string) ( $o['status'] ?? '' ) === 'paid' ) {
                    $paid++;
                }
            }
        }
        if ( $paid > 0 ) {
            MyNJILGA_Admin_UI::callout(
                sprintf(
                    '<strong>%d of these %s already been paid.</strong> Money was collected for %s this site has no record of, so no dues tags or roles were granted for it. Reconcile by hand before the year closes.',
                    $paid,
                    $paid === 1 ? 'has' : 'have',
                    $paid === 1 ? 'an invoice' : 'invoices'
                ),
                'error'
            );
        }
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

    /**
     * What online joining depends on, per Stripe mode: the key may create
     * Checkout Sessions, and the webhook endpoint hears the Checkout
     * events (added automatically on admin_init — see
     * MyNJILGA_Stripe_Connection::maybe_sync_webhook_events()) in the API
     * version the handlers read.
     */
    private static function render_online_joining(): void {
        MyNJILGA_Admin_UI::section(
            'Online joining',
            sprintf(
                'The <code>[njilga_join]</code> shortcode takes payment through Stripe Checkout, in the mode active under Settings → Payments — the same one invoices use. While that\'s Live, staff rehearse in Test with <code>?njilga_test=1</code>. <a href="%s">Shortcodes</a> has the line to paste for each category and the pages that carry one; <a href="%s">Online joins</a> lists every attempt.',
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SHORTCODES ) ),
                esc_url( add_query_arg( 'tab', 'joins', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) ) )
            )
        );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Mode</th><th>Connected</th><th>Key can create checkouts</th><th>Webhook hears Checkout events</th><th>Endpoint API version</th></tr></thead><tbody>';
        foreach ( [ MyNJILGA_Stripe_Connection::MODE_LIVE, MyNJILGA_Stripe_Connection::MODE_TEST ] as $mode ) {
            $connected = MyNJILGA_Stripe_Connection::is_connected( $mode );
            $access    = $connected ? MyNJILGA_Stripe_Connection::checkout_access( $mode ) : null;
            $events    = MyNJILGA_Stripe_Connection::webhook_events_state( $mode );
            $version   = $events['api_version'];
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( ucfirst( $mode ) ),
                MyNJILGA_Admin_UI::validation( $connected ? 'Yes' : 'No', $connected ),
                ! $connected ? MyNJILGA_Admin_UI::blank() : self::checkout_access_cell( $mode, $access ),
                ! $connected ? MyNJILGA_Admin_UI::blank() : self::webhook_events_cell( $events ),
                $version === '' ? MyNJILGA_Admin_UI::blank() : ( $version === MyNJILGA_Stripe_Client::API_VERSION ? MyNJILGA_Admin_UI::validation( $version, true ) : MyNJILGA_Admin_UI::validation( $version . ' — the plugin reads ' . MyNJILGA_Stripe_Client::API_VERSION . '; recreate the endpoint (delete it in Stripe, then reconnect here)', false ) )
            );
        }
        echo '</tbody></table></div></div>';

        MyNJILGA_Admin_UI::callout(
            sprintf(
                '<strong>Before going live:</strong> exclude the Membership pages (and URLs carrying <code>njilga_join</code> or <code>njilga_invite</code>) from any page or CDN cache — the form carries a per-visitor security token. Student IDs are stored in <code>%s</code>, protected by <code>.htaccess</code> on Apache; on nginx, deny that path in the server config (or define <code>NJILGA_PRIVATE_DIR</code> outside the web root in wp-config.php).',
                esc_html( str_replace( ABSPATH, '', MyNJILGA_Join_Documents::dir() ) )
            ),
            'info'
        );
    }

    /**
     * "Key can create checkouts": the stored answer (see
     * MyNJILGA_Stripe_Connection::checkout_access()), when Stripe last gave
     * it, and a Re-check whenever it isn't a plain yes.
     */
    private static function checkout_access_cell( string $mode, ?bool $access ): string {
        $at   = MyNJILGA_Stripe_Connection::checkout_access_checked_at( $mode );
        $when = $at !== '' ? '<span class="njilga-subline">Checked ' . esc_html( $at ) . '</span>' : '';
        if ( $access === true ) {
            return MyNJILGA_Admin_UI::validation( 'Yes', true ) . $when;
        }
        $label = $access === false
            ? MyNJILGA_Admin_UI::validation( 'No — add "Checkout Sessions: Write" to the key', false )
            : MyNJILGA_Admin_UI::status( 'Couldn\'t check just now', 'muted' );
        return $label . self::recheck_checkout_link( $mode ) . $when;
    }

    /**
     * "Webhook hears Checkout events". An endpoint added by hand has no id
     * on file for this plugin to update, so it can only say what the
     * endpoint lacks — or, when the key may not read endpoints, every
     * event it must be subscribed to.
     *
     * @param array<string,mixed> $events MyNJILGA_Stripe_Connection::webhook_events_state()
     */
    private static function webhook_events_cell( array $events ): string {
        if ( $events['synced'] ) {
            return MyNJILGA_Admin_UI::validation( $events['endpoint'] === 'manual' ? 'Yes (endpoint added by hand)' : 'Yes', true );
        }
        if ( $events['endpoint'] === 'none' ) {
            return MyNJILGA_Admin_UI::validation( 'No webhook endpoint on file — see Settings → Payments', false );
        }
        if ( $events['missing'] ) {
            return MyNJILGA_Admin_UI::validation( 'Missing: ' . implode( ', ', $events['missing'] ) . ' — add them in the Stripe Dashboard (Developers → Webhooks)', false );
        }
        if ( $events['endpoint'] === 'manual' && $events['recorded'] && ! $events['checked'] ) {
            return MyNJILGA_Admin_UI::validation( 'Endpoint was added by hand, and its events couldn\'t be read (the key may not read webhook endpoints) — in the Stripe Dashboard (Developers → Webhooks) make sure it is subscribed to: ' . implode( ', ', MyNJILGA_Stripe_Connection::WEBHOOK_EVENTS ), false );
        }
        return MyNJILGA_Admin_UI::validation( 'Not checked yet (checked on the next admin page load)', false );
    }

    /**
     * A pointer, not a copy: the Shortcodes page builds its lines from the
     * live categories and shows where each is used. The table that used
     * to sit here named three category keys by hand, and went stale as
     * soon as one was renamed in Settings.
     */
    private static function render_shortcodes(): void {
        MyNJILGA_Admin_UI::section( 'Shortcodes' );
        printf(
            '<div class="njilga-banner"><div><div class="njilga-banner-title">Shortcodes for the public pages</div><div class="njilga-banner-desc">Ready-to-paste lines for <code>[njilga_join]</code> (one per category an applicant may pick), <code>[njilga_membership_application]</code>, <code>[njilga_firm_dues_status]</code> and <code>[njilga_my_membership]</code>: what each does, which to use, and the pages that carry them now.</div></div><a class="njilga-btn njilga-btn-outline" href="%s">Open Shortcodes</a></div>',
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SHORTCODES ) )
        );
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
