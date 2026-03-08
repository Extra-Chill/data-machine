<?php
/**
 * Scheduled Log Cleanup
 *
 * Periodically prunes old log entries from the database.
 *
 * @package DataMachine\Core\ActionScheduler
 * @since 0.37.1
 */

namespace DataMachine\Core\ActionScheduler;

use DataMachine\Core\Database\Logs\LogRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Register the log cleanup action handler.
 */
add_action(
	'datamachine_cleanup_logs',
	function () {
		/**
		 * Filter the maximum log entry age in days before cleanup.
		 *
		 * @since 0.37.1
		 *
		 * @param int $max_age_days Maximum log entry age in days. Default 30.
		 */
		$max_age_days = apply_filters( 'datamachine_log_max_age_days', 30 );

		$before_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_days * DAY_IN_SECONDS ) );

		$repo    = new LogRepository();
		$deleted = $repo->prune_before( $before_datetime );

		if ( $deleted && $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Scheduled log cleanup completed',
				array(
					'max_age_days' => $max_age_days,
					'rows_deleted' => $deleted,
				)
			);
		}
	}
);

/**
 * Schedule the log cleanup after Action Scheduler is initialized.
 * Runs daily to prune old entries.
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
