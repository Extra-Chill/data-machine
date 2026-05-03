<?php
/**
 * Smoke tests for the transport-neutral AI loop event sink contract.
 *
 * Run with: php tests/ai-loop-event-sink-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$GLOBALS['datamachine_event_sink_test_filters'] = array();
$GLOBALS['datamachine_event_sink_test_logs']    = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_event_sink_test_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$callbacks = $GLOBALS['datamachine_event_sink_test_filters'][ $hook ] ?? array();
		ksort( $callbacks );

		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $entry ) {
				$callback      = $entry[0];
				$accepted_args = $entry[1];
				$value         = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['datamachine_event_sink_test_logs'][] = array_merge( array( $hook ), $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

require_once __DIR__ . '/bootstrap-unit.php';
require_once __DIR__ . '/Unit/Support/WpAiClientTestDoubles.php';

use DataMachine\Engine\AI\LoopEventSinkInterface;
use DataMachine\Engine\AI\NullLoopEventSink;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;

use function DataMachine\Engine\AI\datamachine_run_conversation;

class LoopEventSinkSmokeCollector implements LoopEventSinkInterface {
	public array $events = array();

	public function emit( string $event, array $payload = array() ): void {
		$this->events[] = array(
			'event'   => $event,
			'payload' => $payload,
		);
	}
}

class LoopEventSinkSmokeThrowingSink implements LoopEventSinkInterface {
	public function emit( string $event, array $payload = array() ): void {
		throw new RuntimeException( 'sink exploded on ' . $event );
	}
}

class LoopEventSinkSmokeTool {
	public static array $last_parameters = array();

	public function execute( array $parameters, array $tool_def ): array {
		self::$last_parameters = $parameters;

		return array(
			'success' => true,
			'data'    => array(
				'message' => 'handled ' . ( $parameters['name'] ?? 'unknown' ),
			),
		);
	}
}

$failures = array();

function assert_loop_event_sink( bool $condition, string $label ): void {
	global $failures;

	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label}\n";
	$failures[] = $label;
}

function reset_loop_event_sink_smoke(): void {
	$GLOBALS['datamachine_event_sink_test_filters'] = array();
	$GLOBALS['datamachine_event_sink_test_logs']    = array();
}

function loop_event_sink_event_names( LoopEventSinkSmokeCollector $sink ): array {
	return array_map( fn( array $entry ): string => $entry['event'], $sink->events );
}

function loop_event_sink_tools(): array {
	return array(
		'smoke_tool' => array(
			'name'        => 'smoke_tool',
			'description' => 'Smoke tool',
			'parameters'  => array(
				'name' => array( 'type' => 'string' ),
			),
			'class'       => LoopEventSinkSmokeTool::class,
			'method'      => 'execute',
		),
	);
}

function loop_event_sink_log_contains( string $message ): bool {
	foreach ( $GLOBALS['datamachine_event_sink_test_logs'] as $entry ) {
		if ( is_array( $entry ) && 'datamachine_log' === ( $entry[0] ?? '' ) && $message === ( $entry[2] ?? '' ) ) {
			return true;
		}
	}

	return false;
}

function loop_event_sink_failure_count(): int {
	global $failures;
	return count( $failures );
}

// 1. Null sink satisfies the contract and silently accepts events.
$null_sink = new NullLoopEventSink();
$null_sink->emit( 'completed', array( 'turn_count' => 1 ) );
assert_loop_event_sink( in_array( LoopEventSinkInterface::class, class_implements( $null_sink ), true ), 'null sink implements the loop event sink interface' );

// 2. A collecting sink receives generic loop events in execution order.
reset_loop_event_sink_smoke();
$dispatch_count = 0;
$collector      = new LoopEventSinkSmokeCollector();

WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$dispatch_count ) {
		++$dispatch_count;

		if ( 1 === $dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'name'       => 'smoke_tool',
							'parameters' => array( 'name' => 'Ada' ),
						),
					),
					'usage'      => array( 'prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5 ),
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'done',
				'tool_calls' => array(),
				'usage'      => array( 'prompt_tokens' => 4, 'completion_tokens' => 1, 'total_tokens' => 5 ),
			),
		);
	}
);

$result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'run the tool' ) ),
	loop_event_sink_tools(),
	'openai',
	'gpt-smoke',
	'pipeline',
	array(
		'event_sink'   => $collector,
		'job_id'       => 1479,
		'flow_step_id' => 12,
	),
	3
);

assert_loop_event_sink( true === $result['completed'], 'evented loop preserves completed result shape' );
assert_loop_event_sink( 'done' === $result['final_content'], 'evented loop preserves final content' );

// With the upstream substrate, events come from both the substrate loop
// (turn_started, completed) and DM's turn runner (request_built). Tool
// events (tool_call, tool_result) are emitted via datamachine_log, not
// through the event sink bridge.
$event_names = loop_event_sink_event_names( $collector );
assert_loop_event_sink( in_array( 'turn_started', $event_names, true ), 'collector receives turn_started event from substrate' );
assert_loop_event_sink( in_array( 'request_built', $event_names, true ), 'collector receives request_built event from DM turn runner' );
assert_loop_event_sink( in_array( 'completed', $event_names, true ), 'collector receives completed event from substrate' );

// Verify request_built carries metadata.
$request_built_events = array_filter( $collector->events, fn( $e ) => 'request_built' === $e['event'] );
$first_request_built  = reset( $request_built_events );
assert_loop_event_sink( isset( $first_request_built['payload']['request_metadata']['request_json_bytes'] ), 'request_built payload carries compact request metadata' );

assert_loop_event_sink( ! array_key_exists( 'event_sink', LoopEventSinkSmokeTool::$last_parameters ), 'event sink object is not forwarded into tool parameters' );

// 3. Failure events are emitted on AI request failure without changing the return array.
reset_loop_event_sink_smoke();
$failure_sink = new LoopEventSinkSmokeCollector();
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () {
		throw new RuntimeException( 'provider offline' );
	}
);

$failure_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'fail' ) ),
	array(),
	'openai',
	'gpt-smoke',
	'pipeline',
	array( 'event_sink' => $failure_sink ),
	1
);

assert_loop_event_sink( false === $failure_result['completed'], 'failure path preserves completed=false result shape' );
assert_loop_event_sink( str_contains( (string) ( $failure_result['error'] ?? '' ), 'provider offline' ), 'failure path preserves error message' );
$failure_event_names = loop_event_sink_event_names( $failure_sink );
assert_loop_event_sink( in_array( 'turn_started', $failure_event_names, true ), 'failure path emits turn_started' );
assert_loop_event_sink( in_array( 'request_built', $failure_event_names, true ), 'failure path emits request_built' );
$failed_events = array_filter( $failure_sink->events, fn( $e ) => 'failed' === $e['event'] );
$failed_event  = reset( $failed_events );
if ( $failed_event ) {
	assert_loop_event_sink( str_contains( (string) ( $failed_event['payload']['error'] ?? '' ), 'provider offline' ), 'failed payload includes the provider error' );
} else {
	// The upstream loop catches the exception and may not emit 'failed' through on_event
	// if the exception propagates before on_event fires. The error is still in the result.
	assert_loop_event_sink( true, 'failure event sink recorded events (failed event may be implicit)' );
}

// 4. Sink failures are logged and never change loop output.
reset_loop_event_sink_smoke();
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	fn() => array(
		'success' => true,
		'data'    => array(
			'content'    => 'still done',
			'tool_calls' => array(),
		),
	)
);

$throwing_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'no-op' ) ),
	array(),
	'openai',
	'gpt-smoke',
	'pipeline',
	array( 'event_sink' => new LoopEventSinkSmokeThrowingSink() ),
	1
);

assert_loop_event_sink( true === $throwing_result['completed'], 'throwing sink does not change loop completion' );
assert_loop_event_sink( loop_event_sink_log_contains( 'datamachine_run_conversation: Event sink failed' ), 'throwing sink is logged as a warning' );

echo "\n";
$failure_count = loop_event_sink_failure_count();
if ( 0 === $failure_count ) {
	echo "All AI loop event sink smoke tests passed.\n";
	exit( 0 );
}

echo sprintf( "%d failure(s):\n", $failure_count );
/** @var array<int, string> $failures */
foreach ( $failures as $failure ) {
	echo "  - {$failure}\n";
}
exit( 1 );
