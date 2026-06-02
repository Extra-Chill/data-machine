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
$GLOBALS['datamachine_runtime_engine_merges']  = array();

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

if ( ! function_exists( 'datamachine_merge_engine_data' ) ) {
	function datamachine_merge_engine_data( int $job_id, array $data ): bool {
		$GLOBALS['datamachine_runtime_engine_merges'][ $job_id ][] = $data;
		return true;
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

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $text ) ) ?? '' );
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
use DataMachine\Engine\AI\LoopEventSinkInterface;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;

use function DataMachine\Engine\AI\datamachine_run_conversation;
use function DataMachine\Engine\AI\datamachine_conversation_metadata;

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

class RuntimePolicySmokeEventSink implements LoopEventSinkInterface {
	public array $events = array();

	public function emit( string $event, array $payload = array() ): void {
		$this->events[] = compact( 'event', 'payload' );
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

class RuntimePolicySmokeMaybeFailTool {
	public function execute( array $parameters, array $tool_def ): array {
		unset( $tool_def );

		if ( ! empty( $parameters['fail'] ) ) {
			return array(
				'success' => false,
				'error'   => 'requested failure',
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'message' => 'required tool succeeded',
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

function runtime_policy_action_count( string $hook ): int {
	$count = 0;
	foreach ( $GLOBALS['datamachine_runtime_policy_logs'] as $entry ) {
		if ( $hook === ( $entry[0] ?? '' ) ) {
			++$count;
		}
	}

	return $count;
}

function runtime_policy_first_event_payload( RuntimePolicySmokeEventSink $sink, string $event ): array {
	foreach ( $sink->events as $entry ) {
		if ( $event === ( $entry['event'] ?? '' ) ) {
			return is_array( $entry['payload'] ?? null ) ? $entry['payload'] : array();
		}
	}

	return array();
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

$natural_before_handler_policy   = new DataMachineHandlerCompletionPolicy( array( 'wiki_upsert' ) );
$natural_before_handler_decision = $natural_before_handler_policy->recordNaturalCompletion(
	array( array( 'role' => 'user', 'content' => 'write the wiki article' ) ),
	'I summarized the source.',
	array( 'mode' => 'pipeline' ),
	1
);
assert_runtime_policy( ! $natural_before_handler_decision->isComplete(), 'handler policy rejects natural completion before configured handlers fire' );
assert_runtime_policy( array( 'wiki_upsert' ) === ( $natural_before_handler_decision->context()['remaining_handlers'] ?? null ), 'handler policy reports remaining handlers on natural completion' );

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
	array( 'chat' ),
	array(),
	5
);
$natural_metadata = datamachine_conversation_metadata( $natural_result );

assert_runtime_policy( 1 === $natural_dispatch_count, 'default no-tool response completes naturally' );
assert_runtime_policy( ! empty( $natural_metadata['completed'] ), 'default natural completion result is completed' );

$satisfied_dispatch_count = 0;
$satisfied_nudge_actions  = runtime_policy_action_count( 'datamachine_ai_completion_nudge_added' );
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
	array( 'chat' ),
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
$satisfied_metadata = datamachine_conversation_metadata( $satisfied_result );

assert_runtime_policy( 1 === $satisfied_dispatch_count, 'satisfied natural assertion completes without nudge' );
assert_runtime_policy( ! empty( $satisfied_metadata['completed'] ), 'satisfied natural assertion result is completed' );
assert_runtime_policy( ! isset( $satisfied_metadata['completion_nudge_count'] ), 'satisfied natural assertion has no nudge diagnostics' );
assert_runtime_policy( $satisfied_nudge_actions === runtime_policy_action_count( 'datamachine_ai_completion_nudge_added' ), 'satisfied natural assertion emits no nudge action' );

$nudge_dispatch_count = 0;
$nudge_second_request = null;
$nudge_event_sink     = new RuntimePolicySmokeEventSink();
$nudge_transcript     = new RuntimePolicySmokeTranscriptPersister();
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
	array( 'chat' ),
	array(
		'event_sink'            => $nudge_event_sink,
		'transcript_persister'  => $nudge_transcript,
		'job_id'                => 4242,
		'completion_assertions' => array(
			'required_tool_names' => array( 'runtime_policy_tool' ),
		),
	),
	5
);
$nudge_metadata = datamachine_conversation_metadata( $nudge_result );

assert_runtime_policy( 3 === $nudge_dispatch_count, 'missing natural assertion nudges and keeps loop running' );
assert_runtime_policy( str_contains( wp_json_encode( $nudge_second_request ), 'The task is not complete yet' ), 'natural nudge is appended before retry request' );
assert_runtime_policy( ! str_contains( wp_json_encode( $nudge_second_request ), 'completion signals are still missing' ), 'model-facing nudge omits assertion diagnostics phrasing' );
assert_runtime_policy( 1 === count( $nudge_result['tool_execution_results'] ?? array() ), 'nudged loop captures required tool result' );
assert_runtime_policy( 1 === ( $nudge_metadata['completion_nudge_count'] ?? 0 ), 'nudged loop returns nudge count diagnostic' );
assert_runtime_policy( array( 'runtime_policy_tool' ) === ( $nudge_metadata['completion_assertions_satisfied']['tool_names'] ?? null ), 'nudged loop returns final satisfied assertion diagnostic' );
assert_runtime_policy( str_contains( $nudge_metadata['completion_nudge'] ?? '', 'The task is not complete yet' ), 'nudged loop returns latest natural nudge message' );
assert_runtime_policy( ! array_key_exists( 'completion_nudge_count', $nudge_result ), 'nudged loop keeps Data Machine diagnostics out of the Agents API result top level' );
assert_runtime_policy( 1 === count( array_filter( $nudge_event_sink->events, fn( $entry ) => 'completion_nudge_added' === ( $entry['event'] ?? '' ) ) ), 'nudged loop emits completion_nudge_added event' );
$nudge_event_payload = runtime_policy_first_event_payload( $nudge_event_sink, 'completion_nudge_added' );
assert_runtime_policy( array( 'runtime_policy_tool' ) === ( $nudge_event_payload['completion_assertions_missing']['tool_names'] ?? null ), 'nudge event includes missing assertion context' );
assert_runtime_policy( str_contains( wp_json_encode( $nudge_transcript->calls[0]['messages'] ?? array() ), 'The task is not complete yet' ), 'transcript messages include natural nudge message' );
assert_runtime_policy( 1 === ( $GLOBALS['datamachine_runtime_engine_merges'][4242][0]['completion_nudge_count'] ?? 0 ), 'job engine_data merge includes nudge count' );
assert_runtime_policy( array( 'runtime_policy_tool' ) === ( $GLOBALS['datamachine_runtime_engine_merges'][4242][0]['completion_assertions_missing']['tool_names'] ?? null ), 'job engine_data merge includes missing assertions' );

$minimum_count_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$minimum_count_dispatch_count ) {
		++$minimum_count_dispatch_count;

		if ( 1 === $minimum_count_dispatch_count || 3 === $minimum_count_dispatch_count || 5 === $minimum_count_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => 5 === $minimum_count_dispatch_count ? 'Now complete after enough tool calls.' : 'I am done too early.',
					'tool_calls' => array(),
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content'    => '',
				'tool_calls' => array(
					array(
						'name'       => 'runtime_policy_tool',
						'parameters' => array( 'name' => 'Counted call ' . $minimum_count_dispatch_count ),
					),
				),
			),
		);
	}
);

$minimum_count_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'use the runtime policy tool twice before finishing' ) ),
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
	array( 'chat' ),
	array(
		'completion_assertions' => array(
			'minimum_successful_tool_counts' => array( 'runtime_policy_tool' => 2 ),
		),
	),
	6
);
$minimum_count_metadata = datamachine_conversation_metadata( $minimum_count_result );

assert_runtime_policy( 5 === $minimum_count_dispatch_count, 'minimum successful tool count nudges until enough calls run' );
assert_runtime_policy( 2 === count( $minimum_count_result['tool_execution_results'] ?? array() ), 'minimum successful tool count captures both tool results' );
assert_runtime_policy( array( 'runtime_policy_tool>=2' ) === ( $minimum_count_metadata['completion_assertions_satisfied']['tool_counts'] ?? null ), 'minimum successful tool count reports satisfied count assertion' );
assert_runtime_policy( str_contains( $minimum_count_metadata['completion_nudge'] ?? '', 'The task is not complete yet' ), 'minimum successful tool count returns natural nudge' );
assert_runtime_policy( ! str_contains( $minimum_count_metadata['completion_nudge'] ?? '', 'runtime_policy_tool' ), 'minimum successful tool count nudge omits assertion tool name' );

$duplicate_dispatch_count = 0;
$duplicate_transcript     = new RuntimePolicySmokeTranscriptPersister();
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$duplicate_dispatch_count ) {
		++$duplicate_dispatch_count;

		if ( 1 === $duplicate_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => 'I am done prematurely.',
					'tool_calls' => array(),
				),
			);
		}

		if ( in_array( $duplicate_dispatch_count, array( 2, 3 ), true ) ) {
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
				'content'    => '',
				'tool_calls' => array(
					array(
						'name'       => 'second_policy_tool',
						'parameters' => array( 'name' => 'Hopper' ),
					),
				),
			),
		);
	}
);

$duplicate_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'recover from duplicate tool call while completing assertions' ) ),
	array(
		'runtime_policy_tool' => array(
			'name'        => 'runtime_policy_tool',
			'description' => 'Runtime policy smoke tool',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
		'second_policy_tool'  => array(
			'name'        => 'second_policy_tool',
			'description' => 'Second runtime policy smoke tool',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'pipeline' ),
	array(
		'transcript_persister'  => $duplicate_transcript,
		'completion_assertions' => array(
			'required_tool_names' => array( 'runtime_policy_tool', 'second_policy_tool' ),
		),
	),
	6
);

assert_runtime_policy( $duplicate_dispatch_count >= 4, 'duplicate tool rejection keeps loop running for correction' );
assert_runtime_policy( 2 === count( $duplicate_result['tool_execution_results'] ?? array() ), 'duplicate recovery result includes only executed tool calls' );
assert_runtime_policy( 'second_policy_tool' === ( $duplicate_result['tool_execution_results'][1]['tool_name'] ?? '' ), 'duplicate recovery reaches next required tool' );
assert_runtime_policy( str_contains( wp_json_encode( $duplicate_transcript->calls[0]['messages'] ?? array() ), 'DUPLICATE REJECTED' ), 'duplicate recovery transcript includes correction message' );

$orphan_retry_validation = DataMachine\Engine\AI\ConversationManager::validateToolCall(
	'runtime_policy_tool',
	array( 'name' => 'Ada' ),
	array(
		AgentsAPI\AI\WP_Agent_Message::toolCall( 'Calling runtime_policy_tool', 'runtime_policy_tool', array( 'name' => 'Ada' ), 1 ),
	),
	array( 'name' => 'runtime_policy_tool' )
);

assert_runtime_policy( false === $orphan_retry_validation['is_duplicate'], 'orphaned tool calls without successful results may be retried' );

$successful_retry_validation = DataMachine\Engine\AI\ConversationManager::validateToolCall(
	'runtime_policy_tool',
	array( 'name' => 'Ada' ),
	array(
		AgentsAPI\AI\WP_Agent_Message::toolCall( 'Calling runtime_policy_tool', 'runtime_policy_tool', array( 'name' => 'Ada' ), 1 ),
		AgentsAPI\AI\WP_Agent_Message::toolResult( 'Done', 'runtime_policy_tool', array( 'success' => true ) ),
	),
	array( 'name' => 'runtime_policy_tool' )
);

assert_runtime_policy( true === $successful_retry_validation['is_duplicate'], 'successful prior tool calls are still treated as duplicates' );

$repeatable_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$repeatable_dispatch_count ) {
		++$repeatable_dispatch_count;

		if ( in_array( $repeatable_dispatch_count, array( 1, 2 ), true ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'name'       => 'repeatable_inspection_tool',
							'parameters' => array( 'name' => 'demo@branch' ),
						),
					),
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'Done after repeatable inspection.',
				'tool_calls' => array(),
			),
		);
	}
);

$repeatable_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'inspect status twice' ) ),
	array(
		'repeatable_inspection_tool' => array(
			'name'        => 'repeatable_inspection_tool',
			'description' => 'Repeatable status smoke tool',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
			'runtime'     => array(
				'duplicate_policy' => 'repeatable',
			),
		),
	),
	'openai',
	'gpt-smoke',
	array( 'pipeline' ),
	array(),
	4
);

assert_runtime_policy( 2 === count( $repeatable_result['tool_execution_results'] ?? array() ), 'repeatable inspection tool can be called twice with identical parameters' );
assert_runtime_policy( empty( $repeatable_result['duplicate_tool_call_rejected'] ), 'repeatable inspection tool bypasses duplicate rejection' );

$inspection_nudge_dispatch_count = 0;
$inspection_nudge_transcript     = new RuntimePolicySmokeTranscriptPersister();
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$inspection_nudge_dispatch_count ) {
		++$inspection_nudge_dispatch_count;

		if ( 1 === $inspection_nudge_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'name'       => 'inspection_tool',
							'parameters' => array( 'repo' => 'demo@branch', 'path' => 'README.md' ),
						),
					),
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content'    => '',
				'tool_calls' => array(
					array(
						'name'       => 'memory_write_tool',
						'parameters' => array( 'action' => 'write' ),
					),
				),
			),
		);
	}
);

$inspection_nudge_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'read before writing memory' ) ),
	array(
		'inspection_tool'    => array(
			'name'        => 'inspection_tool',
			'description' => 'Inspection smoke tool',
			'parameters'  => array( 'repo' => array( 'type' => 'string' ), 'path' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
		'memory_write_tool'  => array(
			'name'        => 'memory_write_tool',
			'description' => 'Memory smoke tool',
			'parameters'  => array( 'action' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
			'runtime'     => array(
				'completion_signal' => 'progress',
			),
		),
	),
	'openai',
	'gpt-smoke',
	array( 'pipeline' ),
	array(
		'transcript_persister'  => $inspection_nudge_transcript,
		'completion_assertions' => array(
			'required_tool_names' => array( 'memory_write_tool' ),
		),
	),
	4
);

assert_runtime_policy( $inspection_nudge_dispatch_count >= 2, 'inspection-only turn continues without immediate completion nudge' );
assert_runtime_policy( 2 === count( $inspection_nudge_result['tool_execution_results'] ?? array() ), 'inspection-only nudge suppression still reaches required tool' );
assert_runtime_policy( ! str_contains( wp_json_encode( $inspection_nudge_transcript->calls[0]['messages'] ?? array() ), 'completion signals are still missing' ), 'inspection-only transcript avoids assertion nudge spam' );

$runtime_rule_dispatch_count = 0;
$runtime_rule_transcript     = new RuntimePolicySmokeTranscriptPersister();
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$runtime_rule_dispatch_count ) {
		++$runtime_rule_dispatch_count;

		$tool_name = match ( $runtime_rule_dispatch_count ) {
			1 => 'workspace_worktree_add',
			2, 3, 4 => 'workspace_read',
			default => 'create_github_issue',
		};

		return array(
			'success' => true,
			'data'    => array(
				'content'    => '',
				'tool_calls' => array(
					array(
						'name'       => $tool_name,
						'parameters' => array( 'name' => 'rule-smoke-' . $runtime_rule_dispatch_count ),
					),
				),
			),
		);
	}
);

$runtime_rule_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'enforce inspection budget before fallback issue' ) ),
	array(
		'workspace_worktree_add' => array(
			'name'        => 'workspace_worktree_add',
			'description' => 'Prepare a workspace',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
		'workspace_read'         => array(
			'name'        => 'workspace_read',
			'description' => 'Inspect a workspace file',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
		'create_github_issue'    => array(
			'name'        => 'create_github_issue',
			'description' => 'Open a fallback issue',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'pipeline' ),
	array(
		'transcript_persister'  => $runtime_rule_transcript,
		'completion_assertions' => array(
			'required_tool_names' => array( 'create_github_issue' ),
		),
		'tool_runtime_rules'    => array(
			array(
				'id'                  => 'inspection-budget-smoke',
				'after_tool'          => 'workspace_worktree_add',
				'limited_tools'       => array( 'workspace_read' ),
				'max_calls'           => 2,
				'then_require_one_of' => array( 'create_github_issue' ),
			),
		),
	),
	6
);

$runtime_rule_tool_names = array_map( static fn( $entry ) => (string) ( $entry['tool_name'] ?? '' ), $runtime_rule_result['tool_execution_results'] ?? array() );
assert_runtime_policy( $runtime_rule_dispatch_count >= 4, 'runtime rule rejection keeps loop running for correction' );
assert_runtime_policy( 2 === count( array_filter( $runtime_rule_tool_names, static fn( $tool_name ) => 'workspace_read' === $tool_name ) ), 'runtime rule result excludes rejected inspection call' );
assert_runtime_policy( 'create_github_issue' === ( $runtime_rule_result['tool_execution_results'][3]['tool_name'] ?? '' ), 'runtime rule allows required fallback tool after rejection' );
assert_runtime_policy( str_contains( wp_json_encode( $runtime_rule_transcript->calls[0]['messages'] ?? array() ), 'TOOL POLICY REJECTED' ), 'runtime rule transcript includes rejection message' );

$pr_prerequisite_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$pr_prerequisite_dispatch_count ) {
		++$pr_prerequisite_dispatch_count;
		$tool_name = match ( $pr_prerequisite_dispatch_count ) {
			1 => 'create_github_pull_request',
			2 => 'agent_daily_memory',
			3 => 'create_github_pull_request',
			4 => 'agent_daily_memory',
			5 => 'create_github_pull_request',
			default => '',
		};

		if ( '' === $tool_name ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => 'Done after memory and PR.',
					'tool_calls' => array(),
				),
			);
		}

		$parameters = array( 'name' => 'pr-prerequisite-smoke-' . $pr_prerequisite_dispatch_count );
		if ( 'agent_daily_memory' === $tool_name ) {
			$parameters['action'] = 2 === $pr_prerequisite_dispatch_count ? 'read' : 'write';
		}

		return array(
			'success' => true,
			'data'    => array(
				'content'    => '',
				'tool_calls' => array(
					array(
						'name'       => $tool_name,
						'parameters' => $parameters,
					),
				),
			),
		);
	}
);

$pr_prerequisite_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'write memory before PR' ) ),
	array(
		'create_github_pull_request' => array(
			'name'        => 'create_github_pull_request',
			'description' => 'Open a PR',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
		'agent_daily_memory'         => array(
			'name'        => 'agent_daily_memory',
			'description' => 'Write daily memory',
			'parameters'  => array(
				'name'   => array( 'type' => 'string' ),
				'action' => array( 'type' => 'string' ),
			),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'pipeline' ),
	array(
		'completion_assertions' => array(
			'required_tool_names' => array( 'create_github_pull_request', 'agent_daily_memory' ),
		),
		'tool_runtime_rules'    => array(
			array(
				'id'                 => 'daily-memory-before-pr-smoke',
				'type'               => 'require_prior_tool',
				'before_tool'        => 'create_github_pull_request',
				'require_prior_tool' => array( 'agent_daily_memory' ),
				'require_prior_tool_parameters' => array(
					'agent_daily_memory' => array( 'action' => 'write' ),
				),
			),
		),
	),
	8
);

$pr_prerequisite_tool_names = array_map( static fn( $entry ) => (string) ( $entry['tool_name'] ?? '' ), $pr_prerequisite_result['tool_execution_results'] ?? array() );
assert_runtime_policy( 6 === $pr_prerequisite_dispatch_count, 'prior-tool runtime rule rejects premature PR until parameter-matched memory' );
assert_runtime_policy( array( 'agent_daily_memory', 'agent_daily_memory', 'create_github_pull_request' ) === $pr_prerequisite_tool_names, 'prior-tool runtime rule excludes rejected PR attempts from tool results' );
assert_runtime_policy( str_contains( wp_json_encode( $pr_prerequisite_result['messages'] ?? array() ), 'Before using create_github_pull_request' ), 'prior-tool runtime rule explains required tool order' );

$block_until_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$block_until_dispatch_count ) {
		++$block_until_dispatch_count;
		$tool_name = match ( $block_until_dispatch_count ) {
			1, 2 => 'create_github_issue',
			3    => 'comment_github_pull_request',
			default => '',
		};

		if ( '' === $tool_name ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => 'Done after source PR callback.',
					'tool_calls' => array(),
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content'    => '',
				'tool_calls' => array(
					array(
						'name'       => $tool_name,
						'parameters' => array( 'name' => 'block-until-smoke-' . $block_until_dispatch_count ),
					),
				),
			),
		);
	}
);

$block_until_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'open one fallback issue, then comment on the source PR' ) ),
	array(
		'create_github_issue'          => array(
			'name'        => 'create_github_issue',
			'description' => 'Open a fallback issue',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
		'comment_github_pull_request' => array(
			'name'        => 'comment_github_pull_request',
			'description' => 'Comment back on the source PR',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'pipeline' ),
	array(
		'completion_assertions' => array(
			'complete_when_any'    => array(
				array(
					'name'  => 'issue_fallback_path',
					'tools' => array(
						array( 'name' => 'create_github_issue' ),
						array( 'name' => 'comment_github_pull_request' ),
					),
				),
			),
			'required_tool_names' => array( 'comment_github_pull_request' ),
		),
		'tool_runtime_rules'    => array(
			array(
				'id'            => 'issue-before-source-callback-smoke',
				'type'          => 'block_until_tool',
				'after_tool'    => 'create_github_issue',
				'blocked_tools' => array( 'create_github_issue' ),
				'until_one_of'  => array( 'comment_github_pull_request' ),
			),
		),
	),
	6
);

$block_until_tool_names = array_map( static fn( $entry ) => (string) ( $entry['tool_name'] ?? '' ), $block_until_result['tool_execution_results'] ?? array() );
assert_runtime_policy( 3 === $block_until_dispatch_count, 'block-until runtime rule lets the model recover after rejected repeat publish tool' );
assert_runtime_policy( array( 'create_github_issue', 'comment_github_pull_request' ) === $block_until_tool_names, 'block-until runtime rule excludes repeated publish tool before callback' );
assert_runtime_policy( str_contains( wp_json_encode( $block_until_result['messages'] ?? array() ), 'After create_github_issue' ), 'block-until runtime rule explains required follow-up tool' );

$satisfied_runtime_rule_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$satisfied_runtime_rule_dispatch_count ) {
		++$satisfied_runtime_rule_dispatch_count;
		$tool_name = match ( $satisfied_runtime_rule_dispatch_count ) {
			1 => 'workspace_worktree_add',
			2, 3, 4 => 'workspace_read',
			5 => 'workspace_edit',
			default => 'workspace_git_status',
		};

		return array(
			'success' => true,
			'data'    => array(
				'content'    => '',
				'tool_calls' => array(
					array(
						'name'       => $tool_name,
						'parameters' => array( 'name' => 'satisfied-rule-smoke-' . $satisfied_runtime_rule_dispatch_count ),
					),
				),
			),
		);
	}
);

$satisfied_runtime_rule_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'allow status after required edit' ) ),
	array(
		'workspace_worktree_add' => array(
			'name'        => 'workspace_worktree_add',
			'description' => 'Prepare a workspace',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
		'workspace_read'         => array(
			'name'        => 'workspace_read',
			'description' => 'Inspect a workspace file',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
		'workspace_edit'         => array(
			'name'        => 'workspace_edit',
			'description' => 'Edit a workspace file',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
		'workspace_git_status'   => array(
			'name'        => 'workspace_git_status',
			'description' => 'Inspect workspace status after editing',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'pipeline' ),
	array(
		'completion_assertions' => array(
			'required_tool_names' => array( 'workspace_git_status' ),
		),
		'tool_runtime_rules'    => array(
			array(
				'id'                  => 'inspection-budget-satisfied-smoke',
				'after_tool'          => 'workspace_worktree_add',
				'limited_tools'       => array( 'workspace_read' ),
				'max_calls'           => 2,
				'then_require_one_of' => array( 'workspace_edit' ),
			),
		),
	),
	8
);

$satisfied_runtime_rule_tool_names = array_map( static fn( $entry ) => (string) ( $entry['tool_name'] ?? '' ), $satisfied_runtime_rule_result['tool_execution_results'] ?? array() );
assert_runtime_policy( in_array( 'workspace_edit', $satisfied_runtime_rule_tool_names, true ), 'runtime rule allows required edit after inspection rejection' );
assert_runtime_policy( in_array( 'workspace_git_status', $satisfied_runtime_rule_tool_names, true ), 'runtime rule stops restricting tools after required edit' );

$assertion_policy = new DataMachineHandlerCompletionPolicy(
	array(),
	new \DataMachine\Engine\AI\DataMachineCompletionAssertions(
		array(
			'required_tool_names' => array( 'create_github_pull_request', 'comment_github_pull_request' ),
		)
	)
);
$assertion_decision = $assertion_policy->recordToolResult(
	'workspace_read',
	array( 'handler' => 'workspace_read' ),
	array( 'success' => true ),
	array( 'mode' => 'pipeline' ),
	1
);

assert_runtime_policy( ! $assertion_decision->isComplete(), 'handler policy waits when generic assertions are missing' );
assert_runtime_policy( str_contains( $assertion_decision->context()['continuation_message'] ?? '', 'The task is not complete yet' ), 'handler policy provides natural assertion continuation nudge' );

$non_handler_assertion_policy = new DataMachineHandlerCompletionPolicy(
	array(),
	new \DataMachine\Engine\AI\DataMachineCompletionAssertions(
		array(
			'required_tool_names' => array( 'create_github_pull_request', 'comment_github_pull_request' ),
		)
	)
);
$non_handler_assertion_decision = $non_handler_assertion_policy->recordToolResult(
	'workspace_worktree_add',
	array( 'name' => 'workspace_worktree_add' ),
	array( 'success' => true ),
	array( 'mode' => 'pipeline' ),
	1
);

assert_runtime_policy( ! $non_handler_assertion_decision->isComplete(), 'handler policy waits after non-handler tools when generic assertions are missing' );
assert_runtime_policy( str_contains( $non_handler_assertion_decision->context()['continuation_message'] ?? '', 'The task is not complete yet' ), 'handler policy nudges naturally after non-handler tools with missing assertions' );
assert_runtime_policy( ! str_contains( $non_handler_assertion_decision->context()['continuation_message'] ?? '', 'create_github_pull_request' ), 'handler policy natural nudge omits missing assertion tool names' );

$failed_required_tool_policy = new DataMachineHandlerCompletionPolicy(
	array(),
	new \DataMachine\Engine\AI\DataMachineCompletionAssertions(
		array(
			'required_tool_names' => array( 'workspace_edit', 'workspace_git_commit' ),
		)
	)
);
$failed_required_tool_policy->recordToolResult(
	'workspace_edit',
	array( 'name' => 'workspace_edit' ),
	array(
		'success' => false,
		'error'   => 'old_string not found in file content.',
	),
	array( 'mode' => 'pipeline' ),
	1
);
$failed_required_tool_decision = $failed_required_tool_policy->recordNaturalCompletion(
	array( array( 'role' => 'user', 'content' => 'edit then commit' ) ),
	'I cannot proceed.',
	array( 'mode' => 'pipeline' ),
	2
);

assert_runtime_policy( ! $failed_required_tool_decision->isComplete(), 'failed required tool does not satisfy completion assertion' );
assert_runtime_policy( array( 'workspace_edit', 'workspace_git_commit' ) === ( $failed_required_tool_decision->context()['missing']['tool_names'] ?? null ), 'failed required tool remains in missing assertion list' );

$outcome_assertions_config = array(
	'complete_when_any' => array(
		array(
			'name'  => 'pull_request_path',
			'tools' => array(
				array(
					'name'            => 'create_github_pull_request',
					'success'         => true,
					'required_output' => 'html_url',
				),
				array(
					'name'    => 'comment_github_pull_request',
					'success' => true,
				),
			),
		),
		array(
			'name'  => 'issue_fallback_path',
			'tools' => array(
				array(
					'name'            => 'create_github_issue',
					'success'         => true,
					'required_output' => 'html_url',
				),
				array(
					'name'    => 'comment_github_pull_request',
					'success' => true,
				),
			),
		),
	),
);

$pr_outcome_policy = new DataMachineHandlerCompletionPolicy(
	array(),
	new \DataMachine\Engine\AI\DataMachineCompletionAssertions( $outcome_assertions_config )
);
$pr_outcome_policy->recordToolResult(
	'create_github_pull_request',
	array( 'name' => 'create_github_pull_request' ),
	array(
		'success' => true,
		'data'    => array( 'html_url' => 'https://github.com/Extra-Chill/data-machine/pull/1' ),
	),
	array( 'mode' => 'pipeline' ),
	1
);
$pr_outcome_policy->recordToolResult(
	'comment_github_pull_request',
	array( 'name' => 'comment_github_pull_request' ),
	array( 'success' => true ),
	array( 'mode' => 'pipeline' ),
	2
);
$pr_outcome_decision = $pr_outcome_policy->recordNaturalCompletion(
	array( array( 'role' => 'user', 'content' => 'open a PR or fallback issue, then comment on source PR' ) ),
	'Complete via PR path.',
	array( 'mode' => 'pipeline' ),
	3
);
assert_runtime_policy( $pr_outcome_decision->isComplete(), 'complete_when_any PR path satisfies completion assertion' );
assert_runtime_policy( array( 'pull_request_path' ) === ( $pr_outcome_decision->context()['satisfied']['complete_when_any'] ?? null ), 'named complete_when_any PR path is reported as satisfied' );

$issue_outcome_policy = new DataMachineHandlerCompletionPolicy(
	array(),
	new \DataMachine\Engine\AI\DataMachineCompletionAssertions( $outcome_assertions_config )
);
$issue_outcome_policy->recordToolResult(
	'create_github_issue',
	array( 'name' => 'create_github_issue' ),
	array(
		'success'  => true,
		'html_url' => 'https://github.com/Extra-Chill/data-machine/issues/1',
	),
	array( 'mode' => 'pipeline' ),
	1
);
$issue_outcome_policy->recordToolResult(
	'comment_github_pull_request',
	array( 'name' => 'comment_github_pull_request' ),
	array( 'success' => true ),
	array( 'mode' => 'pipeline' ),
	2
);
$issue_outcome_decision = $issue_outcome_policy->recordNaturalCompletion(
	array( array( 'role' => 'user', 'content' => 'open a PR or fallback issue, then comment on source PR' ) ),
	'Complete via issue fallback path.',
	array( 'mode' => 'pipeline' ),
	3
);
assert_runtime_policy( $issue_outcome_decision->isComplete(), 'complete_when_any issue fallback path satisfies completion assertion' );
assert_runtime_policy( array( 'issue_fallback_path' ) === ( $issue_outcome_decision->context()['satisfied']['complete_when_any'] ?? null ), 'named complete_when_any issue path is reported as satisfied' );

$failed_outcome_policy = new DataMachineHandlerCompletionPolicy(
	array(),
	new \DataMachine\Engine\AI\DataMachineCompletionAssertions( $outcome_assertions_config )
);
$failed_outcome_policy->recordToolResult(
	'create_github_issue',
	array( 'name' => 'create_github_issue' ),
	array(
		'success' => false,
		'data'    => array( 'html_url' => 'https://github.com/Extra-Chill/data-machine/issues/1' ),
	),
	array( 'mode' => 'pipeline' ),
	1
);
$failed_outcome_policy->recordToolResult(
	'comment_github_pull_request',
	array( 'name' => 'comment_github_pull_request' ),
	array( 'success' => true ),
	array( 'mode' => 'pipeline' ),
	2
);
$failed_outcome_decision = $failed_outcome_policy->recordNaturalCompletion(
	array( array( 'role' => 'user', 'content' => 'open a PR or fallback issue, then comment on source PR' ) ),
	'Cannot complete because the issue call failed.',
	array( 'mode' => 'pipeline' ),
	3
);
assert_runtime_policy( ! $failed_outcome_decision->isComplete(), 'failed complete_when_any tool result does not satisfy completion assertion' );
assert_runtime_policy( isset( $failed_outcome_decision->context()['missing']['complete_when_any'] ), 'failed complete_when_any path reports missing outcome assertion' );

$parameter_outcome_policy = new DataMachineHandlerCompletionPolicy(
	array(),
	new \DataMachine\Engine\AI\DataMachineCompletionAssertions(
		array(
			'complete_when_any' => array(
				array(
					'name'  => 'mailbox_return',
					'tools' => array(
						array(
							'name'                => 'manage_github_issue',
							'required_parameters' => array( 'action' => 'comment' ),
							'required_output'     => array( 'comment.html_url' ),
						),
					),
				),
			),
		)
	)
);
$parameter_outcome_policy->recordToolResult(
	'manage_github_issue',
	array( 'name' => 'manage_github_issue' ),
	array(
		'success' => true,
		'data'    => array( 'comment' => array( 'html_url' => 'https://github.com/Extra-Chill/data-machine/issues/1#issuecomment-1' ) ),
	),
	array( 'mode' => 'pipeline', 'tool_parameters' => array( 'action' => 'close' ) ),
	1
);
$parameter_outcome_missing_decision = $parameter_outcome_policy->recordNaturalCompletion(
	array( array( 'role' => 'user', 'content' => 'return to mailbox' ) ),
	'Close is not a mailbox reply.',
	array( 'mode' => 'pipeline' ),
	2
);
assert_runtime_policy( ! $parameter_outcome_missing_decision->isComplete(), 'complete_when_any required_parameters reject wrong tool arguments' );
$parameter_outcome_policy->recordToolResult(
	'manage_github_issue',
	array( 'name' => 'manage_github_issue' ),
	array(
		'success' => true,
		'data'    => array( 'comment' => array( 'html_url' => 'https://github.com/Extra-Chill/data-machine/issues/1#issuecomment-2' ) ),
	),
	array( 'mode' => 'pipeline', 'tool_parameters' => array( 'action' => 'comment' ) ),
	3
);
$parameter_outcome_decision = $parameter_outcome_policy->recordNaturalCompletion(
	array( array( 'role' => 'user', 'content' => 'return to mailbox' ) ),
	'Commented on the mailbox issue.',
	array( 'mode' => 'pipeline' ),
	4
);
assert_runtime_policy( $parameter_outcome_decision->isComplete(), 'complete_when_any required_parameters accept matching tool arguments' );
assert_runtime_policy( array( 'mailbox_return' ) === ( $parameter_outcome_decision->context()['satisfied']['complete_when_any'] ?? null ), 'parameter-matched outcome name is reported as satisfied' );

$design_outcome_policy = new DataMachineHandlerCompletionPolicy(
	array(),
	new \DataMachine\Engine\AI\DataMachineCompletionAssertions(
		array(
			'complete_when_any' => array(
				array(
					'name'  => 'design_comment_and_labels',
					'tools' => array(
						array(
							'name'                => 'manage_github_issue',
							'required_parameters' => array( 'action' => 'comment' ),
							'required_output'     => array( 'comment.html_url' ),
						),
						array(
							'name' => 'remove_label_from_issue',
						),
						array(
							'name' => 'add_label_to_issue',
						),
					),
				),
			),
		)
	)
);
$design_outcome_policy->recordToolResult(
	'manage_github_issue',
	array( 'name' => 'manage_github_issue' ),
	array(
		'success' => true,
		'data'    => array( 'comment' => array( 'html_url' => 'https://github.com/Extra-Chill/data-machine/issues/1#issuecomment-3' ) ),
	),
	array( 'mode' => 'pipeline', 'tool_parameters' => array( 'action' => 'comment' ) ),
	1
);
$design_outcome_policy->recordToolResult(
	'remove_label_from_issue',
	array( 'name' => 'remove_label_from_issue' ),
	array( 'success' => true ),
	array( 'mode' => 'pipeline' ),
	2
);
$design_outcome_decision = $design_outcome_policy->recordToolResult(
	'add_label_to_issue',
	array( 'name' => 'add_label_to_issue' ),
	array( 'success' => true ),
	array( 'mode' => 'pipeline' ),
	3
);
assert_runtime_policy( $design_outcome_decision->isComplete(), 'non-handler complete_when_any outcome completes immediately when satisfied' );
assert_runtime_policy( array( 'design_comment_and_labels' ) === ( $design_outcome_decision->context()['satisfied']['complete_when_any'] ?? null ), 'non-handler completion reports satisfied outcome name' );

$multi_call_outcome_policy = new DataMachineHandlerCompletionPolicy(
	array(),
	new \DataMachine\Engine\AI\DataMachineCompletionAssertions(
		array(
			'complete_when_any' => array(
				array(
					'name'  => 'multi_file_change',
					'tools' => array(
						array(
							'name'                 => 'create_or_update_github_file',
							'min_successful_calls' => 2,
						),
					),
				),
			),
		)
	)
);
$multi_call_outcome_policy->recordToolResult( 'create_or_update_github_file', array( 'name' => 'create_or_update_github_file' ), array( 'success' => true ), array( 'mode' => 'pipeline' ), 1 );
$multi_call_missing_decision = $multi_call_outcome_policy->recordNaturalCompletion(
	array( array( 'role' => 'user', 'content' => 'make a multi-file change' ) ),
	'One file changed.',
	array( 'mode' => 'pipeline' ),
	2
);
assert_runtime_policy( ! $multi_call_missing_decision->isComplete(), 'complete_when_any min_successful_calls requires enough matching successful calls' );
$multi_call_outcome_policy->recordToolResult( 'create_or_update_github_file', array( 'name' => 'create_or_update_github_file' ), array( 'success' => true ), array( 'mode' => 'pipeline' ), 3 );
$multi_call_decision = $multi_call_outcome_policy->recordNaturalCompletion(
	array( array( 'role' => 'user', 'content' => 'make a multi-file change' ) ),
	'Two files changed.',
	array( 'mode' => 'pipeline' ),
	4
);
assert_runtime_policy( $multi_call_decision->isComplete(), 'complete_when_any min_successful_calls accepts enough matching successful calls' );

$daily_memory_unavailable_dispatch_count = 0;
$daily_memory_unavailable_sink           = new RuntimePolicySmokeEventSink();
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$daily_memory_unavailable_dispatch_count ) {
		++$daily_memory_unavailable_dispatch_count;

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'This provider call should not happen.',
				'tool_calls' => array(),
			),
		);
	}
);

$daily_memory_unavailable_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'write daily memory before finishing' ) ),
	array(
		'create_github_pull_request' => array(
			'name'        => 'create_github_pull_request',
			'description' => 'Open a pull request',
			'parameters'  => array( 'name' => array( 'type' => 'string' ) ),
			'class'       => RuntimePolicySmokeTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'pipeline' ),
	array(
		'event_sink'            => $daily_memory_unavailable_sink,
		'completion_assertions' => array(
			'required_tool_names' => array( 'create_github_pull_request', 'agent_daily_memory' ),
		),
	),
	5
);
$daily_memory_unavailable_metadata = datamachine_conversation_metadata( $daily_memory_unavailable_result );

assert_runtime_policy( 0 === $daily_memory_unavailable_dispatch_count, 'unavailable required tool fails before provider dispatch' );
assert_runtime_policy( 'completion_required_tool_unavailable' === ( $daily_memory_unavailable_result['error_code'] ?? '' ), 'unavailable required tool returns clear error code' );
assert_runtime_policy( array( 'agent_daily_memory' ) === ( $daily_memory_unavailable_metadata['unavailable_required_tool_names'] ?? null ), 'unavailable required tool diagnostic names missing tool' );
assert_runtime_policy( str_contains( $daily_memory_unavailable_result['error'] ?? '', 'agent_daily_memory' ), 'unavailable required tool error message names daily memory' );
assert_runtime_policy( array( 'agent_daily_memory' ) === ( runtime_policy_first_event_payload( $daily_memory_unavailable_sink, 'completion_assertions_unavailable' )['unavailable_required_tool_names'] ?? null ), 'unavailable required tool emits preflight event' );

$daily_memory_success_dispatch_count = 0;
WpAiClientTestDouble::reset();
WpAiClientTestDouble::set_response_callback(
	function () use ( &$daily_memory_success_dispatch_count ) {
		++$daily_memory_success_dispatch_count;

		if ( 1 === $daily_memory_success_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => 'Done without memory.',
					'tool_calls' => array(),
				),
			);
		}

		if ( 2 === $daily_memory_success_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'name'       => 'agent_daily_memory',
							'parameters' => array( 'fail' => true ),
						),
					),
				),
			);
		}

		if ( 3 === $daily_memory_success_dispatch_count ) {
			return array(
				'success' => true,
				'data'    => array(
					'content'    => '',
					'tool_calls' => array(
						array(
							'name'       => 'agent_daily_memory',
							'parameters' => array(),
						),
					),
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'content'    => 'Complete after successful memory write.',
				'tool_calls' => array(),
			),
		);
	}
);

$daily_memory_success_result = datamachine_run_conversation(
	array( array( 'role' => 'user', 'content' => 'finish only after daily memory succeeds' ) ),
	array(
		'agent_daily_memory' => array(
			'name'        => 'agent_daily_memory',
			'description' => 'Write daily memory',
			'parameters'  => array( 'fail' => array( 'type' => 'boolean' ) ),
			'class'       => RuntimePolicySmokeMaybeFailTool::class,
			'method'      => 'execute',
		),
	),
	'openai',
	'gpt-smoke',
	array( 'pipeline' ),
	array(
		'completion_assertions' => array(
			'required_tool_names' => array( 'agent_daily_memory' ),
		),
	),
	6
);

$daily_memory_tool_results = $daily_memory_success_result['tool_execution_results'] ?? array();
$daily_memory_success_metadata = datamachine_conversation_metadata( $daily_memory_success_result );
assert_runtime_policy( 4 === $daily_memory_success_dispatch_count, 'required daily memory nudges until successful tool result and then natural completion' );
assert_runtime_policy( false === ( $daily_memory_tool_results[0]['result']['success'] ?? null ), 'failed daily memory call is captured but not sufficient' );
assert_runtime_policy( true === ( $daily_memory_tool_results[1]['result']['success'] ?? null ), 'successful daily memory call satisfies required tool assertion' );
assert_runtime_policy( ! empty( $daily_memory_success_metadata['completed'] ), 'daily memory assertion result completes after successful tool call' );

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
	array( 'chat' ),
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
assert_runtime_policy( isset( $result['request_metadata']['request_json_bytes'] ), 'conversation result carries request metadata' );
assert_runtime_policy( isset( $transcript_policy->calls[0]['result']['request_metadata']['request_json_bytes'] ), 'custom transcript persister receives request metadata' );
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
