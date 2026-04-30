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

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['__filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
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

function do_action( string $hook, ...$args ): void {}

function did_action( string $hook ): int {
	return 1;
}

function current_action(): string {
	return '';
}

function get_option( string $key, $default = false ) {
	return $default;
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
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolManager.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineAgentToolPolicyProvider.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineMandatoryToolPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AdjacentHandlerToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolSourceRegistry.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolPolicyResolver.php';

use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;

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

function resolve_source_tools( string $mode, SourcePolicyToolManager $manager, array $extra = array() ): array {
	$resolver = new ToolPolicyResolver( $manager );

	return $resolver->resolve(
		array_merge(
			array(
				'mode'                 => $mode,
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
			'flow_step_id'   => 'fetch_step',
			'step_type'      => 'fetch',
			'handler_slug'   => 'ticketmaster_fetch',
			'handler_config' => array( 'marker' => 'previous' ),
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
	static function ( array $sources, string $mode, array $args, ToolManager $tool_manager ): array {
		unset( $mode, $args, $tool_manager );
		$sources['extra_source'] = static function (): array {
			return array(
				'extra_tool' => array( 'origin' => 'extra', 'access_level' => 'public' ),
			);
		};
		return $sources;
	},
	10,
	4
);
add_filter(
	'agents_api_tool_sources_for_mode',
	static function ( array $sources, string $mode, array $args ): array {
		unset( $args );
		if ( ToolPolicyResolver::MODE_CHAT === $mode ) {
			$sources[] = 'extra_source';
		}
		return $sources;
	},
	10,
	3
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

echo "\n[4] Data Machine source adapters own product vocabulary:\n";
$registry_source          = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/ToolSourceRegistry.php' );
$adjacent_source          = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/Sources/AdjacentHandlerToolSource.php' );
$datamachine_tool_source  = (string) file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php' );
assert_source_equals( false, false !== strpos( $registry_source, 'FlowStepConfig' ), 'generic registry no longer imports FlowStepConfig', $failures, $passes );
assert_source_equals( true, false !== strpos( $adjacent_source, 'FlowStepConfig' ), 'adjacent-handler source owns FlowStepConfig lookup', $failures, $passes );
assert_source_equals( false, false !== strpos( $registry_source, 'get_all_tools' ), 'generic registry no longer reads Data Machine tool registry', $failures, $passes );
assert_source_equals( true, false !== strpos( $datamachine_tool_source, 'get_all_tools' ), 'Data Machine registry source owns legacy registry lookup', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " tool source assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} tool source assertions passed.\n";
