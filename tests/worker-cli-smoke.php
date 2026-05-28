<?php
/**
 * Pure-PHP smoke test for the Data Machine worker CLI command.
 *
 * Run with: php tests/worker-cli-smoke.php
 *
 * @package DataMachine\Tests
 */

$worker_file = __DIR__ . '/../inc/Cli/Commands/WorkerCommand.php';
$boot_file   = __DIR__ . '/../inc/Cli/Bootstrap.php';
$lock_file   = __DIR__ . '/../inc/Cli/WorkerLock.php';
$worker_src  = file_get_contents( $worker_file ) ?: '';
$boot_src    = file_get_contents( $boot_file ) ?: '';
$lock_src    = file_get_contents( $lock_file ) ?: '';

$assertions = 0;

function assert_worker_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_worker_contains( string $needle, string $haystack, string $message ): void {
	assert_worker_true( false !== strpos( $haystack, $needle ), $message );
}

function assert_worker_not_contains( string $needle, string $haystack, string $message ): void {
	assert_worker_true( false === strpos( $haystack, $needle ), $message );
}

assert_worker_contains( "WP_CLI::add_command( 'datamachine worker'", $boot_src, 'worker command is registered' );
assert_worker_contains( 'class WorkerCommand extends BaseCommand', $worker_src, 'worker follows CLI command pattern' );
assert_worker_contains( '@subcommand run', $worker_src, 'worker exposes run subcommand' );
assert_worker_contains( '@subcommand status', $worker_src, 'worker exposes status subcommand' );
assert_worker_contains( 'WorkerLock::acquire', $worker_src, 'worker acquires shared worker/drain lock' );
assert_worker_contains( 'WorkerLock::release', $worker_src, 'worker releases shared worker/drain lock' );
assert_worker_contains( 'register_shutdown_function', $worker_src, 'worker releases locks during PHP shutdown after fatals' );
assert_worker_contains( 'RecoverStuckJobsAbility', $worker_src, 'worker composes stuck job recovery ability' );
assert_worker_contains( 'DrainCommand::drain(', $worker_src, 'worker composes the existing drain loop' );
assert_worker_contains( 'DrainCommand::ensureCliMemoryLimit()', $worker_src, 'worker raises the CLI memory floor before draining' );
assert_worker_contains( "'acquire_lock' => false", $worker_src, 'worker internal drain does not fight outer lock' );
assert_worker_contains( 'PendingActionStore::summary', $worker_src, 'worker reads pending-action gate through the store' );
assert_worker_contains( 'JobsSummaryAbility', $worker_src, 'worker reads job status through the existing summary ability' );
assert_worker_contains( "'compact' => true", $worker_src, 'worker status uses compact job summary' );
assert_worker_contains( 'jobStatusCount', $worker_src, 'worker reads normalized job status buckets' );
assert_worker_contains( 'stop_on_pending_actions', $worker_src, 'worker can stop at approval gates' );
assert_worker_contains( 'max_passes', $worker_src, 'worker supports bounded pass counts' );
assert_worker_contains( 'stop_before_timeout', $worker_src, 'worker exits before external supervisor timeouts' );
assert_worker_contains( 'drain_time_limit', $worker_src, 'worker bounds each drain pass' );
assert_worker_contains( '[--lane=<lane>]', $worker_src, 'worker exposes lane option' );
assert_worker_contains( "'lane'                    => isset( \$assoc_args['lane'] )", $worker_src, 'worker passes lane option into run loop' );
assert_worker_contains( "'lane'         => \$lane", $worker_src, 'worker passes lane option into drain loop' );
assert_worker_contains( "WorkerLock::acquire( self::defaultLockOwner( \$lane )", $worker_src, 'worker acquires lane-scoped lock' );
assert_worker_contains( 'DrainCommand::status(', $worker_src, 'worker reads Action Scheduler state through drain status' );
assert_worker_contains( 'lock_age_seconds', $worker_src, 'worker status reports lock age' );
assert_worker_contains( 'lock_owner', $worker_src, 'worker status reports lock owner' );
assert_worker_contains( 'lock_lane', $worker_src, 'worker status reports lock lane' );
assert_worker_contains( "'stop_reason'              => 'locked'", $worker_src, 'worker exits cleanly when lock is held' );
assert_worker_contains( "'stale' === \$existing['lock_status']", $lock_src, 'worker lock identifies stale lock payloads during acquisition' );
assert_worker_contains( 'delete_option( $option_name )', $lock_src, 'worker lock clears stale option payloads before reclaiming' );
assert_worker_contains( "add_option( \$option_name, \$payload, '', 'no' )", $lock_src, 'worker lock reclaims stale locks through the normal add-option path' );
assert_worker_contains( 'OPTION_NAME . \'_\' . $lane', $lock_src, 'worker lock uses lane-specific option names' );
assert_worker_not_contains( 'action-scheduler action run ', $worker_src, 'worker does not shell directly to Action Scheduler' );
assert_worker_not_contains( 'actionscheduler_actions', $worker_src, 'worker does not query Action Scheduler tables directly' );
assert_worker_not_contains( 'datamachine_jobs', $worker_src, 'worker does not query jobs tables directly' );

echo "OK ({$assertions} assertions)\n";
