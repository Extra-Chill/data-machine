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

$reflection = new ReflectionMethod( RequestBuilder::class, 'wpAiClientPromptContext' );
$context = $reflection->invokeArgs( null, array( $messages ) );

datamachine_request_builder_tool_transcript_assert( 2 === count( $context['history'] ), 'Initial user prompt and assistant tool call remain in history.' );
datamachine_request_builder_tool_transcript_assert( $context['history'][1] instanceof ModelMessage, 'Assistant tool call remains a model message.' );

$function_call = $context['history'][1]->getParts()[0]->getFunctionCall();
datamachine_request_builder_tool_transcript_assert( null !== $function_call, 'Assistant tool call converts to a function call part.' );
datamachine_request_builder_tool_transcript_assert( 'call_123' === $function_call->getId(), 'Function call id is preserved.' );
datamachine_request_builder_tool_transcript_assert( 'client_filesystem-write' === $function_call->getName(), 'Function call name is provider-safe.' );
datamachine_request_builder_tool_transcript_assert( array( 'path' => 'index.html' ) === $function_call->getArgs(), 'Function call parameters are preserved.' );

datamachine_request_builder_tool_transcript_assert( 1 === count( $context['prompt_parts'] ), 'Latest tool result becomes the current prompt part.' );
$function_response = $context['prompt_parts'][0]->getFunctionResponse();
datamachine_request_builder_tool_transcript_assert( null !== $function_response, 'Tool result converts to a function response part.' );
datamachine_request_builder_tool_transcript_assert( 'call_123' === $function_response->getId(), 'Function response id is preserved.' );
datamachine_request_builder_tool_transcript_assert( 'client_filesystem-write' === $function_response->getName(), 'Function response name is provider-safe.' );
datamachine_request_builder_tool_transcript_assert( true === $function_response->getResponse()['success'], 'Function response payload is preserved.' );

echo "RequestBuilder tool transcript smoke passed.\n";
