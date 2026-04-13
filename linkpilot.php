<?php
/**
 * Plugin Name: LinkPilot
 * Plugin URI: https://linkpilothq.com
 * Description: The intelligent link manager for WordPress. Manage, track, and optimize your outbound links.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Jean Galea
 * Author URI: https://jeangalea.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: linkpilot
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LP_VERSION', '1.0.0' );
define( 'LP_PLUGIN_FILE', __FILE__ );
define( 'LP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader
spl_autoload_register( function ( $class ) {
    $prefix = 'LP_';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $filename = 'class-lp-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

    // Check includes/ first, then includes/migrators/
    $file = LP_PLUGIN_DIR . 'includes/' . $filename;
    if ( file_exists( $file ) ) {
        require_once $file;
        return;
    }
    $file = LP_PLUGIN_DIR . 'includes/migrators/' . $filename;
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// The main class is named LinkPilot, not LP_*, so load it manually
require_once LP_PLUGIN_DIR . 'includes/class-linkpilot.php';

register_activation_hook( __FILE__, array( 'LP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LP_Deactivator', 'deactivate' ) );

LinkPilot::instance();
