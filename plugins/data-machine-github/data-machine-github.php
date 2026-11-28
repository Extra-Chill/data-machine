<?php
/**
 * Plugin Name: Data Machine GitHub
 * Plugin URI: https://github.com/Extra-Chill/data-machine/tree/main/plugins/data-machine-github
 * Description: Fetch GitHub repository files into Data Machine pipelines.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: data-machine
 * Author: Chris Huber, extrachill
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: data-machine-github
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DATAMACHINE_GITHUB_VERSION', '0.1.0' );
define( 'DATAMACHINE_GITHUB_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_GITHUB_URL', plugin_dir_url( __FILE__ ) );

$datamachine_github_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $datamachine_github_autoloader ) ) {
	require_once $datamachine_github_autoloader;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'DataMachineGitHub\\';
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}

			$file = DATAMACHINE_GITHUB_PATH . 'inc/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

/** Register after core and sibling plugin autoloaders are available. */
function datamachine_github_load_handlers(): void {
	if ( ! class_exists( \DataMachine\Core\Steps\Fetch\Handlers\FetchHandler::class ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Data Machine GitHub requires the Data Machine core plugin to be active.', 'data-machine-github' ) . '</p></div>';
			}
		);
		return;
	}

	new \DataMachineGitHub\Handlers\GitHub\GitHubFetch();
}

add_action( 'plugins_loaded', 'datamachine_github_load_handlers', 20 );
