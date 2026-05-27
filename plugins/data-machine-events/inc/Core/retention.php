<?php
/**
 * Retention policy overrides for events.extrachill.com.
 *
 * `data-machine-events` runs at high pipeline volume (~60K AS actions/day,
 * with spikes of 285K/day observed on 2026-05-21). Data Machine core's
 * default retention windows (7 days for AS actions/logs, 30 days for jobs)
 * let the on-disk footprint balloon to multiple GB even when retention is
 * "working" — the working set is just huge.
 *
 * These filters tighten the windows specifically for this plugin's
 * deployment context. The plugin is only activated on events.extrachill.com
 * (blog ID 7), so no blog-ID guard is needed — the filters scope themselves
 * by virtue of the plugin not loading on other sites.
 *
 * Steady-state on-disk savings:
 * - c8c_7_actionscheduler_actions: ~2.0 GB → ~600 MB
 * - c8c_7_actionscheduler_logs:    ~470 MB → ~135 MB
 * - c8c_7_datamachine_jobs:        ~900 MB → ~420 MB
 *
 * See: https://github.com/Extra-Chill/data-machine-events/issues/317
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tighten Action Scheduler completed/failed action retention to 2 days.
 *
 * Default in Data Machine core: 7 days.
 */
add_filter(
	'datamachine_as_actions_max_age_days',
	static function ( $days ) {
		return 2;
	},
	10,
	1
);

/**
 * Tighten Action Scheduler log retention to 2 days.
 *
 * Default in Data Machine core: 7 days.
 */
add_filter(
	'datamachine_log_max_age_days',
	static function ( $days ) {
		return 2;
	},
	10,
	1
);

/**
 * Tighten completed Data Machine job retention to 14 days.
 *
 * Default in Data Machine core: 30 days. Failed jobs use the same window
 * via `datamachine_failed_jobs_max_age_days`.
 */
add_filter(
	'datamachine_completed_jobs_max_age_days',
	static function ( $days ) {
		return 14;
	},
	10,
	1
);

add_filter(
	'datamachine_failed_jobs_max_age_days',
	static function ( $days ) {
		return 14;
	},
	10,
	1
);

/**
 * Tighten processed-items retention to 14 days.
 *
 * Default in Data Machine core: 30 days. Processed-items dedup table grows
 * proportionally to pipeline throughput; 14 days is plenty to catch
 * re-ingest loops without keeping a month of history on disk.
 */
add_filter(
	'datamachine_processed_items_max_age_days',
	static function ( $days ) {
		return 14;
	},
	10,
	1
);
