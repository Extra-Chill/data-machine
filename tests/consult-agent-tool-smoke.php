<?php
/**
 * Smoke tests for the model-facing consult_agent tool declaration.
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
$tool_source = (string) file_get_contents( $root . '/inc/Api/Chat/Tools/ConsultAgent.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Host smoke reads source fixtures.
$provider    = (string) file_get_contents( $root . '/inc/Engine/AI/Tools/ToolServiceProvider.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Host smoke reads source fixtures.

$assert( 'consult_agent tool source exists', '' !== $tool_source );
$assert( 'consult_agent registers as opt-in chat tool', false !== strpos( $tool_source, "'consult_agent'" ) && false !== strpos( $tool_source, "'requires_opt_in' => true" ) );
$assert( 'consult_agent is backed by canonical agents/chat', false !== strpos( $tool_source, "wp_get_ability( 'agents/chat' )" ) );
$assert( 'consult_agent blocks self calls', false !== strpos( $tool_source, 'consult_agent cannot call the current agent' ) );
$assert( 'consult_agent enforces optional peer allowlist', false !== strpos( $tool_source, 'allowed_agents' ) && false !== strpos( $tool_source, 'approved peer agents' ) );
$assert( 'consult_agent marks peer-agent client context', false !== strpos( $tool_source, "'source'           => 'peer-agent'" ) );
$assert( 'consult_agent does not own chain depth', false === strpos( $tool_source, 'agent_chat_depth' ) && false === strpos( $tool_source, 'MAX_AGENT_CHAT_DEPTH' ) );
$assert( 'consult_agent inherits agent modes', false !== strpos( $tool_source, 'resolve_peer_modes' ) && false !== strpos( $tool_source, "'modes'" ) );
$assert( 'tool service provider registers consult_agent', false !== strpos( $provider, 'use DataMachine\Api\Chat\Tools\ConsultAgent;' ) && false !== strpos( $provider, 'new ConsultAgent();' ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "consult_agent tool smoke passed ({$passes} assertions).\n";
