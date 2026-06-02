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
	$bootstrap   = file_get_contents( $plugin_root . '/inc/bootstrap.php' );
	$plugin_file = file_get_contents( $plugin_root . '/data-machine.php' );

	if ( false === $bootstrap || false === $plugin_file ) {
		fwrite( STDERR, "FAIL: bootstrap source is not readable\n" );
		exit( 1 );
	}

	$assert(
		'access-store adapter registration uses centralized contract check',
		str_contains( $bootstrap, 'DependencyChecker::CHECK_AGENTS_API_ACCESS_STORE' )
	);

	$assert(
		'pending-action observer registration uses centralized contract check',
		str_contains( $plugin_file, 'DependencyChecker::CHECK_PENDING_ACTION_OBSERVER' )
	);

	$assert(
		'core named dependency checks exist',
		DependencyChecker::CHECK_ACTION_SCHEDULER === 'action_scheduler'
			&& DependencyChecker::CHECK_FILESYSTEM_WRITES === 'filesystem_writes'
			&& DependencyChecker::CHECK_IMAP === 'imap'
			&& DependencyChecker::CHECK_WORDPRESS_ABILITIES === 'wordpress_abilities'
			&& DependencyChecker::CHECK_ZIP_ARCHIVE === 'zip_archive'
	);

	if ( ! interface_exists( 'WP_Agent_Access_Store' ) ) {
		interface WP_Agent_Access_Store {}
	}

	if ( ! interface_exists( 'WP_Agent_Principal_Access_Store' ) ) {
		interface WP_Agent_Principal_Access_Store {}
	}
}

namespace AgentsAPI\AI\Approvals {
	if ( ! interface_exists( WP_Agent_Pending_Action_Observer::class ) ) {
		interface WP_Agent_Pending_Action_Observer {}
	}
}

namespace {
	use DataMachine\Core\Bootstrap\DependencyChecker;

	$assert(
		'access-store contracts are detected after stubs load',
		DependencyChecker::has( DependencyChecker::CHECK_AGENTS_API_ACCESS_STORE )
	);

	$assert(
		'pending-action observer contract is detected after stub loads',
		DependencyChecker::has( DependencyChecker::CHECK_PENDING_ACTION_OBSERVER )
	);

	$assert(
		'unknown dependency checks fail closed',
		! DependencyChecker::has( 'missing-check' )
	);

	if ( $failed > 0 ) {
		fwrite( STDERR, "bootstrap runtime environment smoke failed: {$failed}/{$total}\n" );
		exit( 1 );
	}

	echo "Bootstrap runtime environment smoke passed: {$total} assertions.\n";
}
