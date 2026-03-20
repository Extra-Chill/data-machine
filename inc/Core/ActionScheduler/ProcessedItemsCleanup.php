<?php
/**
 * Processed Items Cleanup
 *
 * Periodically removes old dedup records from the processed_items table.
 * These records prevent re-processing of already-seen items, but entries
 * older than the retention threshold are unlikely to be re-encountered.
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
	'datamachine_cleanup_processed_items',
	function () {
		$db_processed = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();

		/**
		 * Filter the maximum age (in days) for processed items before cleanup.
		 *
		 * Dedup records older than this threshold will be deleted. Items not
		 * re-encountered within this window can be safely re-processed if they
		 * appear again.
		 *
		 * @since 0.40.0
		 *
		 * @param int $max_age_days Maximum age in days. Default 30.
		 */
		$max_age_days = (int) apply_filters( 'datamachine_processed_items_max_age_days', 30 );

		if ( $max_age_days < 1 ) {
			$max_age_days = 30;
		}

		$deleted = $db_processed->delete_old_processed_items( $max_age_days );

		if ( false !== $deleted && $deleted > 0 ) {
			do_action(
				'datamachine_log',
				'info',
				'Scheduled cleanup: deleted old processed items',
				array(
					'items_deleted' => $deleted,
					'max_age_days'  => $max_age_days,
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

		if ( ! as_next_scheduled_action( 'datamachine_cleanup_processed_items', array(), 'datamachine-maintenance' ) ) {
			as_schedule_recurring_action(
				time() + DAY_IN_SECONDS,
				DAY_IN_SECONDS,
				'datamachine_cleanup_processed_items',
				array(),
				'datamachine-maintenance'
			);
		}
	}
);
