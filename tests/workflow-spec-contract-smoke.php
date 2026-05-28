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
	'associative steps' => array( 'steps' => array( 'first' => array( 'step_type' => 'fetch' ) ) ),
	'scalar step'       => array( 'steps' => array( 'fetch' ) ),
	'missing step_type' => array( 'steps' => array( array( 'handler_slug' => 'rss' ) ) ),
	'legacy handler alias field' => array( 'steps' => array( array( 'step_type' => 'fetch', 'handler' => 'rss' ) ) ),
	'legacy handler field' => array( 'steps' => array( array( 'step_type' => 'fetch', 'handler_slug' => 'rss' ) ) ),
	'legacy config field'  => array( 'steps' => array( array( 'step_type' => 'fetch', 'handler_config' => array( 'url' => 'https://example.com' ) ) ) ),
	'non-string step_type' => array( 'steps' => array( array( 'step_type' => 123 ) ) ),
	'unknown step_type' => array( 'steps' => array( array( 'step_type' => 'time_travel' ) ) ),
	'type alias instead of step_type' => array( 'steps' => array( array( 'type' => 'fetch' ) ) ),
);

foreach ( $invalid_cases as $case => $workflow ) {
	$execute_result = workflow_execute_edge_for_test( $workflow );
	$pipeline_error = $pipeline_harness->validateForTest( $workflow );

	assert_workflow_spec_equals( false, $execute_result['success'], "execute-workflow rejects {$case}", $failures, $passes );
	assert_workflow_spec_equals( $execute_result['error'], $pipeline_error, "create-pipeline shares {$case} validation error", $failures, $passes );
}

$valid_workflow = array(
	'steps' => array(
		array( 'step_type' => 'fetch' ),
		array( 'step_type' => 'ai' ),
		array( 'step_type' => 'system_task', 'flow_step_settings' => array( 'task_type' => 'daily_memory_generation' ) ),
	),
);

assert_workflow_spec_equals( array( 'valid' => true ), WorkflowSpecValidator::validate( $valid_workflow ), 'shared validator accepts valid structural workflow', $failures, $passes );
assert_workflow_spec_equals( true, $pipeline_harness->validateForTest( $valid_workflow ), 'create-pipeline adapter accepts valid workflow', $failures, $passes );
assert_workflow_spec_equals( array( 'success' => true ), workflow_execute_edge_for_test( $valid_workflow ), 'execute-workflow adapter accepts valid workflow', $failures, $passes );

$configs         = WorkflowConfigFactory::buildEphemeralConfigs(
	array(
		'steps' => array(
			array(
				'step_type'       => 'fetch',
				'label'           => 'Fetch Source',
				'handler_slugs'   => array( 'mcp' ),
				'handler_configs' => array( 'mcp' => array( 'server' => 'a8c' ) ),
			),
			array(
				'step_type'      => 'ai',
				'label'          => 'Summarize',
				'agent_modes'    => array( 'rl_task' ),
				'system_prompt'  => 'Be concise.',
				'system_prompt_queue' => array(
					array( 'prompt' => 'Variant A', 'added_at' => '2026-05-22T00:00:00Z' ),
				),
				'system_prompt_queue_mode' => 'loop',
				'disabled_tools' => array( 'danger_tool' ),
				'completion_assertions' => array(
					'required_tool_names' => array( 'publish_result' ),
				),
				'tool_runtime_rules' => array(
					array(
						'id'        => 'after-worktree',
						'max_calls' => 4,
					),
				),
			),
			array(
				'step_type'      => 'system_task',
				'label'          => 'Cleanup',
				'flow_step_settings' => array( 'task_type' => 'retention_logs' ),
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
assert_workflow_spec_equals( array( 'rl_task' ), $pipeline_steps[1]['agent_modes'] ?? null, 'ephemeral pipeline_config preserves AI agent modes', $failures, $passes );
assert_workflow_spec_equals( 'Be concise.', $pipeline_steps[1]['system_prompt'] ?? null, 'ephemeral pipeline_config preserves AI system prompt', $failures, $passes );
assert_workflow_spec_equals( array( array( 'prompt' => 'Variant A', 'added_at' => '2026-05-22T00:00:00Z' ) ), $pipeline_steps[1]['system_prompt_queue'] ?? null, 'ephemeral pipeline_config preserves AI system prompt queue', $failures, $passes );
assert_workflow_spec_equals( 'loop', $pipeline_steps[1]['system_prompt_queue_mode'] ?? null, 'ephemeral pipeline_config preserves AI system prompt queue mode', $failures, $passes );
assert_workflow_spec_equals( array( 'danger_tool' ), $pipeline_steps[1]['disabled_tools'] ?? null, 'ephemeral pipeline_config preserves AI disabled tools', $failures, $passes );
assert_workflow_spec_equals( array( 'required_tool_names' => array( 'publish_result' ) ), $pipeline_steps[1]['completion_assertions'] ?? null, 'ephemeral pipeline_config preserves AI completion assertions', $failures, $passes );
assert_workflow_spec_equals( array( array( 'id' => 'after-worktree', 'max_calls' => 4 ) ), $pipeline_steps[1]['tool_runtime_rules'] ?? null, 'ephemeral pipeline_config preserves AI tool runtime rules', $failures, $passes );
assert_workflow_spec_equals( 'Cleanup', $pipeline_steps[2]['label'] ?? null, 'ephemeral pipeline_config preserves system_task label', $failures, $passes );
assert_workflow_spec_equals( array( 'task_type' => 'retention_logs' ), $pipeline_steps[2]['flow_step_settings'] ?? null, 'ephemeral pipeline_config preserves system_task settings', $failures, $passes );
assert_workflow_spec_equals( false, array_key_exists( 'system_prompt', $pipeline_steps[2] ), 'non-AI ephemeral pipeline rows do not gain AI metadata', $failures, $passes );
assert_workflow_spec_equals( false, array_key_exists( 'handler', $pipeline_steps[0] ), 'ephemeral pipeline rows do not emit scalar handler alias', $failures, $passes );
assert_workflow_spec_equals( false, array_key_exists( 'handler_slug', $pipeline_steps[0] ), 'ephemeral pipeline rows do not emit scalar handler_slug', $failures, $passes );
assert_workflow_spec_equals( false, array_key_exists( 'handler_config', $pipeline_steps[0] ), 'ephemeral pipeline rows do not emit scalar handler_config', $failures, $passes );

$execute_source  = file_get_contents( __DIR__ . '/../inc/Abilities/Job/ExecuteWorkflowAbility.php' ) ?: '';
$pipeline_source = file_get_contents( __DIR__ . '/../inc/Abilities/Pipeline/PipelineHelpers.php' ) ?: '';
$system_task_source = file_get_contents( __DIR__ . '/../inc/Core/Steps/SystemTask/SystemTaskStep.php' ) ?: '';

assert_workflow_spec_equals( 1, substr_count( $execute_source, 'WorkflowSpecValidator::validate' ), 'execute-workflow calls shared validator once', $failures, $passes );
assert_workflow_spec_equals( 1, substr_count( $pipeline_source, 'WorkflowSpecValidator::validate' ), 'create-pipeline helper calls shared validator once', $failures, $passes );
assert_workflow_spec_equals( false, str_contains( $system_task_source, "\$task_type = \$settings['task']" ), 'system_task execution does not resolve task type from legacy task field', $failures, $passes );
assert_workflow_spec_equals( true, str_contains( $system_task_source, 'system_task_legacy_task_field' ), 'system_task execution rejects legacy task field explicitly', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " workflow spec assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} workflow spec assertions passed.\n";
}
