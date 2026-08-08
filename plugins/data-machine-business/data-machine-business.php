<?php
/**
 * Plugin Name: Data Machine Business
 * Plugin URI: https://github.com/Extra-Chill/data-machine-business
 * Description: Business and enterprise integrations for Data Machine. Adds support for Google Analytics, PageSpeed Insights, Google Sheets, Slack, Discord, and other business tools.
 * Version: 0.16.3
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

define( 'DATAMACHINE_BUSINESS_VERSION', '0.16.3' );
define( 'DATAMACHINE_BUSINESS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_BUSINESS_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 Autoloading.
$datamachine_business_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $datamachine_business_autoloader ) ) {
	require_once $datamachine_business_autoloader;
} else {
	spl_autoload_register(
		function ( string $class_name ): void {
			$prefix = 'DataMachineBusiness\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}

			$relative_class = substr( $class_name, strlen( $prefix ) );
			$file           = DATAMACHINE_BUSINESS_PATH . 'inc/' . str_replace( '\\', '/', $relative_class ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

/**
 * Load and instantiate business handlers and abilities.
 *
 * The Data Machine core dependency is checked inside this function (at
 * plugins_loaded time) rather than at file-include time because PHP
 * autoloading of classes from sibling network-active plugins is not
 * reliably available when our plugin file is being included, even if
 * the dependency plugin is loaded first alphabetically. Checking at
 * plugins_loaded ensures every plugin's autoloader is registered before
 * we test for the dependency.
 */
function datamachine_business_load_handlers() {
	if ( ! class_exists( 'DataMachine\\Core\\Steps\\Publish\\Handlers\\PublishHandler' ) || ! class_exists( 'DataMachine\\Abilities\\AbilityRegistration' ) ) {
		add_action( 'admin_notices', function () {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Data Machine Business requires Data Machine core plugin to be installed and activated.', 'data-machine-business' ); ?></p>
			</div>
			<?php
		} );
	}

	\DataMachineBusiness\Bootstrap\ProviderBootstrap::boot( \DataMachineBusiness\Bootstrap\ProviderModules::all() );
}

\DataMachineBusiness\Bootstrap\ProviderBootstrap::register_discovery();

// Hook into plugins_loaded to ensure Data Machine core is loaded first.
add_action( 'plugins_loaded', 'datamachine_business_load_handlers', 20 );

/**
 * Register the Data Machine Business AGENTS.md section.
 *
 * Runs in every context (web/cron compose, not only WP-CLI) so concise routing
 * guidance remains available wherever memory is composed.
 */
add_action( 'plugins_loaded', function (): void {
	\DataMachineBusiness\Runtime\AgentsMdSections::register();
}, 21 );

/**
 * Register business-owned analytics routes with Data Machine core.
 */
add_filter( 'datamachine_analytics_ability_map', function ( array $ability_map ): array {
	$ability_map['ga']   = 'datamachine/google-analytics';
	$ability_map['bing'] = 'datamachine/bing-webmaster';
	return $ability_map;
} );

/**
 * Register business-owned WP-CLI commands.
 *
 * The command-string => class map in DataMachineBusiness\Cli\CommandRegistry is
 * the single source of truth for command registration.
 */
add_action( 'plugins_loaded', function (): void {
	// @phpstan-ignore-next-line WP_CLI can be false outside the analysis bootstrap.
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'DataMachine\\Cli\\BaseCommand' ) ) {
		return;
	}

	foreach ( \DataMachineBusiness\Cli\CommandRegistry::map() as $command => $command_class ) {
		\WP_CLI::add_command( $command, $command_class );
	}
}, 21 );

/**
 * Contribute Google Drive scopes to the unified Google OAuth credential.
 *
 * Every Google handler in this plugin (Sheets, Drive, future Calendar,
 * etc.) shares a single Google OAuth client registered under the
 * `google` provider slug so the user only has to grant Data Machine
 * access to Google once. Each handler family declares the scopes it
 * needs here; GoogleAuth::get_scopes() unions and de-duplicates them
 * at consent time.
 *
 * Tokens issued before a scope was added will NOT contain it. Affected
 * users must disconnect and reconnect the Google integration to
 * re-consent. The Drive handler surfaces a clear re-consent error
 * (googledrive_scope_missing) rather than silently returning empty.
 */
add_filter( 'datamachine_google_oauth_scopes', function ( array $scopes ): array {
	foreach ( \DataMachineBusiness\Handlers\GoogleDrive\GoogleDriveSettings::required_scopes() as $scope ) {
		if ( ! in_array( $scope, $scopes, true ) ) {
			$scopes[] = $scope;
		}
	}
	return $scopes;
} );
