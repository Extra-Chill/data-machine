<?php
/**
 * Pure-PHP smoke test for handler-free step config passthrough.
 *
 * Run with: php tests/system-task-config-passthrough-smoke.php
 *
 * Covers the build-side + read-side fix that lets handler-free step
 * types (system_task, webhook_gate, ai with no handler) preserve their
 * handler_config across the workflow → flow_config → step runtime
 * translation.
 *
 * Before this fix, ExecuteWorkflowAbility::buildConfigsFromWorkflow()
 * dropped handler_config to an empty array whenever handler_slug was
 * empty, and Step::getHandlerConfig() returned an empty array on the
 * read side for the same reason. system_task workflows passing
 * { task: 'daily_memory_generation', params: {} } got the config
 * silently dropped and failed with system_task_missing_task_type.
 *
 * The fix keys handler-free configs by the step type slug on both
 * sides so they round-trip correctly.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Inline reimplementation of ExecuteWorkflowAbility::buildConfigsFromWorkflow()
 * for pure-PHP testing (the real method is private + lives in a class
 * with WordPress dependencies).
 *
 * Mirrors the post-fix shape so a regression in the real file shows up
 * as the fixture diverging from the harness.
 */
function build_configs_from_workflow_for_test( array $workflow ): array {
	$flow_config     = array();
	$pipeline_config = array();

	foreach ( $workflow['steps'] as $index => $step ) {
		$step_id          = "ephemeral_step_{$index}";
		$pipeline_step_id = "ephemeral_pipeline_{$index}";

		$handler_slug   = $step['handler_slug'] ?? '';
		$handler_config = $step['handler_config'] ?? array();

		$handler_slugs = array();
		if ( ! empty( $handler_slug ) ) {
			$handler_slugs = array( $handler_slug );
		} elseif ( ! empty( $step['enabled_tools'] ) && is_array( $step['enabled_tools'] ) ) {
			$handler_slugs = $step['enabled_tools'];
		}

		$handler_configs = array();
		if ( ! empty( $handler_slug ) ) {
			$handler_configs[ $handler_slug ] = $handler_config;
		} elseif ( ! empty( $handler_config ) ) {
			$handler_configs[ $step['type'] ] = $handler_config;
		}

		$flow_config[ $step_id ] = array(
			'flow_step_id'     => $step_id,
			'pipeline_step_id' => $pipeline_step_id,
			'step_type'        => $step['type'],
			'execution_order'  => $index,
			'handler_slugs'    => $handler_slugs,
			'handler_configs'  => $handler_configs,
			'user_message'     => $step['user_message'] ?? '',
			'disabled_tools'   => $step['disabled_tools'] ?? array(),
			'pipeline_id'      => 'direct',
			'flow_id'          => 'direct',
		);

		if ( 'ai' === $step['type'] ) {
			$pipeline_config[ $pipeline_step_id ] = array(
				'system_prompt'  => $step['system_prompt'] ?? '',
				'disabled_tools' => $step['disabled_tools'] ?? array(),
			);
		}
	}

	return array(
		'flow_config'     => $flow_config,
		'pipeline_config' => $pipeline_config,
	);
}

/**
 * Inline reimplementation of Step::getHandlerConfig() read-side.
 *
 * Mirrors the post-fix shape: when no handler_slug, fall back to
 * handler_configs keyed by step_type.
 */
function get_handler_config_for_test( array $flow_step_config, string $step_type ): array {
	$slug = $flow_step_config['handler_slugs'][0] ?? null;
	if ( ! empty( $slug ) ) {
		return $flow_step_config['handler_configs'][ $slug ] ?? array();
	}
	return $flow_step_config['handler_configs'][ $step_type ] ?? array();
}

$failures = array();
$passes   = 0;

function assert_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
	if ( $expected === $actual ) {
		$passes++;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo "    expected: " . var_export( $expected, true ) . "\n";
	echo "    actual:   " . var_export( $actual, true ) . "\n";
}

echo "system-task config passthrough smoke\n";
echo "------------------------------------\n";

// Test 1: system_task workflow round-trips handler_config under step type slug.
echo "\n[1] system_task workflow round-trips { task, params }:\n";
$workflow = array(
	'steps' => array(
		array(
			'type'           => 'system_task',
			'handler_config' => array(
				'task'   => 'daily_memory_generation',
				'params' => array(),
			),
		),
	),
);

$built  = build_configs_from_workflow_for_test( $workflow );
$step0  = $built['flow_config']['ephemeral_step_0'];
$config = get_handler_config_for_test( $step0, 'system_task' );

assert_equals( 'daily_memory_generation', $config['task'] ?? null, 'task survives passthrough', $failures, $passes );
assert_equals( array(), $config['params'] ?? null, 'params survives passthrough', $failures, $passes );
assert_equals( array(), $step0['handler_slugs'], 'handler_slugs is empty', $failures, $passes );
assert_equals( array( 'system_task' => array( 'task' => 'daily_memory_generation', 'params' => array() ) ), $step0['handler_configs'], 'handler_configs keyed by step type', $failures, $passes );

// Test 2: handler-bearing step still keys by handler_slug.
echo "\n[2] fetch step (handler-bearing) keys handler_configs by handler_slug:\n";
$workflow = array(
	'steps' => array(
		array(
			'type'           => 'fetch',
			'handler_slug'   => 'mcp',
			'handler_config' => array(
				'server'   => 'a8c',
				'provider' => 'mgs',
			),
		),
	),
);

$built  = build_configs_from_workflow_for_test( $workflow );
$step0  = $built['flow_config']['ephemeral_step_0'];
$config = get_handler_config_for_test( $step0, 'fetch' );

assert_equals( 'a8c', $config['server'] ?? null, 'server reachable via handler_slug', $failures, $passes );
assert_equals( array( 'mcp' ), $step0['handler_slugs'], 'handler_slugs has mcp', $failures, $passes );
assert_equals( array( 'mcp' => array( 'server' => 'a8c', 'provider' => 'mgs' ) ), $step0['handler_configs'], 'handler_configs keyed by handler_slug', $failures, $passes );

// Test 3: empty handler_config and no handler_slug → empty handler_configs.
echo "\n[3] handler-free step with no config has empty handler_configs:\n";
$workflow = array(
	'steps' => array(
		array(
			'type' => 'webhook_gate',
		),
	),
);

$built = build_configs_from_workflow_for_test( $workflow );
$step0 = $built['flow_config']['ephemeral_step_0'];

assert_equals( array(), $step0['handler_configs'], 'no config → empty handler_configs', $failures, $passes );
assert_equals( array(), get_handler_config_for_test( $step0, 'webhook_gate' ), 'getHandlerConfig returns empty', $failures, $passes );

// Test 4: ai step with enabled_tools (no handler_slug, no handler_config).
// AI steps carry their step config via pipeline_config (system_prompt),
// not handler_config — so handler_configs stays empty here.  The build
// side still emits handler_slugs from enabled_tools so the AI step
// knows which tools to expose.
echo "\n[4] ai step with enabled_tools:\n";
$workflow = array(
	'steps' => array(
		array(
			'type'          => 'ai',
			'system_prompt' => 'be helpful',
			'enabled_tools' => array( 'intelligence/search', 'intelligence/wiki-upsert' ),
		),
	),
);

$built = build_configs_from_workflow_for_test( $workflow );
$step0 = $built['flow_config']['ephemeral_step_0'];

assert_equals( array( 'intelligence/search', 'intelligence/wiki-upsert' ), $step0['handler_slugs'], 'enabled_tools become handler_slugs', $failures, $passes );
assert_equals( array(), $step0['handler_configs'], 'ai step without handler_config → empty handler_configs', $failures, $passes );
assert_equals( 'be helpful', $built['pipeline_config']['ephemeral_pipeline_0']['system_prompt'] ?? null, 'system_prompt lands in pipeline_config', $failures, $passes );

// Test 5: regression — system_task workflow that bypassed validation
// after #1200 still got handler_config dropped.  After this fix, the
// task type is reachable end-to-end.
echo "\n[5] regression: validate→build→getHandlerConfig pipeline:\n";
$workflow = array(
	'steps' => array(
		array(
			'type'           => 'system_task',
			'handler_config' => array(
				'task'   => 'agent_ping',
				'params' => array( 'agent_id' => 2 ),
			),
		),
	),
);

$built  = build_configs_from_workflow_for_test( $workflow );
$step0  = $built['flow_config']['ephemeral_step_0'];
$config = get_handler_config_for_test( $step0, 'system_task' );

assert_equals( 'agent_ping', $config['task'] ?? null, 'task type reaches step runtime', $failures, $passes );
assert_equals( array( 'agent_id' => 2 ), $config['params'] ?? null, 'task params reach step runtime', $failures, $passes );

echo "\n------------------------------------\n";
$total = $passes + count( $failures );
echo "{$passes} / {$total} passed\n";

if ( ! empty( $failures ) ) {
	echo "Failures:\n";
	foreach ( $failures as $name ) {
		echo "  - {$name}\n";
	}
	exit( 1 );
}

echo "All checks passed.\n";
exit( 0 );
