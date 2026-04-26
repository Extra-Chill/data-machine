<?php
/**
 * WP-CLI Retention Command
 *
 * Provides CLI access to Data Machine data retention policies,
 * table size visibility, and manual cleanup execution.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.40.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Core\Database\BaseRepository;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Engine\AI\System\Tasks\Retention\RetentionCleanup;
use DataMachine\Engine\Tasks\TaskScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage data retention policies and cleanup.
 *
 * ## EXAMPLES
 *
 *     # Show current retention policies and table sizes
 *     wp datamachine retention show
 *
 *     # Preview what would be purged
 *     wp datamachine retention run --dry-run
 *
 *     # Execute cleanup now
 *     wp datamachine retention run
 */
class RetentionCommand extends BaseCommand {

	/**
	 * Show current retention policies and table sizes.
	 *
	 * Displays the configured retention thresholds for each data domain
	 * alongside current table sizes to help operators understand database
	 * pressure.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine retention show
	 *     wp datamachine retention show --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function show( array $args, array $assoc_args ): void {
		$policies = $this->get_retention_policies();
		$sizes    = $this->get_table_sizes();

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%BRetention Policies%n' ) );
		WP_CLI::log( '' );

		$policy_items = array();
		foreach ( $policies as $domain => $policy ) {
			$size_info      = $sizes[ $domain ] ?? array();
			$policy_items[] = array(
				'domain'    => $domain,
				'retention' => $policy['retention'],
				'filter'    => $policy['filter'],
				'rows'      => $size_info['rows'] ?? 'N/A',
				'size_mb'   => $size_info['size_mb'] ?? 'N/A',
			);
		}

		$this->format_items(
			$policy_items,
			array( 'domain', 'retention', 'rows', 'size_mb', 'filter' ),
			$assoc_args
		);

		// Show total.
		$total_mb = 0;
		foreach ( $sizes as $size_info ) {
			$total_mb += (float) ( $size_info['size_mb'] ?? 0 );
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total tracked table size: %.1f MB', $total_mb ) );
	}

	/**
	 * Schedule retention cleanup for all data domains.
	 *
	 * Enqueues cleanup SystemTask jobs for domains with eligible data,
	 * regardless of their recurring timing. Use --dry-run to preview what
	 * would be deleted without scheduling cleanup jobs.
	 *
	 * [--dry-run]
	 * : Preview what would be purged without deleting anything.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine retention run --dry-run
	 *     wp datamachine retention run --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$results = array();

		foreach ( $this->get_retention_domains() as $domain ) {
			$count  = (int) call_user_func( $domain['count'] );
			$action = $dry_run ? 'would delete' : 'skipped';

			if ( ! $dry_run && $count > 0 ) {
				$job_id = TaskScheduler::schedule( $domain['task_type'], array() );
				$action = $job_id ? 'scheduled job #' . $job_id : 'schedule failed';
			}

			$results[] = array(
				'domain'    => $domain['label'],
				'threshold' => $domain['threshold'],
				'eligible'  => $count,
				'action'    => $action,
			);
		}

		if ( $dry_run ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%YDry run — no data was deleted.%n' ) );
			WP_CLI::log( '' );
		} else {
			WP_CLI::log( '' );
		}

		$this->format_items(
			$results,
			array( 'domain', 'threshold', 'eligible', 'action' ),
			$assoc_args
		);

		if ( ! $dry_run ) {
			$total = array_sum( array_column( $results, 'eligible' ) );
			WP_CLI::success( sprintf( 'Retention cleanup scheduled. %d total eligible rows/items found.', $total ) );
		}
	}

	/**
	 * Get retention task domains for CLI dry-run and manual scheduling.
	 *
	 * @return array<int, array{label:string, task_type:string, threshold:string, count:callable}>
	 */
	private function get_retention_domains(): array {
		return array(
			array(
				'label'     => 'Completed jobs',
				'task_type' => RetentionCleanup::TASK_COMPLETED_JOBS,
				'threshold' => RetentionCleanup::completedJobsMaxAgeDays() . ' days',
				'count'     => array( RetentionCleanup::class, 'countCompletedJobs' ),
			),
			array(
				'label'     => 'Failed jobs',
				'task_type' => RetentionCleanup::TASK_FAILED_JOBS,
				'threshold' => RetentionCleanup::failedJobsMaxAgeDays() . ' days',
				'count'     => array( RetentionCleanup::class, 'countFailedJobs' ),
			),
			array(
				'label'     => 'Pipeline logs',
				'task_type' => RetentionCleanup::TASK_LOGS,
				'threshold' => RetentionCleanup::logsMaxAgeDays() . ' days',
				'count'     => array( RetentionCleanup::class, 'countLogs' ),
			),
			array(
				'label'     => 'Processed items',
				'task_type' => RetentionCleanup::TASK_PROCESSED_ITEMS,
				'threshold' => RetentionCleanup::processedItemsMaxAgeDays() . ' days',
				'count'     => array( RetentionCleanup::class, 'countProcessedItems' ),
			),
			array(
				'label'     => 'AS actions + logs',
				'task_type' => RetentionCleanup::TASK_AS_ACTIONS,
				'threshold' => RetentionCleanup::actionSchedulerMaxAgeDays() . ' days',
				'count'     => array( RetentionCleanup::class, 'countActionSchedulerActions' ),
			),
			array(
				'label'     => 'Stale claims',
				'task_type' => RetentionCleanup::TASK_STALE_CLAIMS,
				'threshold' => round( RetentionCleanup::staleClaimMaxAgeSeconds() / HOUR_IN_SECONDS ) . ' hours',
				'count'     => array( RetentionCleanup::class, 'countStaleClaims' ),
			),
			array(
				'label'     => 'File cleanup',
				'task_type' => RetentionCleanup::TASK_FILES,
				'threshold' => RetentionCleanup::fileRetentionDays() . ' days',
				'count'     => array( RetentionCleanup::class, 'countOldFiles' ),
			),
			array(
				'label'     => 'Chat sessions',
				'task_type' => RetentionCleanup::TASK_CHAT_SESSIONS,
				'threshold' => RetentionCleanup::chatRetentionDays() . ' days',
				'count'     => array( RetentionCleanup::class, 'countChatSessions' ),
			),
		);
	}

	/**
	 * Get the current retention policy configuration.
	 *
	 * @return array<string, array{retention: string, filter: string}>
	 */
	private function get_retention_policies(): array {
		return array(
			'Completed jobs'  => array(
				'retention' => apply_filters( 'datamachine_completed_jobs_max_age_days', 30 ) . ' days',
				'filter'    => 'datamachine_completed_jobs_max_age_days',
			),
			'Failed jobs'     => array(
				'retention' => apply_filters( 'datamachine_failed_jobs_max_age_days', 30 ) . ' days',
				'filter'    => 'datamachine_failed_jobs_max_age_days',
			),
			'Pipeline logs'   => array(
				'retention' => apply_filters( 'datamachine_log_max_age_days', 7 ) . ' days',
				'filter'    => 'datamachine_log_max_age_days',
			),
			'Processed items' => array(
				'retention' => apply_filters( 'datamachine_processed_items_max_age_days', 30 ) . ' days',
				'filter'    => 'datamachine_processed_items_max_age_days',
			),
			'AS actions'      => array(
				'retention' => apply_filters( 'datamachine_as_actions_max_age_days', 7 ) . ' days',
				'filter'    => 'datamachine_as_actions_max_age_days',
			),
			'Stale claims'    => array(
				'retention' => round( apply_filters( 'datamachine_stale_claim_max_age', DAY_IN_SECONDS ) / 3600 ) . ' hours',
				'filter'    => 'datamachine_stale_claim_max_age',
			),
			'Chat sessions'   => array(
				'retention' => \DataMachine\Core\PluginSettings::get( 'chat_retention_days', 90 ) . ' days',
				'filter'    => 'setting: chat_retention_days',
			),
			'File cleanup'    => array(
				'retention' => \DataMachine\Core\PluginSettings::get( 'file_retention_days', 7 ) . ' days',
				'filter'    => 'setting: file_retention_days',
			),
		);
	}

	/**
	 * Get current table sizes for Data Machine and Action Scheduler tables.
	 *
	 * @return array<string, array{rows: int, size_mb: string}>
	 */
	private function get_table_sizes(): array {
		global $wpdb;

		$sizes  = array();
		$tables = array(
			'Completed jobs'  => $wpdb->prefix . 'datamachine_jobs',
			'Failed jobs'     => $wpdb->prefix . 'datamachine_jobs',
			'Pipeline logs'   => $wpdb->prefix . 'datamachine_logs',
			'Processed items' => $wpdb->prefix . 'datamachine_processed_items',
			'AS actions'      => $wpdb->prefix . 'actionscheduler_actions',
			'Stale claims'    => $wpdb->prefix . 'actionscheduler_claims',
		);

		// Deduplicate tables for the query (jobs appears twice).
		$unique_tables = array_unique( array_values( $tables ) );

		$table_data = array();

		if ( BaseRepository::is_sqlite() ) {
			// SQLite: no information_schema. Count rows per table; size is unavailable.
			foreach ( $unique_tables as $tbl ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$count              = (int) $wpdb->get_var(
					$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tbl )
				);
				$table_data[ $tbl ] = array(
					'rows'    => $count,
					'size_mb' => '0.0',
				);
			}
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $unique_tables ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"SELECT table_name, table_rows,
						ROUND((data_length + index_length) / 1024 / 1024, 1) AS size_mb
					FROM information_schema.tables
					WHERE table_schema = DATABASE()
					AND table_name IN ({$placeholders})",
					...$unique_tables
				)
			);

			if ( $results ) {
				foreach ( $results as $row ) {
					$table_data[ $row->table_name ] = array(
						'rows'    => (int) $row->table_rows,
						'size_mb' => $row->size_mb,
					);
				}
			}
		}

		foreach ( $tables as $domain => $table_name ) {
			$sizes[ $domain ] = $table_data[ $table_name ] ?? array(
				'rows'    => 0,
				'size_mb' => '0.0',
			);
		}

		// Chat sessions routes through the conversation store so swapped
		// backends (e.g. AI Framework adapters) can opt into the metrics
		// table or bow out by returning null.
		$chat_metrics = ConversationStoreFactory::get()->get_storage_metrics();
		if ( null !== $chat_metrics ) {
			$sizes['Chat sessions'] = $chat_metrics;
		}

		return $sizes;
	}

}
