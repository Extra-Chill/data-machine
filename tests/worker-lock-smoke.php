<?php
/**
 * Pure-PHP smoke test for the Data Machine worker/drain runtime lock.
 *
 * Run with: php tests/worker-lock-smoke.php
 *
 * @package DataMachine\Tests
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['datamachine_worker_lock_options'] = array();

function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['datamachine_worker_lock_options'][ $name ] ?? $default;
}

function add_option( string $name, mixed $value, string $deprecated = '', string $autoload = 'yes' ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $name, $GLOBALS['datamachine_worker_lock_options'] ) ) {
		return false;
	}

	$GLOBALS['datamachine_worker_lock_options'][ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['datamachine_worker_lock_options'][ $name ] );
	return true;
}

function wp_generate_uuid4(): string {
	static $i = 0;
	++$i;
	return 'worker-lock-token-' . $i;
}

function sanitize_text_field( string $text ): string {
	return trim( $text );
}

require_once __DIR__ . '/../inc/Cli/WorkerLock.php';

use DataMachine\Cli\WorkerLock;

$assertions = 0;

function assert_worker_lock_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$first = WorkerLock::acquire( 'worker one', 120 );
assert_worker_lock_true( true === $first['acquired'], 'first worker acquires the lock' );
assert_worker_lock_true( 'held' === $first['lock_status'], 'acquired lock reports held status' );
assert_worker_lock_true( 'worker one' === $first['lock_owner'], 'lock reports owner' );

$second = WorkerLock::acquire( 'worker two', 120 );
assert_worker_lock_true( false === $second['acquired'], 'overlapping worker skips cleanly' );
assert_worker_lock_true( 'held' === $second['lock_status'], 'overlapping worker sees held lock' );
assert_worker_lock_true( 'worker one' === $second['lock_owner'], 'overlap reports existing owner' );

WorkerLock::release( (string) $first['lock_token'] );
$released = WorkerLock::snapshot();
assert_worker_lock_true( 'unlocked' === $released['lock_status'], 'release clears owned lock' );

$stale_started = time() - 300;
add_option(
	'datamachine_worker_runtime_lock',
	array(
		'token'      => 'stale-token',
		'owner'      => 'old worker',
		'started_at' => $stale_started,
		'expires_at' => $stale_started + 60,
		'ttl'        => 60,
	),
	'',
	'no'
);

$stale = WorkerLock::snapshot();
assert_worker_lock_true( 'stale' === $stale['lock_status'], 'expired lock reports stale status' );

$replacement = WorkerLock::acquire( 'new worker', 120 );
assert_worker_lock_true( true === $replacement['acquired'], 'stale lock is replaced by new owner' );
assert_worker_lock_true( 'new worker' === $replacement['lock_owner'], 'replacement lock reports new owner' );

WorkerLock::release( 'wrong-token' );
$still_held = WorkerLock::snapshot();
assert_worker_lock_true( 'held' === $still_held['lock_status'], 'wrong token does not release lock' );

WorkerLock::release( (string) $replacement['lock_token'] );
assert_worker_lock_true( 'unlocked' === WorkerLock::snapshot()['lock_status'], 'replacement lock releases cleanly' );

echo "OK ({$assertions} assertions)\n";
