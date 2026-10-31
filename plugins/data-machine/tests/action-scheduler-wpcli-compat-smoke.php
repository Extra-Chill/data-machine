<?php
/**
 * Smoke coverage for the bundled Action Scheduler WP-CLI namespace shim.
 *
 * Run with: php tests/action-scheduler-wpcli-compat-smoke.php
 *
 * @package DataMachine\Tests
 */

echo "action-scheduler-wpcli-compat-smoke\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/** @var array<int,string> */
		public static array $commands = array();

		public static function runcommand( string $command ): string {
			self::$commands[] = $command;
			return 'ok';
		}
	}
}

require_once dirname( __DIR__ ) . '/inc/Cli/ActionSchedulerWPCLICompat.php';

$result = \Action_Scheduler\WP_CLI\WP_CLI::runcommand( 'action-scheduler action get 243850 --field=log_entries' );

if ( 'ok' !== $result ) {
	echo "FAIL: shim proxies return values from global WP_CLI\n";
	exit( 1 );
}

if ( array( 'action-scheduler action get 243850 --field=log_entries' ) !== WP_CLI::$commands ) {
	echo "FAIL: shim forwards namespaced WP_CLI::runcommand calls\n";
	exit( 1 );
}

$source = file_get_contents( dirname( __DIR__ ) . '/vendor/woocommerce/action-scheduler/classes/WP_CLI/Action_Command.php' );
if ( false === $source || ! str_contains( $source, 'WP_CLI::runcommand( $command );' ) ) {
	echo "FAIL: bundled Action Scheduler logs command still needs the namespace shim\n";
	exit( 1 );
}

echo "PASS: namespaced Action Scheduler WP_CLI calls proxy to global WP_CLI\n";
