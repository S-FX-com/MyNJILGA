<?php
/**
 * Plugin Name: My NJILGA
 * Plugin URI:  https://njilga.org
 * Description: NJILGA membership dashboard, member/trustee/company reports, and Excel export — driven entirely from FluentCRM tags on the local install.
 * Version:     2.0.0
 * Author:      NJILGA
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NJILGA_REPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'NJILGA_REPORT_URL', plugin_dir_url( __FILE__ ) );

require_once NJILGA_REPORT_DIR . 'includes/class-tags.php';
require_once NJILGA_REPORT_DIR . 'includes/class-members-data.php';
require_once NJILGA_REPORT_DIR . 'includes/class-report-xlsx.php';
require_once NJILGA_REPORT_DIR . 'includes/class-admin-menu.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-dashboard.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-members.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-trustees.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-companies.php';
require_once NJILGA_REPORT_DIR . 'includes/class-page-setup.php';

add_action( 'admin_menu', [ 'MyNJILGA_Admin_Menu', 'register' ] );

// Setup page: create a missing tag via the FluentCRM Tags API.
add_action( 'admin_post_my_njilga_create_tag', [ 'MyNJILGA_Page_Setup', 'handle_create_tag' ] );

// Dashboard download: stream the three-sheet Excel workbook.
add_action( 'admin_post_my_njilga_export', static function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Access denied.' );
    }
    check_admin_referer( 'my_njilga_export' );

    $autoload = NJILGA_REPORT_DIR . 'vendor/autoload.php';
    if ( ! file_exists( $autoload ) ) {
        wp_die( 'PhpSpreadsheet not found. Run <code>composer install</code> in the plugin directory.' );
    }
    require_once $autoload;

    if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
        wp_die( 'FluentCRM is not active.' );
    }

    MyNJILGA_Report_Xlsx::stream();
} );
