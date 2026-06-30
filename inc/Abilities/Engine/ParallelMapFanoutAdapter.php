<?php
/**
 * Parallel-map fan-out adapter.
 *
 * Expresses Data Machine's packet fan-out *in terms of* the generic
 * `parallel` workflow step contract introduced by Agents API
 * ({@link https://github.com/Automattic/agents-api/pull/389}, the
 * parallel-MAP shape). It lets a workflow spec authored against the
 * Agents API `parallel` step be satisfied by Data Machine's
 * Action-Scheduler-backed {@see PipelineBatchScheduler} executor.
 *
 * Architectural boundary (complementary, not duplicative):
 *
 *   - Agents API owns the `parallel` step CONTRACT and its synchronous
 *     reference dispatch (run the same nested steps over N items in one
 *     request, returning `{ shape:'map', count, branches:[...] }`).
 *   - Data Machine provides a CONCURRENT executor backend for the map
 *     shape: each item becomes an independent child pipeline job drained
 *     through Action Scheduler with real concurrency budgets, chunk
 *     sizing, and parent/child status rollup. DM does not replace the
 *     contract; it is one real backend behind it.
 *
 * Generic `parallel`-map step shape this adapter accepts (PR #389):
 *
 *   array(
 *       'id'       => 'fan',
 *       'type'     => 'parallel',
 *       'items'    => array( ...N items... ),  // array binding, resolved by caller
 *       'as'       => 'item',                  // optional item var name
 *       'index_as' => 'index',                 // optional index var name
 *       'steps'    => array( ...nested steps run per item... ),
 *   )
 *
 * Mapping onto Data Machine packet jobs:
 *
 *   - `items`  → the N DataPackets fanned into N child pipeline jobs.
 *   - `steps`  → the remaining pipeline steps each child runs; the caller
 *                supplies the concrete `next_flow_step_id` entry point.
 *   - output  → the `{ shape:'map', count, branches:[...] }` contract
 *                envelope, where each branch describes the dispatched
 *                child (index, item, the nested steps it will run, and a
 *                `scheduled` output marker — DM dispatch is asynchronous,
 *                so branch outputs are scheduling receipts, not final
 *                synchronous results).
 *
 * Adaptive gate: honors the `wp_agent_workflow_should_fanout` filter
 * (default true) so the decision to fan out is consistent across every
 * consumer of the generic contract. When the gate returns false the
 * adapter reports `shape:'inline'` and performs no fan-out.
 *
 * This adapter has no hard dependency on Agents API code being present.
 * It models the contract shape directly so Data Machine's existing
 * behavior keeps working before PR #389 merges; once that substrate is
 * installed, DM composes the same primitive instead of re-deriving it.
 *
 * @package DataMachine\Abilities\Engine
 * @since 0.159.0
 */

namespace DataMachine\Abilities\Engine;

defined( 'ABSPATH' ) || exit;

class ParallelMapFanoutAdapter {

	/**
	 * Contract step type owned by the Agents API `parallel` step.
	 */
	public const STEP_TYPE = 'parallel';

	/**
	 * Map shape identifier from the generic `parallel` contract output.
	 */
	public const SHAPE_MAP = 'map';

	/**
	 * Inline shape reported when the adaptive gate suppresses fan-out.
	 */
	public const SHAPE_INLINE = 'inline';

	/**
	 * @var PipelineBatchScheduler
	 */
	private PipelineBatchScheduler $batch_scheduler;

	/**
	 * @param PipelineBatchScheduler|null $batch_scheduler Injectable executor backend.
	 */
	public function __construct( ?PipelineBatchScheduler $batch_scheduler = null ) {
		$this->batch_scheduler = $batch_scheduler ?? new PipelineBatchScheduler();
	}

	/**
	 * Validate that a step spec is the generic `parallel`-MAP shape.
	 *
	 * The parallel-roles+aggregate shape (the other shape PR #389 expresses)
	 * is not a DM packet fan-out and is intentionally rejected here: it has
	 * no `items` binding and DM has no concurrent backend for it.
	 *
	 * @param array $step Generic workflow step spec.
	 * @return bool True when the step is a parallel-map step.
	 */
	public static function isParallelMapStep( array $step ): bool {
		if ( self::STEP_TYPE !== ( $step['type'] ?? '' ) ) {
			return false;
		}

		// The map shape is identified by an `items` array binding plus
		// nested `steps`. Roles-style parallel steps carry `branches`/
		// `roles` instead and are not mappable onto packet fan-out.
		return array_key_exists( 'items', $step ) && isset( $step['steps'] ) && is_array( $step['steps'] );
	}

	/**
	 * Resolve the adaptive fan-out gate for a `parallel`-map step.
	 *
	 * Mirrors the Agents API `wp_agent_workflow_should_fanout` filter
	 * (default true) so a single ecosystem-wide policy decides whether the
	 * map shape fans out or collapses inline. Data Machine routes its
	 * packet fan-out through the same gate so behavior is consistent
	 * whether the parallel step runs on the Agents API reference dispatch
	 * or on DM's concurrent backend.
	 *
	 * @param array $step    The `parallel`-map step spec.
	 * @param array $context Caller context (job_id, next_flow_step_id, item count, ...).
	 * @return bool Whether to fan out. Defaults to true.
	 */
	public static function shouldFanOut( array $step, array $context = array() ): bool {
		/**
		 * Filter whether a generic `parallel`-map step should fan out.
		 *
		 * Compatible with the Agents API `wp_agent_workflow_should_fanout`
		 * contract (PR #389). Returning false collapses the map shape to an
		 * inline (single-branch) pass so no child jobs are scheduled.
		 *
		 * @param bool  $should  Whether to fan out. Default true.
		 * @param array $step    The `parallel`-map step spec.
		 * @param array $context Caller context.
		 */
		return (bool) apply_filters( 'wp_agent_workflow_should_fanout', true, $step, $context );
	}

	/**
	 * Map a generic `parallel`-map step onto Data Machine packet fan-out.
	 *
	 * Given a parallel-map step whose resolved `items` are DataPacket
	 * arrays, fan them into N independent child pipeline jobs via the
	 * Action-Scheduler-backed executor, and return the generic contract
	 * output envelope describing the dispatch.
	 *
	 * @param array  $step              Generic `parallel`-map step spec.
	 * @param int    $parent_job_id     Parent job that owns the fan-out.
	 * @param string $next_flow_step_id Concrete entry step each child runs (the per-item `steps`).
	 * @param array  $engine_snapshot   Parent engine_data cloned to each child.
	 * @return array {
	 *     Generic `parallel` contract output.
	 *
	 *     @type string $shape    'map' on fan-out, 'inline' when gated off.
	 *     @type int    $count    Number of items / branches.
	 *     @type array  $branches Per-item branch descriptors.
	 *     @type array  $batch    DM batch summary (parent_job_id, total, chunk_size) on fan-out.
	 *     @type bool   $gated    True when the adaptive gate suppressed fan-out.
	 * }
	 */
	public function dispatch(
		array $step,
		int $parent_job_id,
		string $next_flow_step_id,
		array $engine_snapshot
	): array {
		$items = is_array( $step['items'] ?? null ) ? array_values( $step['items'] ) : array();
		$count = count( $items );

		$context = array(
			'job_id'            => $parent_job_id,
			'next_flow_step_id' => $next_flow_step_id,
			'item_count'        => $count,
			'step_id'           => (string) ( $step['id'] ?? '' ),
		);

		if ( ! self::shouldFanOut( $step, $context ) ) {
			return array(
				'shape'    => self::SHAPE_INLINE,
				'count'    => $count,
				'branches' => $this->buildBranches( $step, $items, 'gated' ),
				'gated'    => true,
			);
		}

		$batch = $this->batch_scheduler->fanOut(
			$parent_job_id,
			$next_flow_step_id,
			$items,
			$engine_snapshot
		);

		return array(
			'shape'    => self::SHAPE_MAP,
			'count'    => $count,
			'branches' => $this->buildBranches( $step, $items, 'scheduled' ),
			'batch'    => $batch,
			'gated'    => false,
		);
	}

	/**
	 * Build the per-item branch descriptors for the contract envelope.
	 *
	 * Each branch mirrors the generic `parallel`-map branch shape:
	 * `{ index, item, steps, output }`. Because Data Machine dispatch is
	 * asynchronous (children drain through Action Scheduler later), the
	 * branch `output` is a scheduling receipt rather than the final
	 * synchronous step result. The item var name (`as`) and index var
	 * name (`index_as`) from the step spec are surfaced so callers can map
	 * branch context back onto `${vars.item.*}` bindings.
	 *
	 * @param array  $step  The `parallel`-map step spec.
	 * @param array  $items Resolved item bindings (DataPacket arrays).
	 * @param string $state Dispatch state marker: 'scheduled' or 'gated'.
	 * @return array<int,array<string,mixed>> Branch descriptors.
	 */
	private function buildBranches( array $step, array $items, string $state ): array {
		$nested_steps = is_array( $step['steps'] ?? null ) ? $step['steps'] : array();
		$item_var     = is_string( $step['as'] ?? null ) && '' !== $step['as'] ? $step['as'] : 'item';
		$index_var    = is_string( $step['index_as'] ?? null ) && '' !== $step['index_as'] ? $step['index_as'] : 'index';

		$branches = array();
		foreach ( $items as $index => $item ) {
			$branches[] = array(
				'index'     => $index,
				'item'      => $item,
				'item_var'  => $item_var,
				'index_var' => $index_var,
				'steps'     => $nested_steps,
				'output'    => array( 'state' => $state ),
			);
		}

		return $branches;
	}
}
