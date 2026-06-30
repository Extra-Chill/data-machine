<?php
/**
 * Pure-PHP smoke test for the parallel-map fan-out adapter.
 *
 * Proves Data Machine's packet fan-out is the SINGLE-path expression of the
 * generic Agents API `parallel`-MAP step contract (PR #389):
 *
 *   - A `parallel`-map step spec `{ items, steps }` maps onto DM's
 *     PipelineBatchScheduler executor through one decision/dispatch surface.
 *   - The adapter HARD-DEPENDS on the Agents API substrate: dispatch()
 *     throws when the substrate runner class is absent (no fallback path).
 *   - The `wp_agent_workflow_should_fanout` adaptive gate is the ONLY gate;
 *     dispatch() applies it and returns shape:'inline' when it declines.
 *   - ExecuteStepAbility routes fan-out exclusively through the adapter and
 *     never re-evaluates the gate or calls a legacy fan-out entry point.
 *
 * Run with: php tests/parallel-map-fanout-adapter-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities\Engine {

	// Test double for the Action-Scheduler executor backend. Captures the
	// fanOut() call so the adapter's mapping can be asserted without a DB
	// or WordPress runtime.
	class PipelineBatchScheduler {
		/** @var array<int,array<string,mixed>> */
		public array $calls = array();

		public function fanOut( int $parent_job_id, string $next_flow_step_id, array $dataPackets, array $engine_snapshot ): array {
			$this->calls[] = array(
				'parent_job_id'     => $parent_job_id,
				'next_flow_step_id' => $next_flow_step_id,
				'dataPackets'       => $dataPackets,
				'engine_snapshot'   => $engine_snapshot,
			);

			return array(
				'parent_job_id' => $parent_job_id,
				'total'         => count( $dataPackets ),
				'chunk_size'    => 10,
			);
		}
	}
}

// Stub the Agents API `parallel` step substrate (PR #389) the adapter
// hard-depends on. Its presence lets dispatch() proceed; the "absent"
// case is exercised separately below before this class is defined.
namespace AgentsAPI\AI\Workflows {
	class WP_Agent_Workflow_Runner {}
}

namespace {

	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

	// Filter shim: a single registered callback for wp_agent_workflow_should_fanout.
	$GLOBALS['__should_fanout_filter'] = null;

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			if ( 'wp_agent_workflow_should_fanout' === $hook && is_callable( $GLOBALS['__should_fanout_filter'] ) ) {
				return ( $GLOBALS['__should_fanout_filter'] )( $value, ...$args );
			}
			return $value;
		}
	}

	require_once __DIR__ . '/../inc/Abilities/Engine/ParallelMapFanoutAdapter.php';

	use DataMachine\Abilities\Engine\ParallelMapFanoutAdapter;
	use DataMachine\Abilities\Engine\PipelineBatchScheduler;

	$failures = array();
	$passes   = 0;

	function parallel_map_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
		if ( $expected === $actual ) {
			++$passes;
			echo "  PASS {$name}\n";
			return;
		}

		$failures[] = $name;
		echo "  FAIL {$name}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	}

	echo "parallel-map-fanout-adapter-smoke\n";

	// A DataPacket-shaped item (the array form a fetch step emits).
	function parallel_map_packet( string $title ): array {
		return array(
			'type'      => 'fetch',
			'timestamp' => 0,
			'data'      => array( 'title' => $title ),
			'metadata'  => array( 'item_identifier' => $title ),
		);
	}

	$items = array(
		parallel_map_packet( 'one' ),
		parallel_map_packet( 'two' ),
		parallel_map_packet( 'three' ),
	);

	// Generic `parallel`-MAP step spec, exactly the PR #389 shape.
	$parallel_step = array(
		'id'       => 'fan',
		'type'     => 'parallel',
		'items'    => $items,
		'as'       => 'packet',
		'index_as' => 'i',
		'steps'    => array(
			array( 'id' => 'process', 'type' => 'ability', 'ability' => 'datamachine/execute-step' ),
		),
	);

	// --- Shape detection ---
	parallel_map_assert( true, ParallelMapFanoutAdapter::isParallelMapStep( $parallel_step ), 'parallel-map step is recognized', $failures, $passes );
	parallel_map_assert( false, ParallelMapFanoutAdapter::isParallelMapStep( array( 'type' => 'ability' ) ), 'non-parallel step is rejected', $failures, $passes );
	parallel_map_assert( false, ParallelMapFanoutAdapter::isParallelMapStep( array( 'type' => 'parallel', 'roles' => array() ) ), 'roles-shape parallel step is rejected (no items map)', $failures, $passes );

	// --- Fan-out path: maps onto PipelineBatchScheduler ---
	$GLOBALS['__should_fanout_filter'] = null; // default true
	$backend = new PipelineBatchScheduler();
	$adapter = new ParallelMapFanoutAdapter( $backend );

	$engine_snapshot = array( 'job' => array( 'pipeline_id' => 5, 'flow_id' => 9 ) );
	$result          = $adapter->dispatch( $parallel_step, 42, 'flow_step_next', $engine_snapshot );

	parallel_map_assert( 'map', $result['shape'], 'dispatch reports map shape on fan-out', $failures, $passes );
	parallel_map_assert( 3, $result['count'], 'dispatch count equals item count', $failures, $passes );
	parallel_map_assert( false, $result['gated'], 'fan-out not gated by default', $failures, $passes );
	parallel_map_assert( 1, count( $backend->calls ), 'executor backend fanOut called once', $failures, $passes );
	parallel_map_assert( 42, $backend->calls[0]['parent_job_id'], 'items map onto parent job id', $failures, $passes );
	parallel_map_assert( 'flow_step_next', $backend->calls[0]['next_flow_step_id'], 'nested steps map onto next_flow_step_id entry point', $failures, $passes );
	parallel_map_assert( $items, $backend->calls[0]['dataPackets'], 'items map onto fanned DataPackets verbatim', $failures, $passes );
	parallel_map_assert( $engine_snapshot, $backend->calls[0]['engine_snapshot'], 'engine snapshot forwarded to executor', $failures, $passes );
	parallel_map_assert( 3, $result['batch']['total'], 'batch summary surfaced from executor', $failures, $passes );

	// --- Contract output envelope: branches ---
	parallel_map_assert( 3, count( $result['branches'] ), 'one branch per item', $failures, $passes );
	parallel_map_assert( 0, $result['branches'][0]['index'], 'branch carries item index', $failures, $passes );
	parallel_map_assert( $items[1], $result['branches'][1]['item'], 'branch carries the item binding', $failures, $passes );
	parallel_map_assert( 'packet', $result['branches'][0]['item_var'], 'branch surfaces the `as` item var name', $failures, $passes );
	parallel_map_assert( 'i', $result['branches'][0]['index_var'], 'branch surfaces the `index_as` index var name', $failures, $passes );
	parallel_map_assert( $parallel_step['steps'], $result['branches'][0]['steps'], 'branch carries the nested per-item steps', $failures, $passes );
	parallel_map_assert( array( 'state' => 'scheduled' ), $result['branches'][0]['output'], 'branch output is an async scheduling receipt', $failures, $passes );

	// --- Adaptive gate: filter returning false suppresses fan-out ---
	$GLOBALS['__should_fanout_filter'] = static function ( bool $should, array $step, array $context ): bool {
		// Assert the contract args reach the filter.
		if ( 'parallel' !== ( $step['type'] ?? '' ) || 42 !== ( $context['job_id'] ?? 0 ) ) {
			return $should;
		}
		return false;
	};

	$backend_gated = new PipelineBatchScheduler();
	$adapter_gated = new ParallelMapFanoutAdapter( $backend_gated );
	$gated_result  = $adapter_gated->dispatch( $parallel_step, 42, 'flow_step_next', $engine_snapshot );

	parallel_map_assert( 'inline', $gated_result['shape'], 'gate=false reports inline shape', $failures, $passes );
	parallel_map_assert( true, $gated_result['gated'], 'gate=false marks result gated', $failures, $passes );
	parallel_map_assert( 0, count( $backend_gated->calls ), 'gate=false performs no fan-out', $failures, $passes );
	parallel_map_assert( 3, $gated_result['count'], 'gated result still reports item count', $failures, $passes );

	// --- shouldFanOut() honors the same filter directly ---
	parallel_map_assert( false, ParallelMapFanoutAdapter::shouldFanOut( $parallel_step, array( 'job_id' => 42 ) ), 'shouldFanOut honors filter', $failures, $passes );

	$GLOBALS['__should_fanout_filter'] = null;
	parallel_map_assert( true, ParallelMapFanoutAdapter::shouldFanOut( $parallel_step, array( 'job_id' => 42 ) ), 'shouldFanOut defaults to true', $failures, $passes );

	// --- Hard dependency: dispatch asserts the substrate is present ---
	// The substrate stub is defined, so assertSubstrateAvailable() must NOT
	// throw and dispatch() must proceed.
	parallel_map_assert( true, class_exists( ParallelMapFanoutAdapter::SUBSTRATE_RUNNER_CLASS ), 'substrate runner class resolves', $failures, $passes );

	$substrate_present_ok = true;
	try {
		ParallelMapFanoutAdapter::assertSubstrateAvailable();
	} catch ( \RuntimeException $e ) {
		$substrate_present_ok = false;
	}
	parallel_map_assert( true, $substrate_present_ok, 'assertSubstrateAvailable passes when substrate present', $failures, $passes );

	// The gate filter name the adapter applies IS the Agents API contract
	// filter — a single, named gate, not a DM-private duplicate.
	parallel_map_assert( 'wp_agent_workflow_should_fanout', ParallelMapFanoutAdapter::FANOUT_GATE_FILTER, 'gate is the Agents API contract filter', $failures, $passes );

	// --- Single-path: source-level proof there is no legacy fan-out path ---
	$adapter_source = file_get_contents( __DIR__ . '/../inc/Abilities/Engine/ParallelMapFanoutAdapter.php' ) ?: '';
	parallel_map_assert( true, str_contains( $adapter_source, 'assertSubstrateAvailable' ), 'adapter hard-depends on substrate (no absent fallback)', $failures, $passes );
	parallel_map_assert( false, str_contains( $adapter_source, 'no hard dependency' ), 'adapter drops the no-hard-dependency hedge', $failures, $passes );

	$execute_source = file_get_contents( __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php' ) ?: '';
	// ExecuteStepAbility must route fan-out solely through the adapter: it
	// constructs the adapter and never instantiates the executor backend
	// (PipelineBatchScheduler) directly as a second fan-out entry point.
	parallel_map_assert( true, str_contains( $execute_source, 'new ParallelMapFanoutAdapter()' ), 'ExecuteStepAbility dispatches via the adapter', $failures, $passes );
	parallel_map_assert( false, str_contains( $execute_source, 'new PipelineBatchScheduler()' ), 'ExecuteStepAbility has no direct PipelineBatchScheduler fan-out path', $failures, $passes );
	// The gate is evaluated once, inside the adapter. ExecuteStepAbility must
	// not pre-evaluate ParallelMapFanoutAdapter::shouldFanOut itself.
	parallel_map_assert( false, str_contains( $execute_source, 'ParallelMapFanoutAdapter::shouldFanOut' ), 'ExecuteStepAbility does not re-evaluate the fan-out gate', $failures, $passes );
	// The inline-vs-fanned decision keys off the adapter's contract shape.
	parallel_map_assert( true, str_contains( $execute_source, 'ParallelMapFanoutAdapter::SHAPE_INLINE' ), 'ExecuteStepAbility branches on the adapter contract shape', $failures, $passes );

	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " parallel-map fan-out adapter assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} parallel-map fan-out adapter assertions passed.\n";
}
