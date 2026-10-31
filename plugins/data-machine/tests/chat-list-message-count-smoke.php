<?php
/**
 * Pure-PHP smoke test for chat list message count resolution.
 *
 * Run with: php tests/chat-list-message-count-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {}
}

require_once __DIR__ . '/../inc/Cli/BaseCommand.php';
require_once __DIR__ . '/../inc/Cli/Commands/ChatCommand.php';

$failures = array();
$passes   = 0;

$assert_same = static function ( int $expected, int $actual, string $label ) use ( &$failures, &$passes ): void {
	if ( $expected === $actual ) {
		++$passes;
		echo "PASS: {$label}\n";
		return;
	}

	$failures[] = sprintf( '%s (expected %d, got %d)', $label, $expected, $actual );
	echo "FAIL: {$label}\n";
};

echo "chat-list-message-count-smoke\n";

$method = new ReflectionMethod( DataMachine\Cli\Commands\ChatCommand::class, 'get_session_message_count' );

$count_messages = static function ( array $session ) use ( $method ): int {
	return (int) $method->invoke( null, $session );
};

$assert_same( 8, $count_messages( array( 'message_count' => 8 ) ), 'uses top-level session index count' );
$assert_same( 4, $count_messages( array( 'metadata' => array( 'message_count' => 4 ) ) ), 'falls back to metadata count' );
$assert_same(
	2,
	$count_messages(
		array(
			'messages' => array(
				array( 'role' => 'user', 'content' => 'hello' ),
				array( 'role' => 'assistant', 'content' => 'hi' ),
			),
		)
	),
	'falls back to full message array count'
);
$assert_same(
	8,
	$count_messages(
		array(
			'message_count' => 8,
			'metadata'      => array( 'message_count' => 0 ),
		)
	),
	'prefers top-level session index count over stale metadata'
);

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " chat list message count assertions failed.\n";
	foreach ( $failures as $failure ) {
		echo "- {$failure}\n";
	}
	exit( 1 );
}

echo "\nAll {$passes} chat list message count assertions passed.\n";
