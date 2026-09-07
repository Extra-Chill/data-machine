<?php
/**
 * Guard the model-facing google_analytics tool schema shape.
 *
 * Provider function-calling APIs require `parameters` to be a single
 * `type: object`. A top-level `oneOf`/`anyOf`/`allOf` is rejected with a
 * 400 for the whole request and takes down every AI step that carries this
 * global tool (#123). The strict per-action contract belongs on the ability
 * input_schema, which handle_tool_call() still enforces via execute().
 *
 * Run with: WP_PATH=/path/to/wordpress php tests/google-analytics-tool-schema-smoke.php
 */

$root    = dirname( __DIR__ );
$wp_path = getenv( 'WP_PATH' );

if ( empty( $wp_path ) || ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WP_PATH must point to a WordPress installation.\n" );
	exit( 2 );
}

require_once $wp_path . '/wp-load.php';
if ( ! class_exists( \DataMachineBusiness\Abilities\Analytics\GoogleAnalyticsAbilities::class, false ) ) {
	require_once $root . '/inc/Abilities/Analytics/GoogleAnalyticsAbilities.php';
}
if ( ! class_exists( \DataMachineBusiness\Engine\AI\Tools\Global\GoogleAnalytics::class, false ) ) {
	require_once $root . '/inc/Engine/AI/Tools/Global/GoogleAnalytics.php';
}

$definition = ( new \DataMachineBusiness\Engine\AI\Tools\Global\GoogleAnalytics() )->getToolDefinition();
$parameters = $definition['parameters'] ?? array();
$actions    = $parameters['properties']['action']['enum'] ?? array();

$checks = array(
	'parameters.type is object'             => 'object' === ( $parameters['type'] ?? null ),
	'no top-level oneOf'                    => ! isset( $parameters['oneOf'] ),
	'no top-level anyOf'                    => ! isset( $parameters['anyOf'] ),
	'no top-level allOf'                    => ! isset( $parameters['allOf'] ),
	'action is required'                    => array( 'action' ) === ( $parameters['required'] ?? null ),
	'aggregate_report is an allowed action' => in_array( 'aggregate_report', $actions, true ),
	'page_stats is an allowed action'       => in_array( 'page_stats', $actions, true ),
	'aggregate date_range is exposed'       => isset( $parameters['properties']['date_range'] ),
	'aggregate metrics is exposed'          => isset( $parameters['properties']['metrics'] ),
	'legacy page_filter is exposed'         => isset( $parameters['properties']['page_filter'] ),
);

$failed = array_keys( array_filter( $checks, static fn( $ok ) => ! $ok ) );
if ( $failed ) {
	fwrite( STDERR, 'Tool schema checks failed: ' . implode( ', ', $failed ) . "\n" );
	exit( 1 );
}

echo count( $checks ) . " assertions passed.\n";
