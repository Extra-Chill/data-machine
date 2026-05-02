<?php
/**
 * Smoke test for datamachine_run_conversation() substrate integration.
 *
 * Verifies that DM's conversation entry point correctly delegates to the
 * upstream AgentConversationLoop::run() and returns a normalized result.
 *
 * Run with: php tests/agent-conversation-runner-request-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$GLOBALS['datamachine_runner_request_logs'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		// No-op for smoke tests.
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
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

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		unset( $name );
		return $default;
	}
}

require_once __DIR__ . '/bootstrap-unit.php';
require_once __DIR__ . '/Unit/Support/WpAiClientTestDoubles.php';

use DataMachine\Engine\AI\LoopEventSinkInterface;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;
use AgentsAPI\AI\AgentMessageEnvelope;

use function DataMachine\Engine\AI\datamachine_run_conversation;

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
$tools = array();
$sink  = new RunnerRequestSmokeSink();

// 1. datamachine_run_conversation dispatches via upstream substrate and returns a normalized result.
$dispatched_request = null;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function ( array $request_body ) use ( &$dispatched_request ) {
		$dispatched_request = $request_body;

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'substrate ok',
				'tool_calls' => array(),
				'usage'      => array(
					'prompt_tokens'     => 2,
					'completion_tokens' => 3,
					'total_tokens'      => 5,
				),
			),
		);
	}
);

$result = datamachine_run_conversation(
	$messages,
	$tools,
	'openai',
	'gpt-smoke',
	'pipeline',
	array(
		'job_id'       => 1569,
		'flow_step_id' => 'flow-step-smoke',
		'event_sink'   => $sink,
	),
	7,
	true
);

assert_runner_request( 'substrate ok' === ( $result['final_content'] ?? null ), 'result preserves final content from provider' );
assert_runner_request( true === ( $result['completed'] ?? null ), 'result marks conversation complete when no tools called' );
assert_runner_request( 1 === ( $result['turn_count'] ?? null ), 'result preserves turn count' );
assert_runner_request( is_array( $result['tool_execution_results'] ?? null ), 'result includes tool execution results' );
assert_runner_request( 5 === ( $result['usage']['total_tokens'] ?? null ), 'result preserves accumulated usage totals' );
assert_runner_request( is_array( $dispatched_request ), 'substrate dispatched a provider request' );
assert_runner_request( ! empty( $sink->events ), 'DM event sink received events through the substrate bridge' );

// 2. Error path returns a structured error result without throwing.
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () {
		throw new RuntimeException( 'provider offline' );
	}
);

$error_result = datamachine_run_conversation(
	$messages,
	$tools,
	'openai',
	'gpt-smoke',
	'pipeline',
	array(),
	1
);

assert_runner_request( isset( $error_result['error'] ), 'error path returns a structured error field' );
assert_runner_request( str_contains( (string) ( $error_result['error'] ?? '' ), 'provider offline' ), 'error path preserves the provider error message' );
assert_runner_request( false === ( $error_result['completed'] ?? true ), 'error path marks conversation not completed' );

if ( runner_request_failure_count() > 0 ) {
	exit( 1 );
}

echo "\nAll agent conversation runner request smoke tests passed.\n";
