<?php
/**
 * Plugin Name:       CloseHub Connector
 * Plugin URI:        https://github.com/closemarketing/closehub-connector
 * Description:       Connect your WordPress site to CloseHub with a single API key. Exposes secure endpoints for posts, WooCommerce, and Gravity Forms, with Multisite network support.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Close Marketing
 * Author URI:        https://close.marketing
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       closehub-connector
 */

defined( 'ABSPATH' ) || exit;

define( 'CLOSEHUB_VERSION', '1.1.0' );
define( 'CLOSEHUB_PLUGIN_FILE', __FILE__ );
define( 'CLOSEHUB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

register_activation_hook( __FILE__, 'closehub_activate' );
register_deactivation_hook( __FILE__, 'closehub_deactivate' );

function closehub_activate(): void {
	require_once CLOSEHUB_PLUGIN_DIR . 'includes/class-api-key.php';
	CloseHub_API_Key::maybe_generate();
	flush_rewrite_rules();
}

function closehub_deactivate(): void {
	flush_rewrite_rules();
}

add_action( 'plugins_loaded', 'closehub_init' );

function closehub_init(): void {
	require_once CLOSEHUB_PLUGIN_DIR . 'includes/class-api-key.php';
	require_once CLOSEHUB_PLUGIN_DIR . 'includes/class-rest-api.php';
	require_once CLOSEHUB_PLUGIN_DIR . 'includes/class-admin.php';

	( new CloseHub_REST_API() )->register();
	( new CloseHub_Admin() )->register();
}
