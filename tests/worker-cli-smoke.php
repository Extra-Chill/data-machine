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
$worker_src  = file_get_contents( $worker_file ) ?: '';
$boot_src    = file_get_contents( $boot_file ) ?: '';

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
assert_worker_contains( 'RecoverStuckJobsAbility', $worker_src, 'worker composes stuck job recovery ability' );
assert_worker_contains( 'DrainCommand::drain(', $worker_src, 'worker composes the existing drain loop' );
assert_worker_contains( 'PendingActionStore::summary', $worker_src, 'worker reads pending-action gate through the store' );
assert_worker_contains( 'stop_on_pending_actions', $worker_src, 'worker can stop at approval gates' );
assert_worker_contains( 'drain_time_limit', $worker_src, 'worker bounds each drain pass' );
assert_worker_not_contains( 'action-scheduler action run ', $worker_src, 'worker does not shell directly to Action Scheduler' );
assert_worker_not_contains( 'actionscheduler_actions', $worker_src, 'worker does not query Action Scheduler tables directly' );
assert_worker_not_contains( 'datamachine_jobs', $worker_src, 'worker does not query jobs tables directly' );

echo "OK ({$assertions} assertions)\n";
