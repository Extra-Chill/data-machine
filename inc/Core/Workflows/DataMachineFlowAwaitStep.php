<?php
/**
 * The `datamachine_flow` workflow step type: runs a persisted Data Machine
 * flow and awaits its terminal run-result.
 *
 * Extra-Chill/data-machine#3562, phase 3 of #3428. `datamachine/run-flow`
 * (the built-in `ability` step type already dispatches it) returns as soon
 * as the flow's job is QUEUED — a workflow author cannot consume the flow's
 * eventual output. This step type closes that gap using the generic `await`
 * suspend/resume primitive Automattic/agents-api#577 (v0.15.0) introduced:
 *
 * 1. `handle()` dispatches `datamachine/run-flow`, persists the resulting
 *    flow job id + owning workflow run id as an "await linkage" on the flow
 *    job's own engine_data, then returns a `_suspend => { kind: 'await',
 *    wait_id: (string) $flow_job_id }` directive. The runner parks the
 *    workflow run SUSPENDED (see agents-api#577's `register-workflow-await.php`).
 * 2. `onJobTerminalCommitted()` — hooked on Data Machine's own
 *    `datamachine_job_terminal_committed( $job_id, $status )`, which fires
 *    exactly once per job after its terminal accounting commits — reads the
 *    linkage back off the just-terminalized flow job (if any) and delivers
 *    the canonical run-result envelope into the awaiting workflow step via
 *    `agents_workflow_complete_wait()`.
 *
 * Two races are handled without a second suspend/resume system of their own,
 * because `agents_workflow_complete_wait()` already owns exactly-once
 * delivery (agents-api#577):
 *
 * - **Reconcile lock contention** (`agents_reconcile_lock_unavailable`): the
 *   owning workflow run's per-run reconcile lock was held by a concurrent
 *   writer. Retryable — the same completion is safe to repeat.
 * - **Fast job race**: `datamachine/run-flow` enqueues the flow's first step
 *   asynchronously; on a very fast flow, that step (and therefore this job's
 *   OWN terminal commit) can complete before `run_step_loop()` has finished
 *   persisting the OWNING workflow run as SUSPENDED. `complete_wait()` then
 *   finds the owning run not yet suspended (still `pending`/`running`) and
 *   returns that run state as-is rather than an error. Also retryable — the
 *   suspension will exist by the time the retry runs.
 *
 * Both races schedule exactly one durable Action Scheduler retry a short
 * delay later, which re-runs the identical terminal-commit handling from a
 * fresh job read. Every other non-suspended/error outcome (already consumed,
 * wrong wait, a structurally invalid completion) is a permanent mismatch —
 * logged, not retried, since retrying could never succeed.
 *
 * @package DataMachine\Core\Workflows
 */

namespace DataMachine\Core\Workflows;

use DataMachine\Core\ActionScheduler\GroupRegistrar;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\EngineData;
use DataMachine\Core\JobStatus;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\RunResultEnvelope;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class DataMachineFlowAwaitStep {

	/**
	 * The workflow step type name this class registers.
	 */
	public const STEP_TYPE = 'datamachine_flow';

	/**
	 * Engine data key holding the `{ run_id, runtime, wait_id }` linkage
	 * between a Data Machine flow job and the workflow step awaiting it.
	 */
	private const ENGINE_DATA_KEY = 'agents_workflow_await';

	/**
	 * Action Scheduler hook that repeats a terminal-commit completion attempt
	 * after a transient failure (reconcile lock contention, or the fast-job
	 * race where the owning run has not suspended yet).
	 */
	private const RETRY_HOOK = 'datamachine_flow_await_step_retry_completion';

	/**
	 * Delay before a retried completion attempt runs. Both races it exists
	 * for (reconcile lock contention, the fast-job suspend race) resolve
	 * within milliseconds to seconds in practice; 30s is a durable backstop,
	 * not a tight poll.
	 */
	private const RETRY_DELAY_SECONDS = 30;

	/**
	 * Error codes from {@see \AgentsAPI\AI\Workflows\agents_workflow_complete_wait()}
	 * that indicate a transient condition worth retrying rather than a
	 * permanent mismatch.
	 */
	private const RETRYABLE_ERROR_CODES = array(
		'agents_reconcile_lock_unavailable',
		'agents_workflow_complete_wait_not_found',
	);

	private Jobs $db_jobs;

	public function __construct( ?Jobs $db_jobs = null ) {
		$this->db_jobs = $db_jobs ?? new Jobs();
		$this->registerStepType();
		$this->registerHooks();
	}

	/**
	 * Register the `datamachine_flow` workflow step type.
	 *
	 * No `required` field list: the registry's generic `required` check only
	 * accepts a non-empty STRING value, but `flow_id` is an integer at the
	 * Data Machine ability boundary (`datamachine/run-flow`'s `flow_id` input
	 * is `type: integer`). A literal integer `flow_id` in a workflow spec
	 * would always fail that string-only check, so all field validation
	 * (including the missing-field case) lives in {@see self::validate()}.
	 */
	private function registerStepType(): void {
		\AgentsAPI\AI\Workflows\register_workflow_step_type(
			self::STEP_TYPE,
			array(
				'handler'  => array( $this, 'handle' ),
				'validate' => array( __CLASS__, 'validate' ),
			)
		);
	}

	private function registerHooks(): void {
		add_action( 'datamachine_job_terminal_committed', array( $this, 'onJobTerminalCommitted' ), 10, 2 );
		add_action( self::RETRY_HOOK, array( $this, 'retryCompletion' ), 10, 2 );
	}

	/**
	 * Structural field validation for a `datamachine_flow` step.
	 *
	 * A field bound to a `${...}` binding token is not yet resolved at
	 * structural-validation time, so its literal-value type cannot be
	 * checked here; only literal values are type-checked.
	 *
	 * @param array<mixed> $step Raw `datamachine_flow` step.
	 * @param string       $path Error path prefix for this step.
	 * @return array<int,array{path:string,code:string,message:string}>
	 */
	public static function validate( array $step, string $path ): array {
		$errors = array();

		if ( ! array_key_exists( 'flow_id', $step ) || null === $step['flow_id'] || '' === $step['flow_id'] ) {
			$errors[] = array(
				'path'    => "{$path}.flow_id",
				'code'    => 'missing_required',
				'message' => 'datamachine_flow step is missing required `flow_id`',
			);
		} elseif ( ! self::isBindingToken( $step['flow_id'] ) && ! self::isPositiveIntLike( $step['flow_id'] ) ) {
			$errors[] = array(
				'path'    => "{$path}.flow_id",
				'code'    => 'invalid_type',
				'message' => 'datamachine_flow step `flow_id` must be a positive integer',
			);
		}

		if ( array_key_exists( 'initial_data', $step ) && ! self::isBindingToken( $step['initial_data'] ) && ! is_array( $step['initial_data'] ) ) {
			$errors[] = array(
				'path'    => "{$path}.initial_data",
				'code'    => 'invalid_type',
				'message' => 'datamachine_flow step `initial_data` must be an object',
			);
		}

		if ( array_key_exists( 'timeout_seconds', $step ) && ! self::isBindingToken( $step['timeout_seconds'] ) && ! self::isPositiveIntLike( $step['timeout_seconds'] ) ) {
			$errors[] = array(
				'path'    => "{$path}.timeout_seconds",
				'code'    => 'invalid_type',
				'message' => 'datamachine_flow step `timeout_seconds` must be a positive integer',
			);
		}

		return $errors;
	}

	/**
	 * `datamachine_flow` step handler: dispatch `datamachine/run-flow`, link
	 * the resulting flow job to the awaiting workflow run, and suspend.
	 *
	 * @param array<mixed> $step    Resolved step (bindings already expanded).
	 * @param array<mixed> $context Run context; carries `_workflow_run_id`.
	 * @return array<mixed>|WP_Error
	 */
	public function handle( array $step, array $context ) {
		$flow_id = (int) ( $step['flow_id'] ?? 0 );
		if ( $flow_id <= 0 ) {
			return new WP_Error( 'datamachine_flow_invalid_flow_id', 'datamachine_flow step requires a positive `flow_id`.' );
		}

		$args = array( 'flow_id' => $flow_id );
		if ( is_array( $step['initial_data'] ?? null ) ) {
			$args['initial_data'] = $step['initial_data'];
		}

		$result = \AgentsAPI\AI\Abilities\WP_Agent_Ability_Dispatcher::dispatch( 'datamachine/run-flow', $args );
		if ( is_wp_error( $result ) ) {
			if ( 'ability_not_found' === $result->get_error_code() ) {
				return new WP_Error( 'datamachine_flow_run_flow_missing', 'datamachine/run-flow ability is not registered.' );
			}
			return $result;
		}

		if ( ! is_array( $result ) || empty( $result['success'] ) ) {
			return new WP_Error(
				'datamachine_flow_run_failed',
				is_string( $result['error'] ?? null ) ? $result['error'] : 'datamachine/run-flow did not report success.',
				is_array( $result ) ? $result : array()
			);
		}

		$job_id = isset( $result['job_id'] ) ? (int) $result['job_id'] : 0;
		if ( $job_id <= 0 ) {
			// No job was admitted (a paused flow's recurring schedule, or an
			// empty drain-queue tick — both report `success: true, job_id:
			// null, skipped: true`). Nothing to await; the step succeeds now
			// with the ability's own output rather than suspending on a wait
			// that would never terminalize.
			return $result;
		}

		$run_id = is_string( $context['_workflow_run_id'] ?? null ) ? $context['_workflow_run_id'] : '';
		if ( '' === $run_id ) {
			return new WP_Error( 'datamachine_flow_missing_run_id', 'datamachine_flow step could not resolve the owning workflow run id.' );
		}

		$linked = EngineData::merge(
			$job_id,
			array(
				self::ENGINE_DATA_KEY => array(
					'run_id'  => $run_id,
					'runtime' => DataMachineWorkflowRuntime::RUNTIME_KEY,
					'wait_id' => (string) $job_id,
				),
			)
		);
		if ( ! $linked ) {
			return new WP_Error( 'datamachine_flow_await_link_failed', 'Failed to persist the workflow await linkage on the flow job.' );
		}

		$suspend = array(
			'kind'    => 'await',
			'wait_id' => (string) $job_id,
		);

		$timeout_seconds = self::isPositiveIntLike( $step['timeout_seconds'] ?? null ) ? (int) $step['timeout_seconds'] : 0;
		if ( $timeout_seconds > 0 ) {
			$suspend['timeout_at'] = time() + $timeout_seconds;
		}

		return array( '_suspend' => $suspend );
	}

	/**
	 * `datamachine_job_terminal_committed` — complete the awaiting workflow
	 * step's `await` wait for a job that a `datamachine_flow` step started,
	 * if any. Fires once per job (Jobs::reconcile_terminal_accounting()); a
	 * job with no await linkage (the overwhelming majority) is a no-op read.
	 *
	 * Never throws: a Throwable here would otherwise propagate out of Data
	 * Machine's own terminal accounting notification stage.
	 *
	 * @param int    $job_id Terminalized Data Machine job id.
	 * @param string $status Terminal job status (possibly compound, e.g. `failed - reason`).
	 */
	public function onJobTerminalCommitted( int $job_id, string $status ): void {
		try {
			$job = $this->db_jobs->get_job( $job_id );
			if ( ! is_array( $job ) ) {
				return;
			}

			$link = $this->awaitLink( $job );
			if ( null === $link ) {
				return;
			}

			$status = is_string( $job['status'] ?? null ) && '' !== $job['status'] ? $job['status'] : $status;

			$this->completeWait( $job_id, $status, $link, $job );
		} catch ( \Throwable $error ) {
			do_action(
				'datamachine_log',
				'error',
				'datamachine_flow await terminal-commit handling threw',
				array(
					'job_id'    => $job_id,
					'status'    => $status,
					'exception' => $error->getMessage(),
				)
			);
		}
	}

	/**
	 * Action Scheduler callback: repeat a terminal-commit completion attempt.
	 * Identical to the original attempt — a fresh job read, same linkage
	 * resolution, same completion path — since exactly-once delivery is
	 * guaranteed upstream by {@see \AgentsAPI\AI\Workflows\agents_workflow_complete_wait()}.
	 *
	 * @param int    $job_id Terminalized Data Machine job id.
	 * @param string $status Terminal job status captured at schedule time.
	 */
	public function retryCompletion( int $job_id, string $status ): void {
		$this->onJobTerminalCommitted( $job_id, $status );
	}

	/**
	 * Read the `{ run_id, runtime, wait_id }` await linkage off a job's
	 * engine_data, or null when the job was not started by a
	 * `datamachine_flow` step.
	 *
	 * @param array<string,mixed> $job Data Machine job row.
	 * @return array{run_id:string,wait_id:string,runtime:string}|null
	 */
	private function awaitLink( array $job ): ?array {
		$engine = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$link   = $engine[ self::ENGINE_DATA_KEY ] ?? null;
		if ( ! is_array( $link ) ) {
			return null;
		}

		$run_id  = is_string( $link['run_id'] ?? null ) ? $link['run_id'] : '';
		$wait_id = is_string( $link['wait_id'] ?? null ) ? $link['wait_id'] : '';
		if ( '' === $run_id || '' === $wait_id ) {
			return null;
		}

		return array(
			'run_id'  => $run_id,
			'wait_id' => $wait_id,
			'runtime' => is_string( $link['runtime'] ?? null ) && '' !== $link['runtime'] ? $link['runtime'] : DataMachineWorkflowRuntime::RUNTIME_KEY,
		);
	}

	/**
	 * Deliver the terminal completion into the awaiting workflow step.
	 *
	 * @param int                  $job_id Terminalized flow job id (== the wait's `wait_id`).
	 * @param string               $status Terminal job status.
	 * @param array{run_id:string,wait_id:string,runtime:string} $link Await linkage.
	 * @param array<string,mixed>  $job    Freshly read job row.
	 */
	private function completeWait( int $job_id, string $status, array $link, array $job ): void {
		$completion = $this->buildCompletion( $job_id, $status, $job );

		$result = \AgentsAPI\AI\Workflows\agents_workflow_complete_wait(
			$link['runtime'],
			$link['run_id'],
			$link['wait_id'],
			$completion
		);

		if ( $this->isRetryable( $result ) ) {
			$this->scheduleRetry( $job_id, $status );
			return;
		}

		if ( is_wp_error( $result ) ) {
			// A permanent mismatch (already consumed, wrong wait, foreign
			// suspension kind, invalid completion status): retrying could
			// never succeed. Logged for operator visibility, not retried.
			do_action(
				'datamachine_log',
				'warning',
				'datamachine_flow await completion could not be delivered',
				array(
					'job_id'        => $job_id,
					'run_id'        => $link['run_id'],
					'wait_id'       => $link['wait_id'],
					'error_code'    => $result->get_error_code(),
					'error_message' => $result->get_error_message(),
				)
			);
		}
	}

	/**
	 * Build the `{ status, output? , error? }` completion envelope for
	 * {@see \AgentsAPI\AI\Workflows\agents_workflow_complete_wait()} from a
	 * terminalized flow job's canonical run-result envelope.
	 *
	 * @param int                 $job_id Terminalized flow job id.
	 * @param string              $status Terminal job status.
	 * @param array<string,mixed> $job    Freshly read job row.
	 * @return array<string,mixed>
	 */
	private function buildCompletion( int $job_id, string $status, array $job ): array {
		$envelope = RunResultEnvelope::fromJobSummary( $job, RunMetrics::fromJob( $job ) );

		if ( JobStatus::isStatusSuccess( $status ) ) {
			return array(
				'status' => 'succeeded',
				'output' => $envelope,
			);
		}

		$base_status = JobStatus::fromString( $status )->getBaseStatus();

		return array(
			'status' => 'failed',
			'error'  => array(
				'code'       => '' !== $base_status ? $base_status : 'datamachine_flow_failed',
				'message'    => sprintf( 'Data Machine flow job %d finished with status `%s`.', $job_id, $status ),
				'run_result' => $envelope,
			),
		);
	}

	/**
	 * Whether an `agents_workflow_complete_wait()` outcome is a transient
	 * condition worth retrying: reconcile lock contention, an owning run not
	 * yet found (a fast job racing the recorder's own persistence), or an
	 * owning run found but not yet SUSPENDED (a fast job racing
	 * `run_step_loop()`'s suspension-frame persistence).
	 *
	 * @param mixed $result Return value of `agents_workflow_complete_wait()`.
	 */
	private function isRetryable( $result ): bool {
		if ( is_wp_error( $result ) ) {
			return in_array( $result->get_error_code(), self::RETRYABLE_ERROR_CODES, true );
		}

		if ( $result instanceof \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result ) {
			return in_array(
				$result->get_status(),
				array(
					\AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result::STATUS_PENDING,
					\AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result::STATUS_RUNNING,
				),
				true
			);
		}

		return false;
	}

	/**
	 * Schedule a single durable retry of the terminal-commit completion.
	 * Deduplicated on `(job_id, status)` so a burst of retryable outcomes for
	 * the same job never stacks more than one pending retry.
	 *
	 * @param int    $job_id Terminalized flow job id.
	 * @param string $status Terminal job status at the time of this attempt.
	 */
	private function scheduleRetry( int $job_id, string $status ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'datamachine_flow await completion could not be retried — Action Scheduler is unavailable',
				array(
					'job_id' => $job_id,
					'status' => $status,
				)
			);
			return;
		}

		$args = array( $job_id, $status );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::RETRY_HOOK, $args, GroupRegistrar::GROUP ) ) {
			return;
		}

		as_schedule_single_action( time() + self::RETRY_DELAY_SECONDS, self::RETRY_HOOK, $args, GroupRegistrar::GROUP );
	}

	/**
	 * Whether a raw step field value is an unresolved `${...}` binding token
	 * (any literal-value type check is deferred to run time, after binding
	 * resolution).
	 *
	 * @param mixed $value Raw step field value.
	 */
	private static function isBindingToken( $value ): bool {
		return is_string( $value ) && false !== strpos( $value, '${' );
	}

	/**
	 * Whether a value represents a positive integer, accepting both a native
	 * int and a numeric string (workflow spec JSON may decode either way
	 * depending on how the literal was authored).
	 *
	 * @param mixed $value Candidate value.
	 */
	private static function isPositiveIntLike( $value ): bool {
		if ( is_int( $value ) ) {
			return $value > 0;
		}

		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return ( (int) $value ) > 0;
		}

		return false;
	}
}
