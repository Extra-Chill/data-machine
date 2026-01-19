<?php
namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DataMachineEvents\Core\Venue_Taxonomy;

/**
 * Venues API controller
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

		$venue_data = \DataMachineEvents\Core\Venue_Taxonomy::get_venue_data( $term_id );

		if ( empty( $venue_data ) ) {
			return new \WP_Error(
				'venue_not_found',
				__( 'Venue not found', 'datamachine-events' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $venue_data,
			)
		);
	}

	/**
	 * Check duplicate venue
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response
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

		$existing_term = get_term_by( 'name', $venue_name, 'venue' );

		if ( ! $existing_term ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'data'    => array(
						'is_duplicate' => false,
						'message'      => '',
					),
				)
			);
		}

		if ( ! empty( $venue_address ) ) {
			$existing_address = get_term_meta( $existing_term->term_id, '_venue_address', true );

			$normalized_new      = strtolower( trim( $venue_address ) );
			$normalized_existing = strtolower( trim( $existing_address ) );

			if ( $normalized_new === $normalized_existing ) {
				return rest_ensure_response(
					array(
						'success' => true,
						'data'    => array(
							'is_duplicate'        => true,
							'existing_term_id'    => $existing_term->term_id,
							'existing_venue_name' => $existing_term->name,
							'message'             => sprintf(
								__( 'A venue named "%s" with this address already exists.', 'datamachine-events' ),
								esc_html( $existing_term->name )
							),
						),
					)
				);
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'is_duplicate'        => true,
					'existing_term_id'    => $existing_term->term_id,
					'existing_venue_name' => $existing_term->name,
					'message'             => sprintf(
						__( 'A venue named "%s" already exists. Consider using a more specific name or check if this is the same venue.', 'datamachine-events' ),
						esc_html( $existing_term->name )
					),
				),
			)
		);
	}
}
