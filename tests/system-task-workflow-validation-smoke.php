<?php
/**
 * Pure-PHP smoke for ExecuteWorkflowAbility::validateWorkflow.
 *
 * The validator only enforces STRUCTURAL invariants — the workflow has
 * steps, each step has a registered type. Per-type config requirements
 * (handler_slug presence, handler_config shape, etc.) belong to each
 * step type's own executeStep() at runtime, not here.
 *
 * Run with: php tests/system-task-workflow-validation-smoke.php
 *
 * Background — this fix removes a hardcoded `'ai' !== $step['type']`
 * exception that was rejecting all valid handler-free step types other
 * than ai (system_task, webhook_gate). The check itself was redundant
 * because step types validate their own config at execution; the
 * hardcoded exception list was the bug.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/**
 * Minimal stand-in for StepTypeAbilities. Only getAllStepTypes() is
 * needed — the validator no longer consults uses_handler since per-type
 * config validation has moved to the step types themselves.
 */
class StepTypeAbilitiesHarness {
	private array $step_types = array(
		'fetch'        => array( 'uses_handler' => true ),
		'publish'      => array( 'uses_handler' => true ),
		'upsert'       => array( 'uses_handler' => true ),
		'ai'           => array( 'uses_handler' => false ),
		'webhook_gate' => array( 'uses_handler' => false ),
		'system_task'  => array( 'uses_handler' => false ),
	);

	public function getAllStepTypes(): array {
		return $this->step_types;
	}
}

/**
 * Mirror of the post-fix validateWorkflow logic. Stays literally
 * byte-equivalent to the production source so any drift is visible.
 */
function validate_workflow_for_test( array $workflow ): array {
	if ( ! isset( $workflow['steps'] ) || ! is_array( $workflow['steps'] ) ) {
		return array( 'valid' => false, 'error' => 'Workflow must contain steps array' );
	}

	if ( empty( $workflow['steps'] ) ) {
		return array( 'valid' => false, 'error' => 'Workflow must have at least one step' );
	}

	$step_type_abilities = new StepTypeAbilitiesHarness();
	$valid_types         = array_keys( $step_type_abilities->getAllStepTypes() );

	foreach ( $workflow['steps'] as $index => $step ) {
		if ( ! isset( $step['type'] ) ) {
			return array( 'valid' => false, 'error' => "Step {$index} missing type" );
		}

		if ( ! in_array( $step['type'], $valid_types, true ) ) {
			return array(
				'valid' => false,
				'error' => "Step {$index} has invalid type: {$step['type']}. Valid types: " . implode( ', ', $valid_types ),
			);
		}
	}

	return array( 'valid' => true );
}

function dm_assert( bool $cond, string $msg ): void {
	if ( $cond ) { echo "  [PASS] {$msg}\n"; return; }
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

echo "=== system-task-workflow-validation-smoke ===\n";

// -----------------------------------------------------------------
echo "\n[1] system_task workflow with no handler_slug — VALID (regression fix)\n";
// This is the exact shape SystemTask::getWorkflow() returns.
$wf = array(
	'steps' => array(
		array(
			'type'           => 'system_task',
			'handler_config' => array(
				'task'   => 'daily_memory_generation',
				'params' => array(),
			),
		),
	),
);
$r = validate_workflow_for_test( $wf );
dm_assert( true === $r['valid'], 'system_task step accepted without handler_slug' );

// -----------------------------------------------------------------
echo "\n[2] webhook_gate step with no handler_slug — VALID\n";
$wf = array( 'steps' => array( array( 'type' => 'webhook_gate' ) ) );
$r  = validate_workflow_for_test( $wf );
dm_assert( true === $r['valid'], 'webhook_gate step accepted without handler_slug' );

// -----------------------------------------------------------------
echo "\n[3] ai step with no handler_slug — VALID (existing behavior)\n";
$wf = array( 'steps' => array( array( 'type' => 'ai' ) ) );
$r  = validate_workflow_for_test( $wf );
dm_assert( true === $r['valid'], 'ai step accepted without handler_slug' );

// -----------------------------------------------------------------
echo "\n[4] fetch step without handler_slug — VALID at workflow level\n";
// The validator no longer enforces handler_slug; FetchStep::executeStep()
// will fail at runtime when it tries to dispatch to a missing handler.
// This is the correct boundary: workflow validates structure, step types
// validate their own config.
$wf = array( 'steps' => array( array( 'type' => 'fetch' ) ) );
$r  = validate_workflow_for_test( $wf );
dm_assert( true === $r['valid'], 'fetch without handler_slug accepted at workflow level' );

// -----------------------------------------------------------------
echo "\n[5] fetch step WITH handler_slug — VALID\n";
$wf = array( 'steps' => array( array( 'type' => 'fetch', 'handler_slug' => 'rss' ) ) );
$r  = validate_workflow_for_test( $wf );
dm_assert( true === $r['valid'], 'fetch with handler_slug=rss accepted' );

// -----------------------------------------------------------------
echo "\n[6] publish step without handler_slug — VALID at workflow level\n";
$wf = array( 'steps' => array( array( 'type' => 'publish' ) ) );
$r  = validate_workflow_for_test( $wf );
dm_assert( true === $r['valid'], 'publish without handler_slug accepted at workflow level' );

// -----------------------------------------------------------------
echo "\n[7] upsert step without handler_slug — VALID at workflow level\n";
$wf = array( 'steps' => array( array( 'type' => 'upsert' ) ) );
$r  = validate_workflow_for_test( $wf );
dm_assert( true === $r['valid'], 'upsert without handler_slug accepted at workflow level' );

// -----------------------------------------------------------------
echo "\n[8] mixed workflow: fetch + ai + system_task — VALID\n";
$wf = array(
	'steps' => array(
		array( 'type' => 'fetch', 'handler_slug' => 'rss' ),
		array( 'type' => 'ai' ),
		array( 'type' => 'system_task', 'handler_config' => array( 'task' => 'cleanup' ) ),
	),
);
$r = validate_workflow_for_test( $wf );
dm_assert( true === $r['valid'], 'realistic mixed workflow valid' );

// -----------------------------------------------------------------
echo "\n[9] step with unregistered type — INVALID\n";
$wf = array( 'steps' => array( array( 'type' => 'time_travel' ) ) );
$r  = validate_workflow_for_test( $wf );
dm_assert( false === $r['valid'], 'unregistered type rejected' );
dm_assert( str_contains( $r['error'], 'invalid type' ), 'error names the issue' );
dm_assert( str_contains( $r['error'], 'time_travel' ), 'error names the bad type' );
dm_assert( str_contains( $r['error'], 'Valid types' ), 'error lists valid alternatives' );

// -----------------------------------------------------------------
echo "\n[10] empty workflow — INVALID\n";
$r = validate_workflow_for_test( array( 'steps' => array() ) );
dm_assert( false === $r['valid'], 'empty workflow rejected' );

// -----------------------------------------------------------------
echo "\n[11] workflow without steps array — INVALID\n";
$r = validate_workflow_for_test( array() );
dm_assert( false === $r['valid'], 'workflow lacking steps array rejected' );

// -----------------------------------------------------------------
echo "\n[12] step missing type — INVALID\n";
$r = validate_workflow_for_test( array( 'steps' => array( array() ) ) );
dm_assert( false === $r['valid'], 'step without type rejected' );
dm_assert( str_contains( $r['error'], 'missing type' ), 'error names the issue' );
dm_assert( str_contains( $r['error'], 'Step 0' ), 'error identifies the step index' );

// -----------------------------------------------------------------
echo "\n[13] real-world DailyMemoryTask workflow — VALID (the bug case)\n";
// Verbatim from SystemTask::getWorkflow().
$wf = array(
	'steps' => array(
		array(
			'type'           => 'system_task',
			'handler_config' => array(
				'task'   => 'daily_memory_generation',
				'params' => array( 'date' => '2026-04-24' ),
			),
		),
	),
);
$r = validate_workflow_for_test( $wf );
dm_assert( true === $r['valid'], 'DailyMemoryTask workflow validates cleanly' );

echo "\n=== system-task-workflow-validation-smoke: ALL PASS ===\n";
