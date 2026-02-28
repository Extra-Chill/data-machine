<?php
/**
 * Venue Map Abilities
 *
 * Public ability for listing venues with coordinates, optionally filtered
 * by geo proximity or map viewport bounds. Powers the events-map block
 * frontend via the REST API.
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
			'datamachine-events/list-venues',
			array(
				'label'               => __( 'List Venues', 'datamachine-events' ),
				'description'         => __( 'List venues with coordinates for map rendering. Supports geo proximity and viewport bounds filtering.', 'datamachine-events' ),
				'category'            => 'datamachine',
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
							'type'       => 'object',
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

		// If bounds provided, parse and use bounding box filter.
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

		// Geo proximity: use Geo_Query to get venue IDs + distances.
		$distance_map = array();
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

		// Query venues.
		$query_args = array(
			'taxonomy'   => 'venue',
			'hide_empty' => false,
			'number'     => 0,
		);

		if ( null !== $geo_venue_ids ) {
			$query_args['include'] = $geo_venue_ids;
		}

		$all_venues = get_terms( $query_args );

		if ( is_wp_error( $all_venues ) || empty( $all_venues ) ) {
			return array(
				'venues' => array(),
				'total'  => 0,
				'center' => $has_geo ? array( 'lat' => $lat, 'lng' => $lng ) : null,
				'radius' => $radius,
			);
		}

		// If taxonomy filter, get event post IDs matching and cross-reference venues.
		$taxonomy_venue_ids = null;
		if ( ! empty( $taxonomy ) && $term_id > 0 ) {
			$taxonomy_venue_ids = $this->getVenueIdsForTaxonomyTerm( $taxonomy, $term_id );
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

			// Bounds filter.
			if ( null !== $bounds_parsed ) {
				if ( $venue_lat < $bounds_parsed['sw_lat'] || $venue_lat > $bounds_parsed['ne_lat'] ) {
					continue;
				}
				// Handle antimeridian wrap.
				if ( $bounds_parsed['sw_lng'] <= $bounds_parsed['ne_lng'] ) {
					if ( $venue_lon < $bounds_parsed['sw_lng'] || $venue_lon > $bounds_parsed['ne_lng'] ) {
						continue;
					}
				} else {
					if ( $venue_lon < $bounds_parsed['sw_lng'] && $venue_lon > $bounds_parsed['ne_lng'] ) {
						continue;
					}
				}
			}

			// Taxonomy filter.
			if ( null !== $taxonomy_venue_ids && ! in_array( $venue->term_id, $taxonomy_venue_ids, true ) ) {
				continue;
			}

			$address = Venue_Taxonomy::get_formatted_address( $venue->term_id );
			$url     = get_term_link( $venue );

			$venue_data = array(
				'term_id'     => $venue->term_id,
				'name'        => $venue->name,
				'slug'        => $venue->slug,
				'lat'         => $venue_lat,
				'lon'         => $venue_lon,
				'address'     => $address,
				'url'         => is_string( $url ) ? $url : '',
				'event_count' => $venue->count,
			);

			if ( isset( $distance_map[ $venue->term_id ] ) ) {
				$venue_data['distance'] = $distance_map[ $venue->term_id ];
			}

			$venues[] = $venue_data;
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

		return array(
			'venues' => $venues,
			'total'  => count( $venues ),
			'center' => $has_geo ? array( 'lat' => $lat, 'lng' => $lng ) : null,
			'radius' => $radius,
		);
	}

	/**
	 * Get venue term IDs that have events matching a taxonomy term.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $term_id  Term ID.
	 * @return array Venue term IDs.
	 */
	private function getVenueIdsForTaxonomyTerm( string $taxonomy, int $term_id ): array {
		$posts = get_posts( array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'terms'    => $term_id,
				),
			),
		) );

		if ( empty( $posts ) ) {
			return array();
		}

		$venue_ids = array();
		foreach ( $posts as $post_id ) {
			$terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				$venue_ids = array_merge( $venue_ids, $terms );
			}
		}

		return array_unique( $venue_ids );
	}
}
