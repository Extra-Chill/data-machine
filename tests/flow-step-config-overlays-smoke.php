<?php
/**
 * Pure-PHP smoke test for flow-step config overlay helpers (#1353).
 *
 * Run with: php tests/flow-step-config-overlays-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities\Flow {
	if ( ! class_exists( QueueAbility::class, false ) ) {
		class QueueAbility {
			const SLOT_PROMPT_QUEUE       = 'prompt_queue';
			const SLOT_CONFIG_PATCH_QUEUE = 'config_patch_queue';
		}
	}
}

namespace {

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
		// no-op for tests.
	}
}

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfigFactory.php';

use DataMachine\Core\Steps\FlowStepConfigFactory;

$failed = 0;
$total  = 0;

function assert_overlay( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	++$failed;
	echo "  [FAIL] {$name}" . ( '' !== $detail ? " - {$detail}" : '' ) . "\n";
}

function assert_overlay_same( string $name, $expected, $actual ): void {
	assert_overlay(
		$name,
		$expected === $actual,
		'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
	);
}

echo "=== flow-step-config-overlays-smoke ===\n";

$copy_base = FlowStepConfigFactory::build(
	array(
		'flow_step_id'     => 'publish_step_99',
		'step_type'        => 'publish',
		'pipeline_step_id' => 'publish_step',
		'pipeline_id'      => 7,
		'flow_id'          => 99,
		'execution_order'  => 2,
	)
);
$copy_source = array(
	'handler_slugs'   => array( 'wordpress', 'pinterest' ),
	'handler_configs' => array(
		'wordpress' => array( 'post_type' => 'post' ),
		'pinterest' => array( 'board' => 'events' ),
	),
	'prompt_queue'    => array(
		array( 'prompt' => 'Publish copy', 'added_at' => '2026-04-27T00:00:00Z' ),
	),
	'queue_mode'      => 'loop',
);
$copied = FlowStepConfigFactory::withQueueState(
	FlowStepConfigFactory::withHandlerFields( $copy_base, $copy_source ),
	$copy_source
);

assert_overlay_same( 'copy path keeps multi-handler slugs', $copy_source['handler_slugs'], $copied['handler_slugs'] );
assert_overlay_same( 'copy path keeps multi-handler configs', $copy_source['handler_configs'], $copied['handler_configs'] );
assert_overlay_same( 'copy path keeps prompt queue', $copy_source['prompt_queue'], $copied['prompt_queue'] );
assert_overlay_same( 'copy path keeps queue mode', 'loop', $copied['queue_mode'] );

$overridden = FlowStepConfigFactory::withHandlerConfig(
	$copied,
	'wordpress_xmlrpc',
	array( 'post_type' => 'page' )
);
assert_overlay_same( 'copy override replaces multi-handler list', array( 'wordpress_xmlrpc' ), $overridden['handler_slugs'] );
assert_overlay_same(
	'copy override stores config under replacement handler',
	array( 'wordpress_xmlrpc' => array( 'post_type' => 'page' ) ),
	$overridden['handler_configs']
);

$message_override = FlowStepConfigFactory::withUserMessage( $copied, 'Override prompt', '2026-04-27T01:00:00Z' );
assert_overlay_same(
	'copy user_message override becomes one-entry prompt queue',
	array( array( 'prompt' => 'Override prompt', 'added_at' => '2026-04-27T01:00:00Z' ) ),
	$message_override['prompt_queue']
);
assert_overlay_same( 'copy user_message override forces static queue mode', 'static', $message_override['queue_mode'] );

$import_base = FlowStepConfigFactory::build(
	array(
		'flow_step_id'     => 'fetch_step_42',
		'pipeline_step_id' => 'fetch_step',
		'flow_id'          => 42,
		'step_type'        => 'fetch',
	)
);
$import_restored = FlowStepConfigFactory::withHandlerFields(
	$import_base,
	array(
		'handler_slug'   => 'mcp',
		'handler_config' => array( 'server' => 'a8c', 'provider' => 'slack' ),
	)
);

assert_overlay_same(
	'import restore path preserves field order and scalar handler shape',
	array(
		'flow_step_id'     => 'fetch_step_42',
		'pipeline_step_id' => 'fetch_step',
		'flow_id'          => 42,
		'step_type'        => 'fetch',
		'handler_slug'     => 'mcp',
		'handler_config'   => array( 'server' => 'a8c', 'provider' => 'slack' ),
	),
	$import_restored
);

$import_handler_free = FlowStepConfigFactory::withHandlerFields(
	FlowStepConfigFactory::build(
		array(
			'flow_step_id'     => 'system_task_42',
			'pipeline_step_id' => 'system_task',
			'flow_id'          => 42,
			'step_type'        => 'system_task',
		)
	),
	array( 'handler_config' => array( 'task' => 'daily_memory_generation' ) )
);
assert_overlay_same(
	'import restore path preserves handler-free settings config',
	array( 'task' => 'daily_memory_generation' ),
	$import_handler_free['handler_config']
);

$flow_helpers_source = file_get_contents( __DIR__ . '/../inc/Abilities/Flow/FlowHelpers.php' );
$import_export_source = file_get_contents( __DIR__ . '/../inc/Engine/Actions/ImportExport.php' );

assert_overlay( 'copy path calls withHandlerFields', false !== strpos( $flow_helpers_source, 'FlowStepConfigFactory::withHandlerFields( $new_step_config, $source_step )' ) );
assert_overlay( 'copy path calls withQueueState', false !== strpos( $flow_helpers_source, 'FlowStepConfigFactory::withQueueState( $new_step_config, $source_step )' ) );
assert_overlay( 'import restore path calls withHandlerFields', false !== strpos( $import_export_source, 'FlowStepConfigFactory::withHandlerFields( $flow_config[ $flow_step_id ], $settings )' ) );
assert_overlay( 'import restore path no longer hand-normalizes array_merge', false === strpos( $import_export_source, 'FlowStepConfig::normalizeHandlerShape(\n\t\t\tarray_merge' ) );

echo "\n";
if ( 0 === $failed ) {
	echo "=== flow-step-config-overlays-smoke: ALL PASS ({$total}) ===\n";
	exit( 0 );
}

echo "=== flow-step-config-overlays-smoke: {$failed} FAIL of {$total} ===\n";
exit( 1 );
}
