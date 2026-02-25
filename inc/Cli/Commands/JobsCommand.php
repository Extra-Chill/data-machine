<?php
/**
 * WP-CLI Jobs Command
 *
 * Provides CLI access to job management operations including
 * stuck job recovery and job listing.
 *
 * @package DataMachine\Cli\Commands
 * @since 0.14.6
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachine\Abilities\JobAbilities;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Engine\AI\System\SystemAgent;

defined( 'ABSPATH' ) || exit;

class JobsCommand extends BaseCommand {

	/**
	 * Default fields for job list output.
	 *
	 * @var array
	 */
	private array $default_fields = array( 'id', 'source', 'flow', 'status', 'created', 'completed' );

	/**
	 * Job abilities instance.
	 *
	 * @var JobAbilities
	 */
	private JobAbilities $abilities;

	public function __construct() {
		$this->abilities = new JobAbilities();
	}

	/**
	 * Recover stuck jobs that have job_status in engine_data but status is 'processing'.
	 *
	 * Jobs can become stuck when the engine stores a status override (e.g., from skip_item)
	 * in engine_data but the main status column doesn't get updated. This command finds
	 * those jobs and completes them with their intended final status.
	 *
	 * Also recovers jobs that have been processing for longer than the timeout threshold
	 * without a status override, marking them as failed and potentially requeuing prompts.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be updated without making changes.
	 *
	 * [--flow=<flow_id>]
	 * : Only recover jobs for a specific flow ID.
	 *
	 * [--timeout=<hours>]
	 * : Hours before a processing job without status override is considered timed out.
	 * ---
	 * default: 2
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview stuck jobs recovery
	 *     wp datamachine jobs recover-stuck --dry-run
	 *
	 *     # Recover all stuck jobs
	 *     wp datamachine jobs recover-stuck
	 *
	 *     # Recover stuck jobs for a specific flow
	 *     wp datamachine jobs recover-stuck --flow=98
	 *
	 *     # Recover stuck jobs with custom timeout
	 *     wp datamachine jobs recover-stuck --timeout=4
	 *
	 * @subcommand recover-stuck
	 */
	public function recover_stuck( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$flow_id = isset( $assoc_args['flow'] ) ? (int) $assoc_args['flow'] : null;
		$timeout = isset( $assoc_args['timeout'] ) ? max( 1, (int) $assoc_args['timeout'] ) : 2;

		$result = $this->abilities->executeRecoverStuckJobs(
			array(
				'dry_run'       => $dry_run,
				'flow_id'       => $flow_id,
				'timeout_hours' => $timeout,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$jobs = $result['jobs'] ?? array();

		if ( empty( $jobs ) ) {
			WP_CLI::success( 'No stuck jobs found.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d stuck jobs with job_status in engine_data.', count( $jobs ) ) );

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run - no changes will be made.' );
			WP_CLI::log( '' );
		}

		foreach ( $jobs as $job ) {
			if ( 'skipped' === $job['status'] ) {
				WP_CLI::warning( sprintf( 'Job %d: %s', $job['job_id'], $job['reason'] ?? 'Unknown reason' ) );
			} elseif ( 'would_recover' === $job['status'] ) {
				$display_status = strlen( $job['target_status'] ) > 60 ? substr( $job['target_status'], 0, 60 ) . '...' : $job['target_status'];
				WP_CLI::log(
					sprintf(
						'Would update job %d (flow %d) to: %s',
						$job['job_id'],
						$job['flow_id'],
						$display_status
					)
				);
			} elseif ( 'recovered' === $job['status'] ) {
				$display_status = strlen( $job['target_status'] ) > 60 ? substr( $job['target_status'], 0, 60 ) . '...' : $job['target_status'];
				WP_CLI::log( sprintf( 'Updated job %d to: %s', $job['job_id'], $display_status ) );
			} elseif ( 'would_timeout' === $job['status'] ) {
				WP_CLI::log( sprintf( 'Would timeout job %d (flow %d)', $job['job_id'], $job['flow_id'] ) );
			} elseif ( 'timed_out' === $job['status'] ) {
				WP_CLI::log( sprintf( 'Timed out job %d (flow %d)', $job['job_id'], $job['flow_id'] ) );
			}
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * List jobs with optional status filter.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status (pending, processing, completed, failed, agent_skipped, completed_no_items).
	 *
	 * [--flow=<flow_id>]
	 * : Filter by flow ID.
	 *
	 * [--source=<source>]
	 * : Filter by source (pipeline, system).
	 *
	 * [--limit=<limit>]
	 * : Number of jobs to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # List recent jobs
	 *     wp datamachine jobs list
	 *
	 *     # List processing jobs
	 *     wp datamachine jobs list --status=processing
	 *
	 *     # List jobs for a specific flow
	 *     wp datamachine jobs list --flow=98 --limit=50
	 *
	 *     # Output as CSV
	 *     wp datamachine jobs list --format=csv
	 *
	 *     # Output only IDs (space-separated)
	 *     wp datamachine jobs list --format=ids
	 *
	 *     # Count total jobs
	 *     wp datamachine jobs list --format=count
	 *
	 *     # JSON output
	 *     wp datamachine jobs list --format=json
	 *
	 * @subcommand list
	 */
	public function list_jobs( array $args, array $assoc_args ): void {
		$status  = $assoc_args['status'] ?? null;
		$flow_id = isset( $assoc_args['flow'] ) ? (int) $assoc_args['flow'] : null;
		$limit   = (int) ( $assoc_args['limit'] ?? 20 );
		$format  = $assoc_args['format'] ?? 'table';

		if ( $limit < 1 ) {
			$limit = 20;
		}
		if ( $limit > 500 ) {
			$limit = 500;
		}

		$input = array(
			'per_page' => $limit,
			'offset'   => 0,
			'orderby'  => 'j.job_id',
			'order'    => 'DESC',
		);

		if ( $status ) {
			$input['status'] = $status;
		}

		if ( $flow_id ) {
			$input['flow_id'] = $flow_id;
		}

		$result = $this->abilities->executeGetJobs( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$jobs = $result['jobs'] ?? array();

		if ( empty( $jobs ) ) {
			WP_CLI::warning( 'No jobs found.' );
			return;
		}

		// Filter by source if specified.
		$source_filter = $assoc_args['source'] ?? null;
		if ( $source_filter ) {
			$jobs = array_filter(
				$jobs,
				function ( $j ) use ( $source_filter ) {
					return ( $j['source'] ?? 'pipeline' ) === $source_filter;
				}
			);
			$jobs = array_values( $jobs );

			if ( empty( $jobs ) ) {
				WP_CLI::warning( sprintf( 'No %s jobs found.', $source_filter ) );
				return;
			}
		}

		// Transform jobs to flat row format.
		$items = array_map(
			function ( $j ) {
				$source         = $j['source'] ?? 'pipeline';
				$status_display = strlen( $j['status'] ?? '' ) > 40 ? substr( $j['status'], 0, 40 ) . '...' : ( $j['status'] ?? '' );

				if ( 'system' === $source ) {
					$flow_display = $j['label'] ?? $j['display_label'] ?? 'System Task';
				} else {
					$flow_display = $j['flow_name'] ?? ( isset( $j['flow_id'] ) ? "Flow {$j['flow_id']}" : '' );
				}

				return array(
					'id'        => $j['job_id'] ?? '',
					'source'    => $source,
					'flow'      => $flow_display,
					'status'    => $status_display,
					'created'   => $j['created_at'] ?? '',
					'completed' => $j['completed_at'] ?? '-',
				);
			},
			$jobs
		);

		$this->format_items( $items, $this->default_fields, $assoc_args, 'id' );

		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'Showing %d jobs.', count( $jobs ) ) );
		}
	}

	/**
	 * Show detailed information about a specific job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID to display.
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
	 *     # Show job details
	 *     wp datamachine jobs show 844
	 *
	 *     # Show job as JSON (includes full engine_data)
	 *     wp datamachine jobs show 844 --format=json
	 *
	 * @subcommand show
	 */
	public function show( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Job ID is required.' );
			return;
		}

		$job_id = $args[0];

		if ( ! is_numeric( $job_id ) || (int) $job_id <= 0 ) {
			WP_CLI::error( 'Job ID must be a positive integer.' );
			return;
		}

		$result = $this->abilities->executeGetJobs( array( 'job_id' => (int) $job_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$jobs = $result['jobs'] ?? array();

		if ( empty( $jobs ) ) {
			WP_CLI::error( sprintf( 'Job %d not found.', (int) $job_id ) );
			return;
		}

		$job    = $jobs[0];
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			WP_CLI::log( \Spyc::YAMLDump( $job, false, false, true ) );
			return;
		}

		$this->outputJobTable( $job );
	}

	/**
	 * Output job details in table format.
	 *
	 * @param array $job Job data.
	 */
	private function outputJobTable( array $job ): void {
		$parsed_status = $this->parseCompoundStatus( $job['status'] ?? '' );
		$source        = $job['source'] ?? 'pipeline';
		$is_system     = ( 'system' === $source );

		WP_CLI::log( sprintf( 'Job ID: %d', $job['job_id'] ?? 0 ) );

		if ( $is_system ) {
			WP_CLI::log( sprintf( 'Source: %s', $source ) );
			WP_CLI::log( sprintf( 'Label: %s', $job['label'] ?? $job['display_label'] ?? 'System Task' ) );
		} else {
			WP_CLI::log( sprintf( 'Flow: %s (ID: %s)', $job['flow_name'] ?? 'N/A', $job['flow_id'] ?? 'N/A' ) );
			WP_CLI::log( sprintf( 'Pipeline ID: %s', $job['pipeline_id'] ?? 'N/A' ) );
		}

		WP_CLI::log( sprintf( 'Status: %s', $parsed_status['type'] ) );

		if ( $parsed_status['reason'] ) {
			WP_CLI::log( sprintf( 'Reason: %s', $parsed_status['reason'] ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Created: %s', $job['created_at_display'] ?? $job['created_at'] ?? 'N/A' ) );
		WP_CLI::log( sprintf( 'Completed: %s', $job['completed_at_display'] ?? $job['completed_at'] ?? '-' ) );

		// Show Action Scheduler status for processing/pending jobs (#169).
		if ( in_array( $parsed_status['type'], array( 'processing', 'pending' ), true ) ) {
			$this->outputActionSchedulerStatus( (int) ( $job['job_id'] ?? 0 ) );
		}

		$engine_data = $job['engine_data'] ?? array();

		if ( ! empty( $engine_data ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Engine Data:' );

			$summary    = $this->extractEngineDataSummary( $engine_data );
			$has_nested = false;

			foreach ( $summary as $key => $value ) {
				WP_CLI::log( sprintf( '  %s: %s', $key, $value ) );
				if ( str_starts_with( $value, 'array (' ) ) {
					$has_nested = true;
				}
			}

			if ( $has_nested ) {
				WP_CLI::log( '' );
				WP_CLI::log( '  Use --format=json for full engine data.' );
			}
		}
	}

	/**
	 * Output Action Scheduler status for a job.
	 *
	 * Queries the Action Scheduler tables to find the latest action
	 * and its logs for the given job ID. Helps diagnose stuck jobs
	 * where the AS action may have failed or timed out.
	 *
	 * @param int $job_id Job ID to look up.
	 */
	private function outputActionSchedulerStatus( int $job_id ): void {
		if ( $job_id <= 0 ) {
			return;
		}

		global $wpdb;
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$action = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT action_id, status, scheduled_date_gmt, last_attempt_gmt
				FROM %i
				WHERE hook = 'datamachine_execute_step'
				AND args LIKE %s
				ORDER BY action_id DESC
				LIMIT 1",
				$actions_table,
				'%"job_id":' . $job_id . '%'
			)
		);

		if ( ! $action ) {
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Action Scheduler:' );
		WP_CLI::log( sprintf( '  Action ID: %d', $action->action_id ) );
		WP_CLI::log( sprintf( '  AS Status: %s', $action->status ) );
		WP_CLI::log( sprintf( '  Scheduled: %s', $action->scheduled_date_gmt ) );
		WP_CLI::log( sprintf( '  Last Attempt: %s', $action->last_attempt_gmt ) );

		// Get the latest log message (usually contains failure reason).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$log = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT message, log_date_gmt
				FROM %i
				WHERE action_id = %d
				ORDER BY log_id DESC
				LIMIT 1',
				$logs_table,
				$action->action_id
			)
		);

		if ( $log && ! empty( $log->message ) ) {
			WP_CLI::log( sprintf( '  Last Log: %s (%s)', $log->message, $log->log_date_gmt ) );
		}
	}

	/**
	 * Parse compound status into type and reason.
	 *
	 * Handles formats like "agent_skipped - not a music event".
	 *
	 * @param string $status Raw status string.
	 * @return array With 'type' and 'reason' keys.
	 */
	private function parseCompoundStatus( string $status ): array {
		if ( strpos( $status, ' - ' ) !== false ) {
			$parts = explode( ' - ', $status, 2 );
			return array(
				'type'   => trim( $parts[0] ),
				'reason' => trim( $parts[1] ),
			);
		}

		return array(
			'type'   => $status,
			'reason' => '',
		);
	}

	/**
	 * Extract a summary of engine_data for CLI display.
	 *
	 * Iterates all top-level keys and formats each value by type:
	 * scalars display directly (strings truncated at 120 chars),
	 * arrays show item count and serialized size, bools/nulls display
	 * as literals. No hardcoded key list — works for any job type.
	 *
	 * @param array $engine_data Full engine data array.
	 * @return array Key-value pairs for display.
	 */
	private function extractEngineDataSummary( array $engine_data ): array {
		$summary = array();

		foreach ( $engine_data as $key => $value ) {
			$label = ucwords( str_replace( '_', ' ', $key ) );

			if ( is_array( $value ) ) {
				$count             = count( $value );
				$json              = wp_json_encode( $value );
				$size              = strlen( $json );
				$summary[ $label ] = sprintf( 'array (%d items, %s)', $count, size_format( $size ) );
			} elseif ( is_bool( $value ) ) {
				$summary[ $label ] = $value ? 'true' : 'false';
			} elseif ( is_null( $value ) ) {
				$summary[ $label ] = '(null)';
			} elseif ( is_string( $value ) && strlen( $value ) > 120 ) {
				$summary[ $label ] = substr( $value, 0, 117 ) . '...';
			} else {
				$summary[ $label ] = (string) $value;
			}
		}

		return $summary;
	}

	/**
	 * Show job status summary grouped by status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     # Show status summary
	 *     wp datamachine jobs summary
	 *
	 *     # Output as CSV
	 *     wp datamachine jobs summary --format=csv
	 *
	 *     # JSON output
	 *     wp datamachine jobs summary --format=json
	 *
	 * @subcommand summary
	 */
	public function summary( array $args, array $assoc_args ): void {
		$result = $this->abilities->executeGetJobsSummary( array() );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$summary = $result['summary'] ?? array();

		if ( empty( $summary ) ) {
			WP_CLI::warning( 'No job summary data available.' );
			return;
		}

		// Transform summary to row format.
		$items = array();
		foreach ( $summary as $status => $count ) {
			$items[] = array(
				'status' => $status,
				'count'  => $count,
			);
		}

		$this->format_items( $items, array( 'status', 'count' ), $assoc_args );
	}

	/**
	 * Manually fail a processing job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID to fail.
	 *
	 * [--reason=<reason>]
	 * : Reason for failure.
	 * ---
	 * default: manual
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Fail a stuck job
	 *     wp datamachine jobs fail 844
	 *
	 *     # Fail with a reason
	 *     wp datamachine jobs fail 844 --reason="timeout"
	 *
	 * @subcommand fail
	 */
	public function fail( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || ! is_numeric( $args[0] ) || (int) $args[0] <= 0 ) {
			WP_CLI::error( 'Job ID is required and must be a positive integer.' );
			return;
		}

		$result = $this->abilities->executeFailJob(
			array(
				'job_id' => (int) $args[0],
				'reason' => $assoc_args['reason'] ?? 'manual',
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Retry a failed or stuck job.
	 *
	 * Marks the job as failed and optionally requeues its prompt
	 * if a queued_prompt_backup exists in engine_data.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID to retry.
	 *
	 * [--force]
	 * : Allow retrying any status, not just failed/processing.
	 *
	 * ## EXAMPLES
	 *
	 *     # Retry a failed job
	 *     wp datamachine jobs retry 844
	 *
	 *     # Force retry a completed job
	 *     wp datamachine jobs retry 844 --force
	 *
	 * @subcommand retry
	 */
	public function retry( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || ! is_numeric( $args[0] ) || (int) $args[0] <= 0 ) {
			WP_CLI::error( 'Job ID is required and must be a positive integer.' );
			return;
		}

		$result = $this->abilities->executeRetryJob(
			array(
				'job_id' => (int) $args[0],
				'force'  => isset( $assoc_args['force'] ),
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		WP_CLI::success( $result['message'] );

		if ( ! empty( $result['prompt_requeued'] ) ) {
			WP_CLI::log( 'Prompt was requeued to the flow.' );
		}
	}

	/**
	 * Delete jobs by type.
	 *
	 * Removes job records from the database. Supports deleting all jobs
	 * or only failed jobs. Optionally cleans up processed items tracking
	 * for the deleted jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Which jobs to delete.
	 * ---
	 * default: failed
	 * options:
	 *   - all
	 *   - failed
	 * ---
	 *
	 * [--cleanup-processed]
	 * : Also clear processed items tracking for deleted jobs.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete failed jobs
	 *     wp datamachine jobs delete
	 *
	 *     # Delete all jobs
	 *     wp datamachine jobs delete --type=all
	 *
	 *     # Delete failed jobs and cleanup processed items
	 *     wp datamachine jobs delete --cleanup-processed
	 *
	 *     # Delete all jobs without confirmation
	 *     wp datamachine jobs delete --type=all --yes
	 *
	 * @subcommand delete
	 */
	public function delete( array $args, array $assoc_args ): void {
		$type              = $assoc_args['type'] ?? 'failed';
		$cleanup_processed = isset( $assoc_args['cleanup-processed'] );
		$skip_confirm      = isset( $assoc_args['yes'] );

		if ( ! in_array( $type, array( 'all', 'failed' ), true ) ) {
			WP_CLI::error( 'type must be "all" or "failed"' );
			return;
		}

		// Require confirmation for destructive operations.
		if ( ! $skip_confirm ) {
			$message = 'all' === $type
				? 'Delete ALL jobs? This cannot be undone.'
				: 'Delete all FAILED jobs?';

			if ( $cleanup_processed ) {
				$message .= ' Processed items tracking will also be cleared.';
			}

			WP_CLI::confirm( $message );
		}

		$result = $this->abilities->executeDeleteJobs(
			array(
				'type'              => $type,
				'cleanup_processed' => $cleanup_processed,
			)
		);

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Failed to delete jobs' );
			return;
		}

		WP_CLI::success( $result['message'] );

		if ( $cleanup_processed && ( $result['processed_items_cleaned'] ?? 0 ) > 0 ) {
			WP_CLI::log( sprintf( 'Processed items cleaned: %d', $result['processed_items_cleaned'] ) );
		}
	}

	/**
	 * Undo a completed job by reversing its recorded effects.
	 *
	 * Reads the standardized effects array from the job's engine_data and
	 * reverses each effect (restore content revision, delete meta, remove
	 * attachments, etc.). Only works on jobs whose task type supports undo.
	 *
	 * ## OPTIONS
	 *
	 * [<job_id>]
	 * : Specific job ID to undo.
	 *
	 * [--task-type=<type>]
	 * : Undo all completed jobs of this task type (e.g. internal_linking).
	 *
	 * [--dry-run]
	 * : Preview what would be undone without making changes.
	 *
	 * [--force]
	 * : Re-undo a job even if it was already undone.
	 *
	 * ## EXAMPLES
	 *
	 *     # Undo a single job
	 *     wp datamachine jobs undo 1632
	 *
	 *     # Preview batch undo of all internal linking jobs
	 *     wp datamachine jobs undo --task-type=internal_linking --dry-run
	 *
	 *     # Batch undo all internal linking jobs
	 *     wp datamachine jobs undo --task-type=internal_linking
	 *
	 * @subcommand undo
	 */
	public function undo( array $args, array $assoc_args ): void {
		$job_id    = ! empty( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 0;
		$task_type = $assoc_args['task-type'] ?? '';
		$dry_run   = isset( $assoc_args['dry-run'] );
		$force     = isset( $assoc_args['force'] );

		if ( $job_id <= 0 && empty( $task_type ) ) {
			WP_CLI::error( 'Provide a job ID or --task-type to undo.' );
			return;
		}

		// Resolve jobs to undo.
		$jobs_db = new Jobs();
		$jobs    = array();

		if ( $job_id > 0 ) {
			$job = $jobs_db->get_job( $job_id );
			if ( ! $job ) {
				WP_CLI::error( "Job #{$job_id} not found." );
				return;
			}
			$jobs[] = $job;
		} else {
			$jobs = $this->findJobsByTaskType( $jobs_db, $task_type );
			if ( empty( $jobs ) ) {
				WP_CLI::warning( "No completed jobs found for task type '{$task_type}'." );
				return;
			}
			WP_CLI::log( sprintf( 'Found %d completed %s job(s).', count( $jobs ), $task_type ) );
		}

		// Resolve task handlers.
		$system_agent = SystemAgent::getInstance();
		$handlers     = $system_agent->getTaskHandlers();

		$total_reverted = 0;
		$total_skipped  = 0;
		$total_failed   = 0;

		foreach ( $jobs as $job ) {
			$jid         = $job['job_id'] ?? 0;
			$engine_data = $job['engine_data'] ?? array();
			$jtype       = $engine_data['task_type'] ?? '';

			// Check if already undone.
			if ( ! $force && ! empty( $engine_data['undo'] ) ) {
				WP_CLI::log( sprintf( '  Job #%d: already undone (use --force to re-undo).', $jid ) );
				++$total_skipped;
				continue;
			}

			// Check task supports undo.
			if ( ! isset( $handlers[ $jtype ] ) ) {
				WP_CLI::warning( sprintf( 'Job #%d: unknown task type "%s".', $jid, $jtype ) );
				++$total_skipped;
				continue;
			}

			$task = new $handlers[ $jtype ]();

			if ( ! $task->supportsUndo() ) {
				WP_CLI::log( sprintf( '  Job #%d: task type "%s" does not support undo.', $jid, $jtype ) );
				++$total_skipped;
				continue;
			}

			$effects = $engine_data['effects'] ?? array();
			if ( empty( $effects ) ) {
				WP_CLI::log( sprintf( '  Job #%d: no effects recorded.', $jid ) );
				++$total_skipped;
				continue;
			}

			// Dry run — just describe what would happen.
			if ( $dry_run ) {
				WP_CLI::log( sprintf( '  Job #%d (%s): would undo %d effect(s):', $jid, $jtype, count( $effects ) ) );
				foreach ( $effects as $effect ) {
					$type   = $effect['type'] ?? 'unknown';
					$target = $effect['target'] ?? array();
					WP_CLI::log( sprintf( '    - %s → %s', $type, wp_json_encode( $target ) ) );
				}
				continue;
			}

			// Execute undo.
			WP_CLI::log( sprintf( '  Job #%d (%s): undoing %d effect(s)...', $jid, $jtype, count( $effects ) ) );
			$result = $task->undo( $jid, $engine_data );

			foreach ( $result['reverted'] as $r ) {
				WP_CLI::log( sprintf( '    ✓ %s reverted', $r['type'] ) );
			}
			foreach ( $result['skipped'] as $s ) {
				WP_CLI::log( sprintf( '    - %s skipped: %s', $s['type'], $s['reason'] ?? '' ) );
			}
			foreach ( $result['failed'] as $f ) {
				WP_CLI::warning( sprintf( '    ✗ %s failed: %s', $f['type'], $f['reason'] ?? '' ) );
			}

			$total_reverted += count( $result['reverted'] );
			$total_skipped  += count( $result['skipped'] );
			$total_failed   += count( $result['failed'] );

			// Record undo metadata in engine_data.
			$engine_data['undo'] = array(
				'undone_at'        => current_time( 'mysql' ),
				'effects_reverted' => count( $result['reverted'] ),
				'effects_skipped'  => count( $result['skipped'] ),
				'effects_failed'   => count( $result['failed'] ),
			);
			$jobs_db->store_engine_data( $jid, $engine_data );
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'Dry run complete. %d job(s) would be undone.', count( $jobs ) ) );
			return;
		}

		WP_CLI::success( sprintf(
			'Undo complete: %d effect(s) reverted, %d skipped, %d failed.',
			$total_reverted,
			$total_skipped,
			$total_failed
		) );
	}

	/**
	 * Find completed jobs by task type.
	 *
	 * @param Jobs   $jobs_db  Jobs database instance.
	 * @param string $task_type Task type to filter by.
	 * @return array Array of job records.
	 */
	private function findJobsByTaskType( Jobs $jobs_db, string $task_type ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id FROM {$table}
				WHERE status LIKE %s
				AND engine_data LIKE %s
				ORDER BY job_id DESC",
				'completed%',
				'%"task_type":"' . $wpdb->esc_like( $task_type ) . '"%'
			)
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$jobs = array();
		foreach ( $rows as $row ) {
			$job = $jobs_db->get_job( (int) $row->job_id );
			if ( $job ) {
				$jobs[] = $job;
			}
		}

		return $jobs;
	}
}
