<?php
/**
 * Smoke test for the agent conversation request/runner boundary.
 *
 * Run with: php tests/agent-conversation-runner-request-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$GLOBALS['datamachine_runner_request_filters'] = array();
$GLOBALS['datamachine_runner_request_logs']    = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_runner_request_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$callbacks = $GLOBALS['datamachine_runner_request_filters'][ $hook ] ?? array();
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
		$GLOBALS['datamachine_runner_request_logs'][] = array_merge( array( $hook ), $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

require_once __DIR__ . '/bootstrap-unit.php';

use DataMachine\Engine\AI\AIConversationLoop;
use DataMachine\Engine\AI\AgentConversationRequest;
use DataMachine\Engine\AI\LoopEventSinkInterface;

class RunnerRequestSmokeSink implements LoopEventSinkInterface {
	public array $events = array();

	public function emit( string $event, array $payload = array() ): void {
		$this->events[] = array(
			'event'   => $event,
			'payload' => $payload,
		);
	}
}

$failures = array();

function assert_runner_request( bool $condition, string $label ): void {
	global $failures;

	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label}\n";
	$failures[] = $label;
}

function runner_request_failure_count(): int {
	global $failures;
	return count( $failures );
}

$messages = array(
	array(
		'role'    => 'user',
		'content' => 'hello runner boundary',
	),
);
$tools    = array();
$sink     = new RunnerRequestSmokeSink();
$payload  = array(
	'job_id'                   => 1569,
	'flow_step_id'             => 'flow-step-smoke',
	'pipeline_id'              => 31,
	'flow_id'                  => 41,
	'configured_handler_slugs' => array( 'wiki_upsert' ),
	'persist_transcript'       => false,
	'engine'                   => array( 'snapshot' => 'present' ),
	'event_sink'               => $sink,
);

// 1. The request object exposes generic runner inputs and Data Machine adapter context.
$request = AgentConversationRequest::fromRunArgs(
	$messages,
	$tools,
	'openai',
	'gpt-smoke',
	'pipeline',
	$payload,
	7,
	true
);

assert_runner_request( $messages === $request->messages(), 'request keeps messages as generic input' );
assert_runner_request( $tools === $request->tools(), 'request keeps tools as generic input' );
assert_runner_request( 'openai' === $request->provider(), 'request exposes provider from model config' );
assert_runner_request( 'gpt-smoke' === $request->model(), 'request exposes model from model config' );
assert_runner_request( 'pipeline' === $request->mode(), 'request exposes mode' );
assert_runner_request( 7 === $request->maxTurns(), 'request exposes max turns' );
assert_runner_request( true === $request->singleTurn(), 'request exposes single-turn flag' );
assert_runner_request( $sink === $request->eventSink(), 'request exposes event sink' );
assert_runner_request( ! array_key_exists( 'job_id', $request->payload() ), 'generic payload excludes job id' );
assert_runner_request( ! array_key_exists( 'flow_step_id', $request->payload() ), 'generic payload excludes flow step id' );
assert_runner_request( ! array_key_exists( 'pipeline_id', $request->payload() ), 'generic payload excludes pipeline id' );
assert_runner_request( ! array_key_exists( 'flow_id', $request->payload() ), 'generic payload excludes flow id' );
assert_runner_request( ! array_key_exists( 'configured_handler_slugs', $request->payload() ), 'generic payload excludes handler completion policy' );
assert_runner_request( ! array_key_exists( 'persist_transcript', $request->payload() ), 'generic payload excludes transcript policy' );
assert_runner_request( ! array_key_exists( 'engine', $request->payload() ), 'generic payload excludes engine object' );
assert_runner_request( 1569 === $request->adapterContext()['job_id'], 'adapter context carries job id' );
assert_runner_request( 31 === $request->adapterContext()['pipeline_id'], 'adapter context carries pipeline id' );
assert_runner_request( array( 'wiki_upsert' ) === $request->adapterContext()['configured_handler_slugs'], 'adapter context carries handler completion policy' );
assert_runner_request( false === $request->adapterContext()['persist_transcript'], 'adapter context carries transcript policy' );
assert_runner_request( array( 'snapshot' => 'present' ) === $request->adapterContext()['engine'], 'adapter context carries engine snapshot' );
assert_runner_request( $payload === $request->adapterPayload(), 'adapter payload reconstructs the legacy Data Machine payload' );

// 2. The compatibility facade passes the historical argument list to the Agents API runner filter.
$legacy_filter_args = null;
add_filter(
	'agents_api_conversation_runner',
	function ( $result, ...$args ) use ( &$legacy_filter_args ) {
		$legacy_filter_args = array_merge( array( $result ), $args );
		return null;
	},
	10,
	9
);

$dispatched_request = null;
add_filter(
	'chubes_ai_request',
	function ( array $request_body, ...$args ) use ( &$dispatched_request ) {
		unset( $args );
		$dispatched_request = $request_body;

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'facade ok',
				'tool_calls' => array(),
				'usage'      => array(
					'prompt_tokens'     => 2,
					'completion_tokens' => 3,
					'total_tokens'      => 5,
				),
			),
		);
	},
	10,
	6
);

$result = AIConversationLoop::run(
	$messages,
	$tools,
	'openai',
	'gpt-smoke',
	'pipeline',
	$payload,
	7,
	true
);

assert_runner_request( array_key_exists( 0, $legacy_filter_args ) && null === $legacy_filter_args[0], 'runner filter still receives nullable result seed first' );
assert_runner_request( $messages === ( $legacy_filter_args[1] ?? null ), 'runner filter still receives legacy messages argument' );
assert_runner_request( $tools === ( $legacy_filter_args[2] ?? null ), 'runner filter still receives legacy tools argument' );
assert_runner_request( 'openai' === ( $legacy_filter_args[3] ?? null ), 'runner filter still receives legacy provider argument' );
assert_runner_request( 'gpt-smoke' === ( $legacy_filter_args[4] ?? null ), 'runner filter still receives legacy model argument' );
assert_runner_request( 'pipeline' === ( $legacy_filter_args[5] ?? null ), 'runner filter still receives legacy mode argument' );
assert_runner_request( $payload === ( $legacy_filter_args[6] ?? null ), 'runner filter still receives legacy payload argument' );
assert_runner_request( 7 === ( $legacy_filter_args[7] ?? null ), 'runner filter still receives legacy max-turns argument' );
assert_runner_request( true === ( $legacy_filter_args[8] ?? null ), 'runner filter still receives legacy single-turn argument' );

assert_runner_request( 'facade ok' === $result['final_content'], 'facade result comes back through built-in runner path' );
assert_runner_request( true === $result['completed'], 'facade preserves completed result shape' );
assert_runner_request( 1 === $result['turn_count'], 'facade preserves turn count' );
assert_runner_request( array() === $result['tool_execution_results'], 'facade normalizes optional tool execution results' );
assert_runner_request( 5 === ( $result['usage']['total_tokens'] ?? null ), 'facade preserves usage totals' );
assert_runner_request( is_array( $dispatched_request ), 'built-in runner dispatched a provider request' );
assert_runner_request( array( 'turn_started', 'request_built', 'completed' ) === array_column( $sink->events, 'event' ), 'event sink survives request boundary' );

$legacy_filter_ran = false;
add_filter(
	'datamachine_conversation_runner',
	function ( $result ) use ( &$legacy_filter_ran ) {
		$legacy_filter_ran = true;
		return $result;
	},
	10,
	1
);

AIConversationLoop::run(
	$messages,
	$tools,
	'openai',
	'gpt-smoke',
	'pipeline',
	$payload,
	7,
	true
);
assert_runner_request( false === $legacy_filter_ran, 'legacy Data Machine conversation-runner filter is no longer mirrored' );

if ( runner_request_failure_count() > 0 ) {
	exit( 1 );
}

echo "\nAll agent conversation runner request smoke tests passed.\n";
