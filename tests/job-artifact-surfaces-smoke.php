<?php
/**
 * Pure-PHP smoke test for generic job summary/artifact surfaces (#2490 follow-up).
 *
 * Run with: php tests/job-artifact-surfaces-smoke.php
 *
 * @package DataMachine\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/RunMetrics.php';
require_once __DIR__ . '/../inc/Core/JobArtifactSurfaces.php';

use DataMachine\Core\JobArtifactSurfaces;

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

echo "=== job-artifact-surfaces-smoke ===\n";

$engine_data = array(
	'job_summary'   => array(
		'workload_type' => 'indexing',
		'headline'      => 'Indexed source set with recoverable misses.',
		'counts'        => array(
			'selected'  => 12,
			'processed' => 9,
			'skipped'   => 2,
			'failed'    => 1,
		),
	),
	'job_artifacts' => array(
		'failure_report' => array(
			'artifact_type' => 'failure_report',
			'artifact_ref'  => 'datamachine://jobs/77/artifacts/failure-report',
			'relative_path' => 'datamachine-artifacts/jobs/77/failure-report.json',
			'sha256'        => str_repeat( 'a', 64 ),
			'bytes'         => 512,
		),
	),
);

$summary = JobArtifactSurfaces::summary(
	array(
		'job_id' => 77,
		'status' => 'completed',
	),
	$engine_data
);

$assert( 'summary declares generic indexing workload type', 'indexing' === ( $summary['workload_type'] ?? '' ) );
$assert( 'summary keeps concise headline', 'Indexed source set with recoverable misses.' === ( $summary['headline'] ?? '' ) );
$assert( 'summary exposes generic counts', 12 === ( $summary['counts']['selected'] ?? 0 ) && 1 === ( $summary['counts']['failed'] ?? 0 ) );
$assert( 'summary preserves scoped artifact references', 'datamachine://jobs/77/artifacts/failure-report' === ( $summary['artifact_refs']['failure_report']['artifact_ref'] ?? '' ) );

$refs = JobArtifactSurfaces::artifactRefs(
	array(
		'artifact_files' => array(
			'scoped_report' => array(
				'artifact_type'   => 'scoped_report',
				'artifact_ref'    => 'datamachine://jobs/77/artifacts/scoped-report',
				'bytes'           => 128,
				'retention_scope' => 'indexing_artifacts',
			),
			'tool_trace'    => array(
				'artifact_type' => 'tool_trace',
				'artifact_ref'  => 'datamachine://jobs/77/artifacts/tool-trace',
			),
		),
	)
);

$assert( 'scoped artifact refs can come from runtime artifact_files', isset( $refs['scoped_report'] ) );
$assert( 'normal run artifacts are not mislabeled as scoped job artifacts', ! isset( $refs['tool_trace'] ) );
$assert( 'job_artifacts get default retention scope by convention', 'indexing_artifacts' === ( $summary['artifact_refs']['failure_report']['retention_scope'] ?? '' ) );

$policies = JobArtifactSurfaces::retentionPolicies();
$assert( 'job artifact retention policy is separate and named', isset( $policies['indexing_artifacts'] ) );
$assert( 'job artifact retention policy documents max age', 30 === ( $policies['indexing_artifacts']['max_age_days'] ?? 0 ) );

$job_artifacts_source = file_get_contents( __DIR__ . '/../inc/Core/JobArtifacts.php' ) ?: '';
$retention_source     = file_get_contents( __DIR__ . '/../inc/Engine/AI/System/Tasks/Retention/RetentionCleanup.php' ) ?: '';
$file_cleanup_source  = file_get_contents( __DIR__ . '/../inc/Core/FilesRepository/FileCleanup.php' ) ?: '';
$job_helpers_source   = file_get_contents( __DIR__ . '/../inc/Abilities/Job/JobHelpers.php' ) ?: '';
$assert( 'JobArtifacts projects generic job summaries', str_contains( $job_artifacts_source, 'JobArtifactSurfaces::summary' ) );
$assert( 'JobArtifacts preserves generic scoped artifact refs', str_contains( $job_artifacts_source, 'JobArtifactSurfaces::artifactRefs' ) );
$assert( 'jobs list/detail surfaces expose concise summaries', str_contains( $job_helpers_source, "job['job_summary']" ) );
$assert( 'retention cleanup exposes job artifact policy surface', str_contains( $retention_source, 'jobArtifactRetentionPolicies' ) );
$assert( 'file cleanup targets explicit retention scope from artifact payloads', str_contains( $file_cleanup_source, 'artifact_matches_retention_scope' ) );
$assert( 'generic job surface has no embedded corpus terminology', ! str_contains( file_get_contents( __DIR__ . '/../inc/Core/JobArtifactSurfaces.php' ) ?: '', 'corpus' ) );

if ( $failures > 0 ) {
	echo "\n=== job-artifact-surfaces-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== job-artifact-surfaces-smoke: ALL PASS ({$total} assertions) ===\n";
