<?php
/**
 * Validate datamachine/sendy-list-campaigns' registered input schema with real
 * WordPress REST schema validation (rest_validate_value_from_schema()).
 *
 * Regression coverage for https://github.com/Extra-Chill/data-machine-business/issues/134:
 * the ability's own execute callback treats status='' as "no filter", but the
 * registered schema's enum previously omitted '' — so the exact payload every
 * omitted-flag caller sends (status explicitly set to '') was rejected by
 * WP_Ability::validate_input() before execute_list_campaigns() ever ran. The
 * prior smoke coverage in sendy-campaign-abilities-smoke.php called
 * execute_list_campaigns() directly and never exercised that schema-validation
 * layer, which is why the mismatch shipped unnoticed.
 *
 * Run with: WP_PATH=/path/to/wordpress php tests/sendy-list-campaigns-schema-smoke.php
 */

$root    = dirname( __DIR__ );
$wp_path = getenv( 'WP_PATH' );

if ( empty( $wp_path ) || ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WP_PATH must point to a WordPress installation.\n" );
	exit( 2 );
}

require_once $wp_path . '/wp-load.php';
require_once $root . '/inc/Abilities/Sendy/SendyAbilities.php';

$schema = \DataMachineBusiness\Abilities\Sendy\SendyAbilities::list_campaigns_input_schema();

// The payload extrachill_newsletter_ability_list_campaigns() sends when the
// caller omits --status: the key is always present, defaulting to ''.
$omitted_status  = array( 'per_page' => 20, 'offset' => 0, 'status' => '' );
$sent_filter     = array( 'per_page' => 20, 'offset' => 0, 'status' => 'sent' );
$draft_filter    = array( 'status' => 'draft' );
$scheduled_filter = array( 'status' => 'scheduled' );
$invalid_status  = array( 'status' => 'unknown' );
$fully_omitted   = array( 'per_page' => 20 );
$unknown_prop    = array( 'status' => '', 'unexpected' => true );

$checks = array(
	'status="" validates'             => ! is_wp_error( rest_validate_value_from_schema( $omitted_status, $schema, 'list_campaigns' ) ),
	'status="sent" validates'         => ! is_wp_error( rest_validate_value_from_schema( $sent_filter, $schema, 'list_campaigns' ) ),
	'status="draft" validates'        => ! is_wp_error( rest_validate_value_from_schema( $draft_filter, $schema, 'list_campaigns' ) ),
	'status="scheduled" validates'    => ! is_wp_error( rest_validate_value_from_schema( $scheduled_filter, $schema, 'list_campaigns' ) ),
	'status key fully omitted validates' => ! is_wp_error( rest_validate_value_from_schema( $fully_omitted, $schema, 'list_campaigns' ) ),
	'status="unknown" still rejected' => is_wp_error( rest_validate_value_from_schema( $invalid_status, $schema, 'list_campaigns' ) ),
	'unknown top-level prop rejected' => is_wp_error( rest_validate_value_from_schema( $unknown_prop, $schema, 'list_campaigns' ) ),
);

$failures = array();
foreach ( $checks as $name => $passed ) {
	if ( ! $passed ) {
		$failures[] = $name;
	}
}

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "Sendy list-campaigns schema validation failed:\n" . implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo count( $checks ) . " assertions passed.\n";
