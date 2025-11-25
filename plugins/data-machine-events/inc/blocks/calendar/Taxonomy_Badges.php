<?php
/**
 * Calendar Block Taxonomy Badge System
 *
 * Self-contained badge rendering for Calendar block event items. Provides extensibility
 * filters for themes/plugins (datamachine_events_badge_wrapper_classes, datamachine_events_badge_classes,
 * datamachine_events_excluded_taxonomies) while keeping badge logic within Calendar block.
 * Supports hash-based color classes for consistent styling.
 *
 * Filter: datamachine_events_excluded_taxonomies
 * @param array  $excluded Array of taxonomy slugs to exclude
 * @param string $context  Context identifier: 'badge', 'modal', or empty for all contexts
 *
 * @package DataMachineEvents\Blocks\Calendar
 */

namespace DataMachineEvents\Blocks\Calendar;

if (!defined('ABSPATH')) {
    exit;
}

class Taxonomy_Badges {

    /**
     * @param int $post_id Event post ID
     * @return array Structured array of taxonomy objects and terms
     */
    public static function get_event_taxonomies($post_id) {
        if (!$post_id) {
            return [];
        }
        
        $all_taxonomies = get_object_taxonomies('datamachine_events', 'objects');
        
        if (empty($all_taxonomies)) {
            return [];
        }
        
        $taxonomy_data = [];

        $excluded_taxonomies = apply_filters('datamachine_events_excluded_taxonomies', array(), 'badge');

        foreach ($all_taxonomies as $taxonomy_slug => $taxonomy_object) {
            if (in_array($taxonomy_slug, $excluded_taxonomies, true)) {
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
     * @param int $post_id Event post ID
     * @return string Badge HTML with wrapper and data attributes for each term
     */
    public static function render_taxonomy_badges($post_id) {
        $taxonomies = self::get_event_taxonomies($post_id);

        if (empty($taxonomies)) {
            return '';
        }

        $wrapper_classes = apply_filters('datamachine_events_badge_wrapper_classes', [
            'datamachine-taxonomy-badges'
        ], $post_id);

        $output = '<div class="' . esc_attr(implode(' ', $wrapper_classes)) . '">';

        foreach ($taxonomies as $taxonomy_slug => $taxonomy_data) {
            $taxonomy_object = $taxonomy_data['taxonomy'];
            $terms = $taxonomy_data['terms'];

            foreach ($terms as $term) {
                $badge_classes = [
                    'datamachine-taxonomy-badge',
                    'datamachine-taxonomy-' . esc_attr($taxonomy_slug),
                    'datamachine-term-' . esc_attr($term->slug)
                ];

                $badge_classes = apply_filters('datamachine_events_badge_classes', $badge_classes, $taxonomy_slug, $term, $post_id);

                $term_link = get_term_link($term, $taxonomy_slug);

                if (is_wp_error($term_link)) {
                    $output .= sprintf(
                        '<span class="%s" data-taxonomy="%s" data-term="%s">%s</span>',
                        esc_attr(implode(' ', $badge_classes)),
                        esc_attr($taxonomy_slug),
                        esc_attr($term->slug),
                        esc_html($term->name)
                    );
                } else {
                    $output .= sprintf(
                        '<a href="%s" class="%s" data-taxonomy="%s" data-term="%s">%s</a>',
                        esc_url($term_link),
                        esc_attr(implode(' ', $badge_classes)),
                        esc_attr($taxonomy_slug),
                        esc_attr($term->slug),
                        esc_html($term->name)
                    );
                }
            }
        }

        $output .= '</div>';

        return $output;
    }
    
     /**
      * @return array Taxonomies with slug and label, filtered by datamachine_events_excluded_taxonomies
      */
    public static function get_used_taxonomies() {
        global $wpdb;

        $excluded_taxonomies = apply_filters('datamachine_events_excluded_taxonomies', array(), 'badge');
        $excluded_sql = '';
        if (!empty($excluded_taxonomies)) {
            $excluded_sql = "AND tt.taxonomy NOT IN ('" . implode("','", array_map('esc_sql', $excluded_taxonomies)) . "')";
        }

        $query = "
            SELECT DISTINCT tt.taxonomy, t.name
            FROM {$wpdb->term_taxonomy} tt
            INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
            INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE p.post_type = 'datamachine_events'
            AND p.post_status = 'publish'
            {$excluded_sql}
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
                    'label' => $taxonomy_object && is_object($taxonomy_object->labels) && isset($taxonomy_object->labels->name)
                        ? $taxonomy_object->labels->name
                        : $result->taxonomy
                ];
            }
        }
        
        return $used_taxonomies;
    }
    
     /**
      * @param string $taxonomy_slug
      * @return string Hash-based color class (datamachine-badge-{color})
      */
    public static function get_taxonomy_color_class($taxonomy_slug) {
        $hash = md5($taxonomy_slug);
        $color_index = hexdec(substr($hash, 0, 1)) % 10;
        
        $color_classes = [
            'datamachine-badge-blue',
            'datamachine-badge-green',
            'datamachine-badge-purple',
            'datamachine-badge-orange',
            'datamachine-badge-red',
            'datamachine-badge-teal',
            'datamachine-badge-pink',
            'datamachine-badge-yellow',
            'datamachine-badge-indigo',
            'datamachine-badge-gray'
        ];
        
        return $color_classes[$color_index];
    }
}