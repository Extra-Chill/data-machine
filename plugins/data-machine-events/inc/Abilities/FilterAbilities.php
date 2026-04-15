<?php
/**
 * Filter Abilities
 *
 * Provides filter/taxonomy data via WordPress Abilities API.
 * Single source of truth for filter options with geo-filtering,
 * cross-filtering, and archive context support.
 *
 * Consumers: Filters REST controller, render.php (filter-bar visibility),
 * CLI, Chat, MCP — anything that needs to know "what filter options exist?"
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Blocks\Calendar\Geo_Query;
use DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter;
use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/get-filter-options',
				array(
					'label'               => __( 'Get Filter Options', 'data-machine-events' ),
					'description'         => __( 'Get available taxonomy filter options with event counts, supporting geo-filtering, cross-filtering, and archive context', 'data-machine-events' ),
					'category'            => 'datamachine-events/events',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'active_filters'   => array(
								'type'        => 'object',
								'description' => 'Active filter selections keyed by taxonomy slug [taxonomy => [term_ids]]',
							),
							'date_context'     => array(
								'type'       => 'object',
								'properties' => array(
									'date_start' => array( 'type' => 'string' ),
									'date_end'   => array( 'type' => 'string' ),
									'past'       => array( 'type' => 'string' ),
								),
							),
							'archive_taxonomy' => array(
								'type'        => 'string',
								'description' => 'Archive constraint taxonomy slug',
							),
							'archive_term_id'  => array(
								'type'        => 'integer',
								'description' => 'Archive constraint term ID',
							),
							'geo_lat'          => array(
								'type'        => 'number',
								'description' => 'Latitude for geo-filtering',
							),
							'geo_lng'          => array(
								'type'        => 'number',
								'description' => 'Longitude for geo-filtering',
							),
							'geo_radius'       => array(
								'type'        => 'number',
								'description' => 'Search radius (default: 25)',
							),
							'geo_radius_unit'  => array(
								'type'        => 'string',
								'description' => 'Radius unit: mi or km (default: mi)',
							),
							'context'          => array(
								'type'        => 'string',
								'description' => 'Filter context: modal, inline, badge (default: modal)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'success'         => array( 'type' => 'boolean' ),
							'taxonomies'      => array(
								'type'        => 'object',
								'description' => 'Taxonomy data with hierarchy and event counts, keyed by taxonomy slug',
							),
							'archive_context' => array(
								'type'       => 'object',
								'properties' => array(
									'taxonomy'  => array( 'type' => 'string' ),
									'term_id'   => array( 'type' => 'integer' ),
									'term_name' => array( 'type' => 'string' ),
								),
							),
							'geo_context'     => array(
								'type'       => 'object',
								'properties' => array(
									'active'       => array( 'type' => 'boolean' ),
									'venue_count'  => array( 'type' => 'integer' ),
									'lat'          => array( 'type' => 'number' ),
									'lng'          => array( 'type' => 'number' ),
									'radius'       => array( 'type' => 'number' ),
									'radius_unit'  => array( 'type' => 'string' ),
								),
							),
							'meta'            => array(
								'type'       => 'object',
								'properties' => array(
									'context'        => array( 'type' => 'string' ),
									'active_filters' => array( 'type' => 'object' ),
									'date_context'   => array( 'type' => 'object' ),
								),
							),
						),
					),
					'execute_callback'    => array( $this, 'executeGetFilterOptions' ),
					'permission_callback' => '__return_true',
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute get-filter-options ability
	 *
	 * Builds taxonomy filter options with event counts, applying:
	 * - Archive context constraint (taxonomy archive pages)
	 * - Geo constraint (nearby venues via haversine)
	 * - Cross-filtering (selecting one taxonomy recalculates others)
	 * - Date context (future/past/date range)
	 *
	 * @param array $input Input parameters.
	 * @return array Filter options data.
	 */
	public function executeGetFilterOptions( array $input ): array {
		$active_filters = is_array( $input['active_filters'] ?? null ) ? $input['active_filters'] : array();
		$context        = $input['context'] ?? 'modal';

		$date_context = array(
			'date_start' => $input['date_context']['date_start'] ?? '',
			'date_end'   => $input['date_context']['date_end'] ?? '',
			'past'       => $input['date_context']['past'] ?? '',
		);

		// Build archive constraint.
		$archive_taxonomy = sanitize_key( $input['archive_taxonomy'] ?? '' );
		$archive_term_id  = absint( $input['archive_term_id'] ?? 0 );

		$archive_context    = array();
		$tax_query_override = null;

		if ( $archive_taxonomy && $archive_term_id ) {
			$tax_query_override = array(
				array(
					'taxonomy' => $archive_taxonomy,
					'field'    => 'term_id',
					'terms'    => $archive_term_id,
				),
			);

			$term            = get_term( $archive_term_id, $archive_taxonomy );
			$archive_context = array(
				'taxonomy'  => $archive_taxonomy,
				'term_id'   => $archive_term_id,
				'term_name' => $term && ! is_wp_error( $term ) ? $term->name : '',
			);
		}

		// Build geo constraint.
		$geo_lat    = $input['geo_lat'] ?? '';
		$geo_lng    = $input['geo_lng'] ?? '';
		$geo_radius = $input['geo_radius'] ?? 25;
		$geo_unit   = $input['geo_radius_unit'] ?? 'mi';

		$geo_context = array(
			'active'      => false,
			'venue_count' => 0,
			'lat'         => 0,
			'lng'         => 0,
			'radius'      => (float) $geo_radius,
			'radius_unit' => $geo_unit,
		);

		if ( ! empty( $geo_lat ) && ! empty( $geo_lng ) ) {
			$geo_lat    = (float) $geo_lat;
			$geo_lng    = (float) $geo_lng;
			$geo_radius = (float) $geo_radius;

			if ( Geo_Query::validate_params( $geo_lat, $geo_lng, $geo_radius ) ) {
				$nearby_venue_ids = Geo_Query::get_venue_ids_within_radius( $geo_lat, $geo_lng, $geo_radius, $geo_unit );

				$venue_constraint = array(
					'taxonomy' => 'venue',
					'field'    => 'term_id',
					'terms'    => ! empty( $nearby_venue_ids ) ? $nearby_venue_ids : array( 0 ),
				);

				if ( is_array( $tax_query_override ) ) {
					$tax_query_override[] = $venue_constraint;
				} else {
					$tax_query_override = array( $venue_constraint );
				}

				$geo_context = array(
					'active'      => true,
					'venue_count' => count( $nearby_venue_ids ),
					'lat'         => $geo_lat,
					'lng'         => $geo_lng,
					'radius'      => $geo_radius,
					'radius_unit' => $geo_unit,
				);
			}
		}

		$taxonomies_data = $this->get_all_taxonomies_with_counts( $active_filters, $date_context, $tax_query_override );

		return array(
			'success'         => true,
			'taxonomies'      => $taxonomies_data,
			'archive_context' => $archive_context,
			'geo_context'     => $geo_context,
			'meta'            => array(
				'context'        => $context,
				'active_filters' => $active_filters,
				'date_context'   => $date_context,
			),
		);
	}

	/**
	 * Get all taxonomies with event counts using real-time cross-filtering.
	 *
	 * @param array      $active_filters    Active filter selections keyed by taxonomy slug.
	 * @param array      $date_context      Optional date filtering context (date_start, date_end, past).
	 * @param array|null $tax_query_override Optional taxonomy query override.
	 * @return array Structured taxonomy data with hierarchy and event counts.
	 */
	private function get_all_taxonomies_with_counts( $active_filters = array(), $date_context = array(), $tax_query_override = null ) {
		$taxonomies_data = array();

		$taxonomies = get_object_taxonomies( Event_Post_Type::POST_TYPE, 'objects' );

		if ( ! $taxonomies ) {
			return $taxonomies_data;
		}

		$excluded_taxonomies = apply_filters( 'data_machine_events_excluded_taxonomies', array(), 'modal' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy->name, $excluded_taxonomies, true ) || ! $taxonomy->public ) {
				continue;
			}

			$terms_hierarchy = $this->get_taxonomy_hierarchy( $taxonomy->name, null, $date_context, $active_filters, $tax_query_override );

			if ( ! empty( $terms_hierarchy ) ) {
				$taxonomies_data[ $taxonomy->name ] = array(
					'label'        => $taxonomy->label,
					'name'         => $taxonomy->name,
					'hierarchical' => $taxonomy->hierarchical,
					'terms'        => $terms_hierarchy,
				);
			}
		}

		return $taxonomies_data;
	}

	/**
	 * Get terms in a taxonomy filtered by allowed term IDs.
	 *
	 * @param string     $taxonomy_slug   Taxonomy to get terms for.
	 * @param array|null $allowed_term_ids Limit to these term IDs, or null for all.
	 * @param array      $date_context    Optional date filtering context.
	 * @param array      $active_filters  Optional active taxonomy filters for cross-filtering.
	 * @param array|null $tax_query_override Optional taxonomy query override.
	 * @return array Hierarchical term structure with event counts.
	 */
	private function get_taxonomy_hierarchy( $taxonomy_slug, $allowed_term_ids = null, $date_context = array(), $active_filters = array(), $tax_query_override = null ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy_slug,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		if ( null !== $allowed_term_ids && empty( $allowed_term_ids ) ) {
			return array();
		}

		$term_counts = $this->get_batch_term_counts( $taxonomy_slug, $date_context, $active_filters, $tax_query_override );

		$terms_with_events = array();
		foreach ( $terms as $term ) {
			if ( null !== $allowed_term_ids && ! in_array( $term->term_id, $allowed_term_ids, true ) ) {
				continue;
			}

			$event_count = $term_counts[ $term->term_id ] ?? 0;
			if ( $event_count > 0 ) {
				$term->event_count   = $event_count;
				$terms_with_events[] = $term;
			}
		}

		if ( empty( $terms_with_events ) ) {
			return array();
		}

		$taxonomy_obj = get_taxonomy( $taxonomy_slug );
		if ( $taxonomy_obj && $taxonomy_obj->hierarchical ) {
			return self::build_hierarchy_tree( $terms_with_events );
		}

		return array_map(
			function ( $term ) {
				return array(
					'term_id'     => $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'event_count' => $term->event_count,
					'level'       => 0,
					'children'    => array(),
				);
			},
			$terms_with_events
		);
	}

	/**
	 * Get event counts for all terms in a taxonomy with a single query.
	 *
	 * Uses UpcomingFilter for date filtering SQL fragments.
	 * This method does GROUP BY with cross-taxonomy filtering.
	 *
	 * @param string     $taxonomy_slug     Taxonomy to count events for.
	 * @param array      $date_context      Optional date filtering context.
	 * @param array      $active_filters    Optional active taxonomy filters for cross-filtering.
	 * @param array|null $tax_query_override Optional taxonomy query override.
	 * @return array Term ID => event count mapping.
	 */
	private function get_batch_term_counts( $taxonomy_slug, $date_context = array(), $active_filters = array(), $tax_query_override = null ) {
		global $wpdb;

		$post_type = Event_Post_Type::POST_TYPE;

		$joins         = '';
		$where_clauses = '';
		$params        = array( $taxonomy_slug, $post_type );

		if ( ! empty( $date_context ) ) {
			$date_start       = $date_context['date_start'] ?? '';
			$date_end         = $date_context['date_end'] ?? '';
			$show_past        = ! empty( $date_context['past'] ) && '1' === $date_context['past'];
			$current_datetime = current_time( 'mysql' );

			if ( ! empty( $date_start ) && ! empty( $date_end ) ) {
				$filter = UpcomingFilter::date_range_sql( false, 'p.ID' );
				$joins         .= ' ' . $filter['joins'];
				$where_clauses .= ' AND ' . $filter['where'];
				$params[]       = $date_start . ' 00:00:00';
				$params[]       = $date_end . ' 23:59:59';
			} elseif ( $show_past ) {
				$filter = UpcomingFilter::past_sql( false, 'p.ID' );
				$joins         .= ' ' . $filter['joins'];
				$where_clauses .= ' AND ' . $filter['where'];
				$params[]       = $current_datetime;
				$params[]       = $current_datetime;
			} else {
				$filter = UpcomingFilter::upcoming_sql( false, 'p.ID' );
				$joins         .= ' ' . $filter['joins'];
				$where_clauses .= ' AND ' . $filter['where'];
				$params[]       = $current_datetime;
				$params[]       = $current_datetime;
			}
		}

		if ( ! empty( $tax_query_override ) && is_array( $tax_query_override ) ) {
			$base_join_index = 0;
			foreach ( $tax_query_override as $clause ) {
				$base_taxonomy = sanitize_key( $clause['taxonomy'] ?? '' );
				$base_terms    = array_map( 'absint', (array) ( $clause['terms'] ?? array() ) );

				if ( ! $base_taxonomy || empty( $base_terms ) ) {
					continue;
				}

				$placeholders = implode( ',', array_fill( 0, count( $base_terms ), '%d' ) );
				$alias_tr     = "base_tr_{$base_join_index}";
				$alias_tt     = "base_tt_{$base_join_index}";

				$joins .= " INNER JOIN {$wpdb->term_relationships} {$alias_tr} ON p.ID = {$alias_tr}.object_id";
				$joins .= " INNER JOIN {$wpdb->term_taxonomy} {$alias_tt} ON {$alias_tr}.term_taxonomy_id = {$alias_tt}.term_taxonomy_id";

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$where_clauses .= " AND {$alias_tt}.taxonomy = %s AND {$alias_tt}.term_id IN ($placeholders)";
				$params[]       = $base_taxonomy;
				$params         = array_merge( $params, $base_terms );

				++$base_join_index;
			}
		}

		// Cross-taxonomy filtering (exclude current taxonomy from cross-filter).
		$cross_filters = array_diff_key( $active_filters, array( $taxonomy_slug => true ) );
		$join_index    = 0;
		foreach ( $cross_filters as $filter_taxonomy => $term_ids ) {
			if ( empty( $term_ids ) ) {
				continue;
			}

			$term_ids     = array_map( 'intval', (array) $term_ids );
			$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

			$alias_tr = "cross_tr_{$join_index}";
			$alias_tt = "cross_tt_{$join_index}";

			$joins .= " INNER JOIN {$wpdb->term_relationships} {$alias_tr} ON p.ID = {$alias_tr}.object_id";
			$joins .= " INNER JOIN {$wpdb->term_taxonomy} {$alias_tt} ON {$alias_tr}.term_taxonomy_id = {$alias_tt}.term_taxonomy_id";

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where_clauses .= " AND {$alias_tt}.taxonomy = %s AND {$alias_tt}.term_id IN ($placeholders)";
			$params[]       = $filter_taxonomy;
			$params         = array_merge( $params, $term_ids );

			++$join_index;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $wpdb->prepare(
			"SELECT tt.term_id, COUNT(DISTINCT tr.object_id) as event_count
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt 
                ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->posts} p 
                ON tr.object_id = p.ID
            {$joins}
            WHERE tt.taxonomy = %s
            AND p.post_type = %s
            AND p.post_status = 'publish'
            {$where_clauses}
            GROUP BY tt.term_id",
			$params
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $query );

		$counts = array();
		foreach ( $results as $row ) {
			$counts[ (int) $row->term_id ] = (int) $row->event_count;
		}

		return $counts;
	}

	/**
	 * Build a nested hierarchy tree from a flat array of terms.
	 *
	 * @param array $terms     Flat array of term objects.
	 * @param int   $parent_id Parent term ID for current level.
	 * @param int   $level     Current nesting level.
	 * @return array Nested tree structure.
	 */
	private static function build_hierarchy_tree( $terms, $parent_id = 0, $level = 0 ) {
		$tree = array();

		$term_ids = array_map(
			function ( $t ) {
				return $t->term_id;
			},
			$terms
		);

		foreach ( $terms as $term ) {
			$effective_parent = $term->parent;
			while ( 0 !== $effective_parent && ! in_array( $effective_parent, $term_ids, true ) ) {
				$parent_term      = get_term( $effective_parent );
				$effective_parent = $parent_term && ! is_wp_error( $parent_term ) ? $parent_term->parent : 0;
			}

			if ( $effective_parent == $parent_id ) {
				$term_data = array(
					'term_id'     => $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'event_count' => $term->event_count,
					'level'       => $level,
					'children'    => array(),
				);

				$children = self::build_hierarchy_tree( $terms, $term->term_id, $level + 1 );
				if ( ! empty( $children ) ) {
					$term_data['children'] = $children;
				}

				$tree[] = $term_data;
			}
		}

		return $tree;
	}

	/**
	 * Flatten a nested term hierarchy into a flat array, preserving level information.
	 *
	 * @param array $terms_hierarchy Nested term structure.
	 * @return array Flattened term array maintaining level information.
	 */
	public static function flatten_hierarchy( $terms_hierarchy ) {
		$flattened = array();

		foreach ( $terms_hierarchy as $term ) {
			$flattened[] = $term;

			if ( ! empty( $term['children'] ) ) {
				$flattened = array_merge( $flattened, self::flatten_hierarchy( $term['children'] ) );
			}
		}

		return $flattened;
	}
}
