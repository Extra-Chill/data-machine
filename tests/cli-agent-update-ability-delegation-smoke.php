<?php
/**
 * Pure-PHP smoke test for CLI agent update ability delegation (#3133).
 *
 * Run with: php tests/cli-agent-update-ability-delegation-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {

	define( 'ABSPATH', __DIR__ );

	class WP_Error {
		public function __construct( private string $message ) {}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	class CliAgentUpdateAbort extends \Exception {}

	class WP_CLI {
		public static function log( string $message ): void {
			$GLOBALS['cli_logs'][] = $message;
		}

		public static function line( string $message = '' ): void {
			$GLOBALS['cli_logs'][] = $message;
		}

		public static function success( string $message ): void {
			$GLOBALS['cli_success'][] = $message;
		}

		public static function warning( string $message ): void {
			$GLOBALS['cli_warnings'][] = $message;
		}

		public static function error( string $message ): void {
			$GLOBALS['cli_error'] = $message;
			throw new CliAgentUpdateAbort( $message );
		}
	}

	class CliAgentUpdateAbility {
		public function __construct( private string $name ) {}

		public function execute( array $input ) {
			$GLOBALS['ability_calls'][] = array(
				'name'  => $this->name,
				'input' => $input,
			);
			$GLOBALS['sequence'][] = 'ability';

			return $GLOBALS['ability_result'];
		}
	}

	function wp_get_ability( string $name ): CliAgentUpdateAbility {
		return new CliAgentUpdateAbility( $name );
	}

	function is_wp_error( $result ): bool {
		return $result instanceof WP_Error;
	}
}

namespace DataMachine\Cli {
	class BaseCommand {}

	class AgentResolver {
		public static function resolve( array $assoc_args ): ?int {
			$GLOBALS['resolver_calls'][] = $assoc_args;

			return $GLOBALS['resolved_agent_id'];
		}
	}

	class UserResolver {}
	class JsonInput {}
}

namespace DataMachine\Cli\Commands {
	class DrainCommand {}
}

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public function reassign_agent_id_for_pipeline( int $pipeline_id, ?int $from_agent_id, int $to_agent_id ): int {
			$GLOBALS['cascade_calls'][] = compact( 'pipeline_id', 'from_agent_id', 'to_agent_id' );
			$GLOBALS['sequence'][]      = 'cascade';

			return $GLOBALS['cascade_result'];
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/PipelinesCommand.php';
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/Flows/FlowsCommand.php';

	use DataMachine\Cli\Commands\Flows\FlowsCommand;
	use DataMachine\Cli\Commands\PipelinesCommand;

	$failed = 0;
	$total  = 0;

	function assert_cli_agent_update( string $name, bool $condition ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  PASS: {$name}\n";
			return;
		}

		echo "  FAIL: {$name}\n";
		++$failed;
	}

	function reset_cli_agent_update(): void {
		$GLOBALS['ability_calls']     = array();
		$GLOBALS['ability_result']    = array( 'success' => true );
		$GLOBALS['cascade_calls']     = array();
		$GLOBALS['cascade_result']    = 2;
		$GLOBALS['cli_error']         = null;
		$GLOBALS['cli_logs']          = array();
		$GLOBALS['cli_success']       = array();
		$GLOBALS['cli_warnings']      = array();
		$GLOBALS['resolved_agent_id'] = 17;
		$GLOBALS['resolver_calls']    = array();
		$GLOBALS['sequence']          = array();
	}

	function run_cli_agent_update( string $command_class, string $method_name, int $resource_id, array $assoc_args ): void {
		$method = new \ReflectionMethod( $command_class, $method_name );
		$method->setAccessible( true );

		try {
			$method->invoke( new $command_class(), $resource_id, $assoc_args );
		} catch ( CliAgentUpdateAbort $e ) {
			// WP_CLI::error() exits in production; the test exception preserves that boundary.
		}
	}

	echo "=== CLI Agent Update Ability Delegation Smoke ===\n";

	reset_cli_agent_update();
	run_cli_agent_update( PipelinesCommand::class, 'updatePipeline', 9, array( 'agent' => 'roadie' ) );
	assert_cli_agent_update( 'pipeline resolves the original --agent arguments', array( array( 'agent' => 'roadie' ) ) === $GLOBALS['resolver_calls'] );
	assert_cli_agent_update(
		'pipeline executes registered update ability with resolved agent_id',
		array(
			array(
				'name'  => 'datamachine/update-pipeline',
				'input' => array( 'pipeline_id' => 9, 'agent_id' => 17 ),
			),
		) === $GLOBALS['ability_calls']
	);
	assert_cli_agent_update( 'pipeline preserves agent success output', in_array( 'Agent: set to agent_id=17', $GLOBALS['cli_logs'], true ) );

	reset_cli_agent_update();
	run_cli_agent_update( PipelinesCommand::class, 'updatePipeline', 9, array( 'agent' => '17', 'cascade-flows' => true ) );
	assert_cli_agent_update( 'pipeline cascade runs after successful base ability', array( 'ability', 'cascade' ) === $GLOBALS['sequence'] );
	assert_cli_agent_update( 'pipeline cascade preserves reassignment arguments', array( array( 'pipeline_id' => 9, 'from_agent_id' => null, 'to_agent_id' => 17 ) ) === $GLOBALS['cascade_calls'] );
	assert_cli_agent_update( 'pipeline cascade preserves count output', in_array( 'Cascade: 2 child flow(s) reassigned to agent_id=17.', $GLOBALS['cli_logs'], true ) );

	reset_cli_agent_update();
	$GLOBALS['cascade_result'] = -1;
	run_cli_agent_update( PipelinesCommand::class, 'updatePipeline', 9, array( 'agent' => '17', 'cascade-flows' => true ) );
	assert_cli_agent_update( 'partial cascade keeps successful base assignment', in_array( 'Agent: set to agent_id=17', $GLOBALS['cli_logs'], true ) );
	assert_cli_agent_update( 'partial cascade preserves warning', array( 'Failed to cascade agent_id to child flows.' ) === $GLOBALS['cli_warnings'] );
	assert_cli_agent_update( 'partial cascade remains a successful CLI update', array( 'Pipeline 9 updated.' ) === $GLOBALS['cli_success'] );

	foreach ( array( new WP_Error( 'Pipeline ability WP error' ), array( 'success' => false, 'error' => 'Pipeline legacy failure' ) ) as $failure ) {
		reset_cli_agent_update();
		$GLOBALS['ability_result'] = $failure;
		run_cli_agent_update( PipelinesCommand::class, 'updatePipeline', 9, array( 'agent' => '17', 'cascade-flows' => true ) );
		assert_cli_agent_update( 'pipeline ability failure prevents cascade', array() === $GLOBALS['cascade_calls'] );
		$expected = $failure instanceof WP_Error ? 'Pipeline ability WP error' : 'Pipeline legacy failure';
		assert_cli_agent_update( 'pipeline ability failure preserves CLI error message', $expected === $GLOBALS['cli_error'] );
	}

	reset_cli_agent_update();
	run_cli_agent_update( FlowsCommand::class, 'updateFlow', 12, array( 'agent' => 'roadie', 'dry-run' => true ) );
	assert_cli_agent_update( 'flow dry-run still resolves the agent', 1 === count( $GLOBALS['resolver_calls'] ) );
	assert_cli_agent_update( 'flow dry-run executes no ability', array() === $GLOBALS['ability_calls'] );
	assert_cli_agent_update( 'flow dry-run preserves preview output', array( '[dry-run] would set flow 12 agent to agent_id=17; no changes written' ) === $GLOBALS['cli_logs'] );

	reset_cli_agent_update();
	run_cli_agent_update( FlowsCommand::class, 'updateFlow', 12, array( 'agent' => 'roadie' ) );
	assert_cli_agent_update(
		'flow executes registered update ability with resolved agent_id',
		array(
			array(
				'name'  => 'datamachine/update-flow',
				'input' => array( 'flow_id' => 12, 'agent_id' => 17 ),
			),
		) === $GLOBALS['ability_calls']
	);
	assert_cli_agent_update( 'flow preserves success output', array( 'Flow 12 agent set to agent_id=17.' ) === $GLOBALS['cli_success'] );

	foreach ( array( new WP_Error( 'Flow ability WP error' ), array( 'success' => false, 'error' => 'Flow legacy failure' ) ) as $failure ) {
		reset_cli_agent_update();
		$GLOBALS['ability_result'] = $failure;
		run_cli_agent_update( FlowsCommand::class, 'updateFlow', 12, array( 'agent' => '17' ) );
		$expected = $failure instanceof WP_Error ? 'Flow ability WP error' : 'Flow legacy failure';
		assert_cli_agent_update( 'flow ability failure preserves CLI error message', $expected === $GLOBALS['cli_error'] );
		assert_cli_agent_update( 'flow ability failure prints no success', array() === $GLOBALS['cli_success'] );
	}

	$pipeline_source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/PipelinesCommand.php' );
	$flow_source     = (string) file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/Flows/FlowsCommand.php' );
	assert_cli_agent_update( 'pipeline CLI has no direct base agent repository update', false === strpos( $pipeline_source, "update_pipeline( \$pipeline_id, array( 'agent_id'" ) );
	assert_cli_agent_update( 'flow CLI has no direct base agent repository update', false === strpos( $flow_source, "update_flow( \$flow_id, array( 'agent_id'" ) );

	echo "\n";
	if ( 0 === $failed ) {
		echo "=== cli-agent-update-ability-delegation-smoke: ALL PASS ({$total}) ===\n";
		exit( 0 );
	}

	echo "=== cli-agent-update-ability-delegation-smoke: {$failed} FAIL of {$total} ===\n";
	exit( 1 );
}
