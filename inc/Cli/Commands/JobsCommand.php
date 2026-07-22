<?php
// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Structured command summaries use descriptive keys.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Data Machine owns custom operational tables and these paths require fresh runtime state or one-time schema mutation.
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
use DataMachine\Cli\AbilityRunner;
use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\AgentResolver;
use DataMachine\Cli\JobLivenessClassifier;
use DataMachine\Cli\UserResolver;
use DataMachine\Abilities\Job\DeleteJobsAbility;
use DataMachine\Abilities\Job\FailJobAbility;
use DataMachine\Abilities\Job\GetJobsAbility;
use DataMachine\Abilities\Job\JobsSummaryAbility;
use DataMachine\Abilities\Job\RecoverStuckJobsAbility;
use DataMachine\Abilities\Job\RetryJobAbility;
use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Abilities\Job\RunMetricsAbility;
use DataMachine\Core\AbilityResult;
use DataMachine\Core\ExecutionQuery;
use DataMachine\Core\JobArtifacts;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\Database\Chat\Chat;
use DataMachine\Core\Database\Chat\ConversationStoreFactory;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\Jobs\LegacyAIConcurrencyReconciler;
use AgentsAPI\AI\WP_Agent_Message;
use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachine\Engine\Tasks\TaskRegistry;

defined( 'ABSPATH' ) || exit;

class JobsCommand extends BaseCommand {

	/**
	 * Default fields for job list output.
	 *
	 * @var array
	 */
	private array $default_fields = array( 'id', 'source', 'flow', 'status', 'created', 'completed' );

	/**
	 * Default fields for job liveness diagnostics.
	 *
	 * @var array
	 */
	private array $liveness_fields = array( 'id', 'flow_id', 'classification', 'age_hours', 'defer_count', 'defer_age_seconds', 'pending_actions', 'in_progress_actions', 'oldest_pending', 'latest_attempt' );

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
	 *     # Preview stuck jobs recovery as JSON
	 *     wp datamachine jobs recover-stuck --dry-run --format=json
	 *
	 * @subcommand recover-stuck
	 */
	public function recover_stuck( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$flow_id = isset( $assoc_args['flow'] ) ? (int) $assoc_args['flow'] : null;
		$timeout = isset( $assoc_args['timeout'] ) ? max( 1, (int) $assoc_args['timeout'] ) : 2;
		$format  = $assoc_args['format'] ?? 'table';

		$result = AbilityRunner::execute(
			'datamachine/recover-stuck-jobs',
			array(
				'dry_run'       => $dry_run,
				'flow_id'       => $flow_id,
				'timeout_hours' => $timeout,
			)
		);

		$error = AbilityResult::failure_to_wp_error( $result, 'get_jobs_failed', 'Unknown error occurred' );
		if ( $error ) {
			WP_CLI::error( $error->get_error_message() );
			return;
		}

		$jobs = $result['jobs'] ?? array();

		$summary = $this->summarize_recover_stuck_result( $result );

		if ( 'table' !== $format ) {
			WP_CLI::print_value(
				array(
					'success'        => true,
					'dry_run'        => $dry_run,
					'summary'        => $summary,
					'jobs'           => $jobs,
					'jobs_omitted'   => (int) ( $result['jobs_omitted'] ?? 0 ),
					'jobs_truncated' => ! empty( $result['jobs_truncated'] ),
					'message'        => $result['message'] ?? '',
				),
				array(
					'format' => $format,
				)
			);
			return;
		}

		if ( empty( $jobs ) ) {
			WP_CLI::success( 'No stuck jobs found.' );
			return;
		}

		WP_CLI::log(
			sprintf(
				'Found %d recoverable jobs/actions and %d guarded jobs.',
				$summary['actionable'],
				$summary['skipped']
			)
		);

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
			} elseif ( 'would_reconcile_action' === $job['status'] ) {
				WP_CLI::log(
					sprintf(
						'Would reconcile Action Scheduler action %d (%s) for terminal job %d (flow %d, status %s)',
						$job['action_id'],
						$job['hook'] ?? 'unknown',
						$job['job_id'],
						$job['flow_id'],
						$job['target_status']
					)
				);
			} elseif ( 'reconciled_action' === $job['status'] ) {
				WP_CLI::log(
					sprintf(
						'Reconciled Action Scheduler action %d (%s) for terminal job %d (flow %d, status %s)',
						$job['action_id'],
						$job['hook'] ?? 'unknown',
						$job['job_id'],
						$job['flow_id'],
						$job['target_status']
					)
				);
			}
		}

		if ( ! empty( $result['jobs_truncated'] ) ) {
			WP_CLI::log( sprintf( 'Output truncated; %d additional job/action details omitted.', (int) ( $result['jobs_omitted'] ?? 0 ) ) );
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Summarize recover-stuck ability output for operator-facing CLI reporting.
	 *
	 * @param array $result Recovery ability result.
	 * @return array<string,int>
	 */
	private function summarize_recover_stuck_result( array $result ): array {
		$recovered     = (int) ( $result['recovered'] ?? 0 );
		$timed_out     = (int) ( $result['timed_out'] ?? 0 );
		$stale_actions = (int) ( $result['stale_actions'] ?? 0 );
		$skipped       = (int) ( $result['skipped'] ?? 0 );
		$pathless_requeued = (int) ( $result['pathless_requeued'] ?? 0 );
		$pathless_terminal = (int) ( $result['pathless_terminal'] ?? 0 );

		return array(
			'recovered'     => $recovered,
			'timed_out'     => $timed_out,
			'stale_actions' => $stale_actions,
			'skipped'       => $skipped,
			'pathless_requeued' => $pathless_requeued,
			'pathless_terminal' => $pathless_terminal,
			'actionable'    => $recovered + $timed_out + $stale_actions + $pathless_requeued + $pathless_terminal,
			'total'         => $recovered + $timed_out + $stale_actions + $pathless_requeued + $pathless_terminal + $skipped,
			'requeued'      => (int) ( $result['requeued'] ?? 0 ),
			'jobs_omitted'  => (int) ( $result['jobs_omitted'] ?? 0 ),
		);
	}

	/**
	 * Diagnose liveness for processing jobs and pending backpressure deferrals.
	 *
	 * Processing is a broad lifecycle state. This command reports whether each
	 * processing job is actively executing, waiting on a scheduler action, or
	 * scheduler-starved by overdue pending Action Scheduler work.
	 *
	 * ## OPTIONS
	 *
	 * [--flow=<flow_id>]
	 * : Only diagnose jobs for a specific flow ID.
	 *
	 * [--limit=<limit>]
	 * : Number of processing jobs to inspect, oldest first.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--overdue-minutes=<minutes>]
	 * : Minutes after scheduled time before a pending/in-progress step is classified as overdue.
	 * ---
	 * default: 120
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Diagnose oldest processing jobs
	 *     wp datamachine jobs liveness
	 *
	 *     # Diagnose scheduler-starved jobs as JSON
	 *     wp datamachine jobs liveness --overdue-minutes=120 --format=json
	 *
	 * @subcommand liveness
	 */
	public function liveness( array $args, array $assoc_args ): void {
		global $wpdb;

		$flow_id         = isset( $assoc_args['flow'] ) ? (int) $assoc_args['flow'] : null;
		$limit           = isset( $assoc_args['limit'] ) ? max( 1, min( 500, (int) $assoc_args['limit'] ) ) : 50;
		$overdue_minutes = isset( $assoc_args['overdue-minutes'] ) ? max( 1, (int) $assoc_args['overdue-minutes'] ) : 120;
		$format          = $assoc_args['format'] ?? 'table';

		$jobs_table = $wpdb->prefix . 'datamachine_jobs';
		$where      = "WHERE (status = 'processing' OR (status = 'pending' AND JSON_EXTRACT(engine_data, '$.ai_concurrency_throttle') IS NOT NULL))";
		$values     = array();

		if ( $flow_id ) {
			$where   .= ' AND flow_id = %d';
			$values[] = $flow_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and WHERE are composed from trusted fragments above.
		$sql = "SELECT job_id, flow_id, pipeline_id, agent_id, status, created_at, completed_at, engine_data
			FROM {$jobs_table}
			{$where}
			ORDER BY created_at ASC
			LIMIT %d";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$values[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL is prepared with accumulated placeholders.
		$jobs = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		$items   = array();
		$summary = array(
			'total'                   => 0,
			'active_processing'       => 0,
			'queued_next_step'        => 0,
			'waiting_children'        => 0,
			'scheduler_starved'       => 0,
			'stale_in_progress'       => 0,
			'no_scheduler_path'       => 0,
			'ai_concurrency_deferred' => 0,
		);

		foreach ( $jobs as $job ) {
			$diagnostic = $this->diagnose_job_liveness( $job, $overdue_minutes );
			++$summary['total'];
			if ( isset( $summary[ $diagnostic['classification'] ] ) ) {
				++$summary[ $diagnostic['classification'] ];
			}
			$items[] = $diagnostic;
		}

		if ( 'json' === $format || 'yaml' === $format ) {
			WP_CLI::print_value(
				array(
					'success'         => true,
					'overdue_minutes' => $overdue_minutes,
					'summary'         => $summary,
					'jobs'            => $items,
				),
				array( 'format' => $format )
			);
			return;
		}

		if ( empty( $items ) ) {
			WP_CLI::success( 'No processing jobs found.' );
			return;
		}

		$this->format_items( $items, $this->liveness_fields, $assoc_args, 'id' );

		if ( 'table' === $format ) {
			WP_CLI::log(
				sprintf(
					'Inspected %d active jobs: %d active, %d queued, %d AI concurrency-deferred, %d waiting on children, %d scheduler-starved, %d stale in-progress, %d without scheduler path.',
					$summary['total'],
					$summary['active_processing'],
					$summary['queued_next_step'],
					$summary['ai_concurrency_deferred'],
					$summary['waiting_children'],
					$summary['scheduler_starved'],
					$summary['stale_in_progress'],
					$summary['no_scheduler_path']
				)
			);
		}
	}

	/**
	 * Trim runtime queue payloads from persisted job engine_data.
	 *
	 * Historical batch child jobs may carry copied flow runtime queues in
	 * engine_data. Those queues are read from the live flow record at execution
	 * time, so keeping them on each child only inflates Action Scheduler memory.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Apply changes. Without this flag the command is a dry run.
	 *
	 * [--limit=<limit>]
	 * : Maximum rows to inspect.
	 * ---
	 * default: 500
	 * ---
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
	 * @subcommand trim-runtime-queues
	 */
	public function trim_runtime_queues( array $args, array $assoc_args ): void {
		global $wpdb;

		$apply  = isset( $assoc_args['yes'] );
		$limit  = isset( $assoc_args['limit'] ) ? max( 1, min( 5000, (int) $assoc_args['limit'] ) ) : 500;
		$format = $assoc_args['format'] ?? 'table';

		$jobs_table = $wpdb->prefix . 'datamachine_jobs';
		$jobs_db    = new Jobs();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id, flow_id, pipeline_id, parent_job_id, status, engine_data
				FROM {$jobs_table}
				WHERE status IN ('pending', 'processing')
				AND (engine_data LIKE %s OR engine_data LIKE %s OR engine_data LIKE %s)
				ORDER BY job_id ASC
				LIMIT %d",
				'%' . $wpdb->esc_like( '"config_patch_queue"' ) . '%',
				'%' . $wpdb->esc_like( '"prompt_queue"' ) . '%',
				'%' . $wpdb->esc_like( '"_queue_consume_revision"' ) . '%',
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items   = array();
		$summary = array(
			'inspected' => count( $rows ),
			'trimmed'   => 0,
			'updated'   => 0,
			'failed'    => 0,
		);

		foreach ( $rows as $row ) {
			$job_id = (int) ( $row['job_id'] ?? 0 );
			$before = $this->decode_job_engine_data( $row['engine_data'] ?? null );
			$after  = $this->strip_runtime_queues_from_engine_data( $before );

			$before_json  = wp_json_encode( $before );
			$after_json   = wp_json_encode( $after );
			$before_bytes = strlen( false === $before_json ? '' : $before_json );
			$after_bytes  = strlen( false === $after_json ? '' : $after_json );
			$changed      = $after !== $before;

			$item = array(
				'job_id'        => $job_id,
				'flow_id'       => $row['flow_id'] ?? '',
				'pipeline_id'   => $row['pipeline_id'] ?? '',
				'parent_job_id' => (int) ( $row['parent_job_id'] ?? 0 ),
				'status'        => $row['status'] ?? '',
				'before_bytes'  => $before_bytes,
				'after_bytes'   => $after_bytes,
				'saved_bytes'   => max( 0, $before_bytes - $after_bytes ),
				'action'        => $changed ? ( $apply ? 'updated' : 'would_update' ) : 'unchanged',
			);

			if ( $changed ) {
				++$summary['trimmed'];
				if ( $apply ) {
					if ( $jobs_db->store_engine_data( $job_id, $after ) ) {
						++$summary['updated'];
					} else {
						++$summary['failed'];
						$item['action'] = 'failed';
					}
				}
			}

			$items[] = $item;
		}

		if ( 'json' === $format || 'yaml' === $format ) {
			WP_CLI::print_value(
				array(
					'success' => 0 === $summary['failed'],
					'dry_run' => ! $apply,
					'summary' => $summary,
					'jobs'    => $items,
				),
				array( 'format' => $format )
			);
			return;
		}

		if ( empty( $items ) ) {
			WP_CLI::success( 'No pending or processing jobs with runtime queue payloads found.' );
			return;
		}

		$this->format_items( $items, array( 'job_id', 'flow_id', 'status', 'before_bytes', 'after_bytes', 'saved_bytes', 'action' ), $assoc_args, 'job_id' );
		WP_CLI::log( sprintf( 'Inspected %d jobs; %d need trimming; %d updated; %d failed.', $summary['inspected'], $summary['trimmed'], $summary['updated'], $summary['failed'] ) );
		if ( ! $apply ) {
			WP_CLI::log( 'Dry run - pass --yes to apply changes.' );
		}
	}

	/**
	 * Reconcile jobs whose persisted status disagrees with successful engine artifacts.
	 *
	 * Repairs historical rows where a completed engine artifact disagrees with the
	 * persisted job status, including failed rows and processing rows whose actions
	 * already completed successfully.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be updated without making changes.
	 *
	 * [--limit=<limit>]
	 * : Maximum rows to inspect.
	 * ---
	 * default: 500
	 * ---
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
	 * @subcommand reconcile-status
	 */
	public function reconcile_status( array $args, array $assoc_args ): void {
		global $wpdb;

		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, min( 5000, (int) $assoc_args['limit'] ) ) : 500;
		$format  = $assoc_args['format'] ?? 'table';

		$jobs_table                   = $wpdb->prefix . 'datamachine_jobs';
		$jobs_db                      = new Jobs();
		$failed_like                  = $wpdb->esc_like( 'failed' ) . '%';
		$agent_skipped_like           = $wpdb->esc_like( 'agent_skipped' ) . '%';
		$historical_contention_status = LegacyAIConcurrencyReconciler::SOURCE_STATUS;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id, flow_id, parent_job_id, status, label, engine_data
				FROM {$jobs_table}
				WHERE ((status LIKE %s OR status = 'processing')
				AND (
					engine_data LIKE %s
					OR engine_data LIKE %s
					OR JSON_UNQUOTE(JSON_EXTRACT(engine_data, '$.job_status')) LIKE %s
					OR JSON_UNQUOTE(JSON_EXTRACT(engine_data, '$.runtime_provenance.status.status')) = 'completed'
					OR JSON_UNQUOTE(JSON_EXTRACT(engine_data, '$.runtime_provenance.status.completed')) = 'true'
				)) OR status = %s
				ORDER BY COALESCE(completed_at, created_at) DESC
				LIMIT %d",
				$failed_like,
				'%Updated wiki article:%',
				'%Source rejected:%',
				$agent_skipped_like,
				$historical_contention_status,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items   = array();
		$updated = 0;

		foreach ( $rows as $row ) {
			$plan          = $this->resolve_reconciled_job_plan( $row );
			$target_status = $plan['target_status'];
			if ( '' === $target_status ) {
				continue;
			}

			$item = array(
				'id'            => (int) $row['job_id'],
				'flow_id'       => (int) $row['flow_id'],
				'from_status'   => (string) $row['status'],
				'target_status' => $target_status,
				'reason'        => str_contains( $target_status, 'ai_concurrency_stranded' ) ? 'historical_concurrency_contention' : ( str_starts_with( $target_status, 'agent_skipped' ) ? 'source_rejected' : 'successful_handler_artifact' ),
				'label'         => (string) ( $row['label'] ?? '' ),
			);

			$reconciled = false;
			if ( ! $dry_run && 'legacy_ai_concurrency' === $plan['strategy'] ) {
				$transition             = ( new LegacyAIConcurrencyReconciler() )->reconcile( (int) $row['job_id'] );
				$reconciled             = ! empty( $transition['success'] );
				$item['reconciliation'] = $transition['reconciliation'] ?? array();
			} elseif ( ! $dry_run ) {
				$reconciled = $jobs_db->complete_job( (int) $row['job_id'], $target_status );
			}

			if ( $reconciled ) {
				++$updated;
				$item['status'] = 'reconciled';
			} else {
				$item['status'] = $dry_run ? 'would_reconcile' : 'failed_to_reconcile';
			}

			$items[] = $item;
		}

		$parent_items = $this->reconcile_parent_batch_statuses( $dry_run, $limit );
		foreach ( $parent_items as $parent_item ) {
			if ( 'reconciled' === ( $parent_item['status'] ?? '' ) ) {
				++$updated;
			}
			$items[] = $parent_item;
		}

		$summary = array(
			'inspected' => count( $rows ),
			'matched'   => count( $items ),
			'updated'   => $updated,
		);

		if ( 'table' !== $format ) {
			WP_CLI::print_value(
				array(
					'success' => true,
					'dry_run' => $dry_run,
					'summary' => $summary,
					'jobs'    => $items,
				),
				array( 'format' => $format )
			);
			return;
		}

		if ( empty( $items ) ) {
			WP_CLI::success( 'No misclassified jobs found.' );
			return;
		}

		$this->format_items( $items, array( 'id', 'flow_id', 'status', 'from_status', 'target_status', 'reason' ), $assoc_args, 'id' );
		WP_CLI::success( $dry_run ? sprintf( 'Dry run: %d job(s) would be reconciled.', count( $items ) ) : sprintf( 'Reconciled %d job(s).', $updated ) );
	}

	/**
	 * Reconcile terminal jobs with incomplete post-commit accounting.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report incomplete jobs without replaying accounting.
	 *
	 * [--limit=<limit>]
	 * : Maximum rows to inspect.
	 * ---
	 * default: 100
	 * ---
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
	 * @subcommand reconcile-terminal-accounting
	 */
	public function reconcile_terminal_accounting( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, min( 5000, (int) $assoc_args['limit'] ) ) : 100;
		$format  = $assoc_args['format'] ?? 'table';
		$jobs_db = new Jobs();
		$rows    = $jobs_db->get_incomplete_terminal_accounting( $limit );
		$items   = array();
		$summary = array(
			'incomplete'  => $jobs_db->count_incomplete_terminal_accounting(),
			'inspected'   => count( $rows ),
			'reconciled'  => 0,
			'in_progress' => 0,
			'failed'      => 0,
		);

		foreach ( $rows as $row ) {
			$before = (int) $row['terminal_accounting_state'];
			try {
				$result = $dry_run
					? array(
						'success'     => true,
						'state'       => $before,
						'complete'    => false,
						'in_progress' => false,
						'errors'      => array(),
					)
					: $jobs_db->reconcile_terminal_accounting( (int) $row['job_id'] );
			} catch ( \Throwable $exception ) {
				$result = array(
					'success'     => false,
					'state'       => $before,
					'complete'    => false,
					'in_progress' => false,
					'stage'       => 'reconcile',
					'errors'      => array(
						array(
							'stage'   => 'reconcile',
							'code'    => 'reconciliation_exception',
							'message' => $exception->getMessage(),
						),
					),
				);
			}

			if ( ! $dry_run ) {
				if ( ! empty( $result['complete'] ) ) {
					++$summary['reconciled'];
				} elseif ( ! empty( $result['in_progress'] ) ) {
					++$summary['in_progress'];
				} else {
					++$summary['failed'];
				}
			}
			$errors      = is_array( $result['errors'] ?? null ) ? $result['errors'] : array();
			$error_codes = implode( ',', array_filter( array_column( $errors, 'code' ), 'is_string' ) );
			$action      = $dry_run ? 'would_reconcile' : ( ! empty( $result['complete'] ) ? 'reconciled' : ( ! empty( $result['in_progress'] ) ? 'in_progress' : 'failed' ) );

			$items[] = array(
				'job_id'       => (int) $row['job_id'],
				'status'       => (string) $row['status'],
				'before_state' => $before,
				'after_state'  => (int) ( $result['state'] ?? $before ),
				'stage'        => (string) ( $result['stage'] ?? '' ),
				'action'       => $action,
				'error_codes'  => $error_codes,
				'errors'       => $errors,
			);
		}

		$output = array(
			'success'        => 0 === $summary['failed'],
			'dry_run'        => $dry_run,
			'complete_state' => Jobs::TERMINAL_ACCOUNTING_COMPLETE,
			'summary'        => $summary,
			'jobs'           => $items,
		);

		if ( 'table' !== $format ) {
			WP_CLI::print_value( $output, array( 'format' => $format ) );
			return;
		}

		if ( empty( $items ) ) {
			WP_CLI::success( 'No terminal jobs have incomplete accounting.' );
			return;
		}

		$this->format_items( $items, array( 'job_id', 'status', 'before_state', 'after_state', 'stage', 'action', 'error_codes' ), $assoc_args, 'job_id' );
		WP_CLI::log( sprintf( 'Inspected %d of %d incomplete terminal jobs; %d reconciled; %d in progress; %d failed.', $summary['inspected'], $summary['incomplete'], $summary['reconciled'], $summary['in_progress'], $summary['failed'] ) );
	}

	/**
	 * Resolve the corrected terminal status for a job row.
	 *
	 * @param array<string,mixed> $row Job row.
	 * @return string Corrected status, or empty string when the row is not safe to repair.
	 */
	private function resolve_reconciled_job_status( array $row ): string {
		if ( LegacyAIConcurrencyReconciler::SOURCE_STATUS === (string) ( $row['status'] ?? '' ) ) {
			return LegacyAIConcurrencyReconciler::TARGET_STATUS;
		}

		$engine_data = $this->decode_job_engine_data( $row['engine_data'] ?? null );

		$job_status = isset( $engine_data['job_status'] ) ? (string) $engine_data['job_status'] : '';
		if ( str_starts_with( $job_status, 'agent_skipped' ) ) {
			return $job_status;
		}

		if ( false !== strpos( (string) ( $row['engine_data'] ?? '' ), 'Source rejected:' ) ) {
			return 'agent_skipped - source-rejected';
		}

		if ( false !== strpos( (string) ( $row['engine_data'] ?? '' ), 'Updated wiki article:' ) ) {
			return 'completed';
		}

		if ( $this->engine_data_has_successful_runtime( $engine_data ) && $this->engine_data_has_successful_handler_tool( $engine_data ) ) {
			return 'completed';
		}

		return '';
	}

	/**
	 * Resolve both the target and the only authorized transition mechanism.
	 *
	 * @return array{target_status:string,strategy:string}
	 */
	private function resolve_reconciled_job_plan( array $row ): array {
		$is_legacy_contention = LegacyAIConcurrencyReconciler::SOURCE_STATUS === (string) ( $row['status'] ?? '' );

		return array(
			'target_status' => $this->resolve_reconciled_job_status( $row ),
			'strategy'      => $is_legacy_contention ? 'legacy_ai_concurrency' : 'terminal_transition',
		);
	}

	/**
	 * Determine whether runtime provenance marks the engine attempt complete.
	 *
	 * @param array<string,mixed> $engine_data Decoded engine data.
	 * @return bool Whether runtime provenance is terminal-successful.
	 */
	private function engine_data_has_successful_runtime( array $engine_data ): bool {
		$status = $engine_data['runtime_provenance']['status'] ?? array();
		if ( ! is_array( $status ) ) {
			return false;
		}

		return true === ( $status['completed'] ?? false ) || 'completed' === (string) ( $status['status'] ?? '' );
	}

	/**
	 * Determine whether engine data includes a successful handler tool call.
	 *
	 * @param array<string,mixed> $engine_data Decoded engine data.
	 * @return bool Whether a handler tool completed successfully.
	 */
	private function engine_data_has_successful_handler_tool( array $engine_data ): bool {
		$summary = $engine_data['tool_execution_summary'] ?? array();
		if ( ! is_array( $summary ) ) {
			return false;
		}

		foreach ( $summary as $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			$tool_name = (string) ( $tool['tool_name'] ?? '' );
			if ( true === ( $tool['success'] ?? false ) && in_array( $tool_name, array( 'wiki_upsert', 'create_post', 'update_post' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Repair batch parent rows once their children now contain terminal statuses.
	 *
	 * @param bool $dry_run Whether to preview changes only.
	 * @param int  $limit   Maximum parent rows to inspect.
	 * @return array<int,array<string,mixed>> Reconciliation rows.
	 */
	private function reconcile_parent_batch_statuses( bool $dry_run, int $limit ): array {
		global $wpdb;

		$jobs_table  = $wpdb->prefix . 'datamachine_jobs';
		$jobs_db     = new Jobs();
		$failed_like = $wpdb->esc_like( 'failed' ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is from $wpdb->prefix.
		$parents = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job_id, flow_id, status, label, engine_data
				FROM {$jobs_table}
				WHERE (status LIKE %s OR status = 'processing')
				AND parent_job_id IS NULL
				AND JSON_UNQUOTE(JSON_EXTRACT(engine_data, '$.run_metrics.context.batch_completion_strategy')) = 'children_complete'
				ORDER BY completed_at DESC
				LIMIT %d",
				$failed_like,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = array();

		foreach ( $parents as $parent ) {
			$counts = $this->get_child_terminal_counts( (int) $parent['job_id'] );
			if ( $counts['active'] > 0 || 0 === $counts['total'] ) {
				continue;
			}

			$target_status = '';
			if ( $counts['completed'] > 0 ) {
				$target_status = 'completed';
			} elseif ( $counts['failed'] <= 0 && $counts['skipped'] > 0 ) {
				$target_status = 'completed_no_items';
			}

			if ( '' === $target_status ) {
				continue;
			}

			$engine_data                  = $this->decode_job_engine_data( $parent['engine_data'] ?? null );
			$engine_data['batch_results'] = array(
				'completed' => $counts['completed'],
				'failed'    => $counts['failed'],
				'skipped'   => $counts['skipped'],
				'total'     => $counts['total'],
			);

			if ( ! $dry_run ) {
				datamachine_set_engine_data( (int) $parent['job_id'], $engine_data );
			}

			$updated = ! $dry_run && $jobs_db->complete_job( (int) $parent['job_id'], $target_status );

			$items[] = array(
				'id'            => (int) $parent['job_id'],
				'flow_id'       => (int) $parent['flow_id'],
				'from_status'   => (string) $parent['status'],
				'target_status' => $target_status,
				'reason'        => 'batch_children_reconciled',
				'label'         => (string) ( $parent['label'] ?? '' ),
				'status'        => $dry_run ? 'would_reconcile' : ( $updated ? 'reconciled' : 'failed_to_reconcile' ),
			);
		}

		return $items;
	}

	/**
	 * Decode a job engine_data value.
	 *
	 * @param mixed $engine_data Raw engine data.
	 * @return array<string,mixed>
	 */
	private function decode_job_engine_data( $engine_data ): array {
		if ( is_array( $engine_data ) ) {
			return $engine_data;
		}

		if ( ! is_string( $engine_data ) || '' === $engine_data ) {
			return array();
		}

		$decoded = json_decode( $engine_data, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Strip runtime queue fields from engine_data flow config.
	 *
	 * @param array<string,mixed> $engine_data Decoded engine data.
	 * @return array<string,mixed> Engine data without copied runtime queue payloads.
	 */
	private function strip_runtime_queues_from_engine_data( array $engine_data ): array {
		$flow_config = is_array( $engine_data['flow_config'] ?? null ) ? $engine_data['flow_config'] : array();

		foreach ( $flow_config as $flow_step_id => $flow_step_config ) {
			if ( ! is_array( $flow_step_config ) ) {
				continue;
			}

			unset(
				$flow_step_config['prompt_queue'],
				$flow_step_config['config_patch_queue'],
				$flow_step_config['_queue_consume_revision']
			);

			$flow_config[ $flow_step_id ] = $flow_step_config;
		}

		$engine_data['flow_config'] = $flow_config;

		return $engine_data;
	}

	/**
	 * Count child terminal statuses for a parent job.
	 *
	 * @param int $parent_job_id Parent job ID.
	 * @return array<string,int>
	 */
	private function get_child_terminal_counts( int $parent_job_id ): array {
		global $wpdb;

		$jobs_table              = $wpdb->prefix . 'datamachine_jobs';
		$agent_skipped_like      = $wpdb->esc_like( 'agent_skipped' ) . '%';
		$completed_no_items_like = $wpdb->esc_like( 'completed_no_items' ) . '%';
		$failed_like             = $wpdb->esc_like( 'failed' ) . '%';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is from $wpdb->prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
					SUM(CASE WHEN status LIKE %s OR status LIKE %s THEN 1 ELSE 0 END) AS skipped,
					SUM(CASE WHEN status LIKE %s THEN 1 ELSE 0 END) AS failed,
					SUM(CASE WHEN status IN ('pending', 'processing') THEN 1 ELSE 0 END) AS active
				FROM {$jobs_table}
				WHERE parent_job_id = %d",
				$agent_skipped_like,
				$completed_no_items_like,
				$failed_like,
				$parent_job_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'total'     => (int) ( $row['total'] ?? 0 ),
			'completed' => (int) ( $row['completed'] ?? 0 ),
			'skipped'   => (int) ( $row['skipped'] ?? 0 ),
			'failed'    => (int) ( $row['failed'] ?? 0 ),
			'active'    => (int) ( $row['active'] ?? 0 ),
		);
	}

	/**
	 * Diagnose one active job's scheduler liveness.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @param int                 $overdue_minutes Overdue threshold in minutes.
	 * @return array<string,mixed>
	 */
	private function diagnose_job_liveness( array $job, int $overdue_minutes ): array {
		$job_id  = (int) ( $job['job_id'] ?? 0 );
		$actions = $this->get_job_scheduler_actions( $job_id );

		$engine_data = json_decode( (string) ( $job['engine_data'] ?? '' ), true );
		if ( ! is_array( $engine_data ) ) {
			$engine_data = array();
		}

		$job['engine_data'] = $engine_data;
		$child_counts       = ! empty( $engine_data['batch'] ) ? $this->get_child_status_counts( $job_id ) : array();

		return JobLivenessClassifier::diagnose( $job, $actions, $child_counts, $overdue_minutes, time() );
	}

	/**
	 * Get Action Scheduler actions that can advance a job.
	 *
	 * @param int $job_id Job ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_job_scheduler_actions( int $job_id ): array {
		global $wpdb;

		if ( $job_id <= 0 ) {
			return array();
		}

		$actions_table      = $wpdb->prefix . 'actionscheduler_actions';
		$like_job_id        = '%"job_id":' . $wpdb->esc_like( (string) $job_id ) . '%';
		$like_parent_job_id = '%"parent_job_id":' . $wpdb->esc_like( (string) $job_id ) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WP prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, attempts, args
				 FROM {$actions_table}
				 WHERE hook IN (%s, %s, %s)
				 AND (args LIKE %s OR args LIKE %s)
				 ORDER BY action_id ASC",
				'datamachine_execute_step',
				'datamachine_resume_ai_step',
				PipelineBatchScheduler::BATCH_HOOK,
				$like_job_id,
				$like_parent_job_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $job_id ): bool {
					return $job_id === $this->extract_action_job_id( (string) ( $row['args'] ?? '' ) );
				}
			)
		);
	}

	/**
	 * Count child jobs for a batch parent.
	 *
	 * @param int $parent_job_id Parent job ID.
	 * @return array<string,int>
	 */
	private function get_child_status_counts( int $parent_job_id ): array {
		global $wpdb;

		if ( $parent_job_id <= 0 ) {
			return array();
		}

		$jobs_table = $wpdb->prefix . 'datamachine_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated from the WP prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					SUM(CASE WHEN status = 'processing' OR status = 'pending' THEN 1 ELSE 0 END) as active
				 FROM {$jobs_table}
				 WHERE parent_job_id = %d",
				$parent_job_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'total'  => (int) ( $row['total'] ?? 0 ),
			'active' => (int) ( $row['active'] ?? 0 ),
		);
	}

	/**
	 * Extract job ID from an Action Scheduler args payload.
	 *
	 * @param string $args Action args.
	 * @return int Job ID.
	 */
	private function extract_action_job_id( string $args ): int {
		$decoded = json_decode( $args, true );
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['job_id'] ) && is_numeric( $decoded['job_id'] ) ) {
				return (int) $decoded['job_id'];
			}

			if ( isset( $decoded['parent_job_id'] ) && is_numeric( $decoded['parent_job_id'] ) ) {
				return (int) $decoded['parent_job_id'];
			}

			foreach ( $decoded as $value ) {
				if ( is_array( $value ) && isset( $value['job_id'] ) && is_numeric( $value['job_id'] ) ) {
					return (int) $value['job_id'];
				}

				if ( is_array( $value ) && isset( $value['parent_job_id'] ) && is_numeric( $value['parent_job_id'] ) ) {
					return (int) $value['parent_job_id'];
				}
			}
		}

		$unserialized = maybe_unserialize( $args );
		if ( is_array( $unserialized ) ) {
			if ( isset( $unserialized['job_id'] ) && is_numeric( $unserialized['job_id'] ) ) {
				return (int) $unserialized['job_id'];
			}

			if ( isset( $unserialized['parent_job_id'] ) && is_numeric( $unserialized['parent_job_id'] ) ) {
				return (int) $unserialized['parent_job_id'];
			}

			foreach ( $unserialized as $value ) {
				if ( is_array( $value ) && isset( $value['job_id'] ) && is_numeric( $value['job_id'] ) ) {
					return (int) $value['job_id'];
				}

				if ( is_array( $value ) && isset( $value['parent_job_id'] ) && is_numeric( $value['parent_job_id'] ) ) {
					return (int) $value['parent_job_id'];
				}
			}
		}

		return 0;
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
	 * [--pipeline=<pipeline_id>]
	 * : Filter by pipeline ID.
	 *
	 * [--handler=<handler_slug>]
	 * : Filter by handler slug recorded in generic job outcome metadata.
	 *
	 * [--since=<datetime>]
	 * : Show jobs created after this time. Accepts ISO datetime or relative strings (e.g., "1 hour ago", "today", "yesterday").
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
	 * [--metadata=<filters>]
	 * : Exact metadata filters as comma-separated key=value pairs. Keys are engine_data dot-paths.
	 *
	 * [--metadata-scan-limit=<limit>]
	 * : Maximum candidate jobs to scan after indexed filters when applying metadata filters.
	 * ---
	 * default: 1000
	 * ---
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
	 *     # Show failed jobs from the last 2 hours
	 *     wp datamachine jobs list --status=failed --since="2 hours ago"
	 *
	 *     # Show all jobs since midnight
	 *     wp datamachine jobs list --since=today
	 *
	 *     # Find executions by generic metadata
	 *     wp datamachine jobs list --metadata="task_type=daily,source.slug=example"
	 *
	 * @subcommand list
	 */
	public function list_jobs( array $args, array $assoc_args ): void {
		$status      = $assoc_args['status'] ?? null;
		$flow_id     = isset( $assoc_args['flow'] ) ? (int) $assoc_args['flow'] : null;
		$pipeline_id = isset( $assoc_args['pipeline'] ) ? (int) $assoc_args['pipeline'] : null;
		$limit       = (int) ( $assoc_args['limit'] ?? 20 );
		$format      = $assoc_args['format'] ?? 'table';
		$fields      = $this->parse_job_list_fields( $assoc_args['fields'] ?? '' );

		if ( $limit < 1 ) {
			$limit = 20;
		}
		if ( $limit > 500 ) {
			$limit = 500;
		}

		$scoping = AgentResolver::buildScopingInput( $assoc_args );

		$input = array_merge(
			$scoping,
			array(
				'per_page' => $limit,
				'offset'   => 0,
				'orderby'  => 'j.job_id',
				'order'    => 'DESC',
			)
		);

		if ( $status ) {
			$input['status'] = $status;
		}

		if ( $flow_id ) {
			$input['flow_id'] = $flow_id;
		}

		if ( $pipeline_id ) {
			$input['pipeline_id'] = $pipeline_id;
		}

		if ( ! empty( $assoc_args['source'] ) ) {
			$input['source'] = (string) $assoc_args['source'];
		}

		if ( ! empty( $assoc_args['handler'] ) ) {
			$input['handler'] = (string) $assoc_args['handler'];
		}

		if ( ! empty( $assoc_args['metadata'] ) ) {
			$metadata = ExecutionQuery::parse_metadata_filter_string( (string) $assoc_args['metadata'] );
			if ( empty( $metadata ) ) {
				WP_CLI::error( 'Invalid --metadata value. Use comma-separated key=value pairs.' );
				return;
			}

			$input['metadata']            = $metadata;
			$input['metadata_scan_limit'] = (int) ( $assoc_args['metadata-scan-limit'] ?? 1000 );
		}

		$since = $assoc_args['since'] ?? null;
		if ( $since ) {
			$timestamp = strtotime( $since );
			if ( false === $timestamp ) {
				WP_CLI::error( sprintf( 'Invalid --since value: "%s". Use ISO datetime or relative string (e.g., "1 hour ago", "today").', $since ) );
				return;
			}
			$input['since'] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		if ( 'count' === $format ) {
			WP_CLI::line( (string) ( new Jobs() )->get_jobs_count( $input ) );
			return;
		}

		if ( ! empty( $fields ) ) {
			$input['fields'] = $this->get_database_fields_for_job_list_fields( $fields );
		}

		$result = AbilityRunner::execute( 'datamachine/get-jobs', $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$jobs = $result['jobs'] ?? array();

		if ( empty( $jobs ) && 'json' !== $format ) {
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
			function ( $j ) use ( $format, $fields ) {
				if ( ! empty( $fields ) ) {
					return $this->format_requested_job_list_fields( $j, $fields );
				}

				$source         = $j['source'] ?? 'pipeline';
				$status_display = strlen( $j['status'] ?? '' ) > 40 ? substr( $j['status'], 0, 40 ) . '...' : ( $j['status'] ?? '' );

				if ( 'system' === $source ) {
					$flow_display = $j['label'] ?? $j['display_label'] ?? 'System Task';
				} else {
					$flow_display = $j['flow_name'] ?? ( isset( $j['flow_id'] ) ? "Flow {$j['flow_id']}" : '' );
				}

				$item = array(
					'id'        => $j['job_id'] ?? '',
					'source'    => $source,
					'flow'      => $flow_display,
					'status'    => $status_display,
					'created'   => $j['created_at'] ?? '',
					'completed' => $j['completed_at'] ?? '-',
				);

				if ( 'json' === $format ) {
					$metrics              = RunMetrics::fromJob( $j );
					$item['pipeline_id']  = $j['pipeline_id'] ?? null;
					$item['flow_id']      = $j['flow_id'] ?? null;
					$item['handler_slug'] = $metrics['outcome']['handler_slug'] ?? null;
					$item['outcome']      = $metrics['outcome'];
					$item['step_results'] = $metrics['step_results'];
					$item['counts']       = $metrics['counts'];
				}

				return $item;
			},
			$jobs
		);

		if ( ! empty( $fields ) ) {
			$this->format_items( $items, $fields, $assoc_args, 'id' );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( AbilityResult::cli_collection_payload( $items, $result, 'jobs' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$this->format_items( $items, $this->default_fields, $assoc_args, 'id' );

		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'Showing %d jobs.', count( $jobs ) ) );
		}
	}

	/**
	 * Parse comma-separated list fields.
	 */
	private function parse_job_list_fields( string $fields ): array {
		if ( '' === trim( $fields ) ) {
			return array();
		}

		$parsed = array();
		foreach ( explode( ',', $fields ) as $field ) {
			$field = sanitize_key( trim( $field ) );
			if ( '' !== $field ) {
				$parsed[] = $field;
			}
		}

		return array_values( array_unique( $parsed ) );
	}

	/**
	 * Translate CLI output fields to the minimum job table fields needed.
	 */
	private function get_database_fields_for_job_list_fields( array $fields ): array {
		$field_map = array(
			'id'            => array( 'job_id' ),
			'job_id'        => array( 'job_id' ),
			'user_id'       => array( 'user_id' ),
			'pipeline_id'   => array( 'pipeline_id' ),
			'flow_id'       => array( 'flow_id' ),
			'source'        => array( 'source' ),
			'label'         => array( 'label' ),
			'parent_job_id' => array( 'parent_job_id' ),
			'status'        => array( 'status' ),
			'created'       => array( 'created_at' ),
			'created_at'    => array( 'created_at' ),
			'completed'     => array( 'completed_at' ),
			'completed_at'  => array( 'completed_at' ),
			'flow'          => array( 'source', 'label', 'flow_id', 'flow_name' ),
			'pipeline_name' => array( 'pipeline_name' ),
			'flow_name'     => array( 'flow_name' ),
			'handler_slug'  => array( 'status', 'engine_data' ),
			'outcome'       => array( 'status', 'engine_data' ),
			'step_results'  => array( 'status', 'engine_data' ),
			'counts'        => array( 'status', 'engine_data' ),
		);

		$database_fields = array( 'job_id' );
		foreach ( $fields as $field ) {
			foreach ( $field_map[ $field ] ?? array( $field ) as $database_field ) {
				$database_fields[] = $database_field;
			}
		}

		return array_values( array_unique( $database_fields ) );
	}

	/**
	 * Build one low-memory CLI output row for an explicit --fields list.
	 */
	private function format_requested_job_list_fields( array $job, array $fields ): array {
		$item    = array();
		$metrics = null;

		foreach ( $fields as $field ) {
			switch ( $field ) {
				case 'id':
					$item[ $field ] = $job['job_id'] ?? '';
					break;
				case 'created':
					$item[ $field ] = $job['created_at'] ?? '';
					break;
				case 'completed':
					$item[ $field ] = $job['completed_at'] ?? '-';
					break;
				case 'flow':
					$item[ $field ] = 'system' === ( $job['source'] ?? 'pipeline' )
						? ( $job['label'] ?? $job['display_label'] ?? 'System Task' )
						: ( $job['flow_name'] ?? ( isset( $job['flow_id'] ) ? "Flow {$job['flow_id']}" : '' ) );
					break;
				case 'handler_slug':
				case 'outcome':
				case 'step_results':
				case 'counts':
					$metrics        = $metrics ?? RunMetrics::fromJob( $job );
					$item[ $field ] = 'handler_slug' === $field ? ( $metrics['outcome']['handler_slug'] ?? null ) : $metrics[ $field ];
					break;
				default:
					$item[ $field ] = $job[ $field ] ?? null;
			}
		}

		return $item;
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

		$result = ( new GetJobsAbility() )->execute( array( 'job_id' => (int) $job_id ) );

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
			/** @phpstan-ignore-next-line Spyc is provided by WP-CLI at runtime. */
			WP_CLI::log( (string) call_user_func( array( 'Spyc', 'YAMLDump' ), $job, false, false, true ) );
			return;
		}

		$this->outputJobTable( $job );
	}

	/**
	 * Read the persisted AI conversation transcript for a job.
	 *
	 * Reads engine_data['transcript_session_id'] from the job and renders
	 * the persisted $messages array. Errors cleanly when the job has no
	 * transcript persisted (default behavior — persistence is opt-in).
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID whose transcript should be read.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--raw]
	 * : Dump the messages array verbatim (only with --format=json or yaml).
	 *
	 * ## EXAMPLES
	 *
	 *     # Render the transcript human-readable
	 *     wp datamachine jobs transcript 844
	 *
	 *     # Pipe full messages array as JSON for further processing
	 *     wp datamachine jobs transcript 844 --format=json --raw
	 *
	 * @subcommand transcript
	 */
	public function transcript( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || ! is_numeric( $args[0] ) || (int) $args[0] <= 0 ) {
			WP_CLI::error( 'Job ID is required and must be a positive integer.' );
			return;
		}

		$job_id = (int) $args[0];
		$format = $assoc_args['format'] ?? 'text';
		$raw    = isset( $assoc_args['raw'] );

		$result = ( new GetJobsAbility() )->execute( array( 'job_id' => $job_id ) );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$jobs = $result['jobs'] ?? array();
		if ( empty( $jobs ) ) {
			WP_CLI::error( sprintf( 'Job %d not found.', $job_id ) );
			return;
		}

		$job                   = $jobs[0];
		$engine_data           = $job['engine_data'] ?? array();
		$transcript_session_id = $engine_data['transcript_session_id'] ?? '';
		if ( empty( $transcript_session_id ) ) {
			$transcript_session_id = $this->findTranscriptSessionIdForJob( $job_id );
		}

		if ( empty( $transcript_session_id ) ) {
			WP_CLI::error(
				sprintf(
					'No transcript persisted for job %d. Enable datamachine_persist_pipeline_transcripts (or set persist_transcripts on the pipeline/flow) and re-run the job.',
					$job_id
				)
			);
			return;
		}

		$store   = ConversationStoreFactory::get();
		$session = $store->get_session( (string) $transcript_session_id );

		if ( ! $session ) {
			WP_CLI::error(
				sprintf(
					'Transcript session %s referenced by job %d is missing. It may have been deleted by retention.',
					$transcript_session_id,
					$job_id
				)
			);
			return;
		}

		$messages = $session['messages'] ?? array();
		$metadata = $session['metadata'] ?? array();

		if ( 'json' === $format ) {
			$payload = $raw ? $messages : array(
				'job_id'     => $job_id,
				'session_id' => $transcript_session_id,
				'metadata'   => $metadata,
				'provider'   => $session['provider'] ?? null,
				'model'      => $session['model'] ?? null,
				'messages'   => $messages,
			);
			WP_CLI::log( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		if ( 'yaml' === $format ) {
			$payload = $raw ? $messages : array(
				'job_id'     => $job_id,
				'session_id' => $transcript_session_id,
				'metadata'   => $metadata,
				'provider'   => $session['provider'] ?? null,
				'model'      => $session['model'] ?? null,
				'messages'   => $messages,
			);
			/** @phpstan-ignore-next-line Spyc is provided by WP-CLI at runtime. */
			WP_CLI::log( (string) call_user_func( array( 'Spyc', 'YAMLDump' ), $payload, false, false, true ) );
			return;
		}

		$this->renderTranscriptText( $job_id, (string) $transcript_session_id, $session, $messages, $metadata );
	}

	/**
	 * Export structured artifacts for a job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID whose artifacts should be exported.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine jobs artifacts 844 --format=json
	 *
	 * @subcommand artifacts
	 */
	public function artifacts( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || ! is_numeric( $args[0] ) || (int) $args[0] <= 0 ) {
			WP_CLI::error( 'Job ID is required and must be a positive integer.' );
			return;
		}

		$result = ( new JobArtifacts() )->get( (int) $args[0] );
		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to build job artifacts.' );
			return;
		}

		$payload = $result['artifacts'] ?? array();
		$format  = $assoc_args['format'] ?? 'json';

		if ( 'yaml' === $format ) {
			/** @phpstan-ignore-next-line Spyc is provided by WP-CLI at runtime. */
			WP_CLI::log( (string) call_user_func( array( 'Spyc', 'YAMLDump' ), $payload, false, false, true ) );
			return;
		}

		WP_CLI::log( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Hydrate verified artifact content by portable artifact ref.
	 *
	 * ## OPTIONS
	 *
	 * <artifact_ref>
	 * : The portable artifact ref to hydrate.
	 *
	 * [--format=<format>]
	 * : Output format. `raw` streams the verified content bytes.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - raw
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine jobs artifact-content datamachine://jobs/844/artifacts/tool-trace --format=raw
	 *
	 * @subcommand artifact-content
	 */
	public function artifact_content( array $args, array $assoc_args ): void {
		$artifact_ref = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === trim( $artifact_ref ) ) {
			WP_CLI::error( 'artifact_ref is required.' );
			return;
		}

		$format    = (string) ( $assoc_args['format'] ?? 'json' );
		$artifacts = new JobArtifacts();
		if ( 'raw' === $format ) {
			$result = $artifacts->stream_artifact_ref(
				$artifact_ref,
				static function ( string $content ): void {
					fwrite( STDOUT, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				}
			);
			if ( empty( $result['success'] ) ) {
				WP_CLI::error( $result['error'] ?? 'Failed to hydrate artifact content.' );
			}
			return;
		}

		$result = $artifacts->hydrate_artifact_ref( $artifact_ref );
		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Failed to hydrate artifact content.' );
			return;
		}

		$content = (string) ( $result['content'] ?? '' );
		unset( $result['content'] );
		$result['content_base64'] = base64_encode( $content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes verified artifact bytes for JSON-safe transport.
		$result['encoding']       = 'base64';

		WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Locate a pipeline transcript by metadata for jobs created before engine_data
	 * stored transcript_session_id.
	 *
	 * @param int $job_id Job ID.
	 * @return string Transcript session ID, or empty string when none exists.
	 */
	private function findTranscriptSessionIdForJob( int $job_id ): string {
		global $wpdb;

		$table = Chat::get_prefixed_table_name();
		$like  = '%"job_id":' . $job_id . '%';
		$row   = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT session_id FROM %i WHERE mode = %s AND metadata LIKE %s ORDER BY created_at DESC LIMIT 1',
				$table,
				'pipeline',
				$like
			)
		);

		return is_string( $row ) ? $row : '';
	}

	/**
	 * Render a transcript as a human-readable turn-by-turn text block.
	 *
	 * @param int    $job_id     Owning job ID.
	 * @param string $session_id Transcript session UUID.
	 * @param array  $session    Decoded session row.
	 * @param array  $messages   Decoded messages array.
	 * @param array  $metadata   Decoded metadata array.
	 */
	private function renderTranscriptText( int $job_id, string $session_id, array $session, array $messages, array $metadata ): void {
		WP_CLI::log( sprintf( 'Transcript for job %d', $job_id ) );
		WP_CLI::log( sprintf( '  Session: %s', $session_id ) );
		WP_CLI::log( sprintf( '  Provider: %s', $session['provider'] ?? ( $metadata['provider'] ?? 'unknown' ) ) );
		WP_CLI::log( sprintf( '  Model: %s', $session['model'] ?? ( $metadata['model'] ?? 'unknown' ) ) );
		WP_CLI::log( sprintf( '  Turns: %d', $metadata['turn_count'] ?? 0 ) );
		WP_CLI::log( sprintf( '  Completed: %s', ! empty( $metadata['completed'] ) ? 'true' : 'false' ) );
		if ( ! empty( $metadata['error'] ) ) {
			WP_CLI::log( sprintf( '  Error: %s', $metadata['error'] ) );
		}
		$usage = $metadata['usage'] ?? array();
		if ( ! empty( $usage['total_tokens'] ) ) {
			WP_CLI::log(
				sprintf(
					'  Tokens: prompt=%d completion=%d total=%d',
					$usage['prompt_tokens'] ?? 0,
					$usage['completion_tokens'] ?? 0,
					$usage['total_tokens'] ?? 0
				)
			);
		}
		$request_metadata = $metadata['request_metadata'] ?? array();
		if ( is_array( $request_metadata ) && ! empty( $request_metadata ) ) {
			WP_CLI::log(
				sprintf(
					'  Request: %s total, %s messages, %s tools, %d tools',
					size_format( (int) ( $request_metadata['request_json_bytes'] ?? 0 ) ),
					size_format( (int) ( $request_metadata['messages_json_bytes'] ?? 0 ) ),
					size_format( (int) ( $request_metadata['tools_json_bytes'] ?? 0 ) ),
					(int) ( $request_metadata['tools']['count'] ?? 0 )
				)
			);
		}
		WP_CLI::log( sprintf( '  Messages: %d', count( $messages ) ) );
		WP_CLI::log( '' );

		foreach ( $messages as $idx => $message ) {
			$message = WP_Agent_Message::normalize( $message );
			$role    = $message['role'] ?? 'unknown';
			$type    = $message['type'] ?? WP_Agent_Message::TYPE_TEXT;
			$content = $message['content'] ?? '';
			$header  = sprintf( '[%d] %s (%s)', $idx, $role, $type );

			WP_CLI::log( $header );
			WP_CLI::log( str_repeat( '-', min( 80, strlen( $header ) ) ) );

			if ( is_array( $content ) ) {
				WP_CLI::log( wp_json_encode( $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			} else {
				WP_CLI::log( (string) $content );
			}
			WP_CLI::log( '' );
		}
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

		// Display structured error details for failed jobs (persisted by #536).
		if ( 'failed' === $parsed_status['type'] ) {
			$engine_data   = $job['engine_data'] ?? array();
			$error_message = $engine_data['error_message'] ?? null;
			$error_step_id = $engine_data['error_step_id'] ?? null;
			$error_trace   = $engine_data['error_trace'] ?? null;

			if ( $error_message ) {
				WP_CLI::log( '' );
				WP_CLI::log( WP_CLI::colorize( '%RError:%n ' . $error_message ) );

				if ( $error_step_id ) {
					WP_CLI::log( sprintf( '  Step: %s', $error_step_id ) );
				}

				if ( $error_trace ) {
					WP_CLI::log( '' );
					WP_CLI::log( '  Stack Trace (truncated):' );
					$trace_lines = explode( "\n", $error_trace );
					foreach ( array_slice( $trace_lines, 0, 10 ) as $line ) {
						WP_CLI::log( '    ' . $line );
					}
					if ( count( $trace_lines ) > 10 ) {
						WP_CLI::log( sprintf( '    ... (%d more lines, use --format=json for full trace)', count( $trace_lines ) - 10 ) );
					}
				}
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Created: %s', $job['created_at_display'] ?? $job['created_at'] ?? 'N/A' ) );
		WP_CLI::log( sprintf( 'Completed: %s', $job['completed_at_display'] ?? $job['completed_at'] ?? '-' ) );

		// Show Action Scheduler status for processing/pending jobs (#169).
		if ( in_array( $parsed_status['type'], array( 'processing', 'pending' ), true ) ) {
			$this->outputActionSchedulerStatus( (int) ( $job['job_id'] ?? 0 ) );
		}

		$engine_data = $job['engine_data'] ?? array();

		// Surface transcript availability on its own line so operators see
		// the read command they can run. Stripped from engine_data summary
		// below to avoid double-rendering.
		$transcript_session_id = $engine_data['transcript_session_id'] ?? '';
		if ( ! empty( $transcript_session_id ) ) {
			WP_CLI::log( '' );
			WP_CLI::log(
				sprintf(
					'Transcript: session_id=%s  (wp datamachine jobs transcript %d)',
					$transcript_session_id,
					$job['job_id'] ?? 0
				)
			);
		}

		// Strip error keys already displayed in the error section above
		// and the transcript_session_id surfaced separately.
		unset(
			$engine_data['error_reason'],
			$engine_data['error_message'],
			$engine_data['error_step_id'],
			$engine_data['error_trace'],
			$engine_data['transcript_session_id']
		);

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
		/** @var object{message?: string, log_date_gmt?: string}|null $log */
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
			$log_date_gmt = is_string( $log->log_date_gmt ?? null ) ? $log->log_date_gmt : '';
			WP_CLI::log( sprintf( '  Last Log: %s (%s)', $log->message, $log_date_gmt ) );
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
				$size              = strlen( is_string( $json ) ? $json : '' );
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
	 * [--status=<status>]
	 * : Filter by status prefix.
	 *
	 * [--source=<source>]
	 * : Filter by job source.
	 *
	 * [--flow=<flow_id>]
	 * : Filter by flow ID.
	 *
	 * [--pipeline=<pipeline_id>]
	 * : Filter by pipeline ID.
	 *
	 * [--handler=<handler_slug>]
	 * : Filter by handler slug recorded in generic job outcome metadata.
	 *
	 * [--since=<datetime>]
	 * : Summarize jobs created after this time. Accepts ISO datetime or relative strings (e.g., "1 hour ago", "today", "yesterday").
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
	 *     # Dashboard-safe summary for today's jobs in one pipeline
	 *     wp datamachine jobs summary --pipeline=42 --since=today --format=json
	 *
	 * @subcommand summary
	 */
	public function summary( array $args, array $assoc_args ): void {
		$input = AgentResolver::buildScopingInput( $assoc_args );

		if ( ! empty( $assoc_args['status'] ) ) {
			$input['status'] = (string) $assoc_args['status'];
		}

		if ( isset( $assoc_args['flow'] ) ) {
			$input['flow_id'] = (string) $assoc_args['flow'];
		}

		if ( isset( $assoc_args['pipeline'] ) ) {
			$input['pipeline_id'] = (string) $assoc_args['pipeline'];
		}

		if ( ! empty( $assoc_args['source'] ) ) {
			$input['source'] = (string) $assoc_args['source'];
		}

		if ( ! empty( $assoc_args['handler'] ) ) {
			$input['handler'] = (string) $assoc_args['handler'];
		}

		$since = $assoc_args['since'] ?? null;
		if ( $since ) {
			$timestamp = strtotime( $since );
			if ( false === $timestamp ) {
				WP_CLI::error( sprintf( 'Invalid --since value: "%s". Use ISO datetime or relative string (e.g., "1 hour ago", "today").', $since ) );
				return;
			}
			$input['since'] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		$result = ( new JobsSummaryAbility() )->execute( $input );

		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$summary = $result['summary'] ?? array();

		if ( empty( $summary ) ) {
			WP_CLI::warning( 'No job summary data available.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			/** @phpstan-ignore-next-line Spyc is provided by WP-CLI at runtime. */
			WP_CLI::log( (string) call_user_func( array( 'Spyc', 'YAMLDump' ), $summary, false, false, true ) );
			return;
		}

		$items = array();
		foreach ( array( 'status', 'pipeline', 'flow', 'handler' ) as $group ) {
			foreach ( $summary[ $group ] ?? array() as $row ) {
				$items[] = array(
					'group' => $group,
					'key'   => $this->get_summary_row_key( $group, $row ),
					'name'  => $this->get_summary_row_name( $group, $row ),
					'count' => (int) ( $row['count'] ?? 0 ),
				);
			}
		}

		$this->format_items( $items, array( 'group', 'key', 'name', 'count' ), $assoc_args );

		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'Total: %d', (int) ( $summary['total'] ?? 0 ) ) );
			WP_CLI::log( sprintf( 'Failed: %d', (int) ( $summary['failed_count'] ?? 0 ) ) );
			WP_CLI::log( sprintf( 'Stuck processing: %d', (int) ( $summary['stuck_processing_count'] ?? 0 ) ) );
		}
	}

	/**
	 * Get a stable key for a summary table row.
	 */
	private function get_summary_row_key( string $group, array $row ): string {
		return match ( $group ) {
			'status' => (string) ( $row['status'] ?? '' ),
			'pipeline' => (string) ( $row['pipeline_id'] ?? '' ),
			'flow' => (string) ( $row['flow_id'] ?? '' ),
			'handler' => (string) ( $row['handler_slug'] ?? '' ),
			default => '',
		};
	}

	/**
	 * Get a display name for a summary table row.
	 */
	private function get_summary_row_name( string $group, array $row ): string {
		return match ( $group ) {
			'pipeline' => (string) ( $row['pipeline_name'] ?? '' ),
			'flow' => (string) ( $row['flow_name'] ?? '' ),
			default => '',
		};
	}

	/**
	 * Show run metrics for a job.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID to inspect.
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
	 *     # Show run metrics for a long backfill parent job
	 *     wp datamachine jobs metrics 844
	 *
	 *     # Machine-readable metrics
	 *     wp datamachine jobs metrics 844 --format=json
	 *
	 * @subcommand metrics
	 */
	public function metrics( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || ! is_numeric( $args[0] ) || (int) $args[0] <= 0 ) {
			WP_CLI::error( 'Job ID is required and must be a positive integer.' );
			return;
		}

		$result = ( new RunMetricsAbility() )->execute( array( 'job_id' => (int) $args[0] ) );
		if ( ! $result['success'] ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error occurred' );
			return;
		}

		$metrics = $result['metrics'] ?? array();
		$format  = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'yaml' === $format ) {
			/** @phpstan-ignore-next-line Spyc is provided by WP-CLI at runtime. */
			WP_CLI::log( (string) call_user_func( array( 'Spyc', 'YAMLDump' ), $metrics, false, false, true ) );
			return;
		}

		$counts     = $metrics['counts'] ?? array();
		$children   = $metrics['child_jobs'] ?? array();
		$timestamps = $metrics['timestamps'] ?? array();
		$classes    = is_array( $metrics['outcome_classes'] ?? null ) ? $metrics['outcome_classes'] : array();

		WP_CLI::log( sprintf( 'Job ID: %d', $metrics['job_id'] ?? 0 ) );
		WP_CLI::log( sprintf( 'Status: %s', $metrics['status'] ?? '' ) );
		WP_CLI::log( sprintf( 'Source: %s', $metrics['source'] ?? '' ) );
		WP_CLI::log( sprintf( 'Label: %s', $metrics['label'] ?? '' ) );
		WP_CLI::log( sprintf( 'Flow ID: %s', $metrics['flow_id'] ?? 'N/A' ) );
		WP_CLI::log( sprintf( 'Pipeline ID: %s', $metrics['pipeline_id'] ?? 'N/A' ) );
		WP_CLI::log( sprintf( 'Started: %s', $timestamps['started_at'] ?? '-' ) );
		WP_CLI::log( sprintf( 'Last Activity: %s', $timestamps['last_activity_at'] ?? '-' ) );
		WP_CLI::log( sprintf( 'Completed: %s', $timestamps['completed_at'] ?? '-' ) );
		WP_CLI::log( sprintf( 'Duration: %s seconds', null === ( $metrics['duration_seconds'] ?? null ) ? '-' : (string) $metrics['duration_seconds'] ) );
		WP_CLI::log( '' );

		WP_CLI::log( 'Counts:' );
		foreach ( $counts as $key => $value ) {
			WP_CLI::log( sprintf( '  %s: %d', $key, (int) $value ) );
		}
		WP_CLI::log( '' );

		WP_CLI::log( 'Outcome Classes:' );
		WP_CLI::log( empty( $classes ) ? '  -' : '  ' . implode( ', ', array_map( 'strval', $classes ) ) );
		WP_CLI::log( '' );

		WP_CLI::log( 'Child Jobs:' );
		foreach ( $children as $key => $value ) {
			WP_CLI::log( sprintf( '  %s: %d', $key, (int) $value ) );
		}
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

		$result = ( new FailJobAbility() )->execute(
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

		$result = ( new RetryJobAbility() )->execute(
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

		$result = ( new DeleteJobsAbility() )->execute(
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
	 * Cleanup old jobs by status and age.
	 *
	 * Removes jobs matching a status that are older than a specified age.
	 * Useful for keeping the jobs table clean by purging stale failures,
	 * completed jobs, or other terminal statuses.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<duration>]
	 * : Delete jobs older than this duration. Accepts days (e.g., 30d),
	 *   weeks (e.g., 4w), or hours (e.g., 72h).
	 * ---
	 * default: 30d
	 * ---
	 *
	 * [--status=<status>]
	 * : Which job status to clean up. Uses prefix matching to catch
	 *   compound statuses (e.g., "failed" matches "failed - timeout").
	 * ---
	 * default: failed
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would be deleted without making changes.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview cleanup of failed jobs older than 30 days
	 *     wp datamachine jobs cleanup --dry-run
	 *
	 *     # Delete failed jobs older than 30 days
	 *     wp datamachine jobs cleanup --yes
	 *
	 *     # Delete failed jobs older than 2 weeks
	 *     wp datamachine jobs cleanup --older-than=2w --yes
	 *
	 *     # Delete completed jobs older than 90 days
	 *     wp datamachine jobs cleanup --status=completed --older-than=90d --yes
	 *
	 *     # Delete agent_skipped jobs older than 1 week
	 *     wp datamachine jobs cleanup --status=agent_skipped --older-than=1w
	 *
	 * @subcommand cleanup
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$duration_str = $assoc_args['older-than'] ?? '30d';
		$status       = $assoc_args['status'] ?? 'failed';
		$dry_run      = isset( $assoc_args['dry-run'] );
		$skip_confirm = isset( $assoc_args['yes'] );

		$days = $this->parseDurationToDays( $duration_str );
		if ( null === $days ) {
			WP_CLI::error( sprintf( 'Invalid duration format: "%s". Use format like 30d, 4w, or 72h.', $duration_str ) );
			return;
		}

		$db_jobs = new Jobs();
		$count   = $db_jobs->count_old_jobs( $status, $days );

		if ( 0 === $count ) {
			WP_CLI::success( sprintf( 'No "%s" jobs older than %s found. Nothing to clean up.', $status, $duration_str ) );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d "%s" job(s) older than %s (%d days).', $count, $status, $duration_str, $days ) );

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'Dry run: %d job(s) would be deleted.', $count ) );
			return;
		}

		if ( ! $skip_confirm ) {
			WP_CLI::confirm( sprintf( 'Delete %d "%s" job(s) older than %s?', $count, $status, $duration_str ) );
		}

		$deleted = $db_jobs->delete_old_jobs( $status, $days );

		if ( false === $deleted ) {
			WP_CLI::error( 'Failed to delete jobs.' );
			return;
		}

		WP_CLI::success( sprintf( 'Deleted %d "%s" job(s) older than %s.', $deleted, $status, $duration_str ) );
	}

	/**
	 * Parse a human-readable duration string to days.
	 *
	 * Supports formats: 30d (days), 4w (weeks), 72h (hours).
	 *
	 * @param string $duration Duration string.
	 * @return int|null Number of days, or null if invalid.
	 */
	private function parseDurationToDays( string $duration ): ?int {
		if ( ! preg_match( '/^(\d+)(d|w|h)$/i', trim( $duration ), $matches ) ) {
			return null;
		}

		$value = (int) $matches[1];
		$unit  = strtolower( $matches[2] );

		if ( $value <= 0 ) {
			return null;
		}

		return match ( $unit ) {
			'd' => $value,
			'w' => $value * 7,
			'h' => max( 1, (int) ceil( $value / 24 ) ),
			default => null,
		};
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
		$handlers = TaskRegistry::getHandlers();

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
			if ( ! $task instanceof SystemTask ) {
				WP_CLI::warning( sprintf( 'Job #%d: task type "%s" is not a SystemTask.', $jid, $jtype ) );
				++$total_skipped;
				continue;
			}

			if ( ! $task->supportsUndo() ) {
				WP_CLI::log( sprintf( '  Job #%d: task type "%s" does not support undo.', $jid, $jtype ) );
				++$total_skipped;
				continue;
			}

			// Dry run — describe what would happen. For fan-out parents
			// the parent has no own effects; aggregate from children
			// via Jobs::get_children so the preview is accurate.
			if ( $dry_run ) {
				$preview_effects = is_array( $engine_data['effects'] ?? null ) ? $engine_data['effects'] : array();
				if ( empty( $preview_effects ) ) {
					foreach ( $jobs_db->get_children( (int) $jid ) as $child ) {
						$child_data      = is_array( $child['engine_data'] ?? null ) ? $child['engine_data'] : array();
						$child_effects   = is_array( $child_data['effects'] ?? null ) ? $child_data['effects'] : array();
						$preview_effects = array_merge( $preview_effects, $child_effects );
					}
				}

				if ( empty( $preview_effects ) ) {
					WP_CLI::log( sprintf( '  Job #%d (%s): no effects to undo.', $jid, $jtype ) );
					++$total_skipped;
					continue;
				}

				WP_CLI::log( sprintf( '  Job #%d (%s): would undo %d effect(s):', $jid, $jtype, count( $preview_effects ) ) );
				foreach ( $preview_effects as $effect ) {
					$type   = $effect['type'] ?? 'unknown';
					$target = $effect['target'] ?? array();
					WP_CLI::log( sprintf( '    - %s → %s', $type, wp_json_encode( $target ) ) );
				}
				continue;
			}

			// Execute undo. SystemTask::undo handles both leaf jobs
			// (own effects) and fan-out parents (effects from children
			// via Jobs::get_children). The empty-effects-no-op case is
			// handled inside the task with a structured envelope.
			WP_CLI::log( sprintf( '  Job #%d (%s): undoing...', $jid, $jtype ) );
			$result = $task->undo( $jid, $engine_data );

			$reverted = $result['reverted'];
			$skipped  = $result['skipped'];
			$failed   = $result['failed'];

			if ( empty( $reverted ) && empty( $skipped ) && empty( $failed ) ) {
				WP_CLI::log( sprintf( '  Job #%d (%s): no effects to undo.', $jid, $jtype ) );
				++$total_skipped;
				continue;
			}

			foreach ( $reverted as $r ) {
				WP_CLI::log( sprintf( '    ✓ %s reverted', $r['type'] ?? 'unknown' ) );
			}
			foreach ( $skipped as $s ) {
				WP_CLI::log( sprintf( '    - %s skipped: %s', $s['type'] ?? 'unknown', $s['reason'] ?? '' ) );
			}
			foreach ( $failed as $f ) {
				WP_CLI::warning( sprintf( '    ✗ %s failed: %s', $f['type'] ?? 'unknown', $f['reason'] ?? '' ) );
			}

			$total_reverted += count( $reverted );
			$total_skipped  += count( $skipped );
			$total_failed   += count( $failed );

			// Record undo metadata in engine_data.
			$engine_data['undo'] = array(
				'undone_at'        => current_time( 'mysql' ),
				'effects_reverted' => count( $reverted ),
				'effects_skipped'  => count( $skipped ),
				'effects_failed'   => count( $failed ),
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
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
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
		// phpcs:enable WordPress.DB.PreparedSQL

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
