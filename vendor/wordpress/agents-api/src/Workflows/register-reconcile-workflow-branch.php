<?php
/**
 * The single reconcile entry point for the workflow suspend/resume model.
 *
 * Every branch executor drives ONE canonical function when a branch finishes:
 * {@see agents_reconcile_workflow_branch()}. It merges the completed branch
 * into the suspended run's frame and, once every branch is terminal, runs the
 * aggregate plan and resumes the run from the suspended step. The function is
 * also registered as the `agents/reconcile-workflow-branch` ability so it is
 * reachable uniformly via REST / abilities, mirroring the existing
 * `agents/*-workflow-run` control abilities.
 *
 * Core owns the state machine (merge → all-terminal? → aggregate → resume);
 * the executor owns only the mechanism that runs branches and calls back here.
 *
 * @package AgentsAPI
 * @since   0.5.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

const AGENTS_RECONCILE_WORKFLOW_BRANCH_ABILITY = 'agents/reconcile-workflow-branch';

add_action(
	'wp_abilities_api_init',
	static function (): void {
		if ( wp_has_ability( AGENTS_RECONCILE_WORKFLOW_BRANCH_ABILITY ) ) {
			return;
		}

		wp_register_ability(
			AGENTS_RECONCILE_WORKFLOW_BRANCH_ABILITY,
			array(
				'label'               => 'Reconcile Workflow Branch',
				'description'         => 'Reconcile a single completed parallel branch into a suspended workflow run and, when it was the last outstanding branch, aggregate + resume the run.',
				'category'            => 'agents-api',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'run_id', 'handle_id', 'branch_result' ),
					'properties' => array(
						'run_id'        => array( 'type' => 'string' ),
						'handle_id'     => array( 'type' => 'string' ),
						'branch_result' => array(
							'type'        => 'object',
							'description' => 'BranchResult: { key, status, output, steps?, error? }.',
						),
					),
				),
				'output_schema'       => agents_run_workflow_output_schema(),
				'execute_callback'    => __NAMESPACE__ . '\\agents_reconcile_workflow_branch_ability',
				'permission_callback' => __NAMESPACE__ . '\\agents_workflow_run_cancel_permission',
				'meta'                => array(
					'show_in_rest' => true,
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
 * Ability wrapper for {@see agents_reconcile_workflow_branch()}. Returns the
 * (possibly still-suspended) run's canonical output array, or a WP_Error.
 *
 * @since 0.5.0
 *
 * @param array<string,mixed> $input Ability input.
 * @return array<string,mixed>|\WP_Error
 */
function agents_reconcile_workflow_branch_ability( array $input ) {
	$run_id        = agents_workflow_string( $input['run_id'] ?? '' );
	$handle_id     = agents_workflow_string( $input['handle_id'] ?? '' );
	$branch_result = is_array( $input['branch_result'] ?? null ) ? \AgentsAPI\AI\WP_Agent_Run_Control::string_keyed_array( $input['branch_result'] ) : array();

	if ( '' === $run_id || '' === $handle_id ) {
		return new \WP_Error( 'agents_reconcile_workflow_branch_invalid_input', 'run_id and handle_id are required.' );
	}

	$result = agents_reconcile_workflow_branch( $run_id, $handle_id, $branch_result );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $result->to_array();
}

/**
 * Reconcile a single completed branch into a suspended run and, if it was the
 * last outstanding branch, aggregate + resume.
 *
 * Algorithm (design §3.4):
 *   1. Load the suspended run via the recorder (guard: must be SUSPENDED;
 *      idempotent — a duplicate reconcile for an already-recorded terminal
 *      handle is a no-op that returns the current run).
 *   2. Merge the branch result into `metadata._suspension.completed[handle_id]`
 *      and flip that handle's status. Persist.
 *   3. If NOT all handles terminal → return the still-suspended run.
 *   4. If all terminal → run the aggregate plan, splice the parallel step's
 *      final output into that step's record, then resume the step loop from
 *      `step_index + 1`.
 *
 * CONCURRENCY. Steps 1–3 are a read-modify-write on the shared per-run frame's
 * `completed[]` map. When N branches finish CONCURRENTLY in N separate processes
 * (the async Action Scheduler path), two reconciles reading the frame before
 * either writes would lose an update — the later write clobbers the earlier
 * merge, the frame never reaches all-terminal, and the run hangs SUSPENDED
 * forever (observed in a real MySQL fanout A/B). So the whole load → merge →
 * all-terminal? → aggregate → resume-dispatch section runs under a per-run
 * cross-process lock ({@see agents_workflow_reconcile_with_lock()}), serializing
 * concurrent reconciles so each reads the frame AFTER the previous one committed.
 * The lock is table-free (`add_option()` CAS) and pluggable via the
 * `wp_agent_workflow_reconcile_lock` filter. AS's atomic action-claim remains the
 * resume-DEDUP guard; the lock closes the completed[]-ACCOUNTING gap it never
 * covered.
 *
 * @since 0.5.0
 *
 * @param string              $run_id        Suspended run id.
 * @param string              $handle_id     The completed branch's handle id.
 * @param array<string,mixed> $branch_result BranchResult: { key, status, output, steps?, error? }.
 * @return WP_Agent_Workflow_Run_Result|\WP_Error The (possibly still-suspended) run.
 */
function agents_reconcile_workflow_branch( string $run_id, string $handle_id, array $branch_result ) {
	$recorder = agents_workflow_resolve_recorder();
	if ( null === $recorder ) {
		return new \WP_Error( 'agents_reconcile_workflow_branch_no_recorder', 'A recorder is required to reconcile a suspended run. Register one via the `wp_agent_workflow_run_recorder` filter.' );
	}

	// Serialize the merge → all-terminal? → aggregate → resume-dispatch section
	// per run so concurrent reconciles from separate processes cannot lose a
	// completion. Every recorder read below happens INSIDE the lock, so each
	// reconcile observes the previous one's committed frame.
	return agents_workflow_reconcile_with_lock(
		$run_id,
		static function () use ( $recorder, $run_id, $handle_id, $branch_result ) {
			return agents_reconcile_workflow_branch_locked( $recorder, $run_id, $handle_id, $branch_result );
		}
	);
}

/**
 * The reconcile critical section, run under the per-run lock. Loads the run FRESH
 * (inside the lock), merges the branch, decides all-terminal, and aggregates +
 * dispatches resume when it was the last branch. Extracted from
 * {@see agents_reconcile_workflow_branch()} so the lock wraps exactly the
 * load-modify-decide window and nothing more.
 *
 * @since 0.5.0
 *
 * @param WP_Agent_Workflow_Run_Recorder $recorder      Resolved recorder.
 * @param string                         $run_id        Suspended run id.
 * @param string                         $handle_id     The completed branch's handle id.
 * @param array<string,mixed>            $branch_result BranchResult.
 * @return WP_Agent_Workflow_Run_Result|\WP_Error
 */
function agents_reconcile_workflow_branch_locked( WP_Agent_Workflow_Run_Recorder $recorder, string $run_id, string $handle_id, array $branch_result ) {
	$result = $recorder->find( $run_id );
	if ( null === $result ) {
		return new \WP_Error( 'agents_reconcile_workflow_branch_not_found', sprintf( 'No suspended run was found for run_id `%s`.', $run_id ) );
	}
	if ( ! $result->is_suspended() ) {
		// Idempotency: the run already resumed (or was never suspended). A
		// late/duplicate reconcile is a harmless no-op.
		return $result;
	}

	$suspension = $result->get_suspension();
	/** @var array<int,mixed> $handles */
	$handles = is_array( $suspension['handles'] ?? null ) ? array_values( $suspension['handles'] ) : array();
	/** @var array<string,mixed> $completed */
	$completed = is_array( $suspension['completed'] ?? null ) ? \AgentsAPI\AI\WP_Agent_Run_Control::string_keyed_array( $suspension['completed'] ) : array();

	// Idempotency: a handle already recorded terminal does not re-merge or
	// re-resume. Return the current run untouched.
	if ( isset( $completed[ $handle_id ] ) ) {
		return $result;
	}

	$status = agents_workflow_string( $branch_result['status'] ?? '' );
	$status = ( WP_Agent_Workflow_Run_Result::STATUS_FAILED === $status ) ? WP_Agent_Workflow_Run_Result::STATUS_FAILED : WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED;

	$completed[ $handle_id ] = array(
		'key'    => agents_workflow_string( $branch_result['key'] ?? '' ),
		'status' => $status,
		'output' => $branch_result['output'] ?? null,
		'steps'  => is_array( $branch_result['steps'] ?? null ) ? $branch_result['steps'] : array(),
		'error'  => is_array( $branch_result['error'] ?? null ) ? $branch_result['error'] : null,
		'item'   => $branch_result['item'] ?? null,
	);

	// Flip the matching handle's status.
	foreach ( $handles as $index => $handle ) {
		if ( is_array( $handle ) && agents_workflow_string( $handle['id'] ?? '' ) === $handle_id ) {
			$handle['status']    = $status;
			$handles[ $index ]   = $handle;
		}
	}

	$suspension['handles']   = $handles;
	$suspension['completed'] = $completed;

	$metadata                 = $result->get_metadata();
	$metadata['_suspension']  = $suspension;
	$result                   = $result->with( array( 'metadata' => $metadata ) );
	$recorder->update( $result );

	// Not all terminal yet → wait for more reconcile calls.
	if ( count( $completed ) < count( $handles ) ) {
		return $result;
	}

	// All branches terminal. Was a REQUIRED branch failed? A required-branch
	// failure fails the parallel step, which re-enters the failure path on
	// resume (mirrors the sync `run_role_branch()` required-branch rule).
	$branch_results  = agents_workflow_branch_results_by_key( $completed );
	$required_failed = agents_workflow_required_branch_failed( $suspension, $completed );

	$step_index = is_numeric( $suspension['step_index'] ?? null ) ? (int) $suspension['step_index'] : 0;
	$aggregate  = is_array( $suspension['aggregate'] ?? null ) ? \AgentsAPI\AI\WP_Agent_Run_Control::string_keyed_array( $suspension['aggregate'] ) : array();
	$handlers   = agents_workflow_resolve_step_handlers();

	if ( $required_failed ) {
		$step_output = new \WP_Error( 'workflow_parallel_required_branch_failed', 'A required parallel branch failed during out-of-band execution.' );
	} else {
		$step_output = WP_Agent_Workflow_Runner::aggregate_branch_results( $aggregate, $branch_results, $handlers );
	}

	// Splice the parallel step's final output (or failure) into its record so
	// resume sees a terminal step and downstream `${steps.<id>.output}`
	// bindings resolve against the aggregated result. The run is still
	// SUSPENDED at this point (the frame is intact); resume() is what clears it.
	$result = agents_workflow_splice_step_output( $result, $step_index, $step_output );
	$recorder->update( $result );

	// The "all terminal → resume" transition is the ONE place two branches
	// finishing near-simultaneously in separate processes can race. Rather than
	// hand-roll a cross-process lock (unsafe / forbidden), the transition is
	// pluggable: the owning executor may DEFER resume to an atomically-claimed
	// out-of-band action so exactly one resume runs. The default (Phase 1, and
	// any synchronous / in-process executor) resumes inline right here.
	//
	// A deferring handler enqueues its claimed resume action and returns true;
	// reconcile then returns the aggregate-spliced-but-still-SUSPENDED run. The
	// deferred handler, when its claimed action fires, re-checks the run is
	// still SUSPENDED and calls resume() exactly once (§3.4, §4.3).
	if ( agents_workflow_defer_resume( $run_id, $result ) ) {
		return $result;
	}

	// Resume the step loop from step_index + 1 inline. resume() clears the
	// suspension frame and continues (or fails) from the aggregated output.
	$runner = agents_workflow_resolve_runner( $recorder );
	return $runner->resume( $run_id );
}

/**
 * Ask whether the "all branches terminal → resume" transition should be
 * deferred to an out-of-band, atomically-claimed action rather than resumed
 * inline. This is the single seam that keeps the AS async path from duplicating
 * any reconcile / aggregate logic: reconcile still owns merge → all-terminal? →
 * aggregate → splice; only the FINAL resume dispatch is pluggable.
 *
 * The default is `false` — resume inline (Phase 1 behavior, and correct for any
 * synchronous / in-process executor). The Action Scheduler executor hooks the
 * `wp_agent_workflow_resume_dispatch` filter to enqueue a claimed RESUME action
 * and return `true`, so exactly one resume runs even under a simultaneous
 * multi-branch finish (AS's atomic action-claim is the cross-process guard).
 *
 * @since 0.5.0
 *
 * @param string                       $run_id The suspended run id.
 * @param WP_Agent_Workflow_Run_Result $result The aggregate-spliced, still-suspended run.
 * @return bool True when a handler claimed the resume (reconcile must NOT resume inline).
 */
function agents_workflow_defer_resume( string $run_id, WP_Agent_Workflow_Run_Result $result ): bool {
	$suspension  = $result->get_suspension();
	$executor_id = agents_workflow_string( $suspension['executor_id'] ?? '' );

	/**
	 * Filter whether resume is deferred to an out-of-band claimed action.
	 *
	 * A handler that owns the run's executor enqueues its atomically-claimed
	 * resume action and returns `true`; core then returns the still-suspended
	 * run and the claimed action performs the one-and-only resume. Returning a
	 * falsey value (the default) resumes inline in the reconcile request.
	 *
	 * @since 0.5.0
	 *
	 * @param bool                         $deferred    Whether resume is deferred. Default false.
	 * @param string                       $run_id      The suspended run id.
	 * @param string                       $executor_id The frame's owning executor id.
	 * @param WP_Agent_Workflow_Run_Result $result      The aggregate-spliced, still-suspended run.
	 */
	return (bool) apply_filters( 'wp_agent_workflow_resume_dispatch', false, $run_id, $executor_id, $result );
}

/**
 * Run the reconcile critical section under a per-run cross-process lock so
 * concurrent branch completions cannot lose an update on the frame's
 * `completed[]` map (the silent-stall root cause). The lock is table-free and
 * pluggable: a consumer may replace it via the `wp_agent_workflow_reconcile_lock`
 * filter (e.g. a MySQL `GET_LOCK` or Redis lock); the default is an
 * `add_option()`-backed atomic CAS lock ({@see WP_Agent_Workflow_Reconcile_Lock}).
 *
 * A filter override receives ( $run_id, $critical ) and MUST invoke `$critical`
 * exactly once under mutual exclusion for `$run_id`, returning its result. A
 * falsey filter return means "no override" and the default lock is used.
 *
 * @since 0.5.0
 *
 * @template T
 * @param string       $run_id   Suspended run id whose reconcile is serialized.
 * @param callable():T $critical The load → merge → decide → resume-dispatch section.
 * @return T
 */
function agents_workflow_reconcile_with_lock( string $run_id, callable $critical ) {
	/**
	 * Filter the per-run reconcile lock. Return the critical section's result to
	 * take over serialization with a custom primitive; return a falsey value (the
	 * default) to use the built-in `add_option()` CAS lock.
	 *
	 * @since 0.5.0
	 *
	 * @param mixed        $override No override by default (null → built-in lock runs).
	 * @param string       $run_id   The suspended run id.
	 * @param callable():T $critical The critical section to run under mutual exclusion.
	 */
	$override = apply_filters( 'wp_agent_workflow_reconcile_lock', null, $run_id, $critical );
	if ( null !== $override && false !== $override ) {
		return $override;
	}

	return WP_Agent_Workflow_Reconcile_Lock::with_lock( $run_id, $critical );
}

/**
 * Collect reconciled BranchResult[] keyed by branch key (role or index) from
 * the frame's `completed` map.
 *
 * @since 0.5.0
 *
 * @param array<string,mixed> $completed Frame completed map keyed by handle id.
 * @return array<string,mixed>
 */
function agents_workflow_branch_results_by_key( array $completed ): array {
	$by_key = array();
	foreach ( $completed as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$key            = agents_workflow_string( $entry['key'] ?? '' );
		$by_key[ $key ] = $entry;
	}
	return $by_key;
}

/**
 * Whether any REQUIRED branch reconciled as failed. A branch is required when
 * its handle carries `required` truthy OR the aggregator plan's branch spec
 * marked it required; the executor stamps `required` on the handle at dispatch
 * so reconcile can decide without re-reading the spec.
 *
 * @since 0.5.0
 *
 * @param array<string,mixed> $suspension The suspension frame.
 * @param array<string,mixed> $completed  Frame completed map keyed by handle id.
 * @return bool
 */
function agents_workflow_required_branch_failed( array $suspension, array $completed ): bool {
	$handles         = is_array( $suspension['handles'] ?? null ) ? $suspension['handles'] : array();
	$required_by_key = array();
	foreach ( $handles as $handle ) {
		if ( is_array( $handle ) ) {
			$required_by_key[ agents_workflow_string( $handle['key'] ?? '' ) ] = ! empty( $handle['required'] );
		}
	}

	foreach ( $completed as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		if ( WP_Agent_Workflow_Run_Result::STATUS_FAILED !== agents_workflow_string( $entry['status'] ?? '' ) ) {
			continue;
		}
		$key = agents_workflow_string( $entry['key'] ?? '' );
		if ( ! empty( $required_by_key[ $key ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Splice a terminal output (array) or failure (WP_Error) into the suspended
 * step's record so the resumed run sees a terminal step and the aggregated
 * output flows to downstream bindings.
 *
 * @since 0.5.0
 *
 * @param WP_Agent_Workflow_Run_Result $result     The suspended run.
 * @param int                          $step_index The suspended step's index.
 * @param array<mixed>|\WP_Error       $output     Aggregated output or a failure.
 * @return WP_Agent_Workflow_Run_Result
 */
function agents_workflow_splice_step_output( WP_Agent_Workflow_Run_Result $result, int $step_index, $output ): WP_Agent_Workflow_Run_Result {
	$steps = $result->get_steps();
	if ( ! isset( $steps[ $step_index ] ) || ! is_array( $steps[ $step_index ] ) ) {
		return $result;
	}

	$record = $steps[ $step_index ];
	unset( $record['suspend'] );

	if ( is_wp_error( $output ) ) {
		$record['status'] = WP_Agent_Workflow_Run_Result::STATUS_FAILED;
		$record['output'] = null;
		$record['error']  = array(
			'code'    => $output->get_error_code(),
			'message' => $output->get_error_message(),
			'data'    => $output->get_error_data(),
		);
	} else {
		$record['status'] = WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED;
		$record['output'] = $output;
		unset( $record['error'] );
	}
	$record['ended_at']    = time();
	$steps[ $step_index ]  = $record;

	// Also seed the resumed context snapshot's `steps` map so downstream
	// `${steps.<id>.output}` bindings resolve against the aggregated output.
	$suspension = $result->get_suspension();
	$step_id    = agents_workflow_string( $record['id'] ?? '' );
	if ( '' !== $step_id && is_array( $suspension['context_snapshot'] ?? null ) ) {
		$snapshot_steps = is_array( $suspension['context_snapshot']['steps'] ?? null ) ? $suspension['context_snapshot']['steps'] : array();
		if ( ! is_wp_error( $output ) ) {
			$snapshot_steps[ $step_id ] = array( 'output' => $output );
		}
		$suspension['context_snapshot']['steps'] = $snapshot_steps;
		$metadata                                = $result->get_metadata();
		$metadata['_suspension']                 = $suspension;
		$result                                  = $result->with( array( 'metadata' => $metadata ) );
	}

	return $result->with( array( 'steps' => $steps ) );
}

/**
 * Resolve the workflow run recorder used by reconcile / resume. Consumers own
 * persistence, so the recorder is supplied via a filter — the same seam a
 * consumer already uses to wire a runtime.
 *
 * @since 0.5.0
 */
function agents_workflow_resolve_recorder(): ?WP_Agent_Workflow_Run_Recorder {
	/**
	 * Filter the workflow run recorder used to reload + resume suspended runs.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Agent_Workflow_Run_Recorder|null $recorder Currently resolved recorder.
	 */
	$recorder = apply_filters( 'wp_agent_workflow_run_recorder', null );
	return $recorder instanceof WP_Agent_Workflow_Run_Recorder ? $recorder : null;
}

/**
 * Resolve the runner used to resume a suspended run. Defaults to a fresh runner
 * bound to the supplied recorder; a consumer may override to inject its own
 * handler map or subclass.
 *
 * @since 0.5.0
 */
function agents_workflow_resolve_runner( WP_Agent_Workflow_Run_Recorder $recorder ): WP_Agent_Workflow_Runner {
	/**
	 * Filter the runner used to resume a suspended workflow run.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Agent_Workflow_Runner|null       $runner   Currently resolved runner.
	 * @param WP_Agent_Workflow_Run_Recorder      $recorder The resolved recorder.
	 */
	$runner = apply_filters( 'wp_agent_workflow_resume_runner', null, $recorder );
	return $runner instanceof WP_Agent_Workflow_Runner ? $runner : new WP_Agent_Workflow_Runner( $recorder );
}

/**
 * Resolve the step-type handler map for aggregate execution during reconcile.
 *
 * @since 0.5.0
 *
 * @return array<string,mixed>
 */
function agents_workflow_resolve_step_handlers(): array {
	/** @var array<string,mixed> $handlers */
	$handlers = (array) apply_filters(
		'wp_agent_workflow_step_handlers',
		array(
			'ability'  => array( WP_Agent_Workflow_Runner::class, 'default_ability_handler' ),
			'agent'    => array( WP_Agent_Workflow_Runner::class, 'default_agent_handler' ),
			'foreach'  => array( WP_Agent_Workflow_Runner::class, 'default_foreach_handler' ),
			'parallel' => array( WP_Agent_Workflow_Runner::class, 'default_parallel_handler' ),
		)
	);

	return $handlers;
}
