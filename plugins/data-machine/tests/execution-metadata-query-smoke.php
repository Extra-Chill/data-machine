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

if ( $failures > 0 ) {
	fwrite( fopen( 'php://stderr', 'w' ), "execution-metadata-query-smoke: {$failures} failure(s), {$passes} pass(es).\n" );
	exit( 1 );
}

echo "execution-metadata-query-smoke: ALL PASS ({$passes} assertions)\n";
