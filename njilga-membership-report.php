<?php
/**
 * Plugin Name: My NJILGA
 * Plugin URI:  https://njilga.org
 * Description: NJILGA membership dashboard, member/trustee/company reports, and Excel export — driven entirely from FluentCRM tags on the local install.
 * Version:     2.7.2
 * Author:      S-FX.com
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NJILGA_REPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'NJILGA_REPORT_URL', plugin_dir_url( __FILE__ ) );

// Composer autoload powers the GitHub update checker on every request and
// PhpSpreadsheet on the export request. Classmapped, so PhpSpreadsheet
// itself isn't loaded until first use.
$njilga_autoload = NJILGA_REPORT_DIR . 'vendor/autoload.php';
if ( file_exists( $njilga_autoload ) ) {
    require_once $njilga_autoload;
}

// GitHub-release-based update checks via yahnis-elsts/plugin-update-checker.
// Cuts a new GitHub Release in s-fx-com/MyNJILGA → all installs offer the update.
// For a private repo, define MY_NJILGA_GITHUB_TOKEN in wp-config.php with a PAT.
if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
    $njilga_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/s-fx-com/MyNJILGA/',
        __FILE__,
        'my-njilga'
    );
    if ( defined( 'MY_NJILGA_GITHUB_TOKEN' ) && MY_NJILGA_GITHUB_TOKEN ) {
        $njilga_updater->setAuthentication( MY_NJILGA_GITHUB_TOKEN );
    }
}

require_once NJILGA_REPORT_DIR . 'includes/class-tags.php';
require_once NJILGA_REPORT_DIR . 'includes/class-members-data.php';
require_once NJILGA_REPORT_DIR . 'includes/class-report-csv.php';
require_once NJILGA_REPORT_DIR . 'includes/class-report-xls.php';
require_once NJILGA_REPORT_DIR . 'includes/class-report-summary.php';
require_once NJILGA_REPORT_DIR . 'includes/class-admin-menu.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-dashboard.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-reports.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-members.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-trustees.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-companies.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-firms.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-setup.php';

// Dues Invoicing by Firm — annual, admin-triggered batch invoicing against
// FluentCart. See includes/invoicing/ for the full flow (preview → approve
// → create → send → paid webhook → downgrade sweep).
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-dues-invoice-table.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-invoicing-notes.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-dues-preview.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-invoice-creator.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-invoice-sender.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-payment-listener.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-downgrade-sweep.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-invoicing.php';

add_action( 'admin_menu', [ 'MyNJILGA_Admin_Menu', 'register' ] );

// Keep My NJILGA → Reports highlighted while viewing a hidden report page.
add_filter( 'parent_file',  [ 'MyNJILGA_Admin_Menu', 'highlight_parent_menu' ] );
add_filter( 'submenu_file', [ 'MyNJILGA_Admin_Menu', 'highlight_submenu' ] );

// njilga_dues_invoices table: created on fresh activation, AND re-checked
// on every admin_init. WordPress only fires register_activation_hook on a
// brand new activation — an already-active site picking up this table via
// an auto-update would never see it created without the admin_init check.
register_activation_hook( __FILE__, [ 'MyNJILGA_Dues_Invoice_Table', 'maybe_upgrade' ] );
add_action( 'admin_init', [ 'MyNJILGA_Dues_Invoice_Table', 'maybe_upgrade' ] );

// Dues Invoicing: FluentCart's own "order paid" hook cascades tags/role.
MyNJILGA_Payment_Listener::register();

// Setup page: create a missing tag via the FluentCRM Tags API.
add_action( 'admin_post_my_njilga_create_tag', [ 'MyNJILGA_Page_Setup', 'handle_create_tag' ] );

// Per-page CSV exports. ?type=members|trustees|companies determines the report.
add_action( 'admin_post_my_njilga_export_csv', static function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Access denied.' );
    }
    check_admin_referer( 'my_njilga_export_csv' );

    if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
        wp_die( 'FluentCRM is not active.' );
    }

    $type = sanitize_key( $_REQUEST['type'] ?? '' );
    MyNJILGA_Report_Csv::stream( $type );
} );

// Membership by Firm — formatted Excel (.xls) export.
add_action( 'admin_post_my_njilga_export_firms', [ 'MyNJILGA_Report_Xls', 'handle' ] );

// Executive Summary — formatted Excel (.xls) export combining every report.
add_action( 'admin_post_my_njilga_export_summary', [ 'MyNJILGA_Report_Summary', 'handle' ] );

// Dues Invoicing by Firm — preview/approve/create/send/downgrade actions.
add_action( 'admin_post_my_njilga_dues_preview',   [ 'MyNJILGA_Page_Invoicing', 'handle_preview' ] );
add_action( 'admin_post_my_njilga_dues_approve',   [ 'MyNJILGA_Page_Invoicing', 'handle_approve' ] );
add_action( 'admin_post_my_njilga_dues_create',    [ 'MyNJILGA_Page_Invoicing', 'handle_create' ] );
add_action( 'admin_post_my_njilga_dues_send',      [ 'MyNJILGA_Page_Invoicing', 'handle_send' ] );
add_action( 'admin_post_my_njilga_dues_downgrade', [ 'MyNJILGA_Page_Invoicing', 'handle_downgrade' ] );
