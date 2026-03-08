<?php
/**
 * Audit Log Cleanup
 *
 * Periodically removes old audit log entries to prevent indefinite
 * accumulation. Default retention: 30 days.
 *
 * @package DataMachine\Core\ActionScheduler
 * @since 0.42.0
 */

namespace DataMachine\Core\ActionScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Register the cleanup action handler.
 */
add_action(
	'datamachine_cleanup_audit_log',
	function () {
		$log_repo = new \DataMachine\Core\Database\Agents\AgentLog();

		/**
		 * Filter the maximum age (in days) for audit log entries before cleanup.
		 *
		 * Entries older than this threshold will be deleted.
		 *
		 * @since 0.42.0
		 *
		 * @param int $max_age_days Maximum age in days. Default 30.
		 */
		$max_age_days = (int) apply_filters( 'datamachine_audit_log_max_age_days', 30 );

		if ( $max_age_days < 1 ) {
			$max_age_days = 30;
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_days * DAY_IN_SECONDS ) );
		$deleted     = $log_repo->prune_before( $cutoff_date );

		if ( false !== $deleted && $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Scheduled cleanup: deleted old audit log entries',
				array(
					'entries_deleted' => $deleted,
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

		if ( ! as_next_scheduled_action( 'datamachine_cleanup_audit_log', array(), 'datamachine-maintenance' ) ) {
			as_schedule_recurring_action(
				time() + DAY_IN_SECONDS,
				DAY_IN_SECONDS,
				'datamachine_cleanup_audit_log',
				array(),
				'datamachine-maintenance'
			);
		}
	}
);
