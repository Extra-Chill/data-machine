<?php
/**
 * Pure-PHP smoke test for queued item restoration on job retry/recovery (#1810).
 *
 * Run with: php tests/job-queue-backup-restore-smoke.php
 *
 * @package DataMachine\Tests
 */

require_once __DIR__ . '/bootstrap-unit.php';

$failed = 0;
$total  = 0;

function assert_job_queue_restore_smoke( string $name, bool $cond, string $detail = '' ): void {
	global $failed, $total;
	++$total;
	if ( $cond ) {
		echo "  [PASS] $name\n";
		return;
	}

	echo "  [FAIL] $name" . ( $detail ? " - $detail" : '' ) . "\n";
	++$failed;
}

class JobQueueBackupRestoreHarness {
	use DataMachine\Abilities\Job\JobHelpers;

	public function apply( array &$flow_config, array $backup ): bool {
		return $this->restoreQueuedPromptBackupToFlowConfig( $flow_config, $backup );
	}
}

$helper = new JobQueueBackupRestoreHarness();

echo "Case 1: loop-mode config_patch_queue backup is not duplicated\n";
$flow_config = array(
	'step1' => array(
		'queue_mode'         => 'loop',
		'config_patch_queue' => array(
			array( 'patch' => array( 'slug' => 'second' ), 'added_at' => 't1' ),
			array( 'patch' => array( 'slug' => 'first' ), 'added_at' => 't0' ),
		),
	),
);
$restored    = $helper->apply(
	$flow_config,
	array(
		'slot'         => DataMachine\Abilities\Flow\QueueAbility::SLOT_CONFIG_PATCH_QUEUE,
		'mode'         => 'loop',
		'patch'        => array( 'slug' => 'first' ),
		'flow_step_id' => 'step1',
		'added_at'     => 't0',
	)
);
assert_job_queue_restore_smoke( 'loop config patch restore returns false', false === $restored );
assert_job_queue_restore_smoke( 'loop config patch queue keeps rotated two entries', 2 === count( $flow_config['step1']['config_patch_queue'] ) );
assert_job_queue_restore_smoke( 'loop config patch queue tail is still the consumed item', 'first' === ( $flow_config['step1']['config_patch_queue'][1]['patch']['slug'] ?? null ) );

echo "Case 2: manual retry and recover-stuck use the shared helper\n";
$retry_src   = file_get_contents( __DIR__ . '/../inc/Abilities/Job/RetryJobAbility.php' ) ?: '';
$recover_src = file_get_contents( __DIR__ . '/../inc/Abilities/Job/RecoverStuckJobsAbility.php' ) ?: '';
assert_job_queue_restore_smoke( 'manual retry calls restoreQueuedPromptBackup()', str_contains( $retry_src, 'restoreQueuedPromptBackup( $job_flow_id, $backup )' ) );
assert_job_queue_restore_smoke( 'recover-stuck calls restoreQueuedPromptBackup()', str_contains( $recover_src, 'restoreQueuedPromptBackup( $job_flow_id, $backup )' ) );
assert_job_queue_restore_smoke( 'manual retry no longer appends queue backups inline', ! str_contains( $retry_src, '$flow_config[ $step_id ][ $slot ][] = $entry;' ) );
assert_job_queue_restore_smoke( 'recover-stuck no longer appends queue backups inline', ! str_contains( $recover_src, '$flow_config[ $step_id ][ $slot ][] = $entry;' ) );

echo "Case 3: drain-mode config_patch_queue backup is restored\n";
$flow_config = array(
	'step1' => array(
		'queue_mode'         => 'drain',
		'config_patch_queue' => array(
			array( 'patch' => array( 'slug' => 'second' ), 'added_at' => 't1' ),
		),
	),
);
$restored    = $helper->apply(
	$flow_config,
	array(
		'slot'         => DataMachine\Abilities\Flow\QueueAbility::SLOT_CONFIG_PATCH_QUEUE,
		'mode'         => 'drain',
		'patch'        => array( 'slug' => 'first' ),
		'flow_step_id' => 'step1',
		'added_at'     => 't0',
	)
);
assert_job_queue_restore_smoke( 'drain config patch restore returns true', true === $restored );
assert_job_queue_restore_smoke( 'drain config patch queue appends consumed item', 2 === count( $flow_config['step1']['config_patch_queue'] ) );
assert_job_queue_restore_smoke( 'drain config patch preserves backup payload', 'first' === ( $flow_config['step1']['config_patch_queue'][1]['patch']['slug'] ?? null ) );

echo "Case 4: prompt_queue follows the same mode semantics\n";
$flow_config = array(
	'step1' => array(
		'queue_mode'    => 'loop',
		'prompt_queue'  => array(
			array( 'prompt' => 'second', 'added_at' => 't1' ),
			array( 'prompt' => 'first', 'added_at' => 't0' ),
		),
	),
);
$restored    = $helper->apply(
	$flow_config,
	array(
		'slot'         => DataMachine\Abilities\Flow\QueueAbility::SLOT_PROMPT_QUEUE,
		'mode'         => 'loop',
		'prompt'       => 'first',
		'flow_step_id' => 'step1',
		'added_at'     => 't0',
	)
);
assert_job_queue_restore_smoke( 'loop prompt restore returns false', false === $restored );
assert_job_queue_restore_smoke( 'loop prompt queue keeps rotated two entries', 2 === count( $flow_config['step1']['prompt_queue'] ) );

$flow_config = array( 'step1' => array( 'queue_mode' => 'drain', 'prompt_queue' => array() ) );
$restored    = $helper->apply(
	$flow_config,
	array(
		'slot'         => DataMachine\Abilities\Flow\QueueAbility::SLOT_PROMPT_QUEUE,
		'mode'         => 'drain',
		'prompt'       => 'first',
		'flow_step_id' => 'step1',
		'added_at'     => 't0',
	)
);
assert_job_queue_restore_smoke( 'drain prompt restore returns true', true === $restored );
assert_job_queue_restore_smoke( 'drain prompt queue appends consumed prompt', 'first' === ( $flow_config['step1']['prompt_queue'][0]['prompt'] ?? null ) );

echo "Case 5: static-mode backups are ignored\n";
$flow_config = array( 'step1' => array( 'queue_mode' => 'static', 'prompt_queue' => array( array( 'prompt' => 'first', 'added_at' => 't0' ) ) ) );
$restored    = $helper->apply(
	$flow_config,
	array(
		'slot'         => DataMachine\Abilities\Flow\QueueAbility::SLOT_PROMPT_QUEUE,
		'mode'         => 'static',
		'prompt'       => 'first',
		'flow_step_id' => 'step1',
	)
);
assert_job_queue_restore_smoke( 'static prompt restore returns false', false === $restored );
assert_job_queue_restore_smoke( 'static prompt queue is unchanged', 1 === count( $flow_config['step1']['prompt_queue'] ) );

echo "\nJob queue backup restore smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
