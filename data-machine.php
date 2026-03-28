<?php
/**
 * Plugin Name:     Data Machine
 * Plugin URI:      https://wordpress.org/plugins/data-machine/
 * Description:     AI-powered WordPress plugin for automated content workflows with visual pipeline builder and multi-provider AI integration.
 * Version:           0.60.0
 * Requires at least: 6.9
 * Requires PHP:     8.2
 * Author:          Chris Huber, extrachill
 * Author URI:      https://chubes.net
 * License:         GPL v2 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:     data-machine
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! datamachine_check_requirements() ) {
	return;
}

define( 'DATAMACHINE_VERSION', '0.60.0' );

define( 'DATAMACHINE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/vendor/autoload.php';

// WP-CLI integration
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/inc/Cli/Bootstrap.php';
}

// Procedural includes and side-effect registrations (see inc/bootstrap.php).
// Namespaced classes without file-level side effects rely on Composer PSR-4.
require_once __DIR__ . '/inc/bootstrap.php';

if ( ! class_exists( 'ActionScheduler' ) ) {
	require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}



// Plugin activation hook to initialize default settings
register_activation_hook( __FILE__, 'datamachine_activate_plugin_defaults' );
add_action( 'plugins_loaded', 'datamachine_run_datamachine_plugin', 20 );




add_filter( 'upload_mimes', 'datamachine_allow_json_upload' );

add_action( 'update_option_datamachine_settings', array( \DataMachine\Core\PluginSettings::class, 'clearCache' ) );

add_action(
	'plugins_loaded',
	function () {
		\DataMachine\Core\Database\Chat\Chat::ensure_context_column();
		\DataMachine\Core\Database\Chat\Chat::ensure_agent_id_column();
	},
	6
);

register_activation_hook( __FILE__, 'datamachine_activate_plugin' );
register_deactivation_hook( __FILE__, 'datamachine_deactivate_plugin' );

add_action( 'wp_initialize_site', 'datamachine_on_new_site', 200 );

// Migrations, scaffolding, and activation helpers.
require_once __DIR__ . '/inc/migrations.php';
