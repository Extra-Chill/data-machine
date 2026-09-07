<?php
/**
 * Process-level coverage for abandoned composable-memory callers.
 *
 * Run with: php tests/composable-caller-containment-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'pcntl_waitpid' ) || ! function_exists( 'posix_kill' ) || ! function_exists( 'pcntl_alarm' ) ) {
	echo "SKIP: PCNTL and POSIX process control are required.\n";
	exit( 0 );
}

define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/inc/Cli/CallerLivenessMonitor.php';

use DataMachine\Cli\CallerLivenessMonitor;

$state_file = tempnam( sys_get_temp_dir(), 'dm-compose-caller-' );
if ( false === $state_file ) {
	fwrite( STDERR, "FAIL: unable to create process state file.\n" );
	exit( 1 );
}

$caller_pid = pcntl_fork();
if ( 0 === $caller_pid ) {
	$compose_pid = pcntl_fork();
	if ( 0 === $compose_pid ) {
		if ( ! CallerLivenessMonitor::start() ) {
			exit( 2 );
		}
		$worker_pid = pcntl_fork();
		if ( 0 === $worker_pid ) {
			while ( true ) {
				usleep( 100000 );
			}
		}
		file_put_contents( $state_file, json_encode( array( getmypid(), $worker_pid ) ), LOCK_EX );
		while ( true ) {
			usleep( 100000 );
		}
	}

	while ( true ) {
		usleep( 100000 );
	}
}

$deadline    = microtime( true ) + 3.0;
$compose_pid = 0;
$worker_pid  = 0;
do {
	$pids        = json_decode( (string) file_get_contents( $state_file ), true );
	$compose_pid = (int) ( $pids[0] ?? 0 );
	$worker_pid  = (int) ( $pids[1] ?? 0 );
	if ( $compose_pid > 0 && $worker_pid > 0 ) {
		break;
	}
	usleep( 10000 );
} while ( microtime( true ) < $deadline );

if ( $compose_pid <= 0 || $worker_pid <= 0 ) {
	posix_kill( $caller_pid, SIGKILL );
	pcntl_waitpid( $caller_pid, $status );
	@unlink( $state_file );
	fwrite( STDERR, "FAIL: monitored compose descendant did not start.\n" );
	exit( 1 );
}

posix_kill( $caller_pid, SIGKILL );
pcntl_waitpid( $caller_pid, $status );

$deadline = microtime( true ) + 4.0;
do {
	if ( ! @posix_kill( $compose_pid, 0 ) && ! @posix_kill( $worker_pid, 0 ) ) {
		@unlink( $state_file );
		echo "OK (compose process group terminated after caller death)\n";
		exit( 0 );
	}
	usleep( 20000 );
} while ( microtime( true ) < $deadline );

posix_kill( $compose_pid, SIGKILL );
posix_kill( $worker_pid, SIGKILL );
@unlink( $state_file );
fwrite( STDERR, "FAIL: compose descendant survived caller death.\n" );
exit( 1 );
