<?php
/**
 * Dynamic Taxonomy Badge System
 *
 * @package DmEvents\Core
 */

namespace DmEvents\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders pill-style badges with dynamic color generation for event taxonomies
 */
class Taxonomy_Badges {
    
    /**
     * @param int $post_id Event post ID
     * @return array Structured array of taxonomy objects and terms
     */
    public static function get_event_taxonomies($post_id) {
        if (!$post_id) {
            return [];
        }
        
        $all_taxonomies = get_object_taxonomies('dm_events', 'objects');
        
        if (empty($all_taxonomies)) {
            return [];
        }
        
        $taxonomy_data = [];
        
        foreach ($all_taxonomies as $taxonomy_slug => $taxonomy_object) {
            if ($taxonomy_slug === 'venue') {
                continue;
            }
            
            $terms = get_the_terms($post_id, $taxonomy_slug);
            
            if (!$terms || is_wp_error($terms)) {
                continue;
            }
            
            $taxonomy_data[$taxonomy_slug] = [
                'taxonomy' => $taxonomy_object,
                'terms' => $terms
            ];
        }
        
        return $taxonomy_data;
    }
    
    /**
     * Generate HTML for taxonomy badges with data attributes
     *
     * @param int $post_id Event post ID
     * @return string Complete badge HTML with wrapper div
     */
    public static function render_taxonomy_badges($post_id) {
        $taxonomies = self::get_event_taxonomies($post_id);
        
        if (empty($taxonomies)) {
            return '';
        }
        
        $output = '<div class="dm-taxonomy-badges">';
        
        foreach ($taxonomies as $taxonomy_slug => $taxonomy_data) {
            $taxonomy_object = $taxonomy_data['taxonomy'];
            $terms = $taxonomy_data['terms'];
            
            foreach ($terms as $term) {
                $badge_classes = [
                    'dm-taxonomy-badge',
                    'dm-taxonomy-' . esc_attr($taxonomy_slug),
                    'dm-term-' . esc_attr($term->slug)
                ];
                
                $output .= sprintf(
                    '<span class="%s" data-taxonomy="%s" data-term="%s">%s</span>',
                    esc_attr(implode(' ', $badge_classes)),
                    esc_attr($taxonomy_slug),
                    esc_attr($term->slug),
                    esc_html($term->name)
                );
            }
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Get all taxonomies currently used by published events
     *
     * @return array Taxonomies with slug and label, excluding venues
     */
    public static function get_used_taxonomies() {
        global $wpdb;
        
        $query = "
            SELECT DISTINCT tt.taxonomy, t.name
            FROM {$wpdb->term_taxonomy} tt
            INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
            INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE p.post_type = 'dm_events'
            AND p.post_status = 'publish'
            AND tt.taxonomy != 'venue'
        ";
        
        $results = $wpdb->get_results($query);
        
        if (!$results) {
            return [];
        }
        
        $used_taxonomies = [];
        foreach ($results as $result) {
            if (!isset($used_taxonomies[$result->taxonomy])) {
                $taxonomy_object = get_taxonomy($result->taxonomy);
                $used_taxonomies[$result->taxonomy] = [
                    'slug' => $result->taxonomy,
                    'label' => $taxonomy_object ? $taxonomy_object->labels->name : $result->taxonomy
                ];
            }
        }
        
        return $used_taxonomies;
    }
    
    /**
     * Hash-based color class generation for consistent taxonomy styling
     *
     * @param string $taxonomy_slug
     * @return string
     */
    public static function get_taxonomy_color_class($taxonomy_slug) {
        $hash = md5($taxonomy_slug);
        $color_index = hexdec(substr($hash, 0, 1)) % 10;
        
        $color_classes = [
            'dm-badge-blue',
            'dm-badge-green', 
            'dm-badge-purple',
            'dm-badge-orange',
            'dm-badge-red',
            'dm-badge-teal',
            'dm-badge-pink',
            'dm-badge-yellow',
            'dm-badge-indigo',
            'dm-badge-gray'
        ];
        
        return $color_classes[$color_index];
    }
}