<?php
/**
 * Process-level exit coverage for the Data Machine worker deadline guard.
 *
 * Run with: php tests/worker-process-exit-smoke.php
 *
 * `worker run` already bounds its work loop, but `--once` and `--time-limit`
 * are process-level promises (see issue #3431): after a terminal pass the
 * process must exit, and the wall-clock budget must terminate a hung pass or
 * shutdown path without an external kill. This smoke test arms the same
 * WorkerProcessDeadline guard the CLI command arms, models both failure shapes
 * in child processes, and asserts each child terminates within a bound.
 *
 * @package DataMachine\Tests
 */

namespace {
	$worker_command_file = __DIR__ . '/../inc/Cli/Commands/WorkerCommand.php';
	$deadline_file       = __DIR__ . '/../inc/Cli/WorkerProcessDeadline.php';
	$worker_src          = file_get_contents( $worker_command_file ) ?: '';
	$deadline_src        = file_get_contents( $deadline_file ) ?: '';

	$assertions = 0;

	$assert_true = static function ( bool $condition, string $message ): void {
		global $assertions;
		++$assertions;
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	};

	$assert_contains = static function ( string $needle, string $haystack, string $message ) use ( $assert_true ): void {
		$assert_true( false !== strpos( $haystack, $needle ), $message );
	};

	$assert_contains( 'WorkerProcessDeadline::arm( $time_limit )', $worker_src, 'worker arms the process deadline for its time limit' );
	$assert_contains( 'if ( $time_limit > 0 )', $worker_src, 'worker only arms the deadline when a time limit is configured' );
	$assert_contains( 'pcntl_alarm', $deadline_src, 'deadline guard enforces its bound with a process alarm' );
	$assert_contains( 'SIGKILL', $deadline_src, 'deadline guard escalates when its first exit is trapped' );

	$mode = $argv[1] ?? '';
	if ( ! in_array( $mode, array( 'once', 'time-limit', 'normal' ), true ) ) {
		$run_child = static function ( string $child_mode, int $expected_exit, float $bound_seconds ) use ( $assert_true ): void {
			$started_at = microtime( true );
			$process    = proc_open(
				array( PHP_BINARY, __FILE__, $child_mode ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			$assert_true( is_resource( $process ), "child process {$child_mode} started." );

			$status = array( 'running' => true );
			while ( $status['running'] ) {
				$status = proc_get_status( $process );
				if ( $status['running'] ) {
					$assert_true( microtime( true ) - $started_at <= $bound_seconds, "child process {$child_mode} terminated within {$bound_seconds} seconds without an external kill." );
					usleep( 50000 );
				}
			}

			$assert_true( $expected_exit === $status['exitcode'], "child process {$child_mode} exited with status {$expected_exit}." );
			stream_get_contents( $pipes[1] );
			stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
		};

		if ( ! function_exists( 'pcntl_alarm' ) ) {
			echo "SKIP ({$assertions} assertions; pcntl unavailable, loop-level bounds unchanged)\n";
			exit( 0 );
		}

		$run_child( 'once', 124, 8.0 );
		$run_child( 'time-limit', 124, 8.0 );
		$run_child( 'normal', 0, 8.0 );

		echo "OK ({$assertions} assertions)\n";
		exit( 0 );
	}

	define( 'ABSPATH', __DIR__ . '/../' );
	require_once __DIR__ . '/../inc/Cli/WorkerProcessDeadline.php';

	if ( 'once' === $mode ) {
		// The observed #3431 failure: the terminal summary is printed, then the
		// shutdown path never returns.
		\DataMachine\Cli\WorkerProcessDeadline::arm( 1, 2 );
		echo "passes 1 stop_reason once\n";
		register_shutdown_function(
			static function (): void {
				$started_at = microtime( true );
				while ( microtime( true ) - $started_at < 60 ) {
					// Model the unreleased shutdown-path handle.
				}
			}
		);
		exit( 0 );
	}

	if ( 'time-limit' === $mode ) {
		// A pass that ignores every cooperative bound and burns CPU.
		\DataMachine\Cli\WorkerProcessDeadline::arm( 1, 2 );
		echo "pass\n";
		while ( true ) {
			// Model a non-terminating work loop.
		}
	}

	\DataMachine\Cli\WorkerProcessDeadline::arm( 30, 2 );
	echo "ok\n";
	exit( 0 );
}
