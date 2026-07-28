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
	private const TICKETING_HOSTS = array(
		'eventbrite.com',
		'ticketmaster.com',
		'axs.com',
		'dice.fm',
		'seetickets.com',
		'bandsintown.com',
		'songkick.com',
		'livenation.com',
		'ticketweb.com',
		'etix.com',
		'ticketfly.com',
		'showclix.com',
		'prekindle.com',
		'freshtix.com',
		'tixr.com',
		'seated.com',
		'stubhub.com',
		'vividseats.com',
	);

	/**
	 * Whether a URL belongs to a known public ticketing host.
	 */
	public static function is_ticketing_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		foreach ( self::TICKETING_HOSTS as $ticketing_host ) {
			if ( $host === $ticketing_host || str_ends_with( $host, '.' . $ticketing_host ) ) {
				return true;
			}
		}

		return false;
	}

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
			$sanitizer                = in_array( $field_key, array( 'website', 'ticketing_url' ), true ) ? 'esc_url_raw' : 'sanitize_text_field';
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
