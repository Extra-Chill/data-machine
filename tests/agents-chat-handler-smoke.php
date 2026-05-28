<?php
/**
 * Smoke tests for Data Machine's canonical Agents API chat adapter.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		unset( $hook );
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		unset( $hook );
		return 1;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ): bool {
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $slug ): ?object {
		$GLOBALS['datamachine_agents_chat_handler_last_slug'] = $slug;
		return 'agents/chat' === $slug ? $GLOBALS['datamachine_agents_chat_handler_fake_ability'] : null;
	}
}

$passes = 0;
$fails  = 0;

$assert = static function ( bool $condition, string $label ) use ( &$passes, &$fails ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$label}\n";
		return;
	}

	++$fails;
	echo "FAIL: {$label}\n";
};

$root                = dirname( __DIR__ );
$send_message_source = (string) file_get_contents( $root . '/inc/Abilities/Chat/SendMessageAbility.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source smoke fixture.
$handler_source      = (string) file_get_contents( $root . '/inc/Abilities/Chat/AgentsChatHandler.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source smoke fixture.
$chat_abilities      = (string) file_get_contents( $root . '/inc/Abilities/ChatAbilities.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source smoke fixture.

$assert( str_contains( $chat_abilities, 'new AgentsChatHandler();' ), 'ChatAbilities registers Data Machine as the Agents API chat handler' );
$assert( str_contains( $handler_source, "register_chat_handler( array( $" . "this, 'execute' ) )" ) || str_contains( $handler_source, "add_filter( 'wp_agent_chat_handler'" ), 'AgentsChatHandler attaches to wp_agent_chat_handler seam' );
$assert( str_contains( $handler_source, 'ChatOrchestrator::processChat(' ), 'AgentsChatHandler owns the single ChatOrchestrator runtime call' );
$assert( str_contains( $handler_source, "'selected_pipeline_id' => (int) ( $" . "input['selected_pipeline_id'] ?? 0 )" ), 'AgentsChatHandler forwards selected pipeline context' );
$assert( str_contains( $handler_source, "'request_id'           => $" . "input['request_id'] ?? null" ), 'AgentsChatHandler forwards request id for session dedupe' );
$assert( str_contains( $handler_source, "'agent_slug'           => sanitize_title( (string) ( $" . "input['agent'] ?? '' ) )" ), 'AgentsChatHandler forwards agent targeting to the chat runtime' );
$assert( ! str_contains( $send_message_source, 'ChatOrchestrator::processChat(' ), 'send-message facade does not duplicate ChatOrchestrator runtime logic' );
$assert( str_contains( $send_message_source, "wp_get_ability( 'agents/chat' )" ), 'send-message facade dispatches through canonical agents/chat ability' );

require_once $root . '/inc/Abilities/Chat/SendMessageAbility.php';

$GLOBALS['datamachine_agents_chat_handler_fake_ability'] = new class() {
	public array $last_input = array();

	public function execute( array $input ): array {
		$this->last_input = $input;
		return array(
			'session_id' => 'session-123',
			'reply'      => 'Hello from agents/chat.',
			'messages'   => array(
				array(
					'role'    => 'assistant',
					'content' => 'Hello from agents/chat.',
				),
			),
			'completed'  => true,
			'metadata'   => array(
				'datamachine' => array(
					'tool_calls'   => array( array( 'name' => 'lookup' ) ),
					'conversation' => array( array( 'role' => 'assistant', 'content' => 'Hello from agents/chat.' ) ),
					'max_turns'    => 5,
					'turn_number'  => 2,
				),
			),
		);
	}
};

$ability = new DataMachine\Abilities\Chat\SendMessageAbility();
$result  = $ability->execute(
	array(
		'message'              => 'Continue this chat.',
		'agent_id'             => 42,
		'session_id'           => 'session-123',
		'provider'             => 'openai',
		'model'                => 'gpt-5.5',
		'mode'                 => 'intelligence',
		'selected_pipeline_id' => 7,
		'request_id'           => 'request-abc',
	)
);

$captured = $GLOBALS['datamachine_agents_chat_handler_fake_ability']->last_input;
$assert( 'agents/chat' === $GLOBALS['datamachine_agents_chat_handler_last_slug'], 'send-message resolves canonical agents/chat ability' );
$assert( '42' === ( $captured['agent'] ?? null ), 'send-message maps agent_id to canonical agent target' );
$assert( 'session-123' === ( $captured['session_id'] ?? null ), 'send-message preserves session continuation id' );
$assert( 7 === ( $captured['selected_pipeline_id'] ?? null ), 'send-message preserves Data Machine pipeline context' );
$assert( 'Hello from agents/chat.' === ( $result['response'] ?? null ), 'send-message maps canonical reply to Data Machine response' );
$assert( array( array( 'name' => 'lookup' ) ) === ( $result['tool_calls'] ?? null ), 'send-message restores Data Machine tool call metadata' );

echo "\n{$passes} passed, {$fails} failed\n";
if ( $fails > 0 ) {
	exit( 1 );
}
