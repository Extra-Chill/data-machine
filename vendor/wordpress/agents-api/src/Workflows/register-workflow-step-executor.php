<?php
/**
 * Core branch-executor selector.
 *
 * The parallel handler resolves whether (and how) to run branches out-of-band
 * through the `wp_agent_workflow_step_executor` filter. Core supplies the
 * default at LOW priority so a caller can override at normal priority (10+).
 *
 * Selection order:
 *
 *   1. A caller-forced executor wins — a consumer that has its own durable +
 *      atomic story may return its own {@see WP_Agent_Workflow_Branch_Executor}
 *      at priority 10+. Core makes no concurrency-safety promise for one.
 *   2. Else Action Scheduler, when its async enqueue is present — the only
 *      table-free durable + atomic path. (Phase 2 ships that executor; until
 *      then no executor is returned even when AS is present.)
 *   3. Else `null` → SYNCHRONOUS. The parallel handler, seeing no executor,
 *      runs the in-process `run_parallel_roles()` / `run_parallel_map()` loops
 *      exactly as v0.5.0. No suspend, no reconcile, no table.
 *
 * There is thus always a correct behavior with zero configuration: an install
 * with no async executor selected is byte-for-byte v0.5.0 (serial).
 *
 * @package AgentsAPI
 * @since   0.5.0
 */

namespace AgentsAPI\AI\Workflows;

defined( 'ABSPATH' ) || exit;

add_filter(
	'wp_agent_workflow_step_executor',
	/**
	 * Core default selector for the branch executor.
	 *
	 * @param mixed                $executor Executor resolved so far. A caller
	 *                                       override may already have supplied one.
	 * @param array<string,mixed>  $step     The parallel step being dispatched.
	 * @param array<string,mixed>  $context  Resolution context.
	 * @return WP_Agent_Workflow_Branch_Executor|null
	 */
	static function ( $executor, $step, $context ) {
		unset( $step, $context );

		// A caller forced one (priority 10+ runs after this priority-5 default,
		// so a caller override lands as $executor here on the NEXT pass — but a
		// higher-priority caller filter would also short-circuit by returning
		// early; either way, respect an already-resolved executor).
		if ( $executor instanceof WP_Agent_Workflow_Branch_Executor ) {
			return $executor;
		}

		// Phase 2 will return the Action Scheduler executor here when
		// `function_exists( 'as_enqueue_async_action' )`. Phase 1 ships no async
		// executor, so the selector returns null and the parallel handler runs
		// the v0.5.0 synchronous loops. This keeps existing installs byte-for-byte
		// unchanged while the state machine + interface land dormant.
		return null;
	},
	5,
	3
);
