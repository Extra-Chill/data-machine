<?php
/**
 * Plugin Name: Data Machine Business
 * Plugin URI: https://github.com/Extra-Chill/data-machine-business
 * Description: Business and enterprise integrations for Data Machine. Adds support for Google Sheets, Slack, Discord, and other business tools.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: data-machine
 * Author: Chris Huber, extrachill
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: data-machine-business
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Check if Data Machine core is active
if ( ! class_exists( 'DataMachine\Core\Steps\Publish\Handlers\PublishHandler' ) ) {
	add_action( 'admin_notices', function() {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Data Machine Business requires Data Machine core plugin to be installed and activated.', 'data-machine-business' ); ?></p>
		</div>
		<?php
	} );
	return;
}

define( 'DATAMACHINE_BUSINESS_VERSION', '0.2.0' );
define( 'DATAMACHINE_BUSINESS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_BUSINESS_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 Autoloading
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Load and instantiate business handlers and abilities.
 */
function datamachine_business_load_handlers() {
	// Load Abilities (they self-register)
	// Google Sheets
	new \DataMachineBusiness\Abilities\GoogleSheets\FetchGoogleSheetsAbility();
	new \DataMachineBusiness\Abilities\GoogleSheets\PublishGoogleSheetsAbility();

	// Google Sheets Handlers
	new \DataMachineBusiness\Handlers\GoogleSheets\GoogleSheetsFetch();
	new \DataMachineBusiness\Handlers\GoogleSheets\GoogleSheetsPublish();

	// Slack
	new \DataMachineBusiness\Abilities\Slack\PostMessageSlackAbility();
	new \DataMachineBusiness\Abilities\Slack\FetchMessagesSlackAbility();

	// Slack Handlers
	new \DataMachineBusiness\Handlers\Slack\SlackPublish();
	new \DataMachineBusiness\Handlers\Slack\SlackFetch();
}

// Hook into plugins_loaded to ensure Data Machine core is loaded first
add_action( 'plugins_loaded', 'datamachine_business_load_handlers', 20 );
