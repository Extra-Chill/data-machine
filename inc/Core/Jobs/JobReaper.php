<?php
/**
 * Recurring job reaper: the one caller that runs stuck-job recovery on a schedule.
 *
 * Recovery previously ran only on operator command or worker start, so phantom
 * `pending`/`processing` rows accumulated between manual passes and counted
 * against the flow-start admission ceiling. The reaper runs the existing
 * `datamachine/recover-stuck-jobs` ability (pending + processing scope) on a
 * recurring system task and records what it found.
 *
 * Modes (setting `job_reaper_mode`):
 * - `dry_run` (default): diagnose and record verdict counts. Never mutates.
 * - `apply`: terminalize / resume exactly as
 *   `wp datamachine jobs recover-stuck --recover-pending-orphans` does, through
 *   the same Jobs compare-and-set transitions, bounded per run.
 * - `off`: do nothing.
 *
 * Every run's outcome goes to {@see JobReaperHistory}, including the
 * false-positive signal: jobs flagged on run N that were alive or had
 * progressed by run N+1. That is the data #3481 asked for before apply is
 * enabled.
 *
 * @package DataMachine\Core\Jobs
 * @since 0.180.0
 */

namespace DataMachine\Core\Jobs;

use DataMachine\Abilities\Job\RecoverStuckJobsAbility;
use DataMachine\Abilities\PermissionHelper;
use DataMachine\Core\AbilityResult;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\JobStatus;
use DataMachine\Core\PendingJobRecoveryPolicy;
use DataMachine\Core\PluginSettings;
use DataMachine\Engine\AI\System\Tasks\JobReaperTask;

defined( 'ABSPATH' ) || exit;

final class JobReaper {

	public const MODE_DRY_RUN = 'dry_run';
	public const MODE_APPLY   = 'apply';
	public const MODE_OFF     = 'off';

	/** PluginSettings key holding the mode. */
	public const SETTING_MODE = 'job_reaper_mode';

	/** Ability the reaper runs; the reaper adds scheduling and measurement, not recovery logic. */
	public const ABILITY = 'datamachine/recover-stuck-jobs';

	/** Recovery initiator recorded in machine-readable evidence on recovered jobs. */
	/** Ability input key that switches recover-stuck between preview and apply. */
	public const INPUT_DRY_RUN = 'dry_run';

	public const RECOVERY_TRIGGER = JobReaperTask::TASK_TYPE;

	/** Per-run logical-touch cap passed to the ability (apply mode). */
	public const RUN_TOUCH_LIMIT = 25;

	/** Per-run bound on pending orphans previewed or terminalized. */
	public const RUN_PENDING_LIMIT = 100;

	/** Counter keys in the recover-stuck ability result that map onto reaper verdicts. */
	private const COUNTER_AI_DEFERRAL   = 'pending_ai_terminalized';
	private const COUNTER_STATUS_FINAL  = 'recovered';
	private const COUNTER_BATCH_PARENTS = 'batch_parents_completed';
	private const COUNTER_TIMED_OUT     = RecoverStuckJobsAbility::RESULT_TIMED_OUT;
	private const COUNTER_REQUEUED      = 'requeued';
	private const COUNTER_PATHLESS      = 'pathless_terminal';
	private const COUNTER_STALE_ACTIONS = 'stale_actions';

	/** Heartbeat threshold used when re-checking flagged jobs for liveness. */
	private const LIVENESS_OVERDUE_MINUTES = 120;

	/** Outcomes of re-checking one previously flagged job. */
	public const OUTCOME_ALIVE      = 'alive';
	public const OUTCOME_PROGRESSED = 'progressed';
	public const OUTCOME_STILL      = 'still_flagged';
	public const OUTCOME_RESOLVED   = 'resolved';
	public const OUTCOME_GONE       = 'gone';
	public const OUTCOME_UNKNOWN    = 'unknown';

	/**
	 * Valid modes.
	 *
	 * @return list<string>
	 */
	public static function modes(): array {
		return array( self::MODE_DRY_RUN, self::MODE_APPLY, self::MODE_OFF );
	}

	/**
	 * Resolve the configured mode.
	 *
	 * An unrecognized value falls back to dry-run: a typo must never mean apply.
	 */
	public static function resolveMode(): string {
		$mode = PluginSettings::get( self::SETTING_MODE, self::MODE_DRY_RUN );
		return is_string( $mode ) && in_array( $mode, self::modes(), true ) ? $mode : self::MODE_DRY_RUN;
	}

	/**
	 * Run one reaper pass and record its outcome.
	 *
	 * @param string|null   $mode     Mode override; defaults to the configured mode.
	 * @param callable|null $executor `fn( array $input ): array|\WP_Error`, the recover-stuck ability seam.
	 * @param callable|null $probe    `fn( int $job_id ): ?array{status:string,alive:?bool}`, the re-check seam.
	 * @return array<string,mixed> The recorded run, or `{ mode: off, ran: false }` when off.
	 */
	public static function run( ?string $mode = null, ?callable $executor = null, ?callable $probe = null ): array {
		$mode = null !== $mode && in_array( $mode, self::modes(), true ) ? $mode : self::resolveMode();
		if ( self::MODE_OFF === $mode ) {
			return array(
				'mode' => self::MODE_OFF,
				'ran'  => false,
			);
		}

		$dry_run = self::MODE_APPLY !== $mode;

		// Re-check what the previous run flagged BEFORE this run acts, so an
		// apply pass cannot erase the evidence of its predecessor's misjudgment.
		$flagged        = JobReaperHistory::previousFlagged();
		$previous_check = array() === $flagged ? null : self::checkFlagged( $flagged, $probe ?? self::defaultProbe() );

		$input  = array(
			self::INPUT_DRY_RUN         => $dry_run,
			'recover_pending_orphans'   => ! $dry_run,
			'recover_pathless_children' => false,
			'limit'                     => self::RUN_TOUCH_LIMIT,
			'pending_limit'             => self::RUN_PENDING_LIMIT,
			'recovery_trigger'          => self::RECOVERY_TRIGGER,
		);
		$result = AbilityResult::normalize( ( $executor ?? self::defaultExecutor() )( $input ) );

		$run = array(
			'ran_at'  => gmdate( 'c' ),
			'mode'    => $mode,
			'success' => ! empty( $result['success'] ),
		) + self::summarize( $result, $dry_run );
		if ( ! $run['success'] ) {
			$run['error'] = (string) ( $result['error'] ?? 'recover-stuck ability failed' );
		}
		if ( null !== $previous_check ) {
			$run['previous_check'] = $previous_check;
		}

		JobReaperHistory::record( $run );

		if ( $run['would_act'] > 0 || $run['acted'] > 0 || ! $run['success'] ) {
			do_action(
				'datamachine_log',
				$run['success'] ? 'info' : 'warning',
				$dry_run ? 'Job reaper dry run' : 'Job reaper pass',
				array(
					'mode'           => $mode,
					'verdicts'       => $run['verdicts'],
					'would_act'      => $run['would_act'],
					'acted'          => $run['acted'],
					'guarded'        => $run['guarded'],
					'previous_check' => $previous_check,
					'error'          => $run['error'] ?? '',
					'context'        => 'system',
				)
			);
		}

		return $run;
	}

	/**
	 * Operator-facing report over the recorded history (newest run first).
	 *
	 * `false_positive_rate` is the lifetime share of re-checked flagged jobs that
	 * were alive or had progressed by the following run; null until a flagged job
	 * has been re-checked. Per-run `false_positives` is the same count for that
	 * run's re-check of its predecessor's flags.
	 *
	 * @param int $limit Maximum runs to include.
	 * @return array{mode:string,totals:array<string,mixed>,false_positive_rate:float|null,runs:list<array<string,mixed>>}
	 */
	public static function statusReport( int $limit = 10 ): array {
		$totals = JobReaperHistory::load()['totals'];
		$rows   = array();
		foreach ( JobReaperHistory::recent( $limit ) as $run ) {
			$check    = is_array( $run['previous_check'] ?? null ) ? $run['previous_check'] : array();
			$verdicts = array();
			foreach ( (array) ( $run['verdicts'] ?? array() ) as $verdict => $count ) {
				if ( (int) $count > 0 ) {
					$verdicts[] = sprintf( '%s=%d', $verdict, (int) $count );
				}
			}

			$rows[] = array(
				'ran_at'          => (string) ( $run['ran_at'] ?? '' ),
				'mode'            => (string) ( $run['mode'] ?? '' ),
				'would_act'       => (int) ( $run['would_act'] ?? 0 ),
				'acted'           => (int) ( $run['acted'] ?? 0 ),
				'guarded'         => (int) ( $run['guarded'] ?? 0 ),
				'verdicts'        => implode( ' ', $verdicts ),
				'rechecked'       => (int) ( $check['checked'] ?? 0 ),
				'false_positives' => (int) ( $check['alive'] ?? 0 ) + (int) ( $check['progressed'] ?? 0 ),
				'still_flagged'   => (int) ( $check['still_flagged'] ?? 0 ),
				'limit_reached'   => ! empty( $run['limit_reached'] ) ? 'yes' : 'no',
				'evidence'        => false === ( $run['evidence_complete'] ?? true ) ? 'incomplete' : 'complete',
				'error'           => (string) ( $run['error'] ?? '' ),
			);
		}

		return array(
			'mode'                => self::resolveMode(),
			'totals'              => $totals,
			'false_positive_rate' => JobReaperHistory::falsePositiveRate( $totals ),
			'runs'                => $rows,
		);
	}

	/**
	 * Reduce a recover-stuck result to the per-run record.
	 *
	 * Verdict counts come from the ability's aggregate counters (complete), never
	 * from its job detail rows (capped). Detail rows only supply the flagged job
	 * ids for the next run's false-positive check.
	 *
	 * @param array<string,mixed> $result  Normalized recover-stuck result.
	 * @param bool                $dry_run Whether the pass was a preview.
	 * @return array{verdicts:array<string,int>,would_act:int,acted:int,guarded:int,evidence_complete:bool,limit_reached:bool,flagged_count:int,flagged:list<array{job_id:int,status:string,verdict:string}>}
	 */
	public static function summarize( array $result, bool $dry_run ): array {
		$pending  = is_array( $result['pending_orphans'] ?? null ) ? $result['pending_orphans'] : array();
		$verdicts = array_fill_keys( PendingJobRecoveryPolicy::verdicts(), 0 );
		foreach ( (array) ( $pending['verdicts'] ?? array() ) as $verdict => $count ) {
			$verdicts[ (string) $verdict ] = (int) $count;
		}

		// `requeued` already includes pathless requeues; do not add them again.
		$verdicts['expired_ai_deferral']   = (int) ( $result[ self::COUNTER_AI_DEFERRAL ] ?? 0 );
		$verdicts['job_status_override']   = (int) ( $result[ self::COUNTER_STATUS_FINAL ] ?? 0 );
		$verdicts['batch_parent_complete'] = (int) ( $result[ self::COUNTER_BATCH_PARENTS ] ?? 0 );
		$verdicts['processing_timeout']    = (int) ( $result[ self::COUNTER_TIMED_OUT ] ?? 0 );
		$verdicts['requeued']              = (int) ( $result[ self::COUNTER_REQUEUED ] ?? 0 );
		$verdicts['pathless_child']        = (int) ( $result[ self::COUNTER_PATHLESS ] ?? 0 );
		$verdicts['stale_action']          = (int) ( $result[ self::COUNTER_STALE_ACTIONS ] ?? 0 );

		// Only non-zero verdicts are stored: the record is written every 15 minutes.
		$verdicts = array_filter( $verdicts );

		$flagged = $dry_run ? self::flaggedJobs( is_array( $result['jobs'] ?? null ) ? $result['jobs'] : array() ) : array();

		return array(
			'verdicts'          => $verdicts,
			'would_act'         => array_sum( $verdicts ),
			'acted'             => $dry_run ? 0 : (int) ( $result['mutations'] ?? 0 ),
			'guarded'           => (int) ( $result['skipped'] ?? 0 ),
			'evidence_complete' => ! array_key_exists( 'evidence_complete', $pending ) || (bool) $pending['evidence_complete'],
			'limit_reached'     => ! empty( $result['limit_reached'] ),
			'flagged_count'     => count( $flagged ),
			'flagged'           => array_slice( $flagged, 0, JobReaperHistory::MAX_FLAGGED ),
		);
	}

	/**
	 * Jobs a dry run would have acted on, for the next run's re-check.
	 *
	 * Terminal-backed action reconciliation is excluded: its job is already
	 * terminal, so there is no liveness judgment to second-guess.
	 *
	 * @param array<int,array<string,mixed>> $details Ability job detail rows.
	 * @return list<array{job_id:int,status:string,verdict:string}>
	 */
	private static function flaggedJobs( array $details ): array {
		$flagged = array();
		foreach ( $details as $detail ) {
			$status = (string) ( $detail['status'] ?? '' );
			$job_id = (int) ( $detail['job_id'] ?? 0 );
			if ( $job_id <= 0 || ! str_starts_with( $status, 'would_' ) || 'would_reconcile_action' === $status ) {
				continue;
			}

			$scope     = (string) ( $detail['scope'] ?? '' );
			$flagged[] = array(
				'job_id'  => $job_id,
				'status'  => in_array( $scope, array( 'pending_orphan', 'pending_ai_deferral' ), true ) ? JobStatus::PENDING : JobStatus::PROCESSING,
				'verdict' => (string) ( $detail['verdict'] ?? substr( $status, strlen( 'would_' ) ) ),
			);
			if ( count( $flagged ) >= JobReaperHistory::MAX_FLAGGED ) {
				break;
			}
		}

		return $flagged;
	}

	/**
	 * Re-check jobs the previous run flagged.
	 *
	 * Outcomes per job:
	 * - `alive`: same status, but a live path can now advance it.
	 * - `progressed`: it left the flagged status on its own (pending → processing,
	 *   or completed) since the flag.
	 * - `still_flagged`: same status and still no live path (a true positive).
	 * - `resolved`: it went terminal through failure or cancellation (an operator
	 *   or the reaper acted), which says nothing about the verdict.
	 * - `gone`: the row was deleted.
	 * - `unknown`: scheduler evidence was incomplete, so liveness cannot be judged.
	 *
	 * `checked` counts only the three outcomes that judge the verdict
	 * (`alive` + `progressed` + `still_flagged`); `alive` + `progressed` are the
	 * false positives.
	 *
	 * @param list<array{job_id:int,status:string,verdict:string}> $flagged Previous run's flagged jobs.
	 * @param callable                                             $probe   `fn( int $job_id ): ?array{status:string,alive:?bool}`; `alive` is null when it cannot be judged.
	 * @return array{checked:int,alive:int,progressed:int,still_flagged:int,resolved:int,gone:int,unknown:int}
	 */
	public static function checkFlagged( array $flagged, callable $probe ): array {
		$check = array(
			'checked'                => 0,
			self::OUTCOME_ALIVE      => 0,
			self::OUTCOME_PROGRESSED => 0,
			self::OUTCOME_STILL      => 0,
			self::OUTCOME_RESOLVED   => 0,
			self::OUTCOME_GONE       => 0,
			self::OUTCOME_UNKNOWN    => 0,
		);

		foreach ( $flagged as $entry ) {
			$observed = $probe( $entry['job_id'] );
			if ( ! is_array( $observed ) ) {
				++$check[ self::OUTCOME_GONE ];
				continue;
			}

			$status = (string) ( $observed['status'] ?? '' );
			if ( $status === $entry['status'] ) {
				$outcome = null === ( $observed['alive'] ?? null )
					? self::OUTCOME_UNKNOWN
					: ( $observed['alive'] ? self::OUTCOME_ALIVE : self::OUTCOME_STILL );
			} elseif ( JobStatus::PROCESSING === $status || JobStatus::isStatusSuccess( $status ) ) {
				$outcome = self::OUTCOME_PROGRESSED;
			} else {
				$outcome = self::OUTCOME_RESOLVED;
			}

			++$check[ $outcome ];
			if ( in_array( $outcome, array( self::OUTCOME_ALIVE, self::OUTCOME_PROGRESSED, self::OUTCOME_STILL ), true ) ) {
				++$check['checked'];
			}
		}

		return $check;
	}

	/**
	 * Default ability seam: run recover-stuck as the system, like a recurring task.
	 *
	 * @return callable `fn( array $input ): array|\WP_Error`
	 */
	private static function defaultExecutor(): callable {
		return static function ( array $input ) {
			$ability = wp_get_ability( self::ABILITY );
			if ( ! $ability ) {
				return new \WP_Error( 'job_reaper_ability_missing', 'The recover-stuck-jobs ability is not registered.' );
			}

			return PermissionHelper::run_as_system( static fn() => $ability->execute( $input ) );
		};
	}

	/**
	 * Default re-check seam: current row status plus the single liveness predicate.
	 *
	 * Loads scheduler evidence once (one batch query) on first use.
	 *
	 * @return callable `fn( int $job_id ): ?array{status:string,alive:?bool}`
	 */
	private static function defaultProbe(): callable {
		$jobs     = new Jobs();
		$evidence = null;

		return static function ( int $job_id ) use ( $jobs, &$evidence ): ?array {
			$job = $jobs->get_job( $job_id );
			if ( ! is_array( $job ) ) {
				return null;
			}

			$status = (string) ( $job['status'] ?? '' );
			if ( JobStatus::isStatusFinal( $status ) ) {
				return array(
					'status' => $status,
					'alive'  => false,
				);
			}

			$evidence ??= SchedulerEvidence::load();
			if ( ! $evidence->isComplete() ) {
				// Incomplete evidence reads as alive by construction; that must not
				// be scored as a false positive.
				return array(
					'status' => $status,
					'alive'  => null,
				);
			}

			$engine = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
			$counts = ! empty( $engine['batch'] ) ? JobLiveness::childCounts( $job_id, self::LIVENESS_OVERDUE_MINUTES, $evidence ) : array();

			return array(
				'status' => $status,
				'alive'  => JobLiveness::alive( $job, $evidence, $counts, self::LIVENESS_OVERDUE_MINUTES, time() ),
			);
		};
	}
}
