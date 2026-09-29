<?php
/**
 * Dashboard — the association at a glance: membership, invoices and
 * applications, each a short row of cards that click through to the page
 * where the work happens.
 *
 * It computes nothing itself. Every figure comes from one provider per
 * subject so this page and the pages behind it cannot disagree:
 *
 *   Membership    MyNJILGA_Membership_Stats  (also feeds the Reports KPI tiles
 *                 and lists — "active" is defined once, in
 *                 MyNJILGA_My_Membership::standing())
 *   Invoices      MyNJILGA_Invoice_Stats     (follows Payments' ledger rules, so
 *                 Outstanding, Collected and Past due agree with Payments.
 *                 Invoicing's own summary line is an older per-status total
 *                 that leaves out ACH-in-flight and uncollectible rows and
 *                 partial payments, so its Batch and Collected differ from
 *                 these whenever such rows exist)
 *   Applications  MyNJILGA_Application_Stats (applications + online joins)
 *
 * Each section is loaded on its own and fails on its own: a problem reading
 * one subject shows a note in that section, never a blank dashboard. The
 * detailed lists, CSV and Excel exports stay under Reports.
 */
class MyNJILGA_Page_Dashboard {

    const ACTION_REFRESH = 'my_njilga_refresh_dashboard';

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::open(
            'My NJILGA',
            'The association at a glance — membership, invoices and applications. Detailed lists and exports are under Reports.',
            MyNJILGA_Admin_UI::action_form( self::ACTION_REFRESH, 'Refresh figures', [], 'outline', 'refresh' )
        );

        $crm = ! MyNJILGA_Admin_Menu::require_fluentcrm();

        if ( $crm ) {
            self::render_missing_tag_banner();
        }

        $invoices = self::load( 'invoice', static function () {
            $requested = isset( $_GET['dues_year'] ) ? absint( wp_unslash( $_GET['dues_year'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return MyNJILGA_Invoice_Stats::snapshot( $requested >= 2000 && $requested <= 2100 ? $requested : null );
        } );
        $live = $invoices ? (bool) $invoices['live'] : ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );
        $apps = self::load( 'application', static function () use ( $live ) {
            return MyNJILGA_Application_Stats::snapshot( $live );
        } );

        self::render_alerts( $invoices, $apps );

        if ( $crm ) {
            $members = self::load( 'membership', static function () {
                return MyNJILGA_Membership_Stats::snapshot();
            } );
            self::render_membership( $members );
        }

        self::render_invoices( $invoices );
        self::render_applications( $apps );
        self::render_reports_banner();

        MyNJILGA_Admin_UI::close();
    }

    /**
     * admin-post: drop the cached membership figures and come back. The
     * figures otherwise refresh every few minutes and after each payment.
     */
    public static function handle_refresh(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_REFRESH );
        if ( class_exists( 'MyNJILGA_Membership_Stats' ) ) {
            MyNJILGA_Membership_Stats::flush();
        }
        wp_safe_redirect( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_ROOT ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Alerts
    // -------------------------------------------------------------------------

    /**
     * Things that make a figure below untrustworthy, or a payment not do
     * what it should — said once, above the cards.
     *
     * @param array<string,mixed>|null $invoices
     * @param array<string,mixed>|null $apps
     */
    private static function render_alerts( ?array $invoices, ?array $apps ): void {
        if ( $invoices && empty( $invoices['live'] ) ) {
            MyNJILGA_Admin_UI::callout(
                sprintf(
                    '<strong>Stripe is in Test mode.</strong> The invoice and online-join figures below are test data, not real money. Switch under <a href="%s">Settings → Payments</a>.',
                    esc_url( add_query_arg( 'tab', 'payments', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) )
                ),
                'warning'
            );
        }

        // A paid member the WordPress role could not be given to. Set by
        // MyNJILGA_Role_Sync when a payment hit a role that isn't defined on
        // the site or that carries administrator-level capabilities.
        $problem = class_exists( 'MyNJILGA_Role_Sync' ) ? MyNJILGA_Role_Sync::problem() : null;
        // It is a stored sighting, not a live check: once the admin has fixed
        // the mapping (or created the role) the callout must go, not wait for
        // the next paid batch to clear it.
        if ( $problem ) {
            $now = MyNJILGA_Role_Sync::current_problem_status( $problem );
            if ( $now === '' ) {
                $problem = null;
            } else {
                $problem['status'] = $now; // Created-but-privileged, or deleted-but-still-mapped, reads as what it is now.
            }
        }
        if ( $problem ) {
            $role  = esc_html( (string) ( $problem['role'] ?? '' ) );
            $count = (int) ( $problem['count'] ?? 0 );
            $since = ! empty( $problem['since'] ) ? esc_html( (string) $problem['since'] ) : '';
            $settings = esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) );
            if ( ( $problem['status'] ?? '' ) === 'role_privileged' ) {
                MyNJILGA_Admin_UI::callout(
                    sprintf(
                        '<strong>Payments won\'t grant the WordPress role “%s”.</strong> It carries administrator-level capabilities, so a membership payment must never give it (%d member%s%s). Map the membership category to an ordinary member role in <a href="%s">Settings</a>.',
                        $role,
                        $count,
                        $count === 1 ? '' : 's',
                        $since !== '' ? ' since ' . $since : '',
                        $settings
                    ),
                    'error'
                );
            } else {
                MyNJILGA_Admin_UI::callout(
                    sprintf(
                        '<strong>A paid member couldn\'t be given the WordPress role “%s”.</strong> That role isn\'t defined on this site (%d member%s%s). Create the role, or map the membership category to an existing role in <a href="%s">Settings</a>.',
                        $role,
                        $count,
                        $count === 1 ? '' : 's',
                        $since !== '' ? ' since ' . $since : '',
                        $settings
                    ),
                    'warning'
                );
            }
        }

        if ( $apps && ! empty( $apps['join_problem'] ) ) {
            MyNJILGA_Admin_UI::callout(
                sprintf(
                    '<strong>Online joining is unavailable:</strong> %s',
                    esc_html( (string) $apps['join_problem'] )
                ),
                'warning'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Membership
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $m MyNJILGA_Membership_Stats::snapshot()
     */
    private static function render_membership( ?array $m ): void {
        $desc = 'Active means paid through this year or later, whatever the member\'s email-subscription status. Members still on the older Dues Paid tag with no payment date count as active until that tag is removed.';
        if ( $m && ! empty( $m['generated'] ) ) {
            $ts = strtotime( (string) $m['generated'] . ' UTC' );
            if ( $ts ) {
                $desc .= ' Updated ' . human_time_diff( $ts ) . ' ago.';
            }
        }
        MyNJILGA_Admin_UI::section( 'Membership', $desc );

        if ( ! $m || empty( $m['available'] ) ) {
            MyNJILGA_Admin_UI::callout(
                $m && ! empty( $m['warnings'] )
                    ? esc_html( implode( ' ', array_map( 'strval', (array) $m['warnings'] ) ) )
                    : 'Membership figures are temporarily unavailable.',
                'warning'
            );
            return;
        }

        $mem      = (array) $m['members'];
        $firms    = (array) $m['firms'];
        $trustees = (array) $m['trustees'];
        $noFirm   = (array) $m['no_firm'];
        $year     = (int) $m['year'];
        $active   = (int) $mem['active'];
        $ahead    = (int) $mem['paid_ahead'];

        if ( ! empty( $m['warnings'] ) ) {
            MyNJILGA_Admin_UI::callout(
                '<strong>Check before relying on these figures:</strong><br>' . implode( '<br>', array_map( 'esc_html', array_map( 'strval', (array) $m['warnings'] ) ) ),
                'info'
            );
        }

        MyNJILGA_Admin_UI::stat_cards( [
            [
                'label'   => 'Active members',
                'value'   => self::n( $active ),
                'variant' => 'success',
                'icon'    => 'check-circle',
                'url'     => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_MEMBERS ),
                'sub'     => self::terms( [
                    (int) $mem['dated'] > 0 ? self::n( (int) $mem['dated'] ) . ' with a payment date' : '',
                    (int) $mem['assumed'] > 0 ? self::n( (int) $mem['assumed'] ) . ' on the older tag, no date' : '',
                ] ),
            ],
            [
                'label'   => sprintf( 'Paid ahead for %d', (int) $m['next_year'] ),
                'value'   => self::n( $ahead ),
                'variant' => 'info',
                'icon'    => 'calendar',
                // Only members with a recorded payment date: a member on the
                // older tag with no date has no real expiration to report.
                'sub'     => (int) $mem['renewing'] > 0
                    ? sprintf( '%s with a payment date %s 12/31/%d', self::n( (int) $mem['renewing'] ), (int) $mem['renewing'] === 1 ? 'expires' : 'expire', $year )
                    : '',
            ],
            [
                'label'   => 'Expired members',
                'value'   => self::n( (int) $mem['expired'] ),
                'variant' => (int) $mem['expired'] > 0 ? 'destructive' : 'default',
                'icon'    => 'alert',
                'url'     => add_query_arg( 'scope', 'all', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_FIRMS ) ),
                'sub'     => self::terms( [
                    (int) $mem['exempt'] > 0 ? self::n( (int) $mem['exempt'] ) . ' exempt with no payment' : '',
                    (int) $mem['inactive'] > 0 ? self::n( (int) $mem['inactive'] ) . ' inactive' : '',
                ], 'Not counted: ' ),
            ],
            [
                'label'   => 'Firms with an active member',
                'value'   => self::n( (int) $firms['with_active'] ),
                'variant' => 'default',
                'icon'    => 'building',
                'url'     => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_COMPANIES ),
                'sub'     => self::terms( [
                    (int) $firms['without_active'] > 0 ? self::n( (int) $firms['without_active'] ) . ' with none' : '',
                    (int) $firms['no_owner'] > 0 ? self::n( (int) $firms['no_owner'] ) . ' with no Owner' : '',
                    (int) $firms['empty'] > 0 ? sprintf( '%s %s with no contacts', self::n( (int) $firms['empty'] ), (int) $firms['empty'] === 1 ? 'company' : 'companies' ) : '',
                ] ),
            ],
            [
                'label'   => 'Trustees',
                'value'   => self::n( (int) $trustees['total'] ),
                'variant' => 'info',
                'icon'    => 'award',
                'url'     => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_TRUSTEES ),
                'sub'     => self::terms( [
                    (int) $trustees['active'] > 0 ? self::n( (int) $trustees['active'] ) . ' paid' : '',
                    (int) $trustees['expired'] > 0 ? self::n( (int) $trustees['expired'] ) . ' unpaid' : '',
                    (int) $trustees['exempt'] > 0 ? self::n( (int) $trustees['exempt'] ) . ' Past President or Senior Trustee' : '',
                ] ),
            ],
            [
                'label'   => 'Active members without a firm',
                'value'   => self::n( (int) $noFirm['active'] ),
                'variant' => 'default',
                'icon'    => 'user',
                'sub'     => self::terms( self::category_terms( (array) ( $noFirm['by_category'] ?? [] ), (array) $m['categories'] ) ),
            ],
        ], 3 );

        self::render_categories( (array) $m['categories'] );
    }

    /**
     * Active and expired members per membership category.
     *
     * @param array<string,array{label:string,active:int,expired:int,defaulted:int}> $categories
     */
    private static function render_categories( array $categories ): void {
        $rows = '';
        foreach ( $categories as $c ) {
            if ( (int) $c['active'] + (int) $c['expired'] === 0 ) {
                continue;
            }
            $rows .= sprintf(
                '<tr><td>%s%s</td><td class="njilga-col-num">%s</td><td class="njilga-col-num">%s</td></tr>',
                esc_html( (string) $c['label'] ),
                (int) $c['defaulted'] > 0
                    ? sprintf(
                        '<span class="njilga-subline">%s %s no category tag and %s counted here by default</span>',
                        esc_html( self::n( (int) $c['defaulted'] ) ),
                        (int) $c['defaulted'] === 1 ? 'contact carries' : 'contacts carry',
                        (int) $c['defaulted'] === 1 ? 'is' : 'are'
                    )
                    : '',
                MyNJILGA_Admin_UI::status( self::n( (int) $c['active'] ), (int) $c['active'] > 0 ? 'ok' : 'muted' ),
                MyNJILGA_Admin_UI::status( self::n( (int) $c['expired'] ), (int) $c['expired'] > 0 ? 'bad' : 'muted' )
            );
        }
        if ( $rows === '' ) {
            return;
        }
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table">';
        echo '<thead><tr><th>Membership category</th><th class="njilga-col-num">Active</th><th class="njilga-col-num">Expired</th></tr></thead><tbody>';
        echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
        echo '</tbody></table></div></div>';
    }

    // -------------------------------------------------------------------------
    // Invoices
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $i MyNJILGA_Invoice_Stats::snapshot()
     */
    private static function render_invoices( ?array $i ): void {
        if ( ! $i ) {
            MyNJILGA_Admin_UI::section( 'Invoices' );
            MyNJILGA_Admin_UI::callout( 'Invoice figures are temporarily unavailable.', 'warning' );
            return;
        }

        $year     = (int) $i['year'];
        $mode     = $i['live'] ? 'Live' : 'Test';
        $invoicing = MyNJILGA_Page_Invoicing::page_url( $year );
        $payments  = MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_PAYMENTS );

        MyNJILGA_Admin_UI::section(
            sprintf( 'Invoices — %d dues', $year ),
            sprintf(
                '%s-mode Stripe invoices. Annual figures cover %d dues invoices only (online joins are shown apart); Outstanding and Past due span every year and match <a href="%s">Payments</a>.',
                esc_html( $mode ),
                $year,
                esc_url( $payments )
            )
        );

        if ( empty( $i['connected'] ) ) {
            MyNJILGA_Admin_UI::callout(
                sprintf(
                    'Stripe isn\'t connected in %s mode yet, so there are no invoice figures to show. Connect it under <a href="%s">Settings → Payments</a>.',
                    esc_html( $mode ),
                    esc_url( add_query_arg( 'tab', 'payments', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) )
                ),
                'info'
            );
            return;
        }

        $annual = (array) $i['annual'];
        $recv   = (array) $i['receivables'];
        $pipe   = (array) $i['pipeline'];
        $flags  = (array) $i['flags'];
        $joins  = (array) $i['joins'];

        $cards = [];

        if ( ! empty( $i['year_has_rows'] ) ) {
            $cards[] = [
                'label'   => sprintf( 'Invoiced for %d', $year ),
                'value'   => MyNJILGA_Invoicing::money( (int) $annual['invoiced_cents'] ),
                'variant' => 'default',
                'icon'    => 'file',
                'url'     => $invoicing,
                'sub'     => self::terms( [
                    sprintf( '%s %s', self::n( (int) $annual['invoices'] ), (int) $annual['invoices'] === 1 ? 'invoice' : 'invoices' ),
                    (int) $annual['batch_cents'] > (int) $annual['invoiced_cents']
                        ? 'incl. drafts ' . MyNJILGA_Invoicing::money( (int) $annual['batch_cents'] )
                        : '',
                ] ),
            ];
            $cards[] = [
                'label'   => sprintf( 'Collected for %d', $year ),
                'value'   => MyNJILGA_Invoicing::money( (int) $annual['collected_cents'] ),
                'variant' => 'success',
                'icon'    => 'check-circle',
                'url'     => $payments,
                'sub'     => self::terms( [
                    (int) $annual['invoiced_cents'] > 0 ? sprintf( '%d%% of invoiced', (int) $annual['paid_pct'] ) : '',
                    (int) $annual['invoices'] > 0 ? sprintf( '%s of %s invoices paid', self::n( (int) $annual['paid_invoices'] ), self::n( (int) $annual['invoices'] ) ) : '',
                    (int) ( $joins['cents'] ?? 0 ) > 0
                        ? sprintf( '+ %s from %s online %s', MyNJILGA_Invoicing::money( (int) $joins['cents'] ), self::n( (int) $joins['count'] ), (int) $joins['count'] === 1 ? 'join' : 'joins' )
                        : '',
                ] ),
            ];
        }

        $cards[] = [
            'label'   => 'Outstanding, all years',
            'value'   => MyNJILGA_Invoicing::money( (int) $recv['outstanding_cents'] ),
            'variant' => 'default',
            'icon'    => 'inbox',
            'url'     => $payments,
            'sub'     => self::terms( [
                (int) $recv['open_count'] > 0 ? sprintf( '%s open %s', self::n( (int) $recv['open_count'] ), (int) $recv['open_count'] === 1 ? 'invoice' : 'invoices' ) : '',
                (int) $recv['in_flight_count'] > 0
                    ? sprintf( '%s clearing by bank transfer', MyNJILGA_Invoicing::money( (int) $recv['in_flight_cents'] ) )
                    : '',
                (int) $recv['downgraded_cents'] > 0
                    ? sprintf( '%s on lapsed invoices', MyNJILGA_Invoicing::money( (int) $recv['downgraded_cents'] ) )
                    : '',
            ] ),
        ];
        $cards[] = [
            'label'   => 'Past due',
            'value'   => MyNJILGA_Invoicing::money( (int) $recv['past_due_cents'] ),
            'variant' => (int) $recv['past_due_cents'] > 0 ? 'destructive' : 'default',
            'icon'    => 'alert',
            'url'     => $payments,
            'sub'     => (int) $recv['past_due_count'] > 0
                ? ( (int) $recv['past_due_count'] === 1
                    ? '1 invoice past its due date'
                    : sprintf( '%s invoices past their due date', self::n( (int) $recv['past_due_count'] ) ) )
                : 'Nothing overdue',
        ];
        $cards[] = [
            'label'   => 'Drafts to review',
            'value'   => self::n( (int) $pipe['ready_count'] ),
            'variant' => (int) $pipe['ready_count'] > 0 ? 'warning' : 'default',
            'icon'    => 'file',
            'url'     => $invoicing,
            'sub'     => self::terms( [
                (int) $pipe['ready_count'] > 0 ? MyNJILGA_Invoicing::money( (int) $pipe['ready_cents'] ) . ' estimated' : '',
                (int) $pipe['creating'] > 0 ? self::n( (int) $pipe['creating'] ) . ' being created' : '',
                (int) $pipe['error'] > 0 ? self::n( (int) $pipe['error'] ) . ' with errors' : '',
                (int) $pipe['blocked_no_owner_count'] > 0
                    ? sprintf( '%s blocked, no Owner (%s)', self::n( (int) $pipe['blocked_no_owner_count'] ), MyNJILGA_Invoicing::money( (int) $pipe['blocked_no_owner_cents'] ) )
                    : '',
            ] ),
        ];
        $cards[] = [
            'label'   => 'Invoices flagged',
            'value'   => self::n( (int) $flags['open'] ),
            'variant' => (int) $flags['open'] > 0 ? 'warning' : 'default',
            'icon'    => 'alert',
            'url'     => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ),
            'sub'     => (int) $flags['review'] > 0
                ? sprintf( 'plus %s closed %s carrying a note', self::n( (int) $flags['review'] ), (int) $flags['review'] === 1 ? 'invoice' : 'invoices' )
                : '',
        ];

        if ( empty( $i['year_has_rows'] ) ) {
            MyNJILGA_Admin_UI::callout(
                sprintf(
                    'No %d dues invoices have been generated yet. Open <a href="%s">Invoicing</a> to generate the preview.',
                    $year,
                    esc_url( $invoicing )
                ),
                'info'
            );
        }

        MyNJILGA_Admin_UI::stat_cards( $cards, count( $cards ) === 4 ? 4 : 3 );
    }

    // -------------------------------------------------------------------------
    // Applications
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $a MyNJILGA_Application_Stats::snapshot()
     */
    private static function render_applications( ?array $a ): void {
        if ( ! $a ) {
            MyNJILGA_Admin_UI::section( 'Applications' );
            MyNJILGA_Admin_UI::callout( 'Application figures are temporarily unavailable.', 'warning' );
            return;
        }

        $attention = (int) $a['total_attention'];
        MyNJILGA_Admin_UI::section(
            'Applications',
            'Membership applications and online joins that are waiting on a person.',
            $attention > 0 ? $attention : null
        );

        $apps    = (array) $a['applications'];
        $joins   = (array) $a['joins'];
        $year    = (int) $a['year'];
        $appsUrl = MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS );
        $joinUrl = add_query_arg( 'tab', 'joins', $appsUrl );

        $pending = (int) $apps['pending'];
        $needs   = (int) $joins['needs_attention'];

        MyNJILGA_Admin_UI::stat_cards( [
            [
                'label'   => 'Applications to review',
                'value'   => self::n( $pending ),
                'variant' => $pending > 0 ? 'warning' : 'default',
                'icon'    => 'inbox',
                'url'     => $appsUrl,
                'sub'     => self::terms( [
                    $pending > 0
                        ? ( $apps['oldest_days'] !== null ? 'oldest waiting ' . self::days( (int) $apps['oldest_days'] ) : '' )
                        : 'Queue is clear',
                    (int) $apps['approved_year'] > 0 ? sprintf( '%s approved in %d', self::n( (int) $apps['approved_year'] ), $year ) : '',
                ] ),
            ],
            [
                'label'   => 'Online joins needing attention',
                'value'   => self::n( $needs ),
                'variant' => $needs > 0 ? 'warning' : 'default',
                'icon'    => 'alert',
                'url'     => $joinUrl,
                'sub'     => $needs > 0 ? MyNJILGA_Application_Stats::attention_sentence( $joins ) : 'Nothing waiting',
            ],
            [
                'label'   => 'Bank payments clearing',
                'value'   => self::n( (int) $joins['clearing'] ),
                'variant' => 'info',
                'icon'    => 'refresh',
                'url'     => $joinUrl,
                'sub'     => self::terms( [
                    (int) $joins['clearing'] > 0 && $joins['oldest_clearing_days'] !== null
                        ? 'oldest ' . self::days( (int) $joins['oldest_clearing_days'] )
                        : '',
                    (int) $joins['failed'] > 0 ? sprintf( '%s failed to date', self::n( (int) $joins['failed'] ) ) : '',
                ] ),
            ],
            [
                'label'   => sprintf( 'Online joins completed in %d', $year ),
                'value'   => self::n( (int) $joins['joined_year'] ),
                'variant' => 'success',
                'icon'    => 'check-circle',
                'url'     => $joinUrl,
                'sub'     => self::terms( [
                    (int) $joins['awaiting_payment'] > 0 ? sprintf( '%s awaiting payment', self::n( (int) $joins['awaiting_payment'] ) ) : '',
                    'each join can cover colleagues',
                ] ),
            ],
        ], 4 );
    }

    // -------------------------------------------------------------------------
    // Reports link, tag banner
    // -------------------------------------------------------------------------

    private static function render_reports_banner(): void {
        printf(
            '<div class="njilga-banner"><div><div class="njilga-banner-title">Reports and exports</div><div class="njilga-banner-desc">Every list behind these figures — active members, trustees, firms and membership by firm — with CSV and Excel downloads and the Executive Summary.</div></div><a class="njilga-btn njilga-btn-outline" href="%s">Open Reports</a></div>',
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_REPORTS ) )
        );
    }

    private static function render_missing_tag_banner(): void {
        $missing = [];
        foreach ( MyNJILGA_Tags::DEFINITIONS as $slug => $def ) {
            if ( ! $def['required'] ) continue;
            if ( MyNJILGA_Tags::id_for( $slug ) === null ) {
                $missing[] = $def['title'];
            }
        }
        if ( ! $missing ) return;

        MyNJILGA_Admin_UI::callout(
            sprintf(
                'Required FluentCRM tags missing: <strong>%s</strong>. <a href="%s">Open Setup</a> to create them.',
                esc_html( implode( ', ', $missing ) ),
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
            ),
            'warning'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Run one subject's provider so a failure there costs that section, not
     * the page. Returns null on failure (and says why in the debug log).
     *
     * @return array<string,mixed>|null
     */
    private static function load( string $subject, callable $fn ): ?array {
        try {
            $data = $fn();
            return is_array( $data ) ? $data : null;
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[My NJILGA] Dashboard %s figures failed: %s', $subject, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return null;
        }
    }

    private static function n( int $n ): string {
        return number_format_i18n( $n );
    }

    private static function days( int $days ): string {
        return $days <= 0 ? 'today' : sprintf( '%d day%s', $days, $days === 1 ? '' : 's' );
    }

    /**
     * One secondary line: the non-empty parts joined with a middle dot.
     *
     * @param array<int,string> $parts
     */
    private static function terms( array $parts, string $prefix = '' ): string {
        $parts = array_values( array_filter( $parts, 'strlen' ) );
        return $parts ? $prefix . implode( ' · ', $parts ) : '';
    }

    /**
     * "12 Law Student · 3 Professional" from a key => count map.
     *
     * @param array<string,int>                $byCategory
     * @param array<string,array{label:string}> $categories
     * @return array<int,string>
     */
    private static function category_terms( array $byCategory, array $categories ): array {
        $out = [];
        foreach ( $categories as $key => $c ) {
            $n = (int) ( $byCategory[ $key ] ?? 0 );
            if ( $n > 0 ) {
                $name  = trim( (string) preg_replace( '/\s+Membership$/i', '', (string) $c['label'] ) );
                $out[] = self::n( $n ) . ' ' . ( $name !== '' ? $name : (string) $c['label'] );
            }
        }
        return $out;
    }
}
