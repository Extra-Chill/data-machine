<?php
/**
 * Pure-PHP smoke test for TaskScheduler::scheduleBatch parent_job_id propagation.
 *
 * Run with: php tests/task-scheduler-batch-parent-link-smoke.php
 *
 * Verifies the parent_job_id resolution logic that gates whether
 * children land linked to the original caller (so SystemTask::undo can
 * walk them via Jobs::get_children) or fall through to the legacy
 * unlinked behaviour.
 *
 * Three resolution paths in production:
 *
 * 1. Small batch — every item calls schedule($task, $params, $context, $caller_parent).
 *    The 4th arg is read from $context['parent_job_id'].
 * 2. Large batch — a batch parent job is created. When the caller passed
 *    parent_job_id, the batch parent itself is linked to the caller AND
 *    per-item children chain to the caller (caller intent wins).
 * 3. Large batch (no caller parent) — per-item children chain to the
 *    batch parent for grouping (existing behaviour).
 *
 * The full live path requires WordPress + Action Scheduler + DB, so
 * these harness functions mirror the production array-construction
 * byte-for-byte. Any drift surfaces as a diff between this smoke and
 * the source.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ─── Harness functions mirroring production paths ───────────────────

/**
 * Mirror of the small-batch path inside TaskScheduler::scheduleBatch.
 * Returns the per-item parent_job_id that schedule() would receive.
 */
function resolve_small_batch_parent_id( array $context ): int {
	return isset( $context['parent_job_id'] ) ? (int) $context['parent_job_id'] : 0;
}

/**
 * Mirror of the batch-parent create_args construction.
 * Returns the args array passed to Jobs::create_job for the batch parent.
 */
function build_batch_parent_create_args( string $task_type, int $caller_parent_job_id ): array {
	$args = array(
		'pipeline_id' => 'direct',
		'flow_id'     => 'direct',
		'source'      => 'batch',
		'label'       => 'Batch: ' . ucfirst( str_replace( '_', ' ', $task_type ) ),
	);
	if ( $caller_parent_job_id > 0 ) {
		$args['parent_job_id'] = $caller_parent_job_id;
	}
	return $args;
}

/**
 * Mirror of processBatchChunk's per-item parent resolution closure.
 * Returns the parent_job_id stamped onto each child.
 */
function resolve_chunk_child_parent_id( array $extra, int $batch_parent_id ): int {
	$caller_parent_job_id = (int) ( $extra['caller_parent_job_id'] ?? 0 );
	return $caller_parent_job_id > 0 ? $caller_parent_job_id : $batch_parent_id;
}

/**
 * Mirror of ExecuteWorkflowAbility::execute()'s create_args build for
 * the parent_job_id path. Returns the create_args passed to create_job.
 */
function build_execute_workflow_create_args( ?array $initial_data ): array {
	$args = array(
		'pipeline_id' => 'direct',
		'flow_id'     => 'direct',
		'source'      => 'chat',
		'label'       => 'Chat Workflow',
	);
	if ( is_array( $initial_data ) ) {
		$initial_parent_job_id = (int) ( $initial_data['parent_job_id'] ?? 0 );
		if ( $initial_parent_job_id > 0 ) {
			$args['parent_job_id'] = $initial_parent_job_id;
		}
	}
	return $args;
}

function dm_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

echo "=== task-scheduler-batch-parent-link-smoke ===\n";

// -----------------------------------------------------------------
echo "\n[1] small batch with context['parent_job_id'] → children link to caller\n";
$context = array(
	'parent_job_id' => 64,
	'agent_id'      => 2,
	'user_id'       => 1,
);
$resolved = resolve_small_batch_parent_id( $context );
dm_assert( 64 === $resolved, 'small-batch path resolves caller parent_job_id from context' );

// -----------------------------------------------------------------
echo "\n[2] small batch without parent_job_id → resolves to 0 (legacy unlinked)\n";
$context  = array( 'agent_id' => 2 );
$resolved = resolve_small_batch_parent_id( $context );
dm_assert( 0 === $resolved, 'no parent_job_id in context → 0 (no link)' );

// -----------------------------------------------------------------
echo "\n[3] small batch with parent_job_id=0 explicit → 0 (no link)\n";
$context  = array( 'parent_job_id' => 0 );
$resolved = resolve_small_batch_parent_id( $context );
dm_assert( 0 === $resolved, 'explicit parent_job_id=0 stays 0' );

// -----------------------------------------------------------------
echo "\n[4] small batch with string parent_job_id → coerced to int\n";
$context  = array( 'parent_job_id' => '99' );
$resolved = resolve_small_batch_parent_id( $context );
dm_assert( 99 === $resolved, 'string parent_job_id coerced to int' );

// -----------------------------------------------------------------
echo "\n[5] large batch parent — caller passed parent_job_id → batch parent links to caller\n";
$args = build_batch_parent_create_args( 'wiki_maintain', 64 );
dm_assert( isset( $args['parent_job_id'] ), 'parent_job_id stamped onto batch parent create_args' );
dm_assert( 64 === $args['parent_job_id'], 'batch parent linked to caller (job 64)' );
dm_assert( 'batch' === $args['source'], 'source still batch' );
dm_assert( 'Batch: Wiki maintain' === $args['label'], 'label humanized from task_type' );

// -----------------------------------------------------------------
echo "\n[6] large batch parent — no caller parent → no parent_job_id key\n";
$args = build_batch_parent_create_args( 'alt_text', 0 );
dm_assert( ! isset( $args['parent_job_id'] ), 'no parent_job_id key when caller did not pass one' );
dm_assert( 'batch' === $args['source'], 'source still batch' );

// -----------------------------------------------------------------
echo "\n[7] large batch chunk — caller_parent_job_id wins over batch_parent_id\n";
// Spec: "caller intent wins". When the original caller passed
// parent_job_id, per-item children chain to the caller directly so
// Jobs::get_children($caller_jid) walks them without going through the
// batch parent.
$extra = array(
	'task_type'            => 'wiki_maintain',
	'caller_parent_job_id' => 64,
);
$resolved = resolve_chunk_child_parent_id( $extra, 100 ); // 100 = batch_parent_id
dm_assert( 64 === $resolved, 'children chain to caller (64), not batch parent (100)' );

// -----------------------------------------------------------------
echo "\n[8] large batch chunk — no caller parent → children chain to batch_parent\n";
$extra = array(
	'task_type'            => 'alt_text',
	'caller_parent_job_id' => 0,
);
$resolved = resolve_chunk_child_parent_id( $extra, 100 );
dm_assert( 100 === $resolved, 'children chain to batch parent when caller did not pass parent_job_id' );

// -----------------------------------------------------------------
echo "\n[9] large batch chunk — missing caller_parent_job_id key → falls back to batch_parent\n";
$extra = array(
	'task_type' => 'alt_text',
	// no caller_parent_job_id key at all (legacy in-flight chunk).
);
$resolved = resolve_chunk_child_parent_id( $extra, 100 );
dm_assert( 100 === $resolved, 'missing caller_parent_job_id key → batch parent (legacy compat)' );

// -----------------------------------------------------------------
echo "\n[10] ExecuteWorkflowAbility — parent_job_id from initial_data → create_args\n";
// This is the actual gate: TaskScheduler::schedule passes parent_job_id
// in initial_data, and ExecuteWorkflowAbility reads it back into
// create_args so the indexed parent_job_id column is stamped.
$initial_data = array(
	'task_type'     => 'wiki_maintain',
	'parent_job_id' => 64,
	'agent_id'      => 2,
	'user_id'       => 1,
);
$args = build_execute_workflow_create_args( $initial_data );
dm_assert( isset( $args['parent_job_id'] ), 'parent_job_id stamped onto create_args from initial_data' );
dm_assert( 64 === $args['parent_job_id'], 'create_args parent_job_id = 64' );

// -----------------------------------------------------------------
echo "\n[11] ExecuteWorkflowAbility — no parent_job_id in initial_data → no key\n";
$args = build_execute_workflow_create_args( array( 'task_type' => 'wiki_maintain' ) );
dm_assert( ! isset( $args['parent_job_id'] ), 'no parent_job_id key when initial_data lacks it' );

// -----------------------------------------------------------------
echo "\n[12] ExecuteWorkflowAbility — null initial_data → no key\n";
$args = build_execute_workflow_create_args( null );
dm_assert( ! isset( $args['parent_job_id'] ), 'null initial_data → no parent_job_id key' );

// -----------------------------------------------------------------
echo "\n[13] end-to-end fan-out shape — caller(64) → 3 children, all linked\n";
// Real-world wiki_maintain shape: parent task_type=wiki_maintain at job
// 64 calls scheduleBatch with 3 article slugs and $context = ['parent_
// job_id' => 64]. Small-batch path triggers (3 items < default chunk
// size). Each child's create_args carries parent_job_id=64.
$context = array(
	'parent_job_id' => 64,
	'agent_id'      => 2,
	'user_id'       => 1,
);
$resolved = resolve_small_batch_parent_id( $context );

// Simulate three schedule() calls — each runs through ExecuteWorkflow:
$slugs           = array( 'projects/wiki-a', 'projects/wiki-b', 'projects/wiki-c' );
$child_link_ids  = array();
foreach ( $slugs as $slug ) {
	$initial_data = array(
		'task_type'     => 'wiki_maintain_article',
		'parent_job_id' => $resolved,
		'task_params'   => array( 'slug' => $slug ),
	);
	$args = build_execute_workflow_create_args( $initial_data );
	$child_link_ids[] = $args['parent_job_id'] ?? 0;
}

dm_assert( array( 64, 64, 64 ) === $child_link_ids, 'all three children linked to caller (64)' );

echo "\n=== task-scheduler-batch-parent-link-smoke: ALL PASS ===\n";
