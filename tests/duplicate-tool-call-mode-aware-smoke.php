<?php
/**
 * Smoke tests for the mode-aware duplicate-tool-call correction message.
 *
 * Regression coverage for #1441: when a pipeline AI step double-calls a tool,
 * the correction message must instruct the model to keep going (call the
 * publish handler) rather than the chat-shaped "task is done — end the
 * conversation". The chat-mode behavior must remain identical to the
 * pre-fix-1441 message so existing chat callers are unaffected.
 *
 * Run with: php tests/duplicate-tool-call-mode-aware-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

use AgentsAPI\AI\AgentMessageEnvelope;
use DataMachine\Engine\AI\ConversationManager;

function datamachine_dup_msg_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	$GLOBALS['datamachine_dup_msg_assertions'] = ( $GLOBALS['datamachine_dup_msg_assertions'] ?? 0 ) + 1;
}

$GLOBALS['datamachine_dup_msg_assertions'] = 0;

// Helper: pull the AI-facing error string out of a duplicate-correction envelope.
$extract_error = static function ( array $envelope ): string {
	return (string) ( $envelope['payload']['error'] ?? '' );
};

// --- Default arg keeps chat semantics. ---------------------------------------

$default_envelope = ConversationManager::generateDuplicateToolCallMessage( 'queue_validator' );
$default_error    = $extract_error( $default_envelope );

datamachine_dup_msg_assert(
	str_contains( $default_error, 'end the conversation' ),
	'Default arg (no mode) preserves chat-mode "end the conversation" message.'
);
datamachine_dup_msg_assert(
	str_contains( $default_error, 'queue_validator' ),
	'Default arg renders the duplicated tool name into the error.'
);
datamachine_dup_msg_assert(
	AgentMessageEnvelope::TYPE_TOOL_RESULT === $default_envelope['type'],
	'Duplicate-correction message is emitted as a tool_result envelope.'
);
datamachine_dup_msg_assert(
	false === ( $default_envelope['payload']['success'] ?? null ),
	'Duplicate-correction payload reports success=false.'
);

// --- Explicit chat mode matches default. -------------------------------------

$chat_envelope = ConversationManager::generateDuplicateToolCallMessage( 'foo', 0, 'chat' );
$chat_error    = $extract_error( $chat_envelope );

datamachine_dup_msg_assert(
	str_contains( $chat_error, 'end the conversation' ),
	'Explicit chat mode emits the chat-shaped end-the-conversation guidance.'
);
datamachine_dup_msg_assert(
	! str_contains( $chat_error, 'publish handler' ),
	'Explicit chat mode does NOT mention the publish handler.'
);

// --- Pipeline mode tells the AI to call the publish handler. -----------------

$pipeline_envelope = ConversationManager::generateDuplicateToolCallMessage( 'foo', 0, 'pipeline' );
$pipeline_error    = $extract_error( $pipeline_envelope );

datamachine_dup_msg_assert(
	str_contains( $pipeline_error, 'publish handler' ),
	'Pipeline mode instructs the AI to call the publish handler.'
);
datamachine_dup_msg_assert(
	! str_contains( $pipeline_error, 'end the conversation' ),
	'Pipeline mode does NOT tell the AI to end the conversation.'
);
datamachine_dup_msg_assert(
	str_contains( $pipeline_error, 'foo' ),
	'Pipeline mode renders the duplicated tool name into the error.'
);

// --- Bridge mode tells the AI to continue its response. ----------------------

$bridge_envelope = ConversationManager::generateDuplicateToolCallMessage( 'foo', 0, 'bridge' );
$bridge_error    = $extract_error( $bridge_envelope );

datamachine_dup_msg_assert(
	str_contains( $bridge_error, 'Continue' ),
	'Bridge mode instructs the AI to continue its response to the user.'
);
datamachine_dup_msg_assert(
	! str_contains( $bridge_error, 'end the conversation' ),
	'Bridge mode does NOT tell the AI to end the conversation.'
);

// --- Unknown modes fall through to chat semantics. ---------------------------

$unknown_envelope = ConversationManager::generateDuplicateToolCallMessage( 'foo', 0, 'totally-not-a-mode' );
$unknown_error    = $extract_error( $unknown_envelope );

datamachine_dup_msg_assert(
	str_contains( $unknown_error, 'end the conversation' ),
	'Unknown mode falls back to chat-mode "end the conversation" wording.'
);

// --- Turn count propagates through to the envelope payload. ------------------

$turn_envelope = ConversationManager::generateDuplicateToolCallMessage( 'foo', 7, 'pipeline' );
datamachine_dup_msg_assert(
	7 === ( $turn_envelope['payload']['turn'] ?? null ),
	'Turn count propagates into the duplicate-correction envelope payload.'
);

// --- Done. -------------------------------------------------------------------

printf(
	"OK  %d assertions passed (duplicate-tool-call mode-aware smoke).\n",
	(int) $GLOBALS['datamachine_dup_msg_assertions']
);
