<?php
/**
 * Pure-PHP smoke test for generic job outcome metrics (#2130).
 *
 * Run with: php tests/job-outcome-metrics-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/RunMetrics.php';

use DataMachine\Core\RunMetrics;

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}

	++$failures;
	echo "  [FAIL] {$label}\n";
};

$job = function ( string $status, array $engine_data ): array {
	return array(
		'job_id'      => 123,
		'source'      => 'pipeline',
		'label'       => 'Outcome smoke',
		'flow_id'     => '10',
		'pipeline_id' => '20',
		'status'      => $status,
		'created_at'  => '2026-05-20 00:00:00',
		'engine_data' => $engine_data,
	);
};

echo "=== job-outcome-metrics-smoke ===\n";

echo "\n[1] completed fetch exposes packet count and handler IDs\n";
$metrics = RunMetrics::fromJob(
	$job(
		'completed',
		array(
			'step_results' => array(
				'fetch_1' => array(
					'flow_step_id' => 'fetch_1',
					'step_type'    => 'fetch',
					'result'       => 'completed',
					'handler_slug' => 'mcp',
					'provider_id'  => 'a8c',
					'tool_ids'     => array( 'search' ),
					'packet_count' => 7,
				),
			),
		)
	)
);
$assert( 'fetch packet count is machine readable', 7 === $metrics['outcome']['fetch_packet_count'] );
$assert( 'handler slug is exposed', 'mcp' === $metrics['outcome']['handler_slug'] );
$assert( 'provider id is exposed', 'a8c' === $metrics['outcome']['provider_id'] );
$assert( 'tool ids are exposed', array( 'search' ) === $metrics['outcome']['tool_ids'] );

echo "\n[2] completed_no_items exposes no-content outcome\n";
$metrics = RunMetrics::fromJob(
	$job(
		'completed_no_items',
		array(
			'step_results' => array(
				'fetch_1' => array(
					'flow_step_id' => 'fetch_1',
					'step_type'    => 'fetch',
					'result'       => 'no_content',
					'handler_slug' => 'rss',
					'packet_count' => 0,
				),
			),
		)
	)
);
$assert( 'base status is completed_no_items', 'completed_no_items' === $metrics['outcome']['base_status'] );
$assert( 'no_content boolean is true', true === $metrics['outcome']['no_content'] );

echo "\n[3] failed status exposes failure base status\n";
$metrics = RunMetrics::fromJob( $job( 'failed - api-timeout', array() ) );
$assert( 'failed base status is exposed', 'failed' === $metrics['outcome']['base_status'] );
$assert( 'failed status reason is exposed', 'api-timeout' === $metrics['outcome']['status_reason'] );

echo "\n[4] source rejected exposes rejection reason without log scraping\n";
$metrics = RunMetrics::fromJob(
	$job(
		'agent_skipped - source-rejected',
		array(
			'source_rejection' => array( 'reason' => 'not relevant' ),
			'step_results'     => array(
				'fetch_1' => array(
					'flow_step_id'             => 'fetch_1',
					'step_type'                => 'fetch',
					'result'                   => 'source_rejected',
					'source_rejection_reason'  => 'not relevant',
				),
			),
		)
	)
);
$assert( 'source_rejected boolean is true', true === $metrics['outcome']['source_rejected'] );
$assert( 'source rejection reason is exposed', 'not relevant' === $metrics['outcome']['source_rejection_reason'] );

echo "\n[5] CLI/source integration markers exist\n";
$jobs_command = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' ) ?: '';
$fetch_step   = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/FetchStep.php' ) ?: '';
$disposition  = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/Tools/FetchItemDispositionTool.php' ) ?: '';
$assert( 'jobs list supports pipeline filter', str_contains( $jobs_command, "assoc_args['pipeline']" ) );
$assert( 'jobs list supports handler filter', str_contains( $jobs_command, "assoc_args['handler']" ) );
$assert( 'jobs list JSON includes outcome', str_contains( $jobs_command, "item['outcome']" ) );
$assert( 'fetch step records packet count', str_contains( $fetch_step, "'packet_count' => count( \$packets )" ) );
$assert( 'source rejection persists structured reason', str_contains( $disposition, "'source_rejection'" ) );

if ( $failures > 0 ) {
	echo "\n=== job-outcome-metrics-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== job-outcome-metrics-smoke: ALL PASS ({$total} assertions) ===\n";
