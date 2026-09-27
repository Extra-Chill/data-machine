<?php
/**
 * Pure-PHP smoke test for the `datamachine_flow` workflow step type
 * (Extra-Chill/data-machine#3562): runs a persisted Data Machine flow and
 * awaits its terminal run-result through the generic `await` suspend/resume
 * primitive (Automattic/agents-api#577, v0.15.0).
 *
 * Run with: php tests/datamachine-flow-await-step-smoke.php
 *
 * Covers:
 *   - structural validation (missing/invalid `flow_id`, `initial_data`,
 *     `timeout_seconds`, and that a `${...}` binding token defers the check);
 *   - the step type is registered with the real
 *     `WP_Agent_Workflow_Step_Type_Registry` (handler + validate wired);
 *   - the handler dispatches `datamachine/run-flow`, persists the await
 *     linkage on the resulting flow job's engine_data, and returns the
 *     `_suspend => { kind: await, wait_id }` directive;
 *   - a skipped flow (no job admitted) succeeds immediately with no suspend;
 *   - ability-level failures pass through as WP_Error;
 *   - `datamachine_job_terminal_committed` completes the wait with the
 *     canonical run-result envelope on both the succeeded and failed paths;
 *   - a job with no await linkage is ignored;
 *   - a transient completion outcome (reconcile lock contention, or the
 *     owning run not yet suspended — the fast-job race) schedules exactly
 *     one Action Scheduler retry;
 *   - a permanent mismatch does not schedule a retry.
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Jobs {
	/**
	 * Minimal in-memory Jobs stand-in: only the surface
	 * DataMachineFlowAwaitStep and EngineData::merge()/retrieve() touch.
	 *
	 * Backed by STATIC storage: `DataMachine\Core\EngineData` always
	 * constructs its own `new Jobs()` internally (no dependency injection),
	 * so every instance — the test's own `$jobs` handle and EngineData's
	 * private ones — must share one underlying store.
	 */
	class Jobs {
		/** @var array<int,array<string,mixed>> */
		public static array $store = array();
		public static int $next_id = 100;

		public function create_job( array $job_data ): int|false {
			$job_id               = self::$next_id++;
			self::$store[ $job_id ] = array_merge(
				$job_data,
				array(
					'job_id'      => $job_id,
					'status'      => 'pending',
					'engine_data' => array(),
				)
			);
			return $job_id;
		}

		public function start_job( int $job_id, string $status = 'processing' ): bool {
			self::$store[ $job_id ]['status'] = $status;
			return true;
		}

		public function complete_job( int $job_id, string $status ): bool {
			self::$store[ $job_id ]['status'] = $status;
			return true;
		}

		public function store_engine_data( int $job_id, array $data ): bool {
			self::$store[ $job_id ]['engine_data'] = $data;
			return true;
		}

		public function retrieve_engine_data( int $job_id ): array {
			$engine = self::$store[ $job_id ]['engine_data'] ?? array();
			return is_array( $engine ) ? $engine : array();
		}

		public function compare_and_swap_engine_data( int $job_id, array $expected_data, array $new_data ): array {
			if ( wp_json_encode( $this->retrieve_engine_data( $job_id ) ) !== wp_json_encode( $expected_data ) ) {
				return array(
					'updated'  => false,
					'conflict' => true,
					'error'    => 'conflict',
				);
			}
			self::$store[ $job_id ]['engine_data'] = $new_data;
			return array( 'updated' => true );
		}

		public function get_job( int $job_id ): ?array {
			return self::$store[ $job_id ] ?? null;
		}
	}
}

namespace AgentsAPI\AI\Workflows {
	// Stand in for register-workflow-await.php's completion entry point.
	// Fully faked (rather than requiring the real substrate file) so every
	// test scenario controls the exact `agents_workflow_complete_wait()`
	// outcome DataMachineFlowAwaitStep must react to, including the two
	// specific transient shapes agents-api#577 documents: a WP_Error, and a
	// plain (non-error) WP_Agent_Workflow_Run_Result whose run has not
	// suspended yet.
	$GLOBALS['__complete_wait_calls'] = array();
	$GLOBALS['__complete_wait_next']  = null;

	/**
	 * @param array<string,mixed> $completion
	 * @return WP_Agent_Workflow_Run_Result|\WP_Error
	 */
	function agents_workflow_complete_wait( string $runtime, string $run_id, string $wait_id, array $completion ) {
		$GLOBALS['__complete_wait_calls'][] = array(
			'runtime'    => $runtime,
			'run_id'     => $run_id,
			'wait_id'    => $wait_id,
			'completion' => $completion,
		);

		$responder = $GLOBALS['__complete_wait_next'];
		if ( is_callable( $responder ) ) {
			return $responder( $runtime, $run_id, $wait_id, $completion );
		}

		return new WP_Agent_Workflow_Run_Result( $run_id, 'test/workflow', WP_Agent_Workflow_Run_Result::STATUS_SUCCEEDED, array(), array(), array(), array(), time(), time(), array() );
	}
}

namespace {

	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data() { return $this->data; }
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
	}
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return '2026-05-28 00:00:00'; }
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( string $key ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', $key ) ); }
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( string $value ): string { return trim( $value ); }
	}
	if ( ! function_exists( 'wp_rand' ) ) {
		function wp_rand( int $min = 0, int $max = 0 ): int { return $max > $min ? random_int( $min, $max ) : $min; }
	}
	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $key, string $group = '' ) { return $GLOBALS['__wp_cache'][ $group ][ $key ] ?? false; }
	}
	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $key, $value, string $group = '' ): bool { $GLOBALS['__wp_cache'][ $group ][ $key ] = $value; return true; }
	}

	// Real, functional add_action/do_action so the terminal-commit hook is
	// exercised exactly as Jobs::reconcile_terminal_accounting() fires it,
	// not called directly.
	$GLOBALS['__hooks']              = array();
	$GLOBALS['__scheduled_as']       = array();
	$GLOBALS['__datamachine_log']    = array();
	$GLOBALS['__abilities']          = array();

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			$GLOBALS['__hooks'][ $hook ][] = array( $callback, $accepted_args );
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			if ( 'datamachine_log' === $hook ) {
				$GLOBALS['__datamachine_log'][] = $args;
			}
			foreach ( $GLOBALS['__hooks'][ $hook ] ?? array() as $registered ) {
				[$callback, $accepted_args] = $registered;
				call_user_func_array( $callback, array_slice( $args, 0, $accepted_args ) );
			}
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) { unset( $hook, $args ); return $value; }
	}

	// Deterministic fake Action Scheduler: records scheduling calls, no real
	// timers. Mirrors vendor/wordpress/agents-api's own await smoke test.
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
			$GLOBALS['__scheduled_as'][] = array(
				'timestamp' => $timestamp,
				'hook'      => $hook,
				'args'      => $args,
				'group'     => $group,
			);
			return count( $GLOBALS['__scheduled_as'] );
		}
	}
	if ( ! function_exists( 'as_has_scheduled_action' ) ) {
		function as_has_scheduled_action( string $hook, $args = null, string $group = '' ): bool {
			foreach ( $GLOBALS['__scheduled_as'] as $scheduled ) {
				if ( $scheduled['hook'] === $hook && $scheduled['group'] === $group && ( null === $args || $scheduled['args'] === $args ) ) {
					return true;
				}
			}
			return false;
		}
	}

	// Minimal Abilities API stand-in for WP_Agent_Ability_Dispatcher::dispatch().
	if ( ! class_exists( 'WP_Ability' ) ) {
		class WP_Ability {
			public function __construct( private \Closure $handler ) {}
			public function execute( $input = null ) { return ( $this->handler )( is_array( $input ) ? $input : array() ); }
		}
	}
	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; }
	}
	function register_flow_await_demo_ability( string $name, \Closure $handler ): void {
		$GLOBALS['__abilities'][ $name ] = new WP_Ability( $handler );
	}

	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-parameters.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Abilities/class-wp-agent-ability-dispatcher.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-step-type-registry.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-spec-validator.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/register-workflow-step-types.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-run-result.php';
	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Core/JobArtifactSurfaces.php';
	require_once __DIR__ . '/../inc/Core/StepResult.php';
	require_once __DIR__ . '/../inc/Core/RunMetrics.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Runtime/class-wp-agent-run-result-envelope.php';
	require_once __DIR__ . '/../inc/Core/RunResultEnvelope.php';
	require_once __DIR__ . '/../inc/Core/EngineData.php';
	require_once __DIR__ . '/../inc/Core/ActionScheduler/GroupRegistrar.php';
	require_once __DIR__ . '/../inc/Core/Workflows/DataMachineWorkflowRuntime.php';
	require_once __DIR__ . '/../inc/Core/Workflows/DataMachineFlowAwaitStep.php';

	use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
	use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Step_Type_Registry;
	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Core\Workflows\DataMachineFlowAwaitStep;

	$failures = array();
	$passes   = 0;

	function flow_await_assert( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

	function flow_await_assert_true( $actual, string $name, array &$failures, int &$passes ): void {
		flow_await_assert( true, (bool) $actual, $name, $failures, $passes );
	}

	echo "datamachine-flow-await-step-smoke\n";

	// ═══════════════════════════════════════════════════════════════════
	// 1. Structural validation.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[1] validate()\n";

	flow_await_assert(
		array(),
		DataMachineFlowAwaitStep::validate( array( 'id' => 's', 'type' => 'datamachine_flow', 'flow_id' => 42 ), 'steps.0' ),
		'a valid literal int flow_id passes',
		$failures,
		$passes
	);
	flow_await_assert(
		array(),
		DataMachineFlowAwaitStep::validate( array( 'id' => 's', 'type' => 'datamachine_flow', 'flow_id' => '42' ), 'steps.0' ),
		'a valid numeric-string flow_id passes',
		$failures,
		$passes
	);

	$missing = DataMachineFlowAwaitStep::validate( array( 'id' => 's', 'type' => 'datamachine_flow' ), 'steps.0' );
	flow_await_assert( 1, count( $missing ), 'missing flow_id produces exactly one error', $failures, $passes );
	flow_await_assert( 'missing_required', $missing[0]['code'] ?? null, 'missing flow_id error code', $failures, $passes );
	flow_await_assert( 'steps.0.flow_id', $missing[0]['path'] ?? null, 'missing flow_id error path', $failures, $passes );

	$invalid_flow_id = DataMachineFlowAwaitStep::validate( array( 'flow_id' => 0 ), 'steps.0' );
	flow_await_assert( 1, count( $invalid_flow_id ), 'zero flow_id is rejected as invalid, not missing', $failures, $passes );
	flow_await_assert( 'invalid_type', $invalid_flow_id[0]['code'] ?? null, 'zero flow_id error code', $failures, $passes );

	flow_await_assert(
		array(),
		DataMachineFlowAwaitStep::validate( array( 'flow_id' => '${inputs.flow_id}' ), 'steps.0' ),
		'an unresolved ${...} binding token for flow_id defers the type check',
		$failures,
		$passes
	);

	$bad_initial_data = DataMachineFlowAwaitStep::validate( array( 'flow_id' => 1, 'initial_data' => 'not-an-object' ), 'steps.0' );
	flow_await_assert( 1, count( $bad_initial_data ), 'non-array initial_data is rejected', $failures, $passes );
	flow_await_assert( 'steps.0.initial_data', $bad_initial_data[0]['path'] ?? null, 'initial_data error path', $failures, $passes );

	$bad_timeout = DataMachineFlowAwaitStep::validate( array( 'flow_id' => 1, 'timeout_seconds' => -5 ), 'steps.0' );
	flow_await_assert( 1, count( $bad_timeout ), 'a negative timeout_seconds is rejected', $failures, $passes );
	flow_await_assert( 'steps.0.timeout_seconds', $bad_timeout[0]['path'] ?? null, 'timeout_seconds error path', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 2. Registration through the real step-type registry.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[2] step type registration\n";

	$jobs = new Jobs();
	$step = new DataMachineFlowAwaitStep( $jobs );

	$registered = WP_Agent_Workflow_Step_Type_Registry::get( DataMachineFlowAwaitStep::STEP_TYPE );
	flow_await_assert_true( null !== $registered, 'datamachine_flow is registered in the real step-type registry', $failures, $passes );
	flow_await_assert_true( is_callable( $registered['handler'] ?? null ), 'registered handler is callable', $failures, $passes );
	flow_await_assert_true( is_callable( $registered['validate'] ?? null ), 'registered validate callback is callable', $failures, $passes );

	$registry_errors = WP_Agent_Workflow_Step_Type_Registry::validate_step( array( 'id' => 's', 'type' => 'datamachine_flow' ), 'steps.0' );
	flow_await_assert( 1, count( $registry_errors ), 'the registry dispatches our validate callback for a missing flow_id', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 3. Handler: happy path — suspend + await linkage persisted.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[3] handle() — happy path\n";

	// Mirrors the real RunFlowAbility::execute() contract: every dispatch
	// admits a genuinely NEW Data Machine job (never a fixed id), so each
	// `datamachine_flow` step invocation gets its own distinct flow job to
	// link and later terminalize independently.
	register_flow_await_demo_ability(
		'datamachine/run-flow',
		static function ( array $input ) use ( $jobs ): array {
			$job_id = $jobs->create_job( array( 'source' => 'pipeline', 'flow_id' => (int) ( $input['flow_id'] ?? 0 ) ) );
			$jobs->start_job( $job_id );
			return array(
				'success'    => true,
				'flow_id'    => (int) ( $input['flow_id'] ?? 0 ),
				'job_id'     => $job_id,
				'first_step' => 'fetch_1',
			);
		}
	);

	$handled = $step->handle(
		array( 'flow_id' => 7 ),
		array( '_workflow_run_id' => 'run-happy-1' )
	);

	flow_await_assert_true( is_array( $handled ), 'handler returns an array (not WP_Error)', $failures, $passes );
	flow_await_assert( 'await', $handled['_suspend']['kind'] ?? null, 'handler returns an await suspend directive', $failures, $passes );

	$flow_job_id = isset( $handled['_suspend']['wait_id'] ) ? (int) $handled['_suspend']['wait_id'] : 0;
	flow_await_assert_true( $flow_job_id > 0, 'suspend wait_id resolves to a real, positive flow job id', $failures, $passes );
	flow_await_assert( (string) $flow_job_id, $handled['_suspend']['wait_id'] ?? null, 'suspend wait_id is the flow job id as a string', $failures, $passes );
	flow_await_assert_true( ! isset( $handled['_suspend']['timeout_at'] ), 'no timeout_at without a timeout_seconds field', $failures, $passes );

	$linked_job = $jobs->get_job( $flow_job_id );
	$link       = $linked_job['engine_data']['agents_workflow_await'] ?? null;
	flow_await_assert( 'run-happy-1', $link['run_id'] ?? null, 'await linkage records the owning workflow run id', $failures, $passes );
	flow_await_assert( 'datamachine', $link['runtime'] ?? null, 'await linkage records the datamachine runtime key', $failures, $passes );
	flow_await_assert( (string) $flow_job_id, $link['wait_id'] ?? null, 'await linkage records the flow job id as wait_id', $failures, $passes );

	// timeout_seconds → timeout_at rides the suspend directive.
	$before        = time();
	$handled_ttl   = $step->handle( array( 'flow_id' => 7, 'timeout_seconds' => 120 ), array( '_workflow_run_id' => 'run-ttl-1' ) );
	$timeout_at    = $handled_ttl['_suspend']['timeout_at'] ?? null;
	flow_await_assert_true( is_int( $timeout_at ) && $timeout_at >= $before + 120, 'timeout_seconds becomes an absolute timeout_at on the suspend directive', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 4. Handler: skipped flow (no job admitted) succeeds without suspending.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[4] handle() — skipped flow (no job admitted)\n";

	$GLOBALS['__abilities']['datamachine/run-flow'] = new WP_Ability(
		static function ( array $input ): array {
			unset( $input );
			return array(
				'success'    => true,
				'flow_id'    => 7,
				'job_id'     => null,
				'first_step' => 'fetch_1',
				'skipped'    => true,
				'reason'     => 'empty_drain_queue',
			);
		}
	);

	$skipped = $step->handle( array( 'flow_id' => 7 ), array( '_workflow_run_id' => 'run-skip-1' ) );
	flow_await_assert_true( is_array( $skipped ) && ! isset( $skipped['_suspend'] ), 'a skipped run (no job_id) succeeds immediately, no suspend', $failures, $passes );
	flow_await_assert_true( true === ( $skipped['skipped'] ?? null ), 'the ability output (skipped: true) is returned as the step output', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 5. Handler: ability-level failure passes through as WP_Error.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[5] handle() — ability failure\n";

	$GLOBALS['__abilities']['datamachine/run-flow'] = new WP_Ability(
		static function ( array $input ): \WP_Error {
			unset( $input );
			return new \WP_Error( 'flow_not_found', 'Flow 999 not found.' );
		}
	);

	$failed_dispatch = $step->handle( array( 'flow_id' => 999 ), array( '_workflow_run_id' => 'run-fail-1' ) );
	flow_await_assert_true( is_wp_error( $failed_dispatch ), 'an ability-level WP_Error passes through unchanged', $failures, $passes );
	flow_await_assert( 'flow_not_found', $failed_dispatch->get_error_code(), 'the original error code is preserved', $failures, $passes );

	$missing_flow_id = $step->handle( array(), array( '_workflow_run_id' => 'run-no-id' ) );
	flow_await_assert_true( is_wp_error( $missing_flow_id ), 'a missing/zero flow_id at handler time is a WP_Error', $failures, $passes );
	flow_await_assert( 'datamachine_flow_invalid_flow_id', $missing_flow_id->get_error_code(), 'invalid flow_id error code', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 6. Terminal hook: unlinked jobs are ignored.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[6] onJobTerminalCommitted() — unlinked job is ignored\n";

	$unlinked_job_id = $jobs->create_job( array( 'source' => 'pipeline' ) );
	$jobs->complete_job( $unlinked_job_id, 'completed' );

	$GLOBALS['__complete_wait_calls'] = array();
	do_action( 'datamachine_job_terminal_committed', $unlinked_job_id, 'completed' );
	flow_await_assert( array(), $GLOBALS['__complete_wait_calls'], 'a job with no await linkage never calls agents_workflow_complete_wait', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 7. Terminal hook: succeeded path completes the wait with the envelope.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[7] onJobTerminalCommitted() — succeeded path\n";

	$GLOBALS['__complete_wait_calls'] = array();
	$GLOBALS['__complete_wait_next']  = null;
	$jobs->complete_job( $flow_job_id, 'completed' );

	do_action( 'datamachine_job_terminal_committed', $flow_job_id, 'completed' );

	flow_await_assert( 1, count( $GLOBALS['__complete_wait_calls'] ), 'the succeeded terminal commit calls agents_workflow_complete_wait exactly once', $failures, $passes );
	$succeeded_call = $GLOBALS['__complete_wait_calls'][0];
	flow_await_assert( 'datamachine', $succeeded_call['runtime'], 'completion call carries the datamachine runtime key', $failures, $passes );
	flow_await_assert( 'run-happy-1', $succeeded_call['run_id'], 'completion call carries the owning workflow run id', $failures, $passes );
	flow_await_assert( (string) $flow_job_id, $succeeded_call['wait_id'], 'completion call carries the flow job id as wait_id', $failures, $passes );
	flow_await_assert( 'succeeded', $succeeded_call['completion']['status'] ?? null, 'succeeded completion status is `succeeded`', $failures, $passes );
	flow_await_assert(
		'agents-api/run-result/v1',
		$succeeded_call['completion']['output']['schema'] ?? null,
		'succeeded completion output is the canonical run-result envelope',
		$failures,
		$passes
	);
	flow_await_assert( (string) $flow_job_id, $succeeded_call['completion']['output']['run_id'] ?? null, 'the envelope carries the flow job id as its run_id', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 8. Terminal hook: failed path completes the wait as failed.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[8] onJobTerminalCommitted() — failed path\n";

	$failed_job_id = $jobs->create_job( array( 'source' => 'pipeline' ) );
	$jobs->store_engine_data(
		$failed_job_id,
		array(
			'agents_workflow_await' => array(
				'run_id'  => 'run-failed-1',
				'runtime' => 'datamachine',
				'wait_id' => (string) $failed_job_id,
			),
		)
	);
	$jobs->complete_job( $failed_job_id, 'failed - handler_exception' );

	$GLOBALS['__complete_wait_calls'] = array();
	$GLOBALS['__complete_wait_next']  = null;
	do_action( 'datamachine_job_terminal_committed', $failed_job_id, 'failed - handler_exception' );

	flow_await_assert( 1, count( $GLOBALS['__complete_wait_calls'] ), 'the failed terminal commit calls agents_workflow_complete_wait exactly once', $failures, $passes );
	$failed_call = $GLOBALS['__complete_wait_calls'][0];
	flow_await_assert( 'failed', $failed_call['completion']['status'] ?? null, 'failed completion status is `failed`', $failures, $passes );
	flow_await_assert( 'failed', $failed_call['completion']['error']['code'] ?? null, 'failed completion error code strips the compound reason down to the base status', $failures, $passes );
	flow_await_assert_true( ! empty( $failed_call['completion']['error']['message'] ?? '' ), 'failed completion carries a human-readable message', $failures, $passes );
	flow_await_assert(
		'agents-api/run-result/v1',
		$failed_call['completion']['error']['run_result']['schema'] ?? null,
		'failed completion error carries the canonical run-result envelope',
		$failures,
		$passes
	);

	// A `cancelled` job status is treated as a failed completion too.
	$cancelled_job_id = $jobs->create_job( array( 'source' => 'pipeline' ) );
	$jobs->store_engine_data(
		$cancelled_job_id,
		array(
			'agents_workflow_await' => array(
				'run_id'  => 'run-cancelled-1',
				'runtime' => 'datamachine',
				'wait_id' => (string) $cancelled_job_id,
			),
		)
	);
	$jobs->complete_job( $cancelled_job_id, 'cancelled' );

	$GLOBALS['__complete_wait_calls'] = array();
	do_action( 'datamachine_job_terminal_committed', $cancelled_job_id, 'cancelled' );
	flow_await_assert( 'failed', $GLOBALS['__complete_wait_calls'][0]['completion']['status'] ?? null, 'a cancelled job status also completes the wait as failed', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 9. Retry: reconcile lock contention schedules exactly one retry.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[9] retry — reconcile lock contention\n";

	$contended_job_id = $jobs->create_job( array( 'source' => 'pipeline' ) );
	$jobs->store_engine_data(
		$contended_job_id,
		array(
			'agents_workflow_await' => array(
				'run_id'  => 'run-contended-1',
				'runtime' => 'datamachine',
				'wait_id' => (string) $contended_job_id,
			),
		)
	);
	$jobs->complete_job( $contended_job_id, 'completed' );

	$GLOBALS['__scheduled_as']       = array();
	$GLOBALS['__complete_wait_next'] = static fn() => new \WP_Error( 'agents_reconcile_lock_unavailable', 'Could not commit an await wait completion; retry the persisted reconcile continuation.' );

	do_action( 'datamachine_job_terminal_committed', $contended_job_id, 'completed' );

	flow_await_assert( 1, count( $GLOBALS['__scheduled_as'] ), 'reconcile lock contention schedules exactly one retry', $failures, $passes );
	$scheduled = $GLOBALS['__scheduled_as'][0];
	flow_await_assert( 'datamachine_flow_await_step_retry_completion', $scheduled['hook'], 'retry is scheduled under the flow-await retry hook', $failures, $passes );
	flow_await_assert( array( $contended_job_id, 'completed', 1 ), $scheduled['args'], 'retry payload carries the job id, terminal status and attempt number', $failures, $passes );
	flow_await_assert( 'data-machine', $scheduled['group'], 'retry is scheduled under the data-machine Action Scheduler group', $failures, $passes );

	// A second terminal-commit style attempt with the SAME (job_id, status)
	// while a retry is still pending does not stack a duplicate.
	do_action( 'datamachine_job_terminal_committed', $contended_job_id, 'completed' );
	flow_await_assert( 1, count( $GLOBALS['__scheduled_as'] ), 'a duplicate contention outcome for the same job does not stack a second retry', $failures, $passes );

	// A retry that is still contended schedules the NEXT attempt number.
	$GLOBALS['__scheduled_as']       = array();
	$GLOBALS['__complete_wait_next'] = static fn() => new \WP_Error( 'agents_reconcile_lock_unavailable', 'contended' );
	do_action( 'datamachine_flow_await_step_retry_completion', $contended_job_id, 'completed', 3 );
	flow_await_assert( array( $contended_job_id, 'completed', 4 ), $GLOBALS['__scheduled_as'][0]['args'] ?? null, 'a contended retry schedules the next attempt number', $failures, $passes );

	// At the ceiling, no further retry is scheduled (no unbounded background loop).
	$GLOBALS['__scheduled_as']       = array();
	$GLOBALS['__complete_wait_next'] = static fn() => new \WP_Error( 'agents_reconcile_lock_unavailable', 'contended' );
	do_action( 'datamachine_flow_await_step_retry_completion', $contended_job_id, 'completed', 20 );
	flow_await_assert( 0, count( $GLOBALS['__scheduled_as'] ), 'the retry ceiling stops scheduling further attempts', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 10. Retry: the fast-job race (owning run not yet suspended) also
	//     schedules a retry — a non-error, non-suspended run result.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[10] retry — fast-job race (owning run not yet suspended)\n";

	$fast_job_id = $jobs->create_job( array( 'source' => 'pipeline' ) );
	$jobs->store_engine_data(
		$fast_job_id,
		array(
			'agents_workflow_await' => array(
				'run_id'  => 'run-fast-1',
				'runtime' => 'datamachine',
				'wait_id' => (string) $fast_job_id,
			),
		)
	);
	$jobs->complete_job( $fast_job_id, 'completed' );

	$GLOBALS['__scheduled_as']       = array();
	$GLOBALS['__complete_wait_next'] = static fn( string $runtime, string $run_id ) => new WP_Agent_Workflow_Run_Result( $run_id, 'test/workflow', WP_Agent_Workflow_Run_Result::STATUS_RUNNING, array(), array(), array(), array(), time(), 0, array() );

	do_action( 'datamachine_job_terminal_committed', $fast_job_id, 'completed' );

	flow_await_assert( 1, count( $GLOBALS['__scheduled_as'] ), 'a not-yet-suspended owning run (the fast-job race) schedules a retry', $failures, $passes );
	flow_await_assert( 'datamachine_flow_await_step_retry_completion', $GLOBALS['__scheduled_as'][0]['hook'] ?? null, 'fast-job retry uses the same retry hook', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 11. No retry for a permanent mismatch — logged instead.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[11] no retry for a permanent mismatch\n";

	$mismatched_job_id = $jobs->create_job( array( 'source' => 'pipeline' ) );
	$jobs->store_engine_data(
		$mismatched_job_id,
		array(
			'agents_workflow_await' => array(
				'run_id'  => 'run-mismatch-1',
				'runtime' => 'datamachine',
				'wait_id' => (string) $mismatched_job_id,
			),
		)
	);
	$jobs->complete_job( $mismatched_job_id, 'completed' );

	$GLOBALS['__scheduled_as']       = array();
	$GLOBALS['__datamachine_log']    = array();
	$GLOBALS['__complete_wait_next'] = static fn() => new \WP_Error( 'agents_workflow_complete_wait_unknown_wait', 'wait_id does not match the suspended wait.' );

	do_action( 'datamachine_job_terminal_committed', $mismatched_job_id, 'completed' );

	flow_await_assert( array(), $GLOBALS['__scheduled_as'], 'a permanent mismatch (wrong wait_id) does not schedule a retry', $failures, $passes );
	flow_await_assert_true( count( $GLOBALS['__datamachine_log'] ) > 0, 'a permanent mismatch is logged for operator visibility', $failures, $passes );

	// ═══════════════════════════════════════════════════════════════════
	// 12. The terminal hook never throws even if job lookup misbehaves.
	// ═══════════════════════════════════════════════════════════════════
	echo "\n[12] onJobTerminalCommitted() never throws\n";

	$threw = false;
	try {
		// A job id with no row at all — get_job() returns null; must be a
		// silent no-op, not a fatal.
		do_action( 'datamachine_job_terminal_committed', 999999, 'completed' );
	} catch ( \Throwable $e ) {
		$threw = true;
	}
	flow_await_assert_true( ! $threw, 'a terminal-commit for an unknown job id does not throw', $failures, $passes );

	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " datamachine-flow-await-step assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} datamachine-flow-await-step assertions passed.\n";
}
