<?php
/**
 * Phase 2 wiring for the Action Scheduler branch executor.
 *
 * This is the soft registration that turns the dormant Phase 1 state machine
 * into real concurrency WHEN — and only when — Action Scheduler's async enqueue
 * is present. It does three things, all present-only:
 *
 *   1. Selects the AS executor. The core selector
 *      ({@see register-workflow-step-executor.php}) returns `null` (→ sync) by
 *      default; this hook — at a priority ABOVE that core default but BELOW a
 *      caller override — returns the AS executor when
 *      `as_enqueue_async_action` exists. So: caller override (10+) wins; else
 *      AS (this, priority 6); else null → v0.5.0 sync. An install without AS is
 *      byte-for-byte Phase 1.
 *
 *   2. Registers the per-branch action callback ({@see BRANCH_HOOK}) that
 *      rehydrates a branch from its payload, runs it through the SHARED
 *      `run_branch_steps()`, and drives the REAL reconcile.
 *
 *   3. Registers the resume action callback ({@see RESUME_HOOK}) and the
 *      deferred-resume seam ({@see wp_agent_workflow_resume_dispatch}) so the
 *      "all branches terminal → resume" transition is performed as ONE
 *      atomically-claimed AS action instead of resuming inline. AS's claim is
 *      the cross-process guard that makes resume exactly-once — no lock, no
 *      table.
 *
 * Everything here no-ops cleanly without Action Scheduler; the state machine,
 * interface, and reconcile entry point stay dormant on a no-AS install.
 *
 * @package AgentsAPI
 * @since   0.5.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

// 1. Selector: return the AS executor when AS async enqueue is present. Priority
//    6 runs AFTER the core priority-5 null default (so we can override its null)
//    and BEFORE a caller override at 10+ (which still wins by returning its own
//    executor, respected by the `instanceof` short-circuit here).
add_filter(
	'wp_agent_workflow_step_executor',
	/**
	 * @param mixed                $executor Executor resolved so far.
	 * @param array<string,mixed>  $step     The parallel step being dispatched.
	 * @param array<string,mixed>  $context  Resolution context.
	 * @return WP_Agent_Workflow_Branch_Executor|null
	 */
	static function ( $executor, $step, $context ) {
		unset( $step, $context );

		if ( $executor instanceof WP_Agent_Workflow_Branch_Executor ) {
			return $executor;
		}

		if ( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::is_available() ) {
			return new WP_Agent_Workflow_Action_Scheduler_Branch_Executor();
		}

		return $executor;
	},
	6,
	3
);

// 2. Per-branch action: rehydrate → run via shared run_branch_steps() → reconcile.
add_action(
	WP_Agent_Workflow_Action_Scheduler_Branch_Executor::BRANCH_HOOK,
	/**
	 * @param array<string,mixed> $payload Action payload: { run_id, handle_id, branch }.
	 */
	static function ( $payload = array() ): void {
		WP_Agent_Workflow_Action_Scheduler_Branch_Executor::run_branch_action( is_array( $payload ) ? $payload : array() );
	},
	10,
	1
);

// 3a. Resume action: AS claimed it exactly once → re-check SUSPENDED → resume.
add_action(
	WP_Agent_Workflow_Action_Scheduler_Branch_Executor::RESUME_HOOK,
	/**
	 * @param array<string,mixed> $payload Action payload: { run_id }.
	 */
	static function ( $payload = array() ): void {
		WP_Agent_Workflow_Action_Scheduler_Branch_Executor::run_resume_action( is_array( $payload ) ? $payload : array() );
	},
	10,
	1
);

// 3b. Deferred-resume seam: enqueue a claimed RESUME action for AS-owned runs
//     instead of resuming inline in the reconcile request.
add_filter(
	'wp_agent_workflow_resume_dispatch',
	/**
	 * @param bool   $deferred    Whether resume is already deferred.
	 * @param string $run_id      The suspended run id.
	 * @param string $executor_id The frame's owning executor id.
	 * @return bool
	 */
	static function ( $deferred, $run_id, $executor_id ) {
		return WP_Agent_Workflow_Action_Scheduler_Branch_Executor::maybe_defer_resume(
			(bool) $deferred,
			is_string( $run_id ) ? $run_id : '',
			is_string( $executor_id ) ? $executor_id : ''
		);
	},
	10,
	3
);

// 4. Long-branch reaping window. Action Scheduler's QueueCleaner marks any action
//    still RUNNING past `action_scheduler_failure_period` (default 300s) as a
//    failed/abandoned action, on the assumption an uncatchable fatal killed it. A
//    workflow branch is the opposite case: it is EXPECTED to run long — a branch
//    is often a multi-minute AI generation, code execution, or remote call, which
//    is the whole reason it was fanned out asynchronously. At 300s AS reaps a
//    still-healthy branch mid-flight and the run thrashes on retries. Raise the
//    window so a legitimately long branch survives, while still reaping a truly
//    dead one eventually. The reaper runs in a DIFFERENT process than the branch,
//    so this must be a persistent filter (registered here at load), not something
//    set inside the running branch. Callers whose branches are shorter or longer
//    can retune via `agents_workflow_branch_failure_period`.
//
//    Registered UNCONDITIONALLY (not gated on is_available()): these AS filters
//    only ever fire from inside Action Scheduler's own QueueCleaner, so they are
//    inert no-ops on a no-AS install. Gating registration on is_available() at
//    require-time would race plugin load order — if Action Scheduler loads AFTER
//    agents-api, the gate reads false and the filter is never added, leaving the
//    branch reaper at the 300s default. Registering here at load, before AS's
//    cleaner ever runs, avoids that race.
$agents_workflow_branch_reaper_window = static function ( $period ) {
	/**
	 * The seconds a workflow branch may run before Action Scheduler treats it
	 * as abandoned. Defaults to 3600 (1 hour) so multi-minute branches are not
	 * reaped, while a genuinely stuck branch is still eventually failed.
	 *
	 * @param int $period The AS failure/timeout period (seconds).
	 */
	// absint() coerces a filtered value (a third party may return anything) to a
	// non-negative int; a 0 from a garbage value is harmless here (max() below
	// keeps the incoming period).
	$branch_window = absint( apply_filters( 'agents_workflow_branch_failure_period', 3600 ) );

	// Never shorten the operator's configured window; only extend it. The AS
	// period is numeric; a non-numeric value (a misbehaving filter) coerces to 0,
	// which max() below discards in favor of the branch window.
	$incoming = is_numeric( $period ) ? (int) $period : 0;
	return max( $incoming, $branch_window );
};
add_filter( 'action_scheduler_failure_period', $agents_workflow_branch_reaper_window, 20 );
add_filter( 'action_scheduler_timeout_period', $agents_workflow_branch_reaper_window, 20 );

// 5. Concurrent branch execution policy. Action Scheduler's queue runner defaults to
//    ONE concurrent batch of 25 actions: a single worker claims a batch of pending
//    actions and runs them one after another in one PHP process. For a parallel
//    fan-out that is exactly wrong — N branches enqueued under BRANCH_HOOK would drain
//    serially in one worker instead of N workers each running one branch. The two
//    filters below flip that WHILE — and only while — this executor's own branches are
//    still IN FLIGHT:
//
//      - `concurrent_batches` is RAISED to the IN-FLIGHT branch count (capped at
//        MAX_BRANCH_CONCURRENCY) so up to N workers may each hold a claim at once.
//      - `batch_size` is pinned to 1 so each of those workers claims exactly ONE
//        branch and leaves the rest for its peers.
//
//    Together (concurrent_batches=N + batch_size=1) they turn N running workers into N
//    DISTINCT branches instead of one worker draining a batch of them serially on
//    stores that support concurrent writes. SQLite has one database-wide writer,
//    so it serializes Action Scheduler claims and cannot execute these branches in
//    parallel.
//
//    IN FLIGHT = PENDING + IN-PROGRESS, NOT PENDING ALONE. The gate keys off
//    {@see WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count()}
//    — the count of branch actions that are pending OR in-progress — not the pending
//    count alone. This is the crux of the fix. The instant a worker claims a branch it
//    transitions PENDING -> in-progress, so a pending-only gate collapses toward 0 as
//    soon as claiming starts: the first claim drops the count enough to revert both
//    filters to the AS defaults (concurrent_batches 1, batch_size 25), and AS's
//    `has_maximum_concurrent_batches()` (get_claim_count 1 >= concurrent_batches 1)
//    then blocks any further worker from claiming the still-pending branches while that
//    first branch runs its multi-minute AI call — the fan-out drains SERIALLY. Keying
//    off pending + in-progress keeps the ceiling raised to cover every branch still in
//    flight, so a second/third worker CAN claim a pending branch while the first is
//    in-progress (get_claim_count < concurrent_batches, so the AS gate lets it
//    through). The ceiling stays open until the LAST branch leaves the in-flight set.
//
//    BLAST RADIUS. Both filters are process-global — Action Scheduler has no per-group
//    concurrency knob — so a naive permanent raise would change concurrency for ALL AS
//    workloads. To keep the blast radius bounded, BOTH callbacks are GATED on this
//    executor's OWN branches being in flight: when zero branches are in flight they
//    pass the incoming value through UNCHANGED, so every other AS workload keeps stock
//    behavior (concurrent_batches 1, batch_size 25). The elevated policy exists only
//    for the span of a fan-out (seconds to a few minutes).
//
//    RESUME NEEDS A SLOT TOO — the terminal-drain gap. A fan-out ends with ONE resume
//    action (RESUME_HOOK, enqueued when the last branch reconciles) that drives the
//    suspended run to terminal. It is NOT a branch, so the branch-only in-flight count
//    drops to 0 the moment the last branch completes and the ceiling reverts to the AS
//    default of 1 — right as the resume becomes pending and DUE. When any claim is still
//    outstanding (e.g. a still-in-progress branch from an UNRELATED earlier fan-out held
//    by the 3600s long-branch reaper window above), AS's `has_maximum_concurrent_batches()`
//    (get_claim_count >= concurrent_batches) then stays shut against a ceiling of 1, so
//    the WP-Cron runner claims NOTHING and the due resume sits unclaimed — the run never
//    finalizes — until that unrelated claim is finally reaped (observed: ~51 minutes).
//    Adding the in-flight resume count
//    ({@see WP_Agent_Workflow_Action_Scheduler_Branch_Executor::resume_inflight_count()})
//    to the raise gives the resume its own slot on top of the branches: the ceiling
//    exceeds the outstanding-claim count by one and the gate opens far enough for the
//    runner to claim the resume even while unrelated stale claims linger. When no fan-out
//    is active (both counts 0) the raise is inert and every other AS workload sees stock
//    behavior.
//
//    concurrent_batches only ever RAISES (max of incoming and target) so it never
//    lowers another plugin's already-higher ceiling. Priority 100 so it runs after
//    most callers. Registered UNCONDITIONALLY (not gated on is_available()) for the
//    same reason as the reaper window above: these AS filters only ever fire from
//    inside Action Scheduler's own queue runner, so they are inert on a no-AS install,
//    and registering here at load avoids a plugin-load-order race where AS loading
//    after agents-api would leave the gate reading false and the filter never added.
add_filter(
	'action_scheduler_queue_runner_concurrent_batches',
	/**
	 * @param mixed $batches Incoming concurrent-batch ceiling.
	 * @return int
	 */
	static function ( $batches ) {
		$incoming = is_numeric( $batches ) ? (int) $batches : 1;
		$branches = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count();
		$resumes  = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::resume_inflight_count();
		if ( $branches < 1 && $resumes < 1 ) {
			return $incoming;
		}

		$max = WP_Agent_Workflow_Action_Scheduler_Branch_Executor::MAX_BRANCH_CONCURRENCY;

		// Branch ceiling: enough slots for every in-flight branch (capped at MAX),
		// never lowering a higher ceiling another plugin set. This is the existing
		// parallel-branch behavior.
		$branch_ceiling = max( $incoming, min( $branches, $max ) );

		// Resume headroom is ADDITIVE on top of the branch ceiling — the resume needs
		// a slot BEYOND the branches and beyond any UNRELATED claims that are already
		// consuming the branch ceiling. If it merely matched the ceiling it would be
		// starved: AS's has_maximum_concurrent_batches() compares the GLOBAL claim
		// count against the ceiling, so a lone due resume with even one unrelated claim
		// outstanding would sit at claim_count >= ceiling and never be admitted. Adding
		// the resume count lifts the ceiling above the outstanding claims so the WP-Cron
		// runner is admitted and claims the resume. Bounded (a fan-out has one resume;
		// concurrent fan-outs are bounded by MAX) so the raise stays sane.
		$resume_headroom = min( $resumes, $max );

		return $branch_ceiling + $resume_headroom;
	},
	100
);

add_filter(
	'action_scheduler_queue_runner_batch_size',
	/**
	 * @param mixed $batch_size Incoming batch size.
	 * @return int
	 */
	static function ( $batch_size ) {
		// Pin to 1 while branches are in flight (pending or in-progress) so each worker
		// claims exactly one branch; otherwise pass the incoming value through so
		// ordinary AS throughput (25) is untouched.
		if ( WP_Agent_Workflow_Action_Scheduler_Branch_Executor::branch_inflight_count() > 0 ) {
			return 1;
		}
		return is_numeric( $batch_size ) ? (int) $batch_size : 25;
	},
	100
);
