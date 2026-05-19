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

assert_drain_contains( '[--[no-]drain]', $src, 'flow run usage documents WP-CLI-compatible drain toggle' );
assert_drain_contains( 'get_flag_value( $assoc_args, \'drain\', true )', $src, 'immediate runs drain by default and accepts --no-drain' );
assert_drain_contains( 'if ( $drain ) {', $src, 'drain is gated by the CLI flag' );
assert_drain_contains( 'DrainCommand::drain(', $src, 'immediate run calls first-class DM drain loop' );
assert_drain_contains( "'hooks' => array(", $src, 'immediate flow run scopes its internal drain' );
assert_drain_contains( 'DrainCommand::HOOK_BATCH_CHUNK', $src, 'immediate flow run drains pipeline batch chunks' );
assert_drain_contains( 'DrainCommand::HOOK_EXECUTE_STEP', $src, 'immediate flow run drains pipeline steps' );
assert_drain_contains( "WP_CLI::add_command( 'datamachine drain'", $boot_src, 'first-class datamachine drain command is registered' );
assert_drain_contains( "datamachine_pipeline_batch_chunk'", $drain_src, 'drain includes pipeline batch chunk hook' );
assert_drain_contains( "datamachine_execute_step'", $drain_src, 'drain includes execute step hook' );
assert_drain_contains( '[--job-id=<ids>]', $drain_src, 'drain documents optional job-id scope' );
assert_drain_contains( 'normalizeJobIds', $drain_src, 'drain normalizes optional job-id scope' );
assert_drain_contains( 'hookWhereSql( $hooks, $job_ids )', $drain_src, 'drain supports optional hook and job-id scopes' );
assert_drain_contains( 'a.args LIKE %s', $drain_src, 'drain can filter pending actions by serialized job_id args' );
assert_drain_contains( '"parent_job_id":', $drain_src, 'drain can filter batch actions by serialized parent_job_id args' );
assert_drain_contains( "a.status = \\'pending\\'", $drain_src, 'drain queries pending actions in the Data Machine group' );
assert_drain_contains( 'getDuePendingActionIds', $drain_src, 'drain queries concrete due Data Machine action IDs' );
assert_drain_contains( "\\ActionScheduler::runner()", $drain_src, 'drain runs concrete action IDs through Action Scheduler runner' );
assert_drain_contains( "'Data Machine CLI drain'", $drain_src, 'drain records a Data Machine-specific execution context' );
assert_drain_contains( 'catch ( \\Throwable $throwable )', $drain_src, 'drain catches per-action runner failures instead of fataling after job start' );
assert_drain_contains( "'return_code' => empty( \$warnings ) ? 0 : 1", $drain_src, 'drain surfaces runner failures through a result object' );
assert_drain_contains( "'remaining_pending'", $drain_src, 'drain reports remaining pending actions' );
assert_drain_contains( "'batch_chunks'", $drain_src, 'drain reports batch chunk counts' );
assert_drain_contains( "'step_executions'", $drain_src, 'drain reports step execution counts' );
assert_drain_contains( "'other_actions'", $drain_src, 'drain reports non-pipeline action counts' );
assert_drain_contains( "'completions'", $drain_src, 'drain reports completions' );
assert_drain_contains( "'failures'", $drain_src, 'drain reports failures' );

$run_flow_start = strpos( $src, 'private function runFlow' );
assert_true( false !== $run_flow_start, 'runFlow method found' );

$run_flow_offset = false === $run_flow_start ? 0 : $run_flow_start;
$timestamp_path  = strpos( $src, '// Delayed execution', $run_flow_offset );
$immediate_path  = strpos( $src, '// Immediate execution', $run_flow_offset );
$drain_call      = strpos( $src, 'DrainCommand::drain(', $run_flow_offset );

assert_true( false !== $timestamp_path, 'delayed scheduling path found' );
assert_true( false !== $immediate_path, 'immediate execution path found' );
assert_true( false !== $drain_call, 'drain call found in runFlow' );
assert_true( $drain_call > $immediate_path, 'drain runs only after immediate execution starts jobs' );
assert_true( $drain_call > $timestamp_path, 'timestamp scheduling returns before the drain call' );

echo "OK ({$assertions} assertions)\n";
