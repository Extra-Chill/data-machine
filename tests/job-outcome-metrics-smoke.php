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
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/JobArtifactSurfaces.php';
require_once __DIR__ . '/../inc/Core/StepResult.php';
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
$assert( 'true empty query class is exposed', in_array( 'true_empty_query', $metrics['outcome_classes'], true ) );
$assert( 'true empty query count is exposed', 1 === $metrics['counts']['true_empty_query'] );

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
$assert( 'source rejected outcome class is exposed', array( 'source_rejected' ) === $metrics['outcome_classes'] );

echo "\n[5] failure reasons expose distinct generic outcome classes\n";
$metrics = RunMetrics::fromJob( $job( 'failed - mcp_fetch_failed', array() ) );
$assert( 'provider failure class is exposed from status reason', array( 'provider_error' ) === $metrics['outcome_classes'] );
$assert( 'provider failure count is exposed', 1 === $metrics['counts']['provider_error'] );

$metrics = RunMetrics::fromJob( $job( 'failed - missing_source_content', array() ) );
$assert( 'hydration failure class is exposed from status reason', array( 'hydration_failed' ) === $metrics['outcome_classes'] );

$metrics = RunMetrics::fromJob(
	$job(
		'failed - step_execution_failure',
		array(
			'step_results' => array(
				'ai_1' => array(
					'flow_step_id'      => 'ai_1',
					'step_type'         => 'ai',
					'result'            => 'failed',
					'reason'            => 'empty_data_packet_returned',
					'diagnostic_reason' => 'ai_required_handler_not_called',
					'packet_count'      => 0,
				),
			),
		)
	)
);
$assert( 'AI empty packet class is exposed from step result', in_array( 'ai_empty_packet', $metrics['outcome_classes'], true ) );
$assert( 'AI handler-not-called diagnostic class is exposed from step result', in_array( 'ai_required_handler_not_called', $metrics['outcome_classes'], true ) );
$assert( 'AI diagnostic reason is exposed in outcome details', 'ai_required_handler_not_called' === ( $metrics['outcome']['ai_diagnostic_reason'] ?? '' ) );

$metrics = RunMetrics::fromJob(
	$job(
		'failed - completion_assertions_missing',
		array(
			'step_results' => array(
				'ai_1' => array(
					'flow_step_id'      => 'ai_1',
					'step_type'         => 'ai',
					'result'            => 'failed',
					'diagnostic_reason' => 'ai_completion_assertions_missing',
					'packet_count'      => 0,
				),
			),
		)
	)
);
$assert( 'AI assertion diagnostic class is exposed', in_array( 'ai_completion_assertions_missing', $metrics['outcome_classes'], true ) );

$metrics = RunMetrics::fromJob(
	$job(
		'failed - step_execution_failure',
		array(
			'step_results' => array(
				'ai_1' => array(
					'flow_step_id' => 'ai_1',
					'step_type'    => 'ai',
					'result'       => 'failed',
					'reason'       => 'handler_requiring_step_missing_handler_packets',
					'packet_count' => 2,
				),
			),
		)
	)
);
$assert( 'missing handler packet class is exposed from step result', array( 'missing_handler_packet' ) === $metrics['outcome_classes'] );

$metrics = RunMetrics::fromJob( $job( 'failed - item-deferred', array() ) );
$assert( 'item deferred class is exposed from status reason', array( 'item_deferred' ) === $metrics['outcome_classes'] );

echo "\n[6] AI concurrency backpressure is structured and not a failure\n";
$first_deferred_at = gmdate( 'c', time() - 90 );
$metrics           = RunMetrics::fromJob(
	$job(
		'pending',
		array(
			'ai_concurrency_throttle' => array(
				'state'                 => 'deferred',
				'reason'                => 'ai_concurrency_limit',
				'provider'              => 'openai',
				'flow_step_id'          => 'ai-1',
				'attempts'              => 42,
				'first_deferred_at'     => $first_deferred_at,
				'last_deferred_at'      => gmdate( 'c' ),
				'next_retry_at'         => gmdate( 'c', time() + 60 ),
				'max_defer_age_seconds' => DAY_IN_SECONDS,
				'active'                => 1,
				'limit'                 => 1,
			),
		)
	)
);
$assert( 'contention defer count is machine readable', 42 === $metrics['backpressure']['defer_count'] );
$assert( 'contention defer age is machine readable', $metrics['backpressure']['defer_age_seconds'] >= 90 );
$assert( 'contention capacity is machine readable', 1 === $metrics['backpressure']['active'] && 1 === $metrics['backpressure']['limit'] );
$assert( 'ordinary contention does not increment failed count', 0 === $metrics['counts']['failed'] );

echo "\n[7] CLI/source integration markers exist\n";
$jobs_command = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' ) ?: '';
$fetch_step   = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/FetchStep.php' ) ?: '';
$disposition  = file_get_contents( __DIR__ . '/../inc/Core/Steps/Fetch/Tools/FetchItemDispositionTool.php' ) ?: '';
$assert( 'jobs list supports pipeline filter', str_contains( $jobs_command, "assoc_args['pipeline']" ) );
$assert( 'jobs list supports handler filter', str_contains( $jobs_command, "assoc_args['handler']" ) );
$assert( 'jobs list JSON includes outcome', str_contains( $jobs_command, "item['outcome']" ) );
$assert( 'jobs metrics table prints outcome classes', str_contains( $jobs_command, 'Outcome Classes:' ) );
$assert( 'fetch step records packet count', str_contains( $fetch_step, "'packet_count' => count( \$packets )" ) );
$assert( 'source rejection persists structured reason', str_contains( $disposition, "'source_rejection'" ) );

if ( $failures > 0 ) {
	echo "\n=== job-outcome-metrics-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== job-outcome-metrics-smoke: ALL PASS ({$total} assertions) ===\n";
