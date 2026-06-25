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

    public static function register(): void {
        add_menu_page(
            'My NJILGA',
            'My NJILGA',
            'manage_options',
            self::SLUG_ROOT,
            [ 'MyNJILGA_Page_Dashboard', 'render' ],
            'dashicons-groups',
            30
        );

        // Visible menu: Dashboard, Reports, Setup. The individual reports live
        // behind the Reports landing page rather than cluttering the menu.
        add_submenu_page( self::SLUG_ROOT, 'Dashboard', 'Dashboard', 'manage_options', self::SLUG_ROOT,    [ 'MyNJILGA_Page_Dashboard', 'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Reports',   'Reports',   'manage_options', self::SLUG_REPORTS, [ 'MyNJILGA_Page_Reports',   'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Setup',     'Setup',     'manage_options', self::SLUG_SETUP,   [ 'MyNJILGA_Page_Setup',     'render' ] );

        // Report detail pages: registered under the My NJILGA parent (so they
        // route correctly and keep the menu highlighted), then removed from the
        // visible submenu. They're reached by clicking into them from Reports.
        add_submenu_page( self::SLUG_ROOT, 'Active Members',     'Active Members',     'manage_options', self::SLUG_MEMBERS,   [ 'MyNJILGA_Page_Members',   'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Trustees',           'Trustees',           'manage_options', self::SLUG_TRUSTEES,  [ 'MyNJILGA_Page_Trustees',  'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Companies',          'Companies',          'manage_options', self::SLUG_COMPANIES, [ 'MyNJILGA_Page_Companies', 'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Membership by Firm', 'Membership by Firm', 'manage_options', self::SLUG_FIRMS,     [ 'MyNJILGA_Page_Firms',     'render' ] );

        remove_submenu_page( self::SLUG_ROOT, self::SLUG_MEMBERS );
        remove_submenu_page( self::SLUG_ROOT, self::SLUG_TRUSTEES );
        remove_submenu_page( self::SLUG_ROOT, self::SLUG_COMPANIES );
        remove_submenu_page( self::SLUG_ROOT, self::SLUG_FIRMS );
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
     * and without paid members, paid/unpaid trustees). Shown at the top of the
     * Reports landing page and each individual report. No-op without FluentCRM.
     */
    public static function render_stats_panel(): void {
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return;
        }

        $s = MyNJILGA_Members_Data::report_stats();

        $tiles = [
            [ 'Paid Members',             $s['paid_members'],       '#1d6f42' ],
            [ 'Unpaid Members',           $s['unpaid_members'],     '#d63638' ],
            [ 'Firms w/ Paid Members',    $s['firms_with_paid'],    '#1d6f42' ],
            [ 'Firms w/ No Paid Members', $s['firms_without_paid'], '#d63638' ],
            [ 'Paid Trustees',            $s['paid_trustees'],      '#1d6f42' ],
            [ 'Unpaid Trustees',          $s['unpaid_trustees'],    '#d63638' ],
        ];

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
