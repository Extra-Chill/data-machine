<?php
/**
 * Pure-PHP smoke test for handler-free step config passthrough.
 *
 * Run with: php tests/system-task-config-passthrough-smoke.php
 *
 * Covers the build-side + read-side fix that lets handler-free step
 * types (system_task, webhook_gate, agent_ping) preserve their
 * handler_config across the workflow → flow_config → step runtime
 * translation.
 *
 * Phase 1 fix (#1202): keyed handler-free configs by step_type and
 * added a fallback in Step::getHandlerConfig() so SystemTaskStep could
 * find its { task, params }.
 *
 * Phase 2a fix (#1205): collapsed onto FlowStepConfig::getPrimary
 * HandlerConfig() by writing handler_slugs = [step_type] for handler-
 * free steps with a non-empty handler_config (mirrors the v0.60.0
 * migration in inc/migrations/handler-keys.php). This drops the read-
 * side fallback ladder in Step::getHandlerConfig() — the helper now
 * resolves uniformly via handler_slugs[0].
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
 * Mirrors the post-#1205 shape so a regression in the real file shows
 * up as the fixture diverging from the harness.
 */
function build_configs_from_workflow_for_test( array $workflow ): array {
	$flow_config     = array();
	$pipeline_config = array();

	foreach ( $workflow['steps'] as $index => $step ) {
		$step_id          = "ephemeral_step_{$index}";
		$pipeline_step_id = "ephemeral_pipeline_{$index}";

		$handler_slug   = $step['handler_slug'] ?? '';
		$handler_config = $step['handler_config'] ?? array();
		$step_type      = $step['type'];

		$handler_slugs = array();
		if ( ! empty( $handler_slug ) ) {
			$handler_slugs = array( $handler_slug );
		} elseif ( 'ai' !== $step_type && ! empty( $handler_config ) ) {
			$handler_slugs = array( $step_type );
		}

		$handler_configs = array();
		if ( ! empty( $handler_slug ) ) {
			$handler_configs[ $handler_slug ] = $handler_config;
		} elseif ( 'ai' !== $step_type && ! empty( $handler_config ) ) {
			$handler_configs[ $step_type ] = $handler_config;
		}

		$enabled_tools = ( 'ai' === $step_type && ! empty( $step['enabled_tools'] ) && is_array( $step['enabled_tools'] ) )
			? array_values( $step['enabled_tools'] )
			: array();

		$flow_config[ $step_id ] = array(
			'flow_step_id'     => $step_id,
			'pipeline_step_id' => $pipeline_step_id,
			'step_type'        => $step_type,
			'execution_order'  => $index,
			'handler_slugs'    => $handler_slugs,
			'handler_configs'  => $handler_configs,
			'enabled_tools'    => $enabled_tools,
			'user_message'     => $step['user_message'] ?? '',
			'disabled_tools'   => $step['disabled_tools'] ?? array(),
			'pipeline_id'      => 'direct',
			'flow_id'          => 'direct',
		);

		if ( 'ai' === $step_type ) {
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
 * Inline reimplementation of FlowStepConfig::getPrimaryHandlerConfig().
 *
 * Reads handler_configs[handler_slugs[0]] uniformly. The fallback ladder
 * Step::getHandlerConfig() carried in #1202 is gone — the build side
 * now writes handler_slugs = [step_type] for handler-free steps with a
 * non-empty handler_config so this single lookup resolves both shapes.
 */
function get_primary_handler_config_for_test( array $flow_step_config ): array {
	$slug = $flow_step_config['handler_slugs'][0] ?? '';
	if ( ! empty( $slug ) && ! empty( $flow_step_config['handler_configs'][ $slug ] ) ) {
		return $flow_step_config['handler_configs'][ $slug ];
	}
	return array();
}

/**
 * Inline reimplementation of FlowStepConfig::getEffectiveSlug().
 */
function get_effective_slug_for_test( array $flow_step_config, string $explicit_slug = '' ): string {
	if ( ! empty( $explicit_slug ) ) {
		return $explicit_slug;
	}
	$primary = $flow_step_config['handler_slugs'][0] ?? '';
	if ( ! empty( $primary ) ) {
		return $primary;
	}
	return $flow_step_config['step_type'] ?? '';
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

echo "system-task config passthrough smoke (Phase 2a + 2b)\n";
echo "-----------------------------------------------------\n";

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
$config = get_primary_handler_config_for_test( $step0 );

assert_equals( 'daily_memory_generation', $config['task'] ?? null, 'task survives passthrough', $failures, $passes );
assert_equals( array(), $config['params'] ?? null, 'params survives passthrough', $failures, $passes );
assert_equals( array( 'system_task' ), $step0['handler_slugs'], 'handler_slugs synthesized from step_type', $failures, $passes );
assert_equals( array( 'system_task' => array( 'task' => 'daily_memory_generation', 'params' => array() ) ), $step0['handler_configs'], 'handler_configs keyed by step type', $failures, $passes );
assert_equals( 'system_task', get_effective_slug_for_test( $step0 ), 'getEffectiveSlug returns step_type', $failures, $passes );

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
$config = get_primary_handler_config_for_test( $step0 );

assert_equals( 'a8c', $config['server'] ?? null, 'server reachable via handler_slug', $failures, $passes );
assert_equals( array( 'mcp' ), $step0['handler_slugs'], 'handler_slugs has mcp', $failures, $passes );
assert_equals( array( 'mcp' => array( 'server' => 'a8c', 'provider' => 'mgs' ) ), $step0['handler_configs'], 'handler_configs keyed by handler_slug', $failures, $passes );
assert_equals( 'mcp', get_effective_slug_for_test( $step0 ), 'getEffectiveSlug returns handler_slug', $failures, $passes );

// Test 3: empty handler_config and no handler_slug → empty handler_configs and empty handler_slugs.
// Mirrors inc/migrations/handler-keys.php: when there is nothing to key,
// both arrays stay empty rather than synthesizing a slug-with-no-config row.
echo "\n[3] handler-free step with no config has empty handler_configs and handler_slugs:\n";
$workflow = array(
	'steps' => array(
		array(
			'type' => 'webhook_gate',
		),
	),
);

$built = build_configs_from_workflow_for_test( $workflow );
$step0 = $built['flow_config']['ephemeral_step_0'];

assert_equals( array(), $step0['handler_slugs'], 'no config → empty handler_slugs', $failures, $passes );
assert_equals( array(), $step0['handler_configs'], 'no config → empty handler_configs', $failures, $passes );
assert_equals( array(), get_primary_handler_config_for_test( $step0 ), 'getPrimaryHandlerConfig returns empty', $failures, $passes );
assert_equals( 'webhook_gate', get_effective_slug_for_test( $step0 ), 'getEffectiveSlug falls back to step_type', $failures, $passes );

// Test 4: ai step with enabled_tools.
// Phase 2b: tools now land in `enabled_tools` and `handler_slugs` stays empty
// for AI steps. The field overload (enabled_tools as handler_slugs) is gone.
echo "\n[4] ai step with enabled_tools (Phase 2b shape):\n";
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

assert_equals( array( 'intelligence/search', 'intelligence/wiki-upsert' ), $step0['enabled_tools'], 'enabled_tools land in dedicated field', $failures, $passes );
assert_equals( array(), $step0['handler_slugs'], 'ai handler_slugs stays empty (single-purpose now)', $failures, $passes );
assert_equals( array(), $step0['handler_configs'], 'ai step without handler_config → empty handler_configs', $failures, $passes );
assert_equals( 'be helpful', $built['pipeline_config']['ephemeral_pipeline_0']['system_prompt'] ?? null, 'system_prompt lands in pipeline_config', $failures, $passes );

// Test 5: ai step with no enabled_tools, no handler — both arrays empty, including the new field.
echo "\n[5] ai step with no enabled_tools and no handler_config:\n";
$workflow = array(
	'steps' => array(
		array(
			'type'          => 'ai',
			'system_prompt' => 'be helpful',
		),
	),
);

$built = build_configs_from_workflow_for_test( $workflow );
$step0 = $built['flow_config']['ephemeral_step_0'];

assert_equals( array(), $step0['handler_slugs'], 'ai handler_slugs stays empty', $failures, $passes );
assert_equals( array(), $step0['enabled_tools'], 'ai enabled_tools empty when none provided', $failures, $passes );
assert_equals( array(), $step0['handler_configs'], 'ai with no config → empty handler_configs', $failures, $passes );

// Test 6: regression — system_task workflow that bypassed validation
// after #1200 still got handler_config dropped before #1202. After
// Phase 2a, the task type is reachable end-to-end via the helper.
echo "\n[6] regression: validate→build→getPrimaryHandlerConfig pipeline:\n";
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
$config = get_primary_handler_config_for_test( $step0 );

assert_equals( 'agent_ping', $config['task'] ?? null, 'task type reaches step runtime', $failures, $passes );
assert_equals( array( 'agent_id' => 2 ), $config['params'] ?? null, 'task params reach step runtime', $failures, $passes );

echo "\n-----------------------------------------------------\n";
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
