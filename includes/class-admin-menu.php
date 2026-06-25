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

        add_submenu_page( self::SLUG_ROOT, 'Dashboard',      'Dashboard',      'manage_options', self::SLUG_ROOT,      [ 'MyNJILGA_Page_Dashboard', 'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Active Members', 'Active Members', 'manage_options', self::SLUG_MEMBERS,   [ 'MyNJILGA_Page_Members',   'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Trustees',       'Trustees',       'manage_options', self::SLUG_TRUSTEES,  [ 'MyNJILGA_Page_Trustees',  'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Companies',      'Companies',      'manage_options', self::SLUG_COMPANIES, [ 'MyNJILGA_Page_Companies', 'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Membership by Firm', 'Membership by Firm', 'manage_options', self::SLUG_FIRMS, [ 'MyNJILGA_Page_Firms', 'render' ] );
        add_submenu_page( self::SLUG_ROOT, 'Setup',          'Setup',          'manage_options', self::SLUG_SETUP,     [ 'MyNJILGA_Page_Setup',     'render' ] );
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
    public static function render_firms_export_button( string $label = 'Export to Excel' ): void {
        printf(
            '<form method="post" action="%s" style="margin:0 0 12px">
                <input type="hidden" name="action" value="my_njilga_export_firms">
                %s
                <button type="submit" class="button button-primary">%s</button>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            wp_nonce_field( 'my_njilga_export_firms', '_wpnonce', true, false ),
            esc_html( $label )
        );
    }
}
