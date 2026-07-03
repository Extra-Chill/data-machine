<?php
/**
 * Run Flow Ability
 *
 * Executes a flow immediately. Loads flow/pipeline configurations,
 * creates a job record if needed, builds the engine snapshot, and
 * schedules the first step.
 *
 * Backs the datamachine_run_flow_now action hook.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.30.0
 */

namespace DataMachine\Abilities\Engine;

use DataMachine\Abilities\Flow\QueueAbility;
use DataMachine\Core\Agents\AgentIdentityResolver;
use DataMachine\Core\JobStatus;
use DataMachine\Engine\ExecutionPlan;

defined( 'ABSPATH' ) || exit;

class RunFlowAbility {

	use EngineHelpers;

	/**
	 * Default ceiling on in-flight (pending + processing) jobs before new
	 * scheduler-triggered runs are deferred. Tunable via the queue_tuning
	 * setting `max_active_jobs` or the `datamachine_max_active_jobs` filter.
	 */
	public const DEFAULT_MAX_ACTIVE_JOBS = 500;

	/**
	 * Default backoff (seconds) before a backpressured flow is retried.
	 * Tunable via the `datamachine_backpressure_defer_seconds` filter.
	 */
	public const DEFAULT_BACKPRESSURE_DEFER_SECONDS = 60;

	public function __construct() {
		$this->initDatabases();

		$this->registerAbility();
	}

	/**
	 * Register the datamachine/run-flow ability.
	 */
	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine/run-flow',
				array(
					'label'               => __( 'Run Flow', 'data-machine' ),
					'description'         => __( 'Execute a flow immediately. Loads configs, creates job if needed, schedules first step.', 'data-machine' ),
					'category'            => 'datamachine-jobs',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'flow_id' ),
						'properties' => array(
							'flow_id'        => array(
								'type'        => 'integer',
								'description' => __( 'Flow ID to execute.', 'data-machine' ),
							),
							'job_id'         => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'Pre-created job ID (optional, for API-triggered executions).', 'data-machine' ),
							),
							'initial_data'   => array(
								'type'        => 'object',
								'description' => __( 'Optional initial engine data to merge (e.g. webhook payloads, API context).', 'data-machine' ),
							),
							'respect_paused' => array(
								'type'        => 'boolean',
								'description' => __( 'Internal scheduler safety flag. When true, paused flows are skipped.', 'data-machine' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'    => array( 'type' => 'boolean' ),
							'flow_id'    => array( 'type' => 'integer' ),
							'job_id'     => array( 'type' => array( 'integer', 'null' ) ),
							'first_step' => array( 'type' => 'string' ),
							'skipped'    => array( 'type' => 'boolean' ),
							'reason'     => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'meta'                => array(
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => false,
						),
					),
				)
			);
		};

		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( $register_callback );
	}

	/**
	 * Execute the run-flow ability.
	 *
	 * @param array $input Input with flow_id, optional job_id, initial_data, and respect_paused.
	 * @return array Result with success status and execution details.
	 */
	public function execute( array $input ): array {
		$flow_id = (int) ( $input['flow_id'] ?? 0 );
		$job_id  = $input['job_id'] ?? null;

		$flow = $this->db_flows->get_flow( $flow_id );
		if ( ! $flow ) {
			do_action( 'datamachine_log', 'error', 'Flow execution failed - flow not found', array( 'flow_id' => $flow_id ) );
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d not found.', $flow_id ),
			);
		}

		// Check if flow is paused only for scheduler-triggered executions.
		// Direct ability/manual runs are allowed even when recurring schedules
		// are paused.
		$scheduling_config = $flow['scheduling_config'] ?? array();
		$respect_paused    = true === ( $input['respect_paused'] ?? false );
		if ( $respect_paused && ! \DataMachine\Core\Database\Flows\Flows::is_flow_enabled( $scheduling_config ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Flow execution skipped - flow is paused',
				array( 'flow_id' => $flow_id )
			);
			return array(
				'success' => false,
				'error'   => sprintf( 'Flow %d is paused.', $flow_id ),
			);
		}

		$pipeline_id = (int) $flow['pipeline_id'];
		$flow_config = datamachine_normalize_engine_config( $flow['flow_config'] ?? array() );

		$empty_drain_skip = $this->getEmptyDrainQueueSkip( $flow_config );
		if ( null !== $empty_drain_skip ) {
			$this->recordSuppressedRun( $flow_id, $scheduling_config, $empty_drain_skip );

			do_action(
				'datamachine_log',
				'info',
				'Flow execution skipped - drain queue is empty',
				array(
					'flow_id'      => $flow_id,
					'pipeline_id'  => $pipeline_id,
					'flow_step_id' => $empty_drain_skip['flow_step_id'],
					'reason'       => 'empty_drain_queue',
				)
			);

			return array(
				'success'    => true,
				'flow_id'    => $flow_id,
				'job_id'     => null,
				'first_step' => $empty_drain_skip['flow_step_id'],
				'skipped'    => true,
				'reason'     => 'empty_drain_queue',
			);
		}

		if ( isset( $scheduling_config['datamachine_last_suppressed_run'] ) ) {
			$this->clearSuppressedRun( $flow_id, $scheduling_config );
		}

		$agent_identity = null;
		if ( ! empty( $flow['agent_id'] ) ) {
			try {
				$agent_identity = ( new AgentIdentityResolver() )->resolve_agent_identity( (int) $flow['agent_id'] );
			} catch ( \InvalidArgumentException $e ) {
				$agent_identity = null;
			}
		}

		// Use provided job_id or create new one (for scheduled/recurring flows).
		if ( ! $job_id ) {
			// Admission throttle for scheduler-triggered runs. When the queue
			// already holds at least $max_active in-flight (pending/processing)
			// jobs, defer this run instead of admitting another job. Each job
			// fans out into a chain of execute_step actions, so unbounded
			// admission is what bloats the Action Scheduler tables and starves
			// the claim query into deadlocks. Manual/API runs (respect_paused
			// === false, or a pre-created job_id) are never throttled.
			if ( $respect_paused ) {
				$defer = $this->maybeDeferForBackpressure( $flow_id );
				if ( null !== $defer ) {
					return $defer;
				}
			}

			$job_data = array(
				'pipeline_id' => $pipeline_id,
				'flow_id'     => $flow_id,
				'source'      => 'pipeline',
				'label'       => $flow['flow_name'] ?? null,
				'user_id'     => (int) ( $flow['user_id'] ?? 0 ),
			);

			// Propagate agent_id from flow to job.
			if ( null !== $agent_identity ) {
				$job_data['agent_id'] = $agent_identity->agent_id;
			}

			$job_id = $this->db_jobs->create_job( $job_data );
			if ( ! $job_id ) {
				do_action(
					'datamachine_log',
					'error',
					'Job creation failed - database insert failed',
					array(
						'flow_id'     => $flow_id,
						'pipeline_id' => $pipeline_id,
					)
				);
				return array(
					'success' => false,
					'error'   => 'Job creation failed - database insert failed.',
				);
			}
			do_action(
				'datamachine_log',
				'debug',
				'Job created',
				array(
					'job_id'      => $job_id,
					'flow_id'     => $flow_id,
					'pipeline_id' => $pipeline_id,
				)
			);
		}

		$scheduling_config   = $flow['scheduling_config'] ?? array();
		$run_artifact_policy = \DataMachine\Engine\Bundle\BundleSchema::normalize_run_artifact_egress_policy( $scheduling_config['run_artifacts'] ?? array() );

		// Load pipeline config.
		$pipeline        = $this->db_pipelines->get_pipeline( $pipeline_id );
		$pipeline_config = $pipeline['pipeline_config'] ?? array();

		$pipeline_config = datamachine_normalize_engine_config( $pipeline_config );

		$job_snapshot = array(
			'job_id'      => $job_id,
			'flow_id'     => $flow_id,
			'pipeline_id' => $pipeline_id,
			'user_id'     => (int) ( $flow['user_id'] ?? 0 ),
			'created_at'  => current_time( 'mysql', true ),
		);

		if ( null !== $agent_identity ) {
			$job_snapshot['agent_id']   = $agent_identity->agent_id;
			$job_snapshot['agent_slug'] = $agent_identity->agent_slug;
		}

		$engine_snapshot = array(
			'job'             => $job_snapshot,
			'flow'            => array(
				'name'        => $flow['flow_name'] ?? '',
				'description' => $flow['flow_description'] ?? '',
				'scheduling'  => $scheduling_config,
			),
			'pipeline'        => array(
				'name'        => $pipeline['pipeline_name'] ?? '',
				'description' => $pipeline['pipeline_description'] ?? '',
			),
			'flow_config'     => $flow_config,
			'pipeline_config' => $pipeline_config,
		);
		if ( ! empty( $run_artifact_policy ) ) {
			$engine_snapshot['run_artifact_egress_policy'] = $run_artifact_policy;
			$engine_snapshot['flow']['run_artifacts']      = $run_artifact_policy;
		}

		// Merge initial_data (e.g. webhook payloads, API context) into the
		// engine snapshot. initial_data keys go underneath so engine
		// snapshot keys (job, flow, pipeline, configs) take precedence.
		$initial_data = $input['initial_data'] ?? null;
		if ( ! empty( $initial_data ) && is_array( $initial_data ) ) {
			$engine_snapshot = array_merge( $initial_data, $engine_snapshot );
		}

		// Preserve any pre-existing engine data stored directly on the job.
		$existing_data = \DataMachine\Core\EngineData::retrieve( $job_id );
		if ( ! empty( $existing_data ) ) {
			$engine_snapshot = array_merge( $existing_data, $engine_snapshot );
		}

		/**
		 * Filter the engine snapshot before it is persisted for a new job.
		 *
		 * Lets extensions enrich the engine_data snapshot with context that
		 * lives outside DM core. For example, Data Machine Code projects the
		 * active workspace identity (repo, handle, branch, path) into
		 * `active_workspace` so directives, abilities, and tool calls can
		 * read which repo the current job is operating against.
		 *
		 * Filters MUST return an array. Returning a non-array silently
		 * preserves the prior snapshot.
		 *
		 * @since 0.10.3
		 *
		 * @param array $engine_snapshot The snapshot about to be persisted.
		 * @param int   $job_id          Job being initialized.
		 * @param array $flow            Flow row from the database.
		 * @param array $pipeline        Pipeline row from the database.
		 */
		$filtered_snapshot = apply_filters( 'datamachine_engine_snapshot', $engine_snapshot, $job_id, $flow, $pipeline );
		if ( is_array( $filtered_snapshot ) ) {
			$engine_snapshot = $filtered_snapshot;
		}

		datamachine_set_engine_data( $job_id, $engine_snapshot );

		try {
			$first_flow_step_id = ExecutionPlan::from_flow_config( $flow_config )->first_step_id();
		} catch ( \InvalidArgumentException $e ) {
			$this->db_jobs->complete_job( $job_id, JobStatus::failed( 'invalid_execution_plan' )->toString() );

			do_action(
				'datamachine_log',
				'error',
				'Flow execution failed - invalid execution plan',
				array(
					'job_id'      => $job_id,
					'pipeline_id' => $pipeline_id,
					'flow_id'     => $flow_id,
					'error'       => $e->getMessage(),
				)
			);
			return array(
				'success' => false,
				'job_id'  => $job_id,
				'reason'  => 'invalid_execution_plan',
				'error'   => $e->getMessage(),
			);
		}

		if ( ! $first_flow_step_id ) {
			$this->db_jobs->complete_job( $job_id, JobStatus::failed( 'no_first_step' )->toString() );

			do_action(
				'datamachine_log',
				'error',
				'Flow execution failed - no first step found',
				array(
					'job_id'      => $job_id,
					'pipeline_id' => $pipeline_id,
					'flow_id'     => $flow_id,
				)
			);
			return array(
				'success' => false,
				'job_id'  => $job_id,
				'reason'  => 'no_first_step',
				'error'   => 'Flow execution failed - no first step found.',
			);
		}

		// Transition job from pending to processing only after a first step is known.
		$this->db_jobs->start_job( $job_id );

		do_action( 'datamachine_schedule_next_step', $job_id, $first_flow_step_id, array() );

		do_action(
			'datamachine_log',
			'info',
			'Flow execution started successfully',
			array(
				'flow_id'    => $flow_id,
				'job_id'     => $job_id,
				'first_step' => $first_flow_step_id,
			)
		);

		return array(
			'success'    => true,
			'flow_id'    => $flow_id,
			'job_id'     => $job_id,
			'first_step' => $first_flow_step_id,
		);
	}

	/**
	 * Defer a scheduler-triggered run when the queue is already saturated.
	 *
	 * Reads the in-flight (pending + processing) job count and compares it to
	 * the configured ceiling. When at or over the ceiling, reschedules
	 * `datamachine_run_flow_now` for this flow a short, jittered delay later
	 * and returns a skip result so no new job is admitted. Below the ceiling
	 * (or when throttling is disabled with a ceiling <= 0) returns null and the
	 * caller proceeds to admit the job normally.
	 *
	 * The jittered backoff prevents a thundering-herd re-stampede where every
	 * deferred flow wakes at the same instant and saturates the queue again.
	 *
	 * @param int $flow_id Flow being scheduled.
	 * @return array{success:bool,flow_id:int,job_id:null,skipped:bool,reason:string}|null
	 *               Skip result when deferred, or null to proceed.
	 */
	private function maybeDeferForBackpressure( int $flow_id ): ?array {
		$max_active = self::maxActiveJobs();
		if ( $max_active <= 0 ) {
			return null;
		}

		$active = $this->db_jobs->count_active_jobs();
		if ( $active < $max_active ) {
			return null;
		}

		$delay = self::backpressureDeferSeconds( $flow_id );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			// Only enqueue a deferral tick if one is not already pending for
			// this flow, so repeated saturated cycles don't pile up duplicate
			// wake-ups for the same flow.
			$already_pending = function_exists( 'as_next_scheduled_action' )
				&& false !== as_next_scheduled_action( 'datamachine_run_flow_now', array( $flow_id ), 'data-machine' );

			if ( ! $already_pending ) {
				as_schedule_single_action(
					time() + $delay,
					'datamachine_run_flow_now',
					array( $flow_id ),
					'data-machine'
				);
			}
		}

		do_action(
			'datamachine_log',
			'info',
			'Flow execution deferred - queue backpressure',
			array(
				'flow_id'       => $flow_id,
				'active_jobs'   => $active,
				'max_active'    => $max_active,
				'defer_seconds' => $delay,
				'reason'        => 'queue_backpressure',
			)
		);

		return array(
			'success' => true,
			'flow_id' => $flow_id,
			'job_id'  => null,
			'skipped' => true,
			'reason'  => 'queue_backpressure',
		);
	}

	/**
	 * Resolve the maximum number of in-flight jobs before new scheduler-
	 * triggered runs are deferred.
	 *
	 * Reads queue_tuning.max_active_jobs and runs it through the
	 * `datamachine_max_active_jobs` filter. A value of 0 (or less) disables
	 * admission throttling entirely (the prior unbounded behavior).
	 *
	 * @return int Ceiling on in-flight jobs (0 disables throttling).
	 */
	private static function maxActiveJobs(): int {
		$tuning  = \DataMachine\Core\PluginSettings::get( 'queue_tuning', array() );
		$default = ( is_array( $tuning ) && isset( $tuning['max_active_jobs'] ) )
			? (int) $tuning['max_active_jobs']
			: self::DEFAULT_MAX_ACTIVE_JOBS;

		/**
		 * Filter the in-flight job ceiling used for scheduler admission control.
		 *
		 * @param int $default The resolved ceiling. 0 (or less) disables throttling.
		 */
		$max = (int) apply_filters( 'datamachine_max_active_jobs', $default );

		return $max > 0 ? $max : 0;
	}

	/**
	 * Resolve the deferral backoff (seconds) for a backpressured flow, with
	 * deterministic per-flow jitter to avoid a synchronized re-stampede.
	 *
	 * @param int $flow_id Flow being deferred (used as the jitter seed).
	 * @return int Delay in seconds (always >= 1).
	 */
	private static function backpressureDeferSeconds( int $flow_id ): int {
		$base = (int) apply_filters( 'datamachine_backpressure_defer_seconds', self::DEFAULT_BACKPRESSURE_DEFER_SECONDS );
		if ( $base < 1 ) {
			$base = self::DEFAULT_BACKPRESSURE_DEFER_SECONDS;
		}

		// Deterministic jitter in [0, base) spreads deferred flows across the
		// window instead of waking them all at base seconds.
		$jitter = absint( crc32( 'datamachine_backpressure_' . $flow_id ) ) % $base;

		return $base + $jitter;
	}

	/**
	 * Return first-step drain queue details when a scheduled flow has no work.
	 *
	 * @param array $flow_config Normalized flow config.
	 * @return array{flow_step_id:string,queue_mode:string,reason:string}|null
	 */
	private function getEmptyDrainQueueSkip( array $flow_config ): ?array {
		$availability = QueueAbility::firstDrainQueueWorkAvailability( $flow_config );
		if ( null === $availability || true === $availability['has_work'] ) {
			return null;
		}

		return array(
			'flow_step_id' => $availability['flow_step_id'],
			'queue_mode'   => 'drain',
			'reason'       => 'empty_drain_queue',
		);
	}

	/**
	 * Persist a virtual run marker for scheduled empty drain ticks.
	 *
	 * The cycle scheduler uses latest job timestamps to decide when a flow is
	 * due. Empty drain skips intentionally do not create jobs, so this marker
	 * lets the scheduler back off until the next normal window while keeping the
	 * operator-visible reason separate from provider exhaustion.
	 *
	 * @param int   $flow_id           Flow ID.
	 * @param array $scheduling_config Current scheduling config.
	 * @param array $skip              Skip details from getEmptyDrainQueueSkip().
	 */
	private function recordSuppressedRun( int $flow_id, array $scheduling_config, array $skip ): void {
		$suppressed_at = current_time( 'mysql', true );

		$scheduling_config['datamachine_last_suppressed_run'] = array(
			'reason'        => (string) ( $skip['reason'] ?? 'empty_drain_queue' ),
			'flow_step_id'  => (string) ( $skip['flow_step_id'] ?? '' ),
			'queue_mode'    => (string) ( $skip['queue_mode'] ?? 'drain' ),
			'suppressed_at' => $suppressed_at,
			'backoff_until' => $this->getSuppressedRunBackoffUntil( $scheduling_config, $suppressed_at ),
		);

		$this->db_flows->update_flow_scheduling( $flow_id, $scheduling_config );
	}

	/**
	 * Clear stale suppression metadata once the flow can start normally.
	 *
	 * @param int   $flow_id           Flow ID.
	 * @param array $scheduling_config Current scheduling config.
	 */
	private function clearSuppressedRun( int $flow_id, array $scheduling_config ): void {
		unset( $scheduling_config['datamachine_last_suppressed_run'] );
		$this->db_flows->update_flow_scheduling( $flow_id, $scheduling_config );
	}

	/**
	 * Resolve the human-readable backoff boundary for suppression metadata.
	 *
	 * @param array  $scheduling_config Current scheduling config.
	 * @param string $suppressed_at     UTC MySQL datetime.
	 * @return string|null UTC MySQL datetime when known.
	 */
	private function getSuppressedRunBackoffUntil( array $scheduling_config, string $suppressed_at ): ?string {
		$interval = (string) ( $scheduling_config['interval'] ?? '' );
		if ( '' === $interval || 'manual' === $interval ) {
			return null;
		}

		try {
			$suppressed_time = new \DateTime( $suppressed_at, new \DateTimeZone( 'UTC' ) );

			if ( 'cron' === $interval && ! empty( $scheduling_config['cron_expression'] ) && class_exists( 'CronExpression' ) ) {
				$cron = \CronExpression::factory( (string) $scheduling_config['cron_expression'] );
				return $cron->getNextRunDate( $suppressed_time )->format( 'Y-m-d H:i:s' );
			}

			$intervals     = apply_filters( 'datamachine_scheduler_intervals', array() );
			$interval_data = $intervals[ $interval ] ?? null;
			if ( is_array( $interval_data ) && isset( $interval_data['seconds'] ) ) {
				$suppressed_time->modify( '+' . max( 0, (int) $interval_data['seconds'] ) . ' seconds' );
				return $suppressed_time->format( 'Y-m-d H:i:s' );
			}
		} catch ( \Exception $e ) {
			return null;
		}

		return null;
	}
}
