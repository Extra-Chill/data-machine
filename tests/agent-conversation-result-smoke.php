<?php
/**
 * Smoke tests for agent conversation result validation.
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use DataMachine\Engine\AI\AIConversationLoop;
use DataMachine\Engine\AI\AgentConversationResult;
use DataMachine\Engine\AI\AgentMessageEnvelope;

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		global $datamachine_test_conversation_runner_result;

		if ( 'datamachine_conversation_runner' === $hook ) {
			return $datamachine_test_conversation_runner_result;
		}

		return $value;
	}
}

function datamachine_agent_conversation_result_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$assertions = 0;

$valid_result = array(
	'messages'               => array(
		array( 'role' => 'assistant', 'content' => 'Done.' ),
	),
	'final_content'          => 'Done.',
	'turn_count'             => 1,
	'completed'              => true,
	'last_tool_calls'        => array(),
	'tool_execution_results' => array(
		array(
			'tool_name'       => 'upsert_event',
			'result'          => array( 'success' => true, 'data' => array( 'post_id' => 123 ) ),
			'parameters'      => array( 'title' => 'Test Event' ),
			'is_handler_tool' => true,
			'turn_count'      => 1,
		),
	),
	'usage'                  => array( 'total_tokens' => 10 ),
);

$normalized = AgentConversationResult::normalize( $valid_result );
datamachine_agent_conversation_result_assert(
	AgentMessageEnvelope::TYPE_TEXT === $normalized['messages'][0]['type'],
	'Valid built-in-shaped result should normalize messages to canonical envelopes.'
);
++$assertions;
datamachine_agent_conversation_result_assert(
	'Done.' === $normalized['messages'][0]['content'],
	'Canonical envelope preserves message content.'
);
++$assertions;

$without_tool_results = $valid_result;
unset( $without_tool_results['tool_execution_results'] );
$normalized_without_tools = AgentConversationResult::normalize( $without_tool_results );
datamachine_agent_conversation_result_assert(
	array_key_exists( 'tool_execution_results', $normalized_without_tools ),
	'Missing tool_execution_results should normalize to an explicit empty list.'
);
++$assertions;
datamachine_agent_conversation_result_assert(
	array() === $normalized_without_tools['tool_execution_results'],
	'Normalized tool_execution_results should be empty.'
);
++$assertions;

$malformed_tool_result = $valid_result;
unset( $malformed_tool_result['tool_execution_results'][0]['parameters'] );

try {
	AgentConversationResult::normalize( $malformed_tool_result );
	throw new RuntimeException( 'Malformed tool result should throw.' );
} catch ( InvalidArgumentException $e ) {
	datamachine_agent_conversation_result_assert(
		str_contains( $e->getMessage(), 'invalid_agent_conversation_result: tool_execution_results[0].parameters' ),
		'Malformed tool result should include a machine-readable field path.'
	);
	++$assertions;
}

$missing_messages = $valid_result;
unset( $missing_messages['messages'] );

try {
	AgentConversationResult::normalize( $missing_messages );
	throw new RuntimeException( 'Missing messages should throw.' );
} catch ( InvalidArgumentException $e ) {
	datamachine_agent_conversation_result_assert(
		str_contains( $e->getMessage(), 'invalid_agent_conversation_result: messages' ),
		'Missing messages should include a machine-readable field path.'
	);
	++$assertions;
}

$malformed_message = $valid_result;
$malformed_message['messages'][0] = 'not a message array';

try {
	AgentConversationResult::normalize( $malformed_message );
	throw new RuntimeException( 'Malformed message should throw.' );
} catch ( InvalidArgumentException $e ) {
	datamachine_agent_conversation_result_assert(
		str_contains( $e->getMessage(), 'invalid_agent_conversation_result: messages[0]' ),
		'Malformed message should include a machine-readable field path.'
	);
	++$assertions;
}

$datamachine_test_conversation_runner_result = $malformed_tool_result;
$runner_result = AIConversationLoop::run(
	array( array( 'role' => 'user', 'content' => 'Process this.' ) ),
	array(),
	'test-provider',
	'test-model',
	'pipeline',
	array(),
	1
);

datamachine_agent_conversation_result_assert(
	isset( $runner_result['error'] ),
	'Malformed runner output should return an explicit AI loop error.'
);
++$assertions;
datamachine_agent_conversation_result_assert(
	str_contains( $runner_result['error'], 'invalid_agent_conversation_result: tool_execution_results[0].parameters' ),
	'Runner validation error should preserve the machine-readable field path.'
);
++$assertions;
datamachine_agent_conversation_result_assert(
	array() === $runner_result['tool_execution_results'],
	'Runner validation failure should expose an empty tool result list.'
);
++$assertions;

$datamachine_test_conversation_runner_result = $valid_result;
$runner_valid_result = AIConversationLoop::run(
	array( array( 'role' => 'user', 'content' => 'Process this.' ) ),
	array(),
	'test-provider',
	'test-model',
	'pipeline',
	array(),
	1
);

datamachine_agent_conversation_result_assert(
	! isset( $runner_valid_result['error'] ),
	'Valid runner output should not become an error result.'
);
++$assertions;
datamachine_agent_conversation_result_assert(
	true === $runner_valid_result['tool_execution_results'][0]['is_handler_tool'],
	'Valid handler-tool output should preserve handler-tool metadata.'
);
++$assertions;

echo 'Agent conversation result smoke passed (' . $assertions . " assertions).\n";
