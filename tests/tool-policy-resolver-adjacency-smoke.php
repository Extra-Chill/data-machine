<?php
/**
 * Pure-PHP smoke test for ToolPolicyResolver adjacency with handler-free steps.
 *
 * Run with: php tests/tool-policy-resolver-adjacency-smoke.php
 *
 * After Phase 2a (#1205), ExecuteWorkflowAbility::buildConfigsFromWorkflow()
 * writes handler_slugs = [step_type] for handler-free step types
 * (system_task, webhook_gate, agent_ping) with a non-empty handler_config,
 * mirroring inc/migrations/handler-keys.php (v0.60.0).
 *
 * ToolPolicyResolver::gatherPipelineTools() iterates handler_slugs from
 * adjacent steps to surface their handler tools to the AI. The
 * synthetic-slug shape means an adjacent system_task step will have
 * handler_slugs = ['system_task'] — a slug that does NOT correspond to a
 * registered handler tool. The resolver MUST handle that gracefully:
 * when ToolManager::resolveHandlerTools('system_task', …) returns no
 * tools, the resolver moves on without injecting a synthetic 'system_task'
 * pseudo-tool into the AI's tool list.
 *
 * This regression guard verifies the iteration shape stays correct: the
 * resolver visits each slug, asks the tool manager for tools, and only
 * adds tools the manager actually returns. A handler-free synthetic slug
 * yielding an empty result must not pollute the available_tools map.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Stub ToolManager that records the slugs the resolver asked for and
 * returns canned tools per slug. A real handler slug
 * ('wordpress_publish') gets back a real tool; a synthetic slug
 * ('system_task') gets back an empty array, simulating what the unified
 * datamachine_tools registry actually does for unknown handler slugs.
 */
class StubToolManager {
	public array $resolveCalls = array();

	public function resolveHandlerTools( string $slug, array $config, array $engine_data, string $cache_scope ): array {
		$this->resolveCalls[] = $slug;

		if ( 'wordpress_publish' === $slug ) {
			return array(
				'wordpress_publish_tool' => array(
					'description'       => 'Publish a post',
					'_handler_callable' => 'wordpress_publish::handler',
				),
			);
		}

		// All other slugs (including handler-free synthetic step_type
		// slugs like 'system_task' or 'webhook_gate') return no tools.
		return array();
	}
}

/**
 * Inline reimplementation of ToolPolicyResolver::gatherPipelineTools()
 * adjacency loop. Mirrors inc/Engine/AI/Tools/ToolPolicyResolver.php
 * post-#1205 (the documentation-only edit there did not change the
 * iteration shape).
 */
function gather_pipeline_handler_tools_for_test( array $args, StubToolManager $tool_manager ): array {
	$available_tools = array();

	foreach ( array( $args['previous_step_config'] ?? null, $args['next_step_config'] ?? null ) as $step_config ) {
		if ( ! $step_config ) {
			continue;
		}

		$handler_slugs       = $step_config['handler_slugs'] ?? array();
		$handler_configs_map = $step_config['handler_configs'] ?? array();
		$cache_scope         = $step_config['flow_step_id'] ?? ( $args['cache_scope'] ?? '' );

		foreach ( $handler_slugs as $slug ) {
			$handler_config = $handler_configs_map[ $slug ] ?? array();
			$tools          = $tool_manager->resolveHandlerTools( $slug, $handler_config, array(), $cache_scope );

			foreach ( $tools as $tool_name => $tool_config ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}
	}

	return $available_tools;
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

echo "ToolPolicyResolver adjacency smoke (Phase 2a)\n";
echo "----------------------------------------------\n";

// Test 1: AI step with a publish step (handler-bearing) before it.
// Resolver should pick up wordpress_publish_tool from the adjacency.
echo "\n[1] AI adjacent to handler-bearing publish step:\n";
$tool_manager = new StubToolManager();
$args         = array(
	'previous_step_config' => array(
		'flow_step_id'    => 'flow_pub_1',
		'step_type'       => 'publish',
		'handler_slugs'   => array( 'wordpress_publish' ),
		'handler_configs' => array(
			'wordpress_publish' => array( 'post_status' => 'draft' ),
		),
	),
	'next_step_config'     => null,
);
$tools = gather_pipeline_handler_tools_for_test( $args, $tool_manager );

assert_equals( array( 'wordpress_publish' ), $tool_manager->resolveCalls, 'asked tool manager for wordpress_publish', $failures, $passes );
assert_equals( true, isset( $tools['wordpress_publish_tool'] ), 'wordpress_publish_tool surfaced', $failures, $passes );

// Test 2: AI step with a synthetic-slug system_task adjacency.
// After #1205, system_task carries handler_slugs = ['system_task'].
// Resolver must NOT inject a 'system_task' pseudo-tool — the tool
// manager returns nothing for that slug, and the resolver respects
// the empty result.
echo "\n[2] AI adjacent to handler-free system_task step (synthetic slug):\n";
$tool_manager = new StubToolManager();
$args         = array(
	'previous_step_config' => array(
		'flow_step_id'    => 'flow_sys_1',
		'step_type'       => 'system_task',
		'handler_slugs'   => array( 'system_task' ),
		'handler_configs' => array(
			'system_task' => array( 'task' => 'daily_memory_generation', 'params' => array() ),
		),
	),
	'next_step_config'     => null,
);
$tools = gather_pipeline_handler_tools_for_test( $args, $tool_manager );

assert_equals( array( 'system_task' ), $tool_manager->resolveCalls, 'resolver asked for synthetic system_task slug', $failures, $passes );
assert_equals( array(), $tools, 'no tools surfaced for handler-free synthetic slug', $failures, $passes );

// Test 3: Mixed adjacency — publish before, system_task after.
// Only the real handler tool should land in available_tools.
echo "\n[3] Mixed adjacency (publish before, system_task after):\n";
$tool_manager = new StubToolManager();
$args         = array(
	'previous_step_config' => array(
		'flow_step_id'    => 'flow_pub_1',
		'step_type'       => 'publish',
		'handler_slugs'   => array( 'wordpress_publish' ),
		'handler_configs' => array(
			'wordpress_publish' => array(),
		),
	),
	'next_step_config'     => array(
		'flow_step_id'    => 'flow_sys_2',
		'step_type'       => 'system_task',
		'handler_slugs'   => array( 'system_task' ),
		'handler_configs' => array(
			'system_task' => array( 'task' => 'agent_ping', 'params' => array() ),
		),
	),
);
$tools = gather_pipeline_handler_tools_for_test( $args, $tool_manager );

assert_equals( array( 'wordpress_publish', 'system_task' ), $tool_manager->resolveCalls, 'resolver visited both adjacent slugs', $failures, $passes );
assert_equals( array( 'wordpress_publish_tool' ), array_keys( $tools ), 'only real handler tool landed in available_tools', $failures, $passes );

// Test 4: Multi-handler publish adjacency — the legitimate multi-element callsite.
// Resolver still iterates all slugs and surfaces every tool the manager returns.
echo "\n[4] Multi-handler publish adjacency (the legitimate iteration case):\n";
class MultiHandlerStub extends StubToolManager {
	public function resolveHandlerTools( string $slug, array $config, array $engine_data, string $cache_scope ): array {
		$this->resolveCalls[] = $slug;
		if ( 'wordpress_publish' === $slug ) {
			return array( 'wordpress_publish_tool' => array( 'description' => 'WP publish' ) );
		}
		if ( 'twitter_publish' === $slug ) {
			return array( 'twitter_publish_tool' => array( 'description' => 'Twitter publish' ) );
		}
		return array();
	}
}
$tool_manager = new MultiHandlerStub();
$args         = array(
	'previous_step_config' => array(
		'flow_step_id'    => 'flow_pub_multi',
		'step_type'       => 'publish',
		'handler_slugs'   => array( 'wordpress_publish', 'twitter_publish' ),
		'handler_configs' => array(
			'wordpress_publish' => array(),
			'twitter_publish'   => array(),
		),
	),
	'next_step_config'     => null,
);
$tools = gather_pipeline_handler_tools_for_test( $args, $tool_manager );

assert_equals( array( 'wordpress_publish', 'twitter_publish' ), $tool_manager->resolveCalls, 'resolver visited every handler slug', $failures, $passes );
assert_equals( array( 'wordpress_publish_tool', 'twitter_publish_tool' ), array_keys( $tools ), 'all handler tools surfaced', $failures, $passes );

// Test 5: Empty handler_slugs (handler-bearing step with nothing configured).
// Resolver short-circuits the inner foreach without calling the tool manager.
echo "\n[5] Adjacent step with empty handler_slugs:\n";
$tool_manager = new StubToolManager();
$args         = array(
	'previous_step_config' => array(
		'flow_step_id'    => 'flow_empty',
		'step_type'       => 'publish',
		'handler_slugs'   => array(),
		'handler_configs' => array(),
	),
	'next_step_config'     => null,
);
$tools = gather_pipeline_handler_tools_for_test( $args, $tool_manager );

assert_equals( array(), $tool_manager->resolveCalls, 'resolver did not call tool manager', $failures, $passes );
assert_equals( array(), $tools, 'no tools surfaced', $failures, $passes );

echo "\n----------------------------------------------\n";
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
