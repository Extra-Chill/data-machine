<?php
/** Deterministic regression for missing direct-operation action recovery. */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../inc/Core/JobStatus.php';
require_once __DIR__ . '/../inc/Core/DirectOperationRecoveryPolicy.php';

use DataMachine\Core\DirectOperationRecoveryPolicy;

$failures = 0;
$assert   = static function ( string $name, bool $condition ) use ( &$failures ): void {
	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}
	++$failures;
	echo "  [FAIL] {$name}\n";
};

$job = array(
	'job_id'                 => 42,
	'flow_id'                => 'direct',
	'status'                 => 'processing',
	'created_at'             => '2026-07-27 10:00:00',
	'operation_state'        => 'enqueued',
	'operation_action_id'    => 9342876,
	'operation_generation'   => 7,
	'operation_claim_token'  => 'current-token',
	'operation_step_id'      => 'ephemeral_step_0',
);

echo "=== direct-operation-missing-action-recovery-smoke ===\n";

$missing = DirectOperationRecoveryPolicy::diagnose( $job, 'none', false );
$assert( 'absent recorded action is recoverable', is_array( $missing ) );
$assert( 'diagnosis preserves current action receipt', 9342876 === (int) ( $missing['action_id'] ?? 0 ) && 7 === (int) ( $missing['generation'] ?? 0 ) );
$assert( 'pending current-generation step wins over recovery', null === DirectOperationRecoveryPolicy::diagnose( $job, 'step', false ) );
$assert( 'pending current-generation AI continuation wins over recovery', null === DirectOperationRecoveryPolicy::diagnose( $job, 'ai_continuation', false ) );
$assert( 'existing recorded action wins over recovery regardless of status', null === DirectOperationRecoveryPolicy::diagnose( $job, 'none', true ) );
$assert( 'stale operation states are not reclaimable', null === DirectOperationRecoveryPolicy::diagnose( array_merge( $job, array( 'operation_state' => 'cancelled' ) ), 'none', false ) );

$evidence = DirectOperationRecoveryPolicy::evidence( $job, $missing, 'requeued_missing_direct_action', 'automatic_worker', 0, strtotime( '2026-07-27 12:00:00 UTC' ) );
$assert( 'evidence records missing action and current generation', 'missing' === $evidence['action_status'] && 9342876 === $evidence['action_id'] && 7 === $evidence['action_generation'] );
$assert( 'evidence records deterministic age and trigger', 7200 === $evidence['job_age_seconds'] && 'automatic_worker' === $evidence['recovery_trigger'] );

$post_effects = array_merge( $job, array( 'operation_effects_begun_at' => '2026-07-27 10:01:00' ) );
$terminal     = DirectOperationRecoveryPolicy::evidence( $post_effects, $missing, 'terminalized_missing_direct_action', 'automatic_worker', 1, strtotime( '2026-07-27 12:00:00 UTC' ) );
$assert( 'post-effects evidence prohibits replay and accounts for child cleanup', true === $terminal['operation_effects_begun'] && 1 === $terminal['system_children_terminalized'] );

$jobs_source = file_get_contents( __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php' ) ?: '';
$assert( 'requeue schedules and receipts while holding the jobs-row transaction', str_contains( $jobs_source, 'commit_missing_direct_operation_requeue' ) && str_contains( $jobs_source, "query( 'START TRANSACTION' )" ) && str_contains( $jobs_source, '$schedule( $new_generation, $new_token )' ) );
$assert( 'requeue advances generation and restores pending lifecycle', str_contains( $jobs_source, '$new_generation = $generation + 1' ) && str_contains( $jobs_source, "\$run_lifecycle['status']" ) && str_contains( $jobs_source, 'JobStatus::PENDING' ) );
$assert( 'terminal recovery fences the exact recorded action owner', str_contains( $jobs_source, 'missing_direct_operation_owner_matches' ) && str_contains( $jobs_source, "'operation' === \$mode" ) );

echo sprintf( "\nMissing direct-operation recovery smoke complete: %d failures.\n", $failures );
exit( $failures > 0 ? 1 : 0 );
