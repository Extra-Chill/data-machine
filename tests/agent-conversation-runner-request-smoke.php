<?php
/**
 * Smoke test for datamachine_run_conversation() substrate integration.
 *
 * Verifies that DM's conversation entry point correctly delegates to the
 * upstream WP_Agent_Conversation_Loop::run() and returns a normalized result.
 *
 * Run with: php tests/agent-conversation-runner-request-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$GLOBALS['datamachine_runner_request_logs'] = array();
$GLOBALS['datamachine_runner_request_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_runner_request_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['datamachine_runner_request_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['datamachine_runner_request_filters'][ $hook ] );
		foreach ( $GLOBALS['datamachine_runner_request_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = call_user_func_array( $callback[0], array_slice( array_merge( array( $value ), $args ), 0, (int) $callback[1] ) );
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

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $text ) ) ?? '' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default_value = false ) {
		unset( $name );
		return $default_value;
	}
}

require_once __DIR__ . '/bootstrap-unit.php';
require_once __DIR__ . '/Unit/Support/WpAiClientTestDoubles.php';

use DataMachine\Engine\AI\LoopEventSinkInterface;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;
use AgentsAPI\AI\WP_Agent_Message;
use AgentsAPI\AI\WP_Agent_Conversation_Request;
use AgentsAPI\AI\WP_Agent_Transcript_Persister;

use function DataMachine\Engine\AI\datamachine_run_conversation;
use function DataMachine\Engine\AI\datamachine_conversation_metadata;
use function DataMachine\Engine\AI\datamachine_with_conversation_metadata;

class RunnerRequestSmokeSink implements LoopEventSinkInterface {
	public array $events = array();

	public function emit( string $event, array $payload = array() ): void {
		$this->events[] = array(
			'event'   => $event,
			'payload' => $payload,
		);
	}
}

class RunnerRequestSmokeTool {
	public function execute( array $params ): array {
		return array(
			'success' => true,
			'name'    => (string) ( $params['name'] ?? '' ),
		);
	}
}

class SandboxPipelineSmokeTool {
	public function execute( array $params ): array {
		return array(
			'success' => true,
			'path'    => (string) ( $params['path'] ?? '' ),
			'content' => 'sandbox file content',
		);
	}
}

class RunnerRequestSmokeTranscriptPersister implements WP_Agent_Transcript_Persister {
	public array $calls = array();

	public function persist( array $messages, WP_Agent_Conversation_Request $request, array $result ): string {
		$this->calls[] = array(
			'messages' => $messages,
			'result'   => $result,
			'context'  => $request->runtimeContext(),
		);

		return 'runner-request-smoke-failure-session';
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
	array( 'pipeline' ),
	array(
		'job_id'       => 1569,
		'flow_step_id' => 'flow-step-smoke',
		'event_sink'   => $sink,
	),
	7,
	true
);
$result_metadata = datamachine_conversation_metadata( $result );

assert_runner_request( 'substrate ok' === ( $result['final_content'] ?? null ), 'result preserves final content from provider' );
assert_runner_request( true === ( $result_metadata['completed'] ?? null ), 'result marks conversation complete when no tools called' );
assert_runner_request( ! array_key_exists( 'completed', $result ), 'DM completion flag is namespaced outside the Agents API result top level' );
assert_runner_request( is_array( $result['metadata']['datamachine'] ?? null ), 'result carries Data Machine diagnostics under metadata.datamachine' );
assert_runner_request( 1 === ( $result['turn_count'] ?? null ), 'result preserves turn count' );
assert_runner_request( is_array( $result['tool_execution_results'] ?? null ), 'result includes tool execution results' );
assert_runner_request( 5 === ( $result['usage']['total_tokens'] ?? null ), 'result preserves accumulated usage totals' );
assert_runner_request( is_array( $dispatched_request ), 'substrate dispatched a provider request' );
assert_runner_request( ! empty( $sink->events ), 'DM event sink received events through the substrate bridge' );
assert_runner_request( isset( $result['runtime_provenance'] ) && is_array( $result['runtime_provenance'] ), 'result includes runtime provenance' );
assert_runner_request( 1 === ( $result['runtime_provenance']['schema_version'] ?? null ), 'runtime provenance exposes a stable schema version' );
assert_runner_request( 'openai' === ( $result['runtime_provenance']['provider']['id'] ?? null ), 'runtime provenance records provider id' );
assert_runner_request( 'gpt-smoke' === ( $result['runtime_provenance']['model']['id'] ?? null ), 'runtime provenance records model id' );
assert_runner_request( 5 === ( $result['runtime_provenance']['usage']['total_tokens'] ?? null ), 'runtime provenance records token usage' );
assert_runner_request( 'flow-step-smoke' === ( $result['runtime_provenance']['identifiers']['flow_step_id'] ?? null ), 'runtime provenance records flow step id' );
assert_runner_request( 1569 === ( $result['runtime_provenance']['identifiers']['job_id'] ?? null ), 'runtime provenance records job id' );
assert_runner_request( isset( $result['runtime_provenance']['input']['prompt_sha256'] ) && 64 === strlen( $result['runtime_provenance']['input']['prompt_sha256'] ), 'runtime provenance records prompt hash' );
assert_runner_request( isset( $result['runtime_provenance']['tools']['policy_sha256'] ) && 64 === strlen( $result['runtime_provenance']['tools']['policy_sha256'] ), 'runtime provenance records tool policy hash' );

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
	array( 'pipeline' ),
	array(),
	1
);
$error_metadata = datamachine_conversation_metadata( $error_result );

assert_runner_request( isset( $error_result['error'] ), 'error path returns a structured error field' );
assert_runner_request( str_contains( (string) ( $error_result['error'] ?? '' ), 'provider offline' ), 'error path preserves the provider error message' );
assert_runner_request( false === ( $error_metadata['completed'] ?? true ), 'error path marks conversation not completed' );
assert_runner_request( 'provider_error' === ( $error_result['runtime_provenance']['status']['finish_reason'] ?? null ), 'error provenance records provider error finish reason' );
assert_runner_request( str_contains( (string) ( $error_result['runtime_provenance']['provider_errors'][0]['message'] ?? '' ), 'provider offline' ), 'error provenance records provider error message' );

// 2b. Budget-exceeded results keep canonical status top-level and DM diagnostics namespaced.
$budget_result = datamachine_with_conversation_metadata(
	array(
		'messages'               => $messages,
		'final_content'          => '',
		'turn_count'             => 3,
		'tool_execution_results' => array(),
		'usage'                  => array(),
		'status'                 => 'budget_exceeded',
		'budget'                 => 'turns',
		'completed'              => false,
		'max_turns_reached'      => true,
	),
	array(
		'completed'         => false,
		'max_turns_reached' => true,
		'warning'           => 'Maximum conversation turns reached.',
	)
);
$budget_metadata = datamachine_conversation_metadata( $budget_result );

assert_runner_request( 'budget_exceeded' === ( $budget_result['status'] ?? null ), 'budget exceeded remains the canonical Agents API status' );
assert_runner_request( true === ( $budget_metadata['max_turns_reached'] ?? null ), 'budget exceeded sets namespaced max-turn diagnostic' );
assert_runner_request( false === ( $budget_metadata['completed'] ?? true ), 'budget exceeded marks namespaced completion false' );
assert_runner_request( ! array_key_exists( 'max_turns_reached', $budget_result ), 'budget exceeded omits legacy max_turns_reached from top level' );

// 3. Mid-conversation provider failures preserve the completed-turn context.
$mid_failure_dispatch_count = 0;
$mid_failure_transcript     = new RunnerRequestSmokeTranscriptPersister();
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$mid_failure_dispatch_count ) {
		++$mid_failure_dispatch_count;

		if ( 1 === $mid_failure_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'name'       => 'runner_request_smoke_tool',
							'parameters' => array( 'name' => 'Ada' ),
						),
					),
					'usage'      => array(
						'prompt_tokens'     => 4,
						'completion_tokens' => 5,
						'total_tokens'      => 9,
					),
				),
			);
		}

		throw new RuntimeException( 'provider offline after tool' );
	}
);

$mid_failure_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'use a tool then continue' ) ),
	array(
		'runner_request_smoke_tool' => array(
			'name'        => 'runner_request_smoke_tool',
			'description' => 'Runner request smoke tool',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RunnerRequestSmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'pipeline' ),
	array(
		'transcript_persister' => $mid_failure_transcript,
	),
	3
);

assert_runner_request( 2 === $mid_failure_dispatch_count, 'mid-conversation failure reaches the second provider request' );
assert_runner_request( str_contains( (string) ( $mid_failure_result['error'] ?? '' ), 'provider offline after tool' ), 'mid-conversation failure preserves provider error' );
assert_runner_request( 2 === ( $mid_failure_result['turn_count'] ?? null ), 'mid-conversation failure preserves latest turn count' );
assert_runner_request( 1 === count( $mid_failure_result['tool_execution_results'] ?? array() ), 'mid-conversation failure preserves completed tool results' );
assert_runner_request( str_contains( wp_json_encode( $mid_failure_result['messages'] ?? array() ), 'runner_request_smoke_tool' ), 'mid-conversation failure preserves accumulated messages' );
assert_runner_request( 'provider_error' === ( $mid_failure_result['runtime_provenance']['status']['finish_reason'] ?? null ), 'mid-conversation failure provenance records provider error' );
assert_runner_request( 'runner-request-smoke-failure-session' === ( $mid_failure_result['transcript_session_id'] ?? null ), 'mid-conversation failure returns refreshed transcript session id' );
$last_failure_transcript_call = end( $mid_failure_transcript->calls );
assert_runner_request( ! empty( $mid_failure_transcript->calls ), 'mid-conversation failure persists refreshed failure transcript' );
assert_runner_request( str_contains( (string) ( $last_failure_transcript_call['result']['error'] ?? '' ), 'provider offline after tool' ), 'failure transcript result includes provider error' );
assert_runner_request( 2 === ( $last_failure_transcript_call['result']['turn_count'] ?? null ), 'failure transcript result includes latest turn count' );

// 4. Sandbox/pipeline runs expose parsed function calls even after the final no-tool turn.
$sandbox_dispatch_count = 0;
$sandbox_requests       = array();
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function ( array $request_body ) use ( &$sandbox_dispatch_count, &$sandbox_requests ) {
		++$sandbox_dispatch_count;
		$sandbox_requests[] = $request_body;

		if ( 1 === $sandbox_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'id'         => 'sandbox-call-1',
							'name'       => 'workspace_read',
							'parameters' => array( 'path' => 'README.md' ),
						),
					),
					'usage'      => array(
						'prompt_tokens'     => 7,
						'completion_tokens' => 8,
						'total_tokens'      => 15,
					),
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'sandbox tool complete',
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

$sandbox_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'read the sandbox README' ) ),
	array(
		'workspace_read' => array(
			'name'        => 'workspace_read',
			'description' => 'Read a file from the sandbox workspace.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'path' => array( 'type' => 'string' ),
				),
				'required'   => array( 'path' ),
			),
			'class'       => SandboxPipelineSmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'sandbox', 'pipeline' ),
	array(),
	3
);
$sandbox_metadata = datamachine_conversation_metadata( $sandbox_result );

assert_runner_request( 2 === $sandbox_dispatch_count, 'sandbox/pipeline function call returns to provider for final answer' );
assert_runner_request( isset( $sandbox_requests[0]['tools']['workspace_read'] ), 'sandbox/pipeline request sends workspace tool declaration' );
assert_runner_request( 'sandbox tool complete' === ( $sandbox_result['final_content'] ?? null ), 'sandbox/pipeline run preserves final no-tool answer' );
assert_runner_request( array() === ( $sandbox_metadata['last_tool_calls'] ?? null ), 'last_tool_calls reflects the final no-tool turn' );
assert_runner_request( 1 === count( $sandbox_metadata['tool_calls'] ?? array() ), 'tool_calls preserves parsed calls from earlier turns' );
assert_runner_request( 'workspace_read' === ( $sandbox_metadata['tool_calls'][0]['name'] ?? null ), 'tool_calls records the parsed workspace tool name' );
assert_runner_request( array( 'path' => 'README.md' ) === ( $sandbox_metadata['tool_calls'][0]['parameters'] ?? null ), 'tool_calls records parsed workspace tool parameters' );
assert_runner_request( ! array_key_exists( 'tool_calls', $sandbox_result ), 'tool_calls are namespaced outside the Agents API result top level' );
assert_runner_request( 1 === count( $sandbox_result['tool_execution_results'] ?? array() ), 'sandbox/pipeline function call executes a workspace tool' );
assert_runner_request( 'README.md' === ( $sandbox_result['tool_execution_results'][0]['result']['result']['path'] ?? null ), 'sandbox/pipeline tool execution receives parsed parameters' );

// 4a. Textual AI ACTION prose is not accepted as a final answer.
$text_action_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$text_action_dispatch_count ) {
		++$text_action_dispatch_count;

		if ( 1 === $text_action_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content' => 'AI ACTION (Turn 4): Executing Workspace Read with parameters: repo: agents-api, path: README.md',
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content' => 'text action recovered',
			),
		);
	}
);

$text_action_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'inspect the README' ) ),
	array(),
	'openai',
	'gpt-smoke',
	array( 'sandbox', 'chat' ),
	array(),
	3
);

assert_runner_request( 2 === $text_action_dispatch_count, 'textual AI ACTION response receives a continuation turn' );
assert_runner_request( 'text action recovered' === ( $text_action_result['final_content'] ?? null ), 'textual AI ACTION run preserves recovered final answer' );
assert_runner_request( str_contains( wp_json_encode( $text_action_result['messages'] ?? array() ), 'did not make a valid tool call' ), 'textual AI ACTION run adds a corrective nudge' );

// 4b. Silent max-turn exhaustion after a tool turn is incomplete, not a final answer.
$max_turn_tool_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$max_turn_tool_dispatch_count ) {
		++$max_turn_tool_dispatch_count;

		return array(
			'success' => true,
			'data'    => array(
				'content'    => '',
				'tool_calls' => array(
					array(
						'id'         => 'max-turn-call-1',
						'name'       => 'workspace_read',
						'parameters' => array( 'path' => 'README.md' ),
					),
				),
			),
		);
	}
);

$max_turn_tool_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'read the sandbox README and continue' ) ),
	array(
		'workspace_read' => array(
			'name'        => 'workspace_read',
			'description' => 'Read a file from the sandbox workspace.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'path' => array( 'type' => 'string' ),
				),
				'required'   => array( 'path' ),
			),
			'class'       => SandboxPipelineSmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'sandbox', 'chat' ),
	array(),
	1
);
$max_turn_tool_metadata = datamachine_conversation_metadata( $max_turn_tool_result );

assert_runner_request( 1 === $max_turn_tool_dispatch_count, 'max-turn tool run stops after the allowed provider turn' );
assert_runner_request( false === ( $max_turn_tool_metadata['completed'] ?? true ), 'max-turn tool run is not marked completed' );
assert_runner_request( true === ( $max_turn_tool_metadata['max_turns_reached'] ?? null ), 'max-turn tool run sets max-turn diagnostic' );
assert_runner_request( 1 === count( $max_turn_tool_result['tool_execution_results'] ?? array() ), 'max-turn tool run preserves executed tool result' );

// 4c. Sandbox/pipeline runs also execute XML tool calls emitted as text.
$xml_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$xml_dispatch_count ) {
		++$xml_dispatch_count;

		if ( 1 === $xml_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content' => 'I will inspect first.' . "\n\n" . '<function_calls><invoke name="workspace_read"><parameter name="path">README.md</parameter></invoke></function_calls>',
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content' => 'xml sandbox tool complete',
			),
		);
	}
);

$xml_sandbox_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'read the sandbox README using XML tool syntax' ) ),
	array(
		'workspace_read' => array(
			'name'        => 'workspace_read',
			'description' => 'Read a file from the sandbox workspace.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'path' => array( 'type' => 'string' ),
				),
				'required'   => array( 'path' ),
			),
			'class'       => SandboxPipelineSmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'sandbox', 'pipeline' ),
	array(),
	3
);
$xml_sandbox_metadata = datamachine_conversation_metadata( $xml_sandbox_result );

assert_runner_request( 2 === $xml_dispatch_count, 'sandbox/pipeline XML tool call returns to provider for final answer' );
assert_runner_request( 'xml sandbox tool complete' === ( $xml_sandbox_result['final_content'] ?? null ), 'sandbox/pipeline XML tool call preserves final answer' );
assert_runner_request( 1 === count( $xml_sandbox_metadata['tool_calls'] ?? array() ), 'sandbox/pipeline XML tool call is parsed' );
assert_runner_request( 'workspace_read' === ( $xml_sandbox_metadata['tool_calls'][0]['name'] ?? null ), 'sandbox/pipeline XML tool name is parsed' );
assert_runner_request( array( 'path' => 'README.md' ) === ( $xml_sandbox_metadata['tool_calls'][0]['parameters'] ?? null ), 'sandbox/pipeline XML tool parameters are parsed' );
assert_runner_request( 1 === count( $xml_sandbox_result['tool_execution_results'] ?? array() ), 'sandbox/pipeline XML tool call executes a workspace tool' );

// 4c. Sandbox/pipeline runs also execute JSON tool calls emitted in <tool_call> text envelopes.
$json_tool_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$json_tool_dispatch_count ) {
		++$json_tool_dispatch_count;

		if ( 1 === $json_tool_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content' => '<tool_call>{"name":"workspace_read","arguments":{"path":"README.md"}}</tool_call>',
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content' => 'json tool sandbox complete',
			),
		);
	}
);

$json_tool_sandbox_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'read the sandbox README using JSON tool syntax' ) ),
	array(
		'workspace_read' => array(
			'name'        => 'workspace_read',
			'description' => 'Read a file from the sandbox workspace.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'path' => array( 'type' => 'string' ),
				),
				'required'   => array( 'path' ),
			),
			'class'       => SandboxPipelineSmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'sandbox', 'pipeline' ),
	array(),
	3
);
$json_tool_sandbox_metadata = datamachine_conversation_metadata( $json_tool_sandbox_result );

assert_runner_request( 2 === $json_tool_dispatch_count, 'sandbox/pipeline JSON text tool call returns to provider for final answer' );
assert_runner_request( 'json tool sandbox complete' === ( $json_tool_sandbox_result['final_content'] ?? null ), 'sandbox/pipeline JSON text tool call preserves final answer' );
assert_runner_request( 1 === count( $json_tool_sandbox_metadata['tool_calls'] ?? array() ), 'sandbox/pipeline JSON text tool call is parsed' );
assert_runner_request( 'workspace_read' === ( $json_tool_sandbox_metadata['tool_calls'][0]['name'] ?? null ), 'sandbox/pipeline JSON text tool name is parsed' );
assert_runner_request( array( 'path' => 'README.md' ) === ( $json_tool_sandbox_metadata['tool_calls'][0]['parameters'] ?? null ), 'sandbox/pipeline JSON text tool parameters are parsed' );
assert_runner_request( 1 === count( $json_tool_sandbox_result['tool_execution_results'] ?? array() ), 'sandbox/pipeline JSON text tool call executes a workspace tool' );

// 4d. Sandbox/pipeline runs also execute fenced JSON tool_calls arrays emitted as text.
$json_array_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$json_array_dispatch_count ) {
		++$json_array_dispatch_count;

		if ( 1 === $json_array_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content' => "I will inspect first.\n```json\n{\"tool_calls\":[{\"id\":\"read-call\",\"type\":\"function\",\"function\":{\"name\":\"workspace_read\",\"arguments\":{\"path\":\"README.md\"}}}]}\n```",
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content' => 'json array sandbox complete',
			),
		);
	}
);

$json_array_sandbox_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'read the sandbox README using fenced JSON tool_calls syntax' ) ),
	array(
		'workspace_read' => array(
			'name'        => 'workspace_read',
			'description' => 'Read a file from the sandbox workspace.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'path' => array( 'type' => 'string' ),
				),
				'required'   => array( 'path' ),
			),
			'class'       => SandboxPipelineSmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'sandbox', 'pipeline' ),
	array(),
	3
);
$json_array_sandbox_metadata = datamachine_conversation_metadata( $json_array_sandbox_result );

assert_runner_request( 2 === $json_array_dispatch_count, 'sandbox/pipeline fenced JSON tool_calls returns to provider for final answer' );
assert_runner_request( 'json array sandbox complete' === ( $json_array_sandbox_result['final_content'] ?? null ), 'sandbox/pipeline fenced JSON tool_calls preserves final answer' );
assert_runner_request( 1 === count( $json_array_sandbox_metadata['tool_calls'] ?? array() ), 'sandbox/pipeline fenced JSON tool_calls is parsed' );
assert_runner_request( 'workspace_read' === ( $json_array_sandbox_metadata['tool_calls'][0]['name'] ?? null ), 'sandbox/pipeline fenced JSON tool_calls name is parsed' );
assert_runner_request( array( 'path' => 'README.md' ) === ( $json_array_sandbox_metadata['tool_calls'][0]['parameters'] ?? null ), 'sandbox/pipeline fenced JSON tool_calls parameters are parsed' );
assert_runner_request( 1 === count( $json_array_sandbox_result['tool_execution_results'] ?? array() ), 'sandbox/pipeline fenced JSON tool_calls executes a workspace tool' );

// 5. Client runtime tools are fulfilled by the transport callback, not PHP ToolExecutor.
$runtime_dispatch_count = 0;
$runtime_requests       = array();
$runtime_sink           = new RunnerRequestSmokeSink();
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$runtime_dispatch_count ) {
		++$runtime_dispatch_count;

		if ( 1 === $runtime_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'id'         => 'client-call-1',
							'name'       => 'client/select_block',
							'parameters' => array( 'client_id' => 'block-1' ),
						),
					),
					'usage'      => array(
						'prompt_tokens'     => 3,
						'completion_tokens' => 4,
						'total_tokens'      => 7,
					),
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'runtime callback complete',
				'tool_calls' => array(),
				'usage'      => array(
					'prompt_tokens'     => 5,
					'completion_tokens' => 6,
					'total_tokens'      => 11,
				),
			),
		);
	}
);
add_filter(
	'datamachine_runtime_tool_result',
	function ( $result, array $request ) use ( &$runtime_requests ) {
		$runtime_requests[] = $request;

		if ( 'client/select_block' !== ( $request['tool_name'] ?? '' ) ) {
			return $result;
		}

		return array(
			'success'     => true,
			'selected_id' => $request['parameters']['client_id'] ?? '',
			'call_id'     => $request['call_id'] ?? '',
		);
	},
	10,
	2
);

$runtime_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'select the current block' ) ),
	array(
		'client/select_block' => array(
			'name'              => 'client/select_block',
			'description'       => 'Select a block in the active editor.',
			'parameters'        => array(
				'type'       => 'object',
				'properties' => array(
					'client_id' => array( 'type' => 'string' ),
				),
			),
			'executor'          => 'client',
			'external_executor' => true,
			'runtime_tool'      => true,
		),
	),
	'openai',
	'gpt-smoke',
	array( 'chat' ),
	array(
		'event_sink' => $runtime_sink,
	),
	3
);

assert_runner_request( 2 === $runtime_dispatch_count, 'runtime tool callback returns to the provider for the follow-up turn' );
assert_runner_request( 'runtime callback complete' === ( $runtime_result['final_content'] ?? null ), 'runtime tool callback preserves final content' );
assert_runner_request( 1 === count( $runtime_result['tool_execution_results'] ?? array() ), 'runtime tool callback records one tool result' );
assert_runner_request( true === ( $runtime_result['tool_execution_results'][0]['result']['success'] ?? null ), 'runtime tool callback result succeeds' );
assert_runner_request( 'client' === ( $runtime_result['tool_execution_results'][0]['result']['executor'] ?? null ), 'runtime tool result preserves client executor marker' );
assert_runner_request( 'block-1' === ( $runtime_result['tool_execution_results'][0]['result']['selected_id'] ?? null ), 'runtime tool result includes transport-provided data' );
assert_runner_request( 'client-call-1' === ( $runtime_requests[0]['call_id'] ?? null ), 'runtime tool callback receives the model call id' );
assert_runner_request( str_contains( wp_json_encode( $runtime_sink->events ), 'runtime_tool_call' ), 'runtime tool callback emits a runtime_tool_call event' );
assert_runner_request( str_contains( wp_json_encode( $runtime_sink->events ), 'runtime_tool_result' ), 'runtime tool callback emits a runtime_tool_result event' );

if ( runner_request_failure_count() > 0 ) {
	exit( 1 );
}

echo "\nAll agent conversation runner request smoke tests passed.\n";
