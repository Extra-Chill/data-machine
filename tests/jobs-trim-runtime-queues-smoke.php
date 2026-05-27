<?php
/**
 * Static smoke test for jobs trim-runtime-queues repair command.
 *
 * Run with: php tests/jobs-trim-runtime-queues-smoke.php
 *
 * @package DataMachine\Tests
 */

$failed = 0;
$total  = 0;

function assert_jobs_trim_runtime_queues_smoke( string $name, bool $condition ): void {
	global $failed, $total;
	++$total;

	if ( $condition ) {
		echo "  [PASS] {$name}\n";
		return;
	}

	echo "  [FAIL] {$name}\n";
	++$failed;
}

$source = file_get_contents( __DIR__ . '/../inc/Cli/Commands/JobsCommand.php' ) ?: '';
$method = strstr( $source, 'public function trim_runtime_queues' ) ?: '';

echo "=== jobs-trim-runtime-queues-smoke ===\n";

assert_jobs_trim_runtime_queues_smoke( 'command exposes trim-runtime-queues subcommand', str_contains( $source, '@subcommand trim-runtime-queues' ) );
assert_jobs_trim_runtime_queues_smoke( 'command defaults to dry run without --yes', str_contains( $method, '$apply  = isset( $assoc_args[\'yes\'] );' ) );
assert_jobs_trim_runtime_queues_smoke( 'command scans only pending or processing jobs', str_contains( $method, "WHERE status IN ('pending', 'processing')" ) );
assert_jobs_trim_runtime_queues_smoke( 'command searches for copied config queues', str_contains( $method, 'config_patch_queue' ) );
assert_jobs_trim_runtime_queues_smoke( 'command persists via Jobs repository', str_contains( $method, '$jobs_db->store_engine_data( $job_id, $after )' ) );
assert_jobs_trim_runtime_queues_smoke( 'helper strips config patch queue', str_contains( $source, '$flow_step_config[\'config_patch_queue\']' ) );
assert_jobs_trim_runtime_queues_smoke( 'helper strips prompt queue', str_contains( $source, '$flow_step_config[\'prompt_queue\']' ) );
assert_jobs_trim_runtime_queues_smoke( 'helper strips queue revision', str_contains( $source, '$flow_step_config[\'_queue_consume_revision\']' ) );

echo "\nJobs trim runtime queues smoke complete: {$total} assertions, {$failed} failures.\n";
if ( $failed > 0 ) {
	exit( 1 );
}
