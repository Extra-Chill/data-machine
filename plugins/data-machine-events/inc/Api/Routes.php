<?php
namespace DataMachineEvents\Api;

defined( 'ABSPATH' ) || exit;

const API_NAMESPACE = 'datamachine/v1';

use DataMachineEvents\Api\Controllers\Calendar;
use DataMachineEvents\Api\Controllers\Venues;
use DataMachineEvents\Api\Controllers\EventIcs;
use DataMachineEvents\Api\Controllers\Filters;
use DataMachineEvents\Api\Controllers\Geocoding;
use DataMachineEvents\Api\Controllers\VenueMap;

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
				'scope'            => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'description'       => 'Time scope: today, tonight, this-weekend, this-week',
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
				'format'           => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
					'description'       => 'Response format. Default (empty) returns the legacy HTML-string envelope. "data" returns the structured data-only envelope (phase 1 of refactor #298). All other values fall back to the default envelope.',
				),
				'month'            => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Visible month for month-grid display (YYYY-MM). When set, the response is scoped to that calendar month and pagination is collapsed to a single page (issue #318). Invalid values fall back as if absent.',
				),
				'scope_token'      => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Opaque consumer-minted scope token. data-machine-events does not interpret it; it is passed through to the data_machine_events_calendar_query_args filter $input so a consumer can re-apply a server-side query constraint (e.g. owner scoping) that must survive the REST round-trip. The minting + validation is owned entirely by the consumer.',
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

	$venue_map = new VenueMap();

	register_rest_route(
		API_NAMESPACE,
		'/events/venues',
		array(
			'methods'             => 'GET',
			'callback'            => array( $venue_map, 'list_venues' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'lat'         => array(
					'type'              => 'number',
					'sanitize_callback' => 'floatval',
				),
				'lng'         => array(
					'type'              => 'number',
					'sanitize_callback' => 'floatval',
				),
				'radius'      => array(
					'type'              => 'integer',
					'default'           => 25,
					'sanitize_callback' => 'absint',
				),
				'radius_unit' => array(
					'type'              => 'string',
					'default'           => 'mi',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'bounds'      => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'taxonomy'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'term_id'     => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'include'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Comma-separated opt-in extensions. Currently supports "events" to attach per-venue upcoming_events_at_venue (requires taxonomy+term_id).',
				),
				'with_events' => array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'description'       => 'Boolean shortcut equivalent to include=events.',
				),
				'scope_token' => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Opaque consumer-minted scope token. data-machine-events does not interpret it; it is passed through to the data_machine_events_map_query_args and data_machine_events_map_venues filter $input so a consumer can re-apply a server-side constraint (e.g. owner scoping) that must survive the REST round-trip. Minting + validation owned entirely by the consumer.',
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
				'active'           => array(
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
				'context'          => array(
					'type'              => 'string',
					'default'           => 'modal',
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
				'past'             => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'archive_taxonomy' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'archive_term_id'  => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'lat'              => array(
					'type'              => 'number',
					'sanitize_callback' => 'floatval',
				),
				'lng'              => array(
					'type'              => 'number',
					'sanitize_callback' => 'floatval',
				),
				'radius'           => array(
					'type'              => 'integer',
					'default'           => 25,
					'sanitize_callback' => 'absint',
				),
				'radius_unit'      => array(
					'type'              => 'string',
					'default'           => 'mi',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	$event_ics = new EventIcs();

	register_rest_route(
		API_NAMESPACE,
		'/events/(?P<id>\d+)/ics',
		array(
			'methods'             => 'GET',
			'callback'            => array( $event_ics, 'download' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && (int) $param > 0;
					},
				),
			),
		)
	);

	$geocoding = new Geocoding();

	register_rest_route(
		API_NAMESPACE,
		'/events/geocode/search',
		array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $geocoding, 'search' ),
			'permission_callback' => '__return_true',
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
