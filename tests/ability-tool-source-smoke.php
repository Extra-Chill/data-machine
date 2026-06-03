<?php
/**
 * Pure-PHP smoke test for ability-backed tool declarations (#2448).
 *
 * Run with: php tests/ability-tool-source-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$__filters = array();

function ability_tool_smoke_filter_id( callable $callback ): string {
	if ( is_array( $callback ) ) {
		$owner = is_object( $callback[0] ) ? spl_object_hash( $callback[0] ) : (string) $callback[0];
		return $owner . '::' . (string) $callback[1];
	}

	if ( $callback instanceof Closure ) {
		return spl_object_hash( $callback );
	}

	return is_string( $callback ) ? $callback : spl_object_hash( (object) $callback );
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['__filters'][ $hook ][ $priority ][ ability_tool_smoke_filter_id( $callback ) ] = array( $callback, $accepted_args );
}

function apply_filters( string $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['__filters'][ $hook ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['__filters'][ $hook ] );
	foreach ( $GLOBALS['__filters'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$callback      = $entry[0];
			$accepted_args = $entry[1];
			$call_args     = array_slice( array_merge( array( $value ), $args ), 0, $accepted_args );
			$value         = $callback( ...$call_args );
		}
	}

	return $value;
}

function do_action( string $hook, ...$args ): void {}

function did_action( string $hook ): int {
	return 1;
}

function current_action(): string {
	return '';
}

function get_option( string $key, $default_value = false ) {
	return $default_value;
}

function is_wp_error( $value ): bool {
	return false;
}

class WP_Abilities_Registry {
	/** @var array<string, Ability_Tool_Source_Smoke_Ability> */
	private static array $abilities = array();

	public static function reset(): void {
		self::$abilities = array();
	}

	public static function get_instance(): self {
		return new self();
	}

	public function register_for_smoke( string $slug, Ability_Tool_Source_Smoke_Ability $ability ): void {
		self::$abilities[ $slug ] = $ability;
	}

	public function is_registered( string $slug ): bool {
		return isset( self::$abilities[ $slug ] );
	}

	public function get_registered( string $slug ) {
		return self::$abilities[ $slug ] ?? null;
	}
}

class Ability_Tool_Source_Smoke_Ability {
	public int $execute_count = 0;

	public function __construct(
		private string $name,
		private string $label,
		private string $description,
		private string $category,
		private array $input_schema,
		private array $meta = array(),
		private bool $permitted = true
	) {}

	public function get_name(): string {
		return $this->name;
	}

	public function get_label(): string {
		return $this->label;
	}

	public function get_description(): string {
		return $this->description;
	}

	public function get_category(): string {
		return $this->category;
	}

	public function get_input_schema(): array {
		return $this->input_schema;
	}

	public function get_meta(): array {
		return $this->meta;
	}

	public function check_permissions( $input = null ): bool {
		unset( $input );
		return $this->permitted;
	}

	public function execute( $input = null ): array {
		++$this->execute_count;
		return array(
			'success'  => true,
			'received' => $input,
		);
	}
}

require_once __DIR__ . '/../inc/Core/PluginSettings.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-access-policy.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-declaration.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-parameters.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-call.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-result.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-executor.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-execution-core.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-policy-filter.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-policy.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-source-registry.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Approvals/class-wp-agent-approval-memory-store.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Approvals/class-wp-agent-null-approval-memory-store.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Workspace/class-wp-agent-workspace-scope.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-action-policy.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-action-policy-provider.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-action-policy-resolver.php';
require_once __DIR__ . '/../inc/Core/AbilityResult.php';
require_once __DIR__ . '/../inc/Core/Workspace/WordPressWorkspaceScope.php';
require_once __DIR__ . '/../inc/Core/WordPress/PostTracking.php';
require_once __DIR__ . '/../inc/Engine/AI/Actions/DataMachineModeActionPolicyProvider.php';
require_once __DIR__ . '/../inc/Engine/AI/Actions/ActionPolicyResolver.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolManager.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Execution/ToolExecutionCore.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolExecutor.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ability-tool-projections.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineAgentToolPolicyProvider.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineMandatoryToolPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineToolAccessPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/RuntimeToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AdjacentHandlerToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AbilityToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolSourceRegistry.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolPolicyResolver.php';

use DataMachine\Engine\AI\Tools\ToolExecutor;
use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use DataMachine\Engine\AI\Tools\Sources\AbilityToolSource;

class AbilityToolSourceSmokeManager extends ToolManager {
	public function get_all_tools(): array {
		return array(
			'collision_tool' => array(
				'description' => 'Static tool wins collisions.',
				'modes'       => array( 'pipeline' ),
				'origin'      => 'static',
			),
		);
	}

	public function is_tool_available( string $tool_id, ?string $context_id = null ): bool {
		unset( $context_id );
		return 'disabled_ability_tool' !== $tool_id;
	}

	public function is_globally_enabled( string $tool_id ): bool {
		return 'disabled_ability_tool' !== $tool_id;
	}
}

$failures = array();
$passes   = 0;

function assert_ability_tool_source_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

function resolve_ability_source_tools( string|array $modes, array $extra = array() ): array {
	$resolver = new ToolPolicyResolver( new AbilityToolSourceSmokeManager() );

	return $resolver->resolve(
		array_merge(
			array(
				'modes'                => is_array( $modes ) ? $modes : array( $modes ),
				'agent_id'             => 0,
				'previous_step_config' => null,
				'next_step_config'     => null,
				'engine_data'          => array(),
				'categories'           => array(),
			),
			$extra
		)
	);
}

echo "ability-tool-source-smoke\n";

WP_Abilities_Registry::reset();
$registry = WP_Abilities_Registry::get_instance();
$ability  = new Ability_Tool_Source_Smoke_Ability(
	'demo/summarize',
	'Summarize Demo',
	'Summarize a demo payload.',
	'demo-category',
	array(
		'type'       => 'object',
		'required'   => array( 'message' ),
		'properties' => array(
			'message' => array(
				'type'        => 'string',
				'description' => 'Message to summarize.',
			),
		),
	),
	array(
		'annotations' => array(
			'readonly'   => true,
			'idempotent' => true,
		),
	)
);
$registry->register_for_smoke( 'demo/summarize', $ability );

$disabled = new Ability_Tool_Source_Smoke_Ability(
	'demo/disabled',
	'Disabled Demo',
	'Disabled ability.',
	'demo-category',
	array( 'type' => 'object' )
);
$registry->register_for_smoke( 'demo/disabled', $disabled );

$helper_registered = datamachine_register_ability_tool(
	'helper_demo',
	array(
		'ability' => 'demo/summarize',
		'modes'   => array( 'chat' ),
	)
);

add_filter(
	'datamachine_ability_tool_projections',
	static function ( array $tools ): array {
		$tools['summarize_demo'] = array(
			'ability'              => 'demo/summarize',
			'modes'                => array( 'chat', 'pipeline' ),
			'action_policy'        => 'direct',
			'requires_opt_in'      => true,
			'client_context_bindings' => array( 'job_id' ),
		);
		$tools['collision_tool'] = array(
			'ability' => 'demo/summarize',
			'modes'   => array( 'pipeline' ),
		);
		$tools['disabled_ability_tool'] = array(
			'ability'         => 'demo/disabled',
			'modes'           => array( 'pipeline' ),
			'requires_config' => true,
		);
		$tools['missing_ability_tool'] = array(
			'ability' => 'demo/missing',
			'modes'   => array( 'pipeline' ),
		);
		return $tools;
	},
	10,
	1
);

add_filter(
	'datamachine_ability_tools',
	static function ( array $tools ): array {
		$tools['legacy_alias_demo'] = array(
			'ability' => 'demo/summarize',
			'modes'   => array( 'chat' ),
		);
		return $tools;
	},
	10,
	1
);

add_filter(
	'datamachine_ability_tool_definition',
	static function ( array $tool, string $tool_name ): array {
		if ( 'summarize_demo' === $tool_name ) {
			$tool['description'] = 'Model-facing summary override.';
		}
		return $tool;
	},
	10,
	2
);

echo "\n[1] ability metadata becomes a model-facing tool declaration:\n";
$source = new AbilityToolSource( new AbilityToolSourceSmokeManager() );
$tools  = $source( array( 'chat' ), array( 'allow_only' => array( 'summarize_demo' ) ) );
assert_ability_tool_source_equals( true, $helper_registered, 'helper registers a valid ability tool projection', $failures, $passes );
assert_ability_tool_source_equals( true, isset( $tools['summarize_demo'] ), 'chat mode exposes selected ability tool when opted in', $failures, $passes );
assert_ability_tool_source_equals( true, isset( $tools['helper_demo'] ), 'helper projection feeds ability tool source', $failures, $passes );
assert_ability_tool_source_equals( true, isset( $tools['legacy_alias_demo'] ), 'legacy ability tools filter remains supported', $failures, $passes );
assert_ability_tool_source_equals( 'demo/summarize', $tools['summarize_demo']['ability'] ?? '', 'generated tool links ability slug', $failures, $passes );
assert_ability_tool_source_equals( 'Summarize Demo', $tools['summarize_demo']['label'] ?? '', 'generated tool carries ability label', $failures, $passes );
assert_ability_tool_source_equals( 'demo-category', $tools['summarize_demo']['ability_category'] ?? '', 'generated tool carries ability category', $failures, $passes );
assert_ability_tool_source_equals( 'Model-facing summary override.', $tools['summarize_demo']['description'] ?? '', 'definition filter can override model-facing description', $failures, $passes );
assert_ability_tool_source_equals( array( 'message' ), $tools['summarize_demo']['parameters']['required'] ?? array(), 'generated parameters come from ability input schema', $failures, $passes );
assert_ability_tool_source_equals( true, $tools['summarize_demo']['annotations']['readonly'] ?? false, 'generated tool carries ability annotations', $failures, $passes );

echo "\n[2] source policy handles opt-in, missing abilities, config, and modes:\n";
$no_opt_in = $source( array( 'pipeline' ), array() );
assert_ability_tool_source_equals( false, isset( $no_opt_in['summarize_demo'] ), 'requires_opt_in hides ability tool without allowlist', $failures, $passes );
$with_opt_in = $source( array( 'pipeline' ), array( 'allow_only' => array( 'summarize_demo' ) ) );
assert_ability_tool_source_equals( true, isset( $with_opt_in['summarize_demo'] ), 'allow_only exposes opt-in ability tool', $failures, $passes );
assert_ability_tool_source_equals( false, isset( $with_opt_in['missing_ability_tool'] ), 'missing ability declaration is skipped', $failures, $passes );
assert_ability_tool_source_equals( false, isset( $with_opt_in['disabled_ability_tool'] ), 'requires_config ability tool respects tool availability', $failures, $passes );
$chat_only = $source( array( 'chat' ), array( 'allow_only' => array( 'summarize_demo' ) ) );
assert_ability_tool_source_equals( false, isset( $chat_only['collision_tool'] ), 'mode filtering hides unrelated ability tools', $failures, $passes );

echo "\n[3] composed resolver keeps static tools ahead of generated ability tools:\n";
$resolved = resolve_ability_source_tools( 'pipeline', array( 'allow_only' => array( 'summarize_demo', 'collision_tool' ) ) );
assert_ability_tool_source_equals( array( 'collision_tool', 'summarize_demo' ), array_keys( $resolved ), 'static registry wins generated ability tool name collisions', $failures, $passes );
assert_ability_tool_source_equals( 'static', $resolved['collision_tool']['origin'] ?? '', 'collision preserves static declaration', $failures, $passes );

echo "\n[4] generated declaration executes through ability-native ToolExecutionCore:\n";
$result = ToolExecutor::executeTool(
	'summarize_demo',
	array( 'message' => 'hello' ),
	array( 'summarize_demo' => $with_opt_in['summarize_demo'] ),
	array( 'job_id' => 123 ),
	ToolPolicyResolver::MODE_PIPELINE
);
assert_ability_tool_source_equals( true, $result['success'] ?? false, 'generated ability tool executes successfully', $failures, $passes );
assert_ability_tool_source_equals( 1, $ability->execute_count, 'ability execute callback ran once', $failures, $passes );
assert_ability_tool_source_equals( 'hello', $result['result']['received']['message'] ?? '', 'AI parameter reached ability input', $failures, $passes );
assert_ability_tool_source_equals( 123, $result['result']['received']['job_id'] ?? 0, 'payload context reached ability input through existing binding path', $failures, $passes );

echo "\nAssertions: {$passes} passed, " . count( $failures ) . ' failed, ' . ( $passes + count( $failures ) ) . " total\n";
exit( count( $failures ) );
