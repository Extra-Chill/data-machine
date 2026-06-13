<?php
/**
 * Pure-PHP smoke test for the admin scale fixture contract.
 *
 * Run with: php tests/admin-scale-fixture-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';
		return trim( $title, '-' );
	}
}

require_once __DIR__ . '/../inc/Core/Fixtures/AdminScaleFixture.php';

use DataMachine\Core\Fixtures\AdminScaleFixture;

$failures = array();
$passes   = 0;

function assert_admin_scale_fixture_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected !== $actual ) {
		$failures[] = sprintf( '%s: expected %s, got %s', $name, var_export( $expected, true ), var_export( $actual, true ) );
		return;
	}

	$passes++;
}

echo "admin-scale-fixture-smoke\n";

$config = AdminScaleFixture::normalize_config(
	array(
		'seed_slug'          => 'Profile Run!',
		'pipeline_count'     => '2',
		'flows_per_pipeline' => '3',
		'steps_per_flow'     => '4',
		'payload_size'       => '512',
	)
);

assert_admin_scale_fixture_equals( 'profile-run', $config['seed_slug'], 'normalizes seed slug', $failures, $passes );
assert_admin_scale_fixture_equals( 2, $config['pipeline_count'], 'normalizes pipeline count', $failures, $passes );
assert_admin_scale_fixture_equals( 3, $config['flows_per_pipeline'], 'normalizes flows per pipeline', $failures, $passes );
assert_admin_scale_fixture_equals( 4, $config['steps_per_flow'], 'normalizes steps per flow', $failures, $passes );
assert_admin_scale_fixture_equals( 512, $config['payload_size'], 'normalizes payload size', $failures, $passes );

$rejected = false;
try {
	AdminScaleFixture::normalize_config( array( 'payload_size' => 1048577 ) );
} catch ( InvalidArgumentException $exception ) {
	$rejected = str_contains( $exception->getMessage(), 'payload_size' );
}

assert_admin_scale_fixture_equals( true, $rejected, 'rejects unbounded payload size', $failures, $passes );

$source = file_get_contents( __DIR__ . '/../inc/Cli/Commands/FixturesCommand.php' ) ?: '';
assert_admin_scale_fixture_equals( true, str_contains( $source, 'fixtures admin-scale setup' ), 'documents setup command', $failures, $passes );
assert_admin_scale_fixture_equals( true, str_contains( $source, 'fixtures admin-scale cleanup' ), 'documents cleanup command', $failures, $passes );

if ( ! empty( $failures ) ) {
	echo "\nFAILED: " . count( $failures ) . " admin scale fixture assertions failed.\n";
	foreach ( $failures as $failure ) {
		echo ' - ' . $failure . "\n";
	}
	exit( 1 );
}

echo "\nAll {$passes} admin scale fixture assertions passed.\n";
