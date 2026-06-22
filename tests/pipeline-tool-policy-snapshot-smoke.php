<?php
/**
 * Pure-PHP smoke test for snapshot-based pipeline tool policy (#1445).
 *
 * Run with: php tests/pipeline-tool-policy-snapshot-smoke.php
 *
 * @package DataMachine\Tests
 */

namespace DataMachine\Abilities\Flow {
	if ( ! class_exists( QueueAbility::class, false ) ) {
		class QueueAbility {
			const SLOT_PROMPT_QUEUE       = 'prompt_queue';
			const SLOT_CONFIG_PATCH_QUEUE = 'config_patch_queue';
		}
	}
}

namespace DataMachine\Core {
	if ( ! class_exists( PluginSettings::class, false ) ) {
		class PluginSettings {
			public static function get( string $key, $default = null ) {
				return $default;
			}
		}
	}
}

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		global $datamachine_pipeline_policy_filters;
		if ( is_array( $datamachine_pipeline_policy_filters[ $hook ] ?? null ) ) {
			foreach ( $datamachine_pipeline_policy_filters[ $hook ] as $callback ) {
				$value = $callback( $value, ...$args );
			}
			return $value;
		}

		if ( 'datamachine_step_types' === $hook ) {
			return array(
				'ai'      => array( 'uses_handler' => false, 'multi_handler' => false ),
				'fetch'   => array( 'uses_handler' => true, 'multi_handler' => false ),
				'publish' => array( 'uses_handler' => true, 'multi_handler' => true ),
				'upsert'  => array( 'uses_handler' => true, 'multi_handler' => true ),
			);
		}

		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback ): bool {
		global $datamachine_pipeline_policy_filters;
		$datamachine_pipeline_policy_filters[ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook, callable $callback ): bool {
		global $datamachine_pipeline_policy_filters;
		if ( ! is_array( $datamachine_pipeline_policy_filters[ $hook ] ?? null ) ) {
			return false;
		}

		foreach ( $datamachine_pipeline_policy_filters[ $hook ] as $index => $registered_callback ) {
			if ( $registered_callback === $callback ) {
				unset( $datamachine_pipeline_policy_filters[ $hook ][ $index ] );
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		// no-op for tests.
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return 1;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		static $i = 0;
		++$i;
		return 'uuid-' . $i;
	}
}

if ( ! class_exists( 'SnapshotPolicyAbility', false ) ) {
	class SnapshotPolicyAbility {
		public function get_meta(): array {
			return array( 'annotations' => array() );
		}

		public function get_category(): string {
			return 'test';
		}

		public function get_description(): string {
			return 'Snapshot policy test ability';
		}

		public function get_label(): string {
			return 'Snapshot policy test ability';
		}

		public function get_input_schema(): array {
			return array( 'type' => 'object', 'properties' => array() );
		}
	}
}

if ( ! class_exists( 'WP_Abilities_Registry', false ) ) {
	class WP_Abilities_Registry {
		public static function get_instance(): self {
			return new self();
		}

		public function is_registered( string $ability_slug ): bool {
			return in_array( $ability_slug, array( 'test/control-plane', 'test/runner' ), true );
		}

		public function get_registered( string $ability_slug ): ?SnapshotPolicyAbility {
			return $this->is_registered( $ability_slug ) ? new SnapshotPolicyAbility() : null;
		}
	}
}

function datamachine_pipeline_policy_register_test_ability( string $ability_slug ): void {
	if ( ! class_exists( 'WP_Abilities_Registry', false ) || ! method_exists( 'WP_Abilities_Registry', 'get_instance' ) ) {
		return;
	}

	if ( class_exists( 'WP_Ability_Categories_Registry', false ) && method_exists( 'WP_Ability_Categories_Registry', 'get_instance' ) ) {
		$category_registry = WP_Ability_Categories_Registry::get_instance();
		if ( is_object( $category_registry ) && method_exists( $category_registry, 'register' ) ) {
			$category_registry->register(
				'test',
				array(
					'label'       => 'Test',
					'description' => 'Synthetic test abilities.',
				)
			);
		}
	}

	$registry = WP_Abilities_Registry::get_instance();
	if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
		return;
	}

	if ( method_exists( $registry, 'is_registered' ) && $registry->is_registered( $ability_slug ) ) {
		return;
	}

	$registry->register(
		$ability_slug,
		array(
			'label'               => 'Snapshot policy test ability',
			'description'         => 'Snapshot policy test ability',
			'category'            => 'test',
			'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
			'output_schema'       => array( 'type' => 'object', 'properties' => array() ),
			'execute_callback'    => static fn() => array(),
			'permission_callback' => static fn() => true,
			'meta'                => array( 'annotations' => array() ),
		)
	);
}

datamachine_pipeline_policy_register_test_ability( 'test/control-plane' );
datamachine_pipeline_policy_register_test_ability( 'test/runner' );

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfigFactory.php';
require_once __DIR__ . '/../inc/Core/Steps/WorkflowConfigFactory.php';
require_once __DIR__ . '/../inc/Core/Steps/AI/ToolPolicy/PipelineToolPolicyArgs.php';
require_once __DIR__ . '/../inc/Core/DataPath.php';
require_once __DIR__ . '/../inc/Core/OutputContract.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-access-policy.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-declaration.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-policy-filter.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-policy.php';
require_once __DIR__ . '/../vendor/wordpress/agents-api/src/Tools/class-wp-agent-tool-source-registry.php';
require_once __DIR__ . '/../inc/Engine/AI/ToolSchemaNormalizer.php';
require_once __DIR__ . '/../inc/Engine/AI/DataMachineCompletionAssertions.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolManager.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/AbilityToolAdapter.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineAgentToolPolicyProvider.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineMandatoryToolPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineToolAccessPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/HostToolPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/RuntimeToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AdjacentHandlerToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AbilityToolSource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolSourceRegistry.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolPolicyResolver.php';

use DataMachine\Core\Steps\AI\ToolPolicy\PipelineToolPolicyArgs;
use DataMachine\Core\Steps\WorkflowConfigFactory;
use DataMachine\Engine\AI\Tools\ToolManager;
use DataMachine\Engine\AI\Tools\ToolPolicyResolver;

class SnapshotPolicyToolManager extends ToolManager {
	/** @var array<int, string|null> */
	public array $availability_contexts = array();

	public function get_all_tools(): array {
		return array(
			'alpha_tool'         => array( 'modes' => array( 'pipeline' ) ),
			'beta_tool'          => array( 'modes' => array( 'pipeline' ) ),
			'danger_tool'        => array( 'modes' => array( 'pipeline' ) ),
			'agent_daily_memory' => array( 'modes' => array( 'chat', ToolPolicyResolver::MODE_PIPELINE ), 'requires_opt_in' => true ),
			'agent_memory'       => array( 'modes' => array( 'chat', ToolPolicyResolver::MODE_PIPELINE ), 'requires_opt_in' => true ),
			'chat_only'          => array( 'modes' => array( 'chat' ) ),
		);
	}

	public function is_tool_available( string $tool_id, ?string $context_id = null ): bool {
		$this->availability_contexts[] = $context_id;
		return null === $context_id;
	}

	public function is_globally_enabled( string $tool_id ): bool {
		return true;
	}

	public function resolveHandlerTools( string $handler_slug, array $handler_config, array $engine_data, string $cache_scope = '' ): array {
		return array();
	}
}

$failures = array();
$passes   = 0;

function assert_policy_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

function resolve_policy_tools_for_test( array $flow_step_config, array $pipeline_step_config, SnapshotPolicyToolManager $manager ): array {
	$resolver = new ToolPolicyResolver( $manager );

	return $resolver->resolve(
		array_merge(
			array(
				'mode'                 => ToolPolicyResolver::MODE_PIPELINE,
				'agent_id'             => 0,
				'previous_step_config' => null,
				'next_step_config'     => null,
				'pipeline_step_id'     => (string) ( $flow_step_config['pipeline_step_id'] ?? '' ),
				'engine_data'          => array(),
				'categories'           => array(),
			),
			PipelineToolPolicyArgs::fromConfigs( $flow_step_config, $pipeline_step_config )
		)
	);
}

function resolve_policy_tools_with_evidence_for_test( array $flow_step_config, array $pipeline_step_config, SnapshotPolicyToolManager $manager, array $required_tool_names ): array {
	$resolver = new ToolPolicyResolver( $manager );

	return $resolver->resolveWithEvidence(
		array_merge(
			array(
				'mode'                 => ToolPolicyResolver::MODE_PIPELINE,
				'agent_id'             => 0,
				'previous_step_config' => null,
				'next_step_config'     => null,
				'pipeline_step_id'     => (string) ( $flow_step_config['pipeline_step_id'] ?? '' ),
				'engine_data'          => array(),
				'categories'           => array(),
			),
			PipelineToolPolicyArgs::fromConfigs( $flow_step_config, $pipeline_step_config )
		),
		$required_tool_names,
		is_array( $flow_step_config['enabled_tools'] ?? null ) ? $flow_step_config['enabled_tools'] : array()
	);
}

echo "pipeline-tool-policy-snapshot-smoke\n";

echo "\n[1] non-empty enabled_tools narrows pipeline tools:\n";
$manager = new SnapshotPolicyToolManager();
$tools   = resolve_policy_tools_for_test(
	array(
		'step_type'        => 'ai',
		'pipeline_step_id' => 'ephemeral_pipeline_0',
		'enabled_tools'    => array( 'alpha_tool' ),
	),
	array(),
	$manager
);
assert_policy_equals( array( 'alpha_tool' ), array_keys( $tools ), 'allowlist keeps only enabled tool', $failures, $passes );
assert_policy_equals( array(), $manager->availability_contexts, 'default pipeline tools avoid per-step availability context lookups', $failures, $passes );

$manager = new SnapshotPolicyToolManager();
$tools   = resolve_policy_tools_for_test(
	array(
		'step_type'        => 'ai',
		'pipeline_step_id' => 'ephemeral_pipeline_0',
		'enabled_tools'    => array( 'agent_daily_memory', 'agent_memory' ),
	),
	array(),
	$manager
);
assert_policy_equals( array( 'agent_daily_memory', 'agent_memory' ), array_keys( $tools ), 'allowlist can intentionally grant policy-controlled memory tools', $failures, $passes );
assert_policy_equals( array(), $manager->availability_contexts, 'opt-in memory tools avoid per-step availability context lookups', $failures, $passes );

echo "\n[2] ephemeral explicit-empty enabled_tools denies all optional tools:\n";
// An explicitly-empty enabled_tools means "no optional tools." The factory
// always writes the enabled_tools key for AI steps, so this is the explicit
// case: every optional preset tool is stripped (none here are mandatory
// plumbing). This is the footgun fix — previously these fell through to the
// full preset minus only the disabled_tools entry.
$ephemeral = WorkflowConfigFactory::buildEphemeralConfigs(
	array(
		'steps' => array(
			array(
				'step_type'      => 'ai',
				'enabled_tools'  => array(),
				'disabled_tools' => array( 'danger_tool' ),
			),
		),
	)
);
$flow_step_config     = $ephemeral['flow_config']['ephemeral_step_0'];
$pipeline_step_config = $ephemeral['pipeline_config']['ephemeral_pipeline_0'];
$manager              = new SnapshotPolicyToolManager();
$tools                = resolve_policy_tools_for_test( $flow_step_config, $pipeline_step_config, $manager );
assert_policy_equals( array(), array_keys( $tools ), 'explicit-empty enabled_tools strips every optional preset tool', $failures, $passes );
assert_policy_equals( array(), $manager->availability_contexts, 'ephemeral policy never re-reads by synthetic pipeline step ID', $failures, $passes );

echo "\n[3] persistent explicit-empty enabled_tools denies all optional tools:\n";
$workflow = array(
	'steps' => array(
		array(
			'step_type'      => 'ai',
			'enabled_tools'  => array(),
			'disabled_tools' => array( 'beta_tool' ),
		),
	),
);
$pipeline_config      = WorkflowConfigFactory::buildPersistentPipelineConfig( $workflow, 44 );
$flow_config          = WorkflowConfigFactory::buildPersistentFlowConfig( $workflow, 44, 55, $pipeline_config );
$pipeline_step_id     = array_key_first( $pipeline_config );
$flow_step_id         = array_key_first( $flow_config );
$manager              = new SnapshotPolicyToolManager();
$tools                = resolve_policy_tools_for_test( $flow_config[ $flow_step_id ], $pipeline_config[ $pipeline_step_id ], $manager );
assert_policy_equals( array(), array_keys( $tools ), 'persistent explicit-empty enabled_tools strips every optional preset tool', $failures, $passes );
assert_policy_equals( array(), $manager->availability_contexts, 'persistent policy does not re-read persisted step config', $failures, $passes );

echo "\n[3b] absent enabled_tools (legacy step) keeps the preset:\n";
// When the enabled_tools key was never configured (legacy, pre-field steps),
// the step must keep the context preset for back-compat — no allowlist.
$manager = new SnapshotPolicyToolManager();
$tools   = resolve_policy_tools_for_test(
	array(
		'step_type'        => 'ai',
		'pipeline_step_id' => 'ephemeral_pipeline_0',
		// no enabled_tools key at all.
	),
	array( 'disabled_tools' => array( 'danger_tool' ) ),
	$manager
);
assert_policy_equals( array( 'alpha_tool', 'beta_tool' ), array_keys( $tools ), 'absent enabled_tools keeps preset minus explicit deny', $failures, $passes );

echo "\n[4] RequestInspector and AIStep share policy input helper:\n";
$ai_step_source   = file_get_contents( __DIR__ . '/../inc/Core/Steps/AI/AIStep.php' ) ?: '';
$inspector_source = file_get_contents( __DIR__ . '/../inc/Engine/AI/RequestInspector.php' ) ?: '';
assert_policy_equals( 1, substr_count( $ai_step_source, 'PipelineToolPolicyArgs::fromConfigs' ), 'AIStep uses pipeline policy helper once', $failures, $passes );
assert_policy_equals( 1, substr_count( $inspector_source, 'PipelineToolPolicyArgs::fromConfigs' ), 'RequestInspector uses pipeline policy helper once', $failures, $passes );

echo "\n[5] helper translates flow/pipeline policy fields into resolver args:\n";
$args = PipelineToolPolicyArgs::fromConfigs(
	array(
		'step_type'      => 'ai',
		'enabled_tools'  => array( 'alpha_tool', '', 'alpha_tool', 42, 'beta_tool' ),
		'disabled_tools' => array( 'flow_denied', 'shared_denied', '', 'flow_denied' ),
	),
	array(
		'disabled_tools' => array( 'pipeline_denied', 'shared_denied', false, 'pipeline_denied' ),
	)
);
assert_policy_equals(
	array(
		'allow_only'          => array( 'alpha_tool', 'beta_tool' ),
		'allow_only_explicit' => true,
		'deny'                => array( 'pipeline_denied', 'shared_denied', 'flow_denied' ),
	),
	$args,
	'enabled_tools and disabled_tools map to allow_only and deny args with original ordering',
	$failures,
	$passes
);

echo "\n[6] explicit-empty enabled_tools emits an empty allow_only + explicit flag:\n";
$args = PipelineToolPolicyArgs::fromConfigs(
	array(
		'step_type'     => 'ai',
		'enabled_tools' => array(),
	),
	array()
);
assert_policy_equals(
	array(
		'allow_only'          => array(),
		'allow_only_explicit' => true,
	),
	$args,
	'empty explicit enabled_tools => empty allow_only flagged explicit (deny all optional)',
	$failures,
	$passes
);

echo "\n[7] absent enabled_tools (legacy) emits no allow_only constraint:\n";
$args = PipelineToolPolicyArgs::fromConfigs(
	array(
		'step_type' => 'ai',
		// no enabled_tools key.
	),
	array()
);
assert_policy_equals(
	array(),
	$args,
	'absent enabled_tools => no allow_only / allow_only_explicit (preset applies)',
	$failures,
	$passes
);

echo "\n[8] required-tool evidence reports policy-filtered unavailable tools:\n";
$resolution = resolve_policy_tools_with_evidence_for_test(
	array(
		'step_type'        => 'ai',
		'pipeline_step_id' => 'ephemeral_pipeline_0',
		'enabled_tools'    => array( 'beta_tool' ),
	),
	array(),
	new SnapshotPolicyToolManager(),
	array( 'alpha_tool' )
);
assert_policy_equals( array( 'beta_tool' ), array_keys( $resolution['tools'] ), 'evidence test allowlist resolves enabled tool only', $failures, $passes );
assert_policy_equals( array( 'beta_tool' ), $resolution['evidence']['requested_tool_names'] ?? null, 'evidence preserves requested tool names', $failures, $passes );
assert_policy_equals( array( 'alpha_tool' ), $resolution['evidence']['required_tool_names'] ?? null, 'evidence preserves required tool names', $failures, $passes );
assert_policy_equals( array( 'alpha_tool' ), $resolution['evidence']['unavailable_required_tool_names'] ?? null, 'evidence reports unavailable required tool names', $failures, $passes );
assert_policy_equals( 'policy_filtered', $resolution['evidence']['required_tool_resolution'][0]['reason'] ?? null, 'evidence categorizes gathered-but-filtered required tool', $failures, $passes );
assert_policy_equals( 'static_registry', $resolution['evidence']['required_tool_resolution'][0]['source'] ?? null, 'evidence identifies source for filtered required tool', $failures, $passes );

echo "\n[9] required-tool evidence reports resolved tools:\n";
$resolution = resolve_policy_tools_with_evidence_for_test(
	array(
		'step_type'        => 'ai',
		'pipeline_step_id' => 'ephemeral_pipeline_0',
		'enabled_tools'    => array( 'alpha_tool' ),
	),
	array(),
	new SnapshotPolicyToolManager(),
	array( 'alpha_tool' )
);
assert_policy_equals( array( 'alpha_tool' ), array_keys( $resolution['tools'] ), 'resolved evidence keeps required tool', $failures, $passes );
assert_policy_equals( array(), $resolution['evidence']['unavailable_required_tool_names'] ?? null, 'resolved evidence has no unavailable required tools', $failures, $passes );
assert_policy_equals( 'resolved', $resolution['evidence']['required_tool_resolution'][0]['status'] ?? null, 'resolved evidence marks required tool resolved', $failures, $passes );
assert_policy_equals( 'alpha_tool', $resolution['evidence']['required_tool_resolution'][0]['resolved_name'] ?? null, 'resolved evidence preserves resolved logical name', $failures, $passes );

echo "\n[10] required-tool evidence reuses captured source trace:\n";
$resolver_source = file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/ToolPolicyResolver.php' ) ?: '';
assert_policy_equals( 0, substr_count( $resolver_source, 'gatherWithMetadata(' ), 'evidence no longer manually replays metadata-only source gathering', $failures, $passes );
assert_policy_equals( true, str_contains( $resolver_source, '$args[\'source_trace\'] = $this->last_source_trace' ), 'evidence builder receives captured trace metadata', $failures, $passes );

echo "\n[11] host tool policy delegates control-plane tools from every source:\n";
$previous_policy_env = getenv( 'DATAMACHINE_HOST_TOOL_POLICY_JSON' );
putenv(
	'DATAMACHINE_HOST_TOOL_POLICY_JSON=' . json_encode(
		array(
			'schema'                     => 'datamachine/host-tool-policy/v1',
			'default_execution_location' => 'disabled',
			'tools'                      => array(
				'alpha_tool'                   => array( 'execution_location' => 'control_plane' ),
				'beta_tool'                    => array( 'execution_location' => 'runner' ),
				'client/control_plane_runtime' => array( 'execution_location' => 'control_plane' ),
				'client/runner_runtime'        => array( 'execution_location' => 'runner' ),
				'control_plane_ability'        => array( 'execution_location' => 'control_plane' ),
				'runner_ability'               => array( 'execution_location' => 'runner' ),
			),
		)
	)
);
$resolver   = new ToolPolicyResolver( new SnapshotPolicyToolManager() );
$resolution = $resolver->resolveWithEvidence(
	array(
		'mode'                      => ToolPolicyResolver::MODE_PIPELINE,
		'agent_id'                  => 0,
		'previous_step_config'      => null,
		'next_step_config'          => null,
		'pipeline_step_id'          => 'ephemeral_pipeline_0',
		'engine_data'               => array(),
		'categories'                => array(),
		'allow_only_explicit'       => true,
		'allow_only'                => array( 'alpha_tool', 'beta_tool', 'client/control_plane_runtime', 'client/runner_runtime', 'control_plane_ability', 'runner_ability' ),
		'runtime_tool_declarations' => array(
			'client/control_plane_runtime' => array( 'description' => 'Control-plane runtime tool', 'parameters' => array( 'type' => 'object', 'properties' => array() ), 'executor' => 'client', 'scope' => 'run' ),
			'client/runner_runtime'        => array( 'description' => 'Runner runtime tool', 'parameters' => array( 'type' => 'object', 'properties' => array() ), 'executor' => 'client', 'scope' => 'run' ),
		),
		'ability_tools'             => array(
			'control_plane_ability' => array( 'ability' => 'test/control-plane', 'modes' => array( ToolPolicyResolver::MODE_PIPELINE ) ),
			'runner_ability'        => array( 'ability' => 'test/runner', 'modes' => array( ToolPolicyResolver::MODE_PIPELINE ) ),
		),
	),
	array( 'alpha_tool', 'client/control_plane_runtime', 'control_plane_ability' ),
	array( 'alpha_tool', 'beta_tool', 'client/control_plane_runtime', 'client/runner_runtime', 'control_plane_ability', 'runner_ability' )
);
if ( false === $previous_policy_env ) {
	putenv( 'DATAMACHINE_HOST_TOOL_POLICY_JSON' );
} else {
	putenv( 'DATAMACHINE_HOST_TOOL_POLICY_JSON=' . $previous_policy_env );
}
assert_policy_equals( array( 'client/control_plane_runtime', 'client/runner_runtime', 'alpha_tool', 'beta_tool', 'control_plane_ability', 'runner_ability' ), array_keys( $resolution['tools'] ), 'host policy keeps runner tools and delegated control-plane tools across local sources', $failures, $passes );
assert_policy_equals( array(), $resolution['evidence']['unavailable_required_tool_names'] ?? null, 'host policy resolves control-plane required tools as delegated tools', $failures, $passes );
assert_policy_equals( 'resolved', $resolution['evidence']['required_tool_resolution'][0]['status'] ?? null, 'host policy reports delegated static tool as resolved', $failures, $passes );
assert_policy_equals( 'resolved', $resolution['evidence']['required_tool_resolution'][1]['status'] ?? null, 'host policy reports delegated runtime tool as resolved', $failures, $passes );
assert_policy_equals( 'resolved', $resolution['evidence']['required_tool_resolution'][2]['status'] ?? null, 'host policy reports delegated ability tool as resolved', $failures, $passes );
assert_policy_equals( 'client', $resolution['tools']['alpha_tool']['executor'] ?? null, 'control-plane static tool uses delegated client executor', $failures, $passes );
assert_policy_equals( 'control_plane', $resolution['tools']['alpha_tool']['runtime']['execution_location'] ?? null, 'control-plane static tool records execution location', $failures, $passes );
assert_policy_equals( 'client', $resolution['tools']['client/control_plane_runtime']['executor'] ?? null, 'control-plane runtime tool uses delegated client executor', $failures, $passes );
assert_policy_equals( 'client', $resolution['tools']['control_plane_ability']['executor'] ?? null, 'control-plane ability tool uses delegated client executor', $failures, $passes );
assert_policy_equals( array( 'client/control_plane_runtime', 'client/runner_runtime' ), $resolution['evidence']['available_tool_sources'][0]['accepted_tool_names'] ?? null, 'runtime source accepts delegated control-plane tool', $failures, $passes );
assert_policy_equals( array( 'alpha_tool', 'beta_tool' ), $resolution['evidence']['available_tool_sources'][2]['accepted_tool_names'] ?? null, 'static registry evidence accepts delegated control-plane tool', $failures, $passes );
assert_policy_equals( array( 'control_plane_ability', 'runner_ability' ), $resolution['evidence']['available_tool_sources'][3]['accepted_tool_names'] ?? null, 'ability source accepts delegated control-plane tool', $failures, $passes );

$completion_assertions = new \DataMachine\Engine\AI\DataMachineCompletionAssertions(
	array(
		'complete_when_any' => array(
			array(
				'name'  => 'control_plane_path',
				'tools' => array(
					array( 'name' => 'alpha_tool' ),
					array( 'name' => 'control_plane_ability' ),
				),
			),
		),
	)
);
assert_policy_equals( array(), $completion_assertions->unavailableRequiredToolNames( $resolution['tools'] ), 'complete_when_any treats delegated control-plane path as available', $failures, $passes );
assert_policy_equals( array( 'alpha_tool', 'control_plane_ability' ), $completion_assertions->unavailableRequiredToolNames( array() ), 'complete_when_any still reports missing tools without a delegated path', $failures, $passes );

echo "\n[12] host tool policy accepts default_location policy payloads:\n";
$resolution = ( new ToolPolicyResolver( new SnapshotPolicyToolManager() ) )->resolveWithEvidence(
	array(
		'mode'                => ToolPolicyResolver::MODE_PIPELINE,
		'pipeline_step_id'    => 'ephemeral_pipeline_0',
		'engine_data'         => array(),
		'categories'          => array(),
		'allow_only_explicit' => true,
		'allow_only'          => array( 'alpha_tool', 'beta_tool' ),
		'host_tool_policy'    => array(
			'schema'           => 'generic/host-tool-policy/v1',
			'default_location' => 'runner',
			'tools'            => array(
				'alpha_tool' => array( 'execution_location' => 'control_plane' ),
			),
		),
	),
	array( 'alpha_tool' ),
	array( 'alpha_tool', 'beta_tool' )
);
assert_policy_equals( 'client', $resolution['tools']['alpha_tool']['executor'] ?? null, 'default_location host policy delegates explicit control-plane tool', $failures, $passes );
assert_policy_equals( null, $resolution['tools']['beta_tool']['executor'] ?? null, 'default_location host policy leaves runner-default tool local', $failures, $passes );

echo "\n[13] host tool policy accepts wrapped runtime policy payloads:\n";
$wrapped_policy = array(
	'apply' => 'propose_only',
	'read'  => 'workspace',
	'tools' => array(
		'schema'           => 'generic/host-tool-policy/v1',
		'default_location' => 'runner',
		'tools'            => array(
			'alpha_tool' => array( 'execution_location' => 'control_plane' ),
		),
	),
	'write' => 'artifacts_only',
);
$resolution     = ( new ToolPolicyResolver( new SnapshotPolicyToolManager() ) )->resolve(
	array(
		'mode'                => ToolPolicyResolver::MODE_PIPELINE,
		'pipeline_step_id'    => 'ephemeral_pipeline_0',
		'engine_data'         => array(),
		'categories'          => array(),
		'allow_only_explicit' => true,
		'allow_only'          => array( 'alpha_tool', 'beta_tool' ),
		'host_tool_policy'    => $wrapped_policy,
	)
);
assert_policy_equals( 'client', $resolution['alpha_tool']['executor'] ?? null, 'wrapped policy delegates explicit control-plane tool', $failures, $passes );
assert_policy_equals( null, $resolution['beta_tool']['executor'] ?? null, 'wrapped policy leaves runner-default tool local', $failures, $passes );

echo "\n[14] host tool policy accepts neutral list-shaped sandbox policy payloads:\n";
$transport_policy = array(
	'schema'           => 'datamachine/sandbox-tool-policy/v1',
	'default_location' => 'runner',
	'tools'            => array(
		array(
			'name'               => 'alpha_tool',
			'execution_location' => 'control_plane',
		),
	),
);
$resolution       = ( new ToolPolicyResolver( new SnapshotPolicyToolManager() ) )->resolve(
	array(
		'mode'                => ToolPolicyResolver::MODE_PIPELINE,
		'pipeline_step_id'    => 'ephemeral_pipeline_0',
		'engine_data'         => array(),
		'categories'          => array(),
		'allow_only_explicit' => true,
		'allow_only'          => array( 'alpha_tool', 'beta_tool' ),
		'host_tool_policy'    => $transport_policy,
	)
);
assert_policy_equals( 'client', $resolution['alpha_tool']['executor'] ?? null, 'neutral sandbox policy delegates explicit control-plane tool', $failures, $passes );
assert_policy_equals( null, $resolution['beta_tool']['executor'] ?? null, 'neutral sandbox policy leaves runner-default tool local', $failures, $passes );

echo "\n[14b] host tool policy accepts generic list-shaped runtime policy payloads:\n";
$transport_policy = array(
	'schema'           => 'agents-api/runtime-tool-policy/v1',
	'default_location' => 'runner',
	'tools'            => array(
		array(
			'name'               => 'alpha_tool',
			'execution_location' => 'control_plane',
		),
	),
);
$resolution       = ( new ToolPolicyResolver( new SnapshotPolicyToolManager() ) )->resolve(
	array(
		'mode'                => ToolPolicyResolver::MODE_PIPELINE,
		'pipeline_step_id'    => 'ephemeral_pipeline_0',
		'engine_data'         => array(),
		'categories'          => array(),
		'allow_only_explicit' => true,
		'allow_only'          => array( 'alpha_tool', 'beta_tool' ),
		'host_tool_policy'    => $transport_policy,
	)
);
assert_policy_equals( 'client', $resolution['alpha_tool']['executor'] ?? null, 'generic runtime policy delegates explicit control-plane tool', $failures, $passes );
assert_policy_equals( null, $resolution['beta_tool']['executor'] ?? null, 'generic runtime policy leaves runner-default tool local', $failures, $passes );

echo "\n[15] host tool policy accepts neutral host policy payloads:\n";
$host_policy = array(
	'schema'           => 'datamachine/host-tool-policy/v1',
	'default_location' => 'runner',
	'tools'            => array(
		'alpha_tool' => array( 'execution_location' => 'control_plane' ),
	),
);
$resolution  = ( new ToolPolicyResolver( new SnapshotPolicyToolManager() ) )->resolve(
	array(
		'mode'                => ToolPolicyResolver::MODE_PIPELINE,
		'pipeline_step_id'    => 'ephemeral_pipeline_0',
		'engine_data'         => array(),
		'categories'          => array(),
		'allow_only_explicit' => true,
		'allow_only'          => array( 'alpha_tool', 'beta_tool' ),
		'host_tool_policy'    => $host_policy,
	)
);
assert_policy_equals( 'client', $resolution['alpha_tool']['executor'] ?? null, 'neutral host policy delegates explicit control-plane tool', $failures, $passes );
assert_policy_equals( null, $resolution['beta_tool']['executor'] ?? null, 'neutral host policy leaves runner-default tool local', $failures, $passes );

echo "\n[16] host tool policy accepts filter-registered list-shaped transport payloads:\n";
$transport_schema_filter = static function ( array $schemas ): array {
	$schemas[] = 'vendor/tool-policy/v1';
	return $schemas;
};
add_filter( 'datamachine_host_tool_policy_transport_schemas', $transport_schema_filter );
$transport_policy = array(
	'schema' => 'vendor/tool-policy/v1',
	'tools'  => array(
		array(
			'id'                 => 'alpha_tool',
			'execution_location' => 'control_plane',
		),
	),
);
$resolution       = ( new ToolPolicyResolver( new SnapshotPolicyToolManager() ) )->resolve(
	array(
		'mode'                => ToolPolicyResolver::MODE_PIPELINE,
		'pipeline_step_id'    => 'ephemeral_pipeline_0',
		'engine_data'         => array(),
		'categories'          => array(),
		'allow_only_explicit' => true,
		'allow_only'          => array( 'alpha_tool', 'beta_tool' ),
		'host_tool_policy'    => $transport_policy,
	)
);
remove_filter( 'datamachine_host_tool_policy_transport_schemas', $transport_schema_filter );
assert_policy_equals( 'client', $resolution['alpha_tool']['executor'] ?? null, 'filter-registered transport policy delegates explicit control-plane tool', $failures, $passes );
assert_policy_equals( null, $resolution['beta_tool']['executor'] ?? null, 'filter-registered transport policy does not affect unrelated tools', $failures, $passes );

echo "\n[17] host tool policy ignores unrecognized list-shaped transport payloads:\n";
$transport_policy = array(
	'schema' => 'vendor/tool-policy/v1',
	'tools'  => array(
		array(
			'id'                 => 'alpha_tool',
			'execution_location' => 'control_plane',
		),
	),
);
$resolution       = ( new ToolPolicyResolver( new SnapshotPolicyToolManager() ) )->resolve(
	array(
		'mode'                => ToolPolicyResolver::MODE_PIPELINE,
		'pipeline_step_id'    => 'ephemeral_pipeline_0',
		'engine_data'         => array(),
		'categories'          => array(),
		'allow_only_explicit' => true,
		'allow_only'          => array( 'alpha_tool', 'beta_tool' ),
		'host_tool_policy'    => $transport_policy,
	)
);
assert_policy_equals( null, $resolution['alpha_tool']['executor'] ?? null, 'list-shaped transport policy is not converted into host policy', $failures, $passes );
assert_policy_equals( null, $resolution['beta_tool']['executor'] ?? null, 'list-shaped transport policy does not affect unrelated tools', $failures, $passes );

echo "\n[18] production host policy code has no Codebox sandbox schema special-case:\n";
$host_policy_source = file_get_contents( __DIR__ . '/../inc/Engine/AI/Tools/HostToolPolicy.php' ) ?: '';
assert_policy_equals( false, str_contains( $host_policy_source, 'wp-codebox/sandbox-tool-policy/v1' ), 'HostToolPolicy does not name the Codebox sandbox schema', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " pipeline policy assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} pipeline policy assertions passed.\n";
}
