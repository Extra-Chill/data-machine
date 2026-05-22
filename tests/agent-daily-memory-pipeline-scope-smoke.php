<?php
/**
 * Pure-PHP smoke test for pipeline-scoped agent_daily_memory calls (#1877).
 *
 * Run with: php tests/agent-daily-memory-pipeline-scope-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities {
	class PermissionHelper {
		private static ?int $agent_id = null;
		private static int $owner_id = 0;

		public static function set_agent_context( int $agent_id, int $owner_id ): void {
			self::$agent_id = $agent_id;
			self::$owner_id = $owner_id;
		}

		public static function clear_agent_context(): void {
			self::$agent_id = null;
			self::$owner_id = 0;
		}

		public static function in_agent_context(): bool {
			return null !== self::$agent_id;
		}

		public static function acting_user_id(): int {
			return self::$owner_id;
		}

		public static function get_acting_agent_id(): ?int {
			return self::$agent_id;
		}
	}
}

namespace DataMachine\Core\FilesRepository {
	class DirectoryManager {
		public function get_effective_user_id( int $user_id = 0 ): int {
			return $user_id > 0 ? $user_id : 1;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$agent_daily_memory_ability_inputs = array();
	$agent_daily_memory_current_user_id = 0;

	function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		// Tool registration is not under test here.
	}

	function wp_get_ability( string $name ) {
		return new class( $name ) {
			private string $name;

			public function __construct( string $name ) {
				$this->name = $name;
			}

			public function execute( array $input ): array {
				global $agent_daily_memory_ability_inputs;
				$agent_daily_memory_ability_inputs[ $this->name ] = $input;

				return array( 'success' => true );
			}
		};
	}

	function is_wp_error( $value ): bool {
		return false;
	}

	function get_current_user_id(): int {
		global $agent_daily_memory_current_user_id;
		return $agent_daily_memory_current_user_id;
	}

	require_once __DIR__ . '/../inc/Engine/AI/Tools/BaseTool.php';
	require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolPolicyResolver.php';
	require_once __DIR__ . '/../inc/Engine/AI/Tools/Global/AgentDailyMemory.php';

	use DataMachine\Abilities\PermissionHelper;
	use DataMachine\Engine\AI\Tools\Global\AgentDailyMemory;

	$failures = array();
	$passes   = 0;

	function assert_daily_memory_scope( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

	echo "agent-daily-memory-pipeline-scope-smoke (#1877)\n";

	$tool = new AgentDailyMemory();
	$definition = $tool->getToolDefinition();
	assert_daily_memory_scope(
		array( 'read', 'write', 'list', 'search' ),
		$definition['parameters']['properties']['action']['enum'] ?? null,
		'action schema exposes valid enum values',
		$failures,
		$passes
	);
	assert_daily_memory_scope(
		array( 'append', 'write' ),
		$definition['parameters']['properties']['mode']['enum'] ?? null,
		'mode schema exposes valid enum values',
		$failures,
		$passes
	);

	PermissionHelper::set_agent_context( 42, 77 );

	$tool->handle_tool_call( array( 'action' => 'write', 'content' => 'entry', 'user_id' => 999 ) );
	assert_daily_memory_scope(
		array(
			'user_id'  => 77,
			'agent_id' => 42,
			'content'  => 'entry',
			'date'     => gmdate( 'Y-m-d' ),
			'mode'     => 'append',
		),
		$agent_daily_memory_ability_inputs['datamachine/daily-memory-write'] ?? null,
		'write ignores model user_id and uses executing agent scope',
		$failures,
		$passes
	);

	$last_write_input = $agent_daily_memory_ability_inputs['datamachine/daily-memory-write'] ?? null;
	$invalid_result   = $tool->handle_tool_call( array( 'action' => 'write', 'content' => 'bad mode', 'mode' => 'append()} bad tool args' ) );
	assert_daily_memory_scope(
		false,
		$invalid_result['success'] ?? null,
		'invalid write mode is rejected by the tool',
		$failures,
		$passes
	);
	assert_daily_memory_scope(
		'Invalid mode "append()} bad tool args". Use "append" or "write".',
		$invalid_result['error'] ?? null,
		'invalid write mode returns a corrective error',
		$failures,
		$passes
	);
	assert_daily_memory_scope(
		$last_write_input,
		$agent_daily_memory_ability_inputs['datamachine/daily-memory-write'] ?? null,
		'invalid write mode does not call the write ability',
		$failures,
		$passes
	);

	$tool->handle_tool_call( array( 'action' => 'read', 'date' => '2026-05-08', 'user_id' => 999 ) );
	assert_daily_memory_scope(
		array(
			'user_id'  => 77,
			'agent_id' => 42,
			'date'     => '2026-05-08',
		),
		$agent_daily_memory_ability_inputs['datamachine/daily-memory-read'] ?? null,
		'read uses executing agent scope',
		$failures,
		$passes
	);

	$tool->handle_tool_call( array( 'action' => 'list', 'user_id' => 999 ) );
	assert_daily_memory_scope(
		array(
			'user_id'  => 77,
			'agent_id' => 42,
		),
		$agent_daily_memory_ability_inputs['datamachine/daily-memory-list'] ?? null,
		'list uses executing agent scope',
		$failures,
		$passes
	);

	$tool->handle_tool_call( array( 'action' => 'search', 'query' => 'entry', 'user_id' => 999 ) );
	assert_daily_memory_scope(
		array(
			'user_id'  => 77,
			'agent_id' => 42,
			'query'    => 'entry',
		),
		$agent_daily_memory_ability_inputs['datamachine/search-daily-memory'] ?? null,
		'search uses executing agent scope',
		$failures,
		$passes
	);

	PermissionHelper::clear_agent_context();
	$tool->handle_tool_call( array( 'action' => 'write', 'content' => 'pipeline entry', 'user_id' => 77, 'agent_id' => 42 ) );
	assert_daily_memory_scope(
		array(
			'user_id'  => 77,
			'agent_id' => 42,
			'content'  => 'pipeline entry',
			'date'     => gmdate( 'Y-m-d' ),
			'mode'     => 'append',
		),
		$agent_daily_memory_ability_inputs['datamachine/daily-memory-write'] ?? null,
		'pipeline payload agent_id preserves agent memory scope without permission context',
		$failures,
		$passes
	);

	$tool->handle_tool_call( array( 'action' => 'read', 'date' => '2026-05-09', 'user_id' => 123 ) );
	assert_daily_memory_scope(
		array(
			'user_id'  => 123,
			'agent_id' => 0,
			'date'     => '2026-05-09',
		),
		$agent_daily_memory_ability_inputs['datamachine/daily-memory-read'] ?? null,
		'non-agent chat behavior preserves explicit user_id scope',
		$failures,
		$passes
	);

	if ( $failures ) {
		echo "\nFAILED: " . count( $failures ) . " daily memory scope assertions failed.\n";
		exit( 1 );
	}

	echo "\nAll {$passes} daily memory scope assertions passed.\n";
}
