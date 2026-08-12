<?php
/** Bounded contract checks for native job-query ability failures. */

$root    = dirname( __DIR__ );
$get     = file_get_contents( $root . '/inc/Abilities/Job/GetJobsAbility.php' ) ?: '';
$summary = file_get_contents( $root . '/inc/Abilities/Job/JobsSummaryAbility.php' ) ?: '';
$health  = file_get_contents( $root . '/inc/Abilities/Job/FlowHealthAbility.php' ) ?: '';
$rest    = file_get_contents( $root . '/inc/Api/Jobs.php' ) ?: '';
$cli     = file_get_contents( $root . '/inc/Cli/Commands/JobsCommand.php' ) ?: '';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	printf( "PASS: %s\n", $message );
};

$assert( str_contains( $get, 'array|\\WP_Error' ), 'get-jobs declares native failures' );
$assert( str_contains( $summary, 'array|\\WP_Error' ), 'jobs summary declares native failures' );
$assert( str_contains( $health, 'array|\\WP_Error' ), 'flow health declares native failures' );
$assert( ! str_contains( $get, "'success'    => false" ), 'get-jobs has no legacy failure envelope' );
$assert( ! str_contains( $summary, "'success'    => false" ), 'jobs summary has no legacy failure envelope' );
$assert( ! str_contains( $health, "'success' => false" ), 'flow health has no legacy failure envelope' );
$assert( str_contains( $rest, "rest_collection_response( \$result, 'jobs'" ), 'REST collection presenter remains intact' );
$assert( str_contains( $rest, "'job_not_found'" ), 'REST item not-found presenter remains intact' );
$assert( str_contains( $cli, "cli_collection_payload( \$items, \$result, 'jobs' )" ), 'CLI JSON rows remain intact' );
$assert( substr_count( $cli, "failure_to_wp_error( \$result, 'get_jobs_failed'" ) >= 2, 'CLI item presenters accept native failures' );

printf( "Job query WP_Error contract smoke tests passed.\n" );
