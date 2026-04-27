<?php
/**
 * Pure-PHP smoke test for CLI flow-run Action Scheduler draining (#1374).
 *
 * Run with: php tests/flow-run-cli-drain-smoke.php
 *
 * @package DataMachine\Tests
 */

$file = __DIR__ . '/../inc/Cli/Commands/Flows/FlowsCommand.php';
$src  = file_get_contents( $file );

$assertions = 0;

function assert_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_contains( string $needle, string $haystack, string $message ): void {
	assert_true( false !== strpos( $haystack, $needle ), $message );
}

function assert_not_contains( string $needle, string $haystack, string $message ): void {
	assert_true( false === strpos( $haystack, $needle ), $message );
}

assert_contains( '[--no-drain]', $src, 'flow run usage documents --no-drain escape hatch' );
assert_contains( '$drain     = ! isset( $assoc_args[\'no-drain\'] );', $src, 'immediate runs drain by default' );
assert_contains( 'if ( $drain ) {', $src, 'drain is gated by the CLI flag' );
assert_contains( '$this->drainDueStepActions();', $src, 'immediate run calls the drain helper' );
assert_contains( 'private function drainDueStepActions(): void', $src, 'drain helper exists' );
assert_contains( 'WP_CLI::runcommand(', $src, 'drain helper reuses WP-CLI command runner' );
assert_contains( 'action-scheduler run --hooks=datamachine_execute_step --quiet', $src, 'drain is scoped to due DM step actions' );
assert_contains( "'exit_error' => false", $src, 'drain failure is surfaced as warning instead of fataling after job start' );
assert_contains( "'return'     => 'all'", $src, 'drain captures Action Scheduler command result' );
assert_contains( 'Drained due Data Machine step actions.', $src, 'successful drain is visible to CLI operator' );

$run_flow_start = strpos( $src, 'private function runFlow' );
$timestamp_path = strpos( $src, '// Delayed execution', $run_flow_start );
$immediate_path = strpos( $src, '// Immediate execution', $run_flow_start );
$drain_call     = strpos( $src, '$this->drainDueStepActions();', $run_flow_start );

assert_true( false !== $run_flow_start, 'runFlow method found' );
assert_true( false !== $timestamp_path, 'delayed scheduling path found' );
assert_true( false !== $immediate_path, 'immediate execution path found' );
assert_true( false !== $drain_call, 'drain call found in runFlow' );
assert_true( $drain_call > $immediate_path, 'drain runs only after immediate execution starts jobs' );
assert_true( $drain_call > $timestamp_path, 'timestamp scheduling returns before the drain call' );

$helper_src = substr( $src, strpos( $src, 'private function drainDueStepActions(): void' ) );
assert_not_contains( 'datamachine_run_flow_now', $helper_src, 'drain does not run scheduled flow-trigger actions' );

echo "OK ({$assertions} assertions)\n";
