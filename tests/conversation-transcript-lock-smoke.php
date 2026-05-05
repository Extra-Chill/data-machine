<?php
/**
 * Pure-PHP smoke test for Data Machine's Agents API transcript lock adoption.
 *
 * Run with: php tests/conversation-transcript-lock-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

function did_action( string $hook = '' ): int {
	return 0;
}

function doing_action( string $hook = '' ): bool {
	return false;
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	// no-op
}

function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	// no-op
}

require_once __DIR__ . '/agents-api-loader.php';
datamachine_tests_require_agents_api();
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock;
use DataMachine\Tests\Unit\Core\Database\Chat\InMemoryConversationStore;

$failures = array();
$passes   = 0;

$assert_equals = static function ( mixed $expected, mixed $actual, string $label ) use ( &$failures, &$passes ): void {
	if ( $expected === $actual ) {
		echo "PASS: {$label}\n";
		++$passes;
		return;
	}

	echo "FAIL: {$label}\n";
	$failures[] = $label;
};

echo "data-machine-conversation-transcript-lock-smoke\n";

$store = new InMemoryConversationStore();
$store->set_clock( 1000 );
$session_id = $store->create_session( 1, 0, array(), 'chat' );

$assert_equals( true, $store instanceof WP_Agent_Conversation_Lock, 'Data Machine aggregate store implements Agents API lock contract' );

echo "\n[1] Acquire then release succeeds:\n";
$token = $store->acquire_session_lock( $session_id, 30 );
$assert_equals( true, is_string( $token ) && '' !== $token, 'acquire returns an ownership token' );
$assert_equals( true, $store->release_session_lock( $session_id, (string) $token ), 'release accepts the active token' );

echo "\n[2] Contention returns null:\n";
$token_a = $store->acquire_session_lock( $session_id, 30 );
$token_b = $store->acquire_session_lock( $session_id, 30 );
$assert_equals( true, is_string( $token_a ) && '' !== $token_a, 'first contender acquires lock' );
$assert_equals( null, $token_b, 'second contender is denied while lock is active' );

echo "\n[3] TTL expiry permits reacquisition:\n";
$store->set_clock( 1031 );
$token_c = $store->acquire_session_lock( $session_id, 30 );
$assert_equals( true, is_string( $token_c ) && '' !== $token_c && $token_c !== $token_a, 'expired lock is reclaimable with a new token' );

echo "\n[4] Stale token release is rejected:\n";
$assert_equals( false, $store->release_session_lock( $session_id, (string) $token_a ), 'stale token does not release reacquired lock' );
$assert_equals( true, $store->release_session_lock( $session_id, (string) $token_c ), 'current token still releases after stale rejection' );

echo "\n[5] Non-contended chat writes still work:\n";
$messages = array(
	array(
		'role'    => 'user',
		'content' => 'Hello',
	),
);
$assert_equals( true, $store->update_session( $session_id, $messages, array( 'status' => 'completed' ), 'openai', 'gpt-test' ), 'session update succeeds without an active lock' );
$session = $store->get_session( $session_id );
$assert_equals( 1, count( $session['messages'] ?? array() ), 'non-contended update persists messages' );

echo "\n";
if ( empty( $failures ) ) {
	echo "All Data Machine conversation transcript lock smoke tests passed ({$passes} assertions).\n";
	exit( 0 );
}

echo sprintf( "%d failure(s):\n", count( $failures ) );
foreach ( $failures as $failure ) {
	echo "  - {$failure}\n";
}
exit( 1 );
