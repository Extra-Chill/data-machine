<?php
namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;

/**
 * Venues API controller
 *
 * Delegates to VenueAbilities for business logic.
 */
class Venues {
	/**
	 * Get venue by term id
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get( WP_REST_Request $request ) {
		$term_id = $request->get_param( 'id' );

		if ( empty( $term_id ) ) {
			return new \WP_Error(
				'missing_term_id',
				__( 'Venue ID is required', 'datamachine-events' ),
				array( 'status' => 400 )
			);
		}

		$ability = wp_get_ability( 'datamachine-events/get-venue' );
		if ( ! $ability ) {
			return new \WP_Error(
				'ability_not_found',
				__( 'Ability not available', 'datamachine-events' ),
				array( 'status' => 500 )
			);
		}

		$result = $ability->execute( array( 'id' => (int) $term_id ) );

		if ( isset( $result['error'] ) ) {
			return new \WP_Error(
				'venue_not_found',
				$result['error'],
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Check duplicate venue
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_duplicate( WP_REST_Request $request ) {
		$venue_name    = $request->get_param( 'name' );
		$venue_address = $request->get_param( 'address' );

		if ( empty( $venue_name ) ) {
			return new \WP_Error(
				'missing_venue_name',
				__( 'Venue name is required', 'datamachine-events' ),
				array( 'status' => 400 )
			);
		}

		$ability = wp_get_ability( 'datamachine-events/check-duplicate-venue' );
		if ( ! $ability ) {
			return new \WP_Error(
				'ability_not_found',
				__( 'Ability not available', 'datamachine-events' ),
				array( 'status' => 500 )
			);
		}

		$input = array( 'name' => sanitize_text_field( $venue_name ) );
		if ( ! empty( $venue_address ) ) {
			$input['address'] = sanitize_text_field( $venue_address );
		}

		$result = $ability->execute( $input );

		if ( isset( $result['error'] ) ) {
			return new \WP_Error(
				'check_duplicate_failed',
				$result['error'],
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}
}
