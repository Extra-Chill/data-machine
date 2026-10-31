<?php
/**
 * Static smoke coverage for bounded pending-action summary context output.
 *
 * Run with: php tests/pending-actions-summary-bounds-smoke.php
 *
 * @package DataMachine\Tests
 */

echo "pending-actions-summary-bounds-smoke\n";

$root              = dirname( __DIR__ );
$store_source      = file_get_contents( $root . '/inc/Engine/AI/Actions/PendingActionStore.php' );
$inspection_source = file_get_contents( $root . '/inc/Engine/AI/Actions/PendingActionInspectionAbility.php' );
$cli_source        = file_get_contents( $root . '/inc/Cli/Commands/PendingActionsCommand.php' );

$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}

	$failures[] = $message;
	echo "FAIL: {$message}\n";
};

$assert( is_string( $store_source ) && str_contains( $store_source, 'return 25;' ), 'summary context output defaults to 25 buckets' );
$assert( is_string( $store_source ) && str_contains( $store_source, 'include_context_details' ) && str_contains( $store_source, 'return 0;' ), 'summary supports explicit unbounded context details' );
$assert( is_string( $store_source ) && str_contains( $store_source, 'limit_summary_bucket' ) && str_contains( $store_source, 'array_slice( $bucket, 0, $limit, true )' ), 'summary slices context buckets after aggregation' );
$assert( is_string( $store_source ) && str_contains( $store_source, 'by_context_omitted' ) && str_contains( $store_source, 'context_detail_truncated' ), 'summary reports omitted context bucket metadata' );
$assert( is_string( $inspection_source ) && str_contains( $inspection_source, 'context_limit' ) && str_contains( $inspection_source, 'include_context_details' ), 'ability schema exposes bounded and opt-in context summary controls' );
$assert( is_string( $cli_source ) && str_contains( $cli_source, '--context-limit=<limit>' ) && str_contains( $cli_source, '--include-context-details' ), 'WP-CLI summary documents bounded and opt-in context controls' );

if ( ! empty( $failures ) ) {
	exit( 1 );
}
