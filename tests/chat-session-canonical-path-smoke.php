<?php
/**
 * Source smoke coverage for canonical chat session creation.
 *
 * Run with: php tests/chat-session-canonical-path-smoke.php
 *
 * @package DataMachine\Tests
 */

declare(strict_types=1);

$root              = dirname( __DIR__ );
$chat_orchestrator = file_get_contents( $root . '/inc/Api/Chat/ChatOrchestrator.php' );
$bootstrap         = file_get_contents( $root . '/inc/bootstrap.php' );
$agent_abilities   = file_get_contents( $root . '/inc/Abilities/AgentAbilities.php' );

$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}

	echo "FAIL: {$message}\n";
	$failures[] = $message;
};

$assert(
	false !== strpos( $chat_orchestrator, "wp_get_ability( 'agents/create-conversation-session' )" ),
	'ChatOrchestrator resolves the canonical create-conversation-session ability'
);

$assert(
	false !== strpos( $chat_orchestrator, "'session_owner'" ),
	'ChatOrchestrator passes opaque owners through the canonical session_owner input'
);

$assert(
	false !== strpos( $chat_orchestrator, 'WP_Agent_Execution_Principal::user_session' ),
	'ChatOrchestrator passes a bounded user-session principal for non-REST canonical session creation'
);

$assert(
	false !== strpos( $bootstrap, "'wp_agent_runtime_import_bundle'" ) && false !== strpos( $bootstrap, 'importRuntimeAgentBundle' ),
	'Data Machine registers the generic agent runtime bundle importer hook'
);

$assert(
	false !== strpos( $agent_abilities, "wp_get_ability( 'datamachine/import-agent' )" ) && false !== strpos( $agent_abilities, 'PermissionHelper::set_agent_context' ),
	'Data Machine owns Data Machine agent bundle import mechanics for generic agent runtimes'
);

$assert(
	false === strpos( $bootstrap, 'wp_codebox_' ) && false === strpos( $agent_abilities, 'wp_codebox_' ),
	'Data Machine does not reference WP Codebox-specific runtime hooks'
);

$assert(
	false !== strpos( $chat_orchestrator, "'session_creation_ability_unavailable'" ),
	'ChatOrchestrator reports missing ability dependencies as an explicit error'
);

$assert(
	false !== strpos( $agent_abilities, 'runtimeAgentBundleImportInput' ),
	'Data Machine normalizes runtime bundle import specs before canonical import'
);

$conversation_loop = file_get_contents( $root . '/inc/Engine/AI/conversation-loop.php' );

$assert(
	false !== strpos( $conversation_loop, 'WP_Agent_Conversation_Loop::run(' ) && false !== strpos( $conversation_loop, "'provider_turn_adapter' => \$provider_turn_adapter" ),
	'Data Machine passes its provider turn adapter through the Agents API loop options'
);

$assert(
	false !== strpos( $conversation_loop, "WP_Agent_Conversation_Loop::run(\n\t\t\t\$messages,\n\t\t\tnull," ),
	'Data Machine lets the Agents API loop wrap the provider turn adapter'
);

$assert(
	false !== strpos( $conversation_loop, 'static function ( $provider_turn_request, array $turn_context = array() )' ),
	'Data Machine provider turn adapter accepts both legacy array turns and request-object turns'
);

$assert(
	false !== strpos( $conversation_loop, '$provider_turn_request->runtimeContext()' ),
	'Data Machine provider turn adapter reads runtime context from provider turn request objects'
);

$assert(
	false === strpos( $chat_orchestrator, 'Fallback: direct store access' ),
	'ChatOrchestrator does not keep a direct-store fallback path'
);

$assert(
	false === strpos( $chat_orchestrator, '$chat_db->create_session' ),
	'ChatOrchestrator does not create chat sessions through the store directly'
);

echo "\n";
if ( empty( $failures ) ) {
	echo "OK: canonical chat session creation smoke assertions passed.\n";
	exit( 0 );
}

echo sprintf( "FAILED: %d assertion(s) failed.\n", count( $failures ) );
exit( 1 );
