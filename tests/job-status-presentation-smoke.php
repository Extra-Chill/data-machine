<?php
/** Regression coverage for read-time job status presentation. */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/JobArtifactSurfaces.php';
require_once __DIR__ . '/../inc/Abilities/Job/JobHelpers.php';

class JobStatusPresentationHarness {
	use DataMachine\Abilities\Job\JobHelpers;

	public function present( array $job ): array {
		return $this->addDisplayFields( $job );
	}
}

$harness = new JobStatusPresentationHarness();
$legacy = $harness->present( array( 'status' => 'agent_skipped - source-rejected', 'engine_data' => array() ) );
$normalized = $harness->present( array( 'status' => 'failed', 'engine_data' => array( 'job_status_reason' => 'provider timeout' ) ) );

$failures = array();
if ( 'agent_skipped' !== $legacy['base_status'] || 'agent_skipped - source-rejected' !== $legacy['status'] || 'source-rejected' !== $legacy['status_reason'] || 'agent_skipped - source-rejected' !== $legacy['status_display'] ) {
	$failures[] = 'legacy compound row is not composed correctly';
}
if ( 'failed' !== $normalized['base_status'] || 'failed - provider timeout' !== $normalized['status'] || 'provider timeout' !== $normalized['status_reason'] || 'failed - provider timeout' !== $normalized['status_display'] ) {
	$failures[] = 'normalized row is not composed correctly';
}

if ( $failures ) {
	echo 'FAIL: ' . implode( "\nFAIL: ", $failures ) . "\n";
	exit( 1 );
}

echo "Job status presentation smoke complete: all assertions passed.\n";
