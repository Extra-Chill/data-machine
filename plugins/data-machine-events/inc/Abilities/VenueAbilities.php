<?php
/**
 * Venue Abilities
 *
 * Provides abilities for venue management: health checks, updates, retrieval,
 * and duplicate detection. Chat tools and REST controllers delegate to these
 * abilities for business logic.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Venue_Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueAbilities {

	private const DEFAULT_LIMIT = 25;

	private const TICKET_PLATFORM_DOMAINS = array(
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

	private const SUSPICIOUS_PATH_PATTERNS = array(
		'/event/',
		'/events/',
		'/e/',
		'/tickets/',
		'/shows/',
		'/tour/',
	);

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerHealthCheckAbility();
			$this->registerUpdateVenueAbility();
			$this->registerGetVenueAbility();
			$this->registerCheckDuplicateAbility();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerHealthCheckAbility(): void {
		wp_register_ability(
			'data-machine-events/venue-health-check',
			array(
				'label'               => __( 'Venue Health Check', 'data-machine-events' ),
				'description'         => __( 'Scan venues for data quality issues: missing address, coordinates, timezone, or website. Also detects suspicious websites where a ticket URL was mistakenly stored as venue website.', 'data-machine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Max venues to return per issue category (default: 25)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total_venues'        => array( 'type' => 'integer' ),
						'missing_address'     => array(
							'type'       => 'object',
							'properties' => array(
								'count'  => array( 'type' => 'integer' ),
								'venues' => array( 'type' => 'array' ),
							),
						),
						'missing_coordinates' => array(
							'type'       => 'object',
							'properties' => array(
								'count'  => array( 'type' => 'integer' ),
								'venues' => array( 'type' => 'array' ),
							),
						),
						'missing_timezone'    => array(
							'type'       => 'object',
							'properties' => array(
								'count'  => array( 'type' => 'integer' ),
								'venues' => array( 'type' => 'array' ),
							),
						),
						'missing_website'     => array(
							'type'       => 'object',
							'properties' => array(
								'count'  => array( 'type' => 'integer' ),
								'venues' => array( 'type' => 'array' ),
							),
						),
						'suspicious_website'  => array(
							'type'       => 'object',
							'properties' => array(
								'count'  => array( 'type' => 'integer' ),
								'venues' => array( 'type' => 'array' ),
							),
						),
						'message'             => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeHealthCheck' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerUpdateVenueAbility(): void {
		wp_register_ability(
			'data-machine-events/update-venue',
			array(
				'label'               => __( 'Update Venue', 'data-machine-events' ),
				'description'         => __( 'Update a venue name and/or meta fields. Address changes trigger automatic geocoding.', 'data-machine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'venue' ),
					'properties' => array(
						'venue'       => array(
							'type'        => 'string',
							'description' => 'Venue identifier (term ID, name, or slug)',
						),
						'name'        => array(
							'type'        => 'string',
							'description' => 'New venue name',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Venue description',
						),
						'address'     => array(
							'type'        => 'string',
							'description' => 'Street address',
						),
						'city'        => array(
							'type'        => 'string',
							'description' => 'City',
						),
						'state'       => array(
							'type'        => 'string',
							'description' => 'State/region',
						),
						'zip'         => array(
							'type'        => 'string',
							'description' => 'Postal/ZIP code',
						),
						'country'     => array(
							'type'        => 'string',
							'description' => 'Country',
						),
						'phone'       => array(
							'type'        => 'string',
							'description' => 'Phone number',
						),
						'website'     => array(
							'type'        => 'string',
							'description' => 'Website URL',
						),
						'capacity'    => array(
							'type'        => 'string',
							'description' => 'Venue capacity',
						),
						'coordinates' => array(
							'type'        => 'string',
							'description' => 'GPS coordinates as "lat,lng"',
						),
						'timezone'    => array(
							'type'        => 'string',
							'description' => 'IANA timezone identifier (e.g., America/New_York)',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'term_id'        => array( 'type' => 'integer' ),
						'name'           => array( 'type' => 'string' ),
						'updated_fields' => array( 'type' => 'array' ),
						'venue_data'     => array( 'type' => 'object' ),
						'message'        => array( 'type' => 'string' ),
						'error'          => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeUpdateVenue' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerGetVenueAbility(): void {
		wp_register_ability(
			'data-machine-events/get-venue',
			array(
				'label'               => __( 'Get Venue', 'data-machine-events' ),
				'description'         => __( 'Get venue details by term ID', 'data-machine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Venue term ID',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'        => array( 'type' => 'string' ),
						'term_id'     => array( 'type' => 'integer' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'address'     => array( 'type' => 'string' ),
						'city'        => array( 'type' => 'string' ),
						'state'       => array( 'type' => 'string' ),
						'zip'         => array( 'type' => 'string' ),
						'country'     => array( 'type' => 'string' ),
						'phone'       => array( 'type' => 'string' ),
						'website'     => array( 'type' => 'string' ),
						'capacity'    => array( 'type' => 'string' ),
						'coordinates' => array( 'type' => 'string' ),
						'timezone'    => array( 'type' => 'string' ),
						'error'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetVenue' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerCheckDuplicateAbility(): void {
		wp_register_ability(
			'data-machine-events/check-duplicate-venue',
			array(
				'label'               => __( 'Check Duplicate Venue', 'data-machine-events' ),
				'description'         => __( 'Check if a venue with the given name already exists', 'data-machine-events' ),
				'category'            => 'datamachine',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'name'    => array(
							'type'        => 'string',
							'description' => 'Venue name to check',
						),
						'address' => array(
							'type'        => 'string',
							'description' => 'Optional address for more accurate matching',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'is_duplicate'        => array( 'type' => 'boolean' ),
						'existing_term_id'    => array( 'type' => 'integer' ),
						'existing_venue_name' => array( 'type' => 'string' ),
						'message'             => array( 'type' => 'string' ),
						'error'               => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeCheckDuplicate' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute venue health check.
	 *
	 * @param array $input Input parameters with optional 'limit'
	 * @return array Health check results with category counts and venue lists
	 */
	public function executeHealthCheck( array $input ): array {
		$limit = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );
		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $venues ) ) {
			return array(
				'error' => 'Failed to query venues: ' . $venues->get_error_message(),
			);
		}

		if ( empty( $venues ) ) {
			return array(
				'total_venues' => 0,
				'message'      => 'No venues found in the system.',
			);
		}

		$missing_address     = array();
		$missing_coordinates = array();
		$missing_timezone    = array();
		$missing_website     = array();
		$suspicious_website  = array();

		foreach ( $venues as $venue ) {
			$address     = get_term_meta( $venue->term_id, '_venue_address', true );
			$city        = get_term_meta( $venue->term_id, '_venue_city', true );
			$coordinates = get_term_meta( $venue->term_id, '_venue_coordinates', true );
			$timezone    = get_term_meta( $venue->term_id, '_venue_timezone', true );

			$venue_info = array(
				'term_id'     => $venue->term_id,
				'name'        => $venue->name,
				'event_count' => $venue->count,
			);

			if ( empty( $address ) && empty( $city ) ) {
				$missing_address[] = $venue_info;
			}

			if ( empty( $coordinates ) ) {
				$missing_coordinates[] = $venue_info;
			}

			if ( ! empty( $coordinates ) && empty( $timezone ) ) {
				$missing_timezone[] = $venue_info;
			}

			$website = get_term_meta( $venue->term_id, '_venue_website', true );

			if ( empty( $website ) ) {
				$missing_website[] = $venue_info;
			} else {
				$suspicion = self::checkSuspiciousWebsite( $website );
				if ( $suspicion ) {
					$venue_info['website']          = $website;
					$venue_info['suspicion_reason'] = $suspicion;
					$suspicious_website[]           = $venue_info;
				}
			}
		}

		$total = count( $venues );

		$sort_by_events = fn( $a, $b ) => $b['event_count'] <=> $a['event_count'];
		usort( $missing_address, $sort_by_events );
		usort( $missing_coordinates, $sort_by_events );
		usort( $missing_timezone, $sort_by_events );
		usort( $missing_website, $sort_by_events );
		usort( $suspicious_website, $sort_by_events );

		$message_parts = array();
		if ( ! empty( $missing_address ) ) {
			$message_parts[] = count( $missing_address ) . ' missing address';
		}
		if ( ! empty( $missing_coordinates ) ) {
			$message_parts[] = count( $missing_coordinates ) . ' missing coordinates';
		}
		if ( ! empty( $missing_timezone ) ) {
			$message_parts[] = count( $missing_timezone ) . ' missing timezone';
		}
		if ( ! empty( $missing_website ) ) {
			$message_parts[] = count( $missing_website ) . ' missing website';
		}
		if ( ! empty( $suspicious_website ) ) {
			$message_parts[] = count( $suspicious_website ) . ' suspicious website (possible ticket URL)';
		}

		if ( empty( $message_parts ) ) {
			$message = "All {$total} venues have complete data.";
		} else {
			$message = 'Found issues: ' . implode( ', ', $message_parts ) . '. Use update_venue tool to fix.';
		}

		return array(
			'total_venues'        => $total,
			'missing_address'     => array(
				'count'  => count( $missing_address ),
				'venues' => array_slice( $missing_address, 0, $limit ),
			),
			'missing_coordinates' => array(
				'count'  => count( $missing_coordinates ),
				'venues' => array_slice( $missing_coordinates, 0, $limit ),
			),
			'missing_timezone'    => array(
				'count'  => count( $missing_timezone ),
				'venues' => array_slice( $missing_timezone, 0, $limit ),
			),
			'missing_website'     => array(
				'count'  => count( $missing_website ),
				'venues' => array_slice( $missing_website, 0, $limit ),
			),
			'suspicious_website'  => array(
				'count'  => count( $suspicious_website ),
				'venues' => array_slice( $suspicious_website, 0, $limit ),
			),
			'message'             => $message,
		);
	}

	/**
	 * Execute venue update.
	 *
	 * @param array $input Input parameters with 'venue' identifier and optional fields
	 * @return array Update result with venue data or error
	 */
	public function executeUpdateVenue( array $input ): array {
		$venue_identifier = $input['venue'] ?? null;

		if ( empty( $venue_identifier ) ) {
			return array(
				'error' => 'venue parameter is required',
			);
		}

		$term = $this->resolveVenue( $venue_identifier );
		if ( ! $term ) {
			return array(
				'error' => "Venue '{$venue_identifier}' not found",
			);
		}

		$updated_fields = array();

		$term_updates = array();
		if ( ! empty( $input['name'] ) ) {
			$term_updates['name'] = sanitize_text_field( $input['name'] );
			$updated_fields[]     = 'name';
		}
		if ( isset( $input['description'] ) && '' !== $input['description'] ) {
			$term_updates['description'] = wp_kses_post( $input['description'] );
			$updated_fields[]            = 'description';
		}

		if ( ! empty( $term_updates ) ) {
			$result = wp_update_term( $term->term_id, 'venue', $term_updates );
			if ( is_wp_error( $result ) ) {
				return array(
					'error' => 'Failed to update venue: ' . $result->get_error_message(),
				);
			}
		}

		$meta_keys = array( 'address', 'city', 'state', 'zip', 'country', 'phone', 'website', 'capacity', 'coordinates', 'timezone' );
		$meta_data = array();

		foreach ( $meta_keys as $key ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] && '' !== $input[ $key ] ) {
				$meta_data[ $key ] = $input[ $key ];
				$updated_fields[]  = $key;
			}
		}

		if ( ! empty( $meta_data ) ) {
			Venue_Taxonomy::update_venue_meta( $term->term_id, $meta_data );
		}

		if ( empty( $updated_fields ) ) {
			return array(
				'error' => 'No fields provided to update',
			);
		}

		$updated_term = get_term( $term->term_id, 'venue' );
		$venue_data   = Venue_Taxonomy::get_venue_data( $term->term_id );

		return array(
			'term_id'        => $term->term_id,
			'name'           => $updated_term->name,
			'updated_fields' => $updated_fields,
			'venue_data'     => $venue_data,
			'message'        => "Updated venue '{$updated_term->name}': " . implode( ', ', $updated_fields ),
		);
	}

	/**
	 * Execute get venue.
	 *
	 * @param array $input Input parameters with 'id'
	 * @return array Venue data or error
	 */
	public function executeGetVenue( array $input ): array {
		$term_id = $input['id'] ?? null;

		if ( empty( $term_id ) ) {
			return array(
				'error' => 'Venue ID is required',
			);
		}

		$venue_data = Venue_Taxonomy::get_venue_data( $term_id );

		if ( empty( $venue_data ) ) {
			return array(
				'error' => 'Venue not found',
			);
		}

		return $venue_data;
	}

	/**
	 * Execute check duplicate venue.
	 *
	 * @param array $input Input parameters with 'name' and optional 'address'
	 * @return array Duplicate check result
	 */
	public function executeCheckDuplicate( array $input ): array {
		$venue_name    = $input['name'] ?? null;
		$venue_address = $input['address'] ?? '';

		if ( empty( $venue_name ) ) {
			return array(
				'error' => 'Venue name is required',
			);
		}

		$existing_term = get_term_by( 'name', $venue_name, 'venue' );

		if ( ! $existing_term ) {
			return array(
				'is_duplicate' => false,
				'message'      => '',
			);
		}

		if ( ! empty( $venue_address ) ) {
			$existing_address = get_term_meta( $existing_term->term_id, '_venue_address', true );

			$normalized_new      = strtolower( trim( $venue_address ) );
			$normalized_existing = strtolower( trim( $existing_address ) );

			if ( $normalized_new === $normalized_existing ) {
				return array(
					'is_duplicate'        => true,
					'existing_term_id'    => $existing_term->term_id,
					'existing_venue_name' => $existing_term->name,
					'message'             => sprintf(
						/* translators: %s: venue name */
						__( 'A venue named "%s" with this address already exists.', 'data-machine-events' ),
						esc_html( $existing_term->name )
					),
				);
			}
		}

		return array(
			'is_duplicate'        => true,
			'existing_term_id'    => $existing_term->term_id,
			'existing_venue_name' => $existing_term->name,
			'message'             => sprintf(
				/* translators: %s: venue name */
				__( 'A venue named "%s" already exists. Consider using a more specific name or check if this is the same venue.', 'data-machine-events' ),
				esc_html( $existing_term->name )
			),
		);
	}

	/**
	 * Resolve venue by ID, name, or slug.
	 *
	 * @param string $identifier Venue identifier
	 * @return \WP_Term|null Term object or null if not found
	 */
	private function resolveVenue( string $identifier ): ?\WP_Term {
		if ( is_numeric( $identifier ) ) {
			$term = get_term( (int) $identifier, 'venue' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		$term = get_term_by( 'name', $identifier, 'venue' );
		if ( $term ) {
			return $term;
		}

		$term = get_term_by( 'slug', $identifier, 'venue' );
		if ( $term ) {
			return $term;
		}

		return null;
	}

	/**
	 * Check if a URL looks like a ticket/event URL rather than a venue website.
	 *
	 * @param string $url Website URL to check
	 * @return string|null Suspicion reason, or null if URL looks legitimate
	 */
	private static function checkSuspiciousWebsite( string $url ): ?string {
		if ( empty( $url ) ) {
			return null;
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return null;
		}

		$host = strtolower( $parsed['host'] );

		foreach ( self::TICKET_PLATFORM_DOMAINS as $domain ) {
			if ( str_contains( $host, $domain ) ) {
				return 'ticket_platform_domain';
			}
		}

		$path = strtolower( $parsed['path'] ?? '' );
		foreach ( self::SUSPICIOUS_PATH_PATTERNS as $pattern ) {
			if ( str_contains( $path, $pattern ) ) {
				return 'event_url_path';
			}
		}

		return null;
	}
}
