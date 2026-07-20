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

		public mixed $last_input = null;

		public function __construct( callable $permission_callback, callable $execute_callback ) {
			$this->permission_callback = $permission_callback;
			$this->execute_callback    = $execute_callback;
		}

		public function check_permissions( $input = null ) {
			return call_user_func( $this->permission_callback, $input );
		}

		public function execute( $input = null ) {
			++$this->execute_count;
			$this->last_input = $input;
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
			return (int) ( $tool_result['post_id'] ?? $tool_result['result']['post_id'] ?? 0 );
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

	require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-declaration.php';
	require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-parameters.php';
	require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-call.php';
	require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Runtime/class-wp-agent-citation-metadata.php';
	require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-result.php';
	require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-executor.php';
	require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-executor-registry.php';
	require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-execution-core.php';
	require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Tools/class-wp-agent-action-policy.php';
	require_once dirname( __DIR__ ) . '/vendor/wordpress/agents-api/src/Workspace/class-wp-agent-workspace-scope.php';
	require_once dirname( __DIR__ ) . '/inc/Core/AbilityResult.php';
	require_once dirname( __DIR__ ) . '/inc/Core/Workspace/WordPressWorkspaceScope.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/AI/ToolSchemaNormalizer.php';
	require_once dirname( __DIR__ ) . '/inc/Engine/AI/Tools/AbilityToolAdapter.php';
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
		// @phpstan-ignore-next-line smoke-test stub property shadows production class.
		return count( \DataMachine\Core\WordPress\PostTracking::$stored );
	}

	function first_pending_apply_job_id(): ?int {
		// @phpstan-ignore-next-line smoke-test stub property shadows production class.
		return PendingActionHelper::$staged[0]['apply_input']['job_id'] ?? null;
	}

	function missing_parameters( array $result ): array {
		$missing = $result['metadata']['missing_parameters'] ?? array();
		return is_array( $missing ) ? $missing : array();
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

	class CompositeIssueTool {
		public static int $calls = 0;

		public function execute( array $parameters, array $tool_def ): array {
			++self::$calls;
			$action       = (string) ( $parameters['action'] ?? 'update' );
			$ability_slug = 'comment' === $action ? 'datamachine/comment-issue' : 'datamachine/update-issue';
			$ability      = \WP_Abilities_Registry::get_instance()->get_registered( $ability_slug );

			return $ability->execute(
				array(
					'action'    => $action,
					'issue'     => $parameters['issue'] ?? 0,
					'body'      => $parameters['body'] ?? '',
					'tool_name' => $tool_def['name'] ?? 'composite_issue_tool',
				)
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
			'ability'           => 'datamachine/smoke-ability',
			'execution_ability' => 'datamachine/smoke-ability',
			'description'       => 'Ability-only smoke tool',
			'parameters'        => array(
				'message' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);
	assert_smoke( 'ability-only result succeeds', true === ( $result['success'] ?? false ) );
	assert_smoke( 'ability execute callback ran exactly once', 1 === $ability->execute_count );
	assert_smoke( 'AI parameter reached ability input', 'hello' === ( $result['result']['received']['message'] ?? null ) );
	assert_smoke( 'ambient payload does not implicitly satisfy ability input', ! array_key_exists( 'job_id', $result['result']['received'] ?? array() ) );
	assert_smoke( 'successful ability result still participates in post tracking', 1 === post_tracking_count() );

	echo "\n[ability:1b] Explicit context bindings satisfy runtime-owned parameters\n";
	$bound_ability = new \Ability_Native_Smoke_Ability(
		fn( $input ) => isset( $input['message'], $input['job_id'] ),
		fn( $input ) => array(
			'success'  => true,
			'received' => $input,
		)
	);
	$registry->register_for_smoke( 'datamachine/bound-ability', $bound_ability );
	$result = execute_tool(
		'bound_ability_tool',
		array( 'message' => 'hello' ),
		array(
			'ability'                 => 'datamachine/bound-ability',
			'execution_ability'       => 'datamachine/bound-ability',
			'client_context_bindings' => array( 'job_id' ),
			'parameters'              => array(
				'message' => array(
					'type'     => 'string',
					'required' => true,
				),
				'job_id'  => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		)
	);
	assert_smoke( 'explicit binding executes successfully', true === ( $result['success'] ?? false ) );
	assert_smoke( 'explicit binding passed job_id from runtime context', 42 === ( $result['result']['received']['job_id'] ?? null ) );

	echo "\n[ability:1c] Ambient runtime keys do not satisfy required parameters\n";
	$runtime_keys_ability = new \Ability_Native_Smoke_Ability(
		fn( $input ) => true,
		fn( $input ) => array(
			'success'  => true,
			'received' => $input,
		)
	);
	$registry->register_for_smoke( 'datamachine/runtime-keys-ability', $runtime_keys_ability );
	$result  = execute_tool(
		'runtime_keys_tool',
		array( 'message' => 'hello' ),
		array(
			'ability'           => 'datamachine/runtime-keys-ability',
			'execution_ability' => 'datamachine/runtime-keys-ability',
			'parameters'        => array(
				'message'      => array(
					'type'     => 'string',
					'required' => true,
				),
				'job_id'       => array(
					'type'     => 'integer',
					'required' => true,
				),
				'flow_step_id' => array(
					'type'     => 'integer',
					'required' => true,
				),
				'agent_id'     => array(
					'type'     => 'integer',
					'required' => true,
				),
				'user_id'      => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		),
		array(
			'flow_step_id' => 77,
			'agent_id'     => 88,
			'user_id'      => 99,
		)
	);
	$missing = missing_parameters( $result );
	assert_smoke( 'ambient runtime keys fail required-parameter validation without bindings', false === ( $result['success'] ?? true ) );
	assert_smoke( 'unbound job_id remains missing', in_array( 'job_id', $missing, true ) );
	assert_smoke( 'unbound flow_step_id remains missing', in_array( 'flow_step_id', $missing, true ) );
	assert_smoke( 'unbound agent_id remains missing', in_array( 'agent_id', $missing, true ) );
	assert_smoke( 'unbound user_id remains missing', in_array( 'user_id', $missing, true ) );
	assert_smoke( 'ability is not executed when runtime bindings are absent', 0 === ability_execute_count( $runtime_keys_ability ) );

	echo "\n[core:1] Generic execution core runs without Data Machine decorators\n";
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	\DataMachine\Core\WordPress\PostTracking::$stored = array();
	$core_ability = new \Ability_Native_Smoke_Ability(
		fn( $input ) => true,
		fn( $input ) => 'scalar-ok'
	);
	$registry->register_for_smoke( 'datamachine/core-ability', $core_ability );
	$core_result = ( new \AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core() )->executeTool(
		'core_ability_tool',
		array( 'message' => 'core' ),
		array(
			'core_ability_tool' => array(
				'ability'           => 'datamachine/core-ability',
				'execution_ability' => 'datamachine/core-ability',
				'parameters'        => array(
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
		),
		new ToolExecutionCore(),
		array( 'job_id' => 42 )
	);
	assert_smoke( 'core wraps scalar ability result with normalized envelope', true === ( $core_result['success'] ?? false ) && 'scalar-ok' === ( $core_result['result'] ?? null ) );
	assert_smoke( 'core scalar envelope does not mirror result into data', ! array_key_exists( 'data', $core_result ) );
	assert_smoke( 'core result includes ability slug metadata', 'datamachine/core-ability' === ( $core_result['metadata']['ability'] ?? null ) );
	assert_smoke( 'core path does not perform post tracking decoration', 0 === post_tracking_count() );

	echo "\n[core:1b] Ability maps route through the central adapter\n";
	$mapped_ability = new \Ability_Native_Smoke_Ability(
		fn( $input ) => true,
		fn( $input ) => array(
			'success' => true,
			'visible' => $input['visible'] ?? '',
			'_cache'  => 'internal',
		)
	);
	$registry->register_for_smoke( 'datamachine/mapped-ability', $mapped_ability );
	$mapped_result = ( new \AgentsAPI\AI\Tools\WP_Agent_Tool_Execution_Core() )->executeTool(
		'mapped_ability_tool',
		array(
			'action'  => 'route',
			'visible' => 'kept',
		),
		array(
			'mapped_ability_tool' => array(
				'ability'                    => 'datamachine/mapped-ability',
				'ability_map'                => array( 'route' => 'datamachine/mapped-ability' ),
				'strip_action_parameter'     => true,
				'strip_internal_result_keys' => true,
			),
		),
		new ToolExecutionCore()
	);
	assert_smoke( 'ability map result succeeds', true === ( $mapped_result['success'] ?? false ) );
	assert_smoke( 'ability map strips action before execution', ! array_key_exists( 'action', $mapped_ability->last_input ?? array() ) );
	assert_smoke( 'ability map preserves public result keys', 'kept' === ( $mapped_result['result']['visible'] ?? null ) );
	assert_smoke( 'ability map strips internal result keys when requested', ! array_key_exists( '_cache', $mapped_result['result'] ?? array() ) );

	echo "\n[decorator:1] ToolExecutor still stages pending actions before direct execution\n";
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
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
			'ability'           => 'datamachine/preview-ability',
			'execution_ability' => 'datamachine/preview-ability',
			'action_policy'     => ActionPolicyResolver::POLICY_PREVIEW,
			'action_kind'       => 'preview_kind',
			'parameters'        => array(
				'message' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);
	assert_smoke( 'preview policy returns staged action result', true === ( $result['staged'] ?? false ) && 'pending_1' === ( $result['action_id'] ?? null ) );
	assert_smoke( 'pending action helper does not receive undeclared ambient job_id', null === first_pending_apply_job_id() );
	assert_smoke( 'preview policy does not execute ability directly', 0 === ability_execute_count( $preview_ability ) );
	assert_smoke( 'preview policy does not post-track unexecuted action', 0 === post_tracking_count() );

	echo "\n[decorator:2] Staged policy fails closed without pending-action metadata\n";
	// @phpstan-ignore-next-line smoke-test stub property shadows production class.
	PendingActionHelper::$staged = array();
	$missing_metadata_ability    = new \Ability_Native_Smoke_Ability(
		fn( $input ) => true,
		fn( $input ) => array( 'success' => true )
	);
	$registry->register_for_smoke( 'datamachine/missing-metadata-ability', $missing_metadata_ability );
	$result = execute_tool(
		'preview_missing_metadata_tool',
		array( 'message' => 'needs approval' ),
		array(
			'ability'           => 'datamachine/missing-metadata-ability',
			'execution_ability' => 'datamachine/missing-metadata-ability',
			'action_policy'     => ActionPolicyResolver::POLICY_PREVIEW,
			'parameters'        => array(
				'message' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);
	assert_smoke( 'preview without action_kind fails closed', false === ( $result['success'] ?? true ) );
	assert_smoke( 'missing metadata error is machine-readable', 'missing_pending_action_metadata' === ( $result['metadata']['error_type'] ?? null ) );
	assert_smoke( 'preview without action_kind does not stage ambiguous action', 0 === count( PendingActionHelper::$staged ) );
	assert_smoke( 'preview without action_kind does not execute ability directly', 0 === ability_execute_count( $missing_metadata_ability ) );

	echo "\n[ability:2] Class/method tool executes wrapper when ability metadata is present\n";
	LegacyTool::$calls = 0;
	$result            = execute_tool(
		'wrapper_with_ability_metadata_tool',
		array( 'message' => 'wrapper' ),
		array(
			'name'    => 'wrapper_with_ability_metadata_tool',
			'class'   => LegacyTool::class,
			'method'  => 'execute',
			'ability' => 'datamachine/smoke-ability',
		)
	);
	assert_smoke( 'wrapper-with-ability-metadata result succeeds', true === ( $result['success'] ?? false ) );
	assert_smoke( 'class/method wrapper wins over permission ability metadata', true === ( $result['result']['legacy'] ?? false ) );
	assert_smoke( 'ability metadata does not bypass class/method wrapper', 1 === LegacyTool::$calls );
	assert_smoke( 'permission ability is not executed directly for wrapper tool', 1 === ability_execute_count( $ability ) );

	echo "\n[ability:2b] Composite wrapper routes based on arguments despite ability metadata\n";
	$update_issue = new \Ability_Native_Smoke_Ability(
		fn( $input ) => true,
		fn( $input ) => array(
			'success' => true,
			'route'   => 'update',
			'input'   => $input,
		)
	);
	$comment_issue = new \Ability_Native_Smoke_Ability(
		fn( $input ) => true,
		fn( $input ) => array(
			'success' => true,
			'route'   => 'comment',
			'input'   => $input,
		)
	);
	$registry->register_for_smoke( 'datamachine/update-issue', $update_issue );
	$registry->register_for_smoke( 'datamachine/comment-issue', $comment_issue );
	CompositeIssueTool::$calls = 0;
	$result                    = execute_tool(
		'manage_issue_tool',
		array(
			'action' => 'comment',
			'issue'  => 487,
			'body'   => 'regression smoke',
		),
		array(
			'name'       => 'manage_issue_tool',
			'class'      => CompositeIssueTool::class,
			'method'     => 'execute',
			'ability'    => 'datamachine/update-issue',
			'parameters' => array(
				'action' => array( 'type' => 'string' ),
				'issue'  => array( 'type' => 'integer' ),
				'body'   => array( 'type' => 'string' ),
			),
		)
	);
	assert_smoke( 'composite wrapper result succeeds', true === ( $result['success'] ?? false ) );
	assert_smoke( 'composite wrapper selected comment route', 'comment' === ( $result['result']['route'] ?? null ) );
	assert_smoke( 'composite wrapper executed exactly once', 1 === CompositeIssueTool::$calls );
	assert_smoke( 'permission metadata ability was not executed as the route', 0 === ability_execute_count( $update_issue ) );
	assert_smoke( 'routed comment ability executed exactly once', 1 === ability_execute_count( $comment_issue ) );

	echo "\n[ability:2c] Ambiguous ability metadata without execution marker is rejected\n";
	$ambiguous = new \Ability_Native_Smoke_Ability(
		fn( $input ) => true,
		fn( $input ) => array( 'success' => true )
	);
	$registry->register_for_smoke( 'datamachine/ambiguous-ability', $ambiguous );
	$result = execute_tool(
		'ambiguous_ability_metadata_tool',
		array(),
		array(
			'ability' => 'datamachine/ambiguous-ability',
		)
	);
	assert_smoke( 'ambiguous ability-only metadata fails closed', false === ( $result['success'] ?? true ) );
	assert_smoke( 'ambiguous ability-only metadata reports stable error type', 'ambiguous_tool_execution_contract' === ( $result['metadata']['error_type'] ?? null ) );
	assert_smoke( 'ambiguous ability-only metadata does not execute ability', 0 === ability_execute_count( $ambiguous ) );

	echo "\n[ability:3] Missing ability returns a clear failure\n";
	$result = execute_tool(
		'missing_ability_tool',
		array(),
		array(
			'execution_ability' => 'datamachine/not-registered',
		)
	);
	assert_smoke( 'missing ability fails', false === ( $result['success'] ?? true ) );
	assert_smoke( 'missing ability error names the ability slug', false !== strpos( $result['error'] ?? '', 'datamachine/not-registered' ) );

	echo "\n[ability:4] Permission-denied ability does not execute\n";
	$denied = new \Ability_Native_Smoke_Ability(
		fn( $input ) => false,
		fn( $input ) => array( 'success' => true )
	);
	$registry->register_for_smoke( 'datamachine/denied-ability', $denied );
	$result = execute_tool(
		'denied_ability_tool',
		array(),
		array(
			'ability'           => 'datamachine/denied-ability',
			'execution_ability' => 'datamachine/denied-ability',
		)
	);
	assert_smoke( 'permission-denied ability fails', false === ( $result['success'] ?? true ) );
	assert_smoke( 'permission-denied ability execute callback did not run', 0 === ability_execute_count( $denied ) );
	assert_smoke( 'permission-denied error names the ability slug', false !== strpos( $result['error'] ?? '', 'datamachine/denied-ability' ) );

	echo "\nAssertions: " . ( $total - $failed ) . " passed, {$failed} failed, {$total} total\n";
	exit( (int) $failed );
}
