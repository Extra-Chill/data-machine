<?php
/**
 * Dynamic Taxonomies for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;

if (!defined('ABSPATH')) {
    exit;
}

class DynamicTaxonomies {
    /**
     * Register all mapped taxonomies for chill_events post type
     *
     * @since 1.0.0
     */
    public static function register_for_chill_events() {
        global $wpdb;
        $table = $wpdb->prefix . 'chill_import_modules';
        $taxonomies = [];

        // Check if table exists first
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            error_log('Chill Events: Import modules table does not exist');
            return;
        }

        // Check if taxonomy_mappings column exists
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'taxonomy_mappings'");
        if (empty($column_exists)) {
            error_log('Chill Events: taxonomy_mappings column missing - triggering table structure verification');
            if (class_exists('ChillEvents\\Database')) {
                \ChillEvents\Database::verify_table_structure();
            }
            return;
        }

        // Get all active import modules
        $modules = $wpdb->get_results("SELECT taxonomy_mappings, status FROM $table WHERE status = 'active'");
        
        if ($wpdb->last_error) {
            error_log('Chill Events DynamicTaxonomies SQL Error: ' . $wpdb->last_error);
            return;
        }
        
        foreach ($modules as $module) {
            if (empty($module->taxonomy_mappings)) continue;
            $mappings = maybe_unserialize($module->taxonomy_mappings);
            if (!is_array($mappings)) continue;
            foreach ($mappings as $field => $taxonomy) {
                if ($taxonomy && $taxonomy !== 'skip') {
                    $taxonomies[] = $taxonomy;
                }
            }
        }
        $taxonomies = array_unique($taxonomies);
        foreach ($taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                register_taxonomy_for_object_type($taxonomy, 'chill_events');
            }
        }
        
        // Auto-register any existing taxonomies for chill_events (theme flexibility)
        $all_taxonomies = get_taxonomies(array('public' => true), 'names');
        foreach ($all_taxonomies as $taxonomy) {
            // Skip venue taxonomy (handled by core) and built-in taxonomies
            if ($taxonomy !== 'venue' && !in_array($taxonomy, array('category', 'post_tag', 'nav_menu', 'link_category', 'post_format'))) {
                register_taxonomy_for_object_type($taxonomy, 'chill_events');
            }
        }
    }

    /**
     * Ensure a term exists in a taxonomy, create if needed
     *
     * @param string $taxonomy
     * @param string $term_name
     * @return int|WP_Error Term ID or WP_Error
     */
    public static function ensure_term($taxonomy, $term_name) {
        if (empty($term_name)) {
            return new \WP_Error('empty_term', 'Term name cannot be empty');
        }
        
        if (!taxonomy_exists($taxonomy)) {
            // For venue taxonomy, log but don't fail - fallback to post meta
            if ($taxonomy === 'venue') {
                error_log('[ChillEvents][DEBUG] Venue taxonomy does not exist, falling back to post meta');
                return new \WP_Error('venue_taxonomy_missing', 'Venue taxonomy does not exist');
            }
            return new \WP_Error('invalid_taxonomy', 'Taxonomy does not exist: ' . $taxonomy);
        }
        
        // Check if term already exists
        $term = get_term_by('name', $term_name, $taxonomy);
        if ($term) {
            return $term->term_id;
        }
        
        // Create new term
        $result = wp_insert_term($term_name, $taxonomy);
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $result['term_id'];
    }
} 