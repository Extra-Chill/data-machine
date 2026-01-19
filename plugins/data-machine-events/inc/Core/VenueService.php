<?php
/**
 * Venue Service
 *
 * Centralized service for handling venue logic: normalization, finding existing venues,
 * and creating new venue terms. Used by Import Handlers (for normalization) and
 * Publish Handlers (for term creation).
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueService {

	/**
	 * Normalize raw venue data from import sources.
	 *
	 * @param array $raw_data Raw venue data (name, address, city, etc.)
	 * @return array Normalized venue data
	 */
	public static function normalize_venue_data( array $raw_data ): array {
		$normalized = array(
			'name' => sanitize_text_field( $raw_data['name'] ?? '' ),
		);

		foreach ( array_keys( Venue_Taxonomy::$meta_fields ) as $field_key ) {
			$sanitizer                = ( 'website' === $field_key ) ? 'esc_url_raw' : 'sanitize_text_field';
			$normalized[ $field_key ] = $sanitizer( $raw_data[ $field_key ] ?? '' );
		}

		return $normalized;
	}

	/**
	 * Get existing venue term ID or create a new one.
	 *
	 * @param array $venue_data Normalized venue data
	 * @return int|WP_Error Term ID on success, WP_Error on failure
	 */
	public static function get_or_create_venue( array $venue_data ) {
		$name = $venue_data['name'];
		if ( empty( $name ) ) {
			return new \WP_Error( 'empty_venue_name', 'Venue name is required' );
		}

		// 1. Try to find existing venue by name
		$existing_term = term_exists( $name, 'venue' );
		if ( $existing_term ) {
			$term_id = is_array( $existing_term ) ? $existing_term['term_id'] : $existing_term;
			// Update metadata if needed? For now, just return ID.
			return (int) $term_id;
		}

		// 2. Create new venue
		$result = wp_insert_term( $name, 'venue' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = $result['term_id'];

		// 3. Save metadata
		self::save_venue_meta( $term_id, $venue_data );

		return (int) $term_id;
	}

	/**
	 * Save venue metadata.
	 *
	 * @param int $term_id Venue term ID
	 * @param array $data Venue data
	 */
	private static function save_venue_meta( int $term_id, array $data ): void {
		foreach ( Venue_Taxonomy::$meta_fields as $data_key => $meta_key ) {
			if ( ! empty( $data[ $data_key ] ) ) {
				update_term_meta( $term_id, $meta_key, $data[ $data_key ] );
			}
		}
	}
}
