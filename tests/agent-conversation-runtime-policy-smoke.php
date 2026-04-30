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

require_once __DIR__ . '/bootstrap-unit.php';
require_once __DIR__ . '/Unit/Support/WpAiClientTestDoubles.php';

use DataMachine\Engine\AI\AIConversationLoop;
use DataMachine\Engine\AI\AgentConversationCompletionDecision;
use DataMachine\Engine\AI\AgentConversationCompletionPolicyInterface;
use DataMachine\Engine\AI\AgentConversationTranscriptPersisterInterface;
use DataMachine\Engine\AI\DataMachineHandlerCompletionPolicy;
use DataMachine\Tests\Unit\Support\WpAiClientTestDouble;

class RuntimePolicySmokeCompletionPolicy implements AgentConversationCompletionPolicyInterface {
	public array $calls = array();

	public function recordToolResult( string $tool_name, ?array $tool_def, array $tool_result, string $mode, int $turn_count ): AgentConversationCompletionDecision {
		$this->calls[] = compact( 'tool_name', 'tool_def', 'tool_result', 'mode', 'turn_count' );

		return AgentConversationCompletionDecision::complete(
			'RuntimePolicySmoke: custom policy completed',
			array( 'tool_name' => $tool_name )
		);
	}
}

class RuntimePolicySmokeTranscriptPersister implements AgentConversationTranscriptPersisterInterface {
	public array $calls = array();

	public function persist( array $messages, string $provider, string $model, array $payload, array $result ): string {
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
	'pipeline',
	1
);
$second_decision = $handler_policy->recordToolResult(
	'publish_pinterest',
	array( 'handler' => 'pinterest_publish' ),
	array( 'success' => true ),
	'pipeline',
	2
);

assert_runtime_policy( ! $first_decision->isComplete(), 'handler policy waits for remaining configured handlers' );
assert_runtime_policy( array( 'pinterest_publish' ) === ( $first_decision->context()['remaining_handlers'] ?? null ), 'handler policy reports remaining handlers' );
assert_runtime_policy( $second_decision->isComplete(), 'handler policy completes after all configured handlers fire' );
assert_runtime_policy( array( 'wordpress_publish', 'pinterest_publish' ) === array_values( $second_decision->context()['executed_handlers'] ?? array() ), 'handler policy reports executed handlers' );

// 2. Injected completion/transcript collaborators steer the loop without leaking into provider payloads.
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

$result = ( new AIConversationLoop() )->execute(
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
assert_runtime_policy( true === $result['completed'], 'custom completion policy marked the result complete' );
assert_runtime_policy( 'runtime-policy-transcript' === ( $result['transcript_session_id'] ?? null ), 'custom transcript persister can attach a transcript session id' );
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
