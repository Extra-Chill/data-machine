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
	 * Delegates to Venue_Taxonomy::find_or_create_venue() which provides
	 * address-based matching, name normalization (punctuation, "The" prefix),
	 * and smart metadata merging. This ensures a single venue creation path
	 * across the entire system.
	 *
	 * @param array $venue_data Normalized venue data (must include 'name')
	 * @return int|\WP_Error Term ID on success, WP_Error on failure
	 */
	public static function get_or_create_venue( array $venue_data ) {
		$name = $venue_data['name'] ?? '';
		if ( empty( $name ) ) {
			return new \WP_Error( 'empty_venue_name', 'Venue name is required' );
		}

		$result = Venue_Taxonomy::find_or_create_venue( $name, $venue_data );

		if ( empty( $result['term_id'] ) ) {
			return new \WP_Error( 'venue_creation_failed', 'Failed to find or create venue' );
		}

		return (int) $result['term_id'];
	}
}
