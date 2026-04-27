<?php
/**
 * Pure-PHP smoke test for removing legacy flow-step `handler` storage.
 *
 * Run with: php tests/flow-step-legacy-handler-field-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		if ( 'datamachine_step_types' !== $hook ) {
			return $value;
		}

		return array(
			'ai'           => array( 'uses_handler' => false, 'multi_handler' => false ),
			'system_task'  => array( 'uses_handler' => false, 'multi_handler' => false ),
			'webhook_gate' => array( 'uses_handler' => false, 'multi_handler' => false ),
			'fetch'        => array( 'uses_handler' => true, 'multi_handler' => false ),
			'publish'      => array( 'uses_handler' => true, 'multi_handler' => true ),
			'upsert'       => array( 'uses_handler' => true, 'multi_handler' => true ),
		);
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['__flow_step_legacy_handler_field_actions'][] = array( $hook, $args );
	}
}

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';

use DataMachine\Core\Steps\FlowStepConfig;

$failures = array();
$passes   = 0;

function assert_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

function assert_absent( string $key, array $array, string $name, array &$failures, int &$passes ): void {
	assert_equals( false, array_key_exists( $key, $array ), $name, $failures, $passes );
}

function assert_not_contains( string $needle, string $haystack, string $name, array &$failures, int &$passes ): void {
	assert_equals( false, str_contains( $haystack, $needle ), $name, $failures, $passes );
}

echo "legacy handler field removal smoke (#1348)\n";
echo "-------------------------------------------\n";

echo "\n[1] new synced step seeds structural fields only:\n";
$synced_step = array(
	'flow_step_id'       => 'step_10',
	'step_type'          => 'fetch',
	'pipeline_step_id'   => 'step',
	'pipeline_id'        => 1,
	'flow_id'            => 10,
	'execution_order'    => 0,
	'disabled_tools'     => array(),
	'config_patch_queue' => array(),
	'queue_mode'         => 'static',
);
assert_absent( 'handler', $synced_step, 'synced step has no legacy handler field', $failures, $passes );

echo "\n[2] configured single-handler step stores scalar canonical fields:\n";
$single = FlowStepConfig::normalizeHandlerShape(
	array_merge(
		$synced_step,
		array(
			'handler'        => 'rss',
			'handler_slug'   => 'rss',
			'handler_config' => array( 'url' => 'https://example.com/feed.xml' ),
		)
	)
);
assert_equals( 'rss', $single['handler_slug'] ?? null, 'single-handler slug is canonical scalar', $failures, $passes );
assert_equals( array( 'url' => 'https://example.com/feed.xml' ), $single['handler_config'] ?? array(), 'single-handler config is canonical scalar', $failures, $passes );
assert_absent( 'handler', $single, 'single-handler step drops legacy handler field', $failures, $passes );
assert_absent( 'handler_slugs', $single, 'single-handler step has no plural slugs', $failures, $passes );
assert_absent( 'handler_configs', $single, 'single-handler step has no plural configs', $failures, $passes );

echo "\n[3] configured multi-handler step stores plural canonical fields:\n";
$multi = FlowStepConfig::normalizeHandlerShape(
	array(
		'flow_step_id'     => 'publish_10',
		'step_type'        => 'publish',
		'pipeline_step_id' => 'publish',
		'pipeline_id'      => 1,
		'flow_id'          => 10,
		'handler'          => 'wordpress_publish',
		'handler_slug'     => 'wordpress_publish',
		'handler_config'   => array( 'post_type' => 'post' ),
	)
);
assert_equals( array( 'wordpress_publish' ), $multi['handler_slugs'] ?? array(), 'multi-handler slug is canonical list', $failures, $passes );
assert_equals( array( 'wordpress_publish' => array( 'post_type' => 'post' ) ), $multi['handler_configs'] ?? array(), 'multi-handler config is canonical map', $failures, $passes );
assert_absent( 'handler', $multi, 'multi-handler step drops legacy handler field', $failures, $passes );
assert_absent( 'handler_slug', $multi, 'multi-handler step has no scalar slug', $failures, $passes );
assert_absent( 'handler_config', $multi, 'multi-handler step has no scalar config', $failures, $passes );

echo "\n[4] restored step normalizes imported stale handler residue away:\n";
$restored = FlowStepConfig::normalizeHandlerShape(
	array(
		'flow_step_id'     => 'restored_10',
		'step_type'        => 'fetch',
		'pipeline_step_id' => 'restored',
		'flow_id'          => 10,
		'handler'          => 'legacy_rss',
		'handler_slugs'    => array( 'rss' ),
		'handler_configs'  => array( 'rss' => array( 'url' => 'https://example.com/feed.xml' ) ),
	)
);
assert_equals( 'rss', $restored['handler_slug'] ?? null, 'restored single-handler slug comes from canonical import settings', $failures, $passes );
assert_absent( 'handler', $restored, 'restored step drops stale imported handler field', $failures, $passes );

echo "\n[5] writer sources no longer seed or persist the legacy field:\n";
$source_checks = array(
	'inc/Abilities/Flow/FlowHelpers.php'         => array( "'handler'          => null" ),
	'inc/Abilities/FlowStep/FlowStepHelpers.php' => array( "'handler'          => null" ),
	'inc/Abilities/PipelineStepAbilities.php'    => array( "'handler'          => null" ),
	'inc/Engine/Actions/ImportExport.php'        => array( "\$step['handler'] =" ),
);
foreach ( $source_checks as $relative_path => $needles ) {
	$source = file_get_contents( __DIR__ . '/../' . $relative_path );
	foreach ( $needles as $needle ) {
		assert_not_contains( $needle, $source, "{$relative_path} does not contain {$needle}", $failures, $passes );
	}
}

echo "\n-------------------------------------------\n";
$total = $passes + count( $failures );
echo "{$passes} / {$total} passed\n";

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "\nAll assertions passed.\n";
