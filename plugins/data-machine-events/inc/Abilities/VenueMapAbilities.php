<?php
/**
 * Venue Map Abilities
 *
 * Public ability for listing venues with coordinates, optionally filtered
 * by geo proximity or map viewport bounds. Powers the events-map block
 * frontend via the REST API.
 *
 * Performance:
 * - Bounds filtering uses SQL WHERE on venue_coordinates meta (not PHP loop)
 * - Results capped at 200 venues per request (safety valve)
 * - Taxonomy cross-reference uses a single SQL query instead of N+1
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Blocks\Calendar\Geo_Query;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueMapAbilities {

	/**
	 * Maximum venues returned per request.
	 */
	const MAX_VENUES = 200;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerListVenuesAbility();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	private function registerListVenuesAbility(): void {
		wp_register_ability(
			'data-machine-events/list-venues',
			array(
				'label'               => __( 'List Venues', 'data-machine-events' ),
				'description'         => __( 'List venues with coordinates for map rendering. Supports geo proximity and viewport bounds filtering.', 'data-machine-events' ),
				'category'            => 'datamachine-events/venues',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'lat'         => array(
							'type'        => 'number',
							'description' => 'Center latitude for proximity filtering',
						),
						'lng'         => array(
							'type'        => 'number',
							'description' => 'Center longitude for proximity filtering',
						),
						'radius'      => array(
							'type'        => 'integer',
							'description' => 'Search radius (default 25, max 500)',
						),
						'radius_unit' => array(
							'type'        => 'string',
							'description' => 'mi or km (default mi)',
							'enum'        => array( 'mi', 'km' ),
						),
						'bounds'      => array(
							'type'        => 'string',
							'description' => 'Map viewport bounds as sw_lat,sw_lng,ne_lat,ne_lng',
						),
						'taxonomy'    => array(
							'type'        => 'string',
							'description' => 'Filter by taxonomy slug (e.g. location)',
						),
						'term_id'     => array(
							'type'        => 'integer',
							'description' => 'Filter by taxonomy term ID',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'venues' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'term_id'     => array( 'type' => 'integer' ),
									'name'        => array( 'type' => 'string' ),
									'slug'        => array( 'type' => 'string' ),
									'lat'         => array( 'type' => 'number' ),
									'lon'         => array( 'type' => 'number' ),
									'address'     => array( 'type' => 'string' ),
									'url'         => array( 'type' => 'string' ),
									'event_count' => array( 'type' => 'integer' ),
									'distance'    => array( 'type' => 'number' ),
								),
							),
						),
						'total'  => array( 'type' => 'integer' ),
					'center' => array(
						'type'       => array( 'object', 'null' ),
						'properties' => array(
							'lat' => array( 'type' => 'number' ),
							'lng' => array( 'type' => 'number' ),
						),
					),
						'radius' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'executeListVenues' ),
				'permission_callback' => '__return_true',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Execute list venues.
	 *
	 * @param array $input Input parameters.
	 * @return array Venue list with optional distance data.
	 */
	public function executeListVenues( array $input ): array {
		$lat         = isset( $input['lat'] ) ? (float) $input['lat'] : null;
		$lng         = isset( $input['lng'] ) ? (float) $input['lng'] : null;
		$radius      = isset( $input['radius'] ) ? (int) $input['radius'] : 25;
		$radius_unit = $input['radius_unit'] ?? 'mi';
		$bounds      = $input['bounds'] ?? '';
		$taxonomy    = $input['taxonomy'] ?? '';
		$term_id     = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;

		$has_geo    = null !== $lat && null !== $lng && Geo_Query::validate_params( $lat, $lng, $radius );
		$has_bounds = ! empty( $bounds );

		// Parse bounds.
		$bounds_parsed = null;
		if ( $has_bounds ) {
			$parts = array_map( 'floatval', explode( ',', $bounds ) );
			if ( count( $parts ) === 4 ) {
				$bounds_parsed = array(
					'sw_lat' => $parts[0],
					'sw_lng' => $parts[1],
					'ne_lat' => $parts[2],
					'ne_lng' => $parts[3],
				);
			}
		}

		// Geo proximity mode: use Geo_Query to get venue IDs + distances.
		$distance_map  = array();
		$geo_venue_ids = null;

		if ( $has_geo && ! $has_bounds ) {
			$geo_results   = Geo_Query::find_venues_within_radius( $lat, $lng, $radius, $radius_unit );
			$geo_venue_ids = array_column( $geo_results, 'term_id' );

			foreach ( $geo_results as $row ) {
				$distance_map[ $row['term_id'] ] = $row['distance'];
			}

			if ( empty( $geo_venue_ids ) ) {
				return array(
					'venues' => array(),
					'total'  => 0,
					'center' => array( 'lat' => $lat, 'lng' => $lng ),
					'radius' => $radius,
				);
			}
		}

		// Taxonomy filter: get venue IDs with events matching the term.
		$taxonomy_venue_ids = null;
		if ( ! empty( $taxonomy ) && $term_id > 0 ) {
			$taxonomy_venue_ids = $this->getVenueIdsForTaxonomyTerm( $taxonomy, $term_id );
			if ( empty( $taxonomy_venue_ids ) ) {
				return array(
					'venues' => array(),
					'total'  => 0,
					'center' => $has_geo ? array( 'lat' => $lat, 'lng' => $lng ) : null,
					'radius' => $radius,
				);
			}
		}

		// Bounds mode: query venues with coordinates in SQL.
		if ( null !== $bounds_parsed ) {
			$venue_ids_filter = null;

			// Combine geo and taxonomy filters if both present.
			if ( null !== $geo_venue_ids && null !== $taxonomy_venue_ids ) {
				$venue_ids_filter = array_intersect( $geo_venue_ids, $taxonomy_venue_ids );
			} elseif ( null !== $geo_venue_ids ) {
				$venue_ids_filter = $geo_venue_ids;
			} elseif ( null !== $taxonomy_venue_ids ) {
				$venue_ids_filter = $taxonomy_venue_ids;
			}

			$venues = $this->queryVenuesByBounds( $bounds_parsed, $venue_ids_filter );
		} else {
			// Non-bounds mode: query all matching venues.
			$include_ids = null;

			if ( null !== $geo_venue_ids && null !== $taxonomy_venue_ids ) {
				$include_ids = array_intersect( $geo_venue_ids, $taxonomy_venue_ids );
			} elseif ( null !== $geo_venue_ids ) {
				$include_ids = $geo_venue_ids;
			} elseif ( null !== $taxonomy_venue_ids ) {
				$include_ids = $taxonomy_venue_ids;
			}

			$venues = $this->queryVenuesWithCoordinates( $include_ids );
		}

		// Add distance data if available.
		if ( ! empty( $distance_map ) ) {
			foreach ( $venues as &$venue ) {
				if ( isset( $distance_map[ $venue['term_id'] ] ) ) {
					$venue['distance'] = $distance_map[ $venue['term_id'] ];
				}
			}
			unset( $venue );
		}

		// Sort by distance if geo filtering, otherwise by event count.
		if ( $has_geo && ! empty( $distance_map ) ) {
			usort( $venues, function ( $a, $b ) {
				return ( $a['distance'] ?? PHP_INT_MAX ) <=> ( $b['distance'] ?? PHP_INT_MAX );
			} );
		} else {
			usort( $venues, function ( $a, $b ) {
				return $b['event_count'] <=> $a['event_count'];
			} );
		}

		// Cap results.
		$total  = count( $venues );
		$venues = array_slice( $venues, 0, self::MAX_VENUES );

		return array(
			'venues' => $venues,
			'total'  => $total,
			'center' => $has_geo ? array( 'lat' => $lat, 'lng' => $lng ) : null,
			'radius' => $radius,
		);
	}

	/**
	 * Query venues within map viewport bounds using SQL.
	 *
	 * Filters directly on _venue_coordinates meta in the database instead
	 * of loading all venues into PHP memory.
	 *
	 * @param array      $bounds         Parsed bounds (sw_lat, sw_lng, ne_lat, ne_lng).
	 * @param array|null $venue_ids_filter Optional array of venue IDs to restrict to.
	 * @return array Venue data arrays.
	 */
	private function queryVenuesByBounds( array $bounds, ?array $venue_ids_filter = null ): array {
		global $wpdb;

		// Use SQL to find venue term IDs with coordinates within bounds.
		// _venue_coordinates is stored as "lat,lng" string.
		$sw_lat = $bounds['sw_lat'];
		$ne_lat = $bounds['ne_lat'];
		$sw_lng = $bounds['sw_lng'];
		$ne_lng = $bounds['ne_lng'];

		$id_filter_sql = '';
		$id_filter_params = array();

		if ( null !== $venue_ids_filter ) {
			if ( empty( $venue_ids_filter ) ) {
				return array();
			}
			$placeholders = implode( ',', array_fill( 0, count( $venue_ids_filter ), '%d' ) );
			$id_filter_sql = " AND tm.term_id IN ($placeholders)";
			$id_filter_params = array_values( $venue_ids_filter );
		}

		// Extract lat/lng from "lat,lng" string using SUBSTRING_INDEX.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT tm.term_id,
				CAST(SUBSTRING_INDEX(tm.meta_value, ',', 1) AS DECIMAL(10,7)) AS lat,
				CAST(SUBSTRING_INDEX(tm.meta_value, ',', -1) AS DECIMAL(10,7)) AS lng
			FROM {$wpdb->termmeta} tm
			INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
			WHERE tt.taxonomy = 'venue'
			AND tm.meta_key = '_venue_coordinates'
			AND tm.meta_value != ''
			AND tm.meta_value IS NOT NULL
			AND CAST(SUBSTRING_INDEX(tm.meta_value, ',', 1) AS DECIMAL(10,7)) BETWEEN %f AND %f
			AND CAST(SUBSTRING_INDEX(tm.meta_value, ',', -1) AS DECIMAL(10,7)) BETWEEN %f AND %f
			{$id_filter_sql}
			LIMIT %d",
			array_merge(
				array( $sw_lat, $ne_lat, $sw_lng, $ne_lng ),
				$id_filter_params,
				array( self::MAX_VENUES + 50 ) // Fetch slightly more to allow for invalid entries.
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $query );

		if ( empty( $results ) ) {
			return array();
		}

		// Build venue data from matching term IDs.
		$venues = array();
		foreach ( $results as $row ) {
			$venue_lat = (float) $row->lat;
			$venue_lon = (float) $row->lng;

			if ( 0.0 === $venue_lat && 0.0 === $venue_lon ) {
				continue;
			}

			$term = get_term( (int) $row->term_id, 'venue' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$address = Venue_Taxonomy::get_formatted_address( $term->term_id );
			$url     = get_term_link( $term );

			$venues[] = array(
				'term_id'     => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'lat'         => $venue_lat,
				'lon'         => $venue_lon,
				'address'     => $address,
				'url'         => is_string( $url ) ? $url : '',
				'event_count' => $term->count,
			);
		}

		return $venues;
	}

	/**
	 * Query venues that have coordinates, optionally filtered by IDs.
	 *
	 * Used for non-bounds queries (geo proximity, taxonomy-only).
	 *
	 * @param array|null $include_ids Optional array of venue IDs to include.
	 * @return array Venue data arrays.
	 */
	private function queryVenuesWithCoordinates( ?array $include_ids = null ): array {
		$query_args = array(
			'taxonomy'   => 'venue',
			'hide_empty' => false,
			'number'     => self::MAX_VENUES + 50,
		);

		if ( null !== $include_ids ) {
			if ( empty( $include_ids ) ) {
				return array();
			}
			$query_args['include'] = $include_ids;
		}

		$all_venues = get_terms( $query_args );

		if ( is_wp_error( $all_venues ) || empty( $all_venues ) ) {
			return array();
		}

		$venues = array();
		foreach ( $all_venues as $venue ) {
			$coordinates = get_term_meta( $venue->term_id, '_venue_coordinates', true );
			if ( empty( $coordinates ) || strpos( $coordinates, ',' ) === false ) {
				continue;
			}

			$parts     = explode( ',', $coordinates );
			$venue_lat = floatval( trim( $parts[0] ) );
			$venue_lon = floatval( trim( $parts[1] ) );

			if ( 0.0 === $venue_lat && 0.0 === $venue_lon ) {
				continue;
			}

			$address = Venue_Taxonomy::get_formatted_address( $venue->term_id );
			$url     = get_term_link( $venue );

			$venues[] = array(
				'term_id'     => $venue->term_id,
				'name'        => $venue->name,
				'slug'        => $venue->slug,
				'lat'         => $venue_lat,
				'lon'         => $venue_lon,
				'address'     => $address,
				'url'         => is_string( $url ) ? $url : '',
				'event_count' => $venue->count,
			);
		}

		return $venues;
	}

	/**
	 * Get venue term IDs that have events matching a taxonomy term.
	 *
	 * Uses a single SQL query instead of N+1 wp_get_post_terms calls.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $term_id  Term ID.
	 * @return array Venue term IDs.
	 */
	private function getVenueIdsForTaxonomyTerm( string $taxonomy, int $term_id ): array {
		global $wpdb;

		$post_type = Event_Post_Type::POST_TYPE;

		// Single query: find all venue term IDs associated with events
		// that belong to the given taxonomy term.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT DISTINCT venue_tr.term_taxonomy_id AS venue_tt_id, venue_tt.term_id
			FROM {$wpdb->term_relationships} tax_tr
			INNER JOIN {$wpdb->term_taxonomy} tax_tt
				ON tax_tr.term_taxonomy_id = tax_tt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p
				ON tax_tr.object_id = p.ID
			INNER JOIN {$wpdb->term_relationships} venue_tr
				ON p.ID = venue_tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} venue_tt
				ON venue_tr.term_taxonomy_id = venue_tt.term_taxonomy_id
			WHERE tax_tt.taxonomy = %s
			AND tax_tt.term_id = %d
			AND p.post_type = %s
			AND p.post_status = 'publish'
			AND venue_tt.taxonomy = 'venue'",
			$taxonomy,
			$term_id,
			$post_type
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_col( $query, 1 );

		return array_map( 'intval', $results );
	}
}
