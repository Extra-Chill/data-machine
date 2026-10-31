<?php
/**
 * The `await` primitive: a generic "park a workflow step on an external
 * durable job" wait, completed exactly once.
 *
 * A step handler suspends with `_suspend => { kind: 'await', wait_id, timeout_at? }`
 * (the runner persists it via {@see WP_Agent_Workflow_Runner::build_suspension_frame()}
 * exactly like it already does for the built-in `parallel` fanout). Something
 * OUTSIDE the run — a webhook, a scheduled poll, another workflow's terminal
 * hook — later calls {@see agents_workflow_complete_wait()} (or dispatches the
 * `agents/complete-workflow-wait` ability) with the opaque `wait_id` and a
 * terminal completion. This file owns exactly-once delivery of that completion
 * into the suspended step and the optional `timeout_at` backstop; it does NOT
 * build a second suspend/resume/lock system — every primitive it composes
 * (the per-run reconcile lock, scoped recorder resolution, step-output splice,
 * and the resume dispatch seam) already exists in
 * {@see register-reconcile-workflow-branch.php} for the `parallel` fanout.
 *
 * WHY THIS IS SIMPLER THAN `parallel` RECONCILE. Parallel reconcile is a
 * multi-writer merge — N branches each report in, and only the LAST one may
 * trigger aggregation, so it needs a three-phase claim (`queued` → `running` →
 * `committed`) to fence a potentially-slow, at-most-once aggregate pass outside
 * the short lock. An `await` completion is a SINGLE writer event: one wait,
 * one completion, splice the output directly (no aggregation step to fence
 * separately). So the whole transition — verify SUSPENDED + matching wait_id
 * + not-yet-claimed, splice, mark claimed, done — fits in ONE short critical
 * section under the SAME reconcile lock, then resume dispatches through the
 * SAME seam parallel already uses ({@see agents_workflow_resume_reconcile_continuation()}).
 *
 * EXACTLY ONCE. The frame's `wait_claim` (set to `{ phase: 'consumed' }` in the
 * SAME locked transition that splices the output) is the fence a second
 * completer — a duplicate delivery, or a real completion racing its own
 * timeout — observes: whichever call wins the lock first claims and splices;
 * the other sees the claim already consumed and returns the current run state
 * untouched. Once the run stops being SUSPENDED at all (resumed to a terminal
 * status, or cancelled), that alone is already sufficient — the claim exists
 * to close the SHORT window before resume() actually runs.
 *
 * TIMEOUT. If the directive named a `timeout_at`, the frame's freshly-minted
 * `generation` (an opaque per-suspension-instance token, NOT derived from the
 * caller-opaque `wait_id`) rides in the scheduled Action Scheduler payload.
 * The timeout callback drives the SAME locked completion path with that exact
 * generation, so a stale timeout for a superseded suspension (the run resumed
 * and suspended again on the same `wait_id`) is provably a no-op rather than
 * corrupting a newer, unrelated suspension instance. No Action Scheduler present
 * → the timeout is simply never scheduled (mirrors the substrate's existing
 * "no AS → no async continuation" degradation for parallel resume).
 *
 * CANCELLATION. Cancelling a run suspended on `await` uses the existing
 * cancellation fence unmodified: cancellation stages an intent
 * ({@see WP_Agent_Run_Control::request_cancel()}); the run stays SUSPENDED
 * (nothing new needed here) until something advances it — a real completion,
 * or the timeout — calls {@see WP_Agent_Workflow_Runner::resume()}. Resume's
 * step loop checks `is_cancel_requested()` BEFORE running the next step, so a
 * completion that arrives after cancellation was requested still terminalizes
 * the run CANCELLED (discarding the spliced output) rather than SUCCEEDED —
 * no `await`-specific cancellation code is needed.
 *
 * @package AgentsAPI
 * @since   0.15.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

const AGENTS_COMPLETE_WORKFLOW_WAIT_ABILITY = 'agents/complete-workflow-wait';
const AGENTS_WORKFLOW_AWAIT_TIMEOUT_HOOK    = 'agents_workflow_await_timeout';

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( wp_has_ability( AGENTS_COMPLETE_WORKFLOW_WAIT_ABILITY ) ) {
			return;
		}

		wp_register_ability(
			AGENTS_COMPLETE_WORKFLOW_WAIT_ABILITY,
			array(
				'label'               => 'Complete Workflow Wait',
				'description'         => 'Deliver a completion into a workflow run suspended on an `await` wait and resume it. Exactly once: a duplicate or late completion for an already-consumed wait is a no-op that returns the current run state.',
				'category'            => 'agents-api',
				'input_schema'        => agents_workflow_complete_wait_input_schema(),
				'output_schema'       => agents_run_workflow_output_schema(),
				'execute_callback'    => __NAMESPACE__ . '\\agents_complete_workflow_wait_ability',
				'permission_callback' => __NAMESPACE__ . '\\agents_workflow_complete_wait_permission',
				'meta'                => array(
					// Not REST-visible by default: a wait completion carries an
					// arbitrary consumer-defined output payload, not a shape meant
					// for generic HTTP callers. A consumer that wants HTTP access
					// (e.g. a signed webhook target) exposes its OWN thin adapter
					// rather than flipping this substrate ability's visibility.
					'show_in_rest' => false,
					'annotations'  => array(
						'destructive' => true,
						'idempotent'  => true,
					),
				),
			)
		);
	}
);

/**
 * Ability wrapper for {@see agents_workflow_complete_wait()}.
 *
 * @since 0.15.0
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_complete_workflow_wait_ability( array $input ) {
	$run_id     = agents_workflow_string( $input['run_id'] ?? '' );
	$wait_id    = agents_workflow_string( $input['wait_id'] ?? '' );
	$runtime    = agents_workflow_string( $input['runtime'] ?? '' );
	$completion = is_array( $input['completion'] ?? null ) ? \AgentsAPI\AI\WP_Agent_Run_Control::string_keyed_array( $input['completion'] ) : array();

	if ( '' === $run_id || '' === $wait_id ) {
		return new \WP_Error( 'agents_complete_workflow_wait_invalid_input', 'run_id and wait_id are required.' );
	}

	$result = agents_workflow_complete_wait( $runtime, $run_id, $wait_id, $completion );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $result->to_array();
}

/**
 * Complete one suspended `await` wait and resume the run, exactly once.
 *
 * @since 0.15.0
 *
 * @param string              $runtime    Owning runtime key of the run (#567). Empty for
 *                                        legacy runs started before runtime attribution existed.
 * @param string              $run_id     The suspended run id.
 * @param string              $wait_id    The opaque wait id the suspended step is parked on.
 * @param array<string,mixed> $completion `{ status: succeeded|failed, output?: array, error?: array }`.
 * @return WP_Agent_Workflow_Run_Result|\WP_Error The (possibly still-suspended, on error) run, or a WP_Error.
 */
function agents_workflow_complete_wait( string $runtime, string $run_id, string $wait_id, array $completion ) {
	$recorder = agents_workflow_resolve_recorder( $runtime, $run_id );
	if ( null === $recorder ) {
		return new \WP_Error( 'agents_workflow_complete_wait_no_recorder', 'A recorder is required to complete a suspended wait. Register one via the `wp_agent_workflow_run_recorder` filter.' );
	}

	return agents_workflow_complete_wait_with_recorder( $recorder, $run_id, $wait_id, null, $completion );
}

/**
 * Shared completion path for both a real caller-driven completion (no
 * `$generation` — matches on `wait_id` alone) and the durable timeout callback
 * (an exact `$generation` — fences a stale timeout against a superseded
 * suspension instance).
 *
 * @since 0.15.0
 *
 * @param WP_Agent_Workflow_Run_Recorder $recorder   Resolved recorder.
 * @param string                         $run_id     The suspended run id.
 * @param string                         $wait_id    The opaque wait id.
 * @param string|null                    $generation Exact suspension-instance fence, or null to skip it.
 * @param array<string,mixed>            $completion `{ status, output?, error? }`.
 * @return WP_Agent_Workflow_Run_Result|\WP_Error
 */
function agents_workflow_complete_wait_with_recorder( WP_Agent_Workflow_Run_Recorder $recorder, string $run_id, string $wait_id, ?string $generation, array $completion ) {
	$transition = agents_workflow_reconcile_with_lock(
		$run_id,
		static function () use ( $recorder, $run_id, $wait_id, $generation, $completion ) {
			return agents_workflow_complete_wait_locked( $recorder, $run_id, $wait_id, $generation, $completion );
		}
	);
	if ( is_wp_error( $transition ) || $transition instanceof WP_Agent_Workflow_Run_Result ) {
		return $transition;
	}

	return agents_workflow_resume_reconcile_continuation( $recorder, $run_id, $transition['result'] );
}

/**
 * The completion state transition run under the per-run reconcile lock. Loads
 * the run, verifies it is SUSPENDED on exactly this `wait_id` (and, when given,
 * this exact `generation`), claims the wait, and splices the completion into
 * the suspended step's record. Resume itself runs AFTER this returns and the
 * lock is released (mirrors the parallel aggregate-commit shape).
 *
 * @since 0.15.0
 *
 * @param WP_Agent_Workflow_Run_Recorder $recorder   Resolved recorder.
 * @param string                         $run_id     The suspended run id.
 * @param string                         $wait_id    The opaque wait id asserted by the caller.
 * @param string|null                    $generation Exact suspension-instance fence, or null to skip it.
 * @param array<string,mixed>            $completion `{ status, output?, error? }`.
 * @return WP_Agent_Workflow_Run_Result|array{action:'resume',result:WP_Agent_Workflow_Run_Result}|\WP_Error
 */
function agents_workflow_complete_wait_locked( WP_Agent_Workflow_Run_Recorder $recorder, string $run_id, string $wait_id, ?string $generation, array $completion ) {
	$result = $recorder->find( $run_id );
	if ( null === $result ) {
		return new \WP_Error( 'agents_workflow_complete_wait_not_found', sprintf( 'No suspended run was found for run_id `%s`.', $run_id ) );
	}
	if ( ! $result->is_suspended() ) {
		// Exactly once: the run already resumed (naturally, or because
		// cancellation won the race) — a duplicate/late completion, or a
		// timeout racing a real completion that already won, is a no-op that
		// returns the current (terminal, or newly-suspended-again) run state.
		return $result;
	}

	$suspension = $result->get_suspension();
	if ( 'await' !== agents_workflow_string( $suspension['kind'] ?? '' ) ) {
		return new \WP_Error( 'agents_workflow_complete_wait_not_awaiting', sprintf( 'Run `%s` is not suspended on an `await` wait.', $run_id ) );
	}
	if ( $wait_id !== agents_workflow_string( $suspension['wait_id'] ?? '' ) ) {
		return new \WP_Error( 'agents_workflow_complete_wait_unknown_wait', sprintf( 'wait_id `%s` does not match the suspended wait for run `%s`.', $wait_id, $run_id ) );
	}
	if ( null !== $generation && ! hash_equals( $generation, agents_workflow_string( $suspension['generation'] ?? '' ) ) ) {
		// A stale timeout for a superseded suspension instance (the run resumed
		// and suspended again, coincidentally or by design, on the same
		// wait_id) must not touch the newer, unrelated suspension.
		return $result;
	}

	$wait_claim = is_array( $suspension['wait_claim'] ?? null ) ? $suspension['wait_claim'] : array();
	if ( 'consumed' === agents_workflow_string( $wait_claim['phase'] ?? '' ) ) {
		// Exactly once, closing the short window BEFORE resume() actually runs:
		// a second completer (duplicate delivery, or a real completion racing
		// its own timeout) that reaches this lock after the first already
		// claimed the wait sees it consumed and no-ops.
		return $result;
	}

	$status = agents_workflow_string( $completion['status'] ?? '' );
	if ( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED !== $status && WP_Agent_Workflow_Run_Result::STATUS_FAILED !== $status ) {
		return new \WP_Error(
			'agents_workflow_complete_wait_invalid_status',
			sprintf( 'Completion status `%s` is not terminal (expected `succeeded` or `failed`).', $status )
		);
	}

	$suspension['wait_claim'] = array( 'phase' => 'consumed' );
	$metadata                 = $result->get_metadata();
	$metadata['_suspension']  = $suspension;
	$result                   = $result->with( array( 'metadata' => $metadata ) );

	$step_index  = is_numeric( $suspension['step_index'] ?? null ) ? (int) $suspension['step_index'] : 0;
	$step_output = WP_Agent_Workflow_Run_Result::STATUS_FAILED === $status
		? agents_workflow_wait_error_from_completion( $completion )
		: ( is_array( $completion['output'] ?? null ) ? $completion['output'] : array() );

	$result  = agents_workflow_splice_step_output( $result, $step_index, $step_output );
	$updated = agents_workflow_update_reconcile_state( $recorder, $result, 'commit an await wait completion' );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	return array(
		'action' => 'resume',
		'result' => $result,
	);
}

/**
 * Build the `failed` step output from a completion's `error` payload.
 *
 * @since 0.15.0
 *
 * @param array<string,mixed> $completion `{ status, error? }`.
 */
function agents_workflow_wait_error_from_completion( array $completion ): \WP_Error {
	$error = is_array( $completion['error'] ?? null ) ? $completion['error'] : array();
	$code  = agents_workflow_string( $error['code'] ?? '' );
	return new \WP_Error(
		'' !== $code ? $code : 'workflow_wait_failed',
		'' !== agents_workflow_string( $error['message'] ?? '' ) ? agents_workflow_string( $error['message'] ) : 'The awaited external job failed.',
		$error['data'] ?? null
	);
}

/**
 * Permission gate for `agents/complete-workflow-wait`. Same default as the
 * other workflow control abilities: `manage_options`. Consumers with their own
 * auth model (an HMAC-signed webhook, a scheduled-action context) widen via
 * the filter — mirroring {@see agents_workflow_run_cancel_permission()}.
 *
 * @since 0.15.0
 *
 * @param array<string,mixed> $input Ability input.
 */
function agents_workflow_complete_wait_permission( array $input ): bool {
	$allowed = function_exists( 'current_user_can' ) ? current_user_can( 'manage_options' ) : false;
	return (bool) apply_filters( 'agents_workflow_complete_wait_permission', $allowed, $input );
}

/** @return array<string,mixed> */
function agents_workflow_complete_wait_input_schema(): array {
	return array(
		'type'       => 'object',
		'required'   => array( 'run_id', 'wait_id', 'completion' ),
		'properties' => array(
			'run_id'     => array( 'type' => 'string' ),
			'wait_id'    => array( 'type' => 'string' ),
			'runtime'    => array(
				'type'        => 'string',
				'description' => 'Owning runtime key of the run. Scopes recorder resolution to the runtime that owns the run (#567). Omit for legacy runs started before runtime attribution existed.',
			),
			'completion' => array(
				'type'        => 'object',
				'description' => 'Completion envelope: { status: succeeded|failed, output?: object, error?: { code, message, data? } }.',
				'required'    => array( 'status' ),
				'properties'  => array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'succeeded', 'failed' ),
					),
					'output' => array( 'type' => 'object' ),
					'error'  => array( 'type' => 'object' ),
				),
			),
		),
	);
}

// ── Optional durable timeout backstop ──────────────────────────────────────

/**
 * Schedule the `await` wait's optional durable timeout when Action Scheduler
 * is present. Hooked on the generic `wp_agent_workflow_run_suspended` action;
 * every OTHER suspension kind (or an `await` with no `timeout_at`) is a no-op.
 *
 * @since 0.15.0
 *
 * @param mixed $result     Expected {@see WP_Agent_Workflow_Run_Result}.
 * @param mixed $suspension Expected suspension frame array.
 */
add_action(
	'wp_agent_workflow_run_suspended',
	static function ( $result, $suspension ): void {
		if ( ! $result instanceof WP_Agent_Workflow_Run_Result || ! is_array( $suspension ) ) {
			return;
		}
		agents_workflow_maybe_schedule_wait_timeout( $result, \AgentsAPI\AI\WP_Agent_Run_Control::string_keyed_array( $suspension ) );
	},
	10,
	2
);

/**
 * @since 0.15.0
 *
 * @param array<string,mixed> $suspension Suspension frame.
 */
function agents_workflow_maybe_schedule_wait_timeout( WP_Agent_Workflow_Run_Result $result, array $suspension ): void {
	if ( 'await' !== agents_workflow_string( $suspension['kind'] ?? '' ) ) {
		return;
	}
	$timeout_at = $suspension['timeout_at'] ?? null;
	if ( ! is_numeric( $timeout_at ) ) {
		return;
	}
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		// No Action Scheduler: the timeout is a best-effort backstop and simply
		// never fires — the wait can still be completed (or never is) exactly
		// like any other await. Mirrors the substrate's existing "no AS → no
		// async continuation" degradation for the parallel suspend/resume model.
		return;
	}

	$run_id  = $result->get_run_id();
	$payload = array(
		'run_id'     => $run_id,
		'wait_id'    => agents_workflow_string( $suspension['wait_id'] ?? '' ),
		'generation' => agents_workflow_string( $suspension['generation'] ?? '' ),
		'runtime'    => agents_workflow_string( $suspension['runtime'] ?? '' ),
	);

	try {
		as_schedule_single_action(
			(int) $timeout_at,
			AGENTS_WORKFLOW_AWAIT_TIMEOUT_HOOK,
			array( $payload ),
			agents_workflow_await_timeout_group( $run_id )
		);
	} catch ( \Throwable $error ) {
		// Scheduling failure is non-fatal: the suspend itself already
		// succeeded and durably persisted; the wait can still be completed (or
		// manually failed/cancelled) without a timeout backstop.
		unset( $error );
	}
}

/**
 * The Action Scheduler group an `await` timeout is scheduled under.
 *
 * Deliberately the SAME `agents-api-run-{md5(run_id)}` convention
 * {@see WP_Agent_Workflow_Action_Scheduler_Branch_Executor::group_for_run()}
 * uses for the (unrelated) parallel branch/resume actions — a per-run group
 * an operator or a run-scoped cleanup routine can unschedule by group without
 * this generic await primitive depending on that executor class. Duplicated
 * on purpose: one short, stable string literal is cheaper than a cross-file
 * coupling from a generic wait primitive to a specific fanout executor.
 *
 * @since 0.15.0
 */
function agents_workflow_await_timeout_group( string $run_id ): string {
	return '' !== $run_id ? 'agents-api-run-' . md5( $run_id ) : '';
}

add_action(
	AGENTS_WORKFLOW_AWAIT_TIMEOUT_HOOK,
	/**
	 * @param array<string,mixed> $payload Action payload: { run_id, wait_id, generation, runtime }.
	 */
	static function ( $payload = array() ): void {
		agents_workflow_run_wait_timeout_action( is_array( $payload ) ? \AgentsAPI\AI\WP_Agent_Run_Control::string_keyed_array( $payload ) : array() );
	},
	10,
	1
);

/**
 * The timeout action callback. Completes the wait as `failed` with
 * `workflow_wait_timeout` through the SAME exactly-once locked path a real
 * completion uses, fenced to the exact suspension instance via `generation`.
 *
 * @since 0.15.0
 *
 * @param array<string,mixed> $payload Action payload: { run_id, wait_id, generation, runtime }.
 */
function agents_workflow_run_wait_timeout_action( array $payload ): void {
	$run_id     = agents_workflow_string( $payload['run_id'] ?? '' );
	$wait_id    = agents_workflow_string( $payload['wait_id'] ?? '' );
	$generation = agents_workflow_string( $payload['generation'] ?? '' );
	$runtime    = agents_workflow_string( $payload['runtime'] ?? '' );
	if ( '' === $run_id || '' === $wait_id ) {
		return;
	}

	$recorder = agents_workflow_resolve_recorder( $runtime, $run_id );
	if ( null === $recorder ) {
		return;
	}

	agents_workflow_complete_wait_with_recorder(
		$recorder,
		$run_id,
		$wait_id,
		'' !== $generation ? $generation : null,
		array(
			'status' => WP_Agent_Workflow_Run_Result::STATUS_FAILED,
			'error'  => array(
				'code'    => 'workflow_wait_timeout',
				'message' => 'The awaited external job did not complete before its timeout.',
			),
		)
	);
}
