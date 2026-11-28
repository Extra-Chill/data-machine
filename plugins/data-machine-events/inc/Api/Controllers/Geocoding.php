<?php
/**
 * Geocoding API Controller
 *
 * Thin wrapper around GeocodingAbilities for REST API access.
 * All business logic delegated to GeocodingAbilities.
 *
 * @package DataMachineEvents\Api\Controllers
 */

namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DataMachineEvents\Abilities\GeocodingAbilities;

class Geocoding {

	/**
	 * Search for addresses using Nominatim API
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		$abilities = new GeocodingAbilities();

		$result = $abilities->executeGeocodeSearch(
			array(
				'query' => $request->get_param( 'query' ) ?? '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
