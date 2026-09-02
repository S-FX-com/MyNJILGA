<?php
/**
 * Plugin Name: My NJILGA
 * Plugin URI:  https://njilga.org
 * Description: NJILGA membership dashboard, member/trustee/company reports, annual dues invoicing (FluentCart + FluentCRM), membership application gate, and member-facing dues status — driven entirely from FluentCRM tags on the local install.
 * Version:     2.11.0
 * Author:      S-FX.com
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NJILGA_REPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'NJILGA_REPORT_URL', plugin_dir_url( __FILE__ ) );

// Composer autoload powers the GitHub update checker.
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

// Reports (tag-driven).
require_once NJILGA_REPORT_DIR . 'includes/class-tags.php';
require_once NJILGA_REPORT_DIR . 'includes/class-members-data.php';
require_once NJILGA_REPORT_DIR . 'includes/class-report-csv.php';
require_once NJILGA_REPORT_DIR . 'includes/class-report-xls.php';
require_once NJILGA_REPORT_DIR . 'includes/class-report-summary.php';
require_once NJILGA_REPORT_DIR . 'includes/class-admin-ui.php';
require_once NJILGA_REPORT_DIR . 'includes/class-admin-menu.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-dashboard.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-reports.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-members.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-trustees.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-companies.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-firms.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-setup.php';

// Dues Invoicing — annual, admin-triggered batch invoicing through the
// InvoiceGateway (Stripe). See includes/invoicing/ and README.
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-dues-settings.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-stripe-client.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-stripe-connection.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-pricing-engine.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-dues-snapshot.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-dues-invoice-table.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-dues-payments-table.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-stripe-events-table.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-stripe-customer-map.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-invoicing-notes.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/interface-invoice-gateway.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-stripe-invoice-gateway.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-stripe-webhook.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-invoicing.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-dues-roster.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-dues-preview.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-invoice-creator.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-invoice-sender.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-payment-listener.php';
require_once NJILGA_REPORT_DIR . 'includes/invoicing/class-downgrade-sweep.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-invoicing.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-settings.php';

// Enrollment gate (application form → review queue → approval) and the
// member-facing firm dues status page.
require_once NJILGA_REPORT_DIR . 'includes/enrollment/class-applications-table.php';
require_once NJILGA_REPORT_DIR . 'includes/enrollment/class-application-form.php';
require_once NJILGA_REPORT_DIR . 'includes/enrollment/class-application-review.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-applications.php';
require_once NJILGA_REPORT_DIR . 'includes/class-firm-status-page.php';

add_action( 'admin_menu', [ 'MyNJILGA_Admin_Menu', 'register' ] );

// Keep My NJILGA → Reports highlighted while viewing a hidden report page.
add_filter( 'parent_file',  [ 'MyNJILGA_Admin_Menu', 'highlight_parent_menu' ] );
add_filter( 'submenu_file', [ 'MyNJILGA_Admin_Menu', 'highlight_submenu' ] );

// Custom tables: created on fresh activation AND re-checked on every
// admin_init — WordPress only fires the activation hook on a brand new
// activation, never on an auto-update of an already-active plugin.
register_activation_hook( __FILE__, [ 'MyNJILGA_Dues_Invoice_Table', 'maybe_upgrade' ] );
register_activation_hook( __FILE__, [ 'MyNJILGA_Applications_Table', 'maybe_upgrade' ] );
register_activation_hook( __FILE__, [ 'MyNJILGA_Dues_Payments_Table', 'maybe_upgrade' ] );
register_activation_hook( __FILE__, [ 'MyNJILGA_Stripe_Events_Table', 'maybe_upgrade' ] );
register_activation_hook( __FILE__, [ 'MyNJILGA_Stripe_Customer_Map', 'maybe_upgrade' ] );
add_action( 'admin_init', [ 'MyNJILGA_Dues_Invoice_Table', 'maybe_upgrade' ] );
add_action( 'admin_init', [ 'MyNJILGA_Applications_Table', 'maybe_upgrade' ] );
add_action( 'admin_init', [ 'MyNJILGA_Dues_Payments_Table', 'maybe_upgrade' ] );
add_action( 'admin_init', [ 'MyNJILGA_Stripe_Events_Table', 'maybe_upgrade' ] );
add_action( 'admin_init', [ 'MyNJILGA_Stripe_Customer_Map', 'maybe_upgrade' ] );

// Background invoice creation (Action Scheduler chunks) — the hook must be
// registered on every request so the scheduler's worker can find it.
MyNJILGA_Invoice_Creator::register();

// Stripe webhook receiver — registers its own REST route (rest_api_init)
// and its Action Scheduler processing hook; must run on every request so
// both the REST endpoint and the scheduler's worker can find it.
MyNJILGA_Stripe_Webhook::register();

// Payment listener: registered once every plugin has loaded, so a site
// can swap the invoice gateway via the `my_njilga_invoice_gateway` filter
// before the "order paid" hook is bound.
add_action( 'plugins_loaded', [ 'MyNJILGA_Payment_Listener', 'register' ], 20 );

// Public shortcodes: [njilga_membership_application], [njilga_firm_dues_status].
MyNJILGA_Application_Form::register();
MyNJILGA_Firm_Status_Page::register();

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

// Dues Invoicing — preview/approve/create/send/downgrade actions.
add_action( 'admin_post_' . MyNJILGA_Page_Invoicing::ACTION_PREVIEW,   [ 'MyNJILGA_Page_Invoicing', 'handle_preview' ] );
add_action( 'admin_post_' . MyNJILGA_Page_Invoicing::ACTION_APPROVE,   [ 'MyNJILGA_Page_Invoicing', 'handle_approve' ] );
add_action( 'admin_post_' . MyNJILGA_Page_Invoicing::ACTION_CREATE,    [ 'MyNJILGA_Page_Invoicing', 'handle_create' ] );
add_action( 'admin_post_' . MyNJILGA_Page_Invoicing::ACTION_SEND,      [ 'MyNJILGA_Page_Invoicing', 'handle_send' ] );
add_action( 'admin_post_' . MyNJILGA_Page_Invoicing::ACTION_DOWNGRADE, [ 'MyNJILGA_Page_Invoicing', 'handle_downgrade' ] );

// Dues & Billing settings.
add_action( 'admin_post_' . MyNJILGA_Page_Settings::ACTION_SAVE,  [ 'MyNJILGA_Page_Settings', 'handle_save' ] );
add_action( 'admin_post_' . MyNJILGA_Page_Settings::ACTION_RESET, [ 'MyNJILGA_Page_Settings', 'handle_reset' ] );

// Settings > Payments tab — Stripe connect/credential actions.
add_action( 'admin_post_' . MyNJILGA_Page_Settings::ACTION_PAYMENTS_SAVE,       [ 'MyNJILGA_Page_Settings', 'handle_payments_save' ] );
add_action( 'admin_post_' . MyNJILGA_Page_Settings::ACTION_STRIPE_CONNECT,      [ 'MyNJILGA_Page_Settings', 'handle_connect' ] );
add_action( 'admin_post_' . MyNJILGA_Page_Settings::ACTION_STRIPE_WEBHOOK_SAVE, [ 'MyNJILGA_Page_Settings', 'handle_webhook_save' ] );
add_action( 'admin_post_' . MyNJILGA_Page_Settings::ACTION_STRIPE_SWITCH_MODE,  [ 'MyNJILGA_Page_Settings', 'handle_switch_mode' ] );

// Applications review queue.
add_action( 'admin_post_' . MyNJILGA_Page_Applications::ACTION_DECIDE, [ 'MyNJILGA_Page_Applications', 'handle_decide' ] );
