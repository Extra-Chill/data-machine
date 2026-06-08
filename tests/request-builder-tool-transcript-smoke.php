<?php
/**
 * Smoke test for RequestBuilder tool-call transcript conversion.
 *
 * Run with: php tests/request-builder-tool-transcript-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';
require_once __DIR__ . '/Unit/Support/WpAiClientTestDoubles.php';

use AgentsAPI\AI\WP_Agent_Message;
use DataMachine\Engine\AI\RequestBuilder;
use WordPress\AiClient\Messages\DTO\ModelMessage;

function datamachine_request_builder_tool_transcript_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$messages = array(
	array(
		'role'    => 'user',
		'content' => 'Create the site.',
	),
	WP_Agent_Message::toolCall(
		'',
		'client/filesystem-write',
		array( 'path' => 'index.html' ),
		1,
		array( 'tool_call_id' => 'call_123' )
	),
	WP_Agent_Message::toolResult(
		'{"success":true}',
		'client/filesystem-write',
		array(
			'success' => true,
			'result'  => array( 'path' => 'index.html' ),
		),
		array( 'tool_call_id' => 'call_123' )
	),
);

$aliases = RequestBuilder::providerToolNameAliases(
	array(
		'client/filesystem-write' => array(
			'name'            => 'client/filesystem-write',
			'runtime_tool_id' => 'filesystem_write',
		),
	)
);

$reflection = new ReflectionMethod( RequestBuilder::class, 'wpAiClientPromptContext' );
$context = $reflection->invokeArgs( null, array( $messages, $aliases['logical_to_provider'] ) );

datamachine_request_builder_tool_transcript_assert( 2 === count( $context['history'] ), 'Initial user prompt and assistant tool call remain in history.' );
datamachine_request_builder_tool_transcript_assert( $context['history'][1] instanceof ModelMessage, 'Assistant tool call remains a model message.' );

$function_call = $context['history'][1]->getParts()[0]->getFunctionCall();
datamachine_request_builder_tool_transcript_assert( null !== $function_call, 'Assistant tool call converts to a function call part.' );
datamachine_request_builder_tool_transcript_assert( 'call_123' === $function_call->getId(), 'Function call id is preserved.' );
datamachine_request_builder_tool_transcript_assert( 'filesystem_write' === $function_call->getName(), 'Function call name uses the provider-safe alias.' );
datamachine_request_builder_tool_transcript_assert( array( 'path' => 'index.html' ) === $function_call->getArgs(), 'Function call parameters are preserved.' );

datamachine_request_builder_tool_transcript_assert( 1 === count( $context['prompt_parts'] ), 'Latest tool result becomes the current prompt part.' );
$function_response = $context['prompt_parts'][0]->getFunctionResponse();
datamachine_request_builder_tool_transcript_assert( null !== $function_response, 'Tool result converts to a function response part.' );
datamachine_request_builder_tool_transcript_assert( 'call_123' === $function_response->getId(), 'Function response id is preserved.' );
datamachine_request_builder_tool_transcript_assert( 'filesystem_write' === $function_response->getName(), 'Function response name uses the provider-safe alias.' );
datamachine_request_builder_tool_transcript_assert( true === $function_response->getResponse()['success'], 'Function response payload is preserved.' );
datamachine_request_builder_tool_transcript_assert( 'client/filesystem-write' === $aliases['provider_to_logical']['filesystem_write'], 'Alias map can restore the logical tool name.' );

if ( function_exists( 'DataMachine\\Engine\\AI\\datamachine_apply_provider_tool_name_aliases' ) ) {
	$restored = DataMachine\Engine\AI\datamachine_apply_provider_tool_name_aliases(
		array(
			array(
				'name'       => 'filesystem_write',
				'parameters' => array( 'path' => 'index.html' ),
			),
		),
		array( 'tool_name_aliases' => $aliases )
	);
	datamachine_request_builder_tool_transcript_assert( 'client/filesystem-write' === $restored[0]['name'], 'Provider-safe tool calls are restored to logical names before execution.' );
	datamachine_request_builder_tool_transcript_assert( 'filesystem_write' === $restored[0]['provider_name'], 'Restored calls retain the provider alias for diagnostics.' );
}

echo "RequestBuilder tool transcript smoke passed.\n";
