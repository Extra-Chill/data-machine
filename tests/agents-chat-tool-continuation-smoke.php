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

$handler = new DataMachine\Abilities\Chat\AgentsChatHandler();
$method  = new ReflectionMethod( $handler, 'toCanonicalMessages' );

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

echo 'Agents chat tool continuation smoke passed (' . $assertions . " assertions).\n";
