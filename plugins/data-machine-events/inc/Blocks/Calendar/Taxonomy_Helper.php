<?php
/**
 * Taxonomy data discovery, hierarchy building, and post count calculations for calendar filtering
 *
 * @package DataMachineEvents\Blocks\Calendar
 */

namespace DataMachineEvents\Blocks\Calendar;

use DataMachineEvents\Core\Event_Post_Type;
use const DataMachineEvents\Core\EVENT_END_DATETIME_META_KEY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy data processing with hierarchy building and post count calculations
 */
class Taxonomy_Helper {

	/**
	 * Get all taxonomies with event counts using real-time cross-filtering
	 *
	 * @param array $active_filters Active filter selections keyed by taxonomy slug.
	 * @param array $date_context Optional date filtering context (date_start, date_end, past).
	 * @return array Structured taxonomy data with hierarchy and event counts.
	 */
	public static function get_all_taxonomies_with_counts( $active_filters = array(), $date_context = array(), $tax_query_override = null ) {
		$taxonomies_data = array();

		$taxonomies = get_object_taxonomies( Event_Post_Type::POST_TYPE, 'objects' );

		if ( ! $taxonomies ) {
			return $taxonomies_data;
		}

		$excluded_taxonomies = apply_filters( 'datamachine_events_excluded_taxonomies', array(), 'modal' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy->name, $excluded_taxonomies, true ) || ! $taxonomy->public ) {
				continue;
			}

			$terms_hierarchy = self::get_taxonomy_hierarchy( $taxonomy->name, null, $date_context, $active_filters, $tax_query_override );

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
	 * Get terms in a taxonomy filtered by allowed term IDs
	 *
	 * @param string     $taxonomy_slug Taxonomy to get terms for.
	 * @param array|null $allowed_term_ids Limit to these term IDs, or null for all.
	 * @param array      $date_context Optional date filtering context.
	 * @param array      $active_filters Optional active taxonomy filters for cross-filtering.
	 * @return array Hierarchical term structure with event counts.
	 */
	public static function get_taxonomy_hierarchy( $taxonomy_slug, $allowed_term_ids = null, $date_context = array(), $active_filters = array(), $tax_query_override = null ) {
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

		$term_counts = self::get_batch_term_counts( $taxonomy_slug, $date_context, $active_filters, $tax_query_override );

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
	 * Get event counts for all terms in a taxonomy with a single query
	 *
	 * @param string $taxonomy_slug Taxonomy to count events for.
	 * @param array  $date_context  Optional date filtering context.
	 * @param array  $active_filters Optional active taxonomy filters for cross-filtering.
	 * @return array Term ID => event count mapping.
	 */
	public static function get_batch_term_counts( $taxonomy_slug, $date_context = array(), $active_filters = array(), $tax_query_override = null ) {
		global $wpdb;

		$post_type = Event_Post_Type::POST_TYPE;

		$joins         = '';
		$where_clauses = '';
		$params        = array( $taxonomy_slug, $post_type );

		// Date context filtering - uses EVENT_END_DATETIME to match EventQueryBuilder behavior
		if ( ! empty( $date_context ) ) {
			$joins         .= " INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id";
			$where_clauses .= ' AND pm_date.meta_key = %s';
			$params[]       = EVENT_END_DATETIME_META_KEY;

			$date_start       = $date_context['date_start'] ?? '';
			$date_end         = $date_context['date_end'] ?? '';
			$show_past        = ! empty( $date_context['past'] ) && '1' === $date_context['past'];
			$current_datetime = current_time( 'mysql' );

			if ( ! empty( $date_start ) && ! empty( $date_end ) ) {
				// Explicit date range from date picker
				$where_clauses .= ' AND pm_date.meta_value >= %s AND pm_date.meta_value <= %s';
				$params[]       = $date_start . ' 00:00:00';
				$params[]       = $date_end . ' 23:59:59';
			} elseif ( $show_past ) {
				// Past events only (end time before current datetime)
				$where_clauses .= ' AND pm_date.meta_value < %s';
				$params[]       = $current_datetime;
			} else {
				// Default: future events only (end time >= current datetime)
				$where_clauses .= ' AND pm_date.meta_value >= %s';
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

		// Cross-taxonomy filtering (exclude current taxonomy from cross-filter)
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
	 * @param array $terms Flat array of term objects
	 * @param int $parent_id Parent term ID for current level
	 * @param int $level Current nesting level
	 * @return array Nested tree structure
	 */
	public static function build_hierarchy_tree( $terms, $parent_id = 0, $level = 0 ) {
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
	 * @param array $terms_hierarchy Nested term structure
	 * @return array Flattened term array maintaining level information
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
