<?php
/**
 * Pure-PHP smoke test for bootstrap dependency and request-shape checks.
 *
 * Run with: php tests/bootstrap-runtime-environment-smoke.php
 *
 * @package DataMachine\Tests
 */

declare( strict_types = 1 );

namespace {
	use DataMachine\Core\Bootstrap\DependencyChecker;
	use DataMachine\Core\Bootstrap\RuntimeEnvironment;

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/' );
	}

	require_once dirname( __DIR__ ) . '/inc/Core/Bootstrap/RuntimeEnvironment.php';
	require_once dirname( __DIR__ ) . '/inc/Core/Bootstrap/DependencyChecker.php';

	$failed = 0;
	$total  = 0;

	$assert = static function ( string $name, bool $condition ) use ( &$failed, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  PASS: {$name}\n";
			return;
		}

		echo "  FAIL: {$name}\n";
		++$failed;
	};

	$plugin_root = dirname( __DIR__ );
	$bootstrap = file_get_contents( $plugin_root . '/inc/bootstrap.php' );

	if ( false === $bootstrap ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: bootstrap source is not readable\n" );
		exit( 1 );
	}

	$assert(
		'access-store adapter registration no longer gates on Agents API presence checks',
		! str_contains( $bootstrap, 'DependencyChecker::CHECK_AGENTS_API_ACCESS_STORE' )
	);

	$assert(
		'identity-store adapter registration no longer gates on Agents API presence checks',
		! str_contains( $bootstrap, 'DependencyChecker::CHECK_AGENTS_API_IDENTITY_STORE' )
	);

	$assert(
		'core named dependency checks exist',
		DependencyChecker::CHECK_ACTION_SCHEDULER === 'action_scheduler'
			&& DependencyChecker::CHECK_FILESYSTEM_WRITES === 'filesystem_writes'
			&& DependencyChecker::CHECK_IMAP === 'imap'
			&& DependencyChecker::CHECK_WORDPRESS_ABILITIES === 'wordpress_abilities'
			&& DependencyChecker::CHECK_ZIP_ARCHIVE === 'zip_archive'
	);

	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin(): bool {
			return false;
		}
	}

	if ( ! function_exists( 'wp_doing_ajax' ) ) {
		function wp_doing_ajax(): bool {
			return false;
		}
	}

	if ( ! function_exists( 'wp_doing_cron' ) ) {
		function wp_doing_cron(): bool {
			return false;
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string {
			return is_scalar( $value ) ? (string) $value : '';
		}
	}

	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return $value;
		}
	}

	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( string $url, int $component = -1 ) {
			return parse_url( $url, $component );
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, $value ) {
			return $value;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook_name, ...$args ): void {
			unset( $hook_name, $args );
		}
	}

	$_SERVER['REQUEST_URI'] = '/frontend-page/';
	unset( $_GET['rest_route'] );
	putenv( 'WP_AGENT_RUNTIME' );

	$assert(
		'normal frontend request remains lazy by default',
		! RuntimeEnvironment::should_load_full_runtime()
	);

	putenv( 'WP_AGENT_RUNTIME=1' );

	$assert(
		'agent runtime signal loads full runtime',
		RuntimeEnvironment::should_load_full_runtime()
	);

	putenv( 'WP_AGENT_RUNTIME' );
	RuntimeEnvironment::request_full_runtime( 'test-host' );

	$assert(
		'explicit runtime request loads full runtime',
		RuntimeEnvironment::should_load_full_runtime()
	);

}

namespace {
	use DataMachine\Core\Bootstrap\DependencyChecker;

	$assert(
		'unknown dependency checks fail closed',
		! DependencyChecker::has( 'missing-check' )
	);

	if ( $failed > 0 ) {
		fwrite( fopen( 'php://stderr', 'w' ), "bootstrap runtime environment smoke failed: {$failed}/{$total}\n" );
		exit( 1 );
	}

	echo "Bootstrap runtime environment smoke passed: {$total} assertions.\n";
}
