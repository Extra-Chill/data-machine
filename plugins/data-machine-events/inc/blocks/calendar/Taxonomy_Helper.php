<?php
/**
 * Taxonomy data discovery, hierarchy building, and post count calculations for calendar filtering
 *
 * @package DataMachineEvents\Blocks\Calendar
 */

namespace DataMachineEvents\Blocks\Calendar;

use DataMachineEvents\Core\Event_Post_Type;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Taxonomy data processing with hierarchy building and post count calculations
 */
class Taxonomy_Helper {
    
    /**
     * Get all taxonomies with event counts, respecting active filters, dependencies, and date context
     *
     * @param array $active_filters Active filter selections keyed by taxonomy slug.
     * @param array $dependencies Taxonomy dependency mappings.
     * @param array $date_context Optional date filtering context (date_start, date_end, past).
     * @return array Structured taxonomy data with hierarchy and event counts.
     */
    public static function get_all_taxonomies_with_counts( $active_filters = [], $dependencies = [], $date_context = [] ) {
        $taxonomies_data = [];
        
        $taxonomies = get_object_taxonomies( Event_Post_Type::POST_TYPE, 'objects' );
        
        if ( ! $taxonomies ) {
            return $taxonomies_data;
        }
        
        $excluded_taxonomies = apply_filters( 'datamachine_events_excluded_taxonomies', [], 'modal' );
        
        foreach ( $taxonomies as $taxonomy ) {
            if ( in_array( $taxonomy->name, $excluded_taxonomies, true ) || ! $taxonomy->public ) {
                continue;
            }
            
            $allowed_term_ids = null;
            if ( isset( $dependencies[ $taxonomy->name ] ) ) {
                $parent_taxonomy = $dependencies[ $taxonomy->name ];
                if ( ! empty( $active_filters[ $parent_taxonomy ] ) ) {
                    $allowed_term_ids = self::get_dependent_terms(
                        $taxonomy->name,
                        $parent_taxonomy,
                        $active_filters[ $parent_taxonomy ]
                    );
                }
            }
            
            $terms_hierarchy = self::get_taxonomy_hierarchy( $taxonomy->name, $allowed_term_ids, $date_context, $active_filters );
            
            if ( ! empty( $terms_hierarchy ) ) {
                $taxonomies_data[ $taxonomy->name ] = [
                    'label'        => $taxonomy->label,
                    'name'         => $taxonomy->name,
                    'hierarchical' => $taxonomy->hierarchical,
                    'terms'        => $terms_hierarchy,
                ];
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
    public static function get_taxonomy_hierarchy( $taxonomy_slug, $allowed_term_ids = null, $date_context = [], $active_filters = [] ) {
        $terms = get_terms([
            'taxonomy'   => $taxonomy_slug,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
        
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }
        
        if ( null !== $allowed_term_ids && empty( $allowed_term_ids ) ) {
            return [];
        }
        
        $term_counts = self::get_batch_term_counts( $taxonomy_slug, $date_context, $active_filters );
        
        $terms_with_events = [];
        foreach ( $terms as $term ) {
            if ( null !== $allowed_term_ids && ! in_array( $term->term_id, $allowed_term_ids, true ) ) {
                continue;
            }
            
            $event_count = $term_counts[ $term->term_id ] ?? 0;
            if ( $event_count > 0 ) {
                $term->event_count = $event_count;
                $terms_with_events[] = $term;
            }
        }
        
        if ( empty( $terms_with_events ) ) {
            return [];
        }
        
        $taxonomy_obj = get_taxonomy( $taxonomy_slug );
        if ( $taxonomy_obj && $taxonomy_obj->hierarchical ) {
            return self::build_hierarchy_tree( $terms_with_events );
        }
        
        return array_map( function( $term ) {
            return [
                'term_id'     => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'event_count' => $term->event_count,
                'level'       => 0,
                'children'    => [],
            ];
        }, $terms_with_events );
    }
    
    /**
     * Get term IDs in child taxonomy that share events with parent taxonomy terms (OR logic)
     *
     * @param string $child_taxonomy Child taxonomy slug.
     * @param string $parent_taxonomy Parent taxonomy slug.
     * @param array  $parent_term_ids Selected parent term IDs.
     * @return array Child term IDs that have events with any of the parent terms.
     */
    public static function get_dependent_terms( $child_taxonomy, $parent_taxonomy, $parent_term_ids ) {
        global $wpdb;
        
        if ( empty( $parent_term_ids ) ) {
            return [];
        }
        
        $parent_term_ids = array_map( 'intval', $parent_term_ids );
        $placeholders    = implode( ',', array_fill( 0, count( $parent_term_ids ), '%d' ) );
        
        $post_type = Event_Post_Type::POST_TYPE;
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query = $wpdb->prepare(
            "SELECT DISTINCT child_terms.term_id
            FROM {$wpdb->term_relationships} parent_tr
            INNER JOIN {$wpdb->term_taxonomy} parent_tt 
                ON parent_tr.term_taxonomy_id = parent_tt.term_taxonomy_id
            INNER JOIN {$wpdb->posts} p 
                ON parent_tr.object_id = p.ID
            INNER JOIN {$wpdb->term_relationships} child_tr 
                ON parent_tr.object_id = child_tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} child_tt 
                ON child_tr.term_taxonomy_id = child_tt.term_taxonomy_id
            INNER JOIN {$wpdb->terms} child_terms 
                ON child_tt.term_id = child_terms.term_id
            WHERE parent_tt.taxonomy = %s 
            AND parent_tt.term_id IN ($placeholders)
            AND child_tt.taxonomy = %s
            AND p.post_type = %s
            AND p.post_status = 'publish'",
            array_merge(
                [ $parent_taxonomy ],
                $parent_term_ids,
                [ $child_taxonomy, $post_type ]
            )
        );
        
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array_map( 'intval', $wpdb->get_col( $query ) );
    }
    
    /**
     * Get event counts for all terms in a taxonomy with a single query
     *
     * @param string $taxonomy_slug Taxonomy to count events for.
     * @param array  $date_context  Optional date filtering context.
     * @param array  $active_filters Optional active taxonomy filters for cross-filtering.
     * @return array Term ID => event count mapping.
     */
    public static function get_batch_term_counts( $taxonomy_slug, $date_context = [], $active_filters = [] ) {
        global $wpdb;
        
        $post_type = Event_Post_Type::POST_TYPE;
        
        $joins = '';
        $where_clauses = '';
        $params = [ $taxonomy_slug, $post_type ];
        
        // Date context filtering
        if ( ! empty( $date_context ) ) {
            $joins .= " INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id";
            $where_clauses .= " AND pm_date.meta_key = '_datamachine_event_datetime'";
            
            $date_start = $date_context['date_start'] ?? '';
            $date_end = $date_context['date_end'] ?? '';
            $show_past = ! empty( $date_context['past'] ) && '1' === $date_context['past'];
            
            if ( ! empty( $date_start ) && ! empty( $date_end ) ) {
                // Explicit date range from date picker
                $where_clauses .= " AND pm_date.meta_value >= %s AND pm_date.meta_value <= %s";
                $params[] = $date_start . ' 00:00:00';
                $params[] = $date_end . ' 23:59:59';
            } elseif ( $show_past ) {
                // Past events only (before today)
                $where_clauses .= " AND pm_date.meta_value < %s";
                $params[] = current_time( 'Y-m-d' ) . ' 00:00:00';
            } else {
                // Default: future events only (today and forward)
                $where_clauses .= " AND pm_date.meta_value >= %s";
                $params[] = current_time( 'Y-m-d' ) . ' 00:00:00';
            }
        }
        
        // Cross-taxonomy filtering (exclude current taxonomy from cross-filter)
        $cross_filters = array_diff_key( $active_filters, [ $taxonomy_slug => true ] );
        $join_index = 0;
        foreach ( $cross_filters as $filter_taxonomy => $term_ids ) {
            if ( empty( $term_ids ) ) {
                continue;
            }
            
            $term_ids = array_map( 'intval', (array) $term_ids );
            $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
            
            $alias_tr = "cross_tr_{$join_index}";
            $alias_tt = "cross_tt_{$join_index}";
            
            $joins .= " INNER JOIN {$wpdb->term_relationships} {$alias_tr} ON p.ID = {$alias_tr}.object_id";
            $joins .= " INNER JOIN {$wpdb->term_taxonomy} {$alias_tt} ON {$alias_tr}.term_taxonomy_id = {$alias_tt}.term_taxonomy_id";
            
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $where_clauses .= " AND {$alias_tt}.taxonomy = %s AND {$alias_tt}.term_id IN ($placeholders)";
            $params[] = $filter_taxonomy;
            $params = array_merge( $params, $term_ids );
            
            $join_index++;
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
        
        $counts = [];
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
        $tree = [];
        
        $term_ids = array_map( function( $t ) { return $t->term_id; }, $terms );
        
        foreach ( $terms as $term ) {
            $effective_parent = $term->parent;
            while ( $effective_parent !== 0 && ! in_array( $effective_parent, $term_ids, true ) ) {
                $parent_term = get_term( $effective_parent );
                $effective_parent = $parent_term && ! is_wp_error( $parent_term ) ? $parent_term->parent : 0;
            }
            
            if ( $effective_parent == $parent_id ) {
                $term_data = [
                    'term_id'     => $term->term_id,
                    'name'        => $term->name,
                    'slug'        => $term->slug,
                    'event_count' => $term->event_count,
                    'level'       => $level,
                    'children'    => [],
                ];
                
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
        $flattened = [];
        
        foreach ( $terms_hierarchy as $term ) {
            $flattened[] = $term;
            
            if ( ! empty( $term['children'] ) ) {
                $flattened = array_merge( $flattened, self::flatten_hierarchy( $term['children'] ) );
            }
        }
        
        return $flattened;
    }
}