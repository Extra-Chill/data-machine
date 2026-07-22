<?php
/**
 * Static smoke test for job liveness CLI diagnostics.
 *
 * Run with: php tests/job-liveness-cli-smoke.php
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

function assert_job_liveness_cli_smoke( string $name, bool $condition, string $detail = '' ): void {
	global $failed, $total;
	++$total;

	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	echo "  [FAIL] {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
	++$failed;
}

$source = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' ) ?: '';

echo "Case 1: liveness command is registered and read-only\n";
assert_job_liveness_cli_smoke( 'liveness subcommand exists', str_contains( $source, '@subcommand liveness' ) );
assert_job_liveness_cli_smoke( 'diagnostic queries processing jobs and pending contention', str_contains( $source, "status = 'processing' OR (status = 'pending'" ) && str_contains( $source, "$.ai_concurrency_throttle" ) );
assert_job_liveness_cli_smoke( 'diagnostic reads execute-step actions', str_contains( $source, 'datamachine_execute_step' ) );
assert_job_liveness_cli_smoke( 'diagnostic reads pipeline batch actions', str_contains( $source, 'PipelineBatchScheduler::BATCH_HOOK' ) );
assert_job_liveness_cli_smoke( 'diagnostic does not update database rows', ! str_contains( strstr( $source, 'public function liveness' ) ?: '', '$wpdb->update(' ) );

echo "Case 2: liveness classification exposes scheduler starvation\n";
assert_job_liveness_cli_smoke( 'scheduler-starved classification exists', str_contains( $source, "'scheduler_starved'") );
assert_job_liveness_cli_smoke( 'queued-next-step classification exists', str_contains( $source, "'queued_next_step'") );
assert_job_liveness_cli_smoke( 'waiting-children classification exists', str_contains( $source, "'waiting_children'") );
assert_job_liveness_cli_smoke( 'stale-in-progress classification exists', str_contains( $source, "'stale_in_progress'") );
assert_job_liveness_cli_smoke( 'no-scheduler-path classification exists', str_contains( $source, "'no_scheduler_path'") );
assert_job_liveness_cli_smoke( 'AI concurrency deferral classification exists', str_contains( $source, "'ai_concurrency_deferred'") );
assert_job_liveness_cli_smoke( 'contention metrics include count and age', str_contains( $source, "'defer_count'") && str_contains( $source, "'defer_age_seconds'") );
assert_job_liveness_cli_smoke( 'overdue threshold is configurable', str_contains( $source, '[--overdue-minutes=<minutes>]' ) );

echo "Case 3: structured output carries summary and jobs\n";
assert_job_liveness_cli_smoke( 'json example exists', str_contains( $source, 'jobs liveness --overdue-minutes=120 --format=json' ) );
assert_job_liveness_cli_smoke( 'structured output includes summary', str_contains( $source, "'summary'         => $" . 'summary' ) );
assert_job_liveness_cli_smoke( 'structured output includes jobs', str_contains( $source, "'jobs'            => $" . 'items' ) );

echo "\nJob liveness CLI smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
