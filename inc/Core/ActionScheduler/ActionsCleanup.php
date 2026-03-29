<?php
/**
 * Action Scheduler Completed Actions Cleanup
 *
 * Periodically removes old completed and canceled actions from the
 * actionscheduler_actions and actionscheduler_logs tables.
 *
 * Action Scheduler has its own ActionScheduler_QueueCleaner, but it defaults
 * to 31-day retention and modest batch sizes. On high-throughput Data Machine
 * sites generating hundreds of actions per day, these tables become the
 * second-largest database consumers.
 *
 * @package DataMachine\Core\ActionScheduler
 * @since 0.40.0
 */

namespace DataMachine\Core\ActionScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Register the cleanup action handler.
 */
add_action(
	'datamachine_cleanup_as_actions',
	function () {
		global $wpdb;

		/**
		 * Filter the maximum age (in days) for completed Action Scheduler actions.
		 *
		 * Completed, failed, and canceled actions older than this threshold
		 * will be deleted along with their associated log entries.
		 *
		 * @since 0.40.0
		 *
		 * @param int $max_age_days Maximum age in days. Default 7.
		 */
		$max_age_days = (int) apply_filters( 'datamachine_as_actions_max_age_days', 7 );

		if ( $max_age_days < 1 ) {
			$max_age_days = 7;
		}

		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_days * DAY_IN_SECONDS ) );
		$actions_table   = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table      = $wpdb->prefix . 'actionscheduler_logs';

		// Delete AS log entries for old completed/failed/canceled actions first (FK-safe order).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$logs_deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE l FROM {$logs_table} l
				INNER JOIN {$actions_table} a ON l.action_id = a.action_id
				WHERE a.status IN ('complete', 'failed', 'canceled')
				AND a.last_attempt_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff_datetime
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// Delete the completed/failed/canceled actions themselves.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		$actions_deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$actions_table}
				WHERE status IN ('complete', 'failed', 'canceled')
				AND last_attempt_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff_datetime
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		$total_deleted = ( false !== $logs_deleted ? $logs_deleted : 0 )
			+ ( false !== $actions_deleted ? $actions_deleted : 0 );

		if ( $total_deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Scheduled cleanup: deleted old Action Scheduler actions and logs',
				array(
					'actions_deleted' => false !== $actions_deleted ? $actions_deleted : 0,
					'logs_deleted'    => false !== $logs_deleted ? $logs_deleted : 0,
					'max_age_days'    => $max_age_days,
				)
			);
		}
	}
);

/**
 * Schedule the cleanup job after Action Scheduler is initialized.
 * Only runs in admin context to avoid database queries on frontend.
 */
add_action(
	'action_scheduler_init',
	function () {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! as_next_scheduled_action( 'datamachine_cleanup_as_actions', array(), 'datamachine-maintenance' ) ) {
			as_schedule_recurring_action(
				time() + DAY_IN_SECONDS,
				DAY_IN_SECONDS,
				'datamachine_cleanup_as_actions',
				array(),
				'datamachine-maintenance'
			);
		}
	}
);
