<?php
/**
 * Plugin Name: Data Machine Socials
 * Plugin URI: https://github.com/Extra-Chill/data-machine-socials
 * Description: Social media publishing extension for Data Machine. Adds support for Twitter, Facebook, Bluesky, Threads, and Pinterest.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: data-machine
 * Author: Chris Huber, extrachill
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: data-machine-socials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Check if Data Machine core is active
if ( ! class_exists( 'DataMachine\Core\Steps\Publish\Handlers\PublishHandler' ) ) {
	add_action( 'admin_notices', function() {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Data Machine Socials requires Data Machine core plugin to be installed and activated.', 'data-machine-socials' ); ?></p>
		</div>
		<?php
	} );
	return;
}

define( 'DATAMACHINE_SOCIALS_VERSION', '0.1.0' );
define( 'DATAMACHINE_SOCIALS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_SOCIALS_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 Autoloading
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Load and instantiate social media handlers and abilities.
 */
function datamachine_socials_load_handlers() {
	// Load Abilities (they self-register)
	new \DataMachineSocials\Abilities\Pinterest\PinterestBoardsAbility();
	new \DataMachineSocials\Abilities\Pinterest\PinterestPublishAbility();

	// Social Publish Handlers
	new \DataMachineSocials\Handlers\Twitter\Twitter();
	new \DataMachineSocials\Handlers\Facebook\Facebook();
	new \DataMachineSocials\Handlers\Threads\Threads();
	new \DataMachineSocials\Handlers\Bluesky\Bluesky();
	new \DataMachineSocials\Handlers\Pinterest\Pinterest();
}

// Hook into plugins_loaded to ensure Data Machine core is loaded first
add_action( 'plugins_loaded', 'datamachine_socials_load_handlers', 20 );

/**
 * Register WP-CLI commands.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DATAMACHINE_SOCIALS_PATH . 'inc/Cli/Commands/PinterestCommand.php';
	WP_CLI::add_command( 'datamachine-socials pinterest', \DataMachineSocials\Cli\Commands\PinterestCommand::class );
}
