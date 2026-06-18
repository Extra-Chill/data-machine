<?php
/**
 * Smoke assertions for delegated runtime-tool result resolution.
 *
 * Run with: php tests/runtime-tool-delegated-result-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$GLOBALS['datamachine_delegated_runtime_filters'] = array();
$GLOBALS['datamachine_delegated_runtime_logs']    = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_delegated_runtime_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['datamachine_delegated_runtime_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['datamachine_delegated_runtime_filters'][ $hook ] );
		foreach ( $GLOBALS['datamachine_delegated_runtime_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = call_user_func_array( $callback[0], array_slice( array_merge( array( $value ), $args ), 0, (int) $callback[1] ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['datamachine_delegated_runtime_logs'][] = array_merge( array( $hook ), $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

require_once __DIR__ . '/bootstrap-unit.php';
require_once __DIR__ . '/../inc/Engine/AI/conversation-loop.php';

use DataMachine\Engine\AI\LoopEventSinkInterface;
use function DataMachine\Engine\AI\datamachine_build_loop_tool_executor;

class DelegatedRuntimeToolSmokeSink implements LoopEventSinkInterface {
	public array $events = array();

	public function emit( string $event, array $payload = array() ): void {
		$this->events[] = array(
			'event'   => $event,
			'payload' => $payload,
		);
	}
}

$failures = array();
$passes   = 0;

$assert = static function ( bool $condition, string $message ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "  ✓ {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "  ✗ {$message}\n";
};

$delegated_tool = array(
	'name'              => 'delegated_action',
	'description'       => 'A delegated runtime action.',
	'parameters'        => array(
		'type'       => 'object',
		'properties' => array(
			'value' => array( 'type' => 'string' ),
		),
	),
	'executor'          => 'client',
	'external_executor' => true,
	'runtime_tool'      => true,
);
$delegated_tools = array( 'delegated_action' => $delegated_tool );
$sink            = new DelegatedRuntimeToolSmokeSink();

echo "runtime-tool-delegated-result-smoke\n\n";

add_filter(
	'datamachine_runtime_tool_result',
	function ( $result, array $request ) {
		if ( 'delegated_action' !== ( $request['tool_name'] ?? '' ) || 'delegated-filter-call' !== ( $request['call_id'] ?? '' ) ) {
			return $result;
		}

		return array(
			'filtered' => true,
			'value'    => $request['parameters']['value'] ?? '',
		);
	},
	10,
	2
);

$filter_executor = datamachine_build_loop_tool_executor( $delegated_tools, array(), 'chat', array( 'chat' ), $sink, array() );
$filter_result   = $filter_executor->executeWP_Agent_Tool_Call(
	array(
		'tool_name'  => 'delegated_action',
		'id'         => 'delegated-filter-call',
		'parameters' => array( 'value' => 'from-filter' ),
	),
	$delegated_tool,
	array( 'turn' => 1 )
);
$assert( true === ( $filter_result['success'] ?? null ), 'filter result succeeds through delegated executor fallback' );
$assert( 'client' === ( $filter_result['executor'] ?? null ), 'filter result is normalized as client-executed' );
$assert( true === ( $filter_result['filtered'] ?? null ), 'filter payload is preserved' );
$assert( 'from-filter' === ( $filter_result['value'] ?? null ), 'filter receives model parameters' );

$callback_executor = datamachine_build_loop_tool_executor(
	$delegated_tools,
	array(
		'client_context' => array(
			'runtime_tool_callback' => static function ( array $request ): array {
				return array(
					'callback' => true,
					'value'    => $request['parameters']['value'] ?? '',
				);
			},
		),
	),
	'chat',
	array( 'chat' ),
	$sink,
	array()
);
$callback_result   = $callback_executor->executeWP_Agent_Tool_Call(
	array(
		'tool_name'  => 'delegated_action',
		'id'         => 'delegated-callback-call',
		'parameters' => array( 'value' => 'from-callback' ),
	),
	$delegated_tool,
	array( 'turn' => 2 )
);
$assert( true === ( $callback_result['success'] ?? null ), 'runtime_tool_callback result succeeds through delegated executor fallback' );
$assert( 'client' === ( $callback_result['executor'] ?? null ), 'runtime_tool_callback result is normalized as client-executed' );
$assert( true === ( $callback_result['callback'] ?? null ), 'runtime_tool_callback payload is preserved' );
$assert( 'from-callback' === ( $callback_result['value'] ?? null ), 'runtime_tool_callback receives model parameters' );

$preseed_executor = datamachine_build_loop_tool_executor(
	$delegated_tools,
	array(
		'client_context' => array(
			'runtime_tool_results' => array(
				'delegated-preseed-call' => array(
					'preseeded' => true,
					'value'     => 'from-preseed',
				),
			),
		),
	),
	'chat',
	array( 'chat' ),
	$sink,
	array()
);
$preseed_result   = $preseed_executor->executeWP_Agent_Tool_Call(
	array(
		'tool_name'  => 'delegated_action',
		'id'         => 'delegated-preseed-call',
		'parameters' => array( 'value' => 'ignored' ),
	),
	$delegated_tool,
	array( 'turn' => 3 )
);
$assert( true === ( $preseed_result['success'] ?? null ), 'runtime_tool_results pre-seeded result succeeds through delegated executor fallback' );
$assert( 'client' === ( $preseed_result['executor'] ?? null ), 'runtime_tool_results result is normalized as client-executed' );
$assert( true === ( $preseed_result['preseeded'] ?? null ), 'runtime_tool_results payload is preserved' );
$assert( 'from-preseed' === ( $preseed_result['value'] ?? null ), 'runtime_tool_results result is selected by call id' );
$assert( str_contains( wp_json_encode( $sink->events ), 'runtime_tool_call' ), 'delegated executor fallback emits runtime_tool_call events' );
$assert( str_contains( wp_json_encode( $sink->events ), 'runtime_tool_result' ), 'delegated executor fallback emits runtime_tool_result events' );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " delegated runtime tool assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} delegated runtime tool assertions passed.\n";
