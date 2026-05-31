<?php
/**
 * Venue Taxonomy Registration and Management
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

use DataMachineEvents\Core\GeoNamesService;
use DataMachineEvents\Core\NominatimClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comprehensive venue taxonomy with 9 meta fields and admin UI
 */
class Venue_Taxonomy {

	/**
	 * @deprecated 0.40.0 Use NominatimClient::USER_AGENT. Retained as a
	 *             private alias because removing the constant entirely
	 *             would break any reflective consumer.
	 */
	private const NOMINATIM_USER_AGENT = NominatimClient::USER_AGENT;

	public static $meta_fields = array(
		'address'     => '_venue_address',
		'city'        => '_venue_city',
		'state'       => '_venue_state',
		'zip'         => '_venue_zip',
		'country'     => '_venue_country',
		'phone'       => '_venue_phone',
		'website'     => '_venue_website',
		'capacity'    => '_venue_capacity',
		'coordinates' => '_venue_coordinates',
		'timezone'    => '_venue_timezone',
	);

	private static $field_labels = array(
		'address'     => 'Address',
		'city'        => 'City',
		'state'       => 'State',
		'zip'         => 'Postal Code',
		'country'     => 'Country',
		'phone'       => 'Phone',
		'website'     => 'Website',
		'capacity'    => 'Capacity',
		'coordinates' => 'Coordinates',
		'timezone'    => 'Timezone',
	);

	public static function register() {
		self::register_venue_taxonomy();

		self::init_admin_hooks();
	}

	private static function register_venue_taxonomy() {
		if ( taxonomy_exists( 'venue' ) ) {
			register_taxonomy_for_object_type( 'venue', Event_Post_Type::POST_TYPE );
		} else {
			register_taxonomy(
				'venue',
				array( 'post', Event_Post_Type::POST_TYPE ),
				array(
					'hierarchical'      => false,
					'labels'            => array(
						'name'          => _x( 'Venues', 'taxonomy general name', 'data-machine-events' ),
						'singular_name' => _x( 'Venue', 'taxonomy singular name', 'data-machine-events' ),
						'search_items'  => __( 'Search Venues', 'data-machine-events' ),
						'all_items'     => __( 'All Venues', 'data-machine-events' ),
						'edit_item'     => __( 'Edit Venue', 'data-machine-events' ),
						'update_item'   => __( 'Update Venue', 'data-machine-events' ),
						'add_new_item'  => __( 'Add New Venue', 'data-machine-events' ),
						'new_item_name' => __( 'New Venue Name', 'data-machine-events' ),
						'menu_name'     => __( 'Venues', 'data-machine-events' ),
					),
					'show_ui'           => true,
					'show_admin_column' => true,
					'query_var'         => true,
					'rewrite'           => array( 'slug' => 'venue' ),
					'show_in_rest'      => true,
				)
			);
		}

		register_taxonomy_for_object_type( 'venue', Event_Post_Type::POST_TYPE );
	}

	/**
	 * Find or create a venue with given name and metadata
	 *
	 * Matching cascade:
	 * 1. Address-based matching (normalized street + city comparison)
	 * 2. Exact name match
	 * 3. "The" prefix toggle ("The Royal American" ↔ "Royal American")
	 * 4. Normalized name matching (strips punctuation, dashes, case, articles)
	 *    Catches: "Saturn - Birmingham" = "Saturn Birmingham",
	 *             "Reggie's Rock Club" = "Reggies Rock Club",
	 *             "RADIO/EAST" = "Radio East"
	 *
	 * @param string $venue_name Venue name
	 * @param array $venue_data Venue metadata (address, city, state, etc.)
	 * @return array Array with keys: term_id, was_created
	 */
	public static function find_or_create_venue( $venue_name, $venue_data = array() ) {
		// Address-based matching (source of truth)
		$address = $venue_data['address'] ?? '';
		$city    = $venue_data['city'] ?? '';

		$address_match = self::find_venue_by_address( $address, $city );
		if ( $address_match ) {
			if ( ! empty( $venue_data ) ) {
				self::smart_merge_venue_meta( $address_match, $venue_data );
			}

			return array(
				'term_id'     => $address_match,
				'was_created' => false,
			);
		}

		// Allow normalization of venue name (e.g. aliases, corrections)
		$venue_name = apply_filters( 'data_machine_events_normalize_venue_name', $venue_name );

		// Check if venue already exists by name
		$existing = get_term_by( 'name', $venue_name, 'venue' );

		// Smart Lookup: If exact match fails, try variations with/without "The"
		if ( ! $existing ) {
			$alt_name = '';
			if ( stripos( $venue_name, 'The ' ) === 0 ) {
				// Remove "The " prefix
				$alt_name = substr( $venue_name, 4 );
			} else {
				// Add "The " prefix
				$alt_name = 'The ' . $venue_name;
			}

			if ( ! empty( $alt_name ) ) {
				$existing = get_term_by( 'name', $alt_name, 'venue' );
			}
		}

		// Normalized name matching: catches punctuation, dash, and case variants.
		// e.g. "Saturn - Birmingham" = "Saturn Birmingham",
		//      "Reggie's Rock Club" = "Reggies Rock Club"
		if ( ! $existing ) {
			$existing = self::find_venue_by_normalized_name( $venue_name );
		}

		if ( $existing ) {
			$term_id = $existing->term_id;

			// Smart Merge: Fill in any missing metadata fields
			if ( ! empty( $venue_data ) ) {
				self::smart_merge_venue_meta( $term_id, $venue_data );
			}

			return array(
				'term_id'     => $term_id,
				'was_created' => false,
			);
		}

		// Create new venue
		$result = wp_insert_term( $venue_name, 'venue' );

		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Failed to create venue term',
				array(
					'venue_name' => $venue_name,
					'error'      => $result->get_error_message(),
				)
			);
			return array(
				'term_id'     => null,
				'was_created' => false,
			);
		}

		$term_id = $result['term_id'];

		// Update all metadata for new venue
		self::update_venue_meta( $term_id, $venue_data );

		return array(
			'term_id'     => $term_id,
			'was_created' => true,
		);
	}

	/**
	 * Find an existing venue by normalized name comparison.
	 *
	 * Normalizes both the input name and all existing venue names by:
	 * - Decoding HTML entities
	 * - Lowercasing
	 * - Removing articles ("the", "a", "an")
	 * - Stripping all non-alphanumeric characters (punctuation, dashes, apostrophes)
	 * - Collapsing whitespace
	 *
	 * This catches variants that exact name matching misses:
	 * - "Saturn - Birmingham" vs "Saturn Birmingham"
	 * - "Reggie's Rock Club" vs "Reggies Rock Club"
	 * - "RADIO/EAST" vs "Radio East"
	 * - "Emo's-Austin" vs "Emo's Austin"
	 * - "Lo-Fi Brewing" vs "Lofi Brewing"
	 *
	 * Requires the normalized name to be at least 3 characters to avoid
	 * false matches on very short names.
	 *
	 * @param string $venue_name Venue name to search for.
	 * @return \WP_Term|null Matching term or null.
	 */
	private static function find_venue_by_normalized_name( string $venue_name ): ?\WP_Term {
		$normalized_input = self::normalize_venue_name_for_matching( $venue_name );

		if ( strlen( $normalized_input ) < 3 ) {
			return null;
		}

		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $venues ) || empty( $venues ) ) {
			return null;
		}

		foreach ( $venues as $venue ) {
			$normalized_existing = self::normalize_venue_name_for_matching( $venue->name );

			if ( $normalized_input === $normalized_existing ) {
				do_action(
					'datamachine_log',
					'info',
					'Venue matched via normalized name',
					array(
						'input_name'    => $venue_name,
						'matched_name'  => $venue->name,
						'matched_id'    => $venue->term_id,
						'normalized_as' => $normalized_input,
					)
				);
				return $venue;
			}
		}

		return null;
	}

	/**
	 * Normalize a venue name for matching purposes.
	 *
	 * Delegates to Data Machine core's canonical, taxonomy-agnostic
	 * name normalizer (`ResolveTermAbility::normalize_name_for_matching`) so
	 * venue dedup shares one source of truth with every other taxonomy's
	 * fuzzy matching. Falls back to an inline copy of the same algorithm when
	 * core isn't loaded, so this class carries no hard version dependency.
	 *
	 * @param string $name Venue name.
	 * @return string Normalized name.
	 */
	public static function normalize_venue_name_for_matching( string $name ): string {
		if ( class_exists( '\\DataMachine\\Abilities\\Taxonomy\\ResolveTermAbility' )
			&& method_exists( '\\DataMachine\\Abilities\\Taxonomy\\ResolveTermAbility', 'normalize_name_for_matching' )
		) {
			return \DataMachine\Abilities\Taxonomy\ResolveTermAbility::normalize_name_for_matching( $name );
		}

		// Fallback: inline copy of the canonical algorithm (core not loaded).
		$text = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = remove_accents( $text );
		$text = strtolower( $text );
		$text = preg_replace( '/\s*&\s*/', ' and ', $text );
		$text = preg_replace( '/^(the|a|an)\s+/i', '', $text );
		$text = preg_replace( '/[^a-z0-9\s]/', '', $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return $text;
	}

	/**
	 * Public accessor for normalized name venue lookup.
	 *
	 * Used by EventDuplicateStrategy to resolve venue names that differ
	 * in punctuation, dashes, or case from the stored term name.
	 *
	 * @param string $venue_name Venue name to search for.
	 * @return \WP_Term|null Matching term or null.
	 */
	public static function find_venue_by_normalized_name_public( string $venue_name ): ?\WP_Term {
		return self::find_venue_by_normalized_name( $venue_name );
	}

	/**
	 * Public accessor for address-based venue lookup.
	 *
	 * Used by EventDuplicateStrategy to resolve an incoming venue to a
	 * canonical taxonomy term when the venue string differs from the
	 * stored term name but the address matches. This mirrors the
	 * address-first cascade used by find_or_create_venue().
	 *
	 * Returns the resolved \WP_Term (rather than just the term ID) so
	 * callers can use it the same way as find_venue_by_normalized_name_public().
	 *
	 * @param string $address Street address.
	 * @param string $city    City name.
	 * @return \WP_Term|null Matching term or null.
	 */
	public static function find_venue_by_address_public( string $address, string $city ): ?\WP_Term {
		$term_id = self::find_venue_by_address( $address, $city );

		if ( ! $term_id ) {
			return null;
		}

		$term = get_term( $term_id, 'venue' );

		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		return $term;
	}

	/**
	 * Smartly merge new venue data into existing venue
	 * Only updates fields that are currently empty in the database
	 *
	 * Composes MergeTermMetaAbility (fill_empty strategy) with the
	 * venue-specific post-write side effects (geocoding, timezone derivation).
	 *
	 * @param int $term_id Venue term ID
	 * @param array $venue_data New venue data
	 */
	private static function smart_merge_venue_meta( $term_id, $venue_data ) {
		$result = \DataMachine\Abilities\Taxonomy\MergeTermMetaAbility::merge(
			(int) $term_id,
			'venue',
			$venue_data,
			self::$meta_fields,
			\DataMachine\Abilities\Taxonomy\MergeTermMetaAbility::STRATEGY_FILL_EMPTY
		);

		if ( empty( $result['success'] ) ) {
			return;
		}

		$updated           = $result['updated'] ?? array();
		$address_fields    = array( 'address', 'city', 'state', 'zip', 'country' );
		$address_updated   = (bool) array_intersect( $updated, $address_fields );
		$coordinates_added = in_array( 'coordinates', $updated, true );

		if ( $address_updated ) {
			self::maybe_geocode_venue( $term_id );
		} elseif ( $coordinates_added ) {
			$coordinates = get_term_meta( $term_id, '_venue_coordinates', true );
			if ( ! empty( $coordinates ) ) {
				self::maybe_derive_timezone( $term_id, $coordinates );
			}
		}
	}

	/**
	 * Update venue term meta with venue data
	 *
	 * Supports selective updates - only updates fields present in $venue_data array.
	 * This allows updating only changed fields without overwriting unchanged ones.
	 * Automatically geocodes address to coordinates if address fields are updated.
	 *
	 * Composes MergeTermMetaAbility (overwrite strategy) with the venue-specific
	 * post-write side effects (clear-and-regeocode on address change, derive
	 * timezone when coordinates land).
	 *
	 * @param int $term_id Venue term ID
	 * @param array $venue_data Venue data array (can contain subset of fields)
	 * @return bool Success status
	 */
	public static function update_venue_meta( $term_id, $venue_data ) {
		if ( ! $term_id || ! is_array( $venue_data ) ) {
			return false;
		}

		$result = \DataMachine\Abilities\Taxonomy\MergeTermMetaAbility::merge(
			(int) $term_id,
			'venue',
			$venue_data,
			self::$meta_fields,
			\DataMachine\Abilities\Taxonomy\MergeTermMetaAbility::STRATEGY_OVERWRITE
		);

		if ( empty( $result['success'] ) ) {
			return false;
		}

		$updated         = $result['updated'] ?? array();
		$address_fields  = array( 'address', 'city', 'state', 'zip', 'country' );
		$address_changed = (bool) array_intersect( $updated, $address_fields );
		$coordinates_set = in_array( 'coordinates', $updated, true );

		if ( $address_changed ) {
			delete_term_meta( $term_id, '_venue_coordinates' );
			self::maybe_geocode_venue( $term_id );
		} elseif ( $coordinates_set ) {
			$coordinates = get_term_meta( $term_id, '_venue_coordinates', true );
			if ( ! empty( $coordinates ) ) {
				self::maybe_derive_timezone( $term_id, $coordinates );
			}
		}

		return true;
	}

	/**
	 * Geocode venue address if coordinates are missing
	 *
	 * @param int $term_id Venue term ID
	 * @return bool True if geocoding was performed, false otherwise
	 */
	public static function maybe_geocode_venue( $term_id ) {
		if ( ! $term_id ) {
			return false;
		}

		$existing_coords = get_term_meta( $term_id, '_venue_coordinates', true );
		if ( ! empty( $existing_coords ) ) {
			self::maybe_derive_timezone( $term_id, $existing_coords );
			return false;
		}

		$venue_data  = self::get_venue_data( $term_id );
		$coordinates = self::geocode_address( $venue_data );

		if ( $coordinates ) {
			update_term_meta( $term_id, '_venue_coordinates', $coordinates );
			self::maybe_derive_timezone( $term_id, $coordinates );
			return true;
		}

		return false;
	}

	/**
	 * Derive timezone from coordinates if timezone is missing
	 *
	 * Uses GeoNames API to lookup IANA timezone from lat/lng coordinates.
	 * Only runs if GeoNames username is configured in settings.
	 *
	 * @param int $term_id Venue term ID
	 * @param string $coordinates Coordinates as "lat,lng"
	 * @return bool True if timezone was derived and saved, false otherwise
	 */
	public static function maybe_derive_timezone( $term_id, $coordinates = '' ) {
		if ( ! $term_id ) {
			return false;
		}

		$existing_timezone = get_term_meta( $term_id, '_venue_timezone', true );
		if ( ! empty( $existing_timezone ) ) {
			return false;
		}

		if ( empty( $coordinates ) ) {
			$coordinates = get_term_meta( $term_id, '_venue_coordinates', true );
		}

		if ( empty( $coordinates ) ) {
			return false;
		}

		if ( ! GeoNamesService::isConfigured() ) {
			return false;
		}

		$timezone = GeoNamesService::getTimezoneFromCoordinates( $coordinates );

		if ( $timezone ) {
			update_term_meta( $term_id, '_venue_timezone', $timezone );
			return true;
		}

		return false;
	}

	/**
	 * Geocode an address using Nominatim API
	 *
	 * Tries multiple query strategies to handle messy address data from imports.
	 * Falls back to venue name + city if structured address queries fail.
	 *
	 * @param array $venue_data Venue data with address fields
	 * @return string|null Coordinates as "lat,lng" or null on failure
	 */
	public static function geocode_address( $venue_data ) {
		$queries = self::build_geocode_queries( $venue_data );

		if ( empty( $queries ) ) {
			return null;
		}

		foreach ( $queries as $strategy => $query ) {
			$coordinates = self::query_nominatim( $query );

			if ( $coordinates ) {
				do_action(
					'datamachine_log',
					'info',
					'Geocoding succeeded',
					array(
						'strategy' => $strategy,
						'query'    => $query,
						'result'   => $coordinates,
						'name'     => $venue_data['name'] ?? '',
					)
				);
				return $coordinates;
			}
		}

		do_action(
			'datamachine_log',
			'warning',
			'Geocoding failed all strategies',
			array(
				'queries' => $queries,
				'name'    => $venue_data['name'] ?? '',
			)
		);

		return null;
	}

	/**
	 * Build ordered list of geocoding query strategies
	 *
	 * Handles common import data issues:
	 * - Address field containing full address with city/state/zip already embedded
	 * - Suite/unit numbers that confuse Nominatim
	 * - HTML entities in venue names
	 * - Written-out numbers (e.g., "One Lincoln Financial Way")
	 *
	 * @param array $venue_data Venue data with address fields
	 * @return array Ordered queries keyed by strategy name
	 */
	private static function build_geocode_queries( array $venue_data ): array {
		$address = html_entity_decode( trim( $venue_data['address'] ?? '' ) );
		$city    = html_entity_decode( trim( $venue_data['city'] ?? '' ) );
		$state   = trim( $venue_data['state'] ?? '' );
		$zip     = trim( $venue_data['zip'] ?? '' );
		$country = trim( $venue_data['country'] ?? '' );
		$name    = html_entity_decode( trim( $venue_data['name'] ?? '' ) );

		$queries = array();

		if ( empty( $address ) && empty( $city ) && empty( $name ) ) {
			return $queries;
		}

		// Detect if address already contains city/state/zip (common with imports)
		$address_has_city = ! empty( $city ) && false !== stripos( $address, $city );

		// Clean the street portion of the address
		$street = $address;

		if ( $address_has_city ) {
			// Extract just the street part before the city
			$street = self::extract_street_from_address( $address, $city );
		}

		// Strip suite/unit/apartment suffixes that confuse Nominatim
		$street = preg_replace( '/,?\s*(?:Suite|Ste|Unit|Apt|#|Room|Rm)\s*[\w\-#]+.*/i', '', $street );
		// Strip "Located in: ..." annotations
		$street = preg_replace( '/,?\s*Located in:.*$/i', '', $street );
		$street = trim( $street, ', ' );

		// Strategy 1: Cleaned street + city + state + zip
		if ( ! empty( $street ) && ! empty( $city ) ) {
			$parts = array_filter( array( $street, $city, $state, $zip ) );
			$query = implode( ', ', $parts );
			if ( ! empty( $query ) ) {
				$queries['cleaned_address'] = $query;
			}
		}

		// Strategy 2: Street + city + state (no zip — zip sometimes causes false negatives)
		if ( ! empty( $street ) && ! empty( $city ) && ! empty( $state ) ) {
			$query = implode( ', ', array( $street, $city, $state ) );
			if ( ! isset( $queries['cleaned_address'] ) || $query !== $queries['cleaned_address'] ) {
				$queries['no_zip'] = $query;
			}
		}

		// Strategy 3: Venue name + city + state (Nominatim indexes many POIs by name)
		if ( ! empty( $name ) && ! empty( $city ) ) {
			$parts                  = array_filter( array( $name, $city, $state ) );
			$queries['name_lookup'] = implode( ', ', $parts );
		}

		// Strategy 4: Raw address field as-is (in case it's already a complete, well-formatted address)
		if ( ! empty( $address ) && $address !== ( $queries['cleaned_address'] ?? '' ) ) {
			$queries['raw_address'] = html_entity_decode( $address );
		}

		return $queries;
	}

	/**
	 * Extract just the street address from a full address string that contains city info
	 *
	 * @param string $address Full address string
	 * @param string $city City name to find
	 * @return string Street portion of the address
	 */
	private static function extract_street_from_address( string $address, string $city ): string {
		$parts        = preg_split( '/,\s*/', $address );
		$street_parts = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );

			// Stop if this part contains the city name
			if ( false !== stripos( $part, $city ) ) {
				break;
			}

			// Stop if this part looks like a state abbreviation
			if ( preg_match( '/^[A-Z]{2}$/', $part ) ) {
				break;
			}

			// Stop if this part looks like a zip code
			if ( preg_match( '/^\d{5}(-\d{4})?$/', $part ) ) {
				break;
			}

			// Stop if this part is a country
			if ( in_array( strtoupper( $part ), array( 'US', 'USA', 'UNITED STATES' ), true ) ) {
				break;
			}

			$street_parts[] = $part;
		}

		return ! empty( $street_parts ) ? implode( ', ', $street_parts ) : $address;
	}

	/**
	 * Query Nominatim API for coordinates.
	 *
	 * @deprecated 0.40.0 Use {@see NominatimClient::geocodeOne()} which
	 *             returns a richer array (`lat` / `lng` / `display_name` /
	 *             `cached`) instead of the legacy comma-joined string.
	 *             This method is preserved as a back-compat shim because
	 *             external consumers may still depend on the
	 *             `"lat,lng"` return contract.
	 *
	 * @param string $query Search query string.
	 * @return string|null Coordinates as "lat,lng" or null on failure.
	 */
	public static function query_nominatim( string $query ): ?string {
		if ( empty( $query ) ) {
			return null;
		}

		$result = NominatimClient::geocodeOne( $query );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();

			// 'geocode_failed' is the no-results case — legacy contract
			// surfaced that as a silent null. Other errors (transport,
			// invalid response) keep the original log line.
			if ( 'geocode_failed' !== $error_code ) {
				do_action(
					'datamachine_log',
					'error',
					'Geocoding request failed',
					array(
						'error' => $result->get_error_message(),
						'query' => $query,
					)
				);
			}

			return null;
		}

		if ( empty( $result['lat'] ) || empty( $result['lng'] ) ) {
			return null;
		}

		return $result['lat'] . ',' . $result['lng'];
	}

	/**
	 * Normalize address string for consistent comparison
	 *
	 * Strips suite/unit/apartment suffixes BEFORE running the
	 * street-suffix replacements so "3010 Minnehaha Ave STE 420"
	 * and "3010 Minnehaha Ave" collapse to the same key.
	 *
	 * @param string $address Raw address string
	 * @return string Normalized address for matching
	 */
	public static function normalize_address_for_matching( string $address ): string {
		$address = strtolower( trim( $address ) );

		// Strip suite/unit/apartment/room suffixes that produce false
		// non-matches for the same physical street address. Runs first
		// so the downstream replacements operate on the cleaned street.
		// Two passes:
		//   1. Word-prefixed suffix tokens (ste, suite, unit, apt, …) — \b works.
		//   2. Bare `#NNN` style — handled separately because `#` is not a
		//      word character and \b would not anchor it.
		$address = preg_replace(
			'/\b(ste|suite|unit|apt|apartment|room|rm)\s*[a-z0-9\-]+\b/i',
			'',
			$address
		);
		$address = preg_replace( '/#\s*[a-z0-9\-]+/i', '', $address );

		$replacements = array(
			'/\bstreet\b/'    => 'st',
			'/\bavenue\b/'    => 'ave',
			'/\bboulevard\b/' => 'blvd',
			'/\bdrive\b/'     => 'dr',
			'/\broad\b/'      => 'rd',
			'/\blane\b/'      => 'ln',
			'/\bcourt\b/'     => 'ct',
			'/\bsuite\b/'     => 'ste',
			'/\bapartment\b/' => 'apt',
			'/\bhighway\b/'   => 'hwy',
			'/\bparkway\b/'   => 'pkwy',
			'/\bplace\b/'     => 'pl',
			'/\bcircle\b/'    => 'cir',
			'/[.,#]/'         => '',
		);

		foreach ( $replacements as $pattern => $replacement ) {
			$address = preg_replace( $pattern, $replacement, $address );
		}

		// Collapse whitespace and strip stray trailing separators
		// (suite-suffix removal can leave a dangling comma).
		$address = preg_replace( '/\s+/', ' ', trim( $address ) );
		$address = trim( $address, ' ,-' );

		return $address;
	}

	/**
	 * Find existing venue by address and city
	 *
	 * @param string $address Street address
	 * @param string $city City name
	 * @return int|null Term ID if found, null otherwise
	 */
	public static function find_venue_by_address( string $address, string $city ): ?int {
		if ( empty( $address ) || empty( $city ) ) {
			return null;
		}

		$normalized_address = self::normalize_address_for_matching( $address );
		$normalized_city    = strtolower( trim( $city ) );

		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'meta_query' => array(
					array(
						'key'     => '_venue_city',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( is_wp_error( $venues ) || empty( $venues ) ) {
			return null;
		}

		foreach ( $venues as $venue ) {
			$venue_address = get_term_meta( $venue->term_id, '_venue_address', true );
			$venue_city    = get_term_meta( $venue->term_id, '_venue_city', true );

			if ( empty( $venue_address ) || empty( $venue_city ) ) {
				continue;
			}

			$venue_normalized_address = self::normalize_address_for_matching( $venue_address );
			$venue_normalized_city    = strtolower( trim( $venue_city ) );

			if ( $venue_normalized_address === $normalized_address &&
				$venue_normalized_city === $normalized_city ) {
				return $venue->term_id;
			}
		}

		return null;
	}

	/**
	 * Check if venue has any metadata populated
	 *
	 * @param int $term_id Venue term ID
	 * @return bool True if venue has at least one metadata field populated
	 */
	private static function has_venue_metadata( $term_id ) {
		if ( ! $term_id ) {
			return false;
		}

		foreach ( self::$meta_fields as $data_key => $meta_key ) {
			$value = get_term_meta( $term_id, $meta_key, true );
			if ( ! empty( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retrieves complete venue data with all 9 meta fields populated
	 */
	public static function get_venue_data( $term_id ) {
		$term = get_term( $term_id, 'venue' );
		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}

		$venue_data = array(
			'name'        => $term->name,
			'term_id'     => $term_id,
			'slug'        => $term->slug,
			'description' => $term->description,
		);

		foreach ( self::$meta_fields as $data_key => $meta_key ) {
			$venue_data[ $data_key ] = get_term_meta( $term_id, $meta_key, true );
		}

		return $venue_data;
	}

	/**
	 * Generate formatted address string from venue meta fields
	 *
	 * @param int $term_id Venue term ID
	 * @return string Formatted address string
	 */
	public static function get_formatted_address( $term_id, ?array $venue_data = null ) {
		if ( null === $venue_data ) {
			$venue_data = self::get_venue_data( $term_id );
		}

		$address_parts = array();

		if ( ! empty( $venue_data['address'] ) ) {
			$address_parts[] = $venue_data['address'];
		}

		$city_state = array();
		if ( ! empty( $venue_data['city'] ) ) {
			$city_state[] = $venue_data['city'];
		}
		if ( ! empty( $venue_data['state'] ) ) {
			$city_state[] = $venue_data['state'];
		}

		if ( ! empty( $city_state ) ) {
			$address_parts[] = implode( ', ', $city_state );
		}

		if ( ! empty( $venue_data['zip'] ) ) {
			$address_parts[] = $venue_data['zip'];
		}

		return implode( ', ', $address_parts );
	}

	public static function get_all_venues() {
		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $venues ) ) {
			return array();
		}

		$venue_data = array();
		foreach ( $venues as $venue ) {
			$venue_data[] = self::get_venue_data( $venue->term_id );
		}

		return $venue_data;
	}

	public static function get_venues_by_event_count( $min_events = 1 ) {
		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => true,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $venues ) ) {
			return array();
		}

		$venue_data = array();
		foreach ( $venues as $venue ) {
			if ( $venue->count >= $min_events ) {
				$venue_data[] = self::get_venue_data( $venue->term_id );
			}
		}

		return $venue_data;
	}

	private static function init_admin_hooks() {
		add_action( 'venue_add_form_fields', array( __CLASS__, 'add_venue_form_fields' ) );

		add_action( 'venue_edit_form_fields', array( __CLASS__, 'edit_venue_form_fields' ) );

		add_action( 'created_venue', array( __CLASS__, 'save_venue_meta' ) );

		add_action( 'edited_venue', array( __CLASS__, 'save_venue_meta' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_term_edit_assets' ) );
	}

	public static function add_venue_form_fields( $taxonomy ) {
		foreach ( self::$meta_fields as $key => $meta_key ) {
			$label = self::$field_labels[ $key ] ?? ucfirst( $key );
			echo '<div class="form-field">';
			echo "<label for='$meta_key'>$label</label>";

			if ( 'address' === $key ) {
				echo "<input type='text' name='$meta_key' id='$meta_key' value='' class='regular-text venue-address-autocomplete' ";
				echo "data-city-field='_venue_city' data-state-field='_venue_state' data-zip-field='_venue_zip' data-country-field='_venue_country' />";
			} else {
				echo "<input type='text' name='$meta_key' id='$meta_key' value='' class='regular-text' />";
			}

			echo '</div>';
		}
	}

	public static function edit_venue_form_fields( $term ) {
		foreach ( self::$meta_fields as $key => $meta_key ) {
			$label = self::$field_labels[ $key ] ?? ucfirst( $key );
			$value = get_term_meta( $term->term_id, $meta_key, true );
			echo '<tr class="form-field">';
			echo "<th scope='row'><label for='$meta_key'>$label</label></th>";

			if ( 'address' === $key ) {
				echo "<td><input type='text' name='$meta_key' id='$meta_key' value='" . esc_attr( $value ) . "' class='regular-text venue-address-autocomplete' ";
				echo "data-city-field='_venue_city' data-state-field='_venue_state' data-zip-field='_venue_zip' data-country-field='_venue_country' /></td>";
			} else {
				echo "<td><input type='text' name='$meta_key' id='$meta_key' value='" . esc_attr( $value ) . "' class='regular-text' /></td>";
			}

			echo '</tr>';
		}
	}

	public static function save_venue_meta( $term_id ) {
		$address_fields  = array( 'address', 'city', 'state', 'zip', 'country' );
		$address_updated = false;

		foreach ( self::$meta_fields as $key => $meta_key ) {
			if ( isset( $_POST[ $meta_key ] ) ) {
				$new_value = sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) );
				$old_value = get_term_meta( $term_id, $meta_key, true );

				if ( $new_value !== $old_value ) {
					update_term_meta( $term_id, $meta_key, $new_value );

					if ( in_array( $key, $address_fields, true ) ) {
						$address_updated = true;
					}
				}
			}
		}

		if ( $address_updated ) {
			delete_term_meta( $term_id, '_venue_coordinates' );
			self::maybe_geocode_venue( $term_id );
		}
	}

	public static function enqueue_term_edit_assets( $hook ) {
		if ( 'term.php' !== $hook && 'edit-tags.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'venue' !== $screen->taxonomy ) {
			return;
		}

		wp_enqueue_script(
			'data-machine-events-venue-autocomplete',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/js/venue-autocomplete.js',
			array(),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/js/venue-autocomplete.js' ),
			true
		);

		wp_enqueue_style(
			'data-machine-events-venue-autocomplete',
			DATA_MACHINE_EVENTS_PLUGIN_URL . 'assets/css/venue-autocomplete.css',
			array(),
			filemtime( DATA_MACHINE_EVENTS_PLUGIN_DIR . 'assets/css/venue-autocomplete.css' )
		);
	}
}
