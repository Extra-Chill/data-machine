<?php
/**
 * Pure-PHP smoke test for canonical handler config shapes.
 *
 * Run with: php tests/system-task-config-passthrough-smoke.php
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
		$GLOBALS['__system_task_config_passthrough_actions'][] = array( $hook, $args );
	}
}

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';

use DataMachine\Core\Steps\FlowStepConfig;

/**
 * Inline mirror of ExecuteWorkflowAbility::buildConfigsFromWorkflow().
 */
function build_configs_from_workflow_for_test( array $workflow ): array {
	$flow_config     = array();
	$pipeline_config = array();

	foreach ( $workflow['steps'] as $index => $step ) {
		$step_id          = "ephemeral_step_{$index}";
		$pipeline_step_id = "ephemeral_pipeline_{$index}";
		$handler_slug     = $step['handler_slug'] ?? '';
		$handler_config   = $step['handler_config'] ?? array();
		$step_type        = $step['type'];

		$flow_step_config = array(
			'flow_step_id'     => $step_id,
			'pipeline_step_id' => $pipeline_step_id,
			'step_type'        => $step_type,
			'execution_order'  => $index,
			'enabled_tools'    => ( 'ai' === $step_type && ! empty( $step['enabled_tools'] ) && is_array( $step['enabled_tools'] ) )
				? array_values( $step['enabled_tools'] )
				: array(),
			'prompt_queue'     => array(),
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

		$flow_config[ $step_id ] = $flow_step_config;

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

echo "handler config shape smoke (#1293)\n";
echo "-----------------------------------\n";

echo "\n[1] system_task stores handler_config without synthetic slugs:\n";
$built = build_configs_from_workflow_for_test(
	array(
		'steps' => array(
			array(
				'type'           => 'system_task',
				'handler_config' => array(
					'task'   => 'daily_memory_generation',
					'params' => array(),
				),
			),
		),
	)
);
$step0 = $built['flow_config']['ephemeral_step_0'];
assert_equals( array( 'task' => 'daily_memory_generation', 'params' => array() ), FlowStepConfig::getPrimaryHandlerConfig( $step0 ), 'system_task config reachable', $failures, $passes );
assert_equals( 'system_task', FlowStepConfig::getEffectiveSlug( $step0 ), 'system_task settings slug is step_type', $failures, $passes );
assert_absent( 'handler_slug', $step0, 'system_task has no handler_slug', $failures, $passes );
assert_absent( 'handler_slugs', $step0, 'system_task has no handler_slugs', $failures, $passes );
assert_absent( 'handler_configs', $step0, 'system_task has no handler_configs', $failures, $passes );

echo "\n[2] fetch stores scalar handler_slug + handler_config:\n";
$built = build_configs_from_workflow_for_test(
	array(
		'steps' => array(
			array(
				'type'           => 'fetch',
				'handler_slug'   => 'mcp',
				'handler_config' => array( 'server' => 'a8c' ),
			),
		),
	)
);
$step0 = $built['flow_config']['ephemeral_step_0'];
assert_equals( 'mcp', FlowStepConfig::getHandlerSlug( $step0 ), 'fetch handler slug is scalar', $failures, $passes );
assert_equals( array( 'mcp' ), FlowStepConfig::getConfiguredHandlerSlugs( $step0 ), 'fetch generic slug list has one slug', $failures, $passes );
assert_equals( array( 'server' => 'a8c' ), FlowStepConfig::getPrimaryHandlerConfig( $step0 ), 'fetch config reachable', $failures, $passes );
assert_absent( 'handler_slugs', $step0, 'fetch has no handler_slugs', $failures, $passes );
assert_absent( 'handler_configs', $step0, 'fetch has no handler_configs', $failures, $passes );

echo "\n[3] publish keeps multi-handler list shape:\n";
$built = build_configs_from_workflow_for_test(
	array(
		'steps' => array(
			array(
				'type'           => 'publish',
				'handler_slug'   => 'wordpress_publish',
				'handler_config' => array( 'post_type' => 'post' ),
			),
		),
	)
);
$step0 = $built['flow_config']['ephemeral_step_0'];
assert_equals( array( 'wordpress_publish' ), FlowStepConfig::getHandlerSlugs( $step0 ), 'publish handler slugs stay plural', $failures, $passes );
assert_equals( array( 'post_type' => 'post' ), FlowStepConfig::getPrimaryHandlerConfig( $step0 ), 'publish config reachable', $failures, $passes );
assert_absent( 'handler_slug', $step0, 'publish has no scalar handler_slug', $failures, $passes );
assert_absent( 'handler_config', $step0, 'publish has no scalar handler_config', $failures, $passes );

echo "\n[4] webhook_gate with no config has no handler fields:\n";
$built = build_configs_from_workflow_for_test( array( 'steps' => array( array( 'type' => 'webhook_gate' ) ) ) );
$step0 = $built['flow_config']['ephemeral_step_0'];
assert_equals( array(), FlowStepConfig::getPrimaryHandlerConfig( $step0 ), 'webhook_gate config empty', $failures, $passes );
assert_absent( 'handler_slug', $step0, 'webhook_gate has no handler_slug', $failures, $passes );
assert_absent( 'handler_slugs', $step0, 'webhook_gate has no handler_slugs', $failures, $passes );
assert_absent( 'handler_config', $step0, 'webhook_gate has no handler_config when empty', $failures, $passes );

echo "\n[5] AI stores enabled_tools only:\n";
$built = build_configs_from_workflow_for_test(
	array(
		'steps' => array(
			array(
				'type'          => 'ai',
				'system_prompt' => 'be helpful',
				'enabled_tools' => array( 'intelligence/search' ),
			),
		),
	)
);
$step0 = $built['flow_config']['ephemeral_step_0'];
assert_equals( array( 'intelligence/search' ), FlowStepConfig::getEnabledTools( $step0 ), 'AI enabled_tools readable', $failures, $passes );
assert_absent( 'handler_slug', $step0, 'AI has no handler_slug', $failures, $passes );
assert_absent( 'handler_slugs', $step0, 'AI has no handler_slugs', $failures, $passes );
assert_equals( 'be helpful', $built['pipeline_config']['ephemeral_pipeline_0']['system_prompt'] ?? null, 'AI system_prompt lands in pipeline_config', $failures, $passes );

echo "\n[6] normalize legacy rows to canonical storage:\n";
$system_task = FlowStepConfig::normalizeHandlerShape(
	array(
		'step_type'       => 'system_task',
		'handler_slugs'   => array( 'system_task' ),
		'handler_configs' => array( 'system_task' => array( 'task' => 'agent_ping' ) ),
	)
);
assert_equals( array( 'task' => 'agent_ping' ), $system_task['handler_config'] ?? array(), 'legacy synthetic system_task config collapses', $failures, $passes );
assert_absent( 'handler_slugs', $system_task, 'legacy synthetic system_task slugs removed', $failures, $passes );

$fetch = FlowStepConfig::normalizeHandlerShape(
	array(
		'step_type'       => 'fetch',
		'handler_slugs'   => array( 'rss' ),
		'handler_configs' => array( 'rss' => array( 'url' => 'https://example.com/feed.xml' ) ),
	)
);
assert_equals( 'rss', $fetch['handler_slug'] ?? null, 'legacy fetch slug becomes scalar', $failures, $passes );
assert_equals( array( 'url' => 'https://example.com/feed.xml' ), $fetch['handler_config'] ?? array(), 'legacy fetch config becomes scalar', $failures, $passes );
assert_absent( 'handler_slugs', $fetch, 'legacy fetch plural slugs removed', $failures, $passes );

$fetch_configs_only = FlowStepConfig::normalizeHandlerShape(
	array(
		'step_type'       => 'fetch',
		'handler_configs' => array( 'rss' => array( 'url' => 'https://example.com/feed.xml' ) ),
	)
);
assert_equals( 'rss', $fetch_configs_only['handler_slug'] ?? null, 'legacy fetch infers scalar slug from config key', $failures, $passes );
assert_equals( array( 'url' => 'https://example.com/feed.xml' ), $fetch_configs_only['handler_config'] ?? array(), 'legacy fetch keeps config when slug list missing', $failures, $passes );

$publish = FlowStepConfig::normalizeHandlerShape(
	array(
		'step_type'      => 'publish',
		'handler_slug'   => 'wordpress_publish',
		'handler_config' => array( 'post_type' => 'post' ),
	)
);
assert_equals( array( 'wordpress_publish' ), $publish['handler_slugs'] ?? array(), 'legacy publish scalar slug becomes list', $failures, $passes );
assert_equals( array( 'wordpress_publish' => array( 'post_type' => 'post' ) ), $publish['handler_configs'] ?? array(), 'legacy publish scalar config becomes map', $failures, $passes );
assert_absent( 'handler_slug', $publish, 'legacy publish scalar slug removed', $failures, $passes );

$publish_configs_only = FlowStepConfig::normalizeHandlerShape(
	array(
		'step_type'       => 'publish',
		'handler_configs' => array(
			'wordpress_publish' => array( 'post_type' => 'post' ),
			'email_publish'     => array( 'to' => 'ops@example.com' ),
		),
	)
);
assert_equals( array( 'wordpress_publish', 'email_publish' ), $publish_configs_only['handler_slugs'] ?? array(), 'legacy publish infers slugs from config keys', $failures, $passes );
assert_equals( array( 'to' => 'ops@example.com' ), $publish_configs_only['handler_configs']['email_publish'] ?? array(), 'legacy publish keeps all inferred configs', $failures, $passes );

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
