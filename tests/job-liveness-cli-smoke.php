<?php
/** Behavioral smoke test for job liveness classification. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

require_once __DIR__ . '/../inc/Core/ChildJobRecoveryPolicy.php';
require_once __DIR__ . '/../inc/Cli/JobLivenessClassifier.php';

use DataMachine\Cli\JobLivenessClassifier;

$failed = 0;
$total  = 0;
$assert = static function ( string $label, bool $condition ) use ( &$failed, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "  [PASS] {$label}\n";
		return;
	}
	++$failed;
	echo "  [FAIL] {$label}\n";
};

$now = strtotime( '2026-07-22 12:00:00 UTC' );
$job = static fn( array $engine = array() ): array => array(
	'job_id'     => 42,
	'flow_id'    => 7,
	'pipeline_id' => 3,
	'created_at' => '2026-07-22 08:00:00',
	'engine_data' => $engine,
);
$action = static fn( string $status, string $scheduled, array $args = array(), string $hook = 'datamachine_execute_step', int $action_id = 0 ): array => array(
	'action_id'          => $action_id,
	'hook'               => $hook,
	'status'             => $status,
	'scheduled_date_gmt' => $scheduled,
	'last_attempt_gmt'   => '0000-00-00 00:00:00',
	'decoded_args'       => array_merge( array( 'job_id' => 42, 'flow_step_id' => 'step' ), $args ),
);
$classify = static function ( array $job_row, array $actions = array(), array $children = array() ) use ( $now ): array {
	return JobLivenessClassifier::diagnose( $job_row, $actions, $children, 120, $now );
};

echo "=== job-liveness-cli-smoke ===\n";
$active = $classify( $job(), array( $action( 'in-progress', '2026-07-22 11:30:00' ) ) );
$assert( 'fresh running action is active processing', 'active_processing' === $active['classification'] );

$stale = $classify( $job(), array( $action( 'in-progress', '2026-07-22 09:00:00' ) ) );
$assert( 'overdue running action is stale in progress', 'stale_in_progress' === $stale['classification'] );

$starved = $classify( $job(), array( $action( 'pending', '2026-07-22 09:00:00' ) ) );
$assert( 'overdue pending action is scheduler starved', 'scheduler_starved' === $starved['classification'] );

$deferred_job = $job(
	array(
		'ai_concurrency_throttle' => array(
			'state'             => 'deferred',
			'attempts'          => 8,
			'provider'          => 'openai',
			'first_deferred_at' => '2026-07-22T11:00:00Z',
		),
		'ai_concurrency_resume_ownership' => array(
			'generation'   => 3,
			'status'       => 'scheduled',
			'flow_step_id' => 'step',
			'action_id'    => 99,
		),
	)
);
$deferred = $classify( $deferred_job, array( $action( 'pending', '2026-07-22 11:50:00', array( 'ai_resume_generation' => 3 ), 'datamachine_resume_ai_step', 99 ) ) );
$assert( 'fresh resume action with throttle is concurrency deferred', 'ai_concurrency_deferred' === $deferred['classification'] );
$assert( 'deferred metrics expose count and age', 8 === $deferred['defer_count'] && 3600 === $deferred['defer_age_seconds'] );
$assert( 'deferred contention is active', true === $deferred['contention_active'] );

$queued = $classify( $job(), array( $action( 'pending', '2026-07-22 11:50:00' ) ) );
$assert( 'fresh pending action without throttle is queued', 'queued_next_step' === $queued['classification'] );

$waiting = $classify( $job( array( 'batch' => true, 'batch_total' => 3 ) ), array(), array( 'active' => 1, 'total' => 2 ) );
$assert( 'active batch children classify as waiting', 'waiting_children' === $waiting['classification'] );

$resolved = $classify( $job( array( 'ai_concurrency_history' => array( array( 'state' => 'resolved' ) ) ) ) );
$assert( 'resolved history does not report active contention', false === $resolved['contention_active'] );
$assert( 'job without active scheduler path is classified directly', 'no_scheduler_path' === $resolved['classification'] );

$stale_generation = $classify(
	array_merge( $job(), array( 'operation_state' => 'enqueued', 'operation_generation' => 4, 'operation_claim_token' => 'current' ) ),
	array( $action( 'pending', '2026-07-22 11:50:00', array( 'operation_generation' => 3, 'operation_claim_token' => 'stale' ) ) )
);
$assert( 'stale operation generation is not a liveness path', 'no_scheduler_path' === $stale_generation['classification'] );

$recovery_engine = array(
	'scheduler_recovery' => array(
		'state'      => 'requeued',
		'token'      => 'current-recovery',
		'generation' => 8,
		'receipt'    => array( 'generation' => 8, 'action_id' => 108 ),
	),
);
$valid_recovery = $classify( $job( $recovery_engine ), array( $action( 'pending', '2026-07-22 11:50:00', array( 'recovery_generation' => 8, 'recovery_claim_token' => 'current-recovery' ), 'datamachine_execute_step', 108 ) ) );
$stale_recovery = $classify( $job( $recovery_engine ), array( $action( 'pending', '2026-07-22 11:50:00', array( 'recovery_generation' => 7, 'recovery_claim_token' => 'stale-recovery' ), 'datamachine_execute_step', 107 ) ) );
$assert( 'current recovery generation is a liveness path', 'queued_next_step' === $valid_recovery['classification'] );
$assert( 'stale recovery generation is not a liveness path', 'no_scheduler_path' === $stale_recovery['classification'] );

$invalid_receipt_engine = $recovery_engine;
$invalid_receipt_engine['scheduler_recovery']['receipt']['action_id'] = 0;
$invalid_receipt = $classify( $job( $invalid_receipt_engine ), array( $action( 'pending', '2026-07-22 11:50:00', array( 'recovery_generation' => 8, 'recovery_claim_token' => 'current-recovery' ), 'datamachine_execute_step', 108 ) ) );
$assert( 'zero action receipt is not a liveness path', 'no_scheduler_path' === $invalid_receipt['classification'] );

echo "\nJob liveness CLI smoke complete: {$total} assertions, {$failed} failures.\n";
exit( $failed > 0 ? 1 : 0 );
