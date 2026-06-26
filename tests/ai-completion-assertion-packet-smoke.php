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

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
    	$GLOBALS['__filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
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
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( string $hook, ...$args ): void {
    	$GLOBALS['__actions'][] = array( $hook, $args );
    }
}

function did_action( string $hook ): int {
	return 'init' === $hook ? 1 : 0;
}

function current_action(): string {
	return '';
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $key, $default_value = false ) {
    	return $default_value;
    }
}

require_once __DIR__ . '/../inc/Core/DataPacket.php';
require_once __DIR__ . '/../inc/Core/PluginSettings.php';
require_once __DIR__ . '/../inc/Core/Steps/Step.php';
require_once __DIR__ . '/../inc/Core/Steps/StepTypeRegistrationTrait.php';
require_once __DIR__ . '/../inc/Core/Steps/QueueableTrait.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-access-policy.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-declaration.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-policy-filter.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-policy.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-source-registry.php';
require_once __DIR__ . '/../inc/Engine/AI/ConversationManager.php';
require_once __DIR__ . '/../inc/Engine/AI/conversation-loop.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolManager.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineAgentToolPolicyProvider.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineMandatoryToolPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineToolAccessPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/RuntimeToolSource.php';
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
$missing_method = new ReflectionMethod( AIStep::class, 'missingCompletionAssertionsFailure' );
$runtime_input_method = new ReflectionMethod( AIStep::class, 'runtimeInputPacketsForPrompt' );

$result = $method->invoke(
	null,
	\DataMachine\Engine\AI\datamachine_with_conversation_metadata(
		array(
			'messages'               => array(),
			'tool_execution_results' => array(),
		),
		array(
			'completion_assertions_complete'  => true,
			'completion_assertions_satisfied' => array(
				'complete_when_any' => array( 'design_comment_and_labels' ),
			),
			'completion_assertions_missing'   => array(),
		)
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

$missing_failure = $missing_method->invoke(
	null,
	array(),
	array(
		'completion_assertions_complete'  => false,
		'completion_assertions_missing'   => array( 'tool_names' => array( 'comment_github_pull_request' ) ),
		'completion_assertions_satisfied' => array( 'complete_when_any' => array( 'pull_request_path' ) ),
		'completion_assertions_required'  => array( 'tool_names' => array( 'comment_github_pull_request' ) ),
	)
);

assert_completion_packet( is_array( $missing_failure ), 'missing assertions build structured failure payload', $failures, $passes );
assert_completion_packet( 'completion_assertions_missing' === ( $missing_failure['reason'] ?? '' ), 'missing assertion failure uses explicit reason', $failures, $passes );
assert_completion_packet( array( 'comment_github_pull_request' ) === ( $missing_failure['completion_assertions_missing']['tool_names'] ?? array() ), 'missing assertion failure preserves missing tool names', $failures, $passes );

$complete_failure = $missing_method->invoke(
	null,
	array(),
	array(
		'completion_assertions_complete'  => true,
		'completion_assertions_missing'   => array(),
		'completion_assertions_satisfied' => array( 'complete_when_any' => array( 'pull_request_path' ) ),
	)
);

assert_completion_packet( null === $complete_failure, 'satisfied assertions do not build failure payload', $failures, $passes );

$ai_step_reflection = new ReflectionClass( AIStep::class );
$ai_step            = $ai_step_reflection->newInstanceWithoutConstructor();
$engine_data_prop   = $ai_step_reflection->getParentClass()->getProperty( 'engine_data' );
$engine_data_prop->setValue(
	$ai_step,
	array(
		'artifacts'      => array(
			'concept_packet' => array(
				'payload' => array( 'title' => 'Runtime concept' ),
			),
		),
		'concept_packet' => array( 'title' => 'Runtime concept' ),
	)
);
$runtime_packets = $runtime_input_method->invoke( $ai_step, array() );
assert_completion_packet( 1 === count( $runtime_packets ), 'runtime input prompt packet is added when runtime artifacts exist', $failures, $passes );
assert_completion_packet( 'runtime_input' === ( $runtime_packets[0]['type'] ?? null ), 'runtime input packet uses runtime_input type', $failures, $passes );
assert_completion_packet( 'Runtime concept' === ( $runtime_packets[0]['data']['runtime_input']['concept_packet']['title'] ?? null ), 'runtime concept packet is prompt-visible', $failures, $passes );
assert_completion_packet( 'Runtime concept' === ( $runtime_packets[0]['data']['runtime_input']['artifacts']['concept_packet']['payload']['title'] ?? null ), 'runtime artifact map is prompt-visible', $failures, $passes );

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
