<?php
/**
 * Registers the "My NJILGA" top-level admin menu and its sub-pages.
 *
 * The default top-level slug (`my-njilga`) renders the Dashboard, which
 * matches the standard WordPress pattern of the parent slug duplicating
 * the first sub-page.
 */
class MyNJILGA_Admin_Menu {

    const SLUG_ROOT       = 'my-njilga';
    const SLUG_REPORTS    = 'my-njilga-reports';
    const SLUG_MEMBERS    = 'my-njilga-members';
    const SLUG_TRUSTEES   = 'my-njilga-trustees';
    const SLUG_COMPANIES  = 'my-njilga-companies';
    const SLUG_FIRMS      = 'my-njilga-firms';
    const SLUG_INVOICING    = 'my-njilga-invoicing';
    const SLUG_PAYMENTS     = 'my-njilga-payments';
    const SLUG_APPLICATIONS = 'my-njilga-applications';
    const SLUG_SETTINGS     = 'my-njilga-settings';
    const SLUG_SETUP        = 'my-njilga-setup';

    /**
     * Report detail pages that are reachable by URL (clicked into from the
     * Reports landing page) but deliberately kept out of the admin menu.
     *
     * @var array<string,array{0:string,1:string}>  slug => [ title, page class ]
     */
    const HIDDEN_PAGES = [
        self::SLUG_MEMBERS   => [ 'Active Paid Members', 'MyNJILGA_Page_Members'   ],
        self::SLUG_TRUSTEES  => [ 'Trustees',           'MyNJILGA_Page_Trustees'  ],
        self::SLUG_COMPANIES => [ 'Companies',          'MyNJILGA_Page_Companies' ],
        self::SLUG_FIRMS     => [ 'Membership by Firm', 'MyNJILGA_Page_Firms'     ],
    ];

    public static function register(): void {
        // Position 3 places "My NJILGA" directly beneath Dashboard (position 2,
        // with the first core separator at 4), so it's the first item under it.
        add_menu_page(
            'My NJILGA',
            'My NJILGA',
            'manage_options',
            self::SLUG_ROOT,
            [ 'MyNJILGA_Page_Dashboard', 'render' ],
            'dashicons-groups',
            3
        );

        // Visible menu: Dashboard, Reports, Invoicing, Setup. The individual
        // reports live behind the Reports landing page rather than
        // cluttering the menu; Invoicing is its own active workflow tool,
        // so — unlike the reports — it stays visible in the menu.
        add_submenu_page( self::SLUG_ROOT, 'Dashboard', 'Dashboard', 'manage_options', self::SLUG_ROOT,      [ 'MyNJILGA_Page_Dashboard',  'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Reports',   'Reports',   'manage_options', self::SLUG_REPORTS,   [ 'MyNJILGA_Page_Reports',    'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Invoicing', 'Invoicing', 'manage_options', self::SLUG_INVOICING, [ 'MyNJILGA_Page_Invoicing',  'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Payments',  'Payments',  'manage_options', self::SLUG_PAYMENTS,  [ 'MyNJILGA_Page_Payments',  'render' ] );

        // Applications: the enrollment review queue, with a pending-count
        // bubble like Comments/Plugins use.
        // admin_menu fires before admin_init, so on the first admin request
        // after an auto-update the table may not exist yet — create it here
        // rather than query a missing table.
        $pending = 0;
        if ( class_exists( 'MyNJILGA_Applications_Table' ) ) {
            MyNJILGA_Applications_Table::maybe_upgrade();
            $pending = MyNJILGA_Applications_Table::count_pending();
        }
        $appsLabel = 'Applications' . ( $pending > 0 ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $pending ) : '' );
        add_submenu_page( self::SLUG_ROOT, 'Applications', $appsLabel, 'manage_options', self::SLUG_APPLICATIONS, [ 'MyNJILGA_Page_Applications', 'render' ] );

        add_submenu_page( self::SLUG_ROOT, 'Dues & Billing Settings', 'Settings', 'manage_options', self::SLUG_SETTINGS, [ 'MyNJILGA_Page_Settings', 'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Setup',     'Setup',     'manage_options', self::SLUG_SETUP,     [ 'MyNJILGA_Page_Setup',      'render' ] );

        // Report detail pages: registered with an EMPTY parent slug. WordPress
        // keeps them in $submenu[''] — a bucket it never renders — so they stay
        // out of the menu while remaining fully routable via admin.php?page=…
        // (reached by clicking into them from the Reports page). Using
        // remove_submenu_page() instead breaks parent resolution and triggers a
        // "not allowed to access this page" error, so do NOT do that.
        foreach ( self::HIDDEN_PAGES as $slug => $page ) {
            add_submenu_page( '', $page[0], $page[0], 'manage_options', $slug, [ $page[1], 'render' ] );
        }
    }

    /**
     * Keeps the top-level "My NJILGA" menu highlighted while viewing one of
     * the hidden report pages. Hooked on `parent_file`.
     */
    public static function highlight_parent_menu( string $parent_file ): string {
        global $plugin_page;
        return isset( self::HIDDEN_PAGES[ (string) $plugin_page ] ) ? self::SLUG_ROOT : $parent_file;
    }

    /**
     * Keeps the "Reports" submenu item highlighted while viewing one of the
     * hidden report pages. Hooked on `submenu_file`.
     */
    public static function highlight_submenu( $submenu_file ) {
        global $plugin_page;
        return isset( self::HIDDEN_PAGES[ (string) $plugin_page ] ) ? self::SLUG_REPORTS : $submenu_file;
    }

    /**
     * Renders a "← All Reports" link back to the Reports landing page. Shown
     * at the top of each individual report now that they're not in the menu.
     */
    public static function render_back_to_reports(): void {
        MyNJILGA_Admin_UI::back_link( self::url( self::SLUG_REPORTS ), 'All Reports' );
    }

    /**
     * Renders the cross-report KPI dashboard (paid/unpaid members, firms with
     * and without paid members, paid/unpaid trustees, exempt). Shown at the top
     * of the Reports landing page and each individual report. Exempt contacts
     * (Past Presidents / Senior Trustees) are tallied separately and never
     * counted under Unpaid. No-op without FluentCRM.
     */
    public static function render_stats_panel(): void {
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return;
        }

        $s = MyNJILGA_Members_Data::report_stats();

        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Paid Members',             'value' => $s['paid_members'],       'variant' => 'success',     'icon' => 'check-circle' ],
            [ 'label' => 'Unpaid Members',           'value' => $s['unpaid_members'],     'variant' => 'destructive', 'icon' => 'alert' ],
            [ 'label' => 'Firms w/ Paid Members',    'value' => $s['firms_with_paid'],    'variant' => 'success',     'icon' => 'building' ],
            [ 'label' => 'Firms w/ No Paid Members', 'value' => $s['firms_without_paid'], 'variant' => 'destructive', 'icon' => 'building' ],
            [ 'label' => 'Paid Trustees',            'value' => $s['paid_trustees'],      'variant' => 'success',     'icon' => 'award' ],
            [ 'label' => 'Unpaid Trustees',          'value' => $s['unpaid_trustees'],    'variant' => 'destructive', 'icon' => 'award' ],
            [ 'label' => 'Exempt',                   'value' => $s['exempt'],             'variant' => 'info',        'icon' => 'user' ],
        ] );
    }

    /**
     * Membership by Firm overview: a focused four-tile KPI strip — Paid
     * Members, Unpaid Members, Paid Trustees, and Exempt. Exempt counts
     * Past Presidents and Senior Trustees, who are excluded from Unpaid.
     * Shown at the top of the Membership by Firm report. No-op without
     * FluentCRM.
     */
    public static function render_firm_overview_panel(): void {
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return;
        }

        $s = MyNJILGA_Members_Data::report_stats();

        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Paid Members',   'value' => $s['paid_members'],   'variant' => 'success',     'icon' => 'check-circle' ],
            [ 'label' => 'Unpaid Members', 'value' => $s['unpaid_members'], 'variant' => 'destructive', 'icon' => 'alert' ],
            [ 'label' => 'Paid Trustees',  'value' => $s['paid_trustees'],  'variant' => 'success',     'icon' => 'award' ],
            [ 'label' => 'Exempt',         'value' => $s['exempt'],         'variant' => 'info',        'icon' => 'user' ],
        ] );
    }

    /**
     * Renders a responsive grid of KPI tiles. Each tile is [ label, count,
     * accent-colour ]. Public so other pages (e.g. Invoicing's per-status
     * counts) can reuse the same tile styling instead of reinventing it.
     *
     * @param array<int,array{0:string,1:int,2:string}> $tiles
     */
    public static function render_stat_tiles( array $tiles ): void {
        $cards = [];
        foreach ( $tiles as $tile ) {
            $cards[] = [ 'label' => (string) $tile[0], 'value' => (int) $tile[1], 'variant' => 'default', 'icon' => 'users' ];
        }
        MyNJILGA_Admin_UI::stat_cards( $cards );
    }

    /**
     * Renders a "FluentCRM not active" notice when the dependency is
     * missing. Pages call this at the top of render() and return early
     * if it returns true.
     */
    public static function require_fluentcrm(): bool {
        if ( MyNJILGA_Members_Data::fluentcrm_active() ) {
            return false;
        }
        MyNJILGA_Admin_UI::callout( '<strong>FluentCRM is not active.</strong> Install and activate FluentCRM, then reload this page.', 'error' );
        return true;
    }

    public static function url( string $slug ): string {
        return admin_url( 'admin.php?page=' . $slug );
    }

    /**
     * Emit a "Download CSV" form pointing at the export handler with the
     * given report type ("members", "trustees", or "companies"). Each
     * list page renders one of these above its table.
     */
    public static function render_csv_button( string $type, string $label = 'Download CSV' ): void {
        echo '<div class="njilga-actions">'
            . MyNJILGA_Admin_UI::action_form( 'my_njilga_export_csv', $label, [ 'type' => $type ], 'outline', 'download' )
            . '</div>';
    }

    /**
     * Emit the "Export to Excel" form for the Membership by Firm report.
     * Posts to a dedicated handler that streams a formatted .xls (the CSV
     * exporter can't carry the bold firm headings this report needs).
     */
    public static function render_firms_export_button( string $scope = 'all', string $label = 'Export to Excel' ): void {
        echo '<div class="njilga-actions">'
            . MyNJILGA_Admin_UI::action_form( 'my_njilga_export_firms', $label, [ 'scope' => $scope === 'active' ? 'active' : 'all' ], 'primary', 'download' )
            . '</div>';
    }

    /**
     * Emit the "Download Executive Summary" form — a single formatted
     * .xls combining every report (Overview, Active Paid Members,
     * Trustees, Companies, Membership by Firm).
     */
    public static function render_summary_export_button( string $label = 'Download Executive Summary (Excel)' ): void {
        echo MyNJILGA_Admin_UI::action_form( 'my_njilga_export_summary', $label, [], 'primary', 'download' );
    }
}
