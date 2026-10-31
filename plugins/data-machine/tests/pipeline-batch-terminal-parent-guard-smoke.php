<?php
/**
 * Static smoke test for terminal parent guard in pipeline batch chunks.
 *
 * Run with: php tests/pipeline-batch-terminal-parent-guard-smoke.php
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

function assert_pipeline_batch_terminal_guard_smoke( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;

	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
	++$failed;
}

$source        = file_get_contents( __DIR__ . '/../inc/Abilities/Engine/PipelineBatchScheduler.php' ) ?: '';
$process_chunk = strstr( $source, 'public function processChunk' ) ?: '';

echo "Case 1: batch chunks refuse terminal parents\n";
assert_pipeline_batch_terminal_guard_smoke( 'processChunk reads parent job before processing batch state', strpos( $process_chunk, '$parent_job = $this->db_jobs->get_job( $parent_job_id );' ) < strpos( $process_chunk, 'BatchScheduler::processChunk' ) );
assert_pipeline_batch_terminal_guard_smoke( 'processChunk requires processing status', str_contains( $process_chunk, 'JobStatus::PROCESSING !== ( $parent_job[\'status\'] ?? \'\' )' ) );
assert_pipeline_batch_terminal_guard_smoke( 'terminal parent guard returns before child creation', strpos( $process_chunk, 'return;' ) < strpos( $process_chunk, 'BatchScheduler::processChunk' ) );
assert_pipeline_batch_terminal_guard_smoke( 'terminal parent guard logs skipped chunk', str_contains( $process_chunk, 'Pipeline batch: skipped chunk because parent is no longer processing' ) );

echo "\nPipeline batch terminal parent guard smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
