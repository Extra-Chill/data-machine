<?php
/**
 * Static smoke test for memory-bounded stuck job recovery.
 *
 * Run with: php tests/recover-stuck-bounded-memory-smoke.php
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

function assert_recover_stuck_bounded_memory_smoke( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;

	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
	++$failed;
}

$ability_source = file_get_contents( __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php' ) ?: '';
$worker_source  = file_get_contents( __DIR__ . '/../inc/Cli/Commands/WorkerCommand.php' ) ?: '';

echo "Case 1: recovery queries candidate IDs in bounded batches\n";
assert_recover_stuck_bounded_memory_smoke( 'batch size constant exists', str_contains( $ability_source, 'private const CANDIDATE_BATCH_SIZE = 50' ) );
assert_recover_stuck_bounded_memory_smoke( 'candidate queries order by job id', str_contains( $ability_source, 'ORDER BY job_id ASC' ) );
assert_recover_stuck_bounded_memory_smoke( 'candidate queries are limited', str_contains( $ability_source, 'LIMIT " . self::CANDIDATE_BATCH_SIZE' ) );
assert_recover_stuck_bounded_memory_smoke( 'bulk timeout query does not select engine_data', ! str_contains( $ability_source, 'SELECT job_id, flow_id, engine_data FROM' ) );

echo "Case 2: recovery fetches large payloads one job at a time\n";
assert_recover_stuck_bounded_memory_smoke( 'single-job engine data helper exists', str_contains( $ability_source, 'private function getJobEngineData' ) );
assert_recover_stuck_bounded_memory_smoke( 'single-job engine data query is keyed by job_id', str_contains( $ability_source, 'SELECT engine_data FROM {$table} WHERE job_id = %d' ) );
assert_recover_stuck_bounded_memory_smoke( 'timeout loop uses single-job engine data fetch', str_contains( $ability_source, '$engine_data = $this->getJobEngineData( (int) $job->job_id );' ) );

echo "Case 3: dry-run details are bounded\n";
assert_recover_stuck_bounded_memory_smoke( 'job detail limit constant exists', str_contains( $ability_source, 'private const JOB_DETAIL_LIMIT     = 100' ) );
assert_recover_stuck_bounded_memory_smoke( 'bounded append helper exists', str_contains( $ability_source, 'private function appendJobDetail' ) );
assert_recover_stuck_bounded_memory_smoke( 'result reports omitted details', str_contains( $ability_source, "'jobs_omitted'" ) && str_contains( $ability_source, "'jobs_truncated'" ) );

echo "Case 4: worker status avoids full recovery dry-run\n";
assert_recover_stuck_bounded_memory_smoke( 'cheap stuck candidate counter exists', str_contains( $ability_source, 'public static function countStuckCandidates' ) );
assert_recover_stuck_bounded_memory_smoke( 'worker status uses cheap counter', str_contains( $worker_source, 'RecoverStuckJobsAbility::countStuckCandidates()' ) );
assert_recover_stuck_bounded_memory_smoke( 'worker status no longer executes dry-run recovery', ! str_contains( $worker_source, "'dry_run' => true" ) );

echo "\nRecover-stuck bounded memory smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
