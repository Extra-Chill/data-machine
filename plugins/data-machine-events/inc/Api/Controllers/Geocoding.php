<?php
/**
 * Geocoding API Controller
 *
 * Server-side proxy for Nominatim API to avoid CORS restrictions.
 *
 * @package DataMachineEvents\Api\Controllers
 */

namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;

class Geocoding {

	private const NOMINATIM_API = 'https://nominatim.openstreetmap.org/search';
	private const USER_AGENT    = 'DataMachineEvents/1.0 (https://extrachill.com)';

	/**
	 * Search for addresses using Nominatim API
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		$query = $request->get_param( 'query' );

		if ( empty( $query ) || strlen( $query ) < 3 ) {
			return new \WP_Error(
				'invalid_query',
				__( 'Query must be at least 3 characters', 'datamachine-events' ),
				array( 'status' => 400 )
			);
		}

		$url = add_query_arg(
			array(
				'format'         => 'json',
				'addressdetails' => '1',
				'limit'          => '5',
				'q'              => $query,
			),
			self::NOMINATIM_API
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'User-Agent' => self::USER_AGENT,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'geocoding_failed',
				__( 'Geocoding request failed', 'datamachine-events' ),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return new \WP_Error(
				'geocoding_error',
				__( 'Geocoding service returned an error', 'datamachine-events' ),
				array( 'status' => $status_code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'invalid_response',
				__( 'Invalid response from geocoding service', 'datamachine-events' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'results' => $data,
			)
		);
	}
}
