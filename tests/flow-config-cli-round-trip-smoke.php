<?php
/**
 * Command-level flow config string and repair smoke tests (#2914).
 *
 * Run with: php tests/flow-config-cli-round-trip-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$failures = array();
$passes   = 0;

function flow_config_cli_assert( bool $condition, string $message ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		echo "  PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "  FAIL: {$message}\n";
}

function flow_config_cli_assert_same( $expected, $actual, string $message ): void {
	flow_config_cli_assert( $expected === $actual, $message );
	if ( $expected !== $actual ) {
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	}
}

$GLOBALS['flow_config_cli_ability_calls'] = array();
$GLOBALS['flow_config_cli_cas_calls']     = array();
$GLOBALS['flow_config_cli_update_calls']  = array();
$GLOBALS['flow_config_cli_output']        = array();
$GLOBALS['flow_config_cli_raw']           = '';
$GLOBALS['flow_config_cli_conflict']      = false;

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $value ) {
		return (string) $value;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		return new class( $name ) {
			private string $name;

			public function __construct( string $name ) {
				$this->name = $name;
			}

			public function execute( array $input ): array {
				$GLOBALS['flow_config_cli_ability_calls'][] = array(
					'ability' => $this->name,
					'input'   => $input,
				);

				return array(
					'success'      => true,
					'flow_id'      => 91,
					'flow_name'    => $input['flow_name'] ?? 'Flow',
					'pipeline_id'  => $input['pipeline_id'] ?? 7,
					'synced_steps' => 1,
					'flow_data'    => array(),
				);
			}
		};
	}
}

class Flow_Config_CLI_Abort extends \RuntimeException {}

class WP_CLI {
	public static function log( $message = '' ): void {
		$GLOBALS['flow_config_cli_output'][] = (string) $message;
	}

	public static function line( $message = '' ): void {
		$GLOBALS['flow_config_cli_output'][] = (string) $message;
	}

	public static function success( $message ): void {
		$GLOBALS['flow_config_cli_output'][] = 'SUCCESS: ' . $message;
	}

	public static function warning( $message ): void {
		$GLOBALS['flow_config_cli_output'][] = 'WARNING: ' . $message;
	}

	public static function error( $message, $exit = true ): void {
		$GLOBALS['flow_config_cli_output'][] = 'ERROR: ' . $message;
		throw new Flow_Config_CLI_Abort( (string) $message );
	}

	public static function confirm( $message ): void {}
}

} // Global namespace.

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ): void {
		$GLOBALS['flow_config_cli_output'][] = json_encode( $items );
	}
}

namespace DataMachine\Cli {
	class BaseCommand {}

	class AgentResolver {
		public static function resolve( array $args ): int {
			return 1;
		}

		public static function buildScopingInput( array $args ): array {
			return array();
		}
	}

	class UserResolver {}
}

namespace DataMachine\Cli\Commands {
	class DrainCommand {
		public const HOOK_BATCH_CHUNK  = 'batch';
		public const HOOK_EXECUTE_STEP = 'step';
	}
}

namespace DataMachine\Engine\Debug {
	class SyncRunner {}
}

namespace DataMachine\Engine\Tasks {
	class RecurringScheduler {
		public static function looksLikeCronExpression( string $value ): bool {
			return false;
		}
	}
}

namespace DataMachine\Core\Steps {
	class FlowStepConfig {
		public static function usesHandler( array $step ): bool {
			return true;
		}

		public static function getEffectiveSlug( array $step, string $fallback = '' ): string {
			return $fallback ?: 'fixture';
		}

		public static function getConfiguredHandlerSlugs( array $step ): array {
			return array( 'fixture' );
		}

		public static function getHandlerConfigs( array $step ): array {
			return $step['handler_configs'] ?? array();
		}

		public static function getPrimaryHandlerConfig( array $step ): array {
			return $step['handler_configs']['fixture'] ?? array();
		}
	}
}

namespace DataMachine\Abilities {
	class HandlerAbilities {
		public function getAllHandlers(): array {
			return array();
		}

		public function getConfigFields( string $slug ): array {
			return array();
		}
	}
}

namespace DataMachine\Abilities\FlowStep {
	class UpdateFlowStepAbility {
		public function execute( array $input ): array {
			$GLOBALS['flow_config_cli_ability_calls'][] = array(
				'ability' => 'update-flow-step',
				'input'   => $input,
			);
			return array(
				'success'      => true,
				'flow_step_id' => $input['flow_step_id'] ?? '',
			);
		}
	}

	class ConfigureFlowStepsAbility {
		public function execute( array $input ): array {
			$GLOBALS['flow_config_cli_ability_calls'][] = array(
				'ability' => 'configure-flow-steps',
				'input'   => $input,
			);
			return array(
				'success'       => true,
				'updated_steps' => array(),
				'message'       => 'configured',
			);
		}
	}
}

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public function get_flow( int $flow_id ): ?array {
			$config = json_decode( $GLOBALS['flow_config_cli_raw'], true );
			return array(
				'flow_id'     => $flow_id,
				'pipeline_id' => 7,
				'flow_config' => is_array( $config ) ? $config : array(),
			);
		}

		public function get_flow_config_json( int $flow_id ): ?string {
			return $GLOBALS['flow_config_cli_raw'];
		}

		public function compare_and_swap_flow_config( int $flow_id, string $expected, array $replacement ): bool {
			if ( $GLOBALS['flow_config_cli_conflict'] ) {
				$current                                  = json_decode( $GLOBALS['flow_config_cli_raw'], true );
				$current['step']['prompt_queue'][]         = array( 'prompt' => 'concurrent work' );
				$GLOBALS['flow_config_cli_raw']             = json_encode( $current );
				$GLOBALS['flow_config_cli_conflict']        = false;
			}

			$GLOBALS['flow_config_cli_cas_calls'][] = array(
				'flow_id'     => $flow_id,
				'expected'    => $expected,
				'replacement' => $replacement,
			);

			if ( $GLOBALS['flow_config_cli_raw'] !== $expected ) {
				return false;
			}

			$GLOBALS['flow_config_cli_raw'] = json_encode( $replacement );
			return true;
		}

		public function update_flow( int $flow_id, array $data ): bool {
			$GLOBALS['flow_config_cli_update_calls'][] = $data;
			return true;
		}
	}
}

namespace {

require_once dirname( __DIR__ ) . '/inc/Cli/JsonInput.php';
require_once dirname( __DIR__ ) . '/inc/Core/Database/Flows/FlowConfigEscaping.php';
require_once dirname( __DIR__ ) . '/inc/Cli/Commands/Flows/FlowsCommand.php';
require_once dirname( __DIR__ ) . '/inc/Cli/Commands/Flows/BulkConfigCommand.php';

use DataMachine\Cli\Commands\Flows\BulkConfigCommand;
use DataMachine\Cli\Commands\Flows\FlowsCommand;

function flow_config_cli_invoke( string $method, array $arguments ): void {
	$reflection = new \ReflectionMethod( FlowsCommand::class, $method );
	$reflection->setAccessible( true );
	try {
		$reflection->invokeArgs( new FlowsCommand(), $arguments );
	} catch ( Flow_Config_CLI_Abort $e ) {
		// Assertions inspect the captured error.
	}
}

function flow_config_cli_reset(): void {
	$GLOBALS['flow_config_cli_ability_calls'] = array();
	$GLOBALS['flow_config_cli_cas_calls']     = array();
	$GLOBALS['flow_config_cli_update_calls']  = array();
	$GLOBALS['flow_config_cli_output']        = array();
}

$semantic = array(
	'source_url' => 'https://example.com/events/',
	'prompt'     => 'Process start/end.',
	'path'       => 'C:\\Temp\\events.json',
	'regexp'     => '\\d+\\s+events',
);
$json = json_encode( $semantic );

echo "=== Flow config CLI round trips (#2914) ===\n";

flow_config_cli_reset();
flow_config_cli_invoke(
	'createFlow',
	array(
		array(
			'pipeline_id' => 7,
			'name'        => 'CLI strings',
			'step_configs' => json_encode(
				array(
					'fetch' => array( 'handler_config' => $semantic ),
				)
			),
		),
	)
);
flow_config_cli_assert_same( $semantic, $GLOBALS['flow_config_cli_ability_calls'][0]['input']['step_configs']['fetch']['handler_config'] ?? null, 'create decodes config exactly once' );

$GLOBALS['flow_config_cli_raw'] = json_encode(
	array(
		'step' => array(
			'step_type'       => 'fetch',
			'handler_configs' => array( 'fixture' => array() ),
		),
	)
);
flow_config_cli_reset();
flow_config_cli_invoke( 'updateFlow', array( 52, array( 'step' => 'step', 'handler-config' => json_encode( array( 'fixture' => $semantic ) ) ) ) );
flow_config_cli_assert_same( $semantic, $GLOBALS['flow_config_cli_ability_calls'][0]['input']['handler_config'] ?? null, 'update decodes config exactly once' );

flow_config_cli_reset();
flow_config_cli_invoke( 'addHandler', array( 52, array( 'step' => 'step', 'handler' => 'fixture', 'config' => $json ) ) );
flow_config_cli_assert_same( $semantic, $GLOBALS['flow_config_cli_ability_calls'][0]['input']['add_handler_config'] ?? null, 'add-handler decodes config exactly once' );

flow_config_cli_reset();
( new BulkConfigCommand() )->dispatch(
	array(),
	array(
		'scope'   => 'global',
		'handler' => 'fixture',
		'config'  => $json,
		'execute' => true,
	)
);
flow_config_cli_assert_same( $semantic, $GLOBALS['flow_config_cli_ability_calls'][0]['input']['handler_config'] ?? null, 'bulk-config decodes config exactly once' );

flow_config_cli_reset();
$message = 'Process start/end from C:\\Temp with \\d+ items.';
flow_config_cli_invoke( 'updateFlow', array( 52, array( 'step' => 'step', 'set-user-message' => $message ) ) );
flow_config_cli_assert_same( $message, $GLOBALS['flow_config_cli_ability_calls'][0]['input']['user_message'] ?? null, 'set-user-message preserves semantic backslashes' );

$corrupt = array(
	'step' => array(
		'source_url'   => 'https:\\/\\/example.com\\/',
		'prompt_queue' => array( array( 'prompt' => 'Process start\\/end.' ) ),
		'path'         => 'C:\\Temp\\events.json',
		'regexp'       => '\\d+\\s+events',
	),
);
$GLOBALS['flow_config_cli_raw'] = json_encode( $corrupt );
$before                         = $GLOBALS['flow_config_cli_raw'];
flow_config_cli_reset();
flow_config_cli_invoke( 'repairEscapedConfig', array( 52, array( 'format' => 'json' ) ) );
flow_config_cli_assert_same( $before, $GLOBALS['flow_config_cli_raw'], 'repair dry-run does not mutate config' );
flow_config_cli_assert_same( array(), $GLOBALS['flow_config_cli_cas_calls'], 'repair dry-run does not call CAS' );

flow_config_cli_reset();
flow_config_cli_invoke( 'repairEscapedConfig', array( 52, array( 'format' => 'json', 'apply' => true ) ) );
$repaired = json_decode( $GLOBALS['flow_config_cli_raw'], true );
flow_config_cli_assert_same( 'https://example.com/', $repaired['step']['source_url'] ?? null, 'repair apply persists URL replacement' );
flow_config_cli_assert_same( 'Process start/end.', $repaired['step']['prompt_queue'][0]['prompt'] ?? null, 'repair apply persists prompt replacement' );
flow_config_cli_assert_same( 'C:\\Temp\\events.json', $repaired['step']['path'] ?? null, 'repair apply preserves Windows path backslashes' );
flow_config_cli_assert_same( '\\d+\\s+events', $repaired['step']['regexp'] ?? null, 'repair apply preserves regex backslashes' );
flow_config_cli_assert_same( $before, $GLOBALS['flow_config_cli_cas_calls'][0]['expected'] ?? null, 'repair CAS uses the exact reviewed raw snapshot' );
flow_config_cli_assert_same( array(), $GLOBALS['flow_config_cli_update_calls'], 'repair bypasses filtered update_flow boundary' );

$after_apply = $GLOBALS['flow_config_cli_raw'];
flow_config_cli_reset();
flow_config_cli_invoke( 'repairEscapedConfig', array( 52, array( 'apply' => true, 'format' => 'json' ) ) );
flow_config_cli_assert_same( $after_apply, $GLOBALS['flow_config_cli_raw'], 'repeated repair is idempotent' );
flow_config_cli_assert_same( array(), $GLOBALS['flow_config_cli_cas_calls'], 'idempotent repair performs no write' );

$GLOBALS['flow_config_cli_raw']      = json_encode( $corrupt );
$GLOBALS['flow_config_cli_conflict'] = true;
flow_config_cli_reset();
flow_config_cli_invoke( 'repairEscapedConfig', array( 52, array( 'apply' => true, 'format' => 'json' ) ) );
$concurrent = json_decode( $GLOBALS['flow_config_cli_raw'], true );
flow_config_cli_assert_same( 'https:\\/\\/example.com\\/', $concurrent['step']['source_url'] ?? null, 'concurrent modification rejection does not apply stale repair' );
flow_config_cli_assert_same( 'concurrent work', $concurrent['step']['prompt_queue'][1]['prompt'] ?? null, 'concurrent modification rejection preserves new queue work' );
flow_config_cli_assert( str_contains( implode( "\n", $GLOBALS['flow_config_cli_output'] ), 'changed after the repair preview was read' ), 'concurrent modification reports a clean retryable error' );

echo "\n{$passes} passed, " . count( $failures ) . " failed.\n";
exit( empty( $failures ) ? 0 : 1 );

} // Global namespace.
