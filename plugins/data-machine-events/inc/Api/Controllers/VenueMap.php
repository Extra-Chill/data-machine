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
		$abilities = new VenueMapAbilities();
		$result    = $abilities->executeListVenues(
			array(
				'lat'         => $request->get_param( 'lat' ),
				'lng'         => $request->get_param( 'lng' ),
				'radius'      => $request->get_param( 'radius' ) ?? 25,
				'radius_unit' => $request->get_param( 'radius_unit' ) ?? 'mi',
				'bounds'      => $request->get_param( 'bounds' ) ?? '',
				'taxonomy'    => $request->get_param( 'taxonomy' ) ?? '',
				'term_id'     => $request->get_param( 'term_id' ) ?? 0,
			)
		);

		return rest_ensure_response( $result );
	}
}
