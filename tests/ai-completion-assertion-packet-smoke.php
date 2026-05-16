<?php
/**
 * Pure-PHP smoke test for side-effect-only AI completion assertions.
 *
 * Run with: php tests/ai-completion-assertion-packet-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$__filters = array();

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['__filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( string $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['__filters'][ $hook ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['__filters'][ $hook ] );
	foreach ( $GLOBALS['__filters'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$callback      = $entry[0];
			$accepted_args = $entry[1];
			$call_args     = array_slice( array_merge( array( $value ), $args ), 0, $accepted_args );
			$value         = $callback( ...$call_args );
		}
	}

	return $value;
}

function do_action( string $hook, ...$args ): void {
	$GLOBALS['__actions'][] = array( $hook, $args );
}

function did_action( string $hook ): int {
	return 'init' === $hook ? 1 : 0;
}

function current_action(): string {
	return '';
}

function get_option( string $key, $default_value = false ) {
	return $default_value;
}

require_once __DIR__ . '/../inc/Core/DataPacket.php';
require_once __DIR__ . '/../inc/Core/PluginSettings.php';
require_once __DIR__ . '/../inc/Core/Steps/Step.php';
require_once __DIR__ . '/../inc/Core/Steps/StepTypeRegistrationTrait.php';
require_once __DIR__ . '/../inc/Core/Steps/QueueableTrait.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-access-policy.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-policy-filter.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-policy.php';
require_once __DIR__ . '/../inc/Engine/AI/ConversationManager.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolManager.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineAgentToolPolicyProvider.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineMandatoryToolPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineToolAccessPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AdjacentHandlerToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolSourceRegistry.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolPolicyResolver.php';
require_once __DIR__ . '/../inc/Core/Steps/AI/AIStep.php';

use DataMachine\Core\Steps\AI\AIStep;

$passes   = 0;
$failures = array();

function assert_completion_packet( bool $condition, string $name, array &$failures, int &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
}

echo "AI completion assertion packet smoke\n";
echo "-------------------------------------\n\n";

$method = new ReflectionMethod( AIStep::class, 'processLoopResults' );

$result = $method->invoke(
	null,
	array(
		'messages'                       => array(),
		'tool_execution_results'         => array(),
		'completion_assertions_complete'  => true,
		'completion_assertions_satisfied' => array(
			'complete_when_any' => array( 'design_comment_and_labels' ),
		),
		'completion_assertions_missing'   => array(),
	),
	array(
		array(
			'type'     => 'fetch',
			'metadata' => array( 'source_type' => 'github_issue' ),
		),
	),
	array( 'flow_step_id' => 'design_ai_step' ),
	array()
);

assert_completion_packet( 1 === count( $result ), 'AI step emits one summary packet', $failures, $passes );
assert_completion_packet( 'ai_completion_assertions' === ( $result[0]['type'] ?? '' ), 'packet uses completion assertion type', $failures, $passes );
assert_completion_packet( 'design_ai_step' === ( $result[0]['metadata']['flow_step_id'] ?? '' ), 'packet preserves flow step id', $failures, $passes );
assert_completion_packet( array( 'design_comment_and_labels' ) === ( $result[0]['metadata']['completion_assertions_satisfied']['complete_when_any'] ?? array() ), 'packet carries satisfied outcome', $failures, $passes );

echo "\n-------------------------------------\n";
echo sprintf( "%d / %d passed\n", $passes, $passes + count( $failures ) );

if ( ! empty( $failures ) ) {
	echo "Failures:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "All checks passed.\n";
