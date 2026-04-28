<?php
/**
 * Pure-PHP smoke test for AI required handler completion semantics.
 *
 * Run with: php tests/ai-required-handlers-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function do_action( string $hook, ...$args ): void {
	$GLOBALS['__ai_required_handlers_actions'][] = array( $hook, $args );
}

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

function assert_contains( string $needle, string $haystack, string $name, array &$failures, int &$passes ): void {
	assert_equals( true, str_contains( $haystack, $needle ), $name, $failures, $passes );
}

function assert_not_contains( string $needle, string $haystack, string $name, array &$failures, int &$passes ): void {
	assert_equals( false, str_contains( $haystack, $needle ), $name, $failures, $passes );
}

echo "AI required handlers smoke\n";
echo "--------------------------\n";

$publish_step = array(
	'step_type'     => 'publish',
	'handler_slugs' => array( 'wordpress_publish', 'email_publish' ),
);
assert_equals(
	array( 'wordpress_publish', 'email_publish' ),
	FlowStepConfig::getRequiredHandlerSlugsForAi( $publish_step ),
	'publish requires all configured handlers',
	$failures,
	$passes
);

$upsert_step = array(
	'step_type'              => 'upsert',
	'handler_slugs'          => array( 'wordpress_update', 'notion_update' ),
	'required_handler_slugs' => array( 'notion_update' ),
);
assert_equals(
	array( 'notion_update' ),
	FlowStepConfig::getRequiredHandlerSlugsForAi( $upsert_step ),
	'upsert explicit required_handler_slugs wins',
	$failures,
	$passes
);

$upsert_step_without_required = array(
	'step_type'     => 'upsert',
	'handler_slugs' => array( 'wordpress_update', 'notion_update' ),
);
assert_equals(
	array( 'wordpress_update' ),
	FlowStepConfig::getRequiredHandlerSlugsForAi( $upsert_step_without_required ),
	'upsert without required_handler_slugs requires first configured handler',
	$failures,
	$passes
);

$fetch_step = array(
	'step_type'    => 'fetch',
	'handler_slug' => 'rss_fetch',
);
assert_equals(
	array(),
	FlowStepConfig::getRequiredHandlerSlugsForAi( $fetch_step ),
	'handler-free-for-AI completion step returns empty required list',
	$failures,
	$passes
);

$available_tools = array(
	'wordpress_update_tool' => array( 'handler' => 'wordpress_update' ),
	'notion_update_tool'    => array( 'handler' => 'notion_update' ),
);
$required_handler_slugs = FlowStepConfig::getAdjacentRequiredHandlerSlugsForAi( null, $upsert_step );
assert_equals(
	array( 'notion_update' ),
	$required_handler_slugs,
	'adjacent helper resolves required upsert handler slug',
	$failures,
	$passes
);
assert_equals(
	array( 'notion_update' ),
	FlowStepConfig::getAvailableRequiredHandlerSlugsForAi( $required_handler_slugs, $available_tools ),
	'AIStep-style payload tracks required handler slugs via tool metadata, not tool names',
	$failures,
	$passes
);
assert_equals(
	array(),
	FlowStepConfig::getMissingRequiredHandlerSlugsForAi( $required_handler_slugs, $available_tools ),
	'available required handler tools report no missing slugs',
	$failures,
	$passes
);
assert_equals(
	array( 'notion_update' ),
	FlowStepConfig::getMissingRequiredHandlerSlugsForAi( $required_handler_slugs, array() ),
	'missing required handler tools are reported instead of silently dropped',
	$failures,
	$passes
);

$ai_step_source = (string) file_get_contents( __DIR__ . '/../inc/Core/Steps/AI/AIStep.php' );
assert_contains(
	'FlowStepConfig::getAdjacentRequiredHandlerSlugsForAi( $previous_step_config, $next_step_config )',
	$ai_step_source,
	'AIStep resolves adjacent required handlers through shared FlowStepConfig helper',
	$failures,
	$passes
);
assert_contains(
	'FlowStepConfig::getMissingRequiredHandlerSlugsForAi( $required_handler_slugs, $available_tools )',
	$ai_step_source,
	'AIStep fails before model call when required handler tools are unavailable',
	$failures,
	$passes
);
assert_not_contains(
	'array_keys( $available_tools )',
	$ai_step_source,
	'AIStep no longer intersects required handlers against post-policy tool names',
	$failures,
	$passes
);
assert_not_contains(
	'FlowStepConfig::getConfiguredHandlerSlugs( $adj_step_config )',
	$ai_step_source,
	'AIStep no longer passes every adjacent configured handler to the AI loop',
	$failures,
	$passes
);

echo "\n--------------------------\n";
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
