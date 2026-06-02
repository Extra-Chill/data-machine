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

$root           = dirname( __DIR__ );
$handler_source = (string) file_get_contents( $root . '/inc/Abilities/Chat/AgentsChatHandler.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source smoke fixture.
$chat_abilities = (string) file_get_contents( $root . '/inc/Abilities/ChatAbilities.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source smoke fixture.
$chat_api       = (string) file_get_contents( $root . '/inc/Api/Chat/Chat.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source smoke fixture.

$assert( str_contains( $chat_abilities, 'new AgentsChatHandler();' ), 'ChatAbilities registers Data Machine as the Agents API chat handler' );
$assert( str_contains( $handler_source, "register_chat_handler( array( $" . "this, 'execute' ) )" ), 'AgentsChatHandler attaches to canonical Agents API chat handler seam' );
$assert( ! str_contains( $handler_source, "wp_agent_chat_handler" ), 'AgentsChatHandler no longer falls back to legacy chat handler filter' );
$assert( str_contains( $handler_source, 'ChatOrchestrator::processChat(' ), 'AgentsChatHandler owns the single ChatOrchestrator runtime call' );
$assert( str_contains( $handler_source, "'selected_pipeline_id' => (int) ( $" . "input['selected_pipeline_id'] ?? 0 )" ), 'AgentsChatHandler forwards selected pipeline context' );
$assert( str_contains( $handler_source, "'request_id'           => $" . "input['request_id'] ?? null" ), 'AgentsChatHandler forwards request id for session dedupe' );
$assert( str_contains( $handler_source, "'interrupt_source'     => is_callable( $" . "input['interrupt_source'] ?? null ) ? $" . "input['interrupt_source'] : null" ), 'AgentsChatHandler explicitly forwards callable interrupt sources' );
$assert( str_contains( $handler_source, "'agent_slug'           => $" . "identity ? $" . "identity->agent_slug : ''" ), 'AgentsChatHandler forwards resolved agent targeting to the chat runtime' );
$assert( str_contains( $handler_source, "'interrupted'" ) && str_contains( $handler_source, "$" . "result['interrupted'] ?? null" ), 'AgentsChatHandler returns interrupted diagnostics in canonical metadata' );
$assert( str_contains( $handler_source, "'tool_execution_summary'" ), 'AgentsChatHandler returns bounded tool execution diagnostics in canonical metadata' );
$assert( ! file_exists( $root . '/inc/Abilities/Chat/SendMessageAbility.php' ), 'datamachine/send-message facade class is removed' );
$assert( ! str_contains( $chat_abilities, 'SendMessageAbility' ), 'ChatAbilities no longer registers datamachine/send-message' );
$assert( str_contains( $chat_api, "wp_get_ability( 'agents/chat' )" ), 'REST chat endpoint dispatches directly through canonical agents/chat' );
$assert( ! str_contains( $chat_api, "wp_get_ability( 'datamachine/send-message' )" ), 'REST chat endpoint no longer resolves datamachine/send-message' );

echo "\n{$passes} passed, {$fails} failed\n";
if ( $fails > 0 ) {
	exit( 1 );
}
