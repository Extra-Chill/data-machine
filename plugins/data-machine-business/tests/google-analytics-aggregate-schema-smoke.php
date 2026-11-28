<?php
/**
 * Validate the registered-style combined GA schema with WordPress REST schema validation.
 *
 * Run with: WP_PATH=/path/to/wordpress php tests/google-analytics-aggregate-schema-smoke.php
 */

$root    = dirname( __DIR__ );
$wp_path = getenv( 'WP_PATH' );

if ( empty( $wp_path ) || ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WP_PATH must point to a WordPress installation.\n" );
	exit( 2 );
}

require_once $wp_path . '/wp-load.php';
require_once $root . '/inc/Abilities/Analytics/GoogleAnalyticsAbilities.php';

$schema = \DataMachineBusiness\Abilities\Analytics\GoogleAnalyticsAbilities::inputSchema();
$valid_aggregate = array(
	'action'     => 'aggregate_report',
	'date_range' => array( 'start_date' => '2026-01-01', 'end_date' => '2026-01-31' ),
	'metrics'    => array( 'sessions' ),
	'limit'      => 100,
);
$unknown_aggregate = $valid_aggregate;
$unknown_aggregate['unexpected'] = true;
$legacy_with_extra = array( 'action' => 'page_stats', 'limit' => 10000, 'consumer_context' => 'preserved' );

$checks = array(
	! is_wp_error( rest_validate_value_from_schema( $valid_aggregate, $schema, 'aggregate' ) ),
	is_wp_error( rest_validate_value_from_schema( $unknown_aggregate, $schema, 'aggregate' ) ),
	! is_wp_error( rest_validate_value_from_schema( $legacy_with_extra, $schema, 'legacy' ) ),
);

if ( in_array( false, $checks, true ) ) {
	fwrite( STDERR, "Aggregate schema validation failed.\n" );
	exit( 1 );
}

echo "3 assertions passed.\n";
