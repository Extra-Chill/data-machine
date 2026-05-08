<?php
/**
 * Smoke tests for the agent conversation runtime policy boundary.
 *
 * Run with: php tests/agent-conversation-runtime-policy-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$GLOBALS['datamachine_runtime_policy_filters'] = array();
$GLOBALS['datamachine_runtime_policy_logs']    = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['datamachine_runtime_policy_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$callbacks = $GLOBALS['datamachine_runtime_policy_filters'][ $hook ] ?? array();
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
		$GLOBALS['datamachine_runtime_policy_logs'][] = array_merge( array( $hook ), $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) ?? '' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $_option, $default_value = false ) {
		return $default_value;
	}
}

require_once __DIR__ . '/bootstrap-unit.php';
require_once __DIR__ . '/Unit/Support/WpAiClientTestDoubles.php';

use AgentsAPI\AI\WP_Agent_Conversation_Completion_Decision;
use AgentsAPI\AI\WP_Agent_Conversation_Completion_Policy;
use AgentsAPI\AI\WP_Agent_Conversation_Request;
use AgentsAPI\AI\WP_Agent_Transcript_Persister;
use DataMachine\Engine\AI\DataMachineHandlerCompletionPolicy;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;

use function DataMachine\Engine\AI\datamachine_run_conversation;

class RuntimePolicySmokeCompletionPolicy implements WP_Agent_Conversation_Completion_Policy {
	public array $calls = array();

	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, array $runtime_context, int $turn_count ): WP_Agent_Conversation_Completion_Decision {
		$mode = (string) ( $runtime_context['mode'] ?? '' );
		$this->calls[] = compact( 'tool_name', 'tool_def', 'tool_result', 'mode', 'turn_count' );

		return WP_Agent_Conversation_Completion_Decision::complete(
			'RuntimePolicySmoke: custom policy completed',
			array( 'tool_name' => $tool_name )
		);
	}
}

class RuntimePolicySmokeTranscriptPersister implements WP_Agent_Transcript_Persister {
	public array $calls = array();

	public function persist( array $messages, WP_Agent_Conversation_Request $request, array $result ): string {
		$metadata = $request->metadata();
		$provider = (string) ( $metadata['provider'] ?? '' );
		$model    = (string) ( $metadata['model'] ?? '' );
		$payload  = $request->runtimeContext();
		$this->calls[] = compact( 'messages', 'provider', 'model', 'payload', 'result' );

		return 'runtime-policy-transcript';
	}
}

class RuntimePolicySmokeTool {
	public function execute( array $parameters, array $tool_def ): array {
		unset( $tool_def );

		return array(
			'success' => true,
			'data'    => array(
				'message' => 'tool handled ' . ( $parameters['name'] ?? 'unknown' ),
			),
		);
	}
}

$failures   = array();
$assertions = 0;

function assert_runtime_policy( bool $condition, string $label ): void {
	global $failures, $assertions;

	++$assertions;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	echo "FAIL: {$label}\n";
	$failures[] = $label;
}

function runtime_policy_logs_contain( string $message ): bool {
	foreach ( $GLOBALS['datamachine_runtime_policy_logs'] as $entry ) {
		if ( 'datamachine_log' === ( $entry[0] ?? '' ) && $message === ( $entry[2] ?? '' ) ) {
			return true;
		}
	}

	return false;
}

function runtime_policy_failure_count(): int {
	global $failures;

	return count( $failures );
}

// 1. Data Machine's handler policy is a runtime collaborator, not inline loop state.
$handler_policy = new DataMachineHandlerCompletionPolicy( array( 'wordpress_publish', 'pinterest_publish' ) );
$first_decision = $handler_policy->recordToolResult(
	'publish_wordpress',
	array( 'handler' => 'wordpress_publish' ),
	array( 'success' => true ),
	array( 'mode' => 'pipeline' ),
	1
);
$second_decision = $handler_policy->recordToolResult(
	'publish_pinterest',
	array( 'handler' => 'pinterest_publish' ),
	array( 'success' => true ),
	array( 'mode' => 'pipeline' ),
	2
);

assert_runtime_policy( ! $first_decision->isComplete(), 'handler policy waits for remaining configured handlers' );
assert_runtime_policy( array( 'pinterest_publish' ) === ( $first_decision->context()['remaining_handlers'] ?? null ), 'handler policy reports remaining handlers' );
assert_runtime_policy( $second_decision->isComplete(), 'handler policy completes after all configured handlers fire' );
assert_runtime_policy( array( 'wordpress_publish', 'pinterest_publish' ) === array_values( $second_decision->context()['executed_handlers'] ?? array() ), 'handler policy reports executed handlers' );

// 2. Injected completion/transcript collaborators steer the loop without leaking into provider payloads.
$natural_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$natural_dispatch_count ) {
		++$natural_dispatch_count;

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'Natural answer with no tool calls.',
				'tool_calls' => array(),
			),
		);
	}
);

$natural_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'answer naturally' ) ),
	array(),
	'openai',
	'gpt-smoke',
	'chat',
	array(),
	5
);

assert_runtime_policy( 1 === $natural_dispatch_count, 'default no-tool response completes naturally' );
assert_runtime_policy( ! empty( $natural_result['completed'] ), 'default natural completion result is completed' );

$satisfied_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$satisfied_dispatch_count ) {
		++$satisfied_dispatch_count;

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'The required engine data is already present.',
				'tool_calls' => array(),
			),
		);
	}
);

$satisfied_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'finish once final_url exists' ) ),
	array(),
	'openai',
	'gpt-smoke',
	'chat',
	array(
		'completion_assertions' => array(
			'required_engine_data_keys' => array( 'final_url' ),
		),
		'engine_data'           => array(
			'final_url' => 'https://example.test/post',
		),
	),
	5
);

assert_runtime_policy( 1 === $satisfied_dispatch_count, 'satisfied natural assertion completes without nudge' );
assert_runtime_policy( ! empty( $satisfied_result['completed'] ), 'satisfied natural assertion result is completed' );

$nudge_dispatch_count = 0;
$nudge_second_request = null;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function ( array $request_body ) use ( &$nudge_dispatch_count, &$nudge_second_request ) {
		++$nudge_dispatch_count;
		if ( 2 === $nudge_dispatch_count ) {
			$nudge_second_request = $request_body;
		}

		if ( 1 === $nudge_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => 'I am done prematurely.',
					'tool_calls' => array(),
				),
			);
		}

		if ( 2 === $nudge_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'name'       => 'runtime_policy_tool',
							'parameters' => array( 'name' => 'Grace' ),
						),
					),
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'Now complete after the required tool.',
				'tool_calls' => array(),
			),
		);
	}
);

$nudge_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'use the runtime policy tool before finishing' ) ),
	array(
		'runtime_policy_tool' => array(
			'name'        => 'runtime_policy_tool',
			'description' => 'Runtime policy smoke tool',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	'chat',
	array(
		'completion_assertions' => array(
			'required_tool_names' => array( 'runtime_policy_tool' ),
		),
	),
	5
);

assert_runtime_policy( 3 === $nudge_dispatch_count, 'missing natural assertion nudges and keeps loop running' );
assert_runtime_policy( str_contains( wp_json_encode( $nudge_second_request ), 'completion signals are still missing' ), 'nudge is appended before retry request' );
assert_runtime_policy( 1 === count( $nudge_result['tool_execution_results'] ?? array() ), 'nudged loop captures required tool result' );

$dispatch_count     = 0;
$provider_context   = null;
$completion_policy  = new RuntimePolicySmokeCompletionPolicy();
$transcript_policy  = new RuntimePolicySmokeTranscriptPersister();

WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function ( array $request_body ) use ( &$dispatch_count, &$provider_context ) {
		++$dispatch_count;
		$provider_context = $request_body;

		return array(
			'success' => true,
			'data'    => array(
				'content'    => '',
				'tool_calls' => array(
					array(
						'name'       => 'runtime_policy_tool',
						'parameters' => array( 'name' => 'Ada' ),
					),
				),
				'usage'      => array( 'prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5 ),
			),
		);
	}
);

$result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'run one tool' ) ),
	array(
		'runtime_policy_tool' => array(
			'name'        => 'runtime_policy_tool',
			'description' => 'Runtime policy smoke tool',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	'chat',
	array(
		'completion_policy'    => $completion_policy,
		'transcript_persister' => $transcript_policy,
		'job_id'               => 1588,
	),
	5
);

assert_runtime_policy( 1 === $dispatch_count, 'custom completion policy stopped the loop after one provider request' );
assert_runtime_policy( 1 === count( $result['tool_execution_results'] ?? array() ), 'custom completion policy returned one tool result' );
assert_runtime_policy( 1588 === ( $transcript_policy->calls[0]['payload']['job_id'] ?? null ), 'custom transcript persister receives runtime context' );
assert_runtime_policy( 1 === count( $completion_policy->calls ), 'custom completion policy received one tool result' );
assert_runtime_policy( 'runtime_policy_tool' === ( $completion_policy->calls[0]['tool_name'] ?? null ), 'custom completion policy receives tool name' );
assert_runtime_policy( 1 === count( $transcript_policy->calls ), 'custom transcript persister was called once on success' );
assert_runtime_policy( ! str_contains( wp_json_encode( $provider_context ), 'completion_policy' ), 'completion policy object is stripped before provider dispatch' );
assert_runtime_policy( ! str_contains( wp_json_encode( $provider_context ), 'transcript_persister' ), 'transcript persister object is stripped before provider dispatch' );
assert_runtime_policy( runtime_policy_logs_contain( 'RuntimePolicySmoke: custom policy completed' ), 'completion policy diagnostic message is logged by the adapter' );

if ( runtime_policy_failure_count() > 0 ) {
	exit( 1 );
}

echo "\nAgent conversation runtime policy smoke passed ({$assertions} assertions).\n";
