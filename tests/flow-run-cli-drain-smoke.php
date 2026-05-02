<?php
/**
 * Pure-PHP smoke test for CLI flow-run Action Scheduler draining (#1374/#1719).
 *
 * Run with: php tests/flow-run-cli-drain-smoke.php
 *
 * @package DataMachine\Tests
 */

$flows_file = __DIR__ . '/../inc/Cli/Commands/Flows/FlowsCommand.php';
$drain_file = __DIR__ . '/../inc/Cli/Commands/DrainCommand.php';
$boot_file  = __DIR__ . '/../inc/Cli/Bootstrap.php';
$src        = file_get_contents( $flows_file ) ?: '';
$drain_src  = file_get_contents( $drain_file ) ?: '';
$boot_src   = file_get_contents( $boot_file ) ?: '';

$assertions = 0;

function assert_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_drain_contains( string $needle, string $haystack, string $message ): void {
	assert_true( false !== strpos( $haystack, $needle ), $message );
}

function assert_drain_not_contains( string $needle, string $haystack, string $message ): void {
	assert_true( false === strpos( $haystack, $needle ), $message );
}

assert_drain_contains( '[--[no-]drain]', $src, 'flow run usage documents WP-CLI-compatible drain toggle' );
assert_drain_contains( 'get_flag_value( $assoc_args, \'drain\', true )', $src, 'immediate runs drain by default and accepts --no-drain' );
assert_drain_contains( 'if ( $drain ) {', $src, 'drain is gated by the CLI flag' );
assert_drain_contains( 'DrainCommand::drain()', $src, 'immediate run calls first-class DM drain loop' );
assert_drain_contains( "WP_CLI::add_command( 'datamachine drain'", $boot_src, 'first-class datamachine drain command is registered' );
assert_drain_contains( "datamachine_pipeline_batch_chunk'", $drain_src, 'drain includes pipeline batch chunk hook' );
assert_drain_contains( "datamachine_execute_step'", $drain_src, 'drain includes execute step hook' );
assert_drain_contains( 'getDuePendingActionIds', $drain_src, 'drain queries concrete due Data Machine action IDs' );
assert_drain_contains( 'action-scheduler action run ', $drain_src, 'drain runs concrete action IDs instead of generic queue runner' );
assert_drain_contains( "'exit_error' => false", $drain_src, 'drain failure is surfaced as warning instead of fataling after job start' );
assert_drain_contains( "'return'     => 'all'", $drain_src, 'drain captures Action Scheduler command result' );
assert_drain_contains( "'remaining_pending'", $drain_src, 'drain reports remaining pending actions' );
assert_drain_contains( "'batch_chunks'", $drain_src, 'drain reports batch chunk counts' );
assert_drain_contains( "'step_executions'", $drain_src, 'drain reports step execution counts' );
assert_drain_contains( "'completions'", $drain_src, 'drain reports completions' );
assert_drain_contains( "'failures'", $drain_src, 'drain reports failures' );

$run_flow_start = strpos( $src, 'private function runFlow' );
assert_true( false !== $run_flow_start, 'runFlow method found' );

$run_flow_offset = false === $run_flow_start ? 0 : $run_flow_start;
$timestamp_path  = strpos( $src, '// Delayed execution', $run_flow_offset );
$immediate_path  = strpos( $src, '// Immediate execution', $run_flow_offset );
$drain_call      = strpos( $src, 'DrainCommand::drain()', $run_flow_offset );

assert_true( false !== $timestamp_path, 'delayed scheduling path found' );
assert_true( false !== $immediate_path, 'immediate execution path found' );
assert_true( false !== $drain_call, 'drain call found in runFlow' );
assert_true( $drain_call > $immediate_path, 'drain runs only after immediate execution starts jobs' );
assert_true( $drain_call > $timestamp_path, 'timestamp scheduling returns before the drain call' );

$helper_start = strpos( $drain_src, 'public static function drain' );
assert_true( false !== $helper_start, 'drain helper start found' );

$helper_offset = false === $helper_start ? 0 : $helper_start;
$helper_src    = substr( $drain_src, $helper_offset );
assert_drain_not_contains( 'datamachine_run_flow_now', $helper_src, 'drain does not run scheduled flow-trigger actions' );

echo "OK ({$assertions} assertions)\n";
