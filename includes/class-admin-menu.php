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
}
