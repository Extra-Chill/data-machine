<?php
/**
 * Data Machine Events Publisher Settings
 *
 * Centralized configuration management for AI-driven event publishing.
 *
 * @package DmEvents\Steps\Publish\Handlers\DmEvents
 */

namespace DmEvents\Steps\Publish\Handlers\DmEvents;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages publisher configuration and settings for Data Machine integration
 * 
 * Centralized settings management for AI-driven event creation and venue taxonomy handling.
 */
class DmEventsSettings {
    
    
    
    /**
     * Get default values for all available taxonomies (excluding venue)
     *
     * @return array Default taxonomy selections (all set to 'skip').
     */
    private static function get_taxonomy_defaults(): array {
        $defaults = [];
        
        $taxonomies = get_object_taxonomies('dm_events', 'objects');
        
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->name === 'venue') {
                continue;
            }
            
            // Skip built-in formats and other non-content taxonomies
            if (in_array($taxonomy->name, ['post_format', 'nav_menu', 'link_category']) || !$taxonomy->public) {
                continue;
            }
            
            $field_key = "taxonomy_{$taxonomy->name}_selection";
            $defaults[$field_key] = 'skip'; // Default to skip for all taxonomies
        }
        
        return $defaults;
    }
    
    
    /**
     * Get settings fields for Data Machine integration
     * 
     * Required method for Data Machine handler settings system.
     * Returns field definitions following WordPress publish handler pattern.
     * 
     * @param array $current_config Current configuration values for this handler
     * @return array Field definitions for Data Machine settings interface
     */
    public static function get_fields(array $current_config = []): array {
        // Get available WordPress users for post authorship
        $user_options = [];
        $users = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
        foreach ($users as $user) {
            $display_name = !empty($user->display_name) ? $user->display_name : $user->user_login;
            $user_options[$user->ID] = $display_name;
        }
        
        $fields = [
            'post_status' => [
                'type' => 'select',
                'label' => __('Post Status', 'dm-events'),
                'description' => __('Select the status for the newly created event.', 'dm-events'),
                'options' => [
                    'draft' => __('Draft', 'dm-events'),
                    'publish' => __('Publish', 'dm-events'),
                    'pending' => __('Pending Review', 'dm-events'),
                    'private' => __('Private', 'dm-events'),
                ],
            ],
            'include_images' => [
                'type' => 'checkbox',
                'label' => __('Include Images', 'dm-events'),
                'description' => __('Automatically set featured images for events when image URLs are provided by import handlers.', 'dm-events'),
                'default' => false,
            ],
            'post_author' => [
                'type' => 'select',
                'label' => __('Post Author', 'dm-events'),
                'description' => __('Select which WordPress user to publish events under.', 'dm-events'),
                'options' => $user_options,
            ],
        ];
        
        // Add dynamic taxonomy fields (EXCLUDING venue - handled by AI tool)
        $taxonomy_fields = self::get_taxonomy_fields();
        return array_merge($fields, $taxonomy_fields);
    }
    
    /**
     * Sanitize DM Events handler settings for Data Machine integration
     *
     * Required method for Data Machine handler settings system.
     * Validates and sanitizes form input data.
     *
     * @param array $raw_settings Raw settings input from form
     * @return array Sanitized settings
     */
    public static function sanitize(array $raw_settings): array {
        $sanitized = [
            'post_status' => sanitize_text_field($raw_settings['post_status'] ?? 'draft'),
            'post_author' => absint($raw_settings['post_author']),
        ];
        
        // Validate post status
        $valid_statuses = ['draft', 'publish', 'pending', 'private'];
        if (!in_array($sanitized['post_status'], $valid_statuses)) {
            $sanitized['post_status'] = 'draft';
        }
        
        // Sanitize dynamic taxonomy selections (EXCLUDING venue)
        $sanitized = array_merge($sanitized, self::sanitize_taxonomy_selections($raw_settings));
        
        return $sanitized;
    }
    
    /**
     * Get dynamic taxonomy fields for all available public taxonomies (excluding venue)
     *
     * @return array Taxonomy field definitions.
     */
    private static function get_taxonomy_fields(): array {
        $taxonomy_fields = [];
        
        $taxonomies = get_object_taxonomies('dm_events', 'objects');
        
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->name === 'venue') {
                continue;
            }
            
            // Skip built-in formats and other non-content taxonomies
            if (in_array($taxonomy->name, ['post_format', 'nav_menu', 'link_category']) || !$taxonomy->public) {
                continue;
            }
            
            $taxonomy_slug = $taxonomy->name;
            $taxonomy_label = $taxonomy->labels->name ?? $taxonomy->label;
            
            // Build options with skip as default
            $options = [
                'skip' => __('Skip', 'dm-events'),
                'ai_decides' => __('AI Decides', 'dm-events')
            ];
            
            // Get terms for this taxonomy
            $terms = get_terms(['taxonomy' => $taxonomy_slug, 'hide_empty' => false]);
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    $options[$term->term_id] = $term->name;
                }
            }
            
            // Generate field definition
            $field_key = "taxonomy_{$taxonomy_slug}_selection";
            $taxonomy_fields[$field_key] = [
                'type' => 'select',
                'label' => $taxonomy_label,
                'description' => sprintf(
                    __('Configure %s assignment: Skip to exclude from AI instructions, let AI choose, or select specific %s.', 'dm-events'),
                    strtolower($taxonomy_label),
                    $taxonomy->hierarchical ? __('category', 'dm-events') : __('term', 'dm-events')
                ),
                'options' => $options,
            ];
        }
        
        return $taxonomy_fields;
    }
    
    /**
     * Sanitize dynamic taxonomy selection settings (excluding venue)
     *
     * @param array $raw_settings Raw settings array.
     * @return array Sanitized taxonomy selections.
     */
    private static function sanitize_taxonomy_selections(array $raw_settings): array {
        $sanitized = [];
        
        $taxonomies = get_object_taxonomies('dm_events', 'objects');
        
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->name === 'venue') {
                continue;
            }
            
            // Skip built-in formats and other non-content taxonomies
            if (in_array($taxonomy->name, ['post_format', 'nav_menu', 'link_category']) || !$taxonomy->public) {
                continue;
            }
            
            $field_key = "taxonomy_{$taxonomy->name}_selection";
            $raw_value = $raw_settings[$field_key] ?? 'skip';
            
            // Sanitize taxonomy selection value
            if ($raw_value === 'skip' || $raw_value === 'ai_decides') {
                $sanitized[$field_key] = $raw_value;
            } else {
                // Must be a term ID - validate it exists in this taxonomy
                $term_id = absint($raw_value);
                $term = get_term($term_id, $taxonomy->name);
                if (!is_wp_error($term) && $term) {
                    $sanitized[$field_key] = $term_id;
                } else {
                    // Invalid term ID - default to skip
                    $sanitized[$field_key] = 'skip';
                }
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Get taxonomy terms for AI context
     *
     * @param string $taxonomy_name Taxonomy name
     * @return array Terms with name and description for AI context
     */
    public static function get_taxonomy_terms_for_ai(string $taxonomy_name): array {
        $terms = get_terms([
            'taxonomy' => $taxonomy_name,
            'hide_empty' => false,
            'number' => 20 // Limit for AI context
        ]);
        
        if (is_wp_error($terms)) {
            return [];
        }
        
        $ai_terms = [];
        foreach ($terms as $term) {
            $ai_terms[] = [
                'name' => $term->name,
                'description' => $term->description
            ];
        }
        
        return $ai_terms;
    }
    
}