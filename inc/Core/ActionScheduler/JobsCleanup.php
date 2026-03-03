<?php
/**
 * Failed Jobs Cleanup
 *
 * Periodically removes stale failed jobs from the jobs table.
 * Prevents indefinite accumulation of failed job records.
 *
 * @package DataMachine\Core\ActionScheduler
 * @since 0.28.0
 */

namespace DataMachine\Core\ActionScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Register the cleanup action handler.
 */
add_action(
	'datamachine_cleanup_failed_jobs',
	function () {
		$db_jobs = new \DataMachine\Core\Database\Jobs\Jobs();

		/**
		 * Filter the maximum age (in days) for failed jobs before cleanup.
		 *
		 * Jobs with a "failed" status (including compound statuses like
		 * "failed - timeout") older than this threshold will be deleted.
		 *
		 * @since 0.28.0
		 *
		 * @param int $max_age_days Maximum age in days. Default 30.
		 */
		$max_age_days = (int) apply_filters( 'datamachine_failed_jobs_max_age_days', 30 );

		if ( $max_age_days < 1 ) {
			$max_age_days = 30;
		}

		$deleted = $db_jobs->delete_old_jobs( 'failed', $max_age_days );

		if ( false !== $deleted && $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Scheduled cleanup: deleted old failed jobs',
				array(
					'jobs_deleted' => $deleted,
					'max_age_days' => $max_age_days,
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

		if ( ! as_next_scheduled_action( 'datamachine_cleanup_failed_jobs', array(), 'datamachine-maintenance' ) ) {
			as_schedule_recurring_action(
				time() + DAY_IN_SECONDS,
				DAY_IN_SECONDS,
				'datamachine_cleanup_failed_jobs',
				array(),
				'datamachine-maintenance'
			);
		}
	}
);
