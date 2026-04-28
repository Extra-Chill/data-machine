<?php
/**
 * Pure-PHP smoke test for FlowStepConfigFactory extraction.
 *
 * Run with: php tests/flow-step-config-factory-smoke.php
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

use DataMachine\Core\Steps\FlowStepConfig;
use DataMachine\Core\Steps\FlowStepConfigFactory;

$failures = array();
$passes   = 0;

function assert_factory_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

function legacy_workflow_step_config_for_test( array $step, int $index ): array {
	$step_id          = "ephemeral_step_{$index}";
	$pipeline_step_id = "ephemeral_pipeline_{$index}";
	$handler_slug     = $step['handler_slug'] ?? '';
	$handler_config   = $step['handler_config'] ?? array();
	$step_type        = $step['type'];
	$prompt_queue     = array();

	$workflow_user_message = is_string( $step['user_message'] ?? null )
		? trim( $step['user_message'] )
		: '';
	if ( 'ai' === $step_type && '' !== $workflow_user_message ) {
		$prompt_queue = array(
			array(
				'prompt'   => $workflow_user_message,
				'added_at' => '__dynamic__',
			),
		);
	}

	$flow_step_config = array(
		'flow_step_id'     => $step_id,
		'pipeline_step_id' => $pipeline_step_id,
		'step_type'        => $step_type,
		'execution_order'  => $index,
		'enabled_tools'    => ( 'ai' === $step_type && ! empty( $step['enabled_tools'] ) && is_array( $step['enabled_tools'] ) )
			? array_values( $step['enabled_tools'] )
			: array(),
		'prompt_queue'     => $prompt_queue,
		'queue_mode'       => 'static',
		'disabled_tools'   => $step['disabled_tools'] ?? array(),
		'pipeline_id'      => 'direct',
		'flow_id'          => 'direct',
	);

	if ( ! empty( $handler_slug ) ) {
		if ( FlowStepConfig::isMultiHandler( $flow_step_config ) ) {
			$flow_step_config['handler_slugs']   = array( $handler_slug );
			$flow_step_config['handler_configs'] = array( $handler_slug => $handler_config );
		} else {
			$flow_step_config['handler_slug']   = $handler_slug;
			$flow_step_config['handler_config'] = $handler_config;
		}
	} elseif ( ! FlowStepConfig::usesHandler( $flow_step_config ) && ! empty( $handler_config ) ) {
		$flow_step_config['handler_config'] = $handler_config;
	}

	return $flow_step_config;
}

function factory_workflow_step_config_for_test( array $step, int $index ): array {
	$config = FlowStepConfigFactory::buildFromWorkflowStep( $step, $index );
	if ( isset( $config['prompt_queue'][0]['added_at'] ) ) {
		$config['prompt_queue'][0]['added_at'] = '__dynamic__';
	}
	return $config;
}

function legacy_synced_step_config_for_test( array $step, int $flow_id, int $pipeline_id, array $disabled_tools ): array {
	$step_type        = $step['step_type'] ?? '';
	$pipeline_step_id = $step['pipeline_step_id'];
	$flow_step_id     = $pipeline_step_id . '_' . $flow_id;

	$step_config = array(
		'flow_step_id'     => $flow_step_id,
		'step_type'        => $step_type,
		'pipeline_step_id' => $pipeline_step_id,
		'pipeline_id'      => $pipeline_id,
		'flow_id'          => $flow_id,
		'execution_order'  => $step['execution_order'] ?? 0,
		'disabled_tools'   => $disabled_tools,
		'queue_mode'       => 'static',
	);

	if ( 'fetch' === $step_type ) {
		$step_config['config_patch_queue'] = array();
	} else {
		$step_config['prompt_queue'] = array();
	}

	return $step_config;
}

function factory_synced_step_config_for_test( array $step, int $flow_id, int $pipeline_id, array $disabled_tools ): array {
	$pipeline_step_id = $step['pipeline_step_id'];

	return FlowStepConfigFactory::buildFromPipelineStep(
		$step,
		$pipeline_id,
		$flow_id,
		$pipeline_step_id . '_' . $flow_id,
		array( 'disabled_tools' => $disabled_tools )
	);
}

echo "FlowStepConfigFactory smoke (#1345)\n";
echo "-----------------------------------\n";

$workflow_cases = array(
	'fetch keeps scalar handler shape'        => array(
		'type'           => 'fetch',
		'handler_slug'   => 'mcp',
		'handler_config' => array( 'server' => 'a8c' ),
		'disabled_tools' => array( 'local_search' ),
	),
	'publish keeps multi-handler shape'       => array(
		'type'           => 'publish',
		'handler_slug'   => 'wordpress_publish',
		'handler_config' => array( 'post_type' => 'post' ),
	),
	'system_task keeps handler-free settings' => array(
		'type'           => 'system_task',
		'handler_config' => array( 'task' => 'daily_memory_generation' ),
	),
	'ai converts user message to prompt queue' => array(
		'type'          => 'ai',
		'enabled_tools' => array( 'local_search' ),
		'user_message'  => ' Summarize ',
	),
);

foreach ( $workflow_cases as $name => $step ) {
	assert_factory_equals(
		legacy_workflow_step_config_for_test( $step, 2 ),
		factory_workflow_step_config_for_test( $step, 2 ),
		"workflow scaffold: {$name}",
		$failures,
		$passes
	);
}

$sync_cases = array(
	'fetch gets config_patch_queue default' => array(
		'pipeline_step_id' => 'fetch_1',
		'step_type'        => 'fetch',
		'execution_order'  => 0,
	),
	'ai gets prompt_queue default'          => array(
		'pipeline_step_id' => 'ai_1',
		'step_type'        => 'ai',
		'execution_order'  => 1,
	),
	'publish gets prompt_queue default'     => array(
		'pipeline_step_id' => 'publish_1',
		'step_type'        => 'publish',
		'execution_order'  => 2,
	),
);

foreach ( $sync_cases as $name => $step ) {
	assert_factory_equals(
		legacy_synced_step_config_for_test( $step, 42, 7, array( 'tool_a' ) ),
		factory_synced_step_config_for_test( $step, 42, 7, array( 'tool_a' ) ),
		"flow sync scaffold: {$name}",
		$failures,
		$passes
	);
}

$execute_workflow_src = file_get_contents( __DIR__ . '/../inc/Abilities/Job/ExecuteWorkflowAbility.php' ) ?: '';
$flow_helpers_src     = file_get_contents( __DIR__ . '/../inc/Abilities/Flow/FlowHelpers.php' ) ?: '';

assert_factory_equals(
	true,
	false !== strpos( $execute_workflow_src, 'WorkflowConfigFactory::buildEphemeralConfigs( $workflow )' ),
	'ExecuteWorkflowAbility routes workflow rows through the shared workflow factory',
	$failures,
	$passes
);

assert_factory_equals(
	true,
	false !== strpos( $flow_helpers_src, 'FlowStepConfigFactory::buildFromPipelineStep(' ),
	'FlowHelpers routes synced flow rows through the factory scaffold',
	$failures,
	$passes
);

echo "\n-----------------------------------\n";
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
}
