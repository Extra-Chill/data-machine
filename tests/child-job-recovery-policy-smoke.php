<?php
/** Behavioral tests for pathless child recovery policy. */

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../inc/Core/ChildJobRecoveryPolicy.php';

use DataMachine\Core\ChildJobRecoveryPolicy;

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

$now = strtotime( '2026-07-22 20:00:00 UTC' );
$job = array(
	'job_id'              => 42,
	'parent_job_id'       => 7,
	'operation_state'     => '',
	'operation_generation' => 0,
	'operation_claim_token' => '',
);
$step = 'pipeline-step-2';
$engine = array( 'flow_config' => array( $step => array( 'step_type' => 'upsert' ) ) );
$action = static fn( int $id, string $hook, string $status, array $args ): array => array(
	'action_id'         => $id,
	'hook'              => $hook,
	'status'            => $status,
	'scheduled_date_gmt' => '2026-07-22 19:55:00',
	'last_attempt_gmt'  => '2026-07-22 19:56:00',
	'decoded_args'      => $args,
);
$diagnose = static fn( array $job_row, array $engine_data, array $actions ): array => ChildJobRecoveryPolicy::diagnose( $job_row, $engine_data, $actions, 7200, $now );

echo "=== child-job-recovery-policy-smoke ===\n";

$failed_action = $diagnose( $job, $engine, array( $action( 10, 'datamachine_execute_step', 'failed', array( 'job_id' => 42, 'flow_step_id' => $step ) ) ) );
$assert( 'failed canonical action is replayable', $failed_action['retry_eligible'] && ! $failed_action['has_active_path'] );

$missing = $diagnose( $job, $engine, array() );
$assert( 'missing action requires terminal transition', 'missing_action' === $missing['reason'] && ! $missing['retry_eligible'] );

$stale_generation_job = array_merge( $job, array( 'operation_state' => 'enqueued', 'operation_generation' => 2, 'operation_claim_token' => 'current' ) );
$stale_action = $action( 11, 'datamachine_execute_step', 'pending', array( 'job_id' => 42, 'flow_step_id' => $step, 'operation_generation' => 1, 'operation_claim_token' => 'old' ) );
$stale = $diagnose( $stale_generation_job, $engine, array( $stale_action ) );
$assert( 'stale operation action is not a valid path', ! $stale['has_active_path'] && ! $stale['retry_eligible'] );

$ai_engine = $engine + array(
	'ai_concurrency_resume_ownership' => array(
		'flow_step_id' => $step,
		'generation'   => 3,
		'status'       => 'scheduled',
		'action_id'    => 12,
	),
);
$ai = $diagnose( $job, $ai_engine, array( $action( 12, 'datamachine_resume_ai_step', 'pending', array( 'job_id' => 42, 'flow_step_id' => $step, 'ai_resume_generation' => 3 ) ) ) );
$assert( 'active AI resume generation remains owned', $ai['has_active_path'] && ! $ai['retry_eligible'] );

$wrong_ai = $diagnose( $job, $ai_engine, array( $action( 13, 'datamachine_resume_ai_step', 'pending', array( 'job_id' => 42, 'flow_step_id' => $step, 'ai_resume_generation' => 2 ) ) ) );
$assert( 'historical AI generation is irrelevant', ! $wrong_ai['has_active_path'] );

$batch_child = $diagnose( $job, $engine, array( $action( 14, 'datamachine_execute_step', 'pending', array( 'job_id' => 42, 'flow_step_id' => $step ) ) ) );
$assert( 'batch child with pending canonical action remains guarded', $batch_child['has_active_path'] );

$assert( 'job 42 does not own job 420 action args', ! ChildJobRecoveryPolicy::actionBelongsToJob( array( 'job_id' => 420 ), 42 ) );
$assert( 'job 42 owns only exact decoded args', ChildJobRecoveryPolicy::actionBelongsToJob( array( 'job_id' => 42 ), 42 ) );

echo "\nChild job recovery policy smoke complete: {$total} assertions, {$failed} failures.\n";
exit( $failed > 0 ? 1 : 0 );
