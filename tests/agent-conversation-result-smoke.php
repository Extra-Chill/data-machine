<?php
/**
 * Smoke tests for agent conversation result validation.
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use AgentsAPI\AI\AgentConversationResult;
use AgentsAPI\AI\AgentMessageEnvelope;

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

$generic_tool_result = $valid_result;
unset( $generic_tool_result['tool_execution_results'][0]['is_handler_tool'] );
$generic_tool_result['tool_execution_results'][0]['tool_name'] = 'search_knowledge_base';
$generic_tool_result['tool_execution_results'][0]['result']    = array( 'success' => true, 'items' => array() );
$normalized_generic_tool_result = AgentConversationResult::normalize( $generic_tool_result );
datamachine_agent_conversation_result_assert(
	! array_key_exists( 'is_handler_tool', $normalized_generic_tool_result['tool_execution_results'][0] ),
	'Generic tool execution result should not require Data Machine handler metadata.'
);
++$assertions;

$malformed_handler_metadata = $valid_result;
$malformed_handler_metadata['tool_execution_results'][0]['is_handler_tool'] = 'yes';

try {
	AgentConversationResult::normalize( $malformed_handler_metadata );
	throw new RuntimeException( 'Malformed handler metadata should throw.' );
} catch ( InvalidArgumentException $e ) {
	datamachine_agent_conversation_result_assert(
		str_contains( $e->getMessage(), 'invalid_agent_conversation_result: tool_execution_results[0].is_handler_tool' ),
		'Present handler-tool metadata should still be type checked.'
	);
	++$assertions;
}

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

// Verify that malformed tool results produce a machine-readable validation error.
try {
	AgentConversationResult::normalize( $malformed_tool_result );
	throw new RuntimeException( 'Malformed tool result should throw.' );
} catch ( InvalidArgumentException $e ) {
	datamachine_agent_conversation_result_assert(
		str_contains( $e->getMessage(), 'invalid_agent_conversation_result: tool_execution_results[0].parameters' ),
		'Malformed tool result validation error should preserve the machine-readable field path.'
	);
	++$assertions;
}

// Verify that valid results normalize without error.
$runner_valid_result = AgentConversationResult::normalize( $valid_result );

datamachine_agent_conversation_result_assert(
	! isset( $runner_valid_result['error'] ),
	'Valid output should not become an error result.'
);
++$assertions;
datamachine_agent_conversation_result_assert(
	true === $runner_valid_result['tool_execution_results'][0]['is_handler_tool'],
	'Valid handler-tool output should preserve handler-tool metadata.'
);
++$assertions;

echo 'Agent conversation result smoke passed (' . $assertions . " assertions).\n";
