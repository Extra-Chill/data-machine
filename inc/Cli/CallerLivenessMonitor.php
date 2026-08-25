<?php
/**
 * CLI caller-liveness guard.
 *
 * @package DataMachine\Cli
 */

namespace DataMachine\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Stops a long-running CLI operation when its direct caller disappears.
 */
class CallerLivenessMonitor {

	/** Whether monitoring is active. */
	private static bool $active                        = false;
	/** Original parent process ID. */
	private static int $parent_pid                    = 0;
	/** Previous alarm handler. */
	private static $previous_handler                  = null;
	/** Previous asynchronous-signal mode. */
	private static bool $previous_async_signals        = false;
	/** Previous termination handlers keyed by signal. */
	private static array $previous_termination_handlers = array();
	/** Original process group. */
	private static int $previous_process_group        = 0;
	/** Whether start() isolated the process group. */
	private static bool $changed_process_group         = false;

	/**
	 * Start monitoring when the host provides asynchronous POSIX signals.
	 */
	public static function start(): bool {
		if ( self::$active || ! function_exists( 'posix_getppid' ) || ! function_exists( 'pcntl_alarm' ) || ! function_exists( 'pcntl_signal' ) || ! function_exists( 'pcntl_async_signals' ) ) {
			return false;
		}
		$pending_alarm = pcntl_alarm( 0 );
		if ( $pending_alarm > 0 ) {
			pcntl_alarm( $pending_alarm );
			return false;
		}

		$parent_pid = posix_getppid();
		if ( $parent_pid <= 1 ) {
			return false;
		}

		self::$parent_pid             = $parent_pid;
		self::$previous_handler       = function_exists( 'pcntl_signal_get_handler' ) ? pcntl_signal_get_handler( SIGALRM ) : SIG_DFL;
		self::$previous_async_signals = pcntl_async_signals();
		self::$active                 = true;

		if ( function_exists( 'posix_getpgrp' ) && function_exists( 'posix_setpgid' ) ) {
			self::$previous_process_group = posix_getpgrp();
			if ( getmypid() !== self::$previous_process_group ) {
				self::$changed_process_group = posix_setpgid( 0, 0 );
			}
		}

		pcntl_async_signals( true );
		pcntl_signal( SIGALRM, array( self::class, 'check' ) );
		foreach ( array( SIGTERM, SIGINT, SIGHUP ) as $signal ) {
			self::$previous_termination_handlers[ $signal ] = function_exists( 'pcntl_signal_get_handler' ) ? pcntl_signal_get_handler( $signal ) : SIG_DFL;
			pcntl_signal( $signal, array( self::class, 'terminate' ) );
		}
		pcntl_alarm( 1 );
		return true;
	}

	/**
	 * Alarm callback. A changed parent means the invoking process exited.
	 */
	public static function check(): void {
		if ( ! self::$active ) {
			return;
		}

		if ( posix_getppid() !== self::$parent_pid ) {
			self::terminate( SIGTERM );
		}

		pcntl_alarm( 1 );
	}

	/**
	 * Terminate this operation's process group, including callback descendants.
	 *
	 * @param int $signal Termination signal.
	 */
	public static function terminate( int $signal ): void {
		pcntl_alarm( 0 );
		foreach ( array( SIGTERM, SIGINT, SIGHUP ) as $handled_signal ) {
			pcntl_signal( $handled_signal, SIG_DFL );
		}

		$owns_process_group = function_exists( 'posix_getpgrp' ) && posix_getpgrp() === getmypid();
		if ( $owns_process_group && function_exists( 'posix_kill' ) ) {
			posix_kill( 0, $signal );
		}

		exit( 128 + $signal ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer process exit status.
	}

	/**
	 * Restore the caller's signal and process-group state.
	 */
	public static function stop(): void {
		if ( ! self::$active ) {
			return;
		}

		pcntl_alarm( 0 );
		pcntl_signal( SIGALRM, self::$previous_handler ?? SIG_DFL );
		foreach ( self::$previous_termination_handlers as $signal => $handler ) {
			pcntl_signal( $signal, $handler );
		}
		if ( self::$changed_process_group && self::$previous_process_group > 0 ) {
			posix_setpgid( 0, self::$previous_process_group );
		}
		pcntl_async_signals( self::$previous_async_signals );
		self::$active                        = false;
		self::$parent_pid                    = 0;
		self::$previous_handler              = null;
		self::$previous_async_signals        = false;
		self::$previous_termination_handlers = array();
		self::$previous_process_group        = 0;
		self::$changed_process_group         = false;
	}
}
