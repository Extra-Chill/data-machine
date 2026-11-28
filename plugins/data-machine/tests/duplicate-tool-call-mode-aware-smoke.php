<?php
/**
 * Smoke tests for the mode-aware duplicate-tool-call correction message.
 *
 * Regression coverage for #1441: when a pipeline AI step double-calls a tool,
 * the correction message must instruct the model to keep going to the next
 * pipeline action rather than the system-mode "task is done — end the
 * conversation". System mode (the default) keeps the pre-fix-1441 message.
 *
 * Run with: php tests/duplicate-tool-call-mode-aware-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/fixtures/namespaced-wp-fn-stubs.php';
require_once __DIR__ . '/bootstrap-unit.php';

use AgentsAPI\AI\WP_Agent_Message;
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

// --- Default arg keeps system semantics. -------------------------------------

$default_envelope = ConversationManager::generateDuplicateToolCallMessage( 'local_search' );
$default_error    = $extract_error( $default_envelope );

datamachine_dup_msg_assert(
	str_contains( $default_error, 'end the conversation' ),
	'Default arg (no mode) preserves system-mode "end the conversation" message.'
);
datamachine_dup_msg_assert(
	str_contains( $default_error, 'local_search' ),
	'Default arg renders the duplicated tool name into the error.'
);
datamachine_dup_msg_assert(
	WP_Agent_Message::TYPE_TOOL_RESULT === $default_envelope['type'],
	'Duplicate-correction message is emitted as a tool_result envelope.'
);
datamachine_dup_msg_assert(
	false === ( $default_envelope['payload']['success'] ?? null ),
	'Duplicate-correction payload reports success=false.'
);

// --- Explicit system mode matches default. -----------------------------------

$system_envelope = ConversationManager::generateDuplicateToolCallMessage( 'foo', 0, 'system' );
$system_error    = $extract_error( $system_envelope );

datamachine_dup_msg_assert(
	str_contains( $system_error, 'end the conversation' ),
	'Explicit system mode emits the end-the-conversation guidance.'
);
datamachine_dup_msg_assert(
	! str_contains( $system_error, 'publish handler' ),
	'Explicit system mode does NOT mention the publish handler.'
);

// --- Pipeline mode tells the AI to continue the pipeline. --------------------

$pipeline_envelope = ConversationManager::generateDuplicateToolCallMessage( 'foo', 0, 'pipeline' );
$pipeline_error    = $extract_error( $pipeline_envelope );

datamachine_dup_msg_assert(
	str_contains( $pipeline_error, 'next required pipeline action' ),
	'Pipeline mode instructs the AI to move to the next required pipeline action.'
);
datamachine_dup_msg_assert(
	! str_contains( $pipeline_error, 'end the conversation' ),
	'Pipeline mode does NOT tell the AI to end the conversation.'
);
datamachine_dup_msg_assert(
	! str_contains( $pipeline_error, 'publish handler' ),
	'Pipeline mode does NOT assume a publish handler is configured.'
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

// --- Unknown modes fall through to system semantics. -------------------------

$unknown_envelope = ConversationManager::generateDuplicateToolCallMessage( 'foo', 0, 'totally-not-a-mode' );
$unknown_error    = $extract_error( $unknown_envelope );

datamachine_dup_msg_assert(
	str_contains( $unknown_error, 'end the conversation' ),
	'Unknown mode falls back to system-mode "end the conversation" wording.'
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
