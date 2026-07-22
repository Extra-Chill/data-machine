<?php
// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned,Generic.Formatting.MultipleStatementAlignment -- Worker result and option keys are intentionally descriptive.
/**
 * WP-CLI Data Machine worker command.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Abilities\Engine\DrainJobAbility;
use DataMachine\Abilities\Job\JobsSummaryAbility;
use DataMachine\Abilities\Job\RecoverStuckJobsAbility;
use DataMachine\Cli\BaseCommand;
use DataMachine\Cli\WorkerLock;
use DataMachine\Engine\AI\Actions\PendingActionStore;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Keep Data Machine automation moving from a headless CLI process.
 */
class WorkerCommand extends BaseCommand {

	/**
	 * Run a bounded Data Machine worker loop.
	 *
	 * The worker composes existing runtime primitives: stuck-job recovery and the
	 * first-class Data Machine drain loop. It does not process Action Scheduler or
	 * job rows directly.
	 *
	 * ## OPTIONS
	 *
	 * [--time-limit=<seconds>]
	 * : Maximum wall-clock seconds to run. 0 means no time limit.
	 * ---
	 * default: 300
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : Maximum actions to ask the drain loop to claim per batch.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--drain-limit=<number>]
	 * : Maximum actions to execute per drain pass. 0 means no action-count limit.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--drain-time-limit=<seconds>]
	 * : Maximum seconds per drain pass.
	 * ---
	 * default: 120
	 * ---
	 *
	 * [--sleep=<seconds>]
	 * : Seconds to sleep between idle passes.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--stuck-timeout=<hours>]
	 * : Hours before processing jobs are treated as stale.
	 * ---
	 * default: 2
	 * ---
	 *
	 * [--no-recover-stuck]
	 * : Skip stuck-job recovery.
	 *
	 * [--stop-on-pending-actions]
	 * : Stop when pending approval actions exist.
	 *
	 * [--max-passes=<number>]
	 * : Maximum worker passes to run. 0 means no pass-count limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--stop-before-timeout=<seconds>]
	 * : Stop this many seconds before the wall-clock limit so the worker exits cleanly before an external supervisor timeout.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--once]
	 * : Run one recovery/drain pass and exit.
	 *
	 * [--mode=<mode>]
	 * : Worker execution mode. queue drains the shared Action Scheduler queue; job claims and drains one Data Machine job at a time.
	 * ---
	 * default: queue
	 * options:
	 *   - queue
	 *   - job
	 * ---
	 *
	 * [--job-step-budget=<number>]
	 * : Maximum due actions to drain for one claimed job before moving on.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--lane=<lane>]
	 * : Optional worker lane to run. Supported lanes: publish, background.
	 * Publish drains AI/upsert step executions; background drains non-publish work.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine worker run --time-limit=3600 --sleep=30
	 *     wp datamachine worker run --time-limit=900 --max-passes=10 --stop-before-timeout=60
	 *     wp datamachine worker run --once --stop-on-pending-actions
	 *
	 * @subcommand run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $args );

		$options = array(
			'time_limit'              => isset( $assoc_args['time-limit'] ) ? max( 0, (int) $assoc_args['time-limit'] ) : 300,
			'batch_size'              => isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 10,
			'drain_limit'             => isset( $assoc_args['drain-limit'] ) ? max( 0, (int) $assoc_args['drain-limit'] ) : 25,
			'drain_time_limit'        => isset( $assoc_args['drain-time-limit'] ) ? max( 1, (int) $assoc_args['drain-time-limit'] ) : 120,
			'sleep'                   => isset( $assoc_args['sleep'] ) ? max( 0, (int) $assoc_args['sleep'] ) : 30,
			'stuck_timeout'           => isset( $assoc_args['stuck-timeout'] ) ? max( 1, (int) $assoc_args['stuck-timeout'] ) : 2,
			'recover_stuck'           => ! isset( $assoc_args['no-recover-stuck'] ),
			'stop_on_pending_actions' => isset( $assoc_args['stop-on-pending-actions'] ),
			'max_passes'              => isset( $assoc_args['max-passes'] ) ? max( 0, (int) $assoc_args['max-passes'] ) : 0,
			'stop_before_timeout'     => isset( $assoc_args['stop-before-timeout'] ) ? max( 0, (int) $assoc_args['stop-before-timeout'] ) : 30,
			'once'                    => isset( $assoc_args['once'] ),
			'mode'                    => isset( $assoc_args['mode'] ) ? (string) $assoc_args['mode'] : 'queue',
			'job_step_budget'         => isset( $assoc_args['job-step-budget'] ) ? max( 1, (int) $assoc_args['job-step-budget'] ) : 50,
			'lane'                    => isset( $assoc_args['lane'] ) ? (string) $assoc_args['lane'] : '',
		);

		$stats = self::runLoop( $options );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->format_items( array( $stats ), array_keys( $stats ), array( 'format' => 'table' ) );
	}

	/**
	 * Render a lightweight worker status snapshot.
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
		 * ---
		 *
		 * [--lane=<lane>]
		 * : Optional worker lane status. Supported lanes: publish, background.
		 *
		 * @subcommand status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );

		$status = self::statusSnapshot( isset( $assoc_args['lane'] ) ? (string) $assoc_args['lane'] : '' );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $status, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->format_items( array( $status ), array_keys( $status ), array( 'format' => 'table' ) );
	}

	/**
	 * Execute the worker loop.
	 *
	 * @param array<string,mixed> $options Worker options.
	 * @return array<string,int|string> Worker stats.
	 */
	public static function runLoop( array $options ): array {
		DrainCommand::ensureCliMemoryLimit();

		if ( 'job' === self::normalizeMode( $options['mode'] ?? 'queue' ) ) {
			return self::runJobLoop( $options );
		}

		$started_at              = time();
		$time_limit              = max( 0, (int) ( $options['time_limit'] ?? 300 ) );
		$batch_size              = max( 1, (int) ( $options['batch_size'] ?? 10 ) );
		$drain_limit             = max( 0, (int) ( $options['drain_limit'] ?? 25 ) );
		$drain_time_limit        = max( 1, (int) ( $options['drain_time_limit'] ?? 120 ) );
		$sleep                   = max( 0, (int) ( $options['sleep'] ?? 30 ) );
		$stuck_timeout           = max( 1, (int) ( $options['stuck_timeout'] ?? 2 ) );
		$recover_stuck           = (bool) ( $options['recover_stuck'] ?? true );
		$stop_on_pending_actions = (bool) ( $options['stop_on_pending_actions'] ?? false );
		$max_passes              = max( 0, (int) ( $options['max_passes'] ?? 0 ) );
		$stop_before_timeout     = max( 0, (int) ( $options['stop_before_timeout'] ?? 30 ) );
		$once                    = (bool) ( $options['once'] ?? false );
		$lane                    = self::normalizeLane( $options['lane'] ?? '' );
		$lock                    = WorkerLock::acquire( self::defaultLockOwner( $lane ), $time_limit > 0 ? $time_limit + max( 60, $stop_before_timeout ) : 600, $lane );

		if ( empty( $lock['acquired'] ) ) {
			return self::lockedStats( $lock, $lane );
		}

		$lock_token = (string) ( $lock['lock_token'] ?? '' );
		$lock_lane  = $lane;
		register_shutdown_function(
			static function () use ( $lock_token, $lock_lane ): void {
				WorkerLock::release( $lock_token, $lock_lane );
			}
		);

		$passes      = 0;
		$recoveries  = 0;
		$timed_out   = 0;
		$reconciled  = 0;
		$pathless_requeued = 0;
		$pathless_terminal = 0;
		$recovery_claim_conflicts = 0;
		$pathless_policy_skipped = 0;
		$recovery_mutations = 0;
		$recovery_requeued = 0;
		$recovery_skipped = 0;
		$recovery_limit_reached = 0;
		$recovery_ran = false;
		$completions = 0;
		$failures    = 0;
		$warnings    = 0;
		$stop_reason = 'time_limit';

		try {
			while ( true ) {
				$elapsed = time() - $started_at;
				if ( $time_limit > 0 && $elapsed >= $time_limit ) {
					break;
				}
				if ( $time_limit > 0 && ( $time_limit - $elapsed ) <= $stop_before_timeout ) {
					$stop_reason = 'timeout_margin';
					break;
				}
				if ( $max_passes > 0 && $passes >= $max_passes ) {
					$stop_reason = 'max_passes';
					break;
				}

				if ( $stop_on_pending_actions && self::pendingActionCount() > 0 ) {
					$stop_reason = 'pending_actions';
					break;
				}

				++$passes;

				if ( $recover_stuck && ! $recovery_ran ) {
					$recovery_ran = true;
					$recovery = ( new RecoverStuckJobsAbility() )->execute(
						array(
							'dry_run'       => false,
							'timeout_hours' => $stuck_timeout,
							'recovery_trigger' => 'automatic_worker',
							'limit'         => 5,
							'recover_pathless_children' => false,
						)
					);

					if ( empty( $recovery['success'] ) ) {
						++$warnings;
					} else {
						$recoveries += (int) ( $recovery['recovered'] ?? 0 );
						$timed_out  += (int) ( $recovery['timed_out'] ?? 0 );
						$reconciled += (int) ( $recovery['stale_actions'] ?? 0 );
						$pathless_requeued += (int) ( $recovery['pathless_requeued'] ?? 0 );
						$pathless_terminal += (int) ( $recovery['pathless_terminal'] ?? 0 );
						$recovery_claim_conflicts += (int) ( $recovery['claimed_elsewhere'] ?? 0 );
						$pathless_policy_skipped += (int) ( $recovery['pathless_policy_skipped'] ?? 0 );
						$recovery_mutations += (int) ( $recovery['mutations'] ?? 0 );
						$recovery_requeued += (int) ( $recovery['requeued'] ?? 0 );
						$recovery_skipped += (int) ( $recovery['skipped'] ?? 0 );
						$recovery_limit_reached += ! empty( $recovery['limit_reached'] ) ? 1 : 0;
					}
				}

				$remaining_time = $time_limit > 0 ? max( 1, $time_limit - $stop_before_timeout - ( time() - $started_at ) ) : $drain_time_limit;
				$drain          = DrainCommand::drain(
					array(
						'limit'        => $drain_limit,
						'batch_size'   => $batch_size,
						'time_limit'   => min( $drain_time_limit, $remaining_time ),
						'acquire_lock' => false,
						'lane'         => $lane,
					)
				);

				$completions += (int) ( $drain['completions'] ?? 0 );
				$failures    += (int) ( $drain['failures'] ?? 0 );
				$warnings    += (int) ( $drain['warnings'] ?? 0 );

				if ( $once ) {
					$stop_reason = 'once';
					break;
				}

				if ( (int) ( $drain['remaining_pending'] ?? 0 ) <= 0 ) {
					$stop_reason = 'idle';
					if ( $sleep <= 0 ) {
						break;
					}

					sleep( min( $sleep, $time_limit > 0 ? max( 0, $time_limit - $stop_before_timeout - ( time() - $started_at ) ) : $sleep ) );
				}
			}

			$status = self::statusSnapshot( $lane );

			return array(
				'passes'                   => $passes,
				'job_recoveries'           => $recoveries,
				'job_timeouts'             => $timed_out,
				'stale_actions_reconciled' => $reconciled,
				'pathless_children_requeued' => $pathless_requeued,
				'pathless_children_terminal' => $pathless_terminal,
				'recovery_claim_conflicts'   => $recovery_claim_conflicts,
				'pathless_policy_skipped'    => $pathless_policy_skipped,
				'recovery_mutations'         => $recovery_mutations,
				'recovery_requeued'           => $recovery_requeued,
				'recovery_skipped'            => $recovery_skipped,
				'recovery_limit_reached'     => $recovery_limit_reached,
				'action_completions'       => $completions,
				'action_failures'          => $failures,
				'pending_actions'          => (int) $status['pending_actions'],
				'due_actions'              => (int) $status['due_actions'],
				'total_pending_actions'    => (int) $status['total_pending_actions'],
				'processing_jobs'          => (int) $status['processing_jobs'],
				'pending_jobs'             => (int) $status['pending_jobs'],
				'stuck_jobs'               => (int) $status['stuck_jobs'],
				'warnings'                 => $warnings,
				'duration_seconds'         => time() - $started_at,
				'stop_reason'              => $stop_reason,
				'lane'                     => $lane,
			) + self::publicLockStatus( $lock );
		} finally {
			WorkerLock::release( (string) ( $lock['lock_token'] ?? '' ), $lane );
		}
	}

	/**
	 * Execute a job-claiming worker loop.
	 *
	 * @param array<string,mixed> $options Worker options.
	 * @return array<string,int|string> Worker stats.
	 */
	private static function runJobLoop( array $options ): array {
		$started_at              = time();
		$time_limit              = max( 0, (int) ( $options['time_limit'] ?? 300 ) );
		$stuck_timeout           = max( 1, (int) ( $options['stuck_timeout'] ?? 2 ) );
		$recover_stuck           = (bool) ( $options['recover_stuck'] ?? true );
		$stop_on_pending_actions = (bool) ( $options['stop_on_pending_actions'] ?? false );
		$max_passes              = max( 0, (int) ( $options['max_passes'] ?? 0 ) );
		$stop_before_timeout     = max( 0, (int) ( $options['stop_before_timeout'] ?? 30 ) );
		$once                    = (bool) ( $options['once'] ?? false );
		$job_step_budget         = max( 1, (int) ( $options['job_step_budget'] ?? 50 ) );
		$drain_time_limit        = max( 1, (int) ( $options['drain_time_limit'] ?? 120 ) );
		$drain_job               = new DrainJobAbility();

		$passes      = 0;
		$recoveries  = 0;
		$timed_out   = 0;
		$reconciled  = 0;
		$pathless_requeued = 0;
		$pathless_terminal = 0;
		$recovery_claim_conflicts = 0;
		$pathless_policy_skipped = 0;
		$recovery_mutations = 0;
		$recovery_requeued = 0;
		$recovery_skipped = 0;
		$recovery_limit_reached = 0;
		$recovery_ran = false;
		$job_claims  = 0;
		$bootstraps  = 0;
		$completed   = 0;
		$actions     = 0;
		$failures    = 0;
		$warnings    = 0;
		$stop_reason = 'time_limit';

		while ( true ) {
			$elapsed = time() - $started_at;
			if ( $time_limit > 0 && $elapsed >= $time_limit ) {
				break;
			}
			if ( $time_limit > 0 && ( $time_limit - $elapsed ) <= $stop_before_timeout ) {
				$stop_reason = 'timeout_margin';
				break;
			}
			if ( $max_passes > 0 && $passes >= $max_passes ) {
				$stop_reason = 'max_passes';
				break;
			}

			if ( $stop_on_pending_actions && self::pendingActionCount() > 0 ) {
				$stop_reason = 'pending_actions';
				break;
			}

			++$passes;

			if ( $recover_stuck && ! $recovery_ran ) {
				$recovery_ran = true;
				$recovery = ( new RecoverStuckJobsAbility() )->execute(
					array(
						'dry_run'       => false,
						'timeout_hours' => $stuck_timeout,
						'recovery_trigger' => 'automatic_worker',
						'limit'         => 5,
						'recover_pathless_children' => false,
					)
				);

				if ( empty( $recovery['success'] ) ) {
					++$warnings;
				} else {
					$recoveries += (int) ( $recovery['recovered'] ?? 0 );
					$timed_out  += (int) ( $recovery['timed_out'] ?? 0 );
					$reconciled += (int) ( $recovery['stale_actions'] ?? 0 );
					$pathless_requeued += (int) ( $recovery['pathless_requeued'] ?? 0 );
					$pathless_terminal += (int) ( $recovery['pathless_terminal'] ?? 0 );
					$recovery_claim_conflicts += (int) ( $recovery['claimed_elsewhere'] ?? 0 );
					$pathless_policy_skipped += (int) ( $recovery['pathless_policy_skipped'] ?? 0 );
					$recovery_mutations += (int) ( $recovery['mutations'] ?? 0 );
					$recovery_requeued += (int) ( $recovery['requeued'] ?? 0 );
					$recovery_skipped += (int) ( $recovery['skipped'] ?? 0 );
					$recovery_limit_reached += ! empty( $recovery['limit_reached'] ) ? 1 : 0;
				}
			}

			$claim = self::claimNextJob( $time_limit > 0 ? $time_limit + max( 60, $stop_before_timeout ) : 600 );
			if ( null === $claim ) {
				$remaining_seconds = $time_limit > 0 ? max( 1, $time_limit - $stop_before_timeout - ( time() - $started_at ) ) : $drain_time_limit;
				$bootstrap         = self::drainBootstrapActions( min( $drain_time_limit, $remaining_seconds ) );
				$bootstraps       += (int) ( $bootstrap['completions'] ?? 0 );
				$failures         += (int) ( $bootstrap['failures'] ?? 0 );
				$warnings         += (int) ( $bootstrap['warnings'] ?? 0 );

				if ( (int) ( $bootstrap['completions'] ?? 0 ) > 0 ) {
					if ( $once ) {
						$stop_reason = 'once';
						break;
					}

					continue;
				}

				$stop_reason = 'idle';
				break;
			}

			++$job_claims;
			try {
				$remaining_seconds = $time_limit > 0 ? max( 1, $time_limit - $stop_before_timeout - ( time() - $started_at ) ) : $drain_time_limit;
				$result            = $drain_job->execute(
					array(
						'job_id'         => $claim['job_id'],
						'step_budget'    => $job_step_budget,
						'time_budget_ms' => min( $drain_time_limit, $remaining_seconds ) * 1000,
					)
				);

				$actions += (int) ( $result['actions_drained'] ?? 0 );
				if ( ! empty( $result['success'] ) ) {
					++$completed;
				} elseif ( ! empty( $result['error'] ) ) {
					++$failures;
				}
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
				++$failures;
			} finally {
				WorkerLock::release( $claim['token'], $claim['lock_lane'] );
			}

			if ( $once ) {
				$stop_reason = 'once';
				break;
			}
		}

		$status = self::statusSnapshot();

		return array(
			'passes'                   => $passes,
			'job_recoveries'           => $recoveries,
			'job_timeouts'             => $timed_out,
			'stale_actions_reconciled' => $reconciled,
			'pathless_children_requeued' => $pathless_requeued,
			'pathless_children_terminal' => $pathless_terminal,
			'recovery_claim_conflicts'   => $recovery_claim_conflicts,
			'pathless_policy_skipped'    => $pathless_policy_skipped,
			'recovery_mutations'         => $recovery_mutations,
			'recovery_requeued'           => $recovery_requeued,
			'recovery_skipped'            => $recovery_skipped,
			'recovery_limit_reached'     => $recovery_limit_reached,
			'job_claims'               => $job_claims,
			'job_completions'          => $completed,
			'bootstrap_actions'        => $bootstraps,
			'action_completions'       => $actions,
			'action_failures'          => $failures,
			'pending_actions'          => (int) $status['pending_actions'],
			'due_actions'              => (int) $status['due_actions'],
			'total_pending_actions'    => (int) $status['total_pending_actions'],
			'processing_jobs'          => (int) $status['processing_jobs'],
			'pending_jobs'             => (int) $status['pending_jobs'],
			'stuck_jobs'               => (int) $status['stuck_jobs'],
			'warnings'                 => $warnings,
			'duration_seconds'         => time() - $started_at,
			'stop_reason'              => $stop_reason,
			'mode'                     => 'job',
		);
	}

	/**
	 * Drain a small amount of non-job scheduler work that creates future jobs.
	 *
	 * Job mode should not become a second shared queue worker, but it must keep
	 * recurring flow/refill/bootstrap hooks moving after the current job backlog
	 * reaches zero. Action Scheduler owns the concrete action claims, so this can
	 * run without the legacy global Data Machine worker lock.
	 *
	 * @return array<string,int|string>
	 */
	private static function drainBootstrapActions( int $time_limit ): array {
		return DrainCommand::drain(
			array(
				'limit'        => 3,
				'batch_size'   => 1,
				'time_limit'   => max( 1, $time_limit ),
				'acquire_lock' => false,
				'hooks'        => self::bootstrapHooks(),
			)
		);
	}

	/**
	 * Hooks that seed, schedule, or maintain Data Machine jobs but are not a job.
	 *
	 * @return string[]
	 */
	private static function bootstrapHooks(): array {
		return array(
			'datamachine_run_flow_now',
			'datamachine_recurring_wiki_brain_refill',
			'datamachine_recurring_wiki_generated_page_decision',
			'datamachine_recurring_wiki_graph_maintain',
			'datamachine_recurring_wiki_timeline_materialize',
			'datamachine_recurring_wiki_timeline_materialize_wordpress_com',
			'datamachine_recurring_retention_as_actions',
			'datamachine_recurring_retention_chat_sessions',
			'datamachine_recurring_retention_completed_jobs',
			'datamachine_recurring_retention_failed_jobs',
			'datamachine_recurring_retention_files',
			'datamachine_recurring_retention_logs',
			'datamachine_recurring_retention_processed_items',
			'datamachine_recurring_retention_stale_claims',
			'datamachine_recurring_workspace_disk_emergency_cleanup',
			'datamachine_recurring_workspace_retention_cleanup',
		);
	}

	/**
	 * Build a lightweight worker status snapshot.
	 *
	 * @return array<string,int|string>
	 */
	private static function statusSnapshot( string $lane = '' ): array {
		$lane            = self::normalizeLane( $lane );
		$pending_summary = PendingActionStore::summary( array( 'status' => 'pending' ) );
		$drain_status    = DrainCommand::status( array( 'lane' => $lane ) );
		$jobs_summary    = ( new JobsSummaryAbility() )->execute( array( 'compact' => true ) );
		$jobs            = ! empty( $jobs_summary['success'] ) && is_array( $jobs_summary['summary'] ?? null ) ? $jobs_summary['summary'] : array();
		$stuck_jobs      = RecoverStuckJobsAbility::countStuckCandidates();
		$lock            = WorkerLock::snapshot( null, 600, $lane );

		return array(
			'pending_actions'       => (int) ( $pending_summary['total'] ?? 0 ),
			'pending_kinds'         => implode( ',', array_keys( (array) ( $pending_summary['by_kind'] ?? array() ) ) ),
			'due_actions'           => (int) ( $drain_status['due_pending'] ?? 0 ),
			'total_pending_actions' => (int) ( $drain_status['total_pending'] ?? 0 ),
			'action_hooks'          => (string) ( $drain_status['hooks'] ?? '' ),
			'processing_jobs'       => self::jobStatusCount( $jobs, 'processing' ),
			'pending_jobs'          => self::jobStatusCount( $jobs, 'pending' ),
			'failed_jobs'           => (int) ( $jobs['failed_count'] ?? self::jobStatusCount( $jobs, 'failed' ) ),
			'stuck_jobs'            => $stuck_jobs,
			'lane'                  => $lane,
		) + self::publicLockStatus( $lock );
	}

	/**
	 * Read one normalized status bucket from a jobs summary result.
	 *
	 * @param array<string,mixed> $jobs   Jobs summary payload.
	 * @param string              $status Normalized status bucket.
	 * @return int Bucket count.
	 */
	private static function jobStatusCount( array $jobs, string $status ): int {
		foreach ( (array) ( $jobs['status'] ?? array() ) as $row ) {
			if ( (string) ( $row['status'] ?? '' ) === $status ) {
				return (int) ( $row['count'] ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * Build a lock-skipped worker result.
	 *
	 * @param array<string,int|string|bool> $lock Lock state.
	 * @return array<string,int|string> Worker stats.
	 */
	private static function lockedStats( array $lock, string $lane = '' ): array {
		$status = self::statusSnapshot( $lane );

		return array(
			'passes'                   => 0,
			'job_recoveries'           => 0,
			'job_timeouts'             => 0,
			'stale_actions_reconciled' => 0,
			'action_completions'       => 0,
			'action_failures'          => 0,
			'pending_actions'          => (int) $status['pending_actions'],
			'due_actions'              => (int) $status['due_actions'],
			'total_pending_actions'    => (int) $status['total_pending_actions'],
			'processing_jobs'          => (int) $status['processing_jobs'],
			'pending_jobs'             => (int) $status['pending_jobs'],
			'stuck_jobs'               => (int) $status['stuck_jobs'],
			'warnings'                 => 0,
			'duration_seconds'         => 0,
			'stop_reason'              => 'locked',
			'lane'                     => $lane,
		) + self::publicLockStatus( $lock );
	}

	/**
	 * Strip private lock fields before CLI output.
	 *
	 * @param array<string,int|string|bool> $lock Lock state.
	 * @return array<string,int|string> Public lock state.
	 */
	private static function publicLockStatus( array $lock ): array {
		return array(
			'lock_status'      => (string) ( $lock['lock_status'] ?? 'unlocked' ),
			'lock_owner'       => (string) ( $lock['lock_owner'] ?? '' ),
			'lock_age_seconds' => (int) ( $lock['lock_age_seconds'] ?? 0 ),
			'lock_expires_at'  => (int) ( $lock['lock_expires_at'] ?? 0 ),
			'lock_lane'        => (string) ( $lock['lock_lane'] ?? '' ),
		);
	}

	/**
	 * Build a compact default owner string for lock diagnostics.
	 */
	private static function defaultLockOwner( string $lane = '' ): string {
		$pid  = getmypid();
		$kind = '' === $lane ? 'worker' : 'worker:' . $lane;

		return sprintf( '%s pid:%d host:%s', $kind, false === $pid ? 0 : $pid, php_uname( 'n' ) );
	}

	/**
	 * Normalize a worker lane identifier.
	 */
	private static function normalizeLane( mixed $lane ): string {
		$lane = is_string( $lane ) ? strtolower( trim( $lane ) ) : '';
		return in_array( $lane, array( 'publish', 'background' ), true ) ? $lane : '';
	}

	/**
	 * Normalize worker execution mode.
	 */
	private static function normalizeMode( mixed $mode ): string {
		$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : 'queue';
		return 'job' === $mode ? 'job' : 'queue';
	}

	/**
	 * Claim the next due Data Machine job by taking a per-job worker lock.
	 *
	 * @return array{job_id:int,token:string,lock_lane:string}|null Claimed job state.
	 */
	private static function claimNextJob( int $ttl ): ?array {
		foreach ( self::dueJobIds() as $job_id ) {
			$lock_lane = 'job-' . $job_id;
			$lock      = WorkerLock::acquire( self::defaultJobLockOwner( $job_id ), $ttl, $lock_lane );
			if ( empty( $lock['acquired'] ) ) {
				continue;
			}

			return array(
				'job_id'    => $job_id,
				'token'     => (string) ( $lock['lock_token'] ?? '' ),
				'lock_lane' => $lock_lane,
			);
		}

		return null;
	}

	/**
	 * Return job IDs that currently have due, job-scoped Data Machine actions.
	 *
	 * @return int[] Job IDs ordered by oldest due scheduler action.
	 */
	private static function dueJobIds(): array {
		global $wpdb;

		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$groups_table  = $wpdb->prefix . 'actionscheduler_groups';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Worker job selection must inspect fresh scheduler rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.action_id, a.args
				FROM %i a
				INNER JOIN %i g ON g.group_id = a.group_id
				WHERE a.hook IN (%s, %s)
				AND a.status = \'pending\'
				AND g.slug = %s
				AND a.scheduled_date_gmt <= %s
				AND (a.args LIKE %s OR a.args LIKE %s)
				ORDER BY a.scheduled_date_gmt ASC, a.action_id ASC
				LIMIT 200',
				$actions_table,
				$groups_table,
				DrainCommand::HOOK_EXECUTE_STEP,
				DrainCommand::HOOK_BATCH_CHUNK,
				'data-machine',
				gmdate( 'Y-m-d H:i:s' ),
				'%"job_id"%',
				'%"parent_job_id"%'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$job_ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$job_id = self::extractActionJobId( (string) ( $row['args'] ?? '' ) );
			if ( $job_id > 0 ) {
				$job_ids[] = $job_id;
			}
		}

		return array_values( array_unique( $job_ids ) );
	}

	/**
	 * Extract a job identifier from Action Scheduler args.
	 */
	private static function extractActionJobId( string $args_json ): int {
		$args = json_decode( $args_json, true );
		if ( ! is_array( $args ) ) {
			return 0;
		}

		if ( isset( $args['job_id'] ) ) {
			return absint( $args['job_id'] );
		}

		if ( isset( $args['parent_job_id'] ) ) {
			return absint( $args['parent_job_id'] );
		}

		foreach ( $args as $value ) {
			if ( is_array( $value ) && isset( $value['job_id'] ) ) {
				return absint( $value['job_id'] );
			}

			if ( is_array( $value ) && isset( $value['parent_job_id'] ) ) {
				return absint( $value['parent_job_id'] );
			}
		}

		return 0;
	}

	/**
	 * Build a compact default owner string for per-job locks.
	 */
	private static function defaultJobLockOwner( int $job_id ): string {
		$pid = getmypid();
		return sprintf( 'worker:job:%d pid:%d host:%s', $job_id, false === $pid ? 0 : $pid, php_uname( 'n' ) );
	}

	/**
	 * Count pending approval actions.
	 */
	private static function pendingActionCount(): int {
		$status = self::statusSnapshot();
		return (int) $status['pending_actions'];
	}
}
