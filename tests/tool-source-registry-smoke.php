<?php
/**
 * Pure-PHP smoke test for tool source provider composition (#1481).
 *
 * Run with: php tests/tool-source-registry-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$__filters = array();
$__datamachine_log_entries = array();

function source_smoke_filter_id( callable $callback ): string {
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
	$GLOBALS['__filters'][ $hook ][ $priority ][ source_smoke_filter_id( $callback ) ] = array( $callback, $accepted_args );
}

function remove_all_filters_for_source_smoke(): void {
	$GLOBALS['__filters'] = array();
}

function apply_filters( string $hook, $value, ...$args ) {
	if ( 'datamachine_step_types' === $hook && empty( $GLOBALS['__filters'][ $hook ] ) ) {
		return array(
			'ai'      => array( 'uses_handler' => false, 'multi_handler' => false ),
			'fetch'   => array( 'uses_handler' => true, 'multi_handler' => false ),
			'publish' => array( 'uses_handler' => true, 'multi_handler' => true ),
			'upsert'  => array( 'uses_handler' => true, 'multi_handler' => true ),
		);
	}

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

function do_action( string $hook, ...$args ): void {
	if ( 'datamachine_log' === $hook ) {
		$GLOBALS['__datamachine_log_entries'][] = $args;
	}
}

function did_action( string $hook ): int {
	return 1;
}

function current_action(): string {
	return '';
}

function get_option( string $key, $default_value = false ) {
	return $default_value;
}

class WP_Abilities_Registry {
	public static function get_instance(): self {
		return new self();
	}

	public function get_registered( string $slug ) {
		return null;
	}
}

require_once __DIR__ . '/../inc/Core/PluginSettings.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-access-policy.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-declaration.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-parameters.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-call.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-result.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-executor.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-execution-core.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-policy-filter.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-policy.php';
require_once __DIR__ . '/../vendor/automattic/agents-api/src/Tools/class-wp-agent-tool-source-registry.php';
require_once __DIR__ . '/../inc/Core/AbilityResult.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolManager.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Execution/ToolExecutionCore.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolExecutor.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineAgentToolPolicyProvider.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineMandatoryToolPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineToolAccessPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/RuntimeToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AdjacentHandlerToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AbilityToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolSourceRegistry.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolPolicyResolver.php';

use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolExecutor;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;
use DataMachine\Engine\AI\Tools\ToolSourceRegistry;

class SourcePolicyToolManager extends ToolManager {
	/** @var array<int, string> */
	public array $handler_calls = array();

	public function get_all_tools(): array {
		return array(
			'collision_tool'       => array( 'modes' => array( 'pipeline' ), 'origin' => 'static' ),
			'static_pipeline_tool' => array( 'modes' => array( 'pipeline' ), 'origin' => 'static' ),
			'chat_static_tool'     => array( 'modes' => array( 'chat' ), 'origin' => 'static', 'access_level' => 'public' ),
			'system_static_tool'   => array( 'modes' => array( 'system' ), 'origin' => 'static' ),
			'custom_static_tool'   => array( 'modes' => array( 'custom_mode' ), 'origin' => 'static' ),
			'handler_wrapper'      => array( 'modes' => array( 'pipeline' ), '_handler_callable' => 'noop' ),
			'disabled_static_tool' => array( 'modes' => array( 'chat' ), 'origin' => 'static', 'access_level' => 'public' ),
			'durable_memory_tool'  => array( 'modes' => array( 'chat', 'pipeline' ), 'origin' => 'static', 'access_level' => 'public', 'requires_opt_in' => true ),
		);
	}

	public function is_tool_available( string $tool_id, ?string $context_id = null ): bool {
		return 'disabled_static_tool' !== $tool_id;
	}

	public function is_globally_enabled( string $tool_id ): bool {
		return 'disabled_static_tool' !== $tool_id;
	}

	public function resolveHandlerTools( string $handler_slug, array $handler_config, array $engine_data, string $cache_scope = '' ): array {
		$this->handler_calls[] = $handler_slug . ':' . (string) ( $handler_config['marker'] ?? '' ) . ':' . $cache_scope;

		if ( 'publish_to_wordpress' === $handler_slug ) {
			return array(
				'collision_tool'  => array( 'handler' => $handler_slug, 'origin' => 'adjacent' ),
				'adjacent_publish' => array( 'handler' => $handler_slug, 'origin' => 'adjacent' ),
			);
		}

		if ( 'ticketmaster_fetch' === $handler_slug ) {
			return array(
				'adjacent_fetch' => array( 'handler' => $handler_slug, 'origin' => 'adjacent' ),
			);
		}

		return array();
	}
}

$failures = array();
$passes   = 0;

function assert_source_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

function count_source_smoke_callbacks( string $hook ): int {
	$count = 0;
	foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $callbacks ) {
		$count += count( $callbacks );
	}
	return $count;
}

function resolve_source_tools( string|array $modes, SourcePolicyToolManager $manager, array $extra = array() ): array {
	$resolver = new ToolPolicyResolver( $manager );

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

echo "tool-source-registry-smoke\n";

echo "\n[1] pipeline composes adjacent handlers before static registry:\n";
$manager = new SourcePolicyToolManager();
$tools   = resolve_source_tools(
	ToolPolicyResolver::MODE_PIPELINE,
	$manager,
	array(
		'previous_step_config' => array(
			'flow_step_id'    => 'fetch_step',
			'step_type'       => 'fetch',
			'handler_slugs'   => array( 'ticketmaster_fetch' ),
			'handler_configs' => array( 'ticketmaster_fetch' => array( 'marker' => 'previous' ) ),
		),
		'next_step_config'     => array(
			'flow_step_id'     => 'publish_step',
			'step_type'        => 'publish',
			'handler_slugs'    => array( 'publish_to_wordpress' ),
			'handler_configs'  => array( 'publish_to_wordpress' => array( 'marker' => 'next' ) ),
			'enabled_handlers' => array( 'publish_to_wordpress' ),
		),
	)
);
assert_source_equals( array( 'adjacent_fetch', 'collision_tool', 'adjacent_publish', 'static_pipeline_tool' ), array_keys( $tools ), 'pipeline source order is deterministic', $failures, $passes );
assert_source_equals( 'adjacent', $tools['collision_tool']['origin'] ?? '', 'adjacent handler tool wins static name collision', $failures, $passes );
assert_source_equals( false, isset( $tools['handler_wrapper'] ), 'pipeline static source skips handler wrappers', $failures, $passes );
assert_source_equals( array( 'ticketmaster_fetch:previous:fetch_step', 'publish_to_wordpress:next:publish_step' ), $manager->handler_calls, 'adjacent handler source receives configs and cache scopes', $failures, $passes );

echo "\n[2] non-pipeline modes use static registry only:\n";
$manager = new SourcePolicyToolManager();
assert_source_equals( array( 'chat_static_tool' ), array_keys( resolve_source_tools( ToolPolicyResolver::MODE_CHAT, $manager ) ), 'chat mode gets static chat tools only', $failures, $passes );
assert_source_equals( array(), $manager->handler_calls, 'chat mode does not query adjacent handlers', $failures, $passes );
assert_source_equals( array( 'system_static_tool' ), array_keys( resolve_source_tools( ToolPolicyResolver::MODE_SYSTEM, new SourcePolicyToolManager() ) ), 'system mode gets static system tools only', $failures, $passes );
assert_source_equals( array( 'custom_static_tool' ), array_keys( resolve_source_tools( 'custom_mode', new SourcePolicyToolManager() ) ), 'custom mode gets static custom-mode tools only', $failures, $passes );

echo "\n[3] filters can add a source without disturbing default order:\n";
remove_all_filters_for_source_smoke();
add_filter(
	'agents_api_tool_sources',
	static function ( array $sources, array $context ): array {
		unset( $context );
		$sources['extra_source'] = static function ( array $source_context ): array {
			unset( $source_context );
			return array(
				'extra_tool' => array( 'origin' => 'extra', 'access_level' => 'public' ),
			);
		};
		return $sources;
	},
	10,
	2
);
add_filter(
	'agents_api_tool_source_order',
	static function ( array $sources, array $context ): array {
		$modes = is_array( $context['modes'] ?? null ) ? $context['modes'] : array();
		if ( in_array( ToolPolicyResolver::MODE_CHAT, $modes, true ) ) {
			$sources[] = 'extra_source';
		}
		return $sources;
	},
	10,
	2
);
$tools = resolve_source_tools( ToolPolicyResolver::MODE_CHAT, new SourcePolicyToolManager() );
assert_source_equals( array( 'chat_static_tool', 'extra_tool' ), array_keys( $tools ), 'filter appends extra source after static source', $failures, $passes );
remove_all_filters_for_source_smoke();
add_filter(
	'datamachine_tool_sources',
	static function ( array $sources ): array {
		$sources['legacy_source'] = static function (): array {
			return array(
				'legacy_tool' => array( 'origin' => 'legacy', 'access_level' => 'public' ),
			);
		};
		return $sources;
	},
	10,
	1
);
add_filter(
	'datamachine_tool_sources_for_mode',
	static function ( array $sources ): array {
		$sources[] = 'legacy_source';
		return $sources;
	},
	10,
	1
);
assert_source_equals( array( 'chat_static_tool' ), array_keys( resolve_source_tools( ToolPolicyResolver::MODE_CHAT, new SourcePolicyToolManager() ) ), 'legacy Data Machine tool-source filters are no longer mirrored', $failures, $passes );

echo "\n[4] multi-mode runs expose every active mode surface:\n";
remove_all_filters_for_source_smoke();
assert_source_equals(
	array( 'custom_static_tool' ),
	array_keys( resolve_source_tools( 'custom_mode', new SourcePolicyToolManager() ) ),
	'custom mode alone gets only custom-mode tools',
	$failures,
	$passes
);
$multimode = resolve_source_tools( array( 'pipeline', 'custom_mode' ), new SourcePolicyToolManager() );
assert_source_equals( true, isset( $multimode['custom_static_tool'] ), 'multi-mode run keeps custom mode tools', $failures, $passes );
assert_source_equals( true, isset( $multimode['static_pipeline_tool'] ), 'multi-mode run exposes pipeline tools', $failures, $passes );
assert_source_equals( true, isset( $multimode['collision_tool'] ), 'multi-mode run exposes static pipeline tools', $failures, $passes );
assert_source_equals( false, isset( $multimode['chat_static_tool'] ), 'multi-mode run does not leak unrelated mode tools', $failures, $passes );
assert_source_equals( false, isset( $multimode['handler_wrapper'] ), 'multi-mode run still skips handler wrappers in static source', $failures, $passes );

echo "\n[5] requires_opt_in tools require allow_only:\n";
$opt_in_baseline = resolve_source_tools(
	ToolPolicyResolver::MODE_PIPELINE,
	new SourcePolicyToolManager(),
	array( 'allow_only' => array( 'durable_memory_tool' ) )
);
assert_source_equals( true, isset( $opt_in_baseline['durable_memory_tool'] ), 'pipeline mode picks up opt-in tool when allowlisted', $failures, $passes );
$opt_in_tool_policy = resolve_source_tools(
	ToolPolicyResolver::MODE_PIPELINE,
	new SourcePolicyToolManager(),
	array(
		'tool_policy' => array(
			'mode'  => 'allow',
			'tools' => array( 'durable_memory_tool' ),
		),
	)
);
assert_source_equals( true, isset( $opt_in_tool_policy['durable_memory_tool'] ), 'pipeline mode picks up opt-in tool when allowed by tool policy', $failures, $passes );
$opt_in_no_allowlist = resolve_source_tools(
	ToolPolicyResolver::MODE_PIPELINE,
	new SourcePolicyToolManager()
);
$multi_opt_in = resolve_source_tools(
	array( 'custom_mode', ToolPolicyResolver::MODE_PIPELINE ),
	new SourcePolicyToolManager(),
	array( 'allow_only' => array( 'durable_memory_tool' ) )
);
assert_source_equals( true, isset( $multi_opt_in['durable_memory_tool'] ), 'multi-mode run picks up opt-in pipeline tool when allowlisted', $failures, $passes );
$multi_opt_in_no_allowlist = resolve_source_tools(
	array( 'custom_mode', ToolPolicyResolver::MODE_PIPELINE ),
	new SourcePolicyToolManager()
);
assert_source_equals( false, isset( $multi_opt_in_no_allowlist['durable_memory_tool'] ), 'multi-mode run skips opt-in pipeline tool without allowlist', $failures, $passes );
$chat_no_inheritance = resolve_source_tools(
	ToolPolicyResolver::MODE_CHAT,
	new SourcePolicyToolManager()
);
assert_source_equals( false, isset( $chat_no_inheritance['durable_memory_tool'] ), 'chat mode also respects requires_opt_in', $failures, $passes );

echo "\n[6] runtime tools are normalized and opt-in:\n";
$GLOBALS['__datamachine_log_entries'] = array();
$runtime_tools = resolve_source_tools(
	ToolPolicyResolver::MODE_CHAT,
	new SourcePolicyToolManager(),
	array(
		'client_context' => array(
			'runtime_tools' => array(
				'client/select_block' => array(
					'description' => 'Select a block in the active editor.',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'client_id' => array( 'type' => 'string' ),
						),
					),
					'executor'    => 'client',
					'scope'       => 'run',
				),
			),
		),
	)
);
assert_source_equals( false, isset( $runtime_tools['client/select_block'] ), 'runtime tool is denied by default because it requires allow_only opt-in', $failures, $passes );

$runtime_policy_tools = resolve_source_tools(
	ToolPolicyResolver::MODE_CHAT,
	new SourcePolicyToolManager(),
	array(
		'tool_policy'    => array(
			'mode'  => 'allow',
			'tools' => array( 'client/select_block' ),
		),
		'client_context' => array(
			'runtime_tools' => array(
				'client/select_block' => array(
					'description' => 'Select a block in the active editor.',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'client_id' => array( 'type' => 'string' ),
						),
					),
					'executor'    => 'client',
					'scope'       => 'run',
				),
			),
		),
	)
);
assert_source_equals( true, isset( $runtime_policy_tools['client/select_block'] ), 'allow-mode tool policy opts in a client runtime tool', $failures, $passes );

$runtime_tools = resolve_source_tools(
	ToolPolicyResolver::MODE_CHAT,
	new SourcePolicyToolManager(),
	array(
		'allow_only'      => array( 'client/select_block' ),
		'client_context'  => array(
			'runtime_tools' => array(
				'client/select_block' => array(
					'description' => 'Select a block in the active editor.',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'client_id' => array( 'type' => 'string' ),
						),
					),
					'executor'    => 'client',
					'scope'       => 'run',
				),
				'client/bad_tool'     => array(
					'description' => 'Invalid missing executor with secret payload super-secret-token.',
					'scope'       => 'run',
				),
			),
		),
	)
);
assert_source_equals( true, isset( $runtime_tools['client/select_block'] ), 'allow_only opts in a client runtime tool through existing policy filtering', $failures, $passes );
assert_source_equals( false, isset( $runtime_tools['client/bad_tool'] ), 'invalid runtime declarations are skipped', $failures, $passes );
assert_source_equals( 1, count( $GLOBALS['__datamachine_log_entries'] ), 'invalid runtime declaration emits one diagnostic log entry', $failures, $passes );
$runtime_diagnostic = $GLOBALS['__datamachine_log_entries'][0] ?? array();
assert_source_equals( 'warning', $runtime_diagnostic[0] ?? '', 'invalid runtime declaration diagnostic uses warning level', $failures, $passes );
assert_source_equals( 'Invalid runtime tool declaration skipped', $runtime_diagnostic[1] ?? '', 'invalid runtime declaration diagnostic uses bounded message', $failures, $passes );
assert_source_equals( 'invalid_runtime_tool_declaration', $runtime_diagnostic[2]['code'] ?? '', 'invalid runtime declaration diagnostic includes stable code', $failures, $passes );
assert_source_equals( 'client/bad_tool', $runtime_diagnostic[2]['tool'] ?? '', 'invalid runtime declaration diagnostic identifies declaration name', $failures, $passes );
assert_source_equals( true, false !== strpos( $runtime_diagnostic[2]['error'] ?? '', 'executor' ), 'invalid runtime declaration diagnostic reports validation field', $failures, $passes );
assert_source_equals( false, false !== strpos( (string) json_encode( $runtime_diagnostic ), 'super-secret-token' ), 'invalid runtime declaration diagnostic excludes raw declaration payload', $failures, $passes );
assert_source_equals( 'client', $runtime_tools['client/select_block']['executor'] ?? '', 'runtime tool keeps client executor marker', $failures, $passes );
assert_source_equals( true, $runtime_tools['client/select_block']['external_executor'] ?? false, 'runtime tool is marked external executor', $failures, $passes );
assert_source_equals( 'run', $runtime_tools['client/select_block']['scope'] ?? '', 'runtime tool keeps run scope', $failures, $passes );
assert_source_equals(
	array(
		'type'       => 'object',
		'properties' => array(
			'client_id' => array( 'type' => 'string' ),
		),
	),
	$runtime_tools['client/select_block']['parameters'] ?? null,
	'runtime tool preserves normalized JSON schema parameters',
	$failures,
	$passes
);

$executor_source = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/ToolExecutor.php' );
$client_guard    = strpos( $executor_source, "'client' === (string) ( $" . "tool_def['executor'] ?? '' )" );
$policy_resolver = strpos( $executor_source, 'new ActionPolicyResolver()' );
$direct_execute  = strpos( $executor_source, 'executePreparedTool' );
assert_source_equals( true, false !== $client_guard, 'ToolExecutor has an explicit client-executor guard', $failures, $passes );
assert_source_equals( true, false !== $client_guard && false !== $policy_resolver && $client_guard < $policy_resolver, 'client-executor guard runs before action-policy/direct execution setup', $failures, $passes );
assert_source_equals( true, false !== $client_guard && false !== $direct_execute && $client_guard < $direct_execute, 'client-executor guard runs before direct PHP tool execution', $failures, $passes );

$client_execution = ToolExecutor::executeTool(
	'client/select_block',
	array( 'client_id' => 'block-1' ),
	$runtime_tools,
	array(),
	ToolPolicyResolver::MODE_CHAT,
	0,
	array()
);
assert_source_equals( false, $client_execution['success'] ?? null, 'client runtime tool is intentionally not executed server-side', $failures, $passes );
assert_source_equals( 'client', $client_execution['executor'] ?? '', 'client runtime execution refusal preserves executor marker', $failures, $passes );

echo "\n[7] Data Machine source adapters own product vocabulary:\n";
$registry_source          = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/ToolSourceRegistry.php' );
$adjacent_source          = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/Sources/AdjacentHandlerToolSource.php' );
$datamachine_tool_source  = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php' );
assert_source_equals( false, false !== strpos( $registry_source, 'FlowStepConfig' ), 'generic registry no longer imports FlowStepConfig', $failures, $passes );
assert_source_equals( true, false !== strpos( $adjacent_source, 'FlowStepConfig' ), 'adjacent-handler source owns FlowStepConfig lookup', $failures, $passes );
assert_source_equals( false, false !== strpos( $registry_source, 'get_all_tools' ), 'generic registry no longer reads Data Machine tool registry', $failures, $passes );
assert_source_equals( true, false !== strpos( $datamachine_tool_source, 'get_all_tools' ), 'Data Machine registry source owns legacy registry lookup', $failures, $passes );
assert_source_equals( true, false !== strpos( $registry_source, 'WP_Agent_Tool_Source_Registry' ), 'ToolSourceRegistry delegates source composition to Agents API registry', $failures, $passes );
assert_source_equals( true, false !== strpos( $registry_source, 'agents_api_tool_source_order' ), 'Data Machine mode ordering uses Agents API source-order hook', $failures, $passes );

echo "\n[8] source ordering uses one shared global callback:\n";
remove_all_filters_for_source_smoke();
new ToolSourceRegistry( new SourcePolicyToolManager() );
new ToolSourceRegistry( new SourcePolicyToolManager() );
new ToolSourceRegistry( new SourcePolicyToolManager() );
assert_source_equals( 1, count_source_smoke_callbacks( 'agents_api_tool_source_order' ), 'multiple registry instances do not accumulate duplicate source-order callbacks', $failures, $passes );
assert_source_equals(
	array( 'static_registry', 'adjacent_handlers', 'runtime_tools' ),
	ToolSourceRegistry::orderSourcesForContext( array( 'static_registry', 'adjacent_handlers', 'runtime_tools' ), array( 'modes' => array( ToolPolicyResolver::MODE_CHAT ) ), new AgentsAPI\AI\Tools\WP_Agent_Tool_Source_Registry() ),
	'ordering callback leaves non-Data Machine registries unchanged',
	$failures,
	$passes
);

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " tool source assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} tool source assertions passed.\n";
