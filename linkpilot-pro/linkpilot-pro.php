<?php
/**
 * Plugin Name: LinkPilot Pro
 * Plugin URI: https://linkpilothq.com
 * Description: AI-powered features for LinkPilot — smart link suggestions, auto-linking, and content gap detection.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Jean Galea
 * Author URI: https://jeangalea.com
 * License: GPL v2 or later
 * Text Domain: linkpilot-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LPP_VERSION', '1.0.0' );
define( 'LPP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LPP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

add_action( 'plugins_loaded', 'lpp_init' );

function lpp_init() {
    if ( ! class_exists( 'LinkPilot' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            esc_html_e( 'LinkPilot Pro requires the LinkPilot plugin to be installed and active.', 'linkpilot-pro' );
            echo '</p></div>';
        } );
        return;
    }

    spl_autoload_register( function ( $class ) {
        $prefix = 'LPP_';
        if ( strpos( $class, $prefix ) !== 0 ) {
            return;
        }
        $relative = substr( $class, strlen( $prefix ) );
        $file = LPP_PLUGIN_DIR . 'includes/class-lpp-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    } );

    if ( is_admin() ) {
        LPP_Settings::init();
    }

    LPP_Auto_Linker::init();
    LPP_Content_Gaps::init();
    LPP_REST_API::init();
    LPP_Suggestions::init();

    add_action( 'enqueue_block_editor_assets', function() {
        if ( get_option( 'lpp_enable_suggestions', 'yes' ) !== 'yes' ) {
            return;
        }
        wp_enqueue_script(
            'lpp-sidebar',
            LPP_PLUGIN_URL . 'assets/js/sidebar.js',
            array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks', 'wp-api-fetch' ),
            LPP_VERSION,
            true
        );
    } );
}
