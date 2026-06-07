<?php
/**
 * Shared retention cleanup operations.
 *
 * @package DataMachine\Engine\AI\System\Tasks\Retention
 * @since TBD
 */

namespace DataMachine\Engine\AI\System\Tasks\Retention;

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Chat\Chat;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Logs\LogRepository;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\FilesRepository\FileCleanup;
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
		global $wpdb;

		$cutoff        = gmdate( 'Y-m-d H:i:s', time() - ( self::actionSchedulerMaxAgeDays() * DAY_IN_SECONDS ) );
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$actions_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE status IN (%s, %s, %s)
				AND last_attempt_gmt < %s',
				$actions_table,
				'complete',
				'failed',
				'canceled',
				$cutoff
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i l
				INNER JOIN %i a ON l.action_id = a.action_id
				WHERE a.status IN (%s, %s, %s)
				AND a.last_attempt_gmt < %s',
				$logs_table,
				$actions_table,
				'complete',
				'failed',
				'canceled',
				$cutoff
			)
		);

		return $actions_count + $logs_count;
	}

	public static function cleanupActionSchedulerActions(): array {
		global $wpdb;

		$max_age_days  = self::actionSchedulerMaxAgeDays();
		$cutoff        = gmdate( 'Y-m-d H:i:s', time() - ( $max_age_days * DAY_IN_SECONDS ) );
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs_deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE l FROM %i l
				INNER JOIN %i a ON l.action_id = a.action_id
				WHERE a.status IN (%s, %s, %s)
				AND a.last_attempt_gmt < %s',
				$logs_table,
				$actions_table,
				'complete',
				'failed',
				'canceled',
				$cutoff
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$actions_deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE status IN (%s, %s, %s)
				AND last_attempt_gmt < %s',
				$actions_table,
				'complete',
				'failed',
				'canceled',
				$cutoff
			)
		);

		$logs_deleted    = false !== $logs_deleted ? (int) $logs_deleted : 0;
		$actions_deleted = false !== $actions_deleted ? (int) $actions_deleted : 0;
		$total_deleted   = $logs_deleted + $actions_deleted;

		if ( $total_deleted > 0 ) {
			self::log(
				'Scheduled cleanup: deleted old Action Scheduler actions and logs',
				array(
					'actions_deleted' => $actions_deleted,
					'logs_deleted'    => $logs_deleted,
					'max_age_days'    => $max_age_days,
				)
			);
		}

		return array(
			'deleted'         => $total_deleted,
			'actions_deleted' => $actions_deleted,
			'logs_deleted'    => $logs_deleted,
			'max_age_days'    => $max_age_days,
		);
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

	public static function countChatSessions(): int {
		global $wpdb;

		if ( ! Chat::table_exists() ) {
			return 0;
		}

		$table                     = Chat::get_prefixed_table_name();
		$retention_days            = self::chatRetentionDays();
		$transcript_retention_days = self::transcriptRetentionDays();
		$session_cutoff            = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
		$transcript_count          = 0;

		if ( $transcript_retention_days > 0 ) {
			$transcript_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$transcript_retention_days} days" ) );
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
