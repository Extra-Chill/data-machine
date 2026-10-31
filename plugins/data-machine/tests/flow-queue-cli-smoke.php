<?php
/**
 * Pure-PHP smoke test for the Data Machine flow queue CLI command.
 *
 * Run with: php tests/flow-queue-cli-smoke.php
 *
 * @package DataMachine\Tests
 */

$queue_file = __DIR__ . '/../inc/Cli/Commands/Flows/QueueCommand.php';
$queue_src  = file_get_contents( $queue_file ) ?: '';

$assertions = 0;

function assert_flow_queue_cli_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_flow_queue_cli_contains( string $needle, string $haystack, string $message ): void {
	assert_flow_queue_cli_true( false !== strpos( $haystack, $needle ), $message );
}

assert_flow_queue_cli_contains( '[--dry-run]', $queue_src, 'clear command documents dry-run support' );
assert_flow_queue_cli_contains( "! empty( \$assoc_args['dry-run'] )", $queue_src, 'clear command branches before mutation on dry-run' );
assert_flow_queue_cli_contains( "'datamachine/config-patch-list'", $queue_src, 'fetch dry-runs inspect the patch queue' );
assert_flow_queue_cli_contains( "'datamachine/queue-list'", $queue_src, 'prompt dry-runs inspect the prompt queue' );
assert_flow_queue_cli_contains( 'Would clear %d item(s) from queue.', $queue_src, 'dry-run reports the clear count' );

$dry_run_pos = strpos( $queue_src, "! empty( \$assoc_args['dry-run'] )" );
$clear_pos   = strpos( $queue_src, "wp_get_ability( \$ability_id )->execute" );

assert_flow_queue_cli_true( false !== $dry_run_pos && false !== $clear_pos && $dry_run_pos < $clear_pos, 'dry-run check happens before clear ability execution' );

echo "OK ({$assertions} assertions)\n";
