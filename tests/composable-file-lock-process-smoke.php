<?php
/**
 * Process-level coverage for composable-file lock ownership and recovery.
 *
 * Run with: php tests/composable-file-lock-process-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'pcntl_waitpid' ) || ! function_exists( 'posix_kill' ) ) {
	echo "SKIP: PCNTL and POSIX process control are required.\n";
	exit( 0 );
}

define( 'ABSPATH', __DIR__ );

/**
 * Minimal WordPress JSON helper for this standalone smoke test.
 */
function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags );
}

require_once dirname( __DIR__ ) . '/inc/Engine/AI/ComposableFileLock.php';

use DataMachine\Engine\AI\ComposableFileLock;

$directory   = sys_get_temp_dir() . '/dm-compose-lock-' . bin2hex( random_bytes( 5 ) );
$filepath    = $directory . '/AGENTS.md';
$result_file = $directory . '/result.json';
mkdir( $directory, 0700 );

$fail = static function ( string $message ) use ( $directory, $filepath, $result_file ): void {
	@unlink( $result_file );
	@unlink( $directory . '/.' . basename( $filepath ) . '.compose.lock' );
	@rmdir( $directory );
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
};

$first = ComposableFileLock::acquire( 'AGENTS.md', $filepath, 100 );
if ( ! $first['acquired'] || ! $first['lock'] instanceof ComposableFileLock ) {
	$fail( 'first owner did not acquire the lock.' );
}

$contender_pid = pcntl_fork();
if ( 0 === $contender_pid ) {
	$contender = ComposableFileLock::acquire( 'AGENTS.md', $filepath, 100 );
	file_put_contents( $result_file, json_encode( $contender['diagnostic'] ), LOCK_EX );
	exit( $contender['acquired'] ? 2 : 0 );
}
pcntl_waitpid( $contender_pid, $status );
$blocked = json_decode( (string) file_get_contents( $result_file ), true );
if ( ! pcntl_wifexited( $status ) || 0 !== pcntl_wexitstatus( $status ) || 'blocked' !== ( $blocked['lock_status'] ?? '' ) || getmypid() !== ( $blocked['owner_pid'] ?? 0 ) ) {
	$fail( 'contender did not return bounded typed owner diagnostics.' );
}

$after_fork = ComposableFileLock::acquire( 'AGENTS.md', $filepath, 0 );
if ( $after_fork['acquired'] ) {
	$fail( 'forked contender destructor released the parent owner lock.' );
}
$first['lock']->release();

$owner_pid = pcntl_fork();
if ( 0 === $owner_pid ) {
	$owned = ComposableFileLock::acquire( 'AGENTS.md', $filepath, 100 );
	file_put_contents( $result_file, $owned['acquired'] ? 'ready' : 'failed', LOCK_EX );
	while ( true ) {
		usleep( 100000 );
	}
}

$deadline = microtime( true ) + 2.0;
do {
	if ( 'ready' === (string) file_get_contents( $result_file ) ) {
		break;
	}
	usleep( 10000 );
} while ( microtime( true ) < $deadline );

posix_kill( $owner_pid, SIGKILL );
pcntl_waitpid( $owner_pid, $status );
$stale = ComposableFileLock::snapshot( 'AGENTS.md', $filepath );
if ( 'stale' !== $stale['lock_status'] || $owner_pid !== $stale['owner_pid'] || $stale['owner_alive'] ) {
	$fail( 'killed owner did not leave non-blocking stale diagnostics.' );
}

$replacement = ComposableFileLock::acquire( 'AGENTS.md', $filepath, 100 );
if ( ! $replacement['acquired'] || ! $replacement['lock'] instanceof ComposableFileLock ) {
	$fail( 'stale owner metadata prevented lock takeover.' );
}
$replacement['lock']->release();

@unlink( $result_file );
@unlink( $directory . '/.' . basename( $filepath ) . '.compose.lock' );
@rmdir( $directory );
echo "OK (live blocker and stale-owner recovery)\n";
