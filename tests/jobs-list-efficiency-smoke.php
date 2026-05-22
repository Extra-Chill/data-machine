<?php
/**
 * Smoke coverage for low-memory jobs list behavior.
 *
 * @package DataMachine\Tests
 */

$root        = dirname( __DIR__ );
$jobs_cli    = file_get_contents( $root . '/inc/Cli/Commands/JobsCommand.php' ) ?: '';
$jobs_db     = file_get_contents( $root . '/inc/Core/Database/Jobs/Jobs.php' ) ?: '';
$jobs_ability = file_get_contents( $root . '/inc/Abilities/Job/GetJobsAbility.php' ) ?: '';

$failures = 0;
$total    = 0;

$assert = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
	++$total;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}

	++$failures;
	echo "FAIL: {$label}\n";
};

$assert( 'jobs list count uses count query before get-jobs ability', strpos( $jobs_cli, '\'count\' === $format' ) < strpos( $jobs_cli, 'new GetJobsAbility()' ) );
$assert( 'jobs list maps requested fields to database projection', str_contains( $jobs_cli, 'get_database_fields_for_job_list_fields' ) );
$assert( 'jobs list formats explicit fields without default JSON metrics', str_contains( $jobs_cli, 'format_requested_job_list_fields' ) );
$assert( 'get-jobs ability accepts projected fields', str_contains( $jobs_ability, "args['fields']" ) );
$assert( 'jobs repository has projected SELECT path', str_contains( $jobs_db, '$select_fields       = implode' ) );
$assert( 'jobs repository decodes engine_data only when selected', str_contains( $jobs_db, '$decode_engine_data  = in_array' ) );
$assert( 'jobs repository keeps full SELECT as default behavior', str_contains( $jobs_db, '$select_fields       = \'j.*, p.pipeline_name, f.flow_name\'' ) );

if ( $failures > 0 ) {
	echo "\n=== jobs-list-efficiency-smoke: {$failures} FAILURE(S) / {$total} assertions ===\n";
	exit( 1 );
}

echo "\n=== jobs-list-efficiency-smoke: ALL PASS ({$total} assertions) ===\n";
