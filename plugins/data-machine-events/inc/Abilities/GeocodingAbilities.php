<?php
/**
 * Geocoding Abilities
 *
 * Provides abilities for address geocoding, batch venue geocoding, and venue data auditing.
 * Consolidates all Nominatim geocoding into the Abilities API as the universal primitive.
 *
 * Abilities:
 * - datamachine-events/geocode-address  — Geocode an arbitrary address string
 * - datamachine-events/geocode-venues   — Batch geocode venues missing coordinates
 * - datamachine-events/audit-venues     — Audit venue data quality and geocoding coverage
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Venue_Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GeocodingAbilities {

	/**
	 * Transient cache TTL for geocoded addresses (30 days).
	 */
	private const CACHE_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Transient prefix for cached geocoding results.
	 */
	private const CACHE_PREFIX = 'dme_geocode_';

	/**
	 * Rate limit: seconds between Nominatim requests.
	 */
	private const RATE_LIMIT_SECONDS = 2;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerGeocodeAddressAbility();
			$this->registerGeocodeVenuesAbility();
			$this->registerAuditVenuesAbility();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// -------------------------------------------------------------------------
	// Ability: geocode-address
	// -------------------------------------------------------------------------

	private function registerGeocodeAddressAbility(): void {
		wp_register_ability(
			'datamachine-events/geocode-address',
			array(
				'label'               => __( 'Geocode Address', 'datamachine-events' ),
				'description'         => __( 'Geocode an address string to lat/lng coordinates via OpenStreetMap Nominatim. Results are cached for 30 days.', 'datamachine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'query' ),
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Address string to geocode (e.g., "1505 Town Creek Dr, Austin, TX 78741")',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'lat'          => array( 'type' => 'string' ),
						'lng'          => array( 'type' => 'string' ),
						'display_name' => array( 'type' => 'string' ),
						'cached'       => array( 'type' => 'boolean' ),
						'error'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGeocodeAddress' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute geocode-address ability.
	 *
	 * @param array $input Input with 'query' string.
	 * @return array Result with lat, lng, display_name, cached.
	 */
	public function executeGeocodeAddress( array $input ): array {
		$query = trim( $input['query'] ?? '' );

		if ( empty( $query ) || strlen( $query ) < 3 ) {
			return array( 'error' => 'Query must be at least 3 characters.' );
		}

		// Sanitize
		$query = sanitize_text_field( $query );
		$query = substr( $query, 0, 500 );

		// Check cache
		$cache_key = self::CACHE_PREFIX . md5( strtolower( $query ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		// Query Nominatim via the Venue_Taxonomy method
		$coordinates = Venue_Taxonomy::query_nominatim( $query );

		if ( ! $coordinates ) {
			return array( 'error' => 'Could not geocode address: no results from Nominatim.' );
		}

		$parts = explode( ',', $coordinates );

		$result = array(
			'lat'          => $parts[0],
			'lng'          => $parts[1],
			'display_name' => $query,
			'cached'       => false,
		);

		// Cache result
		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	// -------------------------------------------------------------------------
	// Ability: geocode-venues
	// -------------------------------------------------------------------------

	private function registerGeocodeVenuesAbility(): void {
		wp_register_ability(
			'datamachine-events/geocode-venues',
			array(
				'label'               => __( 'Geocode Venues', 'datamachine-events' ),
				'description'         => __( 'Batch geocode venues that have an address but are missing coordinates. Respects Nominatim rate limits.', 'datamachine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'venue_id' => array(
							'type'        => 'integer',
							'description' => 'Geocode a specific venue by term ID (optional, omit for all)',
						),
						'force'    => array(
							'type'        => 'boolean',
							'description' => 'Re-geocode even if coordinates already exist (default: false)',
						),
						'dry_run'  => array(
							'type'        => 'boolean',
							'description' => 'Show what would be geocoded without doing it (default: false)',
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => 'Max venues to process in one batch (default: 50)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'processed' => array( 'type' => 'integer' ),
						'success'   => array( 'type' => 'integer' ),
						'failed'    => array( 'type' => 'integer' ),
						'skipped'   => array( 'type' => 'integer' ),
						'results'   => array( 'type' => 'array' ),
						'message'   => array( 'type' => 'string' ),
						'error'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGeocodeVenues' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute geocode-venues ability.
	 *
	 * @param array $input Input with optional venue_id, force, dry_run, limit.
	 * @return array Batch results.
	 */
	public function executeGeocodeVenues( array $input ): array {
		$venue_id = $input['venue_id'] ?? null;
		$force    = (bool) ( $input['force'] ?? false );
		$dry_run  = (bool) ( $input['dry_run'] ?? false );
		$limit    = (int) ( $input['limit'] ?? 50 );

		if ( $limit <= 0 ) {
			$limit = 50;
		}

		// Single venue mode.
		if ( $venue_id ) {
			$term = get_term( (int) $venue_id, 'venue' );
			if ( ! $term || is_wp_error( $term ) ) {
				return array( 'error' => "Venue term ID {$venue_id} not found." );
			}
			$venues = array( $term );
		} else {
			$venues = get_terms(
				array(
					'taxonomy'   => 'venue',
					'hide_empty' => false,
					'number'     => 0,
				)
			);

			if ( is_wp_error( $venues ) ) {
				return array( 'error' => 'Failed to query venues: ' . $venues->get_error_message() );
			}
		}

		$results   = array();
		$success   = 0;
		$failed    = 0;
		$skipped   = 0;
		$processed = 0;

		foreach ( $venues as $venue ) {
			if ( $processed >= $limit ) {
				break;
			}

			$address = get_term_meta( $venue->term_id, '_venue_address', true );
			$coords  = get_term_meta( $venue->term_id, '_venue_coordinates', true );
			$city    = get_term_meta( $venue->term_id, '_venue_city', true );

			// Skip venues with no address data at all.
			if ( empty( $address ) && empty( $city ) ) {
				++$skipped;
				continue;
			}

			// Skip venues that already have coordinates (unless forced).
			if ( ! empty( $coords ) && ! $force ) {
				++$skipped;
				continue;
			}

			$venue_result = array(
				'term_id' => $venue->term_id,
				'name'    => html_entity_decode( $venue->name ),
				'address' => $address,
				'city'    => $city,
			);

			if ( $dry_run ) {
				$venue_result['action'] = 'would_geocode';
				$results[]              = $venue_result;
				++$processed;
				continue;
			}

			// Clear existing coordinates if force mode.
			if ( $force && ! empty( $coords ) ) {
				delete_term_meta( $venue->term_id, '_venue_coordinates' );
			}

			$geocoded = Venue_Taxonomy::maybe_geocode_venue( $venue->term_id );

			if ( $geocoded ) {
				$new_coords                 = get_term_meta( $venue->term_id, '_venue_coordinates', true );
				$venue_result['action']     = 'geocoded';
				$venue_result['coordinates'] = $new_coords;
				++$success;
			} else {
				$venue_result['action'] = 'failed';
				++$failed;
			}

			$results[] = $venue_result;
			++$processed;

			// Rate limit — respect Nominatim's usage policy.
			if ( $processed < $limit ) {
				sleep( self::RATE_LIMIT_SECONDS );
			}
		}

		$message_parts = array();
		if ( $dry_run ) {
			$message_parts[] = "Dry run: {$processed} venues would be geocoded";
		} else {
			if ( $success > 0 ) {
				$message_parts[] = "{$success} geocoded";
			}
			if ( $failed > 0 ) {
				$message_parts[] = "{$failed} failed";
			}
			if ( $skipped > 0 ) {
				$message_parts[] = "{$skipped} skipped";
			}
		}

		return array(
			'processed' => $processed,
			'success'   => $success,
			'failed'    => $failed,
			'skipped'   => $skipped,
			'results'   => $results,
			'message'   => implode( ', ', $message_parts ) . '.',
		);
	}

	// -------------------------------------------------------------------------
	// Ability: audit-venues
	// -------------------------------------------------------------------------

	private function registerAuditVenuesAbility(): void {
		wp_register_ability(
			'datamachine-events/audit-venues',
			array(
				'label'               => __( 'Audit Venues', 'datamachine-events' ),
				'description'         => __( 'Audit venue data quality: geocoding coverage, missing addresses, missing timezones. Returns a comprehensive data quality report.', 'datamachine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'format' => array(
							'type'        => 'string',
							'description' => 'Output format: "summary" (counts only) or "detailed" (includes venue lists). Default: summary.',
							'enum'        => array( 'summary', 'detailed' ),
						),
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'Max venues to list per category in detailed mode (default: 25)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total_venues'            => array( 'type' => 'integer' ),
						'geocoded'                => array( 'type' => 'object' ),
						'missing_coordinates'     => array( 'type' => 'object' ),
						'missing_address'         => array( 'type' => 'object' ),
						'has_address_no_coords'   => array( 'type' => 'object' ),
						'missing_timezone'        => array( 'type' => 'object' ),
						'coverage_percent'        => array( 'type' => 'number' ),
						'message'                 => array( 'type' => 'string' ),
						'error'                   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeAuditVenues' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute audit-venues ability.
	 *
	 * @param array $input Input with optional format and limit.
	 * @return array Audit results.
	 */
	public function executeAuditVenues( array $input ): array {
		$format = $input['format'] ?? 'summary';
		$limit  = (int) ( $input['limit'] ?? 25 );

		if ( $limit <= 0 ) {
			$limit = 25;
		}

		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $venues ) ) {
			return array( 'error' => 'Failed to query venues: ' . $venues->get_error_message() );
		}

		$total                = count( $venues );
		$geocoded_list        = array();
		$missing_coords_list  = array();
		$missing_address_list = array();
		$has_addr_no_coords   = array();
		$missing_tz_list      = array();

		foreach ( $venues as $venue ) {
			$address     = get_term_meta( $venue->term_id, '_venue_address', true );
			$city        = get_term_meta( $venue->term_id, '_venue_city', true );
			$coords      = get_term_meta( $venue->term_id, '_venue_coordinates', true );
			$timezone    = get_term_meta( $venue->term_id, '_venue_timezone', true );

			$venue_info = array(
				'term_id'     => $venue->term_id,
				'name'        => html_entity_decode( $venue->name ),
				'event_count' => $venue->count,
			);

			if ( ! empty( $coords ) ) {
				$geocoded_list[] = $venue_info;
			} else {
				$venue_info['address'] = $address;
				$venue_info['city']    = $city;
				$missing_coords_list[] = $venue_info;

				if ( ! empty( $address ) || ! empty( $city ) ) {
					$has_addr_no_coords[] = $venue_info;
				}
			}

			if ( empty( $address ) && empty( $city ) ) {
				$missing_address_list[] = $venue_info;
			}

			if ( ! empty( $coords ) && empty( $timezone ) ) {
				$missing_tz_list[] = $venue_info;
			}
		}

		// Sort by event count (most impactful venues first).
		$sort_by_events = fn( $a, $b ) => $b['event_count'] <=> $a['event_count'];
		usort( $missing_coords_list, $sort_by_events );
		usort( $missing_address_list, $sort_by_events );
		usort( $has_addr_no_coords, $sort_by_events );
		usort( $missing_tz_list, $sort_by_events );

		$geocoded_count   = count( $geocoded_list );
		$coverage_percent = $total > 0 ? round( ( $geocoded_count / $total ) * 100, 1 ) : 0;

		$result = array(
			'total_venues'    => $total,
			'coverage_percent' => $coverage_percent,
			'geocoded'         => array(
				'count' => $geocoded_count,
			),
			'missing_coordinates' => array(
				'count' => count( $missing_coords_list ),
			),
			'missing_address' => array(
				'count' => count( $missing_address_list ),
			),
			'has_address_no_coords' => array(
				'count' => count( $has_addr_no_coords ),
			),
			'missing_timezone' => array(
				'count' => count( $missing_tz_list ),
			),
		);

		// Add venue lists in detailed mode.
		if ( 'detailed' === $format ) {
			$result['missing_coordinates']['venues']     = array_slice( $missing_coords_list, 0, $limit );
			$result['missing_address']['venues']         = array_slice( $missing_address_list, 0, $limit );
			$result['has_address_no_coords']['venues']   = array_slice( $has_addr_no_coords, 0, $limit );
			$result['missing_timezone']['venues']        = array_slice( $missing_tz_list, 0, $limit );
		}

		// Build summary message.
		$message = sprintf(
			'%d/%d venues geocoded (%.1f%% coverage). %d missing address, %d have address but no coords, %d missing timezone.',
			$geocoded_count,
			$total,
			$coverage_percent,
			count( $missing_address_list ),
			count( $has_addr_no_coords ),
			count( $missing_tz_list )
		);

		if ( $coverage_percent >= 95 ) {
			$message .= ' Coverage target met.';
		} else {
			$message .= sprintf( ' Need %d more to reach 95%% coverage.', (int) ceil( $total * 0.95 ) - $geocoded_count );
		}

		$result['message'] = $message;

		return $result;
	}
}
