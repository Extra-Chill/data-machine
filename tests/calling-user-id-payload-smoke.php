<?php
/**
 * Smoke tests for the calling_user_id payload helper.
 *
 *   php tests/calling-user-id-payload-smoke.php
 *
 * Exercises datamachine_get_calling_user_id() — the consumer-side helper
 * that tools and directives use to read the human caller identity from
 * an AI invocation payload, distinct from the acting agent_id and from
 * the pipeline user_id (job owner).
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

// Minimal WP stubs so the helper file can load.
function __( $text, $domain = null ) {
	unset( $domain );
	return $text;
}

function do_action( $hook, ...$args ) {
	unset( $hook, $args );
}

function apply_filters( $hook, $value, ...$args ) {
	unset( $hook, $args );
	return $value;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $hook, $callback, $priority, $accepted_args );
}

// The helper file pulls in agents-api and substrate classes that we don't
// need just to test a single payload-reader function. Define the function
// directly here so the test is hermetic — it mirrors the implementation in
// inc/Engine/AI/conversation-loop.php exactly.
require_once dirname( __DIR__ ) . '/inc/Engine/AI/conversation-loop.php';

$pass     = 0;
$fail     = 0;
$failures = array();

function smoke_assert( string $label, bool $condition, string $detail = '' ): void {
	global $pass, $fail, $failures;
	if ( $condition ) {
		$pass++;
		echo "  ✓ {$label}\n";
		return;
	}
	$fail++;
	$failures[] = array( $label, $detail );
	echo "  ✗ {$label}" . ( $detail ? "\n      {$detail}" : '' ) . "\n";
}

use function DataMachine\Engine\AI\datamachine_get_calling_user_id;

echo "[1] explicit positive calling_user_id is returned as-is\n";
smoke_assert( 'integer 42 is returned', 42 === datamachine_get_calling_user_id( array( 'calling_user_id' => 42 ) ) );
smoke_assert( 'integer 1 is returned', 1 === datamachine_get_calling_user_id( array( 'calling_user_id' => 1 ) ) );
smoke_assert( 'numeric string "99" coerces to 99', 99 === datamachine_get_calling_user_id( array( 'calling_user_id' => '99' ) ) );

echo "\n[2] missing key returns 0\n";
smoke_assert( 'empty payload returns 0', 0 === datamachine_get_calling_user_id( array() ) );
smoke_assert(
	'payload with user_id but no calling_user_id returns 0',
	0 === datamachine_get_calling_user_id( array( 'user_id' => 42, 'agent_id' => 7 ) )
);

echo "\n[3] zero and negative values normalize to 0\n";
smoke_assert( 'explicit 0 returns 0', 0 === datamachine_get_calling_user_id( array( 'calling_user_id' => 0 ) ) );
smoke_assert( 'negative -5 normalizes to 0', 0 === datamachine_get_calling_user_id( array( 'calling_user_id' => -5 ) ) );

echo "\n[4] non-numeric values return 0\n";
smoke_assert( 'null returns 0', 0 === datamachine_get_calling_user_id( array( 'calling_user_id' => null ) ) );
smoke_assert( 'string "abc" returns 0', 0 === datamachine_get_calling_user_id( array( 'calling_user_id' => 'abc' ) ) );
smoke_assert( 'array returns 0', 0 === datamachine_get_calling_user_id( array( 'calling_user_id' => array( 42 ) ) ) );

echo "\n[5] calling_user_id is independent of user_id and agent_id\n";
$payload = array(
	'user_id'         => 99,  // pipeline job owner
	'agent_id'        => 7,   // acting agent
	'calling_user_id' => 42,  // human caller
);
smoke_assert( 'calling_user_id reads cleanly with sibling fields', 42 === datamachine_get_calling_user_id( $payload ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( $fail > 0 ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo "  - {$failure[0]}" . ( $failure[1] ? ": {$failure[1]}" : '' ) . "\n";
	}
	exit( 1 );
}
