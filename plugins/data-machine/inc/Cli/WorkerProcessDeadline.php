<?php
/**
 * CLI worker process deadline guard.
 *
 * @package DataMachine\Cli
 */

namespace DataMachine\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Terminate a worker process that exceeds its wall-clock budget.
 *
 * The worker loop already stops at its time limit, but operators schedule
 * `worker run --once` under "skip if the previous run is still going"
 * supervisors, which need a process-level bound: a drain pass, output step, or
 * shutdown callback that never returns must not keep the process resident and
 * burning CPU. This guard arms a SIGALRM watchdog for the full budget; when it
 * fires the process emits a warning and exits with the conventional timeout
 * status 124. If that exit is trapped by a hung shutdown path, a short
 * follow-up alarm escalates to SIGKILL so the bound still holds.
 *
 * Hosts without pcntl simply keep the loop-level bounds.
 */
class WorkerProcessDeadline {

	/** Follow-up grace in seconds before escalating to SIGKILL. */
	private const HARD_KILL_GRACE_SECONDS = 30;

	/** Exit status reported when the deadline fires. */
	public const TIMEOUT_EXIT_CODE = 124;

	/** Whether the watchdog is armed. */
	private static bool $armed = false;

	/** Whether the deadline fired and termination started. */
	private static bool $terminating = false;

	/** Armed budget in seconds. */
	private static int $deadline_seconds = 0;

	/** Grace in seconds before escalating to SIGKILL. */
	private static int $grace_seconds = self::HARD_KILL_GRACE_SECONDS;

	/** Previous SIGALRM handler. */
	private static mixed $previous_handler = null;

	/** Previous asynchronous-signal mode. */
	private static bool $previous_async_signals = false;

	/**
	 * Arm the watchdog for a wall-clock budget.
	 *
	 * @param int $seconds       Deadline in seconds.
	 * @param int $grace_seconds Grace in seconds before escalating to SIGKILL.
	 * @return bool True when the watchdog is armed.
	 */
	public static function arm( int $seconds, int $grace_seconds = self::HARD_KILL_GRACE_SECONDS ): bool {
		if ( self::$armed || $seconds <= 0 ) {
			return false;
		}

		if ( ! function_exists( 'pcntl_alarm' ) || ! function_exists( 'pcntl_signal' ) || ! function_exists( 'pcntl_async_signals' ) ) {
			return false;
		}

		$pending_alarm = pcntl_alarm( 0 );
		if ( $pending_alarm > 0 ) {
			pcntl_alarm( $pending_alarm );
			return false;
		}

		self::$deadline_seconds       = $seconds;
		self::$grace_seconds          = max( 1, $grace_seconds );
		self::$previous_handler       = function_exists( 'pcntl_signal_get_handler' ) ? pcntl_signal_get_handler( SIGALRM ) : SIG_DFL;
		self::$previous_async_signals = pcntl_async_signals();
		self::$armed                  = true;

		pcntl_async_signals( true );
		pcntl_signal( SIGALRM, array( self::class, 'check' ) );
		pcntl_alarm( $seconds );

		return true;
	}

	/**
	 * Alarm callback. The first fire terminates with the timeout status; a
	 * later fire during a trapped exit escalates to SIGKILL.
	 */
	public static function check(): void {
		if ( ! self::$armed ) {
			return;
		}

		if ( ! self::$terminating ) {
			self::$terminating = true;
			pcntl_alarm( self::$grace_seconds );

			$message = sprintf(
				'Data Machine worker exceeded its %d second time limit; forcing process exit.',
				self::$deadline_seconds
			);

			if ( class_exists( 'WP_CLI' ) ) {
				\WP_CLI::warning( $message );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- STDERR is the process diagnostic stream, not a filesystem path; WP_Filesystem cannot write to it, and this runs from a signal handler where WP_CLI is unavailable.
				fwrite( STDERR, 'Warning: ' . $message . PHP_EOL );
			}

			exit( self::TIMEOUT_EXIT_CODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer process exit status.
		}

		$pid = getmypid();
		if ( function_exists( 'posix_kill' ) && false !== $pid ) {
			posix_kill( $pid, SIGKILL );
		}

		exit( self::TIMEOUT_EXIT_CODE + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer process exit status.
	}

	/**
	 * Disarm the watchdog and restore the caller's signal state.
	 */
	public static function disarm(): void {
		if ( ! self::$armed ) {
			return;
		}

		pcntl_alarm( 0 );
		pcntl_signal( SIGALRM, self::$previous_handler ?? SIG_DFL );
		pcntl_async_signals( self::$previous_async_signals );
		self::$armed                  = false;
		self::$terminating            = false;
		self::$deadline_seconds       = 0;
		self::$grace_seconds          = self::HARD_KILL_GRACE_SECONDS;
		self::$previous_handler       = null;
		self::$previous_async_signals = false;
	}
}
