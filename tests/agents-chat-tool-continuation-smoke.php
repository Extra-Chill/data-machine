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

datamachine_agents_chat_tool_continuation_assert(
	str_contains( $chat_orchestrator_source, "\$loop_metadata['tool_execution_summary']" ),
	'ChatOrchestrator should preserve tool execution summaries from conversation loop metadata.'
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
	'Canonical Agents API chat output should omit tool envelopes that carry no renderable tool_name.'
);
++ $assertions;

/*
 * Interactive tool parts that carry a tool_name (e.g. present_question choice
 * cards, confirmation/DiffCard buttons) must be projected through to the
 * canonical messages[] on the live turn — preserving type, payload, and
 * metadata — so the frontend renders the clickable card on the sending turn
 * instead of only after a session reload. This is the fix for the dead-loop
 * where the model references a card the client never received.
 */
$interactive_messages = $method->invoke(
	$handler,
	array(
		array(
			'role'    => 'user',
			'type'    => 'text',
			'content' => 'Should I proceed?',
		),
		array(
			'role'     => 'assistant',
			'type'     => 'tool_call',
			'content'  => 'AI ACTION (Turn 1): Executing Present Question.',
			'payload'  => array(
				'tool_name'  => 'present_question',
				'parameters' => array(
					'question' => 'Which surface?',
				),
				'turn'       => 1,
			),
			'metadata' => array(
				'timestamp' => '2026-07-03T00:00:00+00:00',
			),
		),
		array(
			'role'    => 'user',
			'type'    => 'tool_result',
			'content' => 'TOOL RESPONSE (Turn 1): SUCCESS.',
			'payload' => array(
				'tool_name' => 'present_question',
				'success'   => true,
				'tool_data' => array(
					'question' => 'Which surface?',
					'choices'  => array(
						array(
							'label'   => 'Platform',
							'message' => 'Platform',
						),
						array(
							'label'   => 'UX',
							'message' => 'UX',
						),
					),
				),
			),
		),
		array(
			'role'    => 'assistant',
			'type'    => 'text',
			'content' => 'Click a choice on the card above.',
		),
	)
);

datamachine_agents_chat_tool_continuation_assert(
	4 === count( $interactive_messages ),
	'Interactive tool parts with a tool_name must be carried through into canonical messages[] on the live turn.'
);
++ $assertions;

datamachine_agents_chat_tool_continuation_assert(
	'tool_call' === ( $interactive_messages[1]['type'] ?? null )
		&& 'present_question' === ( $interactive_messages[1]['payload']['tool_name'] ?? null )
		&& 'assistant' === ( $interactive_messages[1]['role'] ?? null ),
	'Live-turn tool_call projection preserves type, role, and tool_name for the frontend renderer.'
);
++ $assertions;

datamachine_agents_chat_tool_continuation_assert(
	'tool_result' === ( $interactive_messages[2]['type'] ?? null )
		&& true === ( $interactive_messages[2]['payload']['success'] ?? null )
		&& 2 === count( $interactive_messages[2]['payload']['tool_data']['choices'] ?? array() ),
	'Live-turn tool_result projection preserves the interactive tool_data payload (question/choices).'
);
++ $assertions;

datamachine_agents_chat_tool_continuation_assert(
	array( 'timestamp' => '2026-07-03T00:00:00+00:00' ) === ( $interactive_messages[1]['metadata'] ?? null ),
	'Live-turn tool projection preserves envelope metadata when present.'
);
++ $assertions;

datamachine_agents_chat_tool_continuation_assert(
	'Click a choice on the card above.' === ( $interactive_messages[3]['content'] ?? null )
		&& ! isset( $interactive_messages[3]['type'] ),
	'Plain text messages retain the {role, content} contract unchanged alongside tool parts.'
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

$summary_output = $output_method->invoke(
	$handler,
	array(
		'session_id'              => 'session-3',
		'response'                => 'I checked the workspace.',
		'tool_execution_summary'  => array(
			array(
				'tool_name'  => 'workspace_read',
				'success'    => true,
				'turn_count' => 1,
				'summary'    => 'Read README.md.',
			),
		),
		'tool_execution_results'  => array(),
	),
);

datamachine_agents_chat_tool_continuation_assert(
	1 === count( $summary_output['metadata']['datamachine']['tool_execution_summary'] ?? array() ),
	'Canonical Agents API chat output should preserve non-empty precomputed tool execution summaries.'
);
++ $assertions;

datamachine_agents_chat_tool_continuation_assert(
	'workspace_read' === ( $summary_output['metadata']['datamachine']['tool_execution_summary'][0]['tool_name'] ?? null ),
	'Canonical Agents API chat output should expose executed tool summaries without requiring raw tool results.'
);
++ $assertions;

echo 'Agents chat tool continuation smoke passed (' . $assertions . " assertions).\n";
