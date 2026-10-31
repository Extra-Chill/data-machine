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
use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;
use AgentsAPI\AI\WP_Agent_Conversation_Loop;
use DataMachine\Engine\AI\DataMachineToolRuntimeRules;
use function DataMachine\Engine\AI\datamachine_build_pre_tool_mediator;

class DelegatedRuntimeToolSmokeSink implements LoopEventSinkInterface {
	public array $events = array();

	public function emit( string $event, array $payload = array() ): void {
		$this->events[] = array(
			'event'   => $event,
			'payload' => $payload,
		);
	}
}

class DelegatedRuntimeToolUnexpectedExecutor implements WP_Agent_Tool_Executor {
	public int $calls = 0;

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_call, $tool_definition, $context );
		++$this->calls;

		return array(
			'success' => false,
			'error'   => 'External runtime tools must not reach the PHP executor.',
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

$run_delegated_tool = static function ( string $call_id, string $value, array $payload = array() ) use ( $delegated_tools, $sink ): array {
	$executor = new DelegatedRuntimeToolUnexpectedExecutor();
	$turns    = 0;
	$mediator = datamachine_build_pre_tool_mediator(
		$delegated_tools,
		$payload,
		'chat',
		array( 'chat' ),
		new DataMachineToolRuntimeRules(),
		$sink,
		array()
	);
	$result   = WP_Agent_Conversation_Loop::run(
		array( array( 'role' => 'user', 'content' => 'Run the delegated action.' ) ),
		static function ( array $messages ) use ( &$turns, $call_id, $value ): array {
			++$turns;
			if ( 1 === $turns ) {
				return array(
					'messages'   => $messages,
					'tool_calls' => array(
						array(
							'id'         => $call_id,
							'name'       => 'delegated_action',
							'parameters' => array( 'value' => $value ),
						),
					),
				);
			}

			return array(
				'messages'   => $messages,
				'content'    => 'Delegated action complete.',
				'tool_calls' => array(),
			);
		},
		array(
			'max_turns'         => 2,
			'tool_executor'     => $executor,
			'tool_declarations' => $delegated_tools,
			'pre_tool_mediator' => $mediator,
		)
	);

	return array( $result, $executor, $turns );
};

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

$filter_run      = $run_delegated_tool( 'delegated-filter-call', 'from-filter' );
$filter_result   = $filter_run[0]['tool_execution_results'][0]['result'] ?? array();
$filter_payload  = is_array( $filter_result['result'] ?? null ) ? $filter_result['result'] : array();
$filter_executor = $filter_run[1];
$assert( true === ( $filter_result['success'] ?? null ), 'filter result succeeds through the integrated mediator path' );
$assert( 'client' === ( $filter_payload['executor'] ?? null ), 'filter result is normalized as client-executed' );
$assert( true === ( $filter_payload['filtered'] ?? null ), 'filter payload is preserved' );
$assert( 'from-filter' === ( $filter_payload['value'] ?? null ), 'filter receives model parameters' );
$assert( 0 === $filter_executor->calls, 'filter fulfillment bypasses the PHP executor' );
$assert( 2 === $filter_run[2], 'filter fulfillment continues to the next provider turn' );
$assert( 'Delegated action complete.' === ( $filter_run[0]['final_content'] ?? null ), 'filter fulfillment preserves continuation content' );
$assert( array( 'tool_call', 'tool_result' ) === array_column( $filter_run[0]['tool_events'] ?? array(), 'type' ), 'filter fulfillment preserves canonical tool events' );

$callback_run = $run_delegated_tool(
	'delegated-callback-call',
	'from-callback',
	array(
		'client_context' => array(
			'runtime_tool_callback' => static function ( array $request ): array {
				return array(
					'callback' => true,
					'value'    => $request['parameters']['value'] ?? '',
				);
			},
		),
	)
);
$callback_result = $callback_run[0]['tool_execution_results'][0]['result'] ?? array();
$callback_payload = is_array( $callback_result['result'] ?? null ) ? $callback_result['result'] : array();
$assert( true === ( $callback_result['success'] ?? null ), 'runtime_tool_callback result succeeds through the integrated mediator path' );
$assert( 'client' === ( $callback_payload['executor'] ?? null ), 'runtime_tool_callback result is normalized as client-executed' );
$assert( true === ( $callback_payload['callback'] ?? null ), 'runtime_tool_callback payload is preserved' );
$assert( 'from-callback' === ( $callback_payload['value'] ?? null ), 'runtime_tool_callback receives model parameters' );
$assert( 0 === $callback_run[1]->calls, 'runtime_tool_callback fulfillment bypasses the PHP executor' );

$preseed_run = $run_delegated_tool(
	'delegated-preseed-call',
	'ignored',
	array(
		'client_context' => array(
			'runtime_tool_results' => array(
				'delegated-preseed-call' => array(
					'preseeded' => true,
					'value'     => 'from-preseed',
				),
			),
		),
	)
);
$preseed_result = $preseed_run[0]['tool_execution_results'][0]['result'] ?? array();
$preseed_payload = is_array( $preseed_result['result'] ?? null ) ? $preseed_result['result'] : array();
$assert( true === ( $preseed_result['success'] ?? null ), 'runtime_tool_results pre-seeded result succeeds through the integrated mediator path' );
$assert( 'client' === ( $preseed_payload['executor'] ?? null ), 'runtime_tool_results result is normalized as client-executed' );
$assert( true === ( $preseed_payload['preseeded'] ?? null ), 'runtime_tool_results payload is preserved' );
$assert( 'from-preseed' === ( $preseed_payload['value'] ?? null ), 'runtime_tool_results result is selected by call id' );
$assert( 0 === $preseed_run[1]->calls, 'runtime_tool_results fulfillment bypasses the PHP executor' );
$assert( str_contains( wp_json_encode( $sink->events ), 'runtime_tool_call' ), 'integrated mediator emits runtime_tool_call events' );
$assert( str_contains( wp_json_encode( $sink->events ), 'runtime_tool_result' ), 'integrated mediator emits runtime_tool_result events' );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " delegated runtime tool assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} delegated runtime tool assertions passed.\n";
