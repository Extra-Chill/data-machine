<?php
/**
 * Smoke tests for the model-facing agent_chat tool declaration.
 *
 * @package DataMachine\Tests
 */

$failures = 0;
$passes   = 0;

$assert = static function ( string $label, bool $condition ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		echo "PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "FAIL: {$label}\n";
};

$root        = dirname( __DIR__ );
$tool_source = (string) file_get_contents( $root . '/inc/Api/Chat/Tools/AgentChat.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Host smoke reads source fixtures.
$provider    = (string) file_get_contents( $root . '/inc/Engine/AI/Tools/ToolServiceProvider.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Host smoke reads source fixtures.

$assert( 'agent_chat tool source exists', '' !== $tool_source );
$assert( 'agent_chat registers as opt-in chat tool', false !== strpos( $tool_source, "'agent_chat'" ) && false !== strpos( $tool_source, "'requires_opt_in' => true" ) );
$assert( 'agent_chat is backed by canonical agents/chat', false !== strpos( $tool_source, "wp_get_ability( 'agents/chat' )" ) );
$assert( 'agent_chat blocks self calls', false !== strpos( $tool_source, 'agent_chat cannot call the current agent' ) );
$assert( 'agent_chat carries depth guard', false !== strpos( $tool_source, 'MAX_AGENT_CHAT_DEPTH' ) && false !== strpos( $tool_source, 'agent_chat_depth' ) );
$assert( 'agent_chat inherits agent modes', false !== strpos( $tool_source, 'resolve_peer_modes' ) && false !== strpos( $tool_source, "'modes'" ) );
$assert( 'tool service provider registers agent_chat', false !== strpos( $provider, 'use DataMachine\Api\Chat\Tools\AgentChat;' ) && false !== strpos( $provider, 'new AgentChat();' ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "agent_chat tool smoke passed ({$passes} assertions).\n";
