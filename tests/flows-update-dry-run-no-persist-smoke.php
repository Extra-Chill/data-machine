<?php
/**
 * Pure-PHP smoke test for `flows update --dry-run` persistence (#2680).
 *
 * Run with: php tests/flows-update-dry-run-no-persist-smoke.php
 *
 * #2680 was a SILENT DATA-DESTRUCTION bug: `flows update <id> --handler-config
 * '...' --dry-run` wrote the change to the database anyway and printed
 * "Handler config updated" as if it were a no-op preview. It clobbered a live
 * 16k-char production flow message down to ~11 chars TWICE during verification.
 *
 * This test drives the REAL FlowsCommand::updateFlow() private method via
 * reflection against a SPY persistence layer (UpdateFlowStepAbility), pinning:
 *
 *   1. With --dry-run, the persist ability is NEVER executed (zero DB writes),
 *      so the stored flow config is byte-identical before and after.
 *   2. With --dry-run, the output reads like a preview ("[dry-run] would
 *      update ... no changes written"), NOT a "Handler config updated" success.
 *   3. WITHOUT --dry-run, the SAME command DOES execute the persist ability
 *      exactly once (the write path is intact — dry-run is the only difference).
 *   4. The same guarantees hold for --set-user-message.
 *
 * The collaborators FlowsCommand touches (WP_CLI, FlowStepConfig, the Flows
 * repository, UpdateFlowStepAbility, wp_get_ability, etc.) are stubbed below in
 * their real namespaces BEFORE the command file is required, so PHP resolves
 * the `new \DataMachine\...` calls to these test doubles.
 *
 * @package DataMachine\Tests
 */

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ─── Test harness ─────────────────────────────────────────────────────

$failures = array();
$passes   = 0;

function smoke_assert( bool $condition, string $name, string $detail = '' ): void {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

function smoke_assert_equals( $expected, $actual, string $name ): void {
	global $failures, $passes;
	if ( $expected === $actual ) {
		++$passes;
		echo "  ✓ {$name}\n";
		return;
	}

	$failures[] = $name;
	echo "  ✗ {$name}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
}

// ─── Spy state (module-level so stubs can record into it) ─────────────

$GLOBALS['__smoke_persist_calls']  = array(); // Records every UpdateFlowStepAbility::execute() input.
$GLOBALS['__smoke_cli_lines']      = array(); // WP_CLI::log / line output.
$GLOBALS['__smoke_cli_success']    = array(); // WP_CLI::success output.
$GLOBALS['__smoke_cli_error']      = null;    // First WP_CLI::error message (throws to abort).

/**
 * The stored flow config the SUT reads from / would write to. Tests snapshot
 * this before/after a dry-run to assert byte-identity.
 */
$GLOBALS['__smoke_stored_flow'] = array(
	'flow_id'     => 4,
	'flow_config' => array(
		'step_abc' => array(
			'step_type'       => 'system_task',
			'handler'         => array( 'handler_slug' => '' ),
			'handler_configs' => array(),
			'flow_step_settings' => array(
				'task'   => 'dispatch_message',
				'params' => array(
					'channel'   => 'extra-chill',
					'recipient' => 'chubes',
					'message'   => str_repeat( 'A', 16104 ), // The 16k-char prod prompt this bug clobbered.
				),
			),
		),
	),
);

// ─── WP function stubs ────────────────────────────────────────────────

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		return $value;
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
	function is_wp_error( $thing ) {
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
		// Only name/scheduling updates reach this in updateFlow(); the tests
		// here exercise handler-config and user-message paths, which use the
		// UpdateFlowStepAbility stub directly. Return a permissive double.
		return new class() {
			public function execute( $input ) {
				return array( 'success' => true, 'flow_name' => $input['flow_name'] ?? '', 'flow_data' => array() );
			}
		};
	}
}

// ─── WP_CLI stub (records output, aborts on error) ────────────────────

class Smoke_WP_CLI_Abort extends \Exception {}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function log( $msg ) {
			$GLOBALS['__smoke_cli_lines'][] = (string) $msg;
		}
		public static function line( $msg = '' ) {
			$GLOBALS['__smoke_cli_lines'][] = (string) $msg;
		}
		public static function success( $msg ) {
			$GLOBALS['__smoke_cli_success'][] = (string) $msg;
		}
		public static function warning( $msg ) {
			$GLOBALS['__smoke_cli_lines'][] = 'WARN: ' . (string) $msg;
		}
		public static function error( $msg ) {
			$GLOBALS['__smoke_cli_error'] = (string) $msg;
			throw new Smoke_WP_CLI_Abort( (string) $msg );
		}
		public static function confirm( $msg ) {}
	}
}

} // end global namespace (stubs / harness)

// ─── Namespaced collaborator stubs (declared BEFORE the SUT is loaded) ─

namespace DataMachine\Cli {
	// BaseCommand: FlowsCommand extends this. Minimal empty base.
	if ( ! class_exists( __NAMESPACE__ . '\\BaseCommand' ) ) {
		class BaseCommand {}
	}
	if ( ! class_exists( __NAMESPACE__ . '\\AgentResolver' ) ) {
		class AgentResolver {
			public static function resolve( $args ) { return 1; }
			public static function buildScopingInput( $args ) { return array(); }
		}
	}
	if ( ! class_exists( __NAMESPACE__ . '\\UserResolver' ) ) {
		class UserResolver {}
	}
}

namespace DataMachine\Cli\Commands {
	if ( ! class_exists( __NAMESPACE__ . '\\DrainCommand' ) ) {
		class DrainCommand {
			const HOOK_BATCH_CHUNK  = 'batch';
			const HOOK_EXECUTE_STEP = 'exec';
			public static function drain( $args ) { return array(); }
		}
	}
}

namespace DataMachine\Engine\Debug {
	if ( ! class_exists( __NAMESPACE__ . '\\SyncRunner' ) ) {
		class SyncRunner {}
	}
}

namespace DataMachine\Core\Steps {
	if ( ! class_exists( __NAMESPACE__ . '\\FlowStepConfig' ) ) {
		class FlowStepConfig {
			public static function usesHandler( $step ) {
				// system_task / dispatch are handler-free → false, which routes
				// updateFlow() through normalizeHandlerFreeConfig().
				$slug = $step['handler']['handler_slug'] ?? '';
				return ! empty( $slug );
			}
			public static function getEffectiveSlug( $step, $fallback = '' ) {
				return $step['flow_step_settings']['task'] ?? ( $step['handler']['handler_slug'] ?? '' );
			}
			public static function getConfiguredHandlerSlugs( $step ) { return array(); }
			public static function getHandlerConfigs( $step ) { return array(); }
			public static function getPrimaryHandlerConfig( $step ) { return array(); }
		}
	}
}

namespace DataMachine\Abilities {
	if ( ! class_exists( __NAMESPACE__ . '\\HandlerAbilities' ) ) {
		class HandlerAbilities {
			public function getAllHandlers() { return array(); }
			public function getConfigFields( $slug ) {
				// system_task settings schema: task_type + params (json).
				return array( 'task' => array(), 'params' => array() );
			}
		}
	}
}

namespace DataMachine\Abilities\FlowStep {
	// SPY: records every execute() call so the test can assert it is NEVER
	// invoked during a dry-run and invoked exactly once otherwise.
	if ( ! class_exists( __NAMESPACE__ . '\\UpdateFlowStepAbility' ) ) {
		class UpdateFlowStepAbility {
			public function execute( $input ) {
				$GLOBALS['__smoke_persist_calls'][] = $input;
				return array( 'success' => true, 'flow_step_id' => $input['flow_step_id'] ?? '', 'message' => 'ok' );
			}
		}
	}
}

namespace DataMachine\Core\Database\Flows {
	if ( ! class_exists( __NAMESPACE__ . '\\Flows' ) ) {
		class Flows {
			public function get_flow( $flow_id ) {
				return $GLOBALS['__smoke_stored_flow'];
			}
			public function update_flow( $flow_id, $data ) {
				$GLOBALS['__smoke_persist_calls'][] = array( 'repo_update_flow' => $data );
				return true;
			}
		}
	}
}

// ─── Load the real subject under test ─────────────────────────────────

namespace {

	require_once dirname( __DIR__ ) . '/inc/Cli/JsonInput.php';
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/Flows/FlowsCommand.php';

	use DataMachine\Cli\Commands\Flows\FlowsCommand;

	/**
	 * Invoke the real private updateFlow() with the given assoc args and return
	 * a snapshot of recorded spy state.
	 */
	function smoke_run_update( array $assoc_args ): array {
		$GLOBALS['__smoke_persist_calls'] = array();
		$GLOBALS['__smoke_cli_lines']     = array();
		$GLOBALS['__smoke_cli_success']   = array();
		$GLOBALS['__smoke_cli_error']     = null;

		$command = new FlowsCommand();
		$ref     = new \ReflectionMethod( FlowsCommand::class, 'updateFlow' );
		$ref->setAccessible( true );

		try {
			$ref->invoke( $command, 4, $assoc_args );
		} catch ( \Smoke_WP_CLI_Abort $e ) {
			// WP_CLI::error() aborts; surfaced via __smoke_cli_error.
		}

		return array(
			'persist_calls' => $GLOBALS['__smoke_persist_calls'],
			'lines'         => $GLOBALS['__smoke_cli_lines'],
			'success'       => $GLOBALS['__smoke_cli_success'],
			'error'         => $GLOBALS['__smoke_cli_error'],
		);
	}

	echo "flows update --dry-run no-persist smoke (#2680)\n";
	echo "------------------------------------------------\n";

	// Snapshot the stored config (serialized) before any run.
	$before = serialize( $GLOBALS['__smoke_stored_flow'] );

	echo "\n[1] --handler-config --dry-run makes ZERO writes:\n";

	$dry = smoke_run_update(
		array(
			'step'           => 'step_abc',
			'handler-config' => '{"params":{"message":"DRYRUN_SENTINEL_SHOULD_NOT_PERSIST"}}',
			'dry-run'        => true,
		)
	);

	smoke_assert(
		array() === $dry['persist_calls'],
		'dry-run handler-config: persist ability NEVER executed',
		count( $dry['persist_calls'] ) . ' persist call(s) recorded'
	);

	$after = serialize( $GLOBALS['__smoke_stored_flow'] );
	smoke_assert(
		$before === $after,
		'dry-run handler-config: stored flow config byte-identical (no clobber)'
	);

	$dry_text = implode( "\n", array_merge( $dry['lines'], $dry['success'] ) );
	smoke_assert(
		false !== stripos( $dry_text, 'dry-run' ) && false !== stripos( $dry_text, 'no changes written' ),
		'dry-run handler-config: emits "[dry-run] ... no changes written" preview',
		$dry_text
	);
	smoke_assert(
		array() === $dry['success'],
		'dry-run handler-config: does NOT print a WP_CLI::success "updated" line',
		implode( ' | ', $dry['success'] )
	);

	echo "\n[2] --handler-config WITHOUT --dry-run DOES persist (write path intact):\n";

	$wet = smoke_run_update(
		array(
			'step'           => 'step_abc',
			'handler-config' => '{"params":{"message":"REAL_WRITE"}}',
		)
	);

	smoke_assert(
		1 === count( $wet['persist_calls'] ),
		'non-dry-run handler-config: persist ability executed exactly once',
		count( $wet['persist_calls'] ) . ' persist call(s) recorded'
	);
	smoke_assert(
		! empty( $wet['success'] ),
		'non-dry-run handler-config: prints a success line'
	);

	echo "\n[3] --set-user-message --dry-run makes ZERO writes:\n";

	$dry_msg = smoke_run_update(
		array(
			'step'             => 'step_abc',
			'set-user-message' => 'DRYRUN_MESSAGE_SHOULD_NOT_PERSIST',
			'dry-run'          => true,
		)
	);

	smoke_assert(
		array() === $dry_msg['persist_calls'],
		'dry-run set-user-message: persist ability NEVER executed',
		count( $dry_msg['persist_calls'] ) . ' persist call(s) recorded'
	);
	$dry_msg_text = implode( "\n", array_merge( $dry_msg['lines'], $dry_msg['success'] ) );
	smoke_assert(
		false !== stripos( $dry_msg_text, 'dry-run' ) && false !== stripos( $dry_msg_text, 'no changes written' ),
		'dry-run set-user-message: emits "[dry-run] ... no changes written" preview',
		$dry_msg_text
	);

	echo "\n[4] --set-user-message WITHOUT --dry-run DOES persist:\n";

	$wet_msg = smoke_run_update(
		array(
			'step'             => 'step_abc',
			'set-user-message' => 'REAL_MESSAGE',
		)
	);

	smoke_assert(
		1 === count( $wet_msg['persist_calls'] ),
		'non-dry-run set-user-message: persist ability executed exactly once',
		count( $wet_msg['persist_calls'] ) . ' persist call(s) recorded'
	);

	echo "\n------------------------------------------------\n";
	$total = $passes + count( $failures );
	echo "{$passes} / {$total} passed\n";

	if ( ! empty( $failures ) ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nAll assertions passed.\n";
}
