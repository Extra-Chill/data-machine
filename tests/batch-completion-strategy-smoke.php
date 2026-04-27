<?php
/**
 * Pure-PHP smoke test for explicit batch completion strategy metadata.
 *
 * Run with: php tests/batch-completion-strategy-smoke.php
 *
 * Verifies issue #1347's metadata-only contract: pipeline fan-out and
 * system-task fan-out declare how their parent batch completes, while the
 * existing completion paths remain unchanged.
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function dm_strategy_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

function dm_strategy_read( string $relative_path ): string {
	$path = dirname( __DIR__ ) . '/' . $relative_path;
	$body = file_get_contents( $path );
	if ( false === $body ) {
		echo "  [FAIL] could not read {$relative_path}\n";
		exit( 1 );
	}
	return $body;
}

/**
 * Mirror of BatchScheduler::start()'s top-level metadata shape.
 */
function build_batch_metadata( int $total, int $chunk_size, string $context, string $completion_strategy ): array {
	return array(
		'batch'                     => true,
		'batch_total'               => $total,
		'batch_scheduled'           => 0,
		'batch_chunk_size'          => $chunk_size,
		'batch_context'             => $context,
		'batch_completion_strategy' => $completion_strategy,
	);
}

/**
 * Mirror of TaskScheduler::getBatchStatus()'s exposed strategy field.
 */
function build_task_batch_status_strategy( array $engine_data ): string {
	return $engine_data['batch_completion_strategy'] ?? '';
}

echo "=== batch-completion-strategy-smoke ===\n";

$batch_scheduler   = dm_strategy_read( 'inc/Core/ActionScheduler/BatchScheduler.php' );
$pipeline_batch    = dm_strategy_read( 'inc/Abilities/Engine/PipelineBatchScheduler.php' );
$task_scheduler    = dm_strategy_read( 'inc/Engine/Tasks/TaskScheduler.php' );
$children_strategy = 'children_complete';
$chunks_strategy   = 'chunks_scheduled';

// -----------------------------------------------------------------
echo "\n[1] BatchScheduler declares and stores completion strategy metadata\n";
dm_strategy_assert(
	str_contains( $batch_scheduler, "COMPLETION_STRATEGY_CHILDREN_COMPLETE = '{$children_strategy}'" ),
	'children_complete strategy constant exists'
);
dm_strategy_assert(
	str_contains( $batch_scheduler, "COMPLETION_STRATEGY_CHUNKS_SCHEDULED = '{$chunks_strategy}'" ),
	'chunks_scheduled strategy constant exists'
);
dm_strategy_assert(
	str_contains( $batch_scheduler, "'batch_completion_strategy' => \$completion_strategy" ),
	'BatchScheduler::start stores batch_completion_strategy in engine_data'
);

$metadata = build_batch_metadata( 25, 10, 'pipeline', $children_strategy );
dm_strategy_assert( true === $metadata['batch'], 'metadata still marks the parent as a batch' );
dm_strategy_assert( 25 === $metadata['batch_total'], 'metadata still stores batch_total' );
dm_strategy_assert( 0 === $metadata['batch_scheduled'], 'metadata still initializes batch_scheduled to 0' );
dm_strategy_assert( 10 === $metadata['batch_chunk_size'], 'metadata still stores batch_chunk_size' );
dm_strategy_assert( 'pipeline' === $metadata['batch_context'], 'metadata still stores batch_context' );
dm_strategy_assert( $children_strategy === $metadata['batch_completion_strategy'], 'metadata stores declared completion strategy' );

// -----------------------------------------------------------------
echo "\n[2] Pipeline fan-out declares children_complete\n";
dm_strategy_assert(
	str_contains( $pipeline_batch, 'BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE' ),
	'PipelineBatchScheduler::fanOut passes children_complete to BatchScheduler::start'
);
dm_strategy_assert(
	str_contains( $pipeline_batch, 'public static function onChildComplete' ),
	'pipeline completion still lives in onChildComplete()'
);
dm_strategy_assert(
	str_contains( $pipeline_batch, '$active > 0 || $total_children < $batch_total' ),
	'pipeline parent still waits for children to finish and all children to be scheduled'
);
dm_strategy_assert(
	str_contains( $pipeline_batch, '$jobs_db->complete_job( (int) $parent_job_id, $parent_status );' ),
	'pipeline parent status is still completed from child aggregation'
);

// -----------------------------------------------------------------
echo "\n[3] System-task fan-out declares chunks_scheduled\n";
dm_strategy_assert(
	str_contains( $task_scheduler, 'BatchScheduler::COMPLETION_STRATEGY_CHUNKS_SCHEDULED' ),
	'TaskScheduler::scheduleBatch passes chunks_scheduled to BatchScheduler::start'
);
dm_strategy_assert(
	str_contains( $task_scheduler, "if ( ! \$result['more'] )" ),
	'task batch still completes on the final chunk path'
);
dm_strategy_assert(
	str_contains( $task_scheduler, '$jobs_db->complete_job( $parent_job_id, JobStatus::COMPLETED );' ),
	'task batch parent still completes after chunks are scheduled'
);

$metadata = build_batch_metadata( 25, 10, 'task', $chunks_strategy );
dm_strategy_assert( 'task' === $metadata['batch_context'], 'task metadata still stores batch_context=task' );
dm_strategy_assert( $chunks_strategy === $metadata['batch_completion_strategy'], 'task metadata stores chunks_scheduled strategy' );

// -----------------------------------------------------------------
echo "\n[4] Task batch status exposes strategy without changing behavior\n";
dm_strategy_assert(
	str_contains( $task_scheduler, "'completion_strategy' => \$engine_data['batch_completion_strategy'] ?? ''" ),
	'getBatchStatus exposes completion_strategy from engine_data'
);
dm_strategy_assert( $chunks_strategy === build_task_batch_status_strategy( $metadata ), 'status helper resolves stored strategy' );
dm_strategy_assert( '' === build_task_batch_status_strategy( array() ), 'missing legacy strategy resolves to empty string' );

echo "\n=== batch-completion-strategy-smoke: ALL PASS ===\n";
