<?php
/**
 * Scheduled Log Cleanup
 *
 * Periodically cleans up log files that exceed size limits.
 * Prevents unbounded log growth on high-volume sites (e.g. events pipeline).
 *
 * @package DataMachine\Core\ActionScheduler
 * @since 0.37.1
 */

namespace DataMachine\Core\ActionScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Register the log cleanup action handler.
 */
add_action(
	'datamachine_cleanup_logs',
	function () {
		/**
		 * Filter the maximum log file size in MB before cleanup.
		 *
		 * @since 0.37.1
		 *
		 * @param int $max_size_mb Maximum log file size in MB. Default 50.
		 */
		$max_size_mb = apply_filters( 'datamachine_log_max_size_mb', 50 );

		/**
		 * Filter the maximum log file age in days before cleanup.
		 *
		 * @since 0.37.1
		 *
		 * @param int $max_age_days Maximum log file age in days. Default 30.
		 */
		$max_age_days = apply_filters( 'datamachine_log_max_age_days', 30 );

		$cleaned = datamachine_cleanup_log_files( $max_size_mb, $max_age_days );

		if ( $cleaned ) {
			do_action(
				'datamachine_log',
				'info',
				'Scheduled log cleanup completed',
				array(
					'max_size_mb'  => $max_size_mb,
					'max_age_days' => $max_age_days,
				)
			);
		}
	}
);

/**
 * Schedule the log cleanup after Action Scheduler is initialized.
 * Runs daily to catch any logs that have grown too large.
 */
add_action(
	'action_scheduler_init',
	function () {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! as_next_scheduled_action( 'datamachine_cleanup_logs', array(), 'datamachine-maintenance' ) ) {
			as_schedule_recurring_action(
				time() + DAY_IN_SECONDS,
				DAY_IN_SECONDS,
				'datamachine_cleanup_logs',
				array(),
				'datamachine-maintenance'
			);
		}
	}
);
