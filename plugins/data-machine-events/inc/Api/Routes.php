<?php
namespace DataMachineEvents\Api;

defined( 'ABSPATH' ) || exit;

const API_NAMESPACE = 'datamachine/v1';

// Ensure controllers are loaded when composer autoloader is not present
if ( defined( 'DATAMACHINE_EVENTS_PLUGIN_DIR' ) ) {
	$controllers = array(
		DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/Calendar.php',
		DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/Venues.php',
		DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/Events.php',
		DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/Filters.php',
		DATAMACHINE_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/Geocoding.php',
	);
	foreach ( $controllers as $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

use DataMachineEvents\Api\Controllers\Calendar;
use DataMachineEvents\Api\Controllers\Venues;
use DataMachineEvents\Api\Controllers\Filters;
use DataMachineEvents\Api\Controllers\Geocoding;

/**
 * Register REST API routes for Data Machine Events
 */
function register_routes() {
	$calendar = new Calendar();
	$venues   = new Venues();

	register_rest_route(
		API_NAMESPACE,
		'/events/calendar',
		array(
			'methods'             => 'GET',
			'callback'            => array( $calendar, 'calendar' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'event_search'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_start'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_end'         => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'tax_filter'       => array(
					'type'              => 'object',
					'sanitize_callback' => function ( $value ) {
						if ( ! is_array( $value ) ) {
							return array();
						}
						$sanitized = array();
						foreach ( $value as $taxonomy => $term_ids ) {
							$taxonomy = sanitize_key( $taxonomy );
							$sanitized[ $taxonomy ] = array_map( 'absint', (array) $term_ids );
						}
						return $sanitized;
					},
				),
				'archive_taxonomy' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'archive_term_id'  => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'paged'            => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'past'             => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		API_NAMESPACE,
		'/events/venues/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'callback'            => array( $venues, 'get' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'id' => array(
					'validate_callback' => function ( $param ) {
						return is_numeric( $param );
					},
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		API_NAMESPACE,
		'/events/venues/check-duplicate',
		array(
			'methods'             => 'GET',
			'callback'            => array( $venues, 'check_duplicate' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'name'    => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'address' => array(
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	$filters = new Filters();

	register_rest_route(
		API_NAMESPACE,
		'/events/filters',
		array(
			'methods'             => 'GET',
			'callback'            => array( $filters, 'get' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'active'     => array(
					'type'              => 'object',
					'default'           => array(),
					'sanitize_callback' => function ( $value ) {
						if ( ! is_array( $value ) ) {
							return array();
						}
						$sanitized = array();
						foreach ( $value as $taxonomy => $term_ids ) {
							$taxonomy              = sanitize_key( $taxonomy );
							$sanitized[ $taxonomy ] = array_map( 'absint', (array) $term_ids );
						}
						return $sanitized;
					},
				),
				'context'    => array(
					'type'              => 'string',
					'default'           => 'modal',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_start' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_end'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'past'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	$geocoding = new Geocoding();

	register_rest_route(
		API_NAMESPACE,
		'/events/geocode/search',
		array(
			'methods'             => 'POST',
			'callback'            => array( $geocoding, 'search' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'query' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
