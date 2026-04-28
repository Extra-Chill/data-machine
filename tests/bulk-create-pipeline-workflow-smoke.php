<?php
/**
 * Pure-PHP smoke test for bulk create-pipeline workflow spec resolution.
 *
 * Run with: php tests/bulk-create-pipeline-workflow-smoke.php
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
			);
		}

		if ( 'datamachine_generate_flow_step_id' === $hook ) {
			return $args[0] . '_' . $args[1];
		}

		return $value;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		static $i = 0;
		++$i;
		return 'bulk-uuid-' . $i;
	}
}

require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfigFactory.php';
require_once __DIR__ . '/../inc/Core/Steps/WorkflowConfigFactory.php';
require_once __DIR__ . '/../inc/Abilities/Pipeline/PipelineHelpers.php';
require_once __DIR__ . '/../inc/Abilities/Pipeline/CreatePipelineAbility.php';

use DataMachine\Abilities\Pipeline\CreatePipelineAbility;
use DataMachine\Core\Steps\WorkflowConfigFactory;

$failures = array();
$passes   = 0;

function assert_bulk_workflow_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

function resolve_bulk_pipeline_spec_for_test( array $pipeline_config, array $template_workflow, array $template_steps ): array {
	$reflection = new \ReflectionClass( CreatePipelineAbility::class );
	$ability    = $reflection->newInstanceWithoutConstructor();
	$method     = $reflection->getMethod( 'resolveBulkPipelineSpec' );

	return $method->invoke( $ability, $pipeline_config, $template_workflow, $template_steps );
}

echo "bulk-create-pipeline-workflow-smoke\n";

$workflow = array(
	'steps' => array(
		array(
			'type'               => 'fetch',
			'label'              => 'Fetch Context',
			'handler_slug'       => 'mcp',
			'handler_config'     => array( 'provider' => 'github', 'query' => 'repo:Extra-Chill/data-machine' ),
			'config_patch_queue' => array( array( 'patch' => array( 'query' => 'workflow spec' ), 'added_at' => '2026-04-27T00:00:00Z' ) ),
			'queue_mode'         => 'drain',
		),
		array(
			'type'           => 'ai',
			'label'          => 'Summarize Context',
			'system_prompt'  => 'Summarize the fetched context.',
			'user_message'   => 'Use the latest queue item.',
			'enabled_tools'  => array( 'datamachine/read-github-file' ),
			'disabled_tools' => array( 'datamachine/delete-pipeline' ),
		),
	),
);

$legacy_steps = array(
	array( 'step_type' => 'fetch', 'label' => 'Legacy Fetch' ),
);

$resolved = resolve_bulk_pipeline_spec_for_test(
	array(
		'name'     => 'Workflow Wins',
		'workflow' => $workflow,
		'steps'    => array( array( 'step_type' => 'ai', 'label' => 'Ignored Legacy AI' ) ),
	),
	array(),
	array()
);

assert_bulk_workflow_equals( $workflow, $resolved['workflow'], 'per-pipeline workflow wins over per-pipeline steps', $failures, $passes );
assert_bulk_workflow_equals( array(), $resolved['steps'], 'per-pipeline workflow suppresses legacy steps', $failures, $passes );

$pipeline_config = WorkflowConfigFactory::buildPersistentPipelineConfig( $resolved['workflow'], 501 );
$flow_config     = WorkflowConfigFactory::buildPersistentFlowConfig( $resolved['workflow'], 501, 601, $pipeline_config );
$flow_steps      = array_values( $flow_config );

assert_bulk_workflow_equals( 2, count( $pipeline_config ), 'bulk workflow creates persistent pipeline rows', $failures, $passes );
assert_bulk_workflow_equals( 2, count( $flow_config ), 'bulk workflow creates persistent flow rows through shared factory', $failures, $passes );
assert_bulk_workflow_equals( 'mcp', $flow_steps[0]['handler_slug'] ?? null, 'bulk workflow preserves handler slug', $failures, $passes );
assert_bulk_workflow_equals( array( 'provider' => 'github', 'query' => 'repo:Extra-Chill/data-machine' ), $flow_steps[0]['handler_config'] ?? null, 'bulk workflow preserves handler config', $failures, $passes );
assert_bulk_workflow_equals( array( 'query' => 'workflow spec' ), $flow_steps[0]['config_patch_queue'][0]['patch'] ?? null, 'bulk workflow preserves config patch queue', $failures, $passes );
assert_bulk_workflow_equals( 'drain', $flow_steps[0]['queue_mode'] ?? null, 'bulk workflow preserves queue mode', $failures, $passes );
assert_bulk_workflow_equals( array( 'datamachine/read-github-file' ), $flow_steps[1]['enabled_tools'] ?? null, 'bulk workflow preserves enabled tools', $failures, $passes );
assert_bulk_workflow_equals( array( 'datamachine/delete-pipeline' ), $flow_steps[1]['disabled_tools'] ?? null, 'bulk workflow preserves disabled tools', $failures, $passes );
assert_bulk_workflow_equals( 'Use the latest queue item.', $flow_steps[1]['prompt_queue'][0]['prompt'] ?? null, 'bulk workflow converts AI user_message to prompt queue head', $failures, $passes );

$template_workflow = array(
	'steps' => array(
		array(
			'type'          => 'publish',
			'label'         => 'Template Publish',
			'handler_slug'  => 'wordpress',
			'handler_config' => array( 'post_type' => 'wiki' ),
		),
	),
);

foreach ( array( 'Template A', 'Template B' ) as $name ) {
	$resolved = resolve_bulk_pipeline_spec_for_test(
		array( 'name' => $name ),
		$template_workflow,
		$legacy_steps
	);

	assert_bulk_workflow_equals( $template_workflow, $resolved['workflow'], "template workflow applies to {$name}", $failures, $passes );
	assert_bulk_workflow_equals( array(), $resolved['steps'], "template workflow wins over template steps for {$name}", $failures, $passes );
}

$resolved = resolve_bulk_pipeline_spec_for_test(
	array(
		'name'  => 'Legacy Override',
		'steps' => $legacy_steps,
	),
	$template_workflow,
	array( array( 'step_type' => 'ai', 'label' => 'Ignored Template AI' ) )
);

assert_bulk_workflow_equals( array(), $resolved['workflow'], 'per-pipeline legacy steps still override template workflow', $failures, $passes );
assert_bulk_workflow_equals( $legacy_steps, $resolved['steps'], 'existing legacy bulk steps behavior remains available', $failures, $passes );

$source = file_get_contents( __DIR__ . '/../inc/Abilities/Pipeline/CreatePipelineAbility.php' ) ?: '';
assert_bulk_workflow_equals( true, false !== strpos( $source, '$single_input[\'workflow\'] = $entry[\'workflow\'];' ), 'bulk execution forwards resolved workflow to single-mode creator', $failures, $passes );
assert_bulk_workflow_equals( true, false !== strpos( $source, '$single_input[\'steps\'] = $entry[\'steps\'];' ), 'bulk execution still forwards legacy steps to single-mode creator', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " bulk workflow assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} bulk workflow assertions passed.\n";
}
