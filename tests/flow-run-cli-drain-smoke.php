<?php
/**
 * Pure-PHP smoke test for CLI flow-run Action Scheduler draining (#1374/#1719).
 *
 * Run with: php tests/flow-run-cli-drain-smoke.php
 *
 * @package DataMachine\Tests
 */

$flows_file   = __DIR__ . '/../inc/Cli/Commands/Flows/FlowsCommand.php';
$drain_file   = __DIR__ . '/../inc/Cli/Commands/DrainCommand.php';
$service_file = __DIR__ . '/../inc/Core/ActionScheduler/ScopedDrainService.php';
$boot_file    = __DIR__ . '/../inc/Cli/CommandRegistry.php';
$src          = file_get_contents( $flows_file ) ?: '';
$drain_src    = file_get_contents( $drain_file ) ?: '';
$service_src  = file_get_contents( $service_file ) ?: '';
$boot_src     = file_get_contents( $boot_file ) ?: '';

$assertions = 0;

function assert_true( bool $condition, string $message ): void {
	global $assertions;
	++$assertions;
	if ( ! $condition ) {
		fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$message}\n" );
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
assert_drain_contains( "'datamachine drain'", $boot_src, 'first-class datamachine drain command is registered' );
assert_drain_contains( 'new ScopedDrainService()', $drain_src, 'CLI drain delegates scoped Action Scheduler work to the shared drain service' );
assert_drain_contains( 'ScopedDrainService::HOOK_BATCH_CHUNK', $drain_src, 'CLI drain exposes the shared batch chunk hook constant' );
assert_drain_contains( 'ScopedDrainService::HOOK_EXECUTE_STEP', $drain_src, 'CLI drain exposes the shared execute step hook constant' );
assert_drain_contains( "datamachine_pipeline_batch_chunk'", $service_src, 'shared drain service includes pipeline batch chunk hook' );
assert_drain_contains( "datamachine_execute_step'", $service_src, 'shared drain service includes execute step hook' );
assert_drain_contains( '[--job-id=<ids>]', $drain_src, 'drain documents optional job-id scope' );
assert_drain_contains( '[--lane=<lane>]', $drain_src, 'drain documents optional lane scope' );
assert_drain_contains( '[--stop-before-timeout=<seconds>]', $drain_src, 'drain documents optional timeout safety margin' );
assert_drain_contains( "'stop_before_timeout'", $drain_src, 'drain accepts worker-style timeout safety margin option' );
assert_drain_contains( 'WorkerLock::acquire', $drain_src, 'drain acquires shared worker/drain lock' );
assert_drain_contains( 'WorkerLock::release', $drain_src, 'drain releases shared worker/drain lock' );
assert_drain_contains( 'register_shutdown_function', $drain_src, 'drain releases locks during PHP shutdown after fatals' );
assert_drain_contains( "'stop_reason'                => 'locked'", $service_src, 'drain exits cleanly when lock is held' );
assert_drain_contains( 'lock_age_seconds', $drain_src, 'drain reports lock age' );
assert_drain_contains( 'lock_owner', $drain_src, 'drain reports lock owner' );
assert_drain_contains( 'normalizeJobIds', $drain_src, 'drain normalizes optional job-id scope before locking' );
assert_drain_contains( 'hookWhereSql( $hooks, $job_ids )', $service_src, 'drain supports optional hook and job-id scopes' );
assert_drain_contains( 'a.args LIKE %s', $service_src, 'drain can filter pending actions by serialized job_id args' );
assert_drain_contains( '"parent_job_id":', $service_src, 'drain can filter batch actions by serialized parent_job_id args' );
assert_drain_contains( "a.status = \'pending\'", $service_src, 'drain queries pending actions in the Data Machine group' );
assert_drain_contains( 'GroupRegistrar::ensureDataMachineGroup()', $service_src, 'drain ensures Action Scheduler can resolve the Data Machine group before claiming' );
assert_drain_contains( 'runActionSchedulerTimeoutCleanup( $store )', $service_src, 'drain resets stale Action Scheduler claims before claiming work' );
assert_drain_contains( 'stake_claim( $claim_size, null, $hooks ?? array(), self::GROUP )', $service_src, 'drain claims due Data Machine actions through Action Scheduler' );
assert_drain_contains( 'claimSizeForScope( $batch_size, $hooks, $job_ids, $lane )', $service_src, 'drain sizes claims using the requested job and lane scope' );
assert_drain_contains( 'claimSizeThroughFirstJobAction', $service_src, 'job-scoped drain claims through the first matching job action' );
assert_drain_contains( 'ORDER BY a.scheduled_date_gmt ASC, a.priority ASC, a.action_id ASC', $service_src, 'job-scoped drain follows Action Scheduler due-order claim semantics' );
assert_drain_contains( 'actionMatchesLane', $service_src, 'drain filters claimed actions by lane when requested' );
assert_drain_contains( 'stepTypeFromExecuteStepArgs', $service_src, 'drain resolves publish lane from execute-step step type' );
assert_drain_contains( '$deadline_at = $started_at + max( 0, $time_limit_ms - $stop_before_timeout_ms ) / 1000', $service_src, 'drain computes an action-level deadline for claimed batches' );
assert_drain_contains( 'microtime( true ) >= $deadline_at', $service_src, 'drain checks the timeout margin between claimed actions' );
assert_drain_contains( 'find_actions_by_claim_id( $claim->get_id() )', $service_src, 'drain verifies Action Scheduler claim ownership before processing' );
assert_drain_contains( 'release_claim( $claim )', $service_src, 'drain releases Action Scheduler claims after processing' );
assert_drain_contains( "\\ActionScheduler::runner()", $service_src, 'drain uses Action Scheduler runner for claimed actions' );
assert_drain_contains( "'Data Machine CLI drain'", $drain_src, 'drain records a Data Machine-specific execution context' );
assert_drain_contains( 'catch ( \\Throwable $throwable )', $service_src, 'drain catches per-action runner failures instead of fataling after job start' );
assert_drain_contains( 'flushRuntimeCache()', $service_src, 'drain flushes runtime cache after processing actions' );
assert_drain_contains( 'isMemorySoftLimitReached()', $service_src, 'drain stops before hard PHP memory exhaustion' );
assert_drain_contains( 'ensureCliMemoryLimit()', $drain_src, 'drain raises the CLI memory floor before processing large batches' );
assert_drain_contains( "'memory_limit'", $service_src, 'drain reports memory-limit stop reason' );
assert_drain_contains( "'return_code'       => empty( \$warnings ) ? 0 : 1", $service_src, 'drain surfaces runner failures through a result object' );
assert_drain_contains( "'actions_processed' => \$processed", $service_src, 'drain reports lane-filtered actions processed by the current batch' );
assert_drain_contains( "\$progress     = (int) ( \$result['actions_processed']", $service_src, 'drain judges lane progress from actions it processed, not unrelated lane deltas' );
assert_drain_contains( "'remaining_pending'", $service_src, 'drain reports remaining pending actions' );
assert_drain_contains( 'getDuePendingCount( $hooks, $job_ids, $lane )', $service_src, 'drain reports lane-scoped remaining pending actions' );
assert_drain_contains( 'getPendingCount( $hooks, $job_ids, $lane )', $service_src, 'drain reports lane-scoped total pending actions' );
assert_drain_contains( "'batch_chunks'", $service_src, 'drain reports batch chunk counts' );
assert_drain_contains( "'step_executions'", $service_src, 'drain reports step execution counts' );
assert_drain_contains( "'other_actions'", $service_src, 'drain reports non-pipeline action counts' );
assert_drain_contains( "'completions'", $service_src, 'drain reports completions' );
assert_drain_contains( "'failures'", $service_src, 'drain reports failures' );
assert_drain_contains( "'stop_reason'", $service_src, 'drain reports why it stopped' );
assert_drain_contains( "'timeout_margin'", $service_src, 'drain reports timeout margin stops' );

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
