<?php
/**
 * Pure-PHP smoke test for ToolPolicyResolver adjacency with handler-free steps.
 *
 * Run with: php tests/tool-policy-resolver-adjacency-smoke.php
 *
 * After #1293, handler-free steps (system_task, webhook_gate) no longer
 * carry synthetic handler_slugs. ToolPolicyResolver::gatherPipelineTools()
 * reads adjacent steps through FlowStepConfig::getConfiguredHandlerSlugs(),
 * so handler-free adjacency should contribute zero handler tools while
 * single-handler and multi-handler steps still expose their real handlers.
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
		$GLOBALS['__tool_policy_resolver_actions'][] = array( $hook, $args );
	}
}

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';

use DataMachine\Core\Steps\FlowStepConfig;

/**
 * Stub ToolManager that records the slugs the resolver asked for and
 * returns canned tools per slug. A real handler slug
 * ('wordpress_publish') gets back a real tool.
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

		// All other slugs return no tools.
		return array();
	}
}

/**
 * Inline reimplementation of ToolPolicyResolver::gatherPipelineTools()
 * adjacency loop. Mirrors inc/Engine/AI/Tools/ToolPolicyResolver.php.
 */
function gather_pipeline_handler_tools_for_test( array $args, StubToolManager $tool_manager ): array {
	$available_tools = array();

	foreach ( array( $args['previous_step_config'] ?? null, $args['next_step_config'] ?? null ) as $step_config ) {
		if ( ! $step_config ) {
			continue;
		}

		$handler_slugs = FlowStepConfig::getConfiguredHandlerSlugs( $step_config );
		$cache_scope   = $step_config['flow_step_id'] ?? ( $args['cache_scope'] ?? '' );

		foreach ( $handler_slugs as $slug ) {
			$handler_config = FlowStepConfig::getHandlerConfigForSlug( $step_config, $slug );
			$tools          = $tool_manager->resolveHandlerTools( $slug, $handler_config, array(), $cache_scope );

			foreach ( $tools as $tool_name => $tool_config ) {
				$available_tools[ $tool_name ] = $tool_config;
			}
		}
	}

	return $available_tools;
}

/**
 * Inline reimplementation of the static-tool merge portion of
 * ToolPolicyResolver::gatherPipelineTools(). Handler-scoped tools must win
 * name collisions because they carry the `handler` metadata downstream steps
 * use to prove required handler execution.
 */
function merge_static_pipeline_tools_for_test( array $available_tools, array $pipeline_tools ): array {
	foreach ( $pipeline_tools as $tool_name => $tool_config ) {
		if ( ! is_array( $tool_config ) ) {
			continue;
		}

		if ( isset( $tool_config['_handler_callable'] ) ) {
			continue;
		}

		if ( isset( $available_tools[ $tool_name ] ) ) {
			continue;
		}

		$available_tools[ $tool_name ] = $tool_config;
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

echo "ToolPolicyResolver adjacency smoke (#1293)\n";
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

// Test 2: AI step with a handler-free system_task adjacency.
// Resolver should not ask the tool manager for any handler slug.
echo "\n[2] AI adjacent to handler-free system_task step:\n";
$tool_manager = new StubToolManager();
$args         = array(
	'previous_step_config' => array(
		'flow_step_id'    => 'flow_sys_1',
		'step_type'       => 'system_task',
		'handler_config'  => array( 'task' => 'daily_memory_generation', 'params' => array() ),
	),
	'next_step_config'     => null,
);
$tools = gather_pipeline_handler_tools_for_test( $args, $tool_manager );

assert_equals( array(), $tool_manager->resolveCalls, 'resolver ignored handler-free system_task', $failures, $passes );
assert_equals( array(), $tools, 'no tools surfaced for handler-free step', $failures, $passes );

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
		'handler_config'  => array( 'task' => 'agent_ping', 'params' => array() ),
	),
);
$tools = gather_pipeline_handler_tools_for_test( $args, $tool_manager );

assert_equals( array( 'wordpress_publish' ), $tool_manager->resolveCalls, 'resolver visited only real adjacent handlers', $failures, $passes );
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

// Test 6: Handler-scoped tool collides with static pipeline tool of same name.
// The handler-scoped definition must win so AIConversationLoop records a
// successful handler completion for downstream UpsertStep/PublishStep.
echo "\n[6] Handler-scoped tool wins static tool name collision:\n";
$tools = merge_static_pipeline_tools_for_test(
	array(
		'wiki_upsert' => array(
			'class'       => 'WikiUpsertHandler',
			'method'      => 'handle_tool_call',
			'handler'     => 'wiki_upsert',
			'parameters'  => array( 'handler-scoped' => true ),
		),
	),
	array(
		'wiki_upsert' => array(
			'class'      => 'WikiUpsertAbility',
			'method'     => 'handle_tool_call',
			'parameters' => array( 'global' => true ),
		),
		'wiki_read'   => array(
			'class'      => 'WikiReadAbility',
			'method'     => 'handle_tool_call',
			'parameters' => array(),
		),
	)
);

assert_equals( 'wiki_upsert', $tools['wiki_upsert']['handler'] ?? null, 'handler metadata preserved on colliding tool', $failures, $passes );
assert_equals( array( 'handler-scoped' => true ), $tools['wiki_upsert']['parameters'], 'handler-scoped definition preserved', $failures, $passes );
assert_equals( true, isset( $tools['wiki_read'] ), 'non-colliding static pipeline tool still added', $failures, $passes );

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
