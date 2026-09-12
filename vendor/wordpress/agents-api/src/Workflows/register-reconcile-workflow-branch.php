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
 * Core owns the state machine (merge → claim → aggregate → fenced commit → resume);
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
 *   4. If all terminal → enqueue the executor's durable aggregate continuation
 *      and persist its generation-bound owner. The claimed continuation records
 *      `running`, aggregates outside the lock, commits, then dispatches resume.
 *
 * CONCURRENCY. Steps 1–3 are a read-modify-write on the shared per-run frame's
 * `completed[]` map. When N branches finish CONCURRENTLY in N separate processes
 * (the async Action Scheduler path), two reconciles reading the frame before
 * either writes would lose an update — the later write clobbers the earlier
 * merge, the frame never reaches all-terminal, and the run hangs SUSPENDED
 * forever (observed in a real MySQL fanout A/B). Short recorder transitions run
 * under a per-run cross-process lock ({@see agents_workflow_reconcile_with_lock()}).
 * For Action Scheduler runs, a dedicated atomically claimed action owns aggregate
 * execution and its failed-action lifecycle fences crashes. Duplicate reconcile
 * delivery observes `queued` or `running` and cannot execute aggregation. The
 * aggregate result is committed only after a fresh read proves the action token
 * still owns the same suspension generation.
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

	$transition = agents_workflow_reconcile_with_lock(
		$run_id,
		static function () use ( $recorder, $run_id, $handle_id, $branch_result ) {
			return agents_reconcile_workflow_branch_locked( $recorder, $run_id, $handle_id, $branch_result );
		}
	);
	if ( is_wp_error( $transition ) || $transition instanceof WP_Agent_Workflow_Run_Result ) {
		return $transition;
	}
	if ( 'resume' === $transition['action'] ) {
		return agents_workflow_resume_reconcile_continuation( $recorder, $run_id, $transition['result'] );
	}

	$required_failed = ! empty( $transition['required_failed'] );
	if ( $required_failed ) {
		$step_output = new \WP_Error( 'workflow_parallel_required_branch_failed', 'A required parallel branch failed during out-of-band execution.' );
	} else {
		$handlers    = agents_workflow_resolve_step_handlers();
		$step_output = WP_Agent_Workflow_Runner::aggregate_branch_results( $transition['aggregate'], $transition['branch_results'], $handlers );
	}

	$commit = agents_workflow_reconcile_with_lock(
		$run_id,
		static function () use ( $recorder, $run_id, $transition, $step_output ) {
			return agents_workflow_commit_reconcile_claim(
				$recorder,
				$run_id,
				$transition['owner_token'],
				$transition['generation'],
				$transition['step_index'],
				$step_output
			);
		}
	);
	if ( is_wp_error( $commit ) || $commit instanceof WP_Agent_Workflow_Run_Result ) {
		return $commit;
	}

	return agents_workflow_resume_reconcile_continuation( $recorder, $run_id, $commit['result'] );
}

/**
 * The reconcile state transition run under the per-run lock. It loads the run,
 * merges one branch, and claims aggregation for the suspension generation when
 * all branches are terminal. Aggregation itself runs after this function returns
 * and the lock has been released.
 *
 * @since 0.5.0
 *
 * @param WP_Agent_Workflow_Run_Recorder $recorder      Resolved recorder.
 * @param string                         $run_id        Suspended run id.
 * @param string                         $handle_id     The completed branch's handle id.
 * @param array<string,mixed>            $branch_result BranchResult.
 * @return WP_Agent_Workflow_Run_Result|array{action:'aggregate',owner_token:string,generation:string,step_index:int,aggregate:array<string,mixed>,branch_results:array<string,mixed>,required_failed:bool}|array{action:'resume',result:WP_Agent_Workflow_Run_Result}|\WP_Error
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

	if ( is_array( $suspension['reconcile_claim'] ?? null ) ) {
		return agents_workflow_advance_reconcile_continuation_locked( $recorder, $result );
	}

	// Bind the completion to server-stored suspension state: only a handle id
	// that was actually recorded when the run suspended may complete. A
	// caller-asserted handle id that is not among the stored handles is rejected
	// (fail closed) so a forged/unknown id cannot inflate the completed[]
	// accounting and prematurely trip the all-terminal gate below.
	if ( ! isset( $completed[ $handle_id ] ) ) {
		$stored_handle = null;
		foreach ( $handles as $handle ) {
			if ( is_array( $handle ) && agents_workflow_string( $handle['id'] ?? '' ) === $handle_id ) {
				$stored_handle = $handle;
				break;
			}
		}
		if ( null === $stored_handle ) {
			return new \WP_Error(
				'agents_reconcile_workflow_branch_unknown_handle',
				sprintf( 'Handle id `%s` is not a known suspended branch of run `%s`.', $handle_id, $run_id )
			);
		}

	// Bind the completion to the handle's stored key too: the caller may not
	// remap its output onto a different branch's aggregate key. An empty stored
	// key preserves compatibility with frames that did not stamp one; otherwise
	// the stored key remains authoritative when the caller omits it.
		$stored_key   = agents_workflow_string( $stored_handle['key'] ?? '' );
		$asserted_key = agents_workflow_string( $branch_result['key'] ?? '' );
		if ( '' !== $stored_key && '' !== $asserted_key && $stored_key !== $asserted_key ) {
			return new \WP_Error(
				'agents_reconcile_workflow_branch_key_mismatch',
				sprintf( 'branch_result key `%s` does not match the stored key `%s` for handle `%s`.', $asserted_key, $stored_key, $handle_id )
			);
		}

		$status = agents_workflow_string( $branch_result['status'] ?? '' );
		if ( WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED !== $status && WP_Agent_Workflow_Run_Result::STATUS_FAILED !== $status ) {
			return new \WP_Error(
				'agents_reconcile_workflow_branch_invalid_status',
				sprintf( 'Branch result status `%s` is not terminal for handle `%s`.', $status, $handle_id )
			);
		}

		$completed[ $handle_id ] = array(
			'key'    => '' !== $stored_key ? $stored_key : $asserted_key,
			'status' => $status,
			'output' => $branch_result['output'] ?? null,
			'steps'  => is_array( $branch_result['steps'] ?? null ) ? $branch_result['steps'] : array(),
			'error'  => is_array( $branch_result['error'] ?? null ) ? $branch_result['error'] : null,
			'item'   => $branch_result['item'] ?? null,
		);

	// Flip the matching handle's status.
		foreach ( $handles as $index => $handle ) {
			if ( is_array( $handle ) && agents_workflow_string( $handle['id'] ?? '' ) === $handle_id ) {
				$handle['status']  = $status;
				$handles[ $index ] = $handle;
			}
		}
	}

	$suspension['handles']   = $handles;
	$suspension['completed'] = $completed;

	$transition = null;
	if ( count( $completed ) >= count( $handles ) ) {
		$generation = agents_workflow_suspension_generation( $suspension );
		$owner_token = agents_workflow_reconcile_claim_token();
		$action_id = agents_workflow_dispatch_aggregate_continuation( $run_id, $suspension, $generation, $owner_token );
		if ( is_int( $action_id ) && $action_id > 0 ) {
			$suspension['reconcile_claim'] = array(
				'phase'       => 'queued',
				'generation'  => $generation,
				'owner_token' => $owner_token,
				'action_id'   => $action_id,
			);
		} elseif ( is_int( $action_id ) ) {
			$suspension['reconcile_claim'] = array(
				'phase'      => 'committed',
				'generation' => $generation,
			);
			$failed_metadata = $result->get_metadata();
			$failed_metadata['_suspension'] = $suspension;
			$result = agents_workflow_splice_step_output(
				$result->with( array( 'metadata' => $failed_metadata ) ),
				is_numeric( $suspension['step_index'] ?? null ) ? (int) $suspension['step_index'] : 0,
				new \WP_Error( 'workflow_parallel_aggregation_dispatch_failed', 'The durable aggregate continuation could not be enqueued.' )
			);
			$suspension = $result->get_suspension();
			$transition = array( 'action' => 'resume' );
		} else {
			$suspension['reconcile_claim'] = array(
				'phase'       => 'running',
				'generation'  => $generation,
				'owner_token' => $owner_token,
			);
			$transition = agents_workflow_reconcile_aggregate_transition( $suspension, $owner_token, $generation );
		}
	}

	$metadata                = $result->get_metadata();
	$metadata['_suspension'] = $suspension;
	$result                  = $result->with( array( 'metadata' => $metadata ) );
	$updated                 = agents_workflow_update_reconcile_state( $recorder, $result, 'record branch completion and continuation claim' );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	if ( null === $transition ) {
		return $result;
	}
	if ( 'resume' === $transition['action'] ) {
		return array(
			'action' => 'resume',
			'result' => $result,
		);
	}
	return $transition;
}

/**
 * Commit an aggregate result only while its generation-bound claim still owns
 * the suspended run. The fresh read is the fence that prevents an expired former
 * option-lock holder from overwriting newer recorder state.
 *
 * @since 0.5.0
 *
 * @param WP_Agent_Workflow_Run_Recorder $recorder    Resolved recorder.
 * @param string                         $run_id      Suspended run id.
 * @param string                         $claim_token Effect owner token elected before aggregation.
 * @param string                         $generation Suspension generation identity.
 * @param int                            $step_index  Suspended parallel step index.
 * @param array<mixed>|\WP_Error          $step_output Aggregated output or failure.
 * @return WP_Agent_Workflow_Run_Result|array{result:WP_Agent_Workflow_Run_Result}|array{action:'resume',result:WP_Agent_Workflow_Run_Result}|\WP_Error
 */
function agents_workflow_commit_reconcile_claim( WP_Agent_Workflow_Run_Recorder $recorder, string $run_id, string $claim_token, string $generation, int $step_index, $step_output ) {
	$result = $recorder->find( $run_id );
	if ( null === $result || ! $result->is_suspended() ) {
		return null === $result
			? new \WP_Error( 'agents_reconcile_workflow_branch_not_found', sprintf( 'No suspended run was found for run_id `%s`.', $run_id ) )
			: $result;
	}

	$suspension = $result->get_suspension();
	$claim      = is_array( $suspension['reconcile_claim'] ?? null ) ? $suspension['reconcile_claim'] : array();
	if (
		'running' !== agents_workflow_string( $claim['phase'] ?? '' ) ||
		! hash_equals( $claim_token, agents_workflow_string( $claim['owner_token'] ?? '' ) ) ||
		! hash_equals( $generation, agents_workflow_string( $claim['generation'] ?? '' ) ) ||
		! hash_equals( $generation, agents_workflow_suspension_generation( $suspension ) )
	) {
		return agents_workflow_advance_reconcile_continuation_locked( $recorder, $result );
	}

	$suspension['reconcile_claim'] = array(
		'phase'       => 'committed',
		'generation'  => $generation,
		'owner_token' => $claim_token,
	);
	$metadata = $result->get_metadata();
	$metadata['_suspension'] = $suspension;
	$result = $result->with( array( 'metadata' => $metadata ) );
	$result = agents_workflow_splice_step_output( $result, $step_index, $step_output );
	$updated = agents_workflow_update_reconcile_state( $recorder, $result, 'commit aggregate output' );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}
	return array( 'result' => $result );
}

/**
 * Run one durable aggregate continuation action. The action atomically moves its
 * matching `queued` generation to `running` before external effects, commits the
 * aggregate under the short lock, then dispatches resume.
 *
 * @param WP_Agent_Workflow_Run_Recorder $recorder   Resolved recorder.
 * @param string                         $run_id     Suspended run id.
 * @param string                         $generation  Suspension generation identity.
 * @param string                         $owner_token Aggregate action owner token.
 * @return WP_Agent_Workflow_Run_Result|\WP_Error
 */
function agents_workflow_run_aggregate_continuation( WP_Agent_Workflow_Run_Recorder $recorder, string $run_id, string $generation, string $owner_token ) {
	$transition = agents_workflow_reconcile_with_lock(
		$run_id,
		static function () use ( $recorder, $run_id, $generation, $owner_token ) {
			return agents_workflow_begin_aggregate_action_locked( $recorder, $run_id, $generation, $owner_token );
		}
	);
	if ( is_wp_error( $transition ) || $transition instanceof WP_Agent_Workflow_Run_Result ) {
		return $transition;
	}
	if ( 'resume' === $transition['action'] ) {
		return agents_workflow_resume_reconcile_continuation( $recorder, $run_id, $transition['result'] );
	}

	$step_output = ! empty( $transition['required_failed'] )
		? new \WP_Error( 'workflow_parallel_required_branch_failed', 'A required parallel branch failed during out-of-band execution.' )
		: WP_Agent_Workflow_Runner::aggregate_branch_results( $transition['aggregate'], $transition['branch_results'], agents_workflow_resolve_step_handlers() );
	$commit = agents_workflow_reconcile_with_lock(
		$run_id,
		static function () use ( $recorder, $run_id, $transition, $step_output ) {
			return agents_workflow_commit_reconcile_claim( $recorder, $run_id, $transition['owner_token'], $transition['generation'], $transition['step_index'], $step_output );
		}
	);
	if ( is_wp_error( $commit ) || $commit instanceof WP_Agent_Workflow_Run_Result ) {
		return $commit;
	}
	return agents_workflow_resume_reconcile_continuation( $recorder, $run_id, $commit['result'] );
}

/**
 * Begin a claimed aggregate action under the short lock.
 *
 * @return WP_Agent_Workflow_Run_Result|array{action:'aggregate',owner_token:string,generation:string,step_index:int,aggregate:array<string,mixed>,branch_results:array<string,mixed>,required_failed:bool}|array{action:'resume',result:WP_Agent_Workflow_Run_Result}|\WP_Error
 */
function agents_workflow_begin_aggregate_action_locked( WP_Agent_Workflow_Run_Recorder $recorder, string $run_id, string $generation, string $owner_token ) {
	$result = $recorder->find( $run_id );
	if ( null === $result ) {
		return new \WP_Error( 'agents_reconcile_workflow_branch_not_found', sprintf( 'No suspended run was found for run_id `%s`.', $run_id ) );
	}
	if ( ! $result->is_suspended() ) {
		return $result;
	}
	$suspension = $result->get_suspension();
	$claim      = is_array( $suspension['reconcile_claim'] ?? null ) ? $suspension['reconcile_claim'] : array();
	if ( 'committed' === agents_workflow_string( $claim['phase'] ?? '' ) && hash_equals( $generation, agents_workflow_string( $claim['generation'] ?? '' ) ) ) {
		return array(
			'action' => 'resume',
			'result' => $result,
		);
	}
	if (
		'queued' !== agents_workflow_string( $claim['phase'] ?? '' ) ||
		! hash_equals( $generation, agents_workflow_string( $claim['generation'] ?? '' ) ) ||
		! hash_equals( $owner_token, agents_workflow_string( $claim['owner_token'] ?? '' ) ) ||
		! hash_equals( $generation, agents_workflow_suspension_generation( $suspension ) )
	) {
		return $result;
	}

	$claim['phase'] = 'running';
	$suspension['reconcile_claim'] = $claim;
	$metadata = $result->get_metadata();
	$metadata['_suspension'] = $suspension;
	$result = $result->with( array( 'metadata' => $metadata ) );
	$updated = agents_workflow_update_reconcile_state( $recorder, $result, 'mark the aggregate action as running' );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}
	return agents_workflow_reconcile_aggregate_transition( $suspension, $owner_token, $generation );
}

/**
 * Return the aggregate inputs carried by one claimed suspension generation.
 *
 * @param array<string,mixed> $suspension Suspension frame.
 * @return array{action:'aggregate',owner_token:string,generation:string,step_index:int,aggregate:array<string,mixed>,branch_results:array<string,mixed>,required_failed:bool}
 */
function agents_workflow_reconcile_aggregate_transition( array $suspension, string $owner_token, string $generation ): array {
	$completed = is_array( $suspension['completed'] ?? null ) ? \AgentsAPI\AI\WP_Agent_Run_Control::string_keyed_array( $suspension['completed'] ) : array();
	return array(
		'action'          => 'aggregate',
		'owner_token'     => $owner_token,
		'generation'      => $generation,
		'step_index'      => is_numeric( $suspension['step_index'] ?? null ) ? (int) $suspension['step_index'] : 0,
		'aggregate'       => is_array( $suspension['aggregate'] ?? null ) ? \AgentsAPI\AI\WP_Agent_Run_Control::string_keyed_array( $suspension['aggregate'] ) : array(),
		'branch_results'  => agents_workflow_branch_results_by_key( $completed ),
		'required_failed' => agents_workflow_required_branch_failed( $suspension, $completed ),
	);
}

/**
 * Duplicate reconciles never take over queued or running aggregate actions.
 *
 * @return WP_Agent_Workflow_Run_Result|array{action:'resume',result:WP_Agent_Workflow_Run_Result}
 */
function agents_workflow_advance_reconcile_continuation_locked( WP_Agent_Workflow_Run_Recorder $recorder, WP_Agent_Workflow_Run_Result $result ) {
	unset( $recorder );
	$claim = $result->get_suspension()['reconcile_claim'] ?? array();
	if ( is_array( $claim ) && 'committed' === agents_workflow_string( $claim['phase'] ?? '' ) ) {
		return array( 'action' => 'resume', 'result' => $result );
	}
	return $result;
}

/**
 * Dispatch one executor-owned durable aggregate action, or null for inline fallback.
 *
 * @param array<string,mixed> $suspension Suspension frame.
 */
function agents_workflow_dispatch_aggregate_continuation( string $run_id, array $suspension, string $generation, string $owner_token, bool $recover_failure = false ): ?int {
	$executor_id = agents_workflow_string( $suspension['executor_id'] ?? '' );
	$action_id = apply_filters( 'wp_agent_workflow_aggregate_dispatch', null, $run_id, $executor_id, $generation, $owner_token, $recover_failure );
	return is_int( $action_id ) ? $action_id : null;
}

/**
 * Handle an aggregate action failure through its persisted phase.
 *
 * @return WP_Agent_Workflow_Run_Result|\WP_Error|null
 */
function agents_workflow_fail_aggregate_continuation( WP_Agent_Workflow_Run_Recorder $recorder, string $run_id, string $generation, string $owner_token, bool $from_durable_action = false ) {
	$transition = agents_workflow_reconcile_with_lock(
		$run_id,
		static function () use ( $recorder, $run_id, $generation, $owner_token ) {
			$result = $recorder->find( $run_id );
			if ( null === $result || ! $result->is_suspended() ) {
				return $result;
			}
			$claim = $result->get_suspension()['reconcile_claim'] ?? array();
			if ( ! is_array( $claim ) || ! hash_equals( $generation, agents_workflow_string( $claim['generation'] ?? '' ) ) || ! hash_equals( $owner_token, agents_workflow_string( $claim['owner_token'] ?? '' ) ) ) {
				return $result;
			}
			if ( 'queued' === agents_workflow_string( $claim['phase'] ?? '' ) ) {
				$action_id = agents_workflow_dispatch_aggregate_continuation( $run_id, $result->get_suspension(), $generation, $owner_token );
				return is_int( $action_id ) && $action_id > 0
					? $result
					: agents_workflow_terminalize_reconcile_continuation( $recorder, $result, 'workflow_parallel_aggregation_dispatch_failed', 'The aggregate continuation failed before effects began and could not be re-enqueued.' );
			}
			if ( 'running' === agents_workflow_string( $claim['phase'] ?? '' ) ) {
				return agents_workflow_terminalize_reconcile_continuation( $recorder, $result, 'workflow_parallel_aggregation_outcome_uncertain', 'The aggregate action failed after external effects may have begun; the aggregate was not rerun.' );
			}
			return 'committed' === agents_workflow_string( $claim['phase'] ?? '' )
				? array( 'action' => 'resume', 'result' => $result )
				: $result;
		}
	);
	if ( is_array( $transition ) ) {
		if ( ! $from_durable_action ) {
			$action_id = agents_workflow_dispatch_aggregate_continuation( $run_id, $transition['result']->get_suspension(), $generation, $owner_token, true );
			if ( is_int( $action_id ) && $action_id > 0 ) {
				return $transition['result'];
			}
		}
		return agents_workflow_resume_reconcile_continuation( $recorder, $run_id, $transition['result'] );
	}
	return $transition;
}

/**
 * Persist an honest failed aggregate and make the continuation resumable.
 *
 * @return array{action:'resume',result:WP_Agent_Workflow_Run_Result}|\WP_Error
 */
function agents_workflow_terminalize_reconcile_continuation( WP_Agent_Workflow_Run_Recorder $recorder, WP_Agent_Workflow_Run_Result $result, string $code, string $message ) {
	$suspension = $result->get_suspension();
	$generation = agents_workflow_suspension_generation( $suspension );
	$claim      = is_array( $suspension['reconcile_claim'] ?? null ) ? $suspension['reconcile_claim'] : array();
	$suspension['reconcile_claim'] = array(
		'phase'       => 'committed',
		'generation'  => $generation,
		'owner_token' => agents_workflow_string( $claim['owner_token'] ?? '' ),
	);
	$metadata = $result->get_metadata();
	$metadata['_suspension'] = $suspension;
	$result = $result->with( array( 'metadata' => $metadata ) );
	$step_index = is_numeric( $suspension['step_index'] ?? null ) ? (int) $suspension['step_index'] : 0;
	$result = agents_workflow_splice_step_output( $result, $step_index, new \WP_Error( $code, $message ) );
	$updated = agents_workflow_update_reconcile_state( $recorder, $result, 'terminalize an ambiguous reconcile continuation' );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}
	return array(
		'action' => 'resume',
		'result' => $result,
	);
}

/**
 * Normalize recorder write uncertainty to PR #534's persisted retry contract.
 *
 * @return true|\WP_Error
 */
function agents_workflow_update_reconcile_state( WP_Agent_Workflow_Run_Recorder $recorder, WP_Agent_Workflow_Run_Result $result, string $transition ) {
	try {
		$updated = $recorder->update( $result );
	} catch ( \Throwable $error ) {
		return new \WP_Error( 'agents_reconcile_lock_unavailable', sprintf( 'Could not %s; retry the persisted reconcile continuation.', $transition ), array( 'cause' => $error->getMessage() ) );
	}
	if ( is_wp_error( $updated ) ) {
		return new \WP_Error( 'agents_reconcile_lock_unavailable', sprintf( 'Could not %s; retry the persisted reconcile continuation.', $transition ), array( 'cause' => $updated->get_error_code() ) );
	}
	return true;
}

/**
 * Resume a durably committed continuation without rerunning aggregation.
 *
 * @return WP_Agent_Workflow_Run_Result|\WP_Error
 */
function agents_workflow_resume_reconcile_continuation( WP_Agent_Workflow_Run_Recorder $recorder, string $run_id, WP_Agent_Workflow_Run_Result $result ) {
	$dispatch = agents_workflow_reconcile_with_lock(
		$run_id,
		static function () use ( $recorder, $run_id, $result ) {
			$current = $recorder->find( $run_id );
			if ( null === $current || ! $current->is_suspended() ) {
				return null !== $current ? $current : $result;
			}
			if ( agents_workflow_defer_resume( $run_id, $current ) ) {
				return $current;
			}

			// Legacy/unavailable unique-action surfaces fall back inline while the
			// per-run lock is still held, so duplicate dispatchers cannot overlap.
			$resumed = agents_workflow_resolve_runner( $recorder )->resume( $run_id );
			if ( ! $resumed->is_suspended() && class_exists( WP_Agent_Workflow_Branch_Store::class ) ) {
				WP_Agent_Workflow_Branch_Store::forget_run( $run_id );
			}
			return $resumed;
		}
	);
	if ( is_wp_error( $dispatch ) ) {
		return $dispatch;
	}
	return $dispatch;
}

/**
 * Derive the stable identity of one suspension generation.
 *
 * @param array<string,mixed> $suspension Suspension frame.
 */
function agents_workflow_suspension_generation( array $suspension ): string {
	$handles = is_array( $suspension['handles'] ?? null ) ? $suspension['handles'] : array();
	$ids     = array();
	foreach ( $handles as $handle ) {
		if ( is_array( $handle ) ) {
			$ids[] = agents_workflow_string( $handle['id'] ?? '' );
		}
	}

	return hash(
		'sha256',
		serialize(
			array(
				'step_index' => is_numeric( $suspension['step_index'] ?? null ) ? (int) $suspension['step_index'] : 0,
				'step_id'    => agents_workflow_string( $suspension['step_id'] ?? '' ),
				'handles'    => $ids,
			)
		)
	);
}

/** Mint an opaque owner token for one reconcile claim. */
function agents_workflow_reconcile_claim_token(): string {
	if ( function_exists( 'wp_generate_uuid4' ) ) {
		return wp_generate_uuid4();
	}
	try {
		return bin2hex( random_bytes( 16 ) );
	} catch ( \Throwable $error ) {
		unset( $error );
		return uniqid( 'reconcile_', true );
	}
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
 * @return T|\WP_Error The section's result, or a retryable WP_Error when the lock could not be acquired.
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
