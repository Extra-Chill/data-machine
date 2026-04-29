<?php
/**
 * Pure-PHP smoke test for Ability-native AI tool execution (#1480).
 *
 * Run with: php tests/tool-executor-ability-native-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $message;

			public function __construct( string $code = '', string $message = '' ) {
				$this->message = '' !== $message ? $message : $code;
			}

			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof WP_Error;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( ...$args ) {
		}
	}

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( string $text ): string {
			return strip_tags( $text );
		}
	}

	if ( ! function_exists( 'wp_trim_words' ) ) {
		function wp_trim_words( string $text, int $num_words = 55, ?string $more = null ): string {
			$words = preg_split( '/\s+/', trim( $text ) );
			if ( ! is_array( $words ) || count( $words ) <= $num_words ) {
				return $text;
			}

			return implode( ' ', array_slice( $words, 0, $num_words ) ) . ( $more ?? '...' );
		}
	}

	class Ability_Native_Smoke_Ability {
		/** @var callable */
		private $permission_callback;

		/** @var callable */
		private $execute_callback;

		public int $execute_count = 0;

		public function __construct( callable $permission_callback, callable $execute_callback ) {
			$this->permission_callback = $permission_callback;
			$this->execute_callback    = $execute_callback;
		}

		public function check_permissions( $input = null ) {
			return call_user_func( $this->permission_callback, $input );
		}

		public function execute( $input = null ) {
			++$this->execute_count;
			return call_user_func( $this->execute_callback, $input );
		}
	}

	class WP_Abilities_Registry {
		private static ?self $instance = null;

		/** @var array<string, Ability_Native_Smoke_Ability> */
		private array $abilities = array();

		public static function get_instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public static function reset(): void {
			self::$instance = new self();
		}

		public function register_for_smoke( string $slug, Ability_Native_Smoke_Ability $ability ): void {
			$this->abilities[ $slug ] = $ability;
		}

		public function get_registered( string $slug ): ?Ability_Native_Smoke_Ability {
			return $this->abilities[ $slug ] ?? null;
		}
	}
}

namespace DataMachine\Engine\AI\Actions {
	class ActionPolicyResolver {
		public const MODE_CHAT        = 'chat';
		public const POLICY_DIRECT    = 'direct';
		public const POLICY_PREVIEW   = 'preview';
		public const POLICY_FORBIDDEN = 'forbidden';

		public function resolveForTool( array $args ): string {
			return $args['tool_def']['action_policy'] ?? self::POLICY_DIRECT;
		}
	}

	class PendingActionHelper {
		/** @var list<array<string, mixed>> */
		public static array $staged = array();

		public static function stage( array $args ): array {
			self::$staged[] = $args;

			return array(
				'staged'    => true,
				'action_id' => 'pending_1',
				'kind'      => $args['kind'] ?? '',
				'summary'   => $args['summary'] ?? '',
			);
		}
	}
}

namespace DataMachine\Core\WordPress {
	class PostTracking {
		/** @var list<array<string, mixed>> */
		public static array $stored = array();

		public static function extractPostId( array $tool_result ): int {
			return (int) ( $tool_result['post_id'] ?? 0 );
		}

		public static function store( int $post_id, array $tool_def, int $job_id ): void {
			self::$stored[] = compact( 'post_id', 'tool_def', 'job_id' );
		}
	}
}

namespace DataMachine\Tests\ToolExecutorAbilityNativeSmoke {
	use DataMachine\Engine\AI\Actions\ActionPolicyResolver;
	use DataMachine\Engine\AI\Actions\PendingActionHelper;
	use DataMachine\Engine\AI\Tools\Execution\ToolExecutionCore;
	use DataMachine\Engine\AI\Tools\ToolExecutor;

	require_once dirname( __DIR__ ) . '/inc/Engine/AI/Tools/ToolParameters.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/AI/Tools/Execution/ToolExecutionCore.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/AI/Tools/ToolExecutor.php';

	$failed = 0;
	$total  = 0;

	function assert_smoke( string $name, bool $condition ): void {
		global $failed, $total;
		++$total;
		if ( $condition ) {
			echo "  PASS: {$name}\n";
			return;
		}

		echo "  FAIL: {$name}\n";
		++$failed;
	}

	function post_tracking_count(): int {
		return count( \DataMachine\Core\WordPress\PostTracking::$stored );
	}

	function first_pending_apply_job_id(): ?int {
		return PendingActionHelper::$staged[0]['apply_input']['job_id'] ?? null;
	}

	function ability_execute_count( \Ability_Native_Smoke_Ability $ability ): int {
		return $ability->execute_count;
	}

	class LegacyTool {
		public static int $calls = 0;

		public function execute( array $parameters, array $tool_def ): array {
			++self::$calls;
			return array(
				'success'   => true,
				'legacy'    => true,
				'tool_name' => $tool_def['name'] ?? 'legacy_tool',
				'received'  => $parameters,
			);
		}
	}

	function execute_tool( string $tool_name, array $tool_parameters, array $tool_def, array $payload = array() ): array {
		return ToolExecutor::executeTool(
			$tool_name,
			$tool_parameters,
			array( $tool_name => $tool_def ),
			array_merge(
				array(
					'job_id' => 42,
					'data'   => array(),
				),
				$payload
			),
			ActionPolicyResolver::MODE_CHAT
		);
	}

	echo "=== ToolExecutor Ability-Native Smoke (#1480) ===\n";

	\WP_Abilities_Registry::reset();
	$registry = \WP_Abilities_Registry::get_instance();
	$ability  = new \Ability_Native_Smoke_Ability(
		fn( $input ) => isset( $input['message'] ),
		fn( $input ) => array(
			'success'  => true,
			'ability'  => true,
			'received' => $input,
			'post_id'  => 123,
		)
	);
	$registry->register_for_smoke( 'datamachine/smoke-ability', $ability );

	echo "\n[ability:1] Ability-only tool executes through WP_Ability::execute()\n";
	$result = execute_tool(
		'ability_only_tool',
		array( 'message' => 'hello' ),
		array(
			'ability'     => 'datamachine/smoke-ability',
			'description' => 'Ability-only smoke tool',
			'parameters'  => array(
				'message' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);
	assert_smoke( 'ability-only result succeeds', true === ( $result['success'] ?? false ) );
	assert_smoke( 'ability execute callback ran exactly once', 1 === $ability->execute_count );
	assert_smoke( 'AI parameter reached ability input', 'hello' === ( $result['received']['message'] ?? null ) );
	assert_smoke( 'payload parameter reached ability input', 42 === ( $result['received']['job_id'] ?? null ) );
	assert_smoke( 'successful ability result still participates in post tracking', 1 === post_tracking_count() );

	echo "\n[core:1] Generic execution core runs without Data Machine decorators\n";
	\DataMachine\Core\WordPress\PostTracking::$stored = array();
	$core_ability = new \Ability_Native_Smoke_Ability(
		fn( $input ) => true,
		fn( $input ) => 'scalar-ok'
	);
	$registry->register_for_smoke( 'datamachine/core-ability', $core_ability );
	$core_result = ( new ToolExecutionCore() )->executeTool(
		'core_ability_tool',
		array( 'message' => 'core' ),
		array(
			'core_ability_tool' => array(
				'ability'    => 'datamachine/core-ability',
				'parameters' => array(
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
		),
		array( 'job_id' => 42 )
	);
	assert_smoke( 'core wraps scalar ability result with normalized envelope', true === ( $core_result['success'] ?? false ) && 'scalar-ok' === ( $core_result['result'] ?? null ) );
	assert_smoke( 'core result includes ability slug', 'datamachine/core-ability' === ( $core_result['ability'] ?? null ) );
	assert_smoke( 'core path does not perform post tracking decoration', 0 === post_tracking_count() );

	echo "\n[decorator:1] ToolExecutor still stages pending actions before direct execution\n";
	PendingActionHelper::$staged = array();
	$preview_ability             = new \Ability_Native_Smoke_Ability(
		fn( $input ) => true,
		fn( $input ) => array(
			'success' => true,
			'post_id' => 456,
		)
	);
	$registry->register_for_smoke( 'datamachine/preview-ability', $preview_ability );
	$result = execute_tool(
		'preview_tool',
		array( 'message' => 'needs approval' ),
		array(
			'ability'       => 'datamachine/preview-ability',
			'action_policy' => ActionPolicyResolver::POLICY_PREVIEW,
			'action_kind'   => 'preview_kind',
			'parameters'    => array(
				'message' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);
	assert_smoke( 'preview policy returns staged action result', true === ( $result['staged'] ?? false ) && 'pending_1' === ( $result['action_id'] ?? null ) );
	assert_smoke( 'pending action helper received complete merged parameters', 42 === first_pending_apply_job_id() );
	assert_smoke( 'preview policy does not execute ability directly', 0 === ability_execute_count( $preview_ability ) );
	assert_smoke( 'preview policy does not post-track unexecuted action', 0 === post_tracking_count() );

	echo "\n[legacy:1] Legacy class/method tool still executes\n";
	LegacyTool::$calls = 0;
	$result            = execute_tool(
		'legacy_tool',
		array( 'message' => 'legacy' ),
		array(
			'name'    => 'legacy_tool',
			'class'   => LegacyTool::class,
			'method'  => 'execute',
			'ability' => 'datamachine/missing-legacy-ability',
		)
	);
	assert_smoke( 'legacy result succeeds', true === ( $result['success'] ?? false ) );
	assert_smoke( 'class/method metadata wins over linked ability during migration', true === ( $result['legacy'] ?? false ) );

	echo "\n[ability:2] Missing ability returns a clear failure\n";
	$result = execute_tool(
		'missing_ability_tool',
		array(),
		array(
			'ability' => 'datamachine/not-registered',
		)
	);
	assert_smoke( 'missing ability fails', false === ( $result['success'] ?? true ) );
	assert_smoke( 'missing ability error names the ability slug', false !== strpos( $result['error'] ?? '', 'datamachine/not-registered' ) );

	echo "\n[ability:3] Permission-denied ability does not execute\n";
	$denied = new \Ability_Native_Smoke_Ability(
		fn( $input ) => false,
		fn( $input ) => array( 'success' => true )
	);
	$registry->register_for_smoke( 'datamachine/denied-ability', $denied );
	$result = execute_tool(
		'denied_ability_tool',
		array(),
		array(
			'ability' => 'datamachine/denied-ability',
		)
	);
	assert_smoke( 'permission-denied ability fails', false === ( $result['success'] ?? true ) );
	assert_smoke( 'permission-denied ability execute callback did not run', 0 === ability_execute_count( $denied ) );
	assert_smoke( 'permission-denied error names the ability slug', false !== strpos( $result['error'] ?? '', 'datamachine/denied-ability' ) );

	echo "\nAssertions: " . ( $total - $failed ) . " passed, {$failed} failed, {$total} total\n";
	exit( (int) $failed );
}
