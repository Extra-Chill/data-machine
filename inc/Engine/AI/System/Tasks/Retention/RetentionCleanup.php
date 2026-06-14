<?php
/**
 * Shared retention cleanup operations.
 *
 * @package DataMachine\Engine\AI\System\Tasks\Retention
 * @since TBD
 */

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\Chat\Chat;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Logs\LogRepository;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\FilesRepository\FileCleanup;
use DataMachine\Core\JobArtifactSurfaces;
use DataMachine\Core\PluginSettings;

class RetentionCleanup {

	public const TASK_COMPLETED_JOBS  = 'retention_completed_jobs';
	public const TASK_FAILED_JOBS     = 'retention_failed_jobs';
	public const TASK_LOGS            = 'retention_logs';
	public const TASK_PROCESSED_ITEMS = 'retention_processed_items';
	public const TASK_AS_ACTIONS      = 'retention_as_actions';
	public const TASK_STALE_CLAIMS    = 'retention_stale_claims';
	public const TASK_FILES           = 'retention_files';
	public const TASK_CHAT_SESSIONS   = 'retention_chat_sessions';
	public const TASK_JOB_ARTIFACTS   = 'retention_job_artifacts';

	public static function completedJobsMaxAgeDays(): int {
		return self::positiveDays( apply_filters( 'datamachine_completed_jobs_max_age_days', 30 ), 30 );
	}

	public static function failedJobsMaxAgeDays(): int {
		return self::positiveDays( apply_filters( 'datamachine_failed_jobs_max_age_days', 30 ), 30 );
	}

	public static function logsMaxAgeDays(): int {
		return self::positiveDays( apply_filters( 'datamachine_log_max_age_days', 7 ), 7 );
	}

	public static function processedItemsMaxAgeDays(): int {
		return self::positiveDays( apply_filters( 'datamachine_processed_items_max_age_days', 30 ), 30 );
	}

	public static function actionSchedulerMaxAgeDays(): int {
		return self::positiveDays( apply_filters( 'datamachine_as_actions_max_age_days', 7 ), 7 );
	}

	/**
	 * Per-hook Action Scheduler max-age overrides, in days.
	 *
	 * High-volume hooks (e.g. step execution fan-out) accrue hundreds of
	 * thousands of completed rows per day that carry ~zero diagnostic value
	 * after a few hours. Pruning them on a much shorter window than the global
	 * default keeps the Action Scheduler tables from ballooning between runs.
	 *
	 * Returns a map of `hook => max_age_days`. A value of `0` (or less) means
	 * "fall back to the global window" for that hook. Fractional days are
	 * allowed so operators can express sub-day windows (e.g. `0.25` for 6h).
	 *
	 * @return array<string, float> Map of hook name to max age in days.
	 */
	public static function actionSchedulerHookMaxAgeDays(): array {
		$defaults = array(
			// Completed step-execution actions are pure fan-out noise after a
			// few hours; prune them aggressively (6h) to cap steady-state rows.
			'datamachine_execute_step' => 0.25,
		);

		$overrides = apply_filters( 'datamachine_as_actions_hook_max_age_days', $defaults );

		if ( ! is_array( $overrides ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $overrides as $hook => $days ) {
			if ( ! is_string( $hook ) || '' === $hook ) {
				continue;
			}

			$days = (float) $days;
			if ( $days > 0 ) {
				$normalized[ $hook ] = $days;
			}
		}

		return $normalized;
	}

	/**
	 * Number of rows deleted per batched DELETE iteration.
	 *
	 * Bounding each DELETE keeps lock duration and replication lag in check on
	 * multi-million-row Action Scheduler tables where a single unbounded
	 * DELETE would lock and time out.
	 *
	 * @return int Batch size (clamped to a sane floor/ceiling).
	 */
	public static function actionSchedulerBatchSize(): int {
		$size = (int) apply_filters( 'datamachine_retention_batch_size', 25000 );

		if ( $size < 1000 ) {
			$size = 1000;
		} elseif ( $size > 100000 ) {
			$size = 100000;
		}

		return $size;
	}

	/**
	 * Hard ceiling on batched DELETE iterations per cleanup pass.
	 *
	 * Acts as a runaway guard so a single cleanup run can never loop forever
	 * (e.g. if rows are being inserted as fast as they are deleted). At the
	 * default batch size of 25k this bounds a single pass to ~5M rows per
	 * table, after which the next scheduled run picks up where this left off.
	 *
	 * @return int Maximum iterations (always >= 1).
	 */
	public static function actionSchedulerMaxIterations(): int {
		$iterations = (int) apply_filters( 'datamachine_retention_max_iterations', 200 );
		return $iterations > 0 ? $iterations : 200;
	}

	/**
	 * Maximum wall-clock seconds a single batched cleanup pass may run.
	 *
	 * Complements the iteration cap: whichever limit trips first ends the pass
	 * gracefully so retention never blocks the queue indefinitely.
	 *
	 * @return int Maximum seconds (always >= 1).
	 */
	public static function actionSchedulerMaxRuntimeSeconds(): int {
		$seconds = (int) apply_filters( 'datamachine_retention_max_runtime_seconds', 60 );
		return $seconds > 0 ? $seconds : 60;
	}

	/**
	 * Whether to run OPTIMIZE TABLE after a meaningful cleanup pass.
	 *
	 * InnoDB does not return space freed by DELETE to the OS; only a table
	 * rebuild (OPTIMIZE TABLE) shrinks the per-table `.ibd` file. Because
	 * OPTIMIZE rebuilds the table (locking + temp space), it is opt-in and
	 * additionally gated by a row-count threshold so it only runs when a pass
	 * deleted enough rows to be worth the rebuild cost.
	 *
	 * @return bool True when OPTIMIZE TABLE is enabled.
	 */
	public static function actionSchedulerOptimizeEnabled(): bool {
		return (bool) apply_filters( 'datamachine_retention_optimize_tables', false );
	}

	/**
	 * Minimum rows deleted from a table before OPTIMIZE TABLE is considered.
	 *
	 * Even when optimization is enabled, skip the rebuild for small deletions
	 * where the reclaimed space does not justify the lock/temp-space cost.
	 *
	 * @return int Row-count threshold (always >= 1).
	 */
	public static function actionSchedulerOptimizeThreshold(): int {
		$threshold = (int) apply_filters( 'datamachine_retention_optimize_threshold', 100000 );
		return $threshold > 0 ? $threshold : 100000;
	}

	public static function staleClaimMaxAgeSeconds(): int {
		$seconds = absint( apply_filters( 'datamachine_stale_claim_max_age', DAY_IN_SECONDS ) );
		return $seconds > 0 ? $seconds : DAY_IN_SECONDS;
	}

	public static function fileRetentionDays(): int {
		return self::positiveDays( PluginSettings::get( 'file_retention_days', 7 ), 7 );
	}

	public static function chatRetentionDays(): int {
		return self::positiveDays( PluginSettings::get( 'chat_retention_days', 90 ), 90 );
	}

	public static function transcriptRetentionDays(): int {
		return (int) get_option( 'datamachine_pipeline_transcript_retention_days', 30 );
	}

	public static function jobArtifactsMaxAgeDays(): int {
		$policy = JobArtifactSurfaces::retentionPolicies()[ JobArtifactSurfaces::DEFAULT_RETENTION_SCOPE ] ?? array();
		return self::positiveDays( $policy['max_age_days'] ?? 30, 30 );
	}

	public static function jobArtifactRetentionPolicies(): array {
		return JobArtifactSurfaces::retentionPolicies();
	}

	public static function countCompletedJobs(): int {
		return ( new Jobs() )->count_old_jobs( 'completed', self::completedJobsMaxAgeDays() );
	}

	public static function cleanupCompletedJobs(): array {
		$max_age_days = self::completedJobsMaxAgeDays();
		$deleted      = ( new Jobs() )->delete_old_jobs( 'completed', $max_age_days );
		$deleted      = false !== $deleted ? (int) $deleted : 0;

		if ( $deleted > 0 ) {
			self::log(
				'Scheduled cleanup: deleted old completed jobs',
				array(
					'jobs_deleted' => $deleted,
					'max_age_days' => $max_age_days,
				)
			);
		}

		return array(
			'deleted'      => $deleted,
			'max_age_days' => $max_age_days,
		);
	}

	public static function countFailedJobs(): int {
		return ( new Jobs() )->count_old_jobs( 'failed', self::failedJobsMaxAgeDays() );
	}

	public static function cleanupFailedJobs(): array {
		$max_age_days = self::failedJobsMaxAgeDays();
		$deleted      = ( new Jobs() )->delete_old_jobs( 'failed', $max_age_days );
		$deleted      = false !== $deleted ? (int) $deleted : 0;

		if ( $deleted > 0 ) {
			self::log(
				'Scheduled cleanup: deleted old failed jobs',
				array(
					'jobs_deleted' => $deleted,
					'max_age_days' => $max_age_days,
				)
			);
		}

		return array(
			'deleted'      => $deleted,
			'max_age_days' => $max_age_days,
		);
	}

	public static function countLogs(): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::logsMaxAgeDays() * DAY_IN_SECONDS ) );
		$table  = $wpdb->prefix . 'datamachine_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}

	public static function cleanupLogs(): array {
		$max_age_days    = self::logsMaxAgeDays();
		$before_datetime = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_days * DAY_IN_SECONDS ) );
		$deleted         = ( new LogRepository() )->prune_before( $before_datetime );
		$deleted         = $deleted ? (int) $deleted : 0;

		if ( $deleted > 0 ) {
			self::log(
				'Scheduled log cleanup completed',
				array(
					'rows_deleted' => $deleted,
					'max_age_days' => $max_age_days,
				)
			);
		}

		return array(
			'deleted'      => $deleted,
			'max_age_days' => $max_age_days,
		);
	}

	public static function countProcessedItems(): int {
		return ( new ProcessedItems() )->count_old_processed_items( self::processedItemsMaxAgeDays() );
	}

	public static function cleanupProcessedItems(): array {
		$max_age_days = self::processedItemsMaxAgeDays();
		$deleted      = ( new ProcessedItems() )->delete_old_processed_items( $max_age_days );
		$deleted      = false !== $deleted ? (int) $deleted : 0;

		if ( $deleted > 0 ) {
			self::log(
				'Scheduled cleanup: deleted old processed items',
				array(
					'items_deleted' => $deleted,
					'max_age_days'  => $max_age_days,
				)
			);
		}

		return array(
			'deleted'      => $deleted,
			'max_age_days' => $max_age_days,
		);
	}

	public static function countActionSchedulerActions(): int {
		return self::countActionSchedulerBreakdown()['total'];
	}

	/**
	 * Per-table eligible-row breakdown for Action Scheduler cleanup.
	 *
	 * Powers CLI dry-run reporting so operators can see exactly how many rows
	 * would be deleted from each table (actions vs logs) under the combined
	 * per-hook + global retention windows.
	 *
	 * @return array{actions: int, logs: int, total: int}
	 */
	public static function countActionSchedulerBreakdown(): array {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';

		$actions_count = 0;
		$logs_count    = 0;

		foreach ( self::actionSchedulerCleanupWindows() as $window ) {
			$cutoff = $window['cutoff'];
			$hook   = $window['hook'];

			if ( null === $hook ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$actions_count += (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s, %s) AND last_attempt_gmt < %s',
						$actions_table,
						'complete',
						'failed',
						'canceled',
						$cutoff
					)
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$logs_count += (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i l INNER JOIN %i a ON l.action_id = a.action_id WHERE a.status IN (%s, %s, %s) AND a.last_attempt_gmt < %s',
						$logs_table,
						$actions_table,
						'complete',
						'failed',
						'canceled',
						$cutoff
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$actions_count += (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s, %s) AND last_attempt_gmt < %s AND hook = %s',
						$actions_table,
						'complete',
						'failed',
						'canceled',
						$cutoff,
						$hook
					)
				);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$logs_count += (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i l INNER JOIN %i a ON l.action_id = a.action_id WHERE a.status IN (%s, %s, %s) AND a.last_attempt_gmt < %s AND a.hook = %s',
						$logs_table,
						$actions_table,
						'complete',
						'failed',
						'canceled',
						$cutoff,
						$hook
					)
				);
			}
		}

		return array(
			'actions' => $actions_count,
			'logs'    => $logs_count,
			'total'   => $actions_count + $logs_count,
		);
	}

	public static function cleanupActionSchedulerActions(): array {
		global $wpdb;

		$max_age_days    = self::actionSchedulerMaxAgeDays();
		$batch_size      = self::actionSchedulerBatchSize();
		$max_iterations  = self::actionSchedulerMaxIterations();
		$max_runtime     = self::actionSchedulerMaxRuntimeSeconds();
		$actions_table   = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table      = $wpdb->prefix . 'actionscheduler_logs';
		$started_at      = microtime( true );
		$deadline        = $started_at + $max_runtime;
		$iterations_used = 0;
		$hit_limit       = false;
		$logs_deleted    = 0;
		$actions_deleted = 0;

		foreach ( self::actionSchedulerCleanupWindows() as $window ) {
			$cutoff = $window['cutoff'];
			$hook   = $window['hook'];

			// Logs are FK-children of actions; prune them first so deleting the
			// parent action never orphans rows. Both passes are batched by id.
			$logs_deleted += self::deleteActionSchedulerLogsBatched(
				$logs_table,
				$actions_table,
				$cutoff,
				$hook,
				$batch_size,
				$max_iterations,
				$deadline,
				$iterations_used,
				$hit_limit
			);

			$actions_deleted += self::deleteActionSchedulerActionsBatched(
				$actions_table,
				$cutoff,
				$hook,
				$batch_size,
				$max_iterations,
				$deadline,
				$iterations_used,
				$hit_limit
			);
		}

		$total_deleted = $logs_deleted + $actions_deleted;

		// InnoDB never returns DELETE-freed space to the OS; only a table
		// rebuild does. Optionally OPTIMIZE the tables when a pass deleted
		// enough rows to justify the lock/temp-space cost.
		$optimized = self::maybeOptimizeActionSchedulerTables(
			array(
				$actions_table => $actions_deleted,
				$logs_table    => $logs_deleted,
			)
		);

		if ( $total_deleted > 0 || $hit_limit ) {
			self::log(
				'Scheduled cleanup: deleted old Action Scheduler actions and logs',
				array(
					'actions_deleted' => $actions_deleted,
					'logs_deleted'    => $logs_deleted,
					'max_age_days'    => $max_age_days,
					'batch_size'      => $batch_size,
					'iterations'      => $iterations_used,
					'hit_limit'       => $hit_limit,
					'optimized'       => $optimized,
				)
			);
		}

		return array(
			'deleted'         => $total_deleted,
			'actions_deleted' => $actions_deleted,
			'logs_deleted'    => $logs_deleted,
			'max_age_days'    => $max_age_days,
			'batch_size'      => $batch_size,
			'iterations'      => $iterations_used,
			'hit_limit'       => $hit_limit,
			'optimized'       => $optimized,
		);
	}

	/**
	 * Build the set of (hook, cutoff) windows to prune.
	 *
	 * Each per-hook override yields its own aggressive cutoff; everything else
	 * falls under a single global window (hook === null) that explicitly
	 * excludes the overridden hooks so rows are never double-counted/deleted.
	 *
	 * @return array<int, array{hook: ?string, cutoff: string}>
	 */
	private static function actionSchedulerCleanupWindows(): array {
		$now            = time();
		$global_seconds = (int) round( self::actionSchedulerMaxAgeDays() * DAY_IN_SECONDS );
		$windows        = array();

		foreach ( self::actionSchedulerHookMaxAgeDays() as $hook => $days ) {
			$seconds   = (int) round( $days * DAY_IN_SECONDS );
			$windows[] = array(
				'hook'   => $hook,
				'cutoff' => gmdate( 'Y-m-d H:i:s', $now - $seconds ),
			);
		}

		$windows[] = array(
			'hook'   => null,
			'cutoff' => gmdate( 'Y-m-d H:i:s', $now - $global_seconds ),
		);

		return $windows;
	}

	/**
	 * Batched delete of Action Scheduler log rows by id-subquery.
	 *
	 * The multi-table `DELETE l FROM logs l JOIN actions a ... LIMIT` form does
	 * not reliably affect rows across all runtimes, so we select a bounded set
	 * of log_ids and delete by primary key — which is reliable and index-fast.
	 *
	 * @param string  $logs_table      Logs table name.
	 * @param string  $actions_table   Actions table name.
	 * @param string  $cutoff          GMT cutoff datetime.
	 * @param ?string $hook            Hook filter, or null for the global window.
	 * @param int     $batch_size      Rows per batch.
	 * @param int     $max_iterations  Hard iteration ceiling (shared budget).
	 * @param float   $deadline        Wall-clock deadline (microtime float).
	 * @param int     $iterations_used Shared iteration counter (by reference).
	 * @param bool    $hit_limit       Set true when a budget cap trips (by reference).
	 * @return int Total rows deleted.
	 */
	private static function deleteActionSchedulerLogsBatched(
		string $logs_table,
		string $actions_table,
		string $cutoff,
		?string $hook,
		int $batch_size,
		int $max_iterations,
		float $deadline,
		int &$iterations_used,
		bool &$hit_limit
	): int {
		global $wpdb;

		$deleted = 0;

		do {
			if ( $iterations_used >= $max_iterations || microtime( true ) >= $deadline ) {
				$hit_limit = true;
				break;
			}
			++$iterations_used;

			if ( null === $hook ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$affected = $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE log_id IN ( SELECT log_id FROM ( SELECT l.log_id FROM %i l INNER JOIN %i a ON l.action_id = a.action_id WHERE a.status IN (%s, %s, %s) AND a.last_attempt_gmt < %s LIMIT %d ) AS tmp )',
						$logs_table,
						$logs_table,
						$actions_table,
						'complete',
						'failed',
						'canceled',
						$cutoff,
						$batch_size
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$affected = $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE log_id IN ( SELECT log_id FROM ( SELECT l.log_id FROM %i l INNER JOIN %i a ON l.action_id = a.action_id WHERE a.status IN (%s, %s, %s) AND a.last_attempt_gmt < %s AND a.hook = %s LIMIT %d ) AS tmp )',
						$logs_table,
						$logs_table,
						$actions_table,
						'complete',
						'failed',
						'canceled',
						$cutoff,
						$hook,
						$batch_size
					)
				);
			}

			$affected = false !== $affected ? (int) $affected : 0;
			$deleted += $affected;
		} while ( $affected > 0 );

		return $deleted;
	}

	/**
	 * Batched delete of Action Scheduler action rows by id-subquery.
	 *
	 * @param string  $actions_table   Actions table name.
	 * @param string  $cutoff          GMT cutoff datetime.
	 * @param ?string $hook            Hook filter, or null for the global window.
	 * @param int     $batch_size      Rows per batch.
	 * @param int     $max_iterations  Hard iteration ceiling (shared budget).
	 * @param float   $deadline        Wall-clock deadline (microtime float).
	 * @param int     $iterations_used Shared iteration counter (by reference).
	 * @param bool    $hit_limit       Set true when a budget cap trips (by reference).
	 * @return int Total rows deleted.
	 */
	private static function deleteActionSchedulerActionsBatched(
		string $actions_table,
		string $cutoff,
		?string $hook,
		int $batch_size,
		int $max_iterations,
		float $deadline,
		int &$iterations_used,
		bool &$hit_limit
	): int {
		global $wpdb;

		$deleted = 0;

		do {
			if ( $iterations_used >= $max_iterations || microtime( true ) >= $deadline ) {
				$hit_limit = true;
				break;
			}
			++$iterations_used;

			if ( null === $hook ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$affected = $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE action_id IN ( SELECT action_id FROM ( SELECT a.action_id FROM %i a WHERE a.status IN (%s, %s, %s) AND a.last_attempt_gmt < %s LIMIT %d ) AS tmp )',
						$actions_table,
						$actions_table,
						'complete',
						'failed',
						'canceled',
						$cutoff,
						$batch_size
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$affected = $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE action_id IN ( SELECT action_id FROM ( SELECT a.action_id FROM %i a WHERE a.status IN (%s, %s, %s) AND a.last_attempt_gmt < %s AND a.hook = %s LIMIT %d ) AS tmp )',
						$actions_table,
						$actions_table,
						'complete',
						'failed',
						'canceled',
						$cutoff,
						$hook,
						$batch_size
					)
				);
			}

			$affected = false !== $affected ? (int) $affected : 0;
			$deleted += $affected;
		} while ( $affected > 0 );

		return $deleted;
	}

	/**
	 * Optionally OPTIMIZE Action Scheduler tables after a cleanup pass.
	 *
	 * Opt-in (`datamachine_retention_optimize_tables`, default off) and gated by
	 * a per-table row-count threshold so the rebuild only runs when enough rows
	 * were deleted to reclaim meaningful disk. Skipped entirely on SQLite.
	 *
	 * @param array<string, int> $tables_deleted Map of table name => rows deleted.
	 * @return array<int, string> Names of tables that were optimized.
	 */
	private static function maybeOptimizeActionSchedulerTables( array $tables_deleted ): array {
		global $wpdb;

		if ( ! self::actionSchedulerOptimizeEnabled() ) {
			return array();
		}

		if ( class_exists( BaseRepository::class ) && BaseRepository::is_sqlite() ) {
			return array();
		}

		$threshold = self::actionSchedulerOptimizeThreshold();
		$optimized = array();

		foreach ( $tables_deleted as $table => $deleted ) {
			if ( (int) $deleted < $threshold ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'OPTIMIZE TABLE %i', $table ) );
			$optimized[] = $table;
		}

		if ( ! empty( $optimized ) ) {
			self::log(
				'Scheduled cleanup: optimized Action Scheduler tables to reclaim disk',
				array(
					'tables_optimized' => $optimized,
					'threshold'        => $threshold,
				)
			);
		}

		return $optimized;
	}

	public static function countStaleClaims(): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::staleClaimMaxAgeSeconds() );
		$table  = $wpdb->prefix . 'actionscheduler_claims';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE date_created_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}

	public static function cleanupStaleClaims(): array {
		global $wpdb;

		$max_age_seconds = self::staleClaimMaxAgeSeconds();
		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds );
		$table           = $wpdb->prefix . 'actionscheduler_claims';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE date_created_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff_datetime
			)
		);
		$deleted = false !== $deleted ? (int) $deleted : 0;

		if ( $deleted > 0 ) {
			self::log(
				'ActionScheduler: Cleaned up stale claims',
				array(
					'claims_deleted'  => $deleted,
					'max_age_seconds' => $max_age_seconds,
					'cutoff_datetime' => $cutoff_datetime,
				)
			);
		}

		return array(
			'deleted'         => $deleted,
			'max_age_seconds' => $max_age_seconds,
		);
	}

	public static function countOldFiles(): int {
		$upload_dir  = wp_upload_dir();
		$base        = trailingslashit( $upload_dir['basedir'] ) . 'datamachine-files';
		$cutoff_time = time() - ( self::fileRetentionDays() * DAY_IN_SECONDS );
		$count       = 0;

		if ( ! is_dir( $base ) ) {
			return 0;
		}

		$pipeline_dirs = glob( "{$base}/pipeline-*", GLOB_ONLYDIR );
		if ( false === $pipeline_dirs ) {
			$pipeline_dirs = array();
		}

		foreach ( $pipeline_dirs as $pipeline_dir ) {
			$flow_dirs = glob( "{$pipeline_dir}/flow-*", GLOB_ONLYDIR );
			if ( false === $flow_dirs ) {
				$flow_dirs = array();
			}

			foreach ( $flow_dirs as $flow_dir ) {
				$flow_id   = basename( $flow_dir );
				$files_dir = "{$flow_dir}/{$flow_id}-files";

				if ( is_dir( $files_dir ) ) {
					$files = glob( "{$files_dir}/*" );
					if ( false === $files ) {
						$files = array();
					}

					foreach ( $files as $file ) {
						if ( is_file( $file ) && filemtime( $file ) < $cutoff_time ) {
							++$count;
						}
					}
				}

				$jobs_dir = "{$flow_dir}/jobs";
				$job_dirs = glob( "{$jobs_dir}/job-*", GLOB_ONLYDIR );
				if ( false === $job_dirs ) {
					$job_dirs = array();
				}

				foreach ( $job_dirs as $job_dir ) {
					$files = glob( "{$job_dir}/*" );
					if ( false === $files ) {
						$files = array();
					}

					if ( empty( $files ) ) {
						continue;
					}

					$all_old = true;
					foreach ( $files as $file ) {
						if ( is_file( $file ) && filemtime( $file ) >= $cutoff_time ) {
							$all_old = false;
							break;
						}
					}

					if ( $all_old ) {
						++$count;
					}
				}
			}
		}

		return $count;
	}

	public static function cleanupOldFiles(): array {
		$retention_days = self::fileRetentionDays();
		$deleted        = ( new FileCleanup() )->cleanup_old_files( $retention_days );

		do_action(
			'datamachine_log',
			'debug',
			'FilesRepository: Cleanup completed',
			array(
				'files_deleted'  => $deleted,
				'retention_days' => $retention_days,
			)
		);

		return array(
			'deleted'        => (int) $deleted,
			'retention_days' => $retention_days,
		);
	}

	public static function countJobArtifacts(): int {
		return ( new FileCleanup() )->count_old_job_artifacts( JobArtifactSurfaces::DEFAULT_RETENTION_SCOPE, self::jobArtifactsMaxAgeDays() );
	}

	public static function cleanupJobArtifacts(): array {
		$retention_days = self::jobArtifactsMaxAgeDays();
		$deleted        = ( new FileCleanup() )->cleanup_old_job_artifacts( JobArtifactSurfaces::DEFAULT_RETENTION_SCOPE, $retention_days );

		if ( $deleted > 0 ) {
			self::log(
				'Scheduled cleanup: deleted old scoped job artifacts',
				array(
					'artifacts_deleted' => $deleted,
					'retention_scope'   => JobArtifactSurfaces::DEFAULT_RETENTION_SCOPE,
					'max_age_days'      => $retention_days,
				)
			);
		}

		return array(
			'deleted'         => (int) $deleted,
			'retention_scope' => JobArtifactSurfaces::DEFAULT_RETENTION_SCOPE,
			'max_age_days'    => $retention_days,
		);
	}

	public static function countChatSessions(): int {
		global $wpdb;

		if ( ! Chat::table_exists() ) {
			return 0;
		}

		$table                     = Chat::get_prefixed_table_name();
		$retention_days            = self::chatRetentionDays();
		$transcript_retention_days = self::transcriptRetentionDays();
		$session_cutoff            = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$transcript_count          = 0;

		if ( $transcript_retention_days > 0 ) {
			$transcript_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $transcript_retention_days * DAY_IN_SECONDS ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$transcript_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i
					WHERE mode = %s
					AND metadata LIKE %s
					AND updated_at < %s',
					$table,
					'pipeline',
					'%"source":"pipeline_transcript"%',
					$transcript_cutoff
				)
			);
		}

		if ( $transcript_retention_days > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$session_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i
					WHERE updated_at < %s
					AND NOT (mode = %s AND metadata LIKE %s)',
					$table,
					$session_cutoff,
					'pipeline',
					'%"source":"pipeline_transcript"%'
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$session_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE updated_at < %s',
					$table,
					$session_cutoff
				)
			);
		}

		return $transcript_count + $session_count;
	}

	public static function cleanupChatSessions(): array {
		$chat_db = ConversationStoreFactory::get();

		if ( $chat_db instanceof Chat && ! Chat::table_exists() ) {
			return array(
				'deleted'             => 0,
				'sessions_deleted'    => 0,
				'transcripts_deleted' => 0,
			);
		}

		$retention_days            = self::chatRetentionDays();
		$transcript_retention_days = self::transcriptRetentionDays();
		$transcripts_deleted       = 0;

		if ( $transcript_retention_days > 0 && method_exists( $chat_db, 'cleanup_pipeline_transcripts' ) ) {
			$transcripts_deleted = $chat_db->cleanup_pipeline_transcripts( $transcript_retention_days );
		}

		$sessions_deleted = $chat_db->cleanup_old_sessions( $retention_days );

		do_action(
			'datamachine_log',
			'debug',
			'Chat sessions cleanup completed',
			array(
				'sessions_deleted'          => $sessions_deleted,
				'retention_days'            => $retention_days,
				'transcripts_deleted'       => $transcripts_deleted,
				'transcript_retention_days' => $transcript_retention_days,
			)
		);

		return array(
			'deleted'                   => (int) $sessions_deleted + (int) $transcripts_deleted,
			'sessions_deleted'          => (int) $sessions_deleted,
			'transcripts_deleted'       => (int) $transcripts_deleted,
			'retention_days'            => $retention_days,
			'transcript_retention_days' => $transcript_retention_days,
		);
	}

	private static function positiveDays( mixed $value, int $fallback ): int {
		$days = (int) $value;
		return $days > 0 ? $days : $fallback;
	}

	private static function log( string $message, array $context ): void {
		do_action( 'datamachine_log', 'info', $message, $context );
	}
}
