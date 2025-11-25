<?php
/**
 * Taxonomy data discovery, hierarchy building, and post count calculations for calendar filtering
 *
 * @package DataMachineEvents\Blocks\Calendar
 */

namespace DataMachineEvents\Blocks\Calendar;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Taxonomy data processing with hierarchy building and post count calculations
 */
class Taxonomy_Helper {
    
    /**
     * @return array Structured taxonomy data excluding venues with hierarchy and event counts
     */
    public static function get_all_taxonomies_with_counts() {
        $taxonomies_data = [];
        
        $taxonomies = get_object_taxonomies('datamachine_events', 'objects');
        
        if (!$taxonomies) {
            return $taxonomies_data;
        }
        
        $excluded_taxonomies = apply_filters('datamachine_events_excluded_taxonomies', array(), 'modal');
        
        foreach ($taxonomies as $taxonomy) {
            if (in_array($taxonomy->name, $excluded_taxonomies, true) || !$taxonomy->public) {
                continue;
            }
            
            $terms_hierarchy = self::get_taxonomy_hierarchy($taxonomy->name);
            
            if (!empty($terms_hierarchy)) {
                $taxonomies_data[$taxonomy->name] = [
                    'label' => $taxonomy->label,
                    'name' => $taxonomy->name,
                    'hierarchical' => $taxonomy->hierarchical,
                    'terms' => $terms_hierarchy
                ];
            }
        }
        
        return $taxonomies_data;
    }
    
    /**
     * @param string $taxonomy_slug
     * @return array Hierarchical term structure with event counts
     */
    public static function get_taxonomy_hierarchy($taxonomy_slug) {
        $terms = get_terms([
            'taxonomy' => $taxonomy_slug,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ]);
        
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }
        
        $terms_with_events = [];
        foreach ($terms as $term) {
            $event_count = self::get_term_event_count($term->term_id);
            if ($event_count > 0) {
                $term->event_count = $event_count;
                $terms_with_events[] = $term;
            }
        }
        
        if (empty($terms_with_events)) {
            return [];
        }
        $taxonomy_obj = get_taxonomy($taxonomy_slug);
        if ($taxonomy_obj && $taxonomy_obj->hierarchical) {
            return self::build_hierarchy_tree($terms_with_events);
        } else {
            return array_map(function($term) {
                return [
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'event_count' => $term->event_count,
                    'level' => 0,
                    'children' => []
                ];
            }, $terms_with_events);
        }
    }
    
    /**
     * @param array $terms Flat array of term objects
     * @param int $parent_id Parent term ID for current level
     * @param int $level Current nesting level
     * @return array Nested tree structure
     */
    public static function build_hierarchy_tree($terms, $parent_id = 0, $level = 0) {
        $tree = [];
        
        foreach ($terms as $term) {
            if ($term->parent == $parent_id) {
                $term_data = [
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'event_count' => $term->event_count,
                    'level' => $level,
                    'children' => []
                ];
                
                $children = self::build_hierarchy_tree($terms, $term->term_id, $level + 1);
                if (!empty($children)) {
                    $term_data['children'] = $children;
                }
                
                $tree[] = $term_data;
            }
        }
        
        return $tree;
    }
    
    /**
     * @param int $term_id Term ID to count events for
     * @return int Number of published events with this term
     */
    public static function get_term_event_count($term_id) {
        $query_args = [
            'post_type' => 'datamachine_events',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => get_term($term_id)->taxonomy,
                    'field' => 'term_id',
                    'terms' => $term_id
                ]
            ]
        ];
        
        $events = get_posts($query_args);
        return count($events);
    }
    
    /**
     * @param array $terms_hierarchy Nested term structure
     * @return array Flattened term array maintaining level information
     */
    public static function flatten_hierarchy($terms_hierarchy) {
        $flattened = [];
        
        foreach ($terms_hierarchy as $term) {
            $flattened[] = $term;
            
            if (!empty($term['children'])) {
                $flattened = array_merge($flattened, self::flatten_hierarchy($term['children']));
            }
        }
        
        return $flattened;
    }
}