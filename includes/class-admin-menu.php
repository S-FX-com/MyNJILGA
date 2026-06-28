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
    const SLUG_SETUP      = 'my-njilga-setup';

    /**
     * Report detail pages that are reachable by URL (clicked into from the
     * Reports landing page) but deliberately kept out of the admin menu.
     *
     * @var array<string,array{0:string,1:string}>  slug => [ title, page class ]
     */
    const HIDDEN_PAGES = [
        self::SLUG_MEMBERS   => [ 'Active Members',     'MyNJILGA_Page_Members'   ],
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

        // Visible menu: Dashboard, Reports, Setup. The individual reports live
        // behind the Reports landing page rather than cluttering the menu.
        add_submenu_page( self::SLUG_ROOT, 'Dashboard', 'Dashboard', 'manage_options', self::SLUG_ROOT,    [ 'MyNJILGA_Page_Dashboard', 'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Reports',   'Reports',   'manage_options', self::SLUG_REPORTS, [ 'MyNJILGA_Page_Reports',   'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Setup',     'Setup',     'manage_options', self::SLUG_SETUP,   [ 'MyNJILGA_Page_Setup',     'render' ] );

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
        printf(
            '<p style="margin:4px 0 12px"><a href="%s" style="text-decoration:none">&larr; All Reports</a></p>',
            esc_url( self::url( self::SLUG_REPORTS ) )
        );
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

        self::render_stat_tiles( [
            [ 'Paid Members',             $s['paid_members'],       '#1d6f42' ],
            [ 'Unpaid Members',           $s['unpaid_members'],     '#d63638' ],
            [ 'Firms w/ Paid Members',    $s['firms_with_paid'],    '#1d6f42' ],
            [ 'Firms w/ No Paid Members', $s['firms_without_paid'], '#d63638' ],
            [ 'Paid Trustees',            $s['paid_trustees'],      '#1d6f42' ],
            [ 'Unpaid Trustees',          $s['unpaid_trustees'],    '#d63638' ],
            [ 'Exempt',                   $s['exempt'],             '#2271b1' ],
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

        echo '<h2 style="margin:8px 0 4px">Overview</h2>';
        self::render_stat_tiles( [
            [ 'Paid Members',   $s['paid_members'],   '#1d6f42' ],
            [ 'Unpaid Members', $s['unpaid_members'], '#d63638' ],
            [ 'Paid Trustees',  $s['paid_trustees'],  '#1d6f42' ],
            [ 'Exempt',         $s['exempt'],         '#2271b1' ],
        ] );
    }

    /**
     * Renders a responsive grid of KPI tiles. Each tile is [ label, count,
     * accent-colour ].
     *
     * @param array<int,array{0:string,1:int,2:string}> $tiles
     */
    private static function render_stat_tiles( array $tiles ): void {
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:12px 0 24px">';
        foreach ( $tiles as $tile ) {
            printf(
                '<div style="padding:14px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid %s;border-radius:4px">
                    <div style="font-size:28px;font-weight:600;line-height:1.1">%d</div>
                    <div style="color:#646970;font-size:13px">%s</div>
                 </div>',
                esc_attr( $tile[2] ),
                (int) $tile[1],
                esc_html( $tile[0] )
            );
        }
        echo '</div>';
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
        echo '<div class="notice notice-error"><p><strong>FluentCRM is not active.</strong> Install and activate FluentCRM, then reload this page.</p></div>';
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
        printf(
            '<form method="post" action="%s" style="margin:0 0 12px">
                <input type="hidden" name="action" value="my_njilga_export_csv">
                <input type="hidden" name="type" value="%s">
                %s
                <button type="submit" class="button">%s</button>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( $type ),
            wp_nonce_field( 'my_njilga_export_csv', '_wpnonce', true, false ),
            esc_html( $label )
        );
    }

    /**
     * Emit the "Export to Excel" form for the Membership by Firm report.
     * Posts to a dedicated handler that streams a formatted .xls (the CSV
     * exporter can't carry the bold firm headings this report needs).
     */
    public static function render_firms_export_button( string $scope = 'all', string $label = 'Export to Excel' ): void {
        printf(
            '<form method="post" action="%s" style="margin:0 0 12px">
                <input type="hidden" name="action" value="my_njilga_export_firms">
                <input type="hidden" name="scope" value="%s">
                %s
                <button type="submit" class="button button-primary">%s</button>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( $scope === 'active' ? 'active' : 'all' ),
            wp_nonce_field( 'my_njilga_export_firms', '_wpnonce', true, false ),
            esc_html( $label )
        );
    }
}
