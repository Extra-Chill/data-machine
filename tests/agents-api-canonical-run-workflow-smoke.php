<?php
/**
 * Pure-PHP smoke test for Data Machine running behind the canonical Agents
 * API `agents/run-workflow` ability (Extra-Chill/data-machine#3552).
 *
 * Replaces the deleted per-ability workflow bridge smoke test, which
 * exercised the old ability directly. This test exercises the real dispatch
 * path instead:
 *
 *   - `\AgentsAPI\AI\Workflows\agents_run_workflow_dispatch()` routes to
 *     `DataMachineWorkflowRuntime::run()` only when the dispatch resolves to
 *     the `datamachine` runtime key (#567 runtime-scoped handler map).
 *   - `AgentsApiWorkflowJobRecorder` is resolved through the scoped
 *     `wp_agent_workflow_run_recorder` filter, not built request-locally.
 *   - `\AgentsAPI\AI\Workflows\agents_get_workflow_run()` resolves Data
 *     Machine runs through `wp_agent_workflow_run_status_handler`.
 *   - A second, independently-registered fake "intelligence" recorder proves
 *     the seams are scoped: each runtime resolves only the runs it owns,
 *     mirroring the real Intelligence + Data Machine coexistence on
 *     studio.extrachill.com.
 *
 * Run with: php tests/agents-api-canonical-run-workflow-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Core\Database\Jobs {
	class Jobs {
		public array $jobs = array();
		public array $engine_data = array();
		public int $next_id = 100;

		public function create_job( array $job_data ): int|false {
			$job_id = $this->next_id++;
			$this->jobs[ $job_id ] = array_merge( $job_data, array( 'status' => 'pending' ) );
			return $job_id;
		}

		public function start_job( int $job_id, string $status = 'processing' ): bool {
			$this->jobs[ $job_id ]['status'] = $status;
			return true;
		}

		public function complete_job( int $job_id, string $status ): bool {
			$this->jobs[ $job_id ]['status'] = $status;
			return true;
		}

		public function store_engine_data( int $job_id, array $data ): bool {
			$this->engine_data[ $job_id ] = $data;
			return true;
		}

		public function get_jobs_for_list_table( array $args ): array {
			$rows = array();
			foreach ( $this->jobs as $job_id => $job ) {
				if ( isset( $args['source'] ) && ( $job['source'] ?? null ) !== $args['source'] ) {
					continue;
				}

				$engine_data = $this->engine_data[ $job_id ] ?? array();
				$encoded     = json_encode( $engine_data );
				foreach ( (array) ( $args['engine_data_contains'] ?? array() ) as $marker ) {
					if ( is_string( $marker ) && '' !== $marker && false === strpos( $encoded, $marker ) ) {
						continue 2;
					}
				}

				$rows[] = array_merge( array( 'job_id' => $job_id, 'engine_data' => $engine_data ), $job );
			}

			usort( $rows, static fn( array $a, array $b ): int => $b['job_id'] <=> $a['job_id'] );
			return array_slice( $rows, (int) ( $args['offset'] ?? 0 ), (int) ( $args['per_page'] ?? 20 ) );
		}
	}
}

namespace {

	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	defined( 'WP_CLI' ) || define( 'WP_CLI', true ); // Exercises PermissionHelper's CLI-bypass branch for the filterPermission() assertions below.

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
	if ( ! function_exists( 'doing_action' ) ) {
		function doing_action( string $hook ): bool { unset( $hook ); return false; }
	}
	if ( ! function_exists( 'did_action' ) ) {
		function did_action( string $hook ): int { unset( $hook ); return 1; }
	}
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id(): int { return 7; }
	}
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( string $capability ): bool { unset( $capability ); return true; }
	}
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in(): bool { return true; }
	}
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return '2026-05-28 00:00:00'; }
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value ) { return json_encode( $value ); }
	}

	// Real, functional add_action/add_filter/apply_filters/do_action — the
	// whole point of this test is proving the runtime-scoped filter seams
	// (#567) actually route correctly, so a passthrough stub is not enough.
	$GLOBALS['__canonical_workflow_hooks'] = array();
	$GLOBALS['__canonical_workflow_log']   = array();

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			$GLOBALS['__canonical_workflow_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
			$GLOBALS['__canonical_workflow_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value, ...$args ) {
			if ( empty( $GLOBALS['__canonical_workflow_hooks'][ $hook ] ) ) {
				return $value;
			}

			ksort( $GLOBALS['__canonical_workflow_hooks'][ $hook ] );
			foreach ( $GLOBALS['__canonical_workflow_hooks'][ $hook ] as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$value = call_user_func_array( $callback[0], array_slice( array_merge( array( $value ), $args ), 0, (int) $callback[1] ) );
				}
			}

			return $value;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {
			$GLOBALS['__canonical_workflow_log'][] = array_merge( array( $hook ), $args );
		}
	}

	// Minimal Abilities API stand-in: WP_Agent_Ability_Dispatcher::dispatch()
	// only needs wp_get_ability() to resolve a WP_Ability-like object.
	if ( ! class_exists( 'WP_Ability' ) ) {
		class WP_Ability {
			public function execute( array $input ) { unset( $input ); return null; }
		}
	}
	$GLOBALS['__canonical_workflow_abilities'] = array();
	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ) { return $GLOBALS['__canonical_workflow_abilities'][ $name ] ?? null; }
	}

	function register_canonical_workflow_demo_ability( string $name, \Closure $handler ): void {
		$GLOBALS['__canonical_workflow_abilities'][ $name ] = new class( $handler ) extends WP_Ability {
			public function __construct( private \Closure $handler ) {}
			public function execute( array $input ) { return ( $this->handler )( $input ); }
		};
	}

	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-parameters.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Abilities/class-wp-agent-ability-dispatcher.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-bindings.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-step-type-registry.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/register-workflow-step-types.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-spec-validator.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-spec.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-registry.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-run-result.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-run-recorder.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Runtime/interface-wp-agent-run-control-store.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Runtime/class-wp-agent-option-run-control-store.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Runtime/class-wp-agent-run-control.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-store.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-lifecycle.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-run-context.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/interface-wp-agent-workflow-branch-executor.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-step-executor.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-runner.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/register-agents-workflow-abilities.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/register-reconcile-workflow-branch.php';
	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Abilities/PermissionHelper.php';
	require_once __DIR__ . '/../inc/Core/AgentsApiWorkflowJobRecorder.php';
	// Referenced only for its STEP_TYPE constant (DataMachineWorkflowRuntime's
	// SUPPORTED_STEP_TYPES allow-list now also accepts `datamachine_flow`,
	// #3562) — not instantiated here, so none of its own dependencies
	// (EngineData, RunMetrics, RunResultEnvelope, GroupRegistrar) are needed.
	require_once __DIR__ . '/../inc/Core/Workflows/DataMachineFlowAwaitStep.php';
	require_once __DIR__ . '/../inc/Core/Workflows/DataMachineWorkflowRuntime.php';

	use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Registry;
	use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
	use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;
	use DataMachine\Core\AgentsApiWorkflowJobRecorder;
	use DataMachine\Core\Database\Jobs\Jobs;
	use DataMachine\Core\Workflows\DataMachineWorkflowRuntime;
	use function AgentsAPI\AI\Workflows\agents_get_workflow_run;
	use function AgentsAPI\AI\Workflows\agents_run_workflow_dispatch;
	use function AgentsAPI\AI\Workflows\agents_workflow_resolve_recorder;

	$failures = array();
	$passes   = 0;

	function assert_canonical_workflow_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

	echo "agents-api-canonical-run-workflow-smoke\n";

	register_canonical_workflow_demo_ability(
		'demo/uppercase',
		static fn( array $input ): array => array( 'value' => strtoupper( (string) ( $input['text'] ?? '' ) ) )
	);
	register_canonical_workflow_demo_ability(
		'agents/chat',
		static fn( array $input ): array => array( 'reply' => sprintf( '%s: %s', $input['agent'] ?? '', $input['message'] ?? '' ) )
	);

	$jobs    = new Jobs();
	$runtime = new DataMachineWorkflowRuntime( $jobs );

	// --- 1. Ability-step workflow, dispatched through the canonical ability ---
	$ability_result = agents_run_workflow_dispatch(
		array(
			'runtime' => 'datamachine',
			'spec'    => array(
				'id'       => 'demo/ability-workflow',
				'triggers' => array( array( 'type' => 'on_demand' ) ),
				'inputs'   => array( 'text' => array( 'type' => 'string', 'required' => true ) ),
				'steps'    => array(
					array( 'id' => 'upper', 'type' => 'ability', 'ability' => 'demo/uppercase', 'args' => array( 'text' => '${inputs.text}' ) ),
				),
			),
			'inputs'  => array( 'text' => 'cook' ),
			'options' => array(
				'run_id'    => 'run-ability-1',
				'artifacts' => array( array( 'name' => 'summary.json', 'type' => 'application/json' ) ),
				'logs'      => array( array( 'level' => 'info', 'message' => 'started' ) ),
			),
		)
	);

	assert_canonical_workflow_equals( false, is_wp_error( $ability_result ), 'ability workflow dispatch is not a native error', $failures, $passes );
	assert_canonical_workflow_equals( 'succeeded', $ability_result['status'] ?? null, 'ability workflow succeeds', $failures, $passes );
	assert_canonical_workflow_equals( 100, $ability_result['job_id'] ?? null, 'ability workflow returns Data Machine job id', $failures, $passes );
	assert_canonical_workflow_equals( 'run-ability-1', $ability_result['run_id'] ?? null, 'ability workflow preserves caller-suggested run id', $failures, $passes );
	assert_canonical_workflow_equals( 'COOK', $ability_result['steps'][0]['output']['value'] ?? null, 'ability workflow output carries step result', $failures, $passes );
	assert_canonical_workflow_equals( 'completed', $jobs->jobs[100]['status'], 'ability workflow maps success to a completed Data Machine job', $failures, $passes );
	assert_canonical_workflow_equals( 'agents_api_workflow', $jobs->jobs[100]['source'], 'ability workflow records source', $failures, $passes );
	assert_canonical_workflow_equals( 'agents/run-workflow', $jobs->engine_data[100]['provenance']['bridge'] ?? null, 'job row provenance points at the canonical ability, not the deleted bridge', $failures, $passes );
	assert_canonical_workflow_equals( 'demo/ability-workflow', $jobs->engine_data[100]['agents_api_workflow']['workflow_id'] ?? null, 'job row records workflow id', $failures, $passes );
	assert_canonical_workflow_equals( 'summary.json', $jobs->engine_data[100]['artifacts'][0]['name'] ?? null, 'canonical options.artifacts lands on the job row', $failures, $passes );
	assert_canonical_workflow_equals( 'started', $jobs->engine_data[100]['logs'][0]['message'] ?? null, 'canonical options.logs lands on the job row', $failures, $passes );

	// --- 2. find() from a fresh recorder instance (separate-process read) ---
	$fresh_recorder = new AgentsApiWorkflowJobRecorder( $jobs, array() );
	$found_result   = $fresh_recorder->find( 'run-ability-1' );
	assert_canonical_workflow_equals( 'run-ability-1', $found_result?->get_run_id(), 'fresh recorder instance finds the recorded run id', $failures, $passes );
	assert_canonical_workflow_equals( 'succeeded', $found_result?->get_status(), 'fresh recorder instance reconstructs succeeded status', $failures, $passes );

	// --- 3. Agent-step workflow ---
	$agent_result = agents_run_workflow_dispatch(
		array(
			'runtime' => 'datamachine',
			'spec'    => array(
				'id'    => 'demo/agent-workflow',
				'steps' => array(
					array( 'id' => 'ask', 'type' => 'agent', 'agent' => 'demo-agent', 'message' => 'hello' ),
				),
			),
			'options' => array( 'run_id' => 'run-agent-1' ),
		)
	);

	assert_canonical_workflow_equals( 'succeeded', $agent_result['status'] ?? null, 'agent workflow succeeds when agents/chat is registered', $failures, $passes );
	assert_canonical_workflow_equals( 101, $agent_result['job_id'] ?? null, 'agent workflow returns job id', $failures, $passes );
	assert_canonical_workflow_equals( 'demo-agent: hello', $agent_result['steps'][0]['output']['reply'] ?? null, 'agent workflow records agent step output', $failures, $passes );

	// --- 4. workflow_id resolution via the in-memory registry (no inline spec) ---
	WP_Agent_Workflow_Registry::register(
		array(
			'id'    => 'demo/registered-workflow',
			'meta'  => array( 'runtime' => 'datamachine' ),
			'steps' => array(
				array( 'id' => 'upper', 'type' => 'ability', 'ability' => 'demo/uppercase', 'args' => array( 'text' => '${inputs.text}' ) ),
			),
		)
	);

	$registered_result = agents_run_workflow_dispatch(
		array(
			'workflow_id' => 'demo/registered-workflow', // no explicit `runtime` — resolved from the registered spec's meta.runtime.
			'inputs'      => array( 'text' => 'registry' ),
			'options'     => array( 'run_id' => 'run-registered-1' ),
		)
	);

	assert_canonical_workflow_equals( 'succeeded', $registered_result['status'] ?? null, 'workflow_id dispatch resolves the runtime from the registered spec meta and succeeds', $failures, $passes );
	assert_canonical_workflow_equals( 'REGISTRY', $registered_result['steps'][0]['output']['value'] ?? null, 'workflow_id dispatch executes the registered spec', $failures, $passes );

	$unknown_workflow_id = agents_run_workflow_dispatch(
		array(
			'runtime'     => 'datamachine',
			'workflow_id' => 'demo/does-not-exist',
		)
	);
	assert_canonical_workflow_equals( true, is_wp_error( $unknown_workflow_id ), 'unknown workflow_id fails clearly', $failures, $passes );
	assert_canonical_workflow_equals( 'agents_workflow_not_found', $unknown_workflow_id->get_error_code(), 'unknown workflow_id error is typed', $failures, $passes );

	$no_spec_no_id = agents_run_workflow_dispatch( array( 'runtime' => 'datamachine' ) );
	assert_canonical_workflow_equals( true, is_wp_error( $no_spec_no_id ), 'missing spec and workflow_id fails clearly', $failures, $passes );
	assert_canonical_workflow_equals( 'invalid_spec', $no_spec_no_id->get_error_code(), 'missing spec/workflow_id error is typed', $failures, $passes );

	// --- 5. Runtime scoping: a dispatch aimed at a foreign runtime never reaches Data Machine ---
	$foreign_runtime_result = agents_run_workflow_dispatch(
		array(
			'runtime' => 'some-other-runtime',
			'spec'    => array(
				'id'    => 'demo/ability-workflow',
				'steps' => array( array( 'id' => 'upper', 'type' => 'ability', 'ability' => 'demo/uppercase' ) ),
			),
		)
	);
	assert_canonical_workflow_equals( true, is_wp_error( $foreign_runtime_result ), 'a dispatch for a foreign runtime is not silently claimed by Data Machine', $failures, $passes );
	assert_canonical_workflow_equals( 'agents_run_workflow_no_handler', $foreign_runtime_result->get_error_code(), 'foreign runtime dispatch reports no_handler, not a Data Machine result', $failures, $passes );

	// --- 6. Failed run: canonical contract returns an array with status=failed, NOT a WP_Error ---
	$missing_input = agents_run_workflow_dispatch(
		array(
			'runtime' => 'datamachine',
			'spec'    => array(
				'id'     => 'demo/missing-input-workflow',
				'inputs' => array( 'text' => array( 'type' => 'string', 'required' => true ) ),
				'steps'  => array(
					array( 'id' => 'upper', 'type' => 'ability', 'ability' => 'demo/uppercase', 'args' => array( 'text' => '${inputs.text}' ) ),
				),
			),
			'options' => array( 'run_id' => 'run-missing-input' ),
		)
	);

	assert_canonical_workflow_equals( false, is_wp_error( $missing_input ), 'a failed run is a canonical array, not a WP_Error', $failures, $passes );
	assert_canonical_workflow_equals( 'failed', $missing_input['status'] ?? null, 'failed run reports status=failed', $failures, $passes );
	assert_canonical_workflow_equals( 'missing_required_input', $missing_input['error']['code'] ?? null, 'failed run preserves the runner error code', $failures, $passes );
	assert_canonical_workflow_equals( 'failed - missing_required_input', $jobs->jobs[103]['status'] ?? null, 'failed run maps to a failed Data Machine job with the runner reason', $failures, $passes );
	assert_canonical_workflow_equals( 'failed', $fresh_recorder->find( 'run-missing-input' )?->get_status(), 'a fresh recorder instance reconstructs the failed run', $failures, $passes );

	// --- 7. Unsupported subset: foreach steps and non-on_demand triggers are rejected before running ---
	$unsupported_step = agents_run_workflow_dispatch(
		array(
			'runtime' => 'datamachine',
			'spec'    => array(
				'id'    => 'demo/foreach-workflow',
				'steps' => array(
					array( 'id' => 'each', 'type' => 'foreach', 'items' => array(), 'steps' => array( array( 'id' => 'inner', 'type' => 'ability', 'ability' => 'demo/uppercase' ) ) ),
				),
			),
		)
	);
	assert_canonical_workflow_equals( true, is_wp_error( $unsupported_step ), 'unsupported foreach step fails clearly', $failures, $passes );
	assert_canonical_workflow_equals( 'agents_api_workflow_step_unsupported', $unsupported_step->get_error_code(), 'unsupported step error is typed', $failures, $passes );

	$unsupported_trigger = agents_run_workflow_dispatch(
		array(
			'runtime' => 'datamachine',
			'spec'    => array(
				'id'       => 'demo/cron-workflow',
				'triggers' => array( array( 'type' => 'cron', 'schedule' => 'hourly' ) ),
				'steps'    => array( array( 'id' => 'upper', 'type' => 'ability', 'ability' => 'demo/uppercase' ) ),
			),
		)
	);
	assert_canonical_workflow_equals( true, is_wp_error( $unsupported_trigger ), 'unsupported cron trigger fails clearly', $failures, $passes );
	assert_canonical_workflow_equals( 'agents_api_workflow_trigger_unsupported', $unsupported_trigger->get_error_code(), 'unsupported trigger error is typed', $failures, $passes );

	// --- 8. recorder->recent() ---
	$recent = $fresh_recorder->recent( array( 'limit' => 10 ) );
	assert_canonical_workflow_equals(
		array( 'run-missing-input', 'run-registered-1', 'run-agent-1', 'run-ability-1' ),
		array_map( static fn( $result ): string => $result->get_run_id(), $recent ),
		'recorder recent returns newest workflow runs across every dispatch above',
		$failures,
		$passes
	);

	// --- 9. agents/get-workflow-run resolves Data Machine runs via the scoped status handler ---
	$run_status = agents_get_workflow_run( array( 'run_id' => 'run-ability-1' ) );
	assert_canonical_workflow_equals( false, is_wp_error( $run_status ), 'agents/get-workflow-run resolves a Data Machine run', $failures, $passes );
	assert_canonical_workflow_equals( 'run-ability-1', $run_status['run_id'] ?? null, 'agents/get-workflow-run returns the correct run id', $failures, $passes );
	assert_canonical_workflow_equals( 'succeeded', $run_status['status'] ?? null, 'agents/get-workflow-run returns the correct status', $failures, $passes );

	$unknown_run_status = agents_get_workflow_run( array( 'run_id' => 'run-does-not-exist' ) );
	assert_canonical_workflow_equals( true, is_wp_error( $unknown_run_status ), 'agents/get-workflow-run reports an unknown run id clearly', $failures, $passes );
	assert_canonical_workflow_equals( 'agents_workflow_run_not_found', $unknown_run_status->get_error_code(), 'unknown run id error is typed', $failures, $passes );

	// --- 10. Recorder coexistence: a second runtime's recorder resolves only its own runs (#567) ---
	// Mirrors Intelligence's remote-search recorder registered alongside Data Machine on studio.extrachill.com.
	class FakeIntelligenceRunRecorder implements WP_Agent_Workflow_Run_Recorder {
		public function start( WP_Agent_Workflow_Run_Result $result ) { return $result->get_run_id(); }
		public function update( WP_Agent_Workflow_Run_Result $result ) { unset( $result ); return true; }
		public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result {
			return 'fake-intelligence-run' === $run_id
				? new WP_Agent_Workflow_Run_Result( $run_id, 'intelligence/remote-search', 'succeeded', array(), array(), array(), array(), 1, 2, array() )
				: null;
		}
		public function recent( array $args = array() ): array { unset( $args ); return array(); }
	}

	$fake_intelligence_recorder = new FakeIntelligenceRunRecorder();
	add_filter(
		'wp_agent_workflow_run_recorder',
		static function ( $recorder, string $runtime = '', string $run_id = '' ) use ( $fake_intelligence_recorder ) {
			unset( $run_id );
			return 'intelligence' === $runtime ? $fake_intelligence_recorder : $recorder;
		},
		10,
		3
	);

	assert_canonical_workflow_equals(
		true,
		agents_workflow_resolve_recorder( 'datamachine', 'run-ability-1' ) instanceof AgentsApiWorkflowJobRecorder,
		'datamachine-scoped recorder resolution finds a Data Machine-owned run',
		$failures,
		$passes
	);
	assert_canonical_workflow_equals(
		null,
		agents_workflow_resolve_recorder( 'datamachine', 'unknown-run-id' ),
		'datamachine-scoped recorder resolution returns null for a run it never recorded',
		$failures,
		$passes
	);
	assert_canonical_workflow_equals(
		$fake_intelligence_recorder,
		agents_workflow_resolve_recorder( 'intelligence', 'fake-intelligence-run' ),
		'intelligence-scoped recorder resolution reaches the OTHER runtime\'s recorder untouched by Data Machine',
		$failures,
		$passes
	);
	assert_canonical_workflow_equals(
		null,
		agents_workflow_resolve_recorder( 'datamachine', 'fake-intelligence-run' ),
		'Data Machine never claims a run id that belongs to another runtime',
		$failures,
		$passes
	);

	// --- 11. Permission widening is scoped to the datamachine runtime only ---
	assert_canonical_workflow_equals(
		true,
		$runtime->filterPermission( true, array( 'runtime' => 'anything' ) ),
		'filterPermission short-circuits once the substrate already granted access',
		$failures,
		$passes
	);
	assert_canonical_workflow_equals(
		true,
		$runtime->filterPermission( false, array( 'runtime' => 'datamachine' ) ),
		'filterPermission widens to PermissionHelper::can_manage() for datamachine-targeted dispatches',
		$failures,
		$passes
	);
	assert_canonical_workflow_equals(
		false,
		$runtime->filterPermission( false, array( 'runtime' => 'some-other-runtime' ) ),
		'filterPermission leaves the substrate default untouched for foreign-runtime dispatches',
		$failures,
		$passes
	);

	// --- 12. `datamachine_flow` (#3562) is in the SUPPORTED_STEP_TYPES allow-list ---
	// Placed last so it does not shift the job-id/recorder assertions above,
	// which hardcode ids and an exact recent() run-id list. Registers a
	// trivial handler directly on the real step-type registry — proving
	// SUPPORTED_STEP_TYPES accepts the type at DM's OWN structural gate,
	// distinct from Extra-Chill/data-machine#3562's real DataMachineFlowAwaitStep
	// handler, which is exercised end to end by
	// tests/datamachine-flow-await-step-smoke.php.
	\AgentsAPI\AI\Workflows\register_workflow_step_type(
		'datamachine_flow',
		array(
			'handler' => static fn( array $step, array $context ): array => array( 'value' => $step['flow_id'] ?? null ),
		)
	);

	$datamachine_flow_step = agents_run_workflow_dispatch(
		array(
			'runtime' => 'datamachine',
			'spec'    => array(
				'id'    => 'demo/flow-await-workflow',
				'steps' => array( array( 'id' => 'run_it', 'type' => 'datamachine_flow', 'flow_id' => 42 ) ),
			),
		)
	);
	assert_canonical_workflow_equals( false, is_wp_error( $datamachine_flow_step ), 'a datamachine_flow step is no longer rejected by the bridgeable-subset gate', $failures, $passes );
	assert_canonical_workflow_equals( 'succeeded', $datamachine_flow_step['status'] ?? null, 'once registered, a datamachine_flow step runs to completion through the datamachine runtime', $failures, $passes );
	assert_canonical_workflow_equals( 42, $datamachine_flow_step['steps'][0]['output']['value'] ?? null, 'the datamachine_flow step output flows through normally', $failures, $passes );

	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " canonical run-workflow assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} canonical run-workflow assertions passed.\n";
}
