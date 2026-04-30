<?php
/**
 * Smoke tests for the agent message envelope contract.
 *
 * Run with: php tests/ai-message-envelope-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use AgentsAPI\Engine\AI\AgentConversationResult;
use DataMachine\Engine\AI\ConversationManager;
use AgentsAPI\Engine\AI\AgentMessageEnvelope;

function datamachine_message_envelope_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function datamachine_message_envelope_count(): void {
	$GLOBALS['datamachine_message_envelope_assertions'] = ( $GLOBALS['datamachine_message_envelope_assertions'] ?? 0 ) + 1;
}

$GLOBALS['datamachine_message_envelope_assertions'] = 0;

$legacy_text = array(
	'role'    => 'user',
	'content' => 'Hello world.',
);

$text_envelope = AgentMessageEnvelope::normalize( $legacy_text );
datamachine_message_envelope_assert( AgentMessageEnvelope::SCHEMA === $text_envelope['schema'], 'Legacy text normalizes to the Agents API schema.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( AgentMessageEnvelope::VERSION === $text_envelope['version'], 'Legacy text normalizes to the current envelope version.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( AgentMessageEnvelope::TYPE_TEXT === $text_envelope['type'], 'Legacy text infers text type.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( array() === $text_envelope['payload'], 'Plain legacy text normalizes with an empty payload.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( $legacy_text === AgentMessageEnvelope::to_provider_message( $legacy_text ), 'Plain legacy text projects without provider metadata churn.' );
datamachine_message_envelope_count();

$legacy_tool_call = array(
	'role'     => 'assistant',
	'content'  => 'AI ACTION (Turn 2): Executing Wiki Upsert with parameters: title: Demo',
	'metadata' => array(
		'type'       => 'tool_call',
		'tool_name'  => 'wiki_upsert',
		'parameters' => array( 'title' => 'Demo' ),
		'turn'       => 2,
	),
);

$tool_call_envelope = AgentMessageEnvelope::normalize( $legacy_tool_call );
datamachine_message_envelope_assert( AgentMessageEnvelope::TYPE_TOOL_CALL === $tool_call_envelope['type'], 'Legacy tool call keeps explicit tool_call type.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( 'wiki_upsert' === $tool_call_envelope['payload']['tool_name'], 'Tool call tool_name is promoted to envelope payload.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( array( 'title' => 'Demo' ) === $tool_call_envelope['payload']['parameters'], 'Tool call parameters are promoted to envelope payload.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( $legacy_tool_call === AgentMessageEnvelope::to_provider_message( $tool_call_envelope ), 'Tool call envelope projects back to provider message shape.' );
datamachine_message_envelope_count();

$built_tool_call = ConversationManager::formatToolCallMessage( 'wiki_upsert', array( 'title' => 'Demo' ), 3 );
datamachine_message_envelope_assert( AgentMessageEnvelope::TYPE_TOOL_CALL === $built_tool_call['type'], 'ConversationManager emits tool_call envelopes.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( 'wiki_upsert' === $built_tool_call['payload']['tool_name'], 'ConversationManager stores tool call details in payload.' );
datamachine_message_envelope_count();

$legacy_tool_result = array(
	'role'     => 'user',
	'content'  => "TOOL RESPONSE (Turn 2): SUCCESS: Wiki Upsert completed successfully.\n\n{\"post_id\":123}",
	'metadata' => array(
		'type'      => 'tool_result',
		'tool_name' => 'wiki_upsert',
		'success'   => true,
		'turn'      => 2,
		'tool_data' => array( 'post_id' => 123 ),
	),
);

$tool_result_envelope = AgentMessageEnvelope::normalize( $legacy_tool_result );
datamachine_message_envelope_assert( AgentMessageEnvelope::TYPE_TOOL_RESULT === $tool_result_envelope['type'], 'Legacy tool result keeps explicit tool_result type.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( true === $tool_result_envelope['payload']['success'], 'Tool result success is promoted to envelope payload.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( array( 'post_id' => 123 ) === $tool_result_envelope['payload']['tool_data'], 'Tool result data is promoted to envelope payload.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( $legacy_tool_result === AgentMessageEnvelope::to_provider_message( $tool_result_envelope ), 'Tool result envelope projects back to provider message shape.' );
datamachine_message_envelope_count();

$typed_final_result = array(
	'schema'   => AgentMessageEnvelope::SCHEMA,
	'version'  => AgentMessageEnvelope::VERSION,
	'type'     => AgentMessageEnvelope::TYPE_FINAL_RESULT,
	'role'     => 'assistant',
	'content'  => 'Finished.',
	'payload'  => array( 'status' => 'complete' ),
	'metadata' => array( 'provider_message_id' => 'msg_123' ),
);

$typed_envelope = AgentMessageEnvelope::normalize( $typed_final_result );
datamachine_message_envelope_assert( 'assistant' === $typed_envelope['role'], 'Future typed envelope keeps role in canonical output.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( 'Finished.' === $typed_envelope['content'], 'Future typed envelope keeps content in canonical output.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( AgentMessageEnvelope::TYPE_FINAL_RESULT === $typed_envelope['type'], 'Future typed envelope keeps type as a top-level field.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( 'complete' === $typed_envelope['payload']['status'], 'Future typed envelope keeps type-specific data in payload.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( 'msg_123' === $typed_envelope['metadata']['provider_message_id'], 'Future typed envelope preserves extension metadata.' );
datamachine_message_envelope_count();

$typed_provider = AgentMessageEnvelope::to_provider_message( $typed_envelope );
datamachine_message_envelope_assert( AgentMessageEnvelope::TYPE_FINAL_RESULT === $typed_provider['metadata']['type'], 'Provider projection folds type into metadata.' );
datamachine_message_envelope_count();
datamachine_message_envelope_assert( 'complete' === $typed_provider['metadata']['status'], 'Provider projection folds payload into metadata.' );
datamachine_message_envelope_count();

$typed_delta = array(
	'schema'  => AgentMessageEnvelope::SCHEMA,
	'version' => AgentMessageEnvelope::VERSION,
	'type'    => AgentMessageEnvelope::TYPE_DELTA,
	'content' => 'partial token',
	'payload' => array( 'index' => 0 ),
);

$delta_envelope = AgentMessageEnvelope::normalize( $typed_delta );
datamachine_message_envelope_assert( 'assistant' === $delta_envelope['role'], 'Typed delta envelope gets assistant default role.' );
datamachine_message_envelope_count();

$old_data_envelope = $typed_delta;
$old_data_envelope['data'] = $old_data_envelope['payload'];
unset( $old_data_envelope['payload'] );
$old_data_normalized = AgentMessageEnvelope::normalize( $old_data_envelope );
datamachine_message_envelope_assert( array( 'index' => 0 ) === $old_data_normalized['payload'], 'Old data envelope key is accepted as a read-time compatibility input.' );
datamachine_message_envelope_count();

$multimodal_legacy = array(
	'role'    => 'user',
	'content' => array(
		array( 'type' => 'text', 'text' => 'Describe this image.' ),
		array( 'type' => 'image_url', 'image_url' => array( 'url' => 'https://example.com/image.jpg' ) ),
	),
);

$multimodal_envelope = AgentMessageEnvelope::normalize( $multimodal_legacy );
datamachine_message_envelope_assert( AgentMessageEnvelope::TYPE_MULTIMODAL_PART === $multimodal_envelope['type'], 'Array content infers multimodal_part type.' );
datamachine_message_envelope_count();

$result = AgentConversationResult::normalize(
	array(
		'messages'               => array( $typed_final_result ),
		'final_content'          => 'Finished.',
		'turn_count'             => 1,
		'completed'              => true,
		'last_tool_calls'        => array(),
		'tool_execution_results' => array(),
		'usage'                  => array(),
	)
);

datamachine_message_envelope_assert( AgentMessageEnvelope::TYPE_FINAL_RESULT === $result['messages'][0]['type'], 'AgentConversationResult accepts typed envelopes and returns canonical envelopes.' );
datamachine_message_envelope_count();

try {
	AgentMessageEnvelope::normalize(
		array(
			'schema'  => AgentMessageEnvelope::SCHEMA,
			'version' => AgentMessageEnvelope::VERSION,
			'type'    => 'unknown_type',
			'content' => 'bad',
		)
	);
	throw new RuntimeException( 'Unsupported envelope type should throw.' );
} catch ( InvalidArgumentException $e ) {
	datamachine_message_envelope_assert( str_contains( $e->getMessage(), 'unsupported type' ), 'Unsupported envelope type fails loudly.' );
	datamachine_message_envelope_count();
}

datamachine_message_envelope_assert( false !== json_encode( $typed_envelope ), 'Canonical envelope remains JSON serializable.' );
datamachine_message_envelope_count();

echo 'AI message envelope smoke passed (' . $GLOBALS['datamachine_message_envelope_assertions'] . " assertions).\n";
