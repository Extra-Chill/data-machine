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
			$size_info       = $sizes[ $domain ] ?? array();
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
	 * Execute retention cleanup for all data domains.
	 *
	 * Runs all configured cleanup routines immediately, regardless of
	 * their scheduled timing. Use --dry-run to preview what would be
	 * deleted without making changes.
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

		// 1. Completed jobs.
		$completed_days = (int) apply_filters( 'datamachine_completed_jobs_max_age_days', 14 );
		$db_jobs        = new \DataMachine\Core\Database\Jobs\Jobs();
		$count          = $db_jobs->count_old_jobs( 'completed', $completed_days );
		$results[]      = array(
			'domain'    => 'Completed jobs',
			'threshold' => $completed_days . ' days',
			'eligible'  => $count,
			'action'    => $dry_run ? 'would delete' : 'deleted',
		);
		if ( ! $dry_run && $count > 0 ) {
			$db_jobs->delete_old_jobs( 'completed', $completed_days );
		}

		// 2. Failed jobs.
		$failed_days = (int) apply_filters( 'datamachine_failed_jobs_max_age_days', 30 );
		$count       = $db_jobs->count_old_jobs( 'failed', $failed_days );
		$results[]   = array(
			'domain'    => 'Failed jobs',
			'threshold' => $failed_days . ' days',
			'eligible'  => $count,
			'action'    => $dry_run ? 'would delete' : 'deleted',
		);
		if ( ! $dry_run && $count > 0 ) {
			$db_jobs->delete_old_jobs( 'failed', $failed_days );
		}

		// 3. Logs.
		$log_days = (int) apply_filters( 'datamachine_log_max_age_days', 7 );
		$count    = $this->count_old_logs( $log_days );
		$results[] = array(
			'domain'    => 'Pipeline logs',
			'threshold' => $log_days . ' days',
			'eligible'  => $count,
			'action'    => $dry_run ? 'would delete' : 'deleted',
		);
		if ( ! $dry_run && $count > 0 ) {
			$repo = new \DataMachine\Core\Database\Logs\LogRepository();
			$repo->prune_before( gmdate( 'Y-m-d H:i:s', time() - ( $log_days * DAY_IN_SECONDS ) ) );
		}

		// 4. Processed items.
		$processed_days = (int) apply_filters( 'datamachine_processed_items_max_age_days', 30 );
		$db_processed   = new \DataMachine\Core\Database\ProcessedItems\ProcessedItems();
		$count          = $db_processed->count_old_processed_items( $processed_days );
		$results[]      = array(
			'domain'    => 'Processed items',
			'threshold' => $processed_days . ' days',
			'eligible'  => $count,
			'action'    => $dry_run ? 'would delete' : 'deleted',
		);
		if ( ! $dry_run && $count > 0 ) {
			$db_processed->delete_old_processed_items( $processed_days );
		}

		// 5. Action Scheduler actions + logs.
		$as_days  = (int) apply_filters( 'datamachine_as_actions_max_age_days', 7 );
		$as_count = $this->count_old_as_actions( $as_days );
		$results[] = array(
			'domain'    => 'AS actions + logs',
			'threshold' => $as_days . ' days',
			'eligible'  => $as_count,
			'action'    => $dry_run ? 'would delete' : 'deleted',
		);
		if ( ! $dry_run && $as_count > 0 ) {
			do_action( 'datamachine_cleanup_as_actions' );
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
			WP_CLI::success( sprintf( 'Retention cleanup complete. %d total rows processed.', $total ) );
		}
	}

	/**
	 * Get the current retention policy configuration.
	 *
	 * @return array<string, array{retention: string, filter: string}>
	 */
	private function get_retention_policies(): array {
		return array(
			'Completed jobs'  => array(
				'retention' => apply_filters( 'datamachine_completed_jobs_max_age_days', 14 ) . ' days',
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
			'Chat sessions'   => $wpdb->prefix . 'datamachine_chat_sessions',
		);

		// Deduplicate tables for the query (jobs appears twice).
		$unique_tables = array_unique( array_values( $tables ) );
		$placeholders  = implode( ',', array_fill( 0, count( $unique_tables ), '%s' ) );

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

		$table_data = array();
		if ( $results ) {
			foreach ( $results as $row ) {
				$table_data[ $row->table_name ] = array(
					'rows'    => (int) $row->table_rows,
					'size_mb' => $row->size_mb,
				);
			}
		}

		foreach ( $tables as $domain => $table_name ) {
			$sizes[ $domain ] = $table_data[ $table_name ] ?? array(
				'rows'    => 0,
				'size_mb' => '0.0',
			);
		}

		return $sizes;
	}

	/**
	 * Count log entries older than a given number of days.
	 *
	 * @param int $older_than_days Age threshold.
	 * @return int Row count.
	 */
	private function count_old_logs( int $older_than_days ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );
		$table  = $wpdb->prefix . 'datamachine_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}

	/**
	 * Count completed/failed/canceled AS actions older than a given number of days.
	 *
	 * @param int $older_than_days Age threshold.
	 * @return int Row count (actions + their log entries).
	 */
	private function count_old_as_actions( int $older_than_days ): int {
		global $wpdb;

		$cutoff        = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$actions_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$actions_table}
				WHERE status IN ('complete', 'failed', 'canceled')
				AND last_attempt_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$logs_table} l
				INNER JOIN {$actions_table} a ON l.action_id = a.action_id
				WHERE a.status IN ('complete', 'failed', 'canceled')
				AND a.last_attempt_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return $actions_count + $logs_count;
	}
}
