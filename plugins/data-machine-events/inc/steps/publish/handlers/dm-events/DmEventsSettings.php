<?php
/**
 * Data Machine Events Publish Handler Settings
 *
 * Defines settings fields and sanitization for Data Machine Events publish handler.
 * Focuses on taxonomy configuration for AI-powered data sorting.
 *
 * @package DmEvents\Steps\Publish\Handlers\DmEvents
 * @since 1.0.0
 */

namespace DmEvents\Steps\Publish\Handlers\DmEvents;

if (!defined('ABSPATH')) {
    exit;
}

class DmEventsSettings {

    /**
     * Constructor.
     * Pure filter-based architecture - no dependencies.
     */
    public function __construct() {
        // No constructor dependencies - all services accessed via filters
    }

    /**
     * Get settings fields for Data Machine Events publish handler.
     *
     * @param array $current_config Current configuration values for this handler.
     * @return array Associative array defining the settings fields.
     */
    public static function get_fields(array $current_config = []): array {
        // Check if dm_events post type exists
        if (!post_type_exists('dm_events')) {
            return [];
        }
        
        // Merge post status field, author field, and taxonomy fields
        return array_merge(
            self::get_post_status_field(), 
            self::get_author_field(), 
            self::get_taxonomy_fields()
        );
    }

    /**
     * Get post status field for event publishing.
     *
     * @return array Post status field definition.
     */
    private static function get_post_status_field(): array {
        return [
            'event_post_status' => [
                'type' => 'select',
                'label' => __('Post Status', 'dm-events'),
                'description' => __('Choose the status for published events.', 'dm-events'),
                'options' => [
                    'publish' => __('Published', 'dm-events'),
                    'draft' => __('Draft', 'dm-events'),
                    'pending' => __('Pending Review', 'dm-events'),
                    'private' => __('Private', 'dm-events')
                ]
            ]
        ];
    }

    /**
     * Get post author field for event publishing.
     *
     * @return array Author field definition.
     */
    private static function get_author_field(): array {
        // Get available WordPress users for post authorship (same pattern as WordPress handler)
        $user_options = [];
        $users = get_users(['fields' => ['ID', 'display_name', 'user_login']]);
        foreach ($users as $user) {
            $display_name = !empty($user->display_name) ? $user->display_name : $user->user_login;
            $user_options[$user->ID] = $display_name;
        }

        return [
            'post_author' => [
                'type' => 'select',
                'label' => __('Post Author', 'dm-events'),
                'description' => __('Select which WordPress user to publish events under.', 'dm-events'),
                'options' => $user_options,
            ],
        ];
    }

    /**
     * Get dynamic taxonomy fields for all public taxonomies on site.
     *
     * @return array Taxonomy field definitions.
     */
    private static function get_taxonomy_fields(): array {
        $taxonomy_fields = [];
        
        // Get all public taxonomies (Data Machine pattern)
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        
        // Return empty array if no taxonomies found or on error
        if (!$taxonomies || is_wp_error($taxonomies)) {
            return [];
        }
        
        foreach ($taxonomies as $taxonomy) {
            // Skip private taxonomies
            if (!$taxonomy->public) {
                continue;
            }
            
            $taxonomy_slug = $taxonomy->name;
            $taxonomy_label = $taxonomy->labels->name ?? $taxonomy->label;
            
            // Skip venue taxonomy - handled autonomously by system
            if ($taxonomy_slug === 'venue') {
                continue;
            }
            
            // Build options for other taxonomies
            $options = [
                'skip' => __('Skip', 'dm-events'),
                'ai_decides' => __('AI Decides', 'dm-events')
            ];
            
            // Get terms for this taxonomy
            $terms = get_terms(['taxonomy' => $taxonomy_slug, 'hide_empty' => false]);
            if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
                foreach ($terms as $term) {
                    if (isset($term->term_id, $term->name)) {
                        $options[$term->term_id] = $term->name;
                    }
                }
            }
            
            // Generate field definition
            $field_key = "taxonomy_{$taxonomy_slug}_selection";
            $taxonomy_fields[$field_key] = [
                'type' => 'select',
                'label' => $taxonomy_label,
                'description' => sprintf(
                    /* translators: %1$s: taxonomy name (lowercase), %2$s: category or term */
                    __('Configure %1$s assignment: Skip to exclude from AI instructions, let AI choose, or select specific %2$s.', 'dm-events'),
                    strtolower($taxonomy_label),
                    $taxonomy->hierarchical ? __('category', 'dm-events') : __('term', 'dm-events')
                ),
                'options' => $options,
            ];
        }
        
        return $taxonomy_fields;
    }

    /**
     * Sanitize Data Machine Events publish handler settings.
     *
     * @param array $raw_settings Raw settings input.
     * @return array Sanitized settings.
     */
    public static function sanitize(array $raw_settings): array {
        // Merge post status and taxonomy sanitization
        return array_merge(
            self::sanitize_post_status($raw_settings),
            self::sanitize_taxonomy_selections($raw_settings)
        );
    }

    /**
     * Sanitize post status setting.
     *
     * @param array $raw_settings Raw settings array.
     * @return array Sanitized post status setting.
     */
    private static function sanitize_post_status(array $raw_settings): array {
        $valid_statuses = ['publish', 'draft', 'pending', 'private'];
        $raw_status = $raw_settings['event_post_status'] ?? 'publish';
        
        return [
            'event_post_status' => in_array($raw_status, $valid_statuses) ? $raw_status : 'publish'
        ];
    }

    /**
     * Sanitize dynamic taxonomy selection settings.
     *
     * @param array $raw_settings Raw settings array.
     * @return array Sanitized taxonomy selections.
     */
    private static function sanitize_taxonomy_selections(array $raw_settings): array {
        $sanitized = [];
        
        // Get all public taxonomies (Data Machine pattern)
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        
        foreach ($taxonomies as $taxonomy) {
            // Skip private taxonomies
            if (!$taxonomy->public) {
                continue;
            }
            
            // Skip venue taxonomy - handled autonomously by system
            if ($taxonomy->name === 'venue') {
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
     * Get default values for all settings.
     *
     * @return array Default values for all settings.
     */
    public static function get_defaults(): array {
        // Merge post status defaults with taxonomy defaults
        return array_merge(self::get_post_status_defaults(), self::get_taxonomy_defaults());
    }

    /**
     * Get default post status setting.
     *
     * @return array Default post status setting.
     */
    private static function get_post_status_defaults(): array {
        return [
            'event_post_status' => 'publish'
        ];
    }

    /**
     * Get default values for all available taxonomies.
     *
     * @return array Default taxonomy selections (all set to 'skip').
     */
    private static function get_taxonomy_defaults(): array {
        $defaults = [];
        
        // Check if post type exists
        if (!post_type_exists('dm_events')) {
            return $defaults;
        }
        
        // Get all public taxonomies (Data Machine pattern)
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        
        if (!$taxonomies || is_wp_error($taxonomies)) {
            return $defaults;
        }
        
        foreach ($taxonomies as $taxonomy) {
            // Skip private taxonomies
            if (!$taxonomy->public) {
                continue;
            }
            
            // Skip venue taxonomy - no defaults needed (handled autonomously)
            if ($taxonomy->name === 'venue') {
                continue;
            }
            
            $field_key = "taxonomy_{$taxonomy->name}_selection";
            $defaults[$field_key] = 'skip';
        }
        
        return $defaults;
    }

    /**
     * Determine if authentication is required based on current configuration.
     *
     * @param array $current_config Current configuration values for this handler.
     * @return bool True if authentication is required, false otherwise.
     */
    public static function requires_authentication(array $current_config = []): bool {
        // Local Data Machine Events does not require authentication
        return false;
    }

    /**
     * Get taxonomy fields configured for AI decision making.
     * 
     * Used by the publisher to determine which taxonomies to expose to AI.
     *
     * @param array $settings Handler settings.
     * @return array Array of taxonomy slugs that should be handled by AI.
     */
    public static function get_ai_taxonomy_fields(array $settings): array {
        $ai_taxonomies = [];
        
        // Check if post type exists
        if (!post_type_exists('dm_events')) {
            return $ai_taxonomies;
        }
        
        // Get all public taxonomies (Data Machine pattern)
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        
        if (!$taxonomies || is_wp_error($taxonomies)) {
            return $ai_taxonomies;
        }
        
        foreach ($taxonomies as $taxonomy) {
            // Skip private taxonomies
            if (!$taxonomy->public) {
                continue;
            }
            
            $field_key = "taxonomy_{$taxonomy->name}_selection";
            
            if (isset($settings[$field_key]) && $settings[$field_key] === 'ai_decides') {
                $ai_taxonomies[] = $taxonomy->name;
            }
        }
        
        return $ai_taxonomies;
    }

    /**
     * Get terms for a specific taxonomy as options for AI context.
     *
     * @param string $taxonomy_slug Taxonomy slug.
     * @return array Array of term options for AI context.
     */
    public static function get_taxonomy_terms_for_ai(string $taxonomy_slug): array {
        // Validate taxonomy exists
        if (!taxonomy_exists($taxonomy_slug)) {
            return [];
        }
        
        $terms = get_terms(['taxonomy' => $taxonomy_slug, 'hide_empty' => false]);
        $options = [];
        
        if (!is_wp_error($terms) && !empty($terms) && is_array($terms)) {
            foreach ($terms as $term) {
                if (isset($term->term_id, $term->name, $term->slug)) {
                    $options[] = [
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'description' => $term->description ?? ''
                    ];
                }
            }
        }
        
        return $options;
    }
}