<?php
/** Static contract checks for bounded, explicitly scoped recovery apply. */

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

$ability = file_get_contents( __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php' ) ?: '';
$worker  = file_get_contents( __DIR__ . '/../inc/Cli/Commands/WorkerCommand.php' ) ?: '';
$jobs    = file_get_contents( __DIR__ . '/../inc/Core/Database/Jobs/Jobs.php' ) ?: '';
$cli     = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' ) ?: '';
$execute = file_get_contents( __DIR__ . '/../inc/Abilities/Engine/ExecuteStepAbility.php' ) ?: '';

echo "=== recover-stuck-bounded-apply-smoke ===\n";
$assert( 'ability exposes exact job scope and hard touch limit', str_contains( $ability, "'job_id'" ) && str_contains( $ability, 'MAX_APPLY_LIMIT' ) && str_contains( $ability, '$touched >= $apply_limit' ) && str_contains( $ability, 'consumeTouchBudget' ) );
$assert( 'pathless apply requires explicit policy', str_contains( $ability, 'recover_pathless_children' ) && str_contains( $ability, 'pathless_child_apply_policy_required' ) );
$assert( 'one compound job defaults and documents three touches', str_contains( $ability, 'DEFAULT_APPLY_LIMIT   = 3' ) && str_contains( $cli, '--recover-pathless-children --limit=3' ) );
$assert( 'compound touches reserve capacity before claim or queue restoration', str_contains( $ability, 'hasTouchCapacity' ) && str_contains( $ability, 'queued_prompt_backup' ) && str_contains( $ability, '$required_touches' ) );
$assert( 'worker runs recovery once with bounded mutations', str_contains( $worker, '! $recovery_ran' ) && str_contains( $worker, "'limit'         => 5") );
$assert( 'worker does not auto-apply historical children', str_contains( $worker, "'recover_pathless_children' => false") );
$assert( 'worker reports every recovery disposition', str_contains( $worker, 'pathless_children_requeued' ) && str_contains( $worker, 'pathless_children_terminal' ) && str_contains( $worker, 'recovery_claim_conflicts' ) && str_contains( $worker, 'pathless_policy_skipped' ) && str_contains( $worker, 'recovery_mutations' ) && str_contains( $worker, 'recovery_requeued' ) && str_contains( $worker, 'recovery_skipped' ) && str_contains( $worker, 'recovery_attempted' ) && str_contains( $worker, 'recovery_touched' ) && str_contains( $worker, 'recovery_mutated' ) );
$assert( 'requeue schedule and receipt share locked transaction', str_contains( $jobs, 'commit_recovery_owned_requeue' ) && str_contains( $jobs, 'get_job_for_update' ) && str_contains( $jobs, "'receipt_commit_failed'") );
$assert( 'terminal recovery validates locked owner', str_contains( $jobs, 'transition_recovery_owned_child' ) && str_contains( $jobs, 'recovery_owner_matches' ) );
$assert( 'locked owner is renewed before side effects', str_contains( $jobs, 'renew_recovery_owner_on_locked_job' ) && str_contains( $jobs, 'RECOVERY_LEASE_TTL' ) );
$assert( 'execution generation renews immediately before Step execute', str_contains( $execute, 'renew_recovery_execution_owner' ) && strpos( $execute, 'renew_recovery_execution_owner' ) < strpos( $execute, '$flow_step->execute' ) );
$assert( 'post-execution and route fences remain active', str_contains( $execute, 'was superseded during execution' ) && str_contains( $execute, 'was superseded before next-step scheduling' ) && str_contains( $execute, 'transitionTerminalWithRecoveryFence' ) );
$assert( 'atomic requeue mirrors run lifecycle', str_contains( $jobs, '$engine[\'run_lifecycle\']' ) && str_contains( $jobs, '$run_lifecycle[\'status\']' ) );
$assert( 'action scan keysets exact numeric boundaries before decode', str_contains( $ability, 'action_id < %d' ) && str_contains( $ability, '$like_job_comma' ) && str_contains( $ability, '$like_job_end' ) && str_contains( $ability, 'actionBelongsToJob' ) );
$assert( 'claim policy includes exact receipt action beyond history cap', str_contains( $ability, '$required_action_id' ) && str_contains( $ability, "['receipt']" ) && str_contains( $ability, 'canClaimNextGeneration' ) );
$assert( 'CLI prints scope and ownership evidence', str_contains( $cli, 'Recovery scope:' ) && str_contains( $cli, 'recovery_lease_age_seconds' ) && str_contains( $cli, 'receipt_state' ) );

echo "\nBounded recovery apply smoke complete: {$total} assertions, {$failed} failures.\n";
exit( $failed > 0 ? 1 : 0 );
