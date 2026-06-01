<?php
/**
 * Venue Map REST API Controller
 *
 * Public endpoint for listing venues with coordinates.
 * Thin wrapper around VenueMapAbilities.
 *
 * @package DataMachineEvents\Api\Controllers
 */

namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DataMachineEvents\Abilities\VenueMapAbilities;

/**
 * Venue map API controller
 */
class VenueMap {

	/**
	 * List venues with coordinates for map rendering.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function list_venues( WP_REST_Request $request ) {
		// Opt-in per-venue events payload: triggered by `?include=events`
		// (legacy/REST-style) or `?with_events=1` (boolean shortcut). Either
		// form sets the include_events ability input; absence keeps the
		// response byte-identical to today's shape so the existing
		// location-archive map keeps working.
		$include_raw  = (string) ( $request->get_param( 'include' ) ?? '' );
		$with_events  = $request->get_param( 'with_events' );
		$include_set  = array_filter( array_map( 'trim', explode( ',', $include_raw ) ) );
		$wants_events = in_array( 'events', $include_set, true )
			|| ( null !== $with_events && filter_var( $with_events, FILTER_VALIDATE_BOOLEAN ) );

		$abilities = new VenueMapAbilities();
		$result    = $abilities->executeListVenues(
			array(
				'lat'            => $request->get_param( 'lat' ),
				'lng'            => $request->get_param( 'lng' ),
				'radius'         => $request->get_param( 'radius' ) ?? 25,
				'radius_unit'    => $request->get_param( 'radius_unit' ) ?? 'mi',
				'bounds'         => $request->get_param( 'bounds' ) ?? '',
				'taxonomy'       => $request->get_param( 'taxonomy' ) ?? '',
				'term_id'        => $request->get_param( 'term_id' ) ?? 0,
				'include_events' => $wants_events,
				// #160: opaque consumer-minted scope token. Threaded into
				// the ability input so the data_machine_events_map_query_args
				// and data_machine_events_map_venues filters receive it and
				// a consumer can re-apply owner scoping that would otherwise
				// die over REST (page-context gates don't hold). The generic
				// layer never interprets it.
				'scope_token'    => (string) ( $request->get_param( 'scope_token' ) ?? '' ),
			)
		);

		return rest_ensure_response( $result );
	}
}
