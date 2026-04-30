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

namespace {

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
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

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfigFactory.php';
require_once __DIR__ . '/../inc/Core/Steps/WorkflowConfigFactory.php';
require_once __DIR__ . '/../inc/Core/Steps/AI/ToolPolicy/PipelineToolPolicyArgs.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/ToolManager.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineAgentToolPolicyProvider.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Policy/DataMachineMandatoryToolPolicy.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/DataMachineToolRegistrySource.php';
require_once __DIR__ . '/../inc/Engine/AI/Tools/Sources/AdjacentHandlerToolSource.php';
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
			'alpha_tool'  => array( 'modes' => array( 'pipeline' ) ),
			'beta_tool'   => array( 'modes' => array( 'pipeline' ) ),
			'danger_tool' => array( 'modes' => array( 'pipeline' ) ),
			'chat_only'   => array( 'modes' => array( 'chat' ) ),
		);
	}

	public function is_tool_available( string $tool_id, ?string $context_id = null ): bool {
		$this->availability_contexts[] = $context_id;
		return null === $context_id;
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
assert_policy_equals( array( null, null, null ), $manager->availability_contexts, 'pipeline availability does not pass context IDs', $failures, $passes );

echo "\n[2] ephemeral workflow disabled_tools deny from snapshot:\n";
$ephemeral = WorkflowConfigFactory::buildEphemeralConfigs(
	array(
		'steps' => array(
			array(
				'type'           => 'ai',
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
assert_policy_equals( array( 'alpha_tool', 'beta_tool' ), array_keys( $tools ), 'ephemeral disabled_tools removes denied tool without DB row', $failures, $passes );
assert_policy_equals( array( null, null, null ), $manager->availability_contexts, 'ephemeral policy never re-reads by synthetic pipeline step ID', $failures, $passes );

echo "\n[3] persistent workflow disabled_tools still apply from snapshot:\n";
$workflow = array(
	'steps' => array(
		array(
			'type'           => 'ai',
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
assert_policy_equals( array( 'alpha_tool', 'danger_tool' ), array_keys( $tools ), 'persistent disabled_tools removes denied tool', $failures, $passes );
assert_policy_equals( array( null, null, null ), $manager->availability_contexts, 'persistent policy does not re-read persisted step config', $failures, $passes );

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
		'allow_only' => array( 'alpha_tool', 'beta_tool' ),
		'deny'       => array( 'pipeline_denied', 'shared_denied', 'flow_denied' ),
	),
	$args,
	'enabled_tools and disabled_tools map to allow_only and deny args with original ordering',
	$failures,
	$passes
);

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " pipeline policy assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} pipeline policy assertions passed.\n";
}
