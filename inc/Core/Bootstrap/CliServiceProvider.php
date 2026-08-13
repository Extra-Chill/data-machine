<?php
/**
 * CLI service provider.
 *
 * @package DataMachine\Core\Bootstrap
 */

namespace DataMachine\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

use DataMachine\Cli\CommandRegistry;
use WP_CLI;

/**
 * Loads the Data Machine command surface in WP-CLI requests.
 */
final class CliServiceProvider {

	/**
	 * Register CLI commands when WP-CLI is active.
	 */
	public static function register(): void {
		// @phpstan-ignore-next-line Runtime constant may be defined false outside PHPStan's configured CLI context.
		if ( ! defined( 'WP_CLI' ) || ! (bool) constant( 'WP_CLI' ) ) {
			return;
		}

		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		$cli_root = dirname( __DIR__, 2 ) . '/Cli';
		require_once $cli_root . '/ActionSchedulerWPCLICompat.php';
		require_once $cli_root . '/CommandRegistry.php';

		foreach ( CommandRegistry::map() as $command => $class ) {
			WP_CLI::add_command( $command, $class );
		}
	}
}
