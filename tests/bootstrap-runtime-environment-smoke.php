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
	$bootstrap = file_get_contents( $plugin_root . '/inc/bootstrap.php' );

	if ( false === $bootstrap ) {
		fwrite( STDERR, "FAIL: bootstrap source is not readable\n" );
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

}

namespace {
	use DataMachine\Core\Bootstrap\DependencyChecker;

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
