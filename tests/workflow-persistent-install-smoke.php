<?php
/**
 * Pure-PHP smoke test for workflow scaffold -> persistent pipeline/flow mapping.
 *
 * Run with: php tests/workflow-persistent-install-smoke.php
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

		if ( 'datamachine_generate_flow_step_id' === $hook ) {
			return $args[0] . '_' . $args[1];
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		// no-op for tests.
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

use DataMachine\Core\Steps\WorkflowConfigFactory;

$failures = array();
$passes   = 0;

function assert_workflow_equals( $expected, $actual, string $name, array &$failures, int &$passes ): void {
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

echo "workflow-persistent-install-smoke\n";

$workflow = array(
	'steps' => array(
		array(
			'type'               => 'fetch',
			'label'              => 'Webhook Payload',
			'handler_slug'       => 'webhook_payload',
			'handler_config'     => array( 'payload_path' => 'pull_request' ),
			'config_patch_queue' => array( array( 'after' => '2026-04-01' ) ),
			'queue_mode'         => 'drain',
		),
		array(
			'type'           => 'ai',
			'label'          => 'Review Pull Request',
			'system_prompt'  => 'Review the PR.',
			'user_message'   => 'Summarize findings.',
			'enabled_tools'  => array( 'datamachine/get-github-pull-review-context', 'datamachine/upsert-github-pull-review-comment' ),
			'disabled_tools' => array( 'datamachine/delete-flow' ),
		),
		array(
			'type'          => 'ai',
			'label'         => 'Rotating Prompt',
			'prompt_queue'  => array(
				array(
					'prompt'   => 'Prompt A',
					'added_at' => '2026-04-27T00:00:00Z',
				),
			),
			'queue_mode'    => 'loop',
			'enabled_tools' => array( 'datamachine/read-github-file' ),
		),
	),
);

$pipeline_config = WorkflowConfigFactory::buildPersistentPipelineConfig( $workflow, 88 );
$pipeline_steps  = array_values( $pipeline_config );

assert_workflow_equals( 3, count( $pipeline_config ), 'persistent pipeline contains every workflow step', $failures, $passes );
assert_workflow_equals( array( 'fetch', 'ai', 'ai' ), array_column( $pipeline_steps, 'step_type' ), 'pipeline preserves workflow step order', $failures, $passes );
assert_workflow_equals( array( 0, 1, 2 ), array_column( $pipeline_steps, 'execution_order' ), 'pipeline stores contiguous execution order', $failures, $passes );
assert_workflow_equals( 'Webhook Payload', $pipeline_steps[0]['label'] ?? null, 'pipeline preserves fetch label', $failures, $passes );
assert_workflow_equals( 'Review Pull Request', $pipeline_steps[1]['label'] ?? null, 'pipeline preserves AI label', $failures, $passes );
assert_workflow_equals( 'Review the PR.', $pipeline_steps[1]['system_prompt'] ?? null, 'pipeline preserves AI system prompt', $failures, $passes );
assert_workflow_equals( array( 'datamachine/delete-flow' ), $pipeline_steps[1]['disabled_tools'] ?? null, 'pipeline preserves AI disabled tools', $failures, $passes );
assert_workflow_equals( false, array_key_exists( 'handler_slug', $pipeline_steps[0] ), 'pipeline rows stay handler-free', $failures, $passes );

$flow_config = WorkflowConfigFactory::buildPersistentFlowConfig( $workflow, 88, 144, $pipeline_config );
$flow_steps  = array_values( $flow_config );

assert_workflow_equals( 3, count( $flow_config ), 'persistent flow contains every workflow step', $failures, $passes );
assert_workflow_equals( array( 'fetch', 'ai', 'ai' ), array_column( $flow_steps, 'step_type' ), 'flow preserves workflow step order', $failures, $passes );
assert_workflow_equals( '88_uuid-1_144', $flow_steps[0]['flow_step_id'] ?? null, 'flow step id maps pipeline step id to flow id', $failures, $passes );
assert_workflow_equals( 88, $flow_steps[0]['pipeline_id'] ?? null, 'flow stores target pipeline id', $failures, $passes );
assert_workflow_equals( 144, $flow_steps[0]['flow_id'] ?? null, 'flow stores target flow id', $failures, $passes );
assert_workflow_equals( 'webhook_payload', $flow_steps[0]['handler_slug'] ?? null, 'flow preserves fetch handler slug', $failures, $passes );
assert_workflow_equals( array( 'payload_path' => 'pull_request' ), $flow_steps[0]['handler_config'] ?? null, 'flow preserves fetch handler config', $failures, $passes );
assert_workflow_equals( array( array( 'after' => '2026-04-01' ) ), $flow_steps[0]['config_patch_queue'] ?? null, 'flow preserves fetch config patch queue', $failures, $passes );
assert_workflow_equals( 'drain', $flow_steps[0]['queue_mode'] ?? null, 'flow preserves explicit fetch queue mode', $failures, $passes );
assert_workflow_equals( array( 'datamachine/get-github-pull-review-context', 'datamachine/upsert-github-pull-review-comment' ), $flow_steps[1]['enabled_tools'] ?? null, 'flow preserves AI enabled tools', $failures, $passes );
assert_workflow_equals( 'Summarize findings.', $flow_steps[1]['prompt_queue'][0]['prompt'] ?? null, 'AI user_message becomes prompt_queue head', $failures, $passes );
assert_workflow_equals( 'static', $flow_steps[1]['queue_mode'] ?? null, 'AI user_message stores static queue mode', $failures, $passes );
assert_workflow_equals( array( 'datamachine/delete-flow' ), $flow_steps[1]['disabled_tools'] ?? null, 'flow preserves AI disabled tools', $failures, $passes );
assert_workflow_equals( array( array( 'prompt' => 'Prompt A', 'added_at' => '2026-04-27T00:00:00Z' ) ), $flow_steps[2]['prompt_queue'] ?? null, 'flow preserves explicit prompt queue', $failures, $passes );
assert_workflow_equals( 'loop', $flow_steps[2]['queue_mode'] ?? null, 'flow preserves explicit AI queue mode', $failures, $passes );

$execute_workflow_source = file_get_contents( __DIR__ . '/../inc/Abilities/Job/ExecuteWorkflowAbility.php' ) ?: '';
$create_pipeline_source  = file_get_contents( __DIR__ . '/../inc/Abilities/Pipeline/CreatePipelineAbility.php' ) ?: '';

assert_workflow_equals( true, false !== strpos( $execute_workflow_source, 'WorkflowConfigFactory::buildEphemeralConfigs( $workflow )' ), 'execute-workflow uses shared workflow config factory', $failures, $passes );
assert_workflow_equals( true, false !== strpos( $create_pipeline_source, 'WorkflowConfigFactory::buildPersistentPipelineConfig( $workflow, $pipeline_id )' ), 'create-pipeline builds persistent pipeline from workflow', $failures, $passes );
assert_workflow_equals( true, false !== strpos( $create_pipeline_source, 'WorkflowConfigFactory::buildPersistentFlowConfig(' ), 'create-pipeline builds persistent flow from workflow', $failures, $passes );

if ( $failures ) {
	echo "\nFAILED: " . count( $failures ) . " workflow persistent install assertions failed.\n";
	exit( 1 );
}

echo "\nAll {$passes} workflow persistent install assertions passed.\n";
}
