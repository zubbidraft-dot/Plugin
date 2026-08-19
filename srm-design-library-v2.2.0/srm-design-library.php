<?php
/**
 * Plugin Name: SRM Design Library
 * Description: A private Elementor design library for storing, browsing, and importing reusable pages, sections, and blocks.
 * Version: 2.2.0
 * Author: SRM
 * Text Domain: srm-design-library
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SRMDL_VERSION', '2.2.0' );
define( 'SRMDL_FILE', __FILE__ );
define( 'SRMDL_DIR', plugin_dir_path( __FILE__ ) );
define( 'SRMDL_URL', plugin_dir_url( __FILE__ ) );

require_once SRMDL_DIR . 'includes/class-srm-design-library.php';
require_once SRMDL_DIR . 'includes/class-srm-cloud-library.php';

function srmdl_boot() {
    return SRM_Design_Library::instance();
}

add_action( 'plugins_loaded', 'srmdl_boot' );

function srmdl_cloud_boot() {
    return SRM_Cloud_Library::instance();
}

add_action( 'plugins_loaded', 'srmdl_cloud_boot', 20 );
