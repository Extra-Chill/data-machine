<?php
namespace DataMachineEvents\Api;

defined( 'ABSPATH' ) || exit;

// Ensure controllers are loaded when composer autoloader is not present
if ( defined( 'DATAMACHINE_EVENTS_PLUGIN_DIR' ) ) {
	$controllers = array(
		DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/Calendar.php',
		DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/Venues.php',
		DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/Events.php',
	);
	foreach ( $controllers as $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

use DataMachineEvents\Api\Controllers\Calendar;
use DataMachineEvents\Api\Controllers\Venues;

/**
 * Register REST API routes for Data Machine Events
 */
function register_routes() {
	$calendar = new Calendar();
	$venues   = new Venues();

	register_rest_route(
		'datamachine/v1',
		'/events/calendar',
		array(
			'methods'             => 'GET',
			'callback'            => array( $calendar, 'calendar' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'event_search' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_start' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_end' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'tax_filter' => array(
					'type' => 'object',
				),
				'paged' => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'past' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'datamachine/v1',
		'/events/venues/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'callback'            => array( $venues, 'get' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'id' => array(
					'validate_callback' => function( $param ) {
						return is_numeric( $param );
					},
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'datamachine/v1',
		'/events/venues/check-duplicate',
		array(
			'methods'             => 'GET',
			'callback'            => array( $venues, 'check_duplicate' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'name' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'address' => array(
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
