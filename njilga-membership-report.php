<?php
/**
 * Plugin Name: NJILGA Membership Report
 * Plugin URI:  https://njilga.org
 * Description: Generates a Member Dues Report from FluentCRM data, exportable as Excel.
 * Version:     1.0.0
 * Author:      NJILGA
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NJILGA_REPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'NJILGA_REPORT_URL', plugin_dir_url( __FILE__ ) );

require_once NJILGA_REPORT_DIR . 'includes/class-pmpro-data.php';
require_once NJILGA_REPORT_DIR . 'includes/class-report-data.php';
require_once NJILGA_REPORT_DIR . 'includes/class-report-xlsx.php';
require_once NJILGA_REPORT_DIR . 'includes/class-admin-page.php';

add_action( 'admin_menu', [ 'NJILGA_Admin_Page', 'register' ] );
add_action( 'admin_post_njilga_export_report', [ 'NJILGA_Admin_Page', 'handle_export' ] );
