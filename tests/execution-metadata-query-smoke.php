<?php
/**
 * Smoke test for generic execution metadata query primitives.
 *
 * Run with: php tests/execution-metadata-query-smoke.php
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/ExecutionQuery.php';

use DataMachine\Core\ExecutionQuery;

$failures = 0;
$passes   = 0;

$assert = static function ( string $label, bool $condition ) use ( &$failures, &$passes ): void {
	if ( $condition ) {
		++$passes;
		return;
	}

	++$failures;
	fwrite( fopen( 'php://stderr', 'w' ), "FAIL: {$label}\n" );
};

$engine_data = array(
	'task_type' => 'daily',
	'attempt'   => 2,
	'source'    => array(
		'slug'    => 'example',
		'enabled' => true,
	),
);

$assert( 'dot-path reader returns nested value', 'example' === ExecutionQuery::get_path_value( $engine_data, 'source.slug' ) );
$assert( 'missing dot-path returns null', null === ExecutionQuery::get_path_value( $engine_data, 'source.missing' ) );

$filters = ExecutionQuery::parse_metadata_filter_string( 'task_type=daily,attempt=2,source.enabled=true' );
$assert( 'metadata parser preserves string values', 'daily' === $filters['task_type'] );
$assert( 'metadata parser normalizes integers', 2 === $filters['attempt'] );
$assert( 'metadata parser normalizes booleans', true === $filters['source.enabled'] );
$assert( 'matching metadata requires every filter', ExecutionQuery::matches_metadata_filters( $engine_data, $filters ) );
$assert( 'metadata matching is exact', ! ExecutionQuery::matches_metadata_filters( $engine_data, array( 'task_type' => 'weekly' ) ) );

$jobs_db_source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Database/Jobs/Jobs.php' ) ?: '';
$run_metadata_source = file_get_contents( dirname( __DIR__ ) . '/inc/Core/Database/RunMetadata/RunMetadata.php' ) ?: '';
$bootstrap_source = file_get_contents( dirname( __DIR__ ) . '/data-machine.php' ) ?: '';
$ability_source = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Job/GetJobsAbility.php' ) ?: '';
$cli_source     = file_get_contents( dirname( __DIR__ ) . '/inc/Cli/Commands/JobsCommand.php' ) ?: '';
$rest_source    = file_get_contents( dirname( __DIR__ ) . '/inc/Api/Jobs.php' ) ?: '';

$assert( 'Jobs repository exposes read-only metadata query primitive', str_contains( $jobs_db_source, 'function query_executions_by_metadata' ) );
$assert( 'run metadata table has indexed exact path/value lookup', str_contains( $run_metadata_source, 'KEY path_value (metadata_path, metadata_value)' ) );
$assert( 'schema creation includes run metadata table', str_contains( $bootstrap_source, 'Database\\RunMetadata\\RunMetadata::create_table' ) );
$assert( 'Jobs metadata query uses indexed run metadata first', str_contains( $jobs_db_source, 'new RunMetadata()' ) && str_contains( $jobs_db_source, "'indexed'    => true" ) );
$assert( 'engine data persistence updates run metadata index', str_contains( $jobs_db_source, 'replace_for_engine_data' ) );
$assert( 'get-jobs ability accepts metadata filters', str_contains( $ability_source, "'metadata'" ) && str_contains( $ability_source, 'query_executions_by_metadata' ) );
$assert( 'jobs CLI exposes metadata filter option', str_contains( $cli_source, '[--metadata=<filters>]' ) );
$assert( 'REST jobs endpoint exposes metadata query diagnostics', str_contains( $rest_source, "'metadata_query'" ) );

if ( $failures > 0 ) {
	fwrite( fopen( 'php://stderr', 'w' ), "execution-metadata-query-smoke: {$failures} failure(s), {$passes} pass(es).\n" );
	exit( 1 );
}

echo "execution-metadata-query-smoke: ALL PASS ({$passes} assertions)\n";
