<?php
/**
 * The generic concurrency seam for the workflow runner's suspend/resume model.
 *
 * A branch executor is the ONLY thing the runner talks to for out-of-band
 * branch execution. It knows nothing about the underlying mechanism — Action
 * Scheduler (the executor core ships in a later phase), a caller's own worker
 * pool, or a deterministic test double. The runner owns the state machine
 * (suspend → dispatch → reconcile → aggregate → resume); the executor owns
 * only the mechanism that actually runs branches and, ultimately, drives the
 * canonical reconcile entry point ({@see agents_reconcile_workflow_branch()})
 * when a branch finishes.
 *
 * Core ships no default executor and makes no concurrency-safety promise for a
 * caller-registered one: a caller that registers its own executor owns the
 * durable park for the suspended run AND the cross-process atomic guard for the
 * resume transition.
 *
 * @package AgentsAPI
 * @since   0.5.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

interface WP_Agent_Workflow_Branch_Executor {

	/**
	 * Stable executor id, e.g. `action_scheduler`. Stored on each branch
	 * handle so the runner can attribute handles to the owning executor.
	 *
	 * @since 0.5.0
	 */
	public function id(): string;

	/**
	 * Dispatch N branches for out-of-band execution.
	 *
	 * @since 0.5.0
	 *
	 * @param array<int,array<string,mixed>> $branches Branch descriptors: each is a
	 *        self-contained payload the executor can run later — `{ key, steps,
	 *        branch_vars, continue_on_error, run_id, step_id }`.
	 * @param array<string,mixed>            $context  Serializable shared context snapshot.
	 * @return array<int,array<string,mixed>>|\WP_Error BranchHandle[] — `{ id, key,
	 *         executor, status, ref }`. Handles MAY be returned already-complete (a
	 *         synchronous executor), in which case the runner never suspends. An
	 *         executor that cannot durably schedule a branch MUST return a WP_Error
	 *         so the run fails fast rather than suspending against a branch that was
	 *         never dispatched — the runner treats a WP_Error dispatch as a hard
	 *         step failure.
	 */
	public function dispatch( array $branches, array $context );

	/**
	 * Whether every dispatched handle has reached a terminal state.
	 *
	 * @since 0.5.0
	 *
	 * @param array<int,array<string,mixed>> $handles BranchHandle[].
	 * @return bool True when every handle is terminal (`succeeded` or `failed`).
	 */
	public function are_all_complete( array $handles ): bool;

	/**
	 * Collect terminal branch outputs keyed by the branch key (role or index).
	 *
	 * @since 0.5.0
	 *
	 * @param array<int,array<string,mixed>> $handles BranchHandle[].
	 * @return array<string,mixed> BranchResult[] keyed by role|index.
	 */
	public function collect( array $handles ): array;
}
