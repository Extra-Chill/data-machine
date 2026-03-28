<?php
/**
 * Filters API controller for centralized taxonomy filter options
 *
 * Thin wrapper around FilterAbilities for REST API access.
 * All business logic delegated to FilterAbilities.
 *
 * @package DataMachineEvents\Api\Controllers
 */

namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DataMachineEvents\Abilities\FilterAbilities;

/**
 * REST controller for filter options endpoint
 */
class Filters {

	/**
	 * Get filter options with real-time cross-filtering and archive context support
	 *
	 * @param WP_REST_Request $request Request object with optional active filters, date context, and archive context.
	 * @return \WP_REST_Response
	 */
	public function get( WP_REST_Request $request ) {
		$abilities = new FilterAbilities();

		$result = $abilities->executeGetFilterOptions(
			array(
				'active_filters'   => $request->get_param( 'active' ) ?? array(),
				'context'          => $request->get_param( 'context' ) ?? 'modal',
				'date_context'     => array(
					'date_start' => $request->get_param( 'date_start' ) ?? '',
					'date_end'   => $request->get_param( 'date_end' ) ?? '',
					'past'       => $request->get_param( 'past' ) ?? '',
				),
				'archive_taxonomy' => $request->get_param( 'archive_taxonomy' ) ?? '',
				'archive_term_id'  => $request->get_param( 'archive_term_id' ) ?? 0,
				'geo_lat'          => $request->get_param( 'lat' ) ?? '',
				'geo_lng'          => $request->get_param( 'lng' ) ?? '',
				'geo_radius'       => $request->get_param( 'radius' ) ?? 25,
				'geo_radius_unit'  => $request->get_param( 'radius_unit' ) ?? 'mi',
			)
		);

		return rest_ensure_response( $result );
	}
}
