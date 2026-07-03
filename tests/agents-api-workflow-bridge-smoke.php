<?php
/**
 * Pure-PHP smoke test for bridging Agents API workflows into Data Machine jobs.
 *
 * Run with: php tests/agents-api-workflow-bridge-smoke.php
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
	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string { unset( $domain ); return $text; }
	}
	if ( ! function_exists( 'doing_action' ) ) {
		function doing_action( string $hook ): bool { unset( $hook ); return false; }
	}
	if ( ! function_exists( 'did_action' ) ) {
		function did_action( string $hook ): int { unset( $hook ); return 1; }
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { unset( $hook, $callback, $priority, $accepted_args ); }
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void { unset( $hook, $args ); }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook, $value ) { unset( $hook ); return $value; }
	}
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id(): int { return 7; }
	}
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return '2026-05-28 00:00:00'; }
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value ): string { return json_encode( $value ); }
	}

	$GLOBALS['__abilities'] = array();
	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; }
	}

	if ( ! class_exists( 'WP_Ability' ) ) {
		class WP_Ability {
			public function execute( array $input ) { unset( $input ); return null; }
			public function get_input_schema(): array { return array(); }
			public function get_meta_item( string $key, $default = null ) { unset( $key ); return $default; }
		}
	}

	if ( ! function_exists( 'wp_register_ability' ) ) {
		class Stub_Agent_Workflow_Ability extends WP_Ability {
			public function __construct( private \Closure $handler ) {}
			public function execute( array $input ) { return ( $this->handler )( $input ); }
		}
	}

	function register_agent_workflow_bridge_smoke_ability( string $name, \Closure $handler ): void {
		if ( function_exists( 'wp_register_ability' ) ) {
			global $wp_current_filter;

			if ( function_exists( 'wp_has_ability_category' ) && ! wp_has_ability_category( 'demo' ) ) {
				$wp_current_filter[] = 'wp_abilities_api_categories_init';
				wp_register_ability_category(
					'demo',
					array(
						'label'       => 'Demo',
						'description' => 'Demo abilities for workflow smoke tests.',
					)
				);
				array_pop( $wp_current_filter );
			}

			$wp_current_filter[] = 'wp_abilities_api_init';
			wp_register_ability(
				$name,
				array(
					'label'               => $name,
					'description'         => 'Workflow bridge smoke test ability.',
					'category'            => 'demo',
					'input_schema'        => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'execute_callback'    => static fn( array $input ): array => $handler( $input ),
					'permission_callback' => '__return_true',
				)
			);
			array_pop( $wp_current_filter );

			return;
		}

		$GLOBALS['__abilities'][ $name ] = new Stub_Agent_Workflow_Ability( $handler );
	}

	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-parameters.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Abilities/class-wp-agent-ability-dispatcher.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-bindings.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-spec-validator.php';
	require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workflows/class-wp-agent-workflow-spec.php';
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
	require_once __DIR__ . '/../inc/Core/JobStatus.php';
	require_once __DIR__ . '/../inc/Abilities/PermissionHelper.php';
	require_once __DIR__ . '/../inc/Abilities/Job/JobHelpers.php';
	require_once __DIR__ . '/../inc/Core/AgentsApiWorkflowJobRecorder.php';
	require_once __DIR__ . '/../inc/Abilities/Job/ExecuteAgentWorkflowAbility.php';

	$failures = array();
	$passes   = 0;

	function assert_agent_workflow_bridge_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

	class Agent_Workflow_Bridge_Harness extends \DataMachine\Abilities\Job\ExecuteAgentWorkflowAbility {
		public function __construct() {}
		public function set_jobs( \DataMachine\Core\Database\Jobs\Jobs $jobs ): void { $this->db_jobs = $jobs; }
	}

	echo "agents-api-workflow-bridge-smoke\n";

	register_agent_workflow_bridge_smoke_ability(
		'demo/uppercase',
		static fn( array $input ): array => array( 'value' => strtoupper( (string) ( $input['text'] ?? '' ) ) )
	);
	register_agent_workflow_bridge_smoke_ability(
		'agents/chat',
		static fn( array $input ): array => array( 'reply' => sprintf( '%s: %s', $input['agent'] ?? '', $input['message'] ?? '' ) )
	);

	$jobs   = new \DataMachine\Core\Database\Jobs\Jobs();
	$bridge = new Agent_Workflow_Bridge_Harness();
	$bridge->set_jobs( $jobs );

	$ability_result = $bridge->execute(
		array(
			'run_id' => 'run-ability-1',
			'metadata' => array(
				'artifacts' => array( array( 'name' => 'summary.json', 'type' => 'application/json' ) ),
				'logs'      => array( array( 'level' => 'info', 'message' => 'started' ) ),
			),
			'spec'   => array(
				'id'       => 'demo/ability-workflow',
				'triggers' => array( array( 'type' => 'on_demand' ) ),
				'inputs'   => array( 'text' => array( 'type' => 'string', 'required' => true ) ),
				'steps'    => array(
					array( 'id' => 'upper', 'type' => 'ability', 'ability' => 'demo/uppercase', 'args' => array( 'text' => '${inputs.text}' ) ),
				),
			),
			'inputs' => array( 'text' => 'cook' ),
		)
	);

	assert_agent_workflow_bridge_equals( true, $ability_result['success'], 'ability workflow succeeds', $failures, $passes );
	assert_agent_workflow_bridge_equals( 100, $ability_result['job_id'], 'ability workflow returns job id', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'run-ability-1', $ability_result['run_id'], 'ability workflow preserves Agents API run id', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'completed', $jobs->jobs[100]['status'], 'ability workflow maps success to completed job', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'agents_api_workflow', $jobs->jobs[100]['source'], 'ability workflow records source', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'demo/ability-workflow', $jobs->engine_data[100]['agents_api_workflow']['workflow_id'], 'ability workflow records workflow id', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'COOK', $jobs->engine_data[100]['step_outcomes'][0]['output']['value'], 'ability workflow records step output', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'summary.json', $jobs->engine_data[100]['artifacts'][0]['name'], 'ability workflow records metadata artifacts explicitly', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'started', $jobs->engine_data[100]['logs'][0]['message'], 'ability workflow records metadata logs explicitly', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'WP_Agent_Workflow_Runner', $jobs->engine_data[100]['provenance']['execution'], 'ability workflow records runner provenance', $failures, $passes );

	$recorder     = new \DataMachine\Core\AgentsApiWorkflowJobRecorder( $jobs, array() );
	$found_result = $recorder->find( 'run-ability-1' );
	assert_agent_workflow_bridge_equals( 'run-ability-1', $found_result?->get_run_id(), 'recorder find returns recorded run id', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'succeeded', $found_result?->get_status(), 'recorder find reconstructs succeeded status', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'COOK', $found_result?->get_steps()[0]['output']['value'] ?? null, 'recorder find reconstructs step output', $failures, $passes );

	$agent_result = $bridge->execute(
		array(
			'run_id' => 'run-agent-1',
			'spec'   => array(
				'id'    => 'demo/agent-workflow',
				'steps' => array(
					array( 'id' => 'ask', 'type' => 'agent', 'agent' => 'demo-agent', 'message' => 'hello' ),
				),
			),
		)
	);

	assert_agent_workflow_bridge_equals( true, $agent_result['success'], 'agent workflow succeeds when agents/chat is registered', $failures, $passes );
	assert_agent_workflow_bridge_equals( 101, $agent_result['job_id'], 'agent workflow returns job id', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'demo-agent: hello', $jobs->engine_data[101]['step_outcomes'][0]['output']['reply'], 'agent workflow records agent step output', $failures, $passes );

	$recent = $recorder->recent( array( 'limit' => 2 ) );
	assert_agent_workflow_bridge_equals( array( 'run-agent-1', 'run-ability-1' ), array_map( static fn( $result ): string => $result->get_run_id(), $recent ), 'recorder recent returns newest workflow runs', $failures, $passes );

	$recent_ability = $recorder->recent( array( 'workflow_id' => 'demo/ability-workflow' ) );
	assert_agent_workflow_bridge_equals( array( 'run-ability-1' ), array_map( static fn( $result ): string => $result->get_run_id(), $recent_ability ), 'recorder recent filters by workflow id', $failures, $passes );

	$missing_input = $bridge->execute(
		array(
			'run_id' => 'run-missing-input',
			'spec'   => array(
				'id'     => 'demo/missing-input-workflow',
				'inputs' => array( 'text' => array( 'type' => 'string', 'required' => true ) ),
				'steps'  => array(
					array( 'id' => 'upper', 'type' => 'ability', 'ability' => 'demo/uppercase', 'args' => array( 'text' => '${inputs.text}' ) ),
				),
			),
		)
	);

	assert_agent_workflow_bridge_equals( false, $missing_input['success'], 'missing input workflow fails', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'failed - missing_required_input', $jobs->jobs[102]['status'], 'failed workflow maps to failed job reason', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'failed', $recorder->find( 'run-missing-input' )?->get_status(), 'recorder find reconstructs failed status', $failures, $passes );

	$skipped_recorder = new \DataMachine\Core\AgentsApiWorkflowJobRecorder( $jobs, array() );
	$skipped_result   = new \AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result( 'run-skipped', 'demo/skipped-workflow', 'skipped', array(), array(), array(), array(), 1, 2, array() );
	$skipped_recorder->start( $skipped_result );
	$skipped_recorder->update( $skipped_result );
	assert_agent_workflow_bridge_equals( 'failed - agents_api_workflow_skipped', $jobs->jobs[103]['status'], 'skipped workflow maps to skipped failure reason', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'skipped', $recorder->find( 'run-skipped' )?->get_status(), 'recorder find reconstructs skipped status', $failures, $passes );

	$unsupported = $bridge->execute(
		array(
			'spec' => array(
				'id'    => 'demo/foreach-workflow',
				'steps' => array(
					array( 'id' => 'each', 'type' => 'foreach', 'items' => array(), 'steps' => array( array( 'id' => 'inner', 'type' => 'ability', 'ability' => 'demo/uppercase' ) ) ),
				),
			),
		)
	);

	assert_agent_workflow_bridge_equals( false, $unsupported['success'], 'unsupported foreach workflow fails clearly', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'agents_api_workflow_step_unsupported', $unsupported['error']['code'], 'unsupported step error is typed', $failures, $passes );

	$trigger_unsupported = $bridge->execute(
		array(
			'spec' => array(
				'id'       => 'demo/cron-workflow',
				'triggers' => array( array( 'type' => 'cron', 'schedule' => 'hourly' ) ),
				'steps'    => array( array( 'id' => 'upper', 'type' => 'ability', 'ability' => 'demo/uppercase' ) ),
			),
		)
	);

	assert_agent_workflow_bridge_equals( false, $trigger_unsupported['success'], 'unsupported trigger workflow fails clearly', $failures, $passes );
	assert_agent_workflow_bridge_equals( 'agents_api_workflow_trigger_unsupported', $trigger_unsupported['error']['code'], 'unsupported trigger error is typed', $failures, $passes );

	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " agents-api workflow bridge assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} agents-api workflow bridge assertions passed.\n";
}
