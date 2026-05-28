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

function update_option( string $name, mixed $value, string|bool|null $autoload = null ): bool {
	unset( $autoload );
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

$global = WorkerLock::acquire( 'global worker', 120 );
$publish = WorkerLock::acquire( 'publish worker', 120, 'publish' );
assert_worker_lock_true( true === $global['acquired'], 'global lock still acquires through legacy option' );
assert_worker_lock_true( true === $publish['acquired'], 'lane lock can run alongside global lock' );
assert_worker_lock_true( 'publish' === $publish['lock_lane'], 'lane lock reports lock lane' );

$publish_overlap = WorkerLock::acquire( 'publish worker two', 120, 'publish' );
assert_worker_lock_true( false === $publish_overlap['acquired'], 'same lane lock excludes overlapping lane worker' );
assert_worker_lock_true( 'publish worker' === $publish_overlap['lock_owner'], 'same lane overlap reports lane owner' );

$background = WorkerLock::acquire( 'background worker', 120, 'background' );
assert_worker_lock_true( true === $background['acquired'], 'different lane lock can run alongside publish lane' );

WorkerLock::release( (string) $global['lock_token'] );
WorkerLock::release( (string) $publish['lock_token'], 'publish' );
WorkerLock::release( (string) $background['lock_token'], 'background' );
assert_worker_lock_true( 'unlocked' === WorkerLock::snapshot()['lock_status'], 'global lock releases after lane test' );
assert_worker_lock_true( 'unlocked' === WorkerLock::snapshot( null, 600, 'publish' )['lock_status'], 'publish lane lock releases after lane test' );
assert_worker_lock_true( 'unlocked' === WorkerLock::snapshot( null, 600, 'background' )['lock_status'], 'background lane lock releases after lane test' );

echo "OK ({$assertions} assertions)\n";
