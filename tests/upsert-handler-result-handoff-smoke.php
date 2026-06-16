<?php
/**
 * Pure-PHP smoke test for AI handler-result handoff to upsert validation.
 *
 * Run with: php tests/upsert-handler-result-handoff-smoke.php
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

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
    	return json_encode( $value, $flags, $depth );
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
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolManager.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineAgentToolPolicyProvider.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineMandatoryToolPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineToolAccessPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/RuntimeToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AdjacentHandlerToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolSourceRegistry.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolPolicyResolver.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolResultFinder.php';
require_once __DIR__ . '/../inc/Core/Steps/AI/AIStep.php';

use DataMachine\Core\Steps\AI\AIStep;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use DataMachine\Engine\AI\Tools\ToolResultFinder;

$passes   = 0;
$failures = array();

function assert_handoff_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

echo "Upsert handler result handoff smoke (#1375)\n";
echo "------------------------------------------\n\n";

add_filter(
	'datamachine_tools',
	static function ( array $tools ): array {
		$tools['wiki_upsert'] = array(
			'description' => 'Global wiki upsert tool',
			'modes'       => array( 'pipeline' ),
		);

		$tools['__handler_tools_wiki_upsert'] = array(
			'_handler_callable' => static function ( $tools, $handler_slug, $handler_config ) {
				if ( 'wiki_upsert' !== $handler_slug ) {
					return $tools;
				}

				$tools['wiki_upsert'] = array(
					'description' => 'Handler-scoped wiki upsert tool',
					'parameters'  => array(),
					'config_seen' => $handler_config,
				);

				return $tools;
			},
			'handler'           => 'wiki_upsert',
			'modes'             => array( 'pipeline' ),
			'access_level'      => 'admin',
		);

		return $tools;
	}
);

$resolver        = new ToolPolicyResolver();
$available_tools = $resolver->resolve(
	array(
		'mode'             => ToolPolicyResolver::MODE_PIPELINE,
		'next_step_config' => array(
			'flow_step_id'    => 'upsert_step',
			'step_type'       => 'upsert',
			'handler_slugs'   => array( 'wiki_upsert' ),
			'handler_configs' => array( 'wiki_upsert' => array( 'fixed_parent_path' => 'woocommerce' ) ),
		),
	)
);

assert_handoff_equals( true, isset( $available_tools['wiki_upsert'] ), 'handler-scoped wiki_upsert tool resolved', $failures, $passes );
assert_handoff_equals( 'wiki_upsert', $available_tools['wiki_upsert']['handler'] ?? null, 'handler slug metadata survives name collision', $failures, $passes );
assert_handoff_equals( 'Handler-scoped wiki upsert tool', $available_tools['wiki_upsert']['description'] ?? null, 'handler definition wins over global tool', $failures, $passes );
assert_handoff_equals( array( 'fixed_parent_path' => 'woocommerce' ), $available_tools['wiki_upsert']['handler_config'] ?? null, 'handler config propagates to resolved tool definition', $failures, $passes );
assert_handoff_equals( array( 'fixed_parent_path' => 'woocommerce' ), $available_tools['wiki_upsert']['config_seen'] ?? null, 'handler callback still receives the same config', $failures, $passes );

$method = new ReflectionMethod( AIStep::class, 'processLoopResults' );
$output_diagnostic_method = new ReflectionMethod( AIStep::class, 'outputDiagnosticReason' );
$diagnostic_method = new ReflectionMethod( AIStep::class, 'emptyOutputDiagnosticReason' );

$packets = $method->invoke(
	null,
	array(
		'messages'               => array(
			array( 'role' => 'assistant', 'content' => 'Updated the article.' ),
		),
		'tool_execution_results' => array(
			array(
				'tool_name'       => 'wiki_upsert',
				'result'          => array(
					'success' => true,
					'action'  => 'updated',
					'article' => array( 'id' => 538, 'title' => 'WooCommerce Ownership Manager' ),
				),
				'parameters'      => array( 'title' => 'WooCommerce Ownership Manager' ),
				'is_handler_tool' => true,
				'turn_count'      => 2,
			),
		),
	),
	array(
		array(
			'type'     => 'fetch',
			'metadata' => array( 'source_type' => 'mcp' ),
		),
	),
	array( 'flow_step_id' => 'ai_step' ),
	$available_tools
);

assert_handoff_equals( 1, count( $packets ), 'AI step emitted one downstream packet', $failures, $passes );
assert_handoff_equals( 'ai_handler_complete', $packets[0]['type'] ?? null, 'AI step emitted handler-complete packet', $failures, $passes );
assert_handoff_equals( 'wiki_upsert', $packets[0]['metadata']['handler_tool'] ?? null, 'packet carries required handler slug', $failures, $passes );

$found = ToolResultFinder::findHandlerResult( $packets, 'wiki_upsert', 'upsert_step', false );
assert_handoff_equals( $packets[0], $found, 'ToolResultFinder finds packet by required handler slug', $failures, $passes );

$diagnostic = $output_diagnostic_method->invoke(
	null,
	array(
		'messages'               => array(
			array( 'role' => 'assistant', 'content' => 'I summarized the source but did not write the article.' ),
		),
		'tool_execution_results' => array(),
	),
	array( 'wiki_upsert' ),
	array(
		array(
			'type' => 'ai_response',
			'data' => array( 'content' => 'I summarized the source but did not write the article.' ),
		),
	)
);
assert_handoff_equals( 'ai_required_handler_not_called', $diagnostic, 'AI non-handler output records missing handler diagnostic', $failures, $passes );

$diagnostic = $output_diagnostic_method->invoke(
	null,
	array( 'tool_execution_results' => array() ),
	array( 'wiki_upsert' ),
	$packets
);
assert_handoff_equals( '', $diagnostic, 'AI handler-complete output records no diagnostic', $failures, $passes );

$diagnostic = $diagnostic_method->invoke(
	null,
	array(
		'messages'               => array(
			array( 'role' => 'assistant', 'content' => 'I could not find enough useful material.' ),
		),
		'tool_execution_results' => array(),
	),
	array( 'wiki_upsert' )
);
assert_handoff_equals( 'ai_required_handler_not_called', $diagnostic, 'AI empty output records missing handler diagnostic', $failures, $passes );

$diagnostic = $diagnostic_method->invoke(
	null,
	array(
		'tool_execution_results' => array(
			array(
				'tool_name'       => 'wiki_upsert',
				'result'          => array( 'success' => false, 'message' => 'quality gate rejected' ),
				'is_handler_tool' => true,
			),
		),
	),
	array( 'wiki_upsert' )
);
assert_handoff_equals( 'ai_handler_tool_failed', $diagnostic, 'AI empty output records failed handler diagnostic', $failures, $passes );

$diagnostic = $diagnostic_method->invoke(
	null,
	array(
		'messages'               => array(),
		'tool_execution_results' => array(),
	),
	array()
);
assert_handoff_equals( 'ai_empty_response', $diagnostic, 'AI empty output records empty response diagnostic', $failures, $passes );

echo "\n------------------------------------------\n";
echo "{$passes} / " . ( $passes + count( $failures ) ) . " passed\n";

if ( ! empty( $failures ) ) {
	echo "Failures:\n";
	foreach ( $failures as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}

echo "All checks passed.\n";
