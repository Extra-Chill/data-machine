<?php
/**
 * Pure-PHP smoke test for workflow spec validation and ephemeral config shape.
 *
 * Run with: php tests/workflow-spec-contract-smoke.php
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

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return 1;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		// no-op for tests.
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		if ( 'datamachine_step_types' !== $hook ) {
			return $value;
		}

		return array(
			'ai'           => array( 'uses_handler' => false, 'multi_handler' => false ),
			'fetch'        => array( 'uses_handler' => true, 'multi_handler' => false ),
			'publish'      => array( 'uses_handler' => true, 'multi_handler' => true ),
			'system_task'  => array( 'uses_handler' => false, 'multi_handler' => false ),
			'webhook_gate' => array( 'uses_handler' => false, 'multi_handler' => false ),
		);
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		// no-op for tests.
	}
}

require_once __DIR__ . '/../inc/Abilities/StepTypeAbilities.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfig.php';
require_once __DIR__ . '/../inc/Core/Steps/FlowStepConfigFactory.php';
require_once __DIR__ . '/../inc/Core/Steps/WorkflowSpecValidator.php';
require_once __DIR__ . '/../inc/Core/Steps/WorkflowConfigFactory.php';
require_once __DIR__ . '/../inc/Abilities/Pipeline/PipelineHelpers.php';

use DataMachine\Abilities\Pipeline\PipelineHelpers;
use DataMachine\Core\Steps\WorkflowConfigFactory;
use DataMachine\Core\Steps\WorkflowSpecValidator;

class WorkflowSpecPipelineHarness {
	use PipelineHelpers;

	public function validateForTest( array $workflow ): bool|string {
		return $this->validateWorkflow( $workflow );
	}
}

function workflow_execute_edge_for_test( $workflow ): array {
	$validation = WorkflowSpecValidator::validate( $workflow );
	if ( ! $validation['valid'] ) {
		return array(
			'success' => false,
			'error'   => $validation['error'] ?? 'Workflow validation failed',
		);
	}

	return array( 'success' => true );
}

$failures = array();
$passes   = 0;

function assert_workflow_spec_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

echo "workflow-spec-contract-smoke\n";

$pipeline_harness = new WorkflowSpecPipelineHarness();
$invalid_cases    = array(
	'missing steps'     => array(),
	'empty steps'       => array( 'steps' => array() ),
	'associative steps' => array( 'steps' => array( 'first' => array( 'type' => 'fetch' ) ) ),
	'scalar step'       => array( 'steps' => array( 'fetch' ) ),
	'missing type'      => array( 'steps' => array( array( 'handler_slug' => 'rss' ) ) ),
	'non-string type'   => array( 'steps' => array( array( 'type' => 123 ) ) ),
	'unknown type'      => array( 'steps' => array( array( 'type' => 'time_travel' ) ) ),
);

foreach ( $invalid_cases as $case => $workflow ) {
	$execute_result = workflow_execute_edge_for_test( $workflow );
	$pipeline_error = $pipeline_harness->validateForTest( $workflow );

	assert_workflow_spec_equals( false, $execute_result['success'], "execute-workflow rejects {$case}", $failures, $passes );
	assert_workflow_spec_equals( $execute_result['error'], $pipeline_error, "create-pipeline shares {$case} validation error", $failures, $passes );
}

$valid_workflow = array(
	'steps' => array(
		array( 'type' => 'fetch' ),
		array( 'type' => 'ai' ),
		array( 'type' => 'system_task', 'handler_config' => array( 'task' => 'daily_memory_generation' ) ),
	),
);

assert_workflow_spec_equals( array( 'valid' => true ), WorkflowSpecValidator::validate( $valid_workflow ), 'shared validator accepts valid structural workflow', $failures, $passes );
assert_workflow_spec_equals( true, $pipeline_harness->validateForTest( $valid_workflow ), 'create-pipeline adapter accepts valid workflow', $failures, $passes );
assert_workflow_spec_equals( array( 'success' => true ), workflow_execute_edge_for_test( $valid_workflow ), 'execute-workflow adapter accepts valid workflow', $failures, $passes );

$configs         = WorkflowConfigFactory::buildEphemeralConfigs(
	array(
		'steps' => array(
			array(
				'type'           => 'fetch',
				'label'          => 'Fetch Source',
				'handler_slug'   => 'mcp',
				'handler_config' => array( 'server' => 'a8c' ),
			),
			array(
				'type'           => 'ai',
				'label'          => 'Summarize',
				'system_prompt'  => 'Be concise.',
				'disabled_tools' => array( 'danger_tool' ),
			),
			array(
				'type'           => 'system_task',
				'label'          => 'Cleanup',
				'handler_config' => array( 'task' => 'retention_logs' ),
			),
		),
	)
);
$pipeline_config = $configs['pipeline_config'];
$pipeline_steps  = array_values( $pipeline_config );

assert_workflow_spec_equals( array( 'ephemeral_pipeline_0', 'ephemeral_pipeline_1', 'ephemeral_pipeline_2' ), array_keys( $pipeline_config ), 'ephemeral pipeline_config has one row per workflow step', $failures, $passes );
assert_workflow_spec_equals( array( 'fetch', 'ai', 'system_task' ), array_column( $pipeline_steps, 'step_type' ), 'ephemeral pipeline_config preserves step types', $failures, $passes );
assert_workflow_spec_equals( array( 0, 1, 2 ), array_column( $pipeline_steps, 'execution_order' ), 'ephemeral pipeline_config preserves execution order', $failures, $passes );
assert_workflow_spec_equals( 'Fetch Source', $pipeline_steps[0]['label'] ?? null, 'ephemeral pipeline_config preserves fetch label', $failures, $passes );
assert_workflow_spec_equals( 'Be concise.', $pipeline_steps[1]['system_prompt'] ?? null, 'ephemeral pipeline_config preserves AI system prompt', $failures, $passes );
assert_workflow_spec_equals( array( 'danger_tool' ), $pipeline_steps[1]['disabled_tools'] ?? null, 'ephemeral pipeline_config preserves AI disabled tools', $failures, $passes );
assert_workflow_spec_equals( 'Cleanup', $pipeline_steps[2]['label'] ?? null, 'ephemeral pipeline_config preserves system_task label', $failures, $passes );
assert_workflow_spec_equals( false, array_key_exists( 'system_prompt', $pipeline_steps[2] ), 'non-AI ephemeral pipeline rows do not gain AI metadata', $failures, $passes );

$execute_source  = file_get_contents( __DIR__ . '/../inc/Abilities/Job/ExecuteWorkflowAbility.php' ) ?: '';
$pipeline_source = file_get_contents( __DIR__ . '/../inc/Abilities/Pipeline/PipelineHelpers.php' ) ?: '';

assert_workflow_spec_equals( 1, substr_count( $execute_source, 'WorkflowSpecValidator::validate' ), 'execute-workflow calls shared validator once', $failures, $passes );
assert_workflow_spec_equals( 1, substr_count( $pipeline_source, 'WorkflowSpecValidator::validate' ), 'create-pipeline helper calls shared validator once', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " workflow spec assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} workflow spec assertions passed.\n";
}
