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
	false !== strpos( $chat_orchestrator, "'session_creation_ability_unavailable'" ),
	'ChatOrchestrator reports missing ability dependencies as an explicit error'
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
