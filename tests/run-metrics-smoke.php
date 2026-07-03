<?php
/**
 * Pure-PHP smoke test for generic run metrics shaping.
 *
 * Run with: php tests/run-metrics-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
		return json_encode( $data, $flags, $depth );
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/JobArtifactSurfaces.php';
require_once __DIR__ . '/../inc/Core/StepResult.php';
require_once __DIR__ . '/../inc/Core/RunResult.php';
require_once __DIR__ . '/../inc/Core/RunMetrics.php';

use DataMachine\Core\RunMetrics;

function datamachine_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		echo "  [PASS] {$msg}\n";
		return;
	}
	echo "  [FAIL] {$msg}\n";
	exit( 1 );
}

echo "=== run-metrics-smoke ===\n";

echo "\n[1] normalize fills generic counters\n";
$normalized = RunMetrics::normalize(
	array(
		'counts'     => array(
			'processed'      => 4,
			'staged_actions' => 2,
		),
		'started_at' => '2026-05-03 10:00:00',
	)
);

datamachine_assert( 4 === $normalized['counts']['processed'], 'processed count preserved' );
datamachine_assert( 2 === $normalized['counts']['staged_actions'], 'staged action count preserved' );
datamachine_assert( 0 === $normalized['counts']['failed'], 'missing failed count defaults to zero' );
datamachine_assert( 0 === $normalized['counts']['retried'], 'missing retried count defaults to zero' );

echo "\n[2] fromJob combines persisted metrics, batch results, tokens, and duration\n";
$metrics = RunMetrics::fromJob(
	array(
		'job_id'        => 123,
		'source'        => 'pipeline',
		'label'         => 'Backfill',
		'flow_id'       => '77',
		'pipeline_id'   => '12',
		'status'        => 'completed',
		'created_at'    => '2026-05-03 09:59:50',
		'completed_at'  => '2026-05-03 10:05:00',
		'engine_data'   => array(
			'run_metrics'   => array(
				'counts'     => array(
					'staged_actions' => 3,
					'accepted_actions' => 2,
				),
				'started_at' => '2026-05-03 10:00:00',
			),
			'batch_results' => array(
				'selected'  => 11,
				'completed' => 8,
				'failed'    => 1,
				'skipped'   => 2,
				'retried'   => 1,
			),
			'token_usage'   => array(
				'total_tokens' => 99,
			),
		),
	)
);

datamachine_assert( 123 === $metrics['job_id'], 'job_id surfaced' );
datamachine_assert( 11 === $metrics['counts']['selected'], 'batch selected count surfaced' );
datamachine_assert( 8 === $metrics['counts']['processed'], 'batch completed count maps to processed' );
datamachine_assert( 2 === $metrics['counts']['skipped'], 'batch skipped count surfaced' );
datamachine_assert( 1 === $metrics['counts']['failed'], 'batch failed count surfaced' );
datamachine_assert( 1 === $metrics['counts']['retried'], 'batch retried count surfaced' );
datamachine_assert( 3 === $metrics['counts']['staged_actions'], 'staged action count surfaced' );
datamachine_assert( 2 === $metrics['counts']['accepted_actions'], 'accepted action count surfaced' );
datamachine_assert( 300 === $metrics['duration_seconds'], 'duration uses started/completed timestamps' );
datamachine_assert( 99 === $metrics['token_usage']['total_tokens'], 'token usage is exposed when present' );

echo "\n[3] status-derived counts cover terminal no-item and failed jobs\n";
$skipped = RunMetrics::fromJob(
	array(
		'job_id'      => 124,
		'source'      => 'system',
		'status'      => 'completed_no_items',
		'created_at'  => '2026-05-03 10:00:00',
		'engine_data' => array(),
	)
);
$failed = RunMetrics::fromJob(
	array(
		'job_id'      => 125,
		'source'      => 'system',
		'status'      => 'failed - boom',
		'created_at'  => '2026-05-03 10:00:00',
		'engine_data' => array(),
	)
);

datamachine_assert( 1 === $skipped['counts']['skipped'], 'completed_no_items increments skipped' );
datamachine_assert( 1 === $failed['counts']['failed'], 'failed status increments failed' );

echo "\n[4] engine_data writers use compare-and-swap, not blind overwrite (regression: #2762)\n";
// The lost-update race behind batch_state_missing came from start(), increment(),
// and complete() doing a non-atomic retrieve()+persist() read-modify-write that
// could overwrite a concurrent fan-out batch_state merge with a stale snapshot.
// All three must route through the compare-and-swap EngineData::mutate() path so
// they can never clobber another writer's keys.
$run_metrics_source = file_get_contents( __DIR__ . '/../inc/Core/RunMetrics.php' );
datamachine_assert( false !== $run_metrics_source, 'RunMetrics source readable' );

foreach ( array( 'start', 'increment', 'complete' ) as $method ) {
	if ( ! preg_match( '/public static function ' . $method . '\([^)]*\)[^{]*\{(.*?)\n\t\}/s', $run_metrics_source, $m ) ) {
		echo "  [FAIL] could not locate {$method}() body\n";
		exit( 1 );
	}
	$body = $m[1];
	datamachine_assert( str_contains( $body, 'EngineData::mutate' ), "{$method}() persists via EngineData::mutate (CAS)" );
	datamachine_assert( ! str_contains( $body, 'EngineData::persist' ), "{$method}() does not blind-overwrite via EngineData::persist" );
}

echo "\n=== run-metrics-smoke: ALL PASS ===\n";
