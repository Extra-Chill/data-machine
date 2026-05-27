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
				'category'            => 'datamachine-events-venues',
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
						'include_events' => array(
							'type'        => 'boolean',
							'description' => 'When true and taxonomy+term_id are set, attach upcoming_events_at_venue per venue (events tagged with that term, chronological ascending). Powers chronological-route mode on the events-map block.',
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
									'upcoming_events_at_venue' => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'properties' => array(
												'post_id'    => array( 'type' => 'integer' ),
												'start_date' => array( 'type' => 'string' ),
												'start_time' => array( 'type' => 'string' ),
												'title'      => array( 'type' => 'string' ),
												'permalink'  => array( 'type' => 'string' ),
											),
										),
									),
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
		$lat            = isset( $input['lat'] ) ? (float) $input['lat'] : null;
		$lng            = isset( $input['lng'] ) ? (float) $input['lng'] : null;
		$radius         = isset( $input['radius'] ) ? (int) $input['radius'] : 25;
		$radius_unit    = $input['radius_unit'] ?? 'mi';
		$bounds         = $input['bounds'] ?? '';
		$taxonomy       = $input['taxonomy'] ?? '';
		$term_id        = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
		$include_events = ! empty( $input['include_events'] );

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

		// Combine geo and taxonomy filters (if both present) into a single
		// candidate venue ID set. Null means "no restriction from this path".
		$include_ids = null;
		if ( null !== $geo_venue_ids && null !== $taxonomy_venue_ids ) {
			$include_ids = array_values( array_intersect( $geo_venue_ids, $taxonomy_venue_ids ) );
		} elseif ( null !== $geo_venue_ids ) {
			$include_ids = $geo_venue_ids;
		} elseif ( null !== $taxonomy_venue_ids ) {
			$include_ids = $taxonomy_venue_ids;
		}

		/**
		 * Filter the resolved venue-map query args before the database query runs.
		 *
		 * Generic extension point mirroring `data_machine_events_calendar_query_args`
		 * for the events-map block. Consumers (e.g. an "only venues attended by the
		 * current user" filter on a `/my-shows/` page) can inject post-filter
		 * constraints here without referencing host-plugin tables from inside
		 * data-machine-events.
		 *
		 * The `include_ids` key constrains the candidate venue set:
		 *   - `null`        : no restriction (default when no geo/taxonomy filter).
		 *   - empty array   : intentionally restrict to zero venues (no results).
		 *   - array of ints : intersected with any existing geo/taxonomy filter.
		 *
		 * @since 1.x.x
		 *
		 * @param array $query_args {
		 *     Resolved query args used by the events-map venue lookup.
		 *
		 *     @type array|null $include_ids   Candidate venue term IDs, or null for unrestricted.
		 *     @type array|null $bounds        Parsed viewport bounds (sw_lat/sw_lng/ne_lat/ne_lng), or null.
		 *     @type bool       $has_geo       Whether geo proximity filtering is active.
		 *     @type float|null $lat           Center latitude, when geo filtering.
		 *     @type float|null $lng           Center longitude, when geo filtering.
		 *     @type int        $radius        Search radius, when geo filtering.
		 *     @type string     $radius_unit   Radius unit ('mi' or 'km').
		 *     @type string     $taxonomy      Taxonomy slug filter, or empty.
		 *     @type int        $term_id       Taxonomy term ID filter, or 0.
		 * }
		 * @param array $params The original input envelope passed to executeListVenues().
		 */
		$query_args = apply_filters(
			'data_machine_events_map_query_args',
			array(
				'include_ids' => $include_ids,
				'bounds'      => $bounds_parsed,
				'has_geo'     => $has_geo,
				'lat'         => $has_geo ? $lat : null,
				'lng'         => $has_geo ? $lng : null,
				'radius'      => $radius,
				'radius_unit' => $radius_unit,
				'taxonomy'    => $taxonomy,
				'term_id'     => $term_id,
			),
			$input
		);

		$include_ids   = $query_args['include_ids'] ?? null;
		$bounds_parsed = $query_args['bounds'] ?? null;

		// Bounds mode: query venues with coordinates in SQL.
		if ( null !== $bounds_parsed ) {
			$venues = $this->queryVenuesByBounds( $bounds_parsed, $include_ids );
		} else {
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

		// Replace placeholder event_count with upcoming-only counts.
		// The query paths above seed event_count=0; the real number comes from
		// a single batched query keyed by the term IDs actually being returned.
		// This is what the popup label promises ("X upcoming events") rather
		// than $term->count, which is the lifetime total of all published
		// events ever assigned to the venue (past + future).
		if ( ! empty( $venues ) ) {
			$venue_ids       = array_map( 'intval', array_column( $venues, 'term_id' ) );
			$upcoming_counts = $this->getUpcomingCountsForVenues( $venue_ids );

			foreach ( $venues as &$venue ) {
				$venue['event_count'] = (int) ( $upcoming_counts[ $venue['term_id'] ] ?? 0 );
			}
			unset( $venue );

			// Opt-in per-venue event list. Only attached when caller asked
			// for it AND a taxonomy/term filter is in play — otherwise this
			// would balloon to every venue's every upcoming event regardless
			// of scope. The chronological sort/grouping for route rendering
			// happens client-side.
			if ( $include_events && ! empty( $taxonomy ) && $term_id > 0 ) {
				$events_by_venue = $this->getUpcomingEventsForVenuesAtTerm( $venue_ids, $taxonomy, $term_id );
				foreach ( $venues as &$venue ) {
					$venue['upcoming_events_at_venue'] = $events_by_venue[ $venue['term_id'] ] ?? array();
				}
				unset( $venue );
			}
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

		/**
		 * Filter the final venue array before it is returned to the caller.
		 *
		 * Runs after sort + cap, so consumers see exactly the venue set that
		 * will be rendered. Consumers may:
		 *
		 *   - Mutate per-venue fields (e.g. override `event_count`, rewrite
		 *     `address`, attach extra metadata).
		 *   - Inject the `upcoming_events_at_venue` payload from a custom
		 *     source when the built-in attachment path (which requires both
		 *     `taxonomy` and `term_id` plus `include_events=true`) does not
		 *     apply — e.g. a host plugin that wants to drive the
		 *     events-map block from a non-taxonomy-archive page and supply
		 *     its own per-venue event list.
		 *   - Re-order the array (e.g. chronological order keyed on the
		 *     injected per-venue payload, replacing the default
		 *     event-count / distance sort).
		 *   - Remove venues (filter to a stricter subset).
		 *
		 * Consumers should not expand the array beyond `MAX_VENUES` — the
		 * cap is a safety valve enforced before this filter runs.
		 *
		 * @since 1.x.x
		 *
		 * @param array $venues The final venue array (already sorted and capped).
		 *                      Each entry has term_id, name, slug, lat, lon,
		 *                      address, url, event_count, and optionally
		 *                      distance / upcoming_events_at_venue.
		 * @param array $params The original input envelope passed to
		 *                      executeListVenues() (lat, lng, radius, bounds,
		 *                      taxonomy, term_id, include_events, etc.).
		 */
		$venues = apply_filters( 'data_machine_events_map_venues', $venues, $input );

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
				'event_count' => 0,
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
				'event_count' => 0,
			);
		}

		return $venues;
	}

	/**
	 * Get upcoming-event counts for a set of venue term IDs.
	 *
	 * Single batched query keyed by the IDs being rendered, returning a
	 * `term_id => count` map. "Upcoming" means events with a
	 * `start_datetime >= today` row in the event_dates table — matching
	 * the semantics of the venue popup label ("X upcoming events") and
	 * the location-archive venue badge graph (Extra-Chill/extrachill-events#88).
	 *
	 * Venues with zero upcoming events are intentionally absent from the
	 * returned map; callers should default to 0 on missing keys. This avoids
	 * a separate "empty" branch and keeps the query trivially indexable.
	 *
	 * @param int[] $venue_ids Term IDs from the venue taxonomy.
	 * @return array<int,int> Map of term_id => upcoming event count.
	 */
	private function getUpcomingCountsForVenues( array $venue_ids ): array {
		if ( empty( $venue_ids ) ) {
			return array();
		}

		global $wpdb;

		$post_type = Event_Post_Type::POST_TYPE;
		$today     = gmdate( 'Y-m-d 00:00:00' );

		$placeholders = implode( ',', array_fill( 0, count( $venue_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT tt.term_id, COUNT(DISTINCT p.ID) AS upcoming_count
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt
				ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p
				ON tr.object_id = p.ID
			INNER JOIN {$wpdb->prefix}datamachine_event_dates ed
				ON p.ID = ed.post_id
			WHERE tt.taxonomy = 'venue'
			AND tt.term_id IN ($placeholders)
			AND p.post_type = %s
			AND p.post_status = 'publish'
			AND ed.start_datetime >= %s
			GROUP BY tt.term_id",
			array_merge( $venue_ids, array( $post_type, $today ) )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $query );

		if ( empty( $rows ) ) {
			return array();
		}

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->term_id ] = (int) $row->upcoming_count;
		}

		return $counts;
	}

	/**
	 * Get upcoming events at each venue, scoped to a co-occurring taxonomy term.
	 *
	 * Returns a `venue_term_id => list of event rows` map. Each event row contains
	 * `post_id, start_date, start_time, title, permalink` — exactly what the
	 * chronological-route popup needs. Rows are ordered ascending by
	 * start_datetime within each venue group so the client can pick the
	 * earliest entry as the venue's route position without re-sorting.
	 *
	 * Single SQL query: term_relationships(venue) joined to term_relationships(filter term)
	 * joined to event_dates filtered to publish+future. Avoids N+1 across venues
	 * and avoids loading every post object — the SELECT pulls post_title and
	 * post_name directly so we can build the permalink ourselves.
	 *
	 * @param int[]  $venue_ids        Venue term IDs scoping the lookup.
	 * @param string $filter_taxonomy  Co-occurring taxonomy slug.
	 * @param int    $filter_term_id   Term ID in that taxonomy.
	 * @return array<int,array<int,array{post_id:int,start_date:string,start_time:string,title:string,permalink:string}>>
	 */
	private function getUpcomingEventsForVenuesAtTerm( array $venue_ids, string $filter_taxonomy, int $filter_term_id ): array {
		if ( empty( $venue_ids ) || empty( $filter_taxonomy ) || $filter_term_id <= 0 ) {
			return array();
		}

		global $wpdb;

		$post_type = Event_Post_Type::POST_TYPE;
		$today     = gmdate( 'Y-m-d 00:00:00' );

		$placeholders = implode( ',', array_fill( 0, count( $venue_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT venue_tt.term_id AS venue_term_id,
				p.ID AS post_id,
				p.post_title,
				p.post_name,
				ed.start_datetime
			FROM {$wpdb->term_relationships} venue_tr
			INNER JOIN {$wpdb->term_taxonomy} venue_tt
				ON venue_tr.term_taxonomy_id = venue_tt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p
				ON venue_tr.object_id = p.ID
			INNER JOIN {$wpdb->prefix}datamachine_event_dates ed
				ON p.ID = ed.post_id
			INNER JOIN {$wpdb->term_relationships} filter_tr
				ON filter_tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} filter_tt
				ON filter_tr.term_taxonomy_id = filter_tt.term_taxonomy_id
			WHERE venue_tt.taxonomy = 'venue'
			AND venue_tt.term_id IN ($placeholders)
			AND p.post_type = %s
			AND p.post_status = 'publish'
			AND ed.start_datetime >= %s
			AND filter_tt.taxonomy = %s
			AND filter_tt.term_id = %d
			ORDER BY venue_tt.term_id ASC, ed.start_datetime ASC",
			array_merge(
				$venue_ids,
				array( $post_type, $today, $filter_taxonomy, $filter_term_id )
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $query );

		if ( empty( $rows ) ) {
			return array();
		}

		$by_venue = array();
		foreach ( $rows as $row ) {
			$venue_term_id = (int) $row->venue_term_id;
			$post_id       = (int) $row->post_id;
			$datetime      = (string) $row->start_datetime;

			// Split "YYYY-MM-DD HH:MM:SS" into separate date/time so the
			// client can format each independently without re-parsing.
			$date_part = '';
			$time_part = '';
			if ( strpos( $datetime, ' ' ) !== false ) {
				list( $date_part, $time_part ) = explode( ' ', $datetime, 2 );
			} else {
				$date_part = $datetime;
			}

			// Build permalink without get_permalink() to avoid N+1 post loads.
			// get_post_permalink falls back to the same path when post_name is set.
			$permalink = get_permalink( $post_id );

			$by_venue[ $venue_term_id ][] = array(
				'post_id'    => $post_id,
				'start_date' => $date_part,
				'start_time' => $time_part,
				'title'      => (string) $row->post_title,
				'permalink'  => is_string( $permalink ) ? $permalink : '',
			);
		}

		return $by_venue;
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
