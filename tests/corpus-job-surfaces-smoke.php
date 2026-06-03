<?php
/**
 * Pure-PHP smoke test for corpus-style job summary/artifact conventions (#2490).
 *
 * Run with: php tests/corpus-job-surfaces-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/RunMetrics.php';
require_once __DIR__ . '/../inc/Core/CorpusJobSurfaces.php';

use DataMachine\Core\CorpusJobSurfaces;

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

echo "=== corpus-job-surfaces-smoke ===\n";

$engine_data = array(
	'job_summary'     => array(
		'headline'          => 'Indexed handbook corpus with recoverable embedding misses.',
		'items_total'       => 12,
		'items_indexed'     => 9,
		'items_unchanged'   => 2,
		'embedding_failures' => 1,
	),
	'run_metrics'     => array(
		'counts' => array(
			'processed' => 9,
			'skipped'   => 2,
			'failed'    => 1,
		),
	),
	'corpus_artifacts' => array(
		'embedding_failures' => array(
			'artifact_type'   => 'embedding_failures',
			'artifact_ref'    => 'datamachine://jobs/77/artifacts/embedding-failures',
			'relative_path'   => 'datamachine-artifacts/jobs/77/embedding-failures.json',
			'sha256'          => str_repeat( 'a', 64 ),
			'bytes'           => 512,
			'retention_scope' => 'corpus_indexing',
		),
	),
);

$summary = CorpusJobSurfaces::summary(
	array(
		'job_id' => 77,
		'status' => 'completed',
	),
	$engine_data
);

$assert( 'summary declares corpus workload type', 'corpus_indexing' === ( $summary['workload_type'] ?? '' ) );
$assert( 'summary keeps concise headline', 'Indexed handbook corpus with recoverable embedding misses.' === ( $summary['headline'] ?? '' ) );
$assert( 'summary exposes corpus counts', 12 === ( $summary['counts']['items_total'] ?? 0 ) && 1 === ( $summary['counts']['embedding_failures'] ?? 0 ) );
$assert( 'summary preserves artifact references', 'datamachine://jobs/77/artifacts/embedding-failures' === ( $summary['artifact_refs']['embedding_failures']['artifact_ref'] ?? '' ) );

$refs = CorpusJobSurfaces::artifactRefs(
	array(
		'artifact_files' => array(
			'chunking_summary' => array(
				'artifact_type' => 'chunking_summary',
				'artifact_ref'  => 'datamachine://jobs/77/artifacts/chunking-summary',
				'bytes'         => 128,
			),
			'tool_trace'       => array(
				'artifact_type' => 'tool_trace',
				'artifact_ref'  => 'datamachine://jobs/77/artifacts/tool-trace',
			),
		),
	)
);

$assert( 'corpus artifact refs can come from runtime artifact_files', isset( $refs['chunking_summary'] ) );
$assert( 'normal run artifacts are not mislabeled as corpus artifacts', ! isset( $refs['tool_trace'] ) );
$assert( 'artifact refs get corpus retention scope by convention', 'corpus_indexing' === ( $refs['chunking_summary']['retention_scope'] ?? '' ) );

$policies = CorpusJobSurfaces::retentionPolicies();
$assert( 'corpus retention policy is separate and named', isset( $policies['corpus_indexing'] ) );
$assert( 'corpus retention policy declares artifact type selectors', in_array( 'retrieval_evaluation', $policies['corpus_indexing']['artifact_types'] ?? array(), true ) );
$assert( 'corpus retention policy documents max age', 30 === ( $policies['corpus_indexing']['max_age_days'] ?? 0 ) );

$job_artifacts_source = file_get_contents( __DIR__ . '/../inc/Core/JobArtifacts.php' ) ?: '';
$retention_source     = file_get_contents( __DIR__ . '/../inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php' ) ?: '';
$file_cleanup_source  = file_get_contents( __DIR__ . '/../inc/Core/FilesRepository/FileCleanup.php' ) ?: '';
$assert( 'JobArtifacts projects corpus job summaries', str_contains( $job_artifacts_source, 'CorpusJobSurfaces::summary' ) );
$assert( 'JobArtifacts preserves generic corpus artifact refs', str_contains( $job_artifacts_source, 'CorpusJobSurfaces::artifactRefs' ) );
$job_helpers_source = file_get_contents( __DIR__ . '/../inc/Abilities/Job/JobHelpers.php' ) ?: '';
$assert( 'jobs list/detail surfaces expose concise summaries', str_contains( $job_helpers_source, "job['job_summary']" ) );
$assert( 'retention cleanup exposes corpus artifact policy surface', str_contains( $retention_source, 'jobArtifactRetentionPolicies' ) );
$assert( 'file cleanup targets corpus retention scope from artifact payloads', str_contains( $file_cleanup_source, 'artifact_matches_retention_scope' ) );

if ( $failures > 0 ) {
	echo "\n=== corpus-job-surfaces-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== corpus-job-surfaces-smoke: ALL PASS ({$total} assertions) ===\n";
