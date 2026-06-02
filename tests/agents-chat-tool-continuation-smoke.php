<?php
/**
 * Smoke tests for Agents API chat tool continuation behavior.
 *
 * @package DataMachine\Tests
 */

function datamachine_agents_chat_tool_continuation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $text ) ) ?? '' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$assertions = 0;

$chat_orchestrator_source = file_get_contents( __DIR__ . '/../inc/Api/Chat/ChatOrchestrator.php' );
datamachine_agents_chat_tool_continuation_assert(
	is_string( $chat_orchestrator_source ),
	'ChatOrchestrator source should be readable.'
);
++ $assertions;

datamachine_agents_chat_tool_continuation_assert(
	! str_contains( $chat_orchestrator_source, "'single_turn'          => true" ),
	'Interactive chat entrypoints should not force single-turn execution.'
);
++ $assertions;

require_once __DIR__ . '/../inc/Abilities/Chat/AgentsChatHandler.php';

$handler_reflection = new ReflectionClass( DataMachine\Abilities\Chat\AgentsChatHandler::class );
$handler            = $handler_reflection->newInstanceWithoutConstructor();
$method             = new ReflectionMethod( $handler, 'toCanonicalMessages' );

$messages = $method->invoke(
	$handler,
	array(
		array(
			'role'    => 'user',
			'type'    => 'text',
			'content' => 'What do you know?',
		),
		array(
			'role'    => 'assistant',
			'type'    => 'tool_call',
			'content' => 'AI ACTION (Turn 1): Executing Wiki Brain List.',
		),
		array(
			'role'    => 'user',
			'type'    => 'tool_result',
			'content' => 'TOOL RESPONSE (Turn 1): SUCCESS.',
		),
		array(
			'role'    => 'assistant',
			'type'    => 'text',
			'content' => 'Here is the answer.',
		),
	)
);

datamachine_agents_chat_tool_continuation_assert(
	array(
		array(
			'role'    => 'user',
			'content' => 'What do you know?',
		),
		array(
			'role'    => 'assistant',
			'content' => 'Here is the answer.',
		),
	) === $messages,
	'Canonical Agents API chat output should omit internal tool call/result messages.'
);
++ $assertions;

$output_method = new ReflectionMethod( $handler, 'toCanonicalOutput' );
$output        = $output_method->invoke(
	$handler,
	array(
		'session_id' => 'session-1',
		'response'   => 'AI ACTION (Turn 4): Executing Workspace Read.',
		'metadata'   => array(
			'datamachine' => array(
				'completed'         => false,
				'max_turns_reached' => true,
			),
		),
	)
);

datamachine_agents_chat_tool_continuation_assert(
	false === $output['completed'],
	'Canonical Agents API chat output should preserve Data Machine incomplete max-turn state.'
);
++ $assertions;

datamachine_agents_chat_tool_continuation_assert(
	true === ( $output['metadata']['datamachine']['max_turns_reached'] ?? null ),
	'Canonical Agents API chat output should preserve Data Machine max-turn diagnostics.'
);
++ $assertions;

$tool_output = $output_method->invoke(
	$handler,
	array(
		'session_id'              => 'session-2',
		'response'                => 'I checked the workspace.',
		'tool_execution_results'  => array(
			array(
				'tool_name'  => 'workspace_read',
				'turn_count' => 2,
				'parameters' => array(
					'path' => 'README.md',
				),
				'result'     => array(
					'success' => true,
					'message' => 'Read file content.',
					'content' => 'raw file content should not be copied into canonical metadata',
				),
			),
			array(
				'tool_name'  => 'workspace_edit',
				'turn_count' => 3,
				'result'     => array(
					'success' => false,
					'message' => 'old_string not found in file content.',
				),
			),
		),
	)
);

datamachine_agents_chat_tool_continuation_assert(
	2 === count( $tool_output['metadata']['datamachine']['tool_execution_summary'] ?? array() ),
	'Canonical Agents API chat output should preserve bounded tool execution diagnostics.'
);
++ $assertions;

datamachine_agents_chat_tool_continuation_assert(
	'workspace_read' === ( $tool_output['metadata']['datamachine']['tool_execution_summary'][0]['tool_name'] ?? null ),
	'Canonical Agents API chat output should preserve tool names.'
);
++ $assertions;

datamachine_agents_chat_tool_continuation_assert(
	false === ( $tool_output['metadata']['datamachine']['tool_execution_summary'][1]['success'] ?? null ),
	'Canonical Agents API chat output should preserve failed tool execution status.'
);
++ $assertions;

datamachine_agents_chat_tool_continuation_assert(
	! str_contains( wp_json_encode( $tool_output['metadata']['datamachine']['tool_execution_summary'] ), 'raw file content' ),
	'Canonical Agents API chat output should not copy raw tool content into metadata summaries.'
);
++ $assertions;

echo 'Agents chat tool continuation smoke passed (' . $assertions . " assertions).\n";
