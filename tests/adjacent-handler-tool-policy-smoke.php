<?php
/**
 * Pure-PHP smoke test for adjacent handler tool policy semantics (#1444).
 *
 * Run with: php tests/adjacent-handler-tool-policy-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Engine\AI\Tools {
	if ( ! class_exists( ToolManager::class ) ) {
		class ToolManager {}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	function do_action( string $_hook, ...$_args ): void {}

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
	require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineAgentToolPolicyProvider.php';
	require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineMandatoryToolPolicy.php';
	require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AdjacentHandlerToolSource.php';
	require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolSourceRegistry.php';
	require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolPolicyResolver.php';

	$failures = array();
	$passes   = 0;

	function assert_same_policy( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

	echo "Adjacent handler tool policy smoke (#1444)\n";
	echo "-------------------------------------------\n";

	$resolver = new \DataMachine\Engine\AI\Tools\ToolPolicyResolver( new \DataMachine\Engine\AI\Tools\ToolManager() );
	$tools    = array(
		'publish_to_wordpress' => array(
			'handler'     => 'wordpress_publish',
			'description' => 'Publish through adjacent WordPress handler',
		),
		'web_fetch'            => array(
			'ability'     => 'datamachine/web-fetch',
			'description' => 'Optional research tool',
		),
	);

	echo "\n[1] Agent tool_policy cannot remove adjacent handler tools:\n";
	$filtered = $resolver->applyAgentPolicy(
		$tools,
		array(
			'mode'  => 'deny',
			'tools' => array( 'publish_to_wordpress', 'web_fetch' ),
		)
	);
	assert_same_policy( true, isset( $filtered['publish_to_wordpress'] ), 'deny policy preserves handler tool', $failures, $passes );
	assert_same_policy( false, isset( $filtered['web_fetch'] ), 'deny policy still removes optional tool', $failures, $passes );

	$filtered = $resolver->applyAgentPolicy(
		$tools,
		array(
			'mode'  => 'allow',
			'tools' => array(),
		)
	);
	assert_same_policy( array( 'publish_to_wordpress' ), array_keys( $filtered ), 'empty allow policy keeps only handler plumbing', $failures, $passes );

	echo "\n[2] Context allow_only cannot remove adjacent handler tools:\n";
	$method   = new ReflectionMethod( \DataMachine\Engine\AI\Tools\ToolPolicyResolver::class, 'filterByAllowOnlyPreservingHandlerTools' );
	$filtered = $method->invoke( $resolver, $tools, array( 'web_fetch' ) );
	assert_same_policy( true, isset( $filtered['publish_to_wordpress'] ), 'allow_only preserves handler tool', $failures, $passes );
	assert_same_policy( true, isset( $filtered['web_fetch'] ), 'allow_only keeps explicitly allowed optional tool', $failures, $passes );

	echo "\n[3] Required handler availability is checked by handler metadata:\n";
	$publish_step = array(
		'step_type'     => 'publish',
		'handler_slugs' => array( 'wordpress_publish' ),
	);
	$required     = \DataMachine\Core\Steps\FlowStepConfig::getAdjacentRequiredHandlerSlugsForAi( null, $publish_step );
	assert_same_policy( array( 'wordpress_publish' ), $required, 'publish step declares required handler slug', $failures, $passes );
	assert_same_policy( array( 'wordpress_publish' ), \DataMachine\Core\Steps\FlowStepConfig::getAvailableRequiredHandlerSlugsForAi( $required, $tools ), 'tool name may differ when handler metadata matches', $failures, $passes );
	assert_same_policy( array(), \DataMachine\Core\Steps\FlowStepConfig::getMissingRequiredHandlerSlugsForAi( $required, $tools ), 'available handler metadata reports no missing slug', $failures, $passes );
	assert_same_policy( array( 'wordpress_publish' ), \DataMachine\Core\Steps\FlowStepConfig::getMissingRequiredHandlerSlugsForAi( $required, array() ), 'missing required handler reports diagnostic slug', $failures, $passes );

	echo "\n[4] Runtime and inspector use the same failure helper:\n";
	$ai_step_source   = (string) file_get_contents( __DIR__ . '/../inc/Core/Steps/AI/AIStep.php' );
	$inspector_source = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/RequestInspector.php' );
	assert_same_policy( true, false !== strpos( $ai_step_source, 'getMissingRequiredHandlerSlugsForAi' ), 'AIStep checks missing required handler tools before model call', $failures, $passes );
	assert_same_policy( true, false !== strpos( $inspector_source, 'getMissingRequiredHandlerSlugsForAi' ), 'RequestInspector checks the same missing handler state', $failures, $passes );
	assert_same_policy( false, false !== strpos( $ai_step_source, 'array_keys( $available_tools )' ), 'AIStep no longer silently narrows by visible tool names', $failures, $passes );

	echo "\n[5] Data Machine policy adapters own mandatory handler-tool vocabulary:\n";
	$resolver_source  = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/ToolPolicyResolver.php' );
	$mandatory_source = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineMandatoryToolPolicy.php' );
	$agent_source     = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineAgentToolPolicyProvider.php' );
	assert_same_policy( false, false !== strpos( $resolver_source, 'DataMachine\\Core\\Database\\Agents\\Agents' ), 'resolver no longer imports Data Machine agent table repository', $failures, $passes );
	assert_same_policy( false, false !== strpos( $resolver_source, 'isPipelineHandlerTool' ), 'resolver no longer owns handler-tool classifier', $failures, $passes );
	assert_same_policy( true, false !== strpos( $mandatory_source, 'isset( $tool[\'handler\'] )' ), 'mandatory policy adapter owns handler metadata classifier', $failures, $passes );
	assert_same_policy( true, false !== strpos( $agent_source, 'new Agents()' ), 'agent policy provider owns persisted agent config lookup', $failures, $passes );

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
}
