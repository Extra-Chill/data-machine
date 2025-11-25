<?php
/**
 * Event Upsert Handler Settings
 *
 * Configuration management for Event Upsert handler with automatic venue handling.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 * @since 0.2.5
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachine\Core\WordPress\WordPressSettingsHandler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages Event Upsert configuration and settings for Data Machine integration
 * 
 * Provides settings interface for intelligent create-or-update event handling
 * with automatic venue metadata population from import handlers.
 */
class EventUpsertSettings {
    
    /**
     * Get settings fields for Data Machine integration
     * 
     * Required method for Data Machine handler settings system.
     * Returns field definitions for Event Upsert handler configuration.
     * 
     * @param array $current_config Current configuration values for this handler
     * @return array Field definitions for Data Machine settings interface
     */
    public static function get_fields(array $current_config = []): array {
        // Get available WordPress users for post authorship
        $user_options = WordPressSettingsHandler::get_user_options();
        
        $fields = [
            'venue_info' => [
                'type' => 'info',
                'label' => __('Automatic Venue Handling', 'datamachine-events'),
                'description' => __('Venues are automatically populated from import handlers (Ticketmaster, Dice FM, Web Scraper, Google Calendar). When available, the following venue metadata is automatically extracted and used to create or update venue taxonomy terms: <strong>Name, Address, City, State, Zip, Country, Phone, Website, GPS Coordinates, Capacity</strong>. The AI will use this data when creating event posts, and venues will be automatically assigned to events.', 'datamachine-events'),
            ],
            'post_status' => [
                'type' => 'select',
                'label' => __('Post Status', 'datamachine-events'),
                'description' => __('Select the status for created/updated events.', 'datamachine-events'),
                'options' => [
                    'draft' => __('Draft', 'datamachine-events'),
                    'publish' => __('Publish', 'datamachine-events'),
                    'pending' => __('Pending Review', 'datamachine-events'),
                    'private' => __('Private', 'datamachine-events'),
                ],
            ],
            'include_images' => [
                'type' => 'checkbox',
                'label' => __('Include Images', 'datamachine-events'),
                'description' => __('Automatically set featured images for events when image URLs are provided by import handlers.', 'datamachine-events'),
                'default' => false,
            ],
            'post_author' => [
                'type' => 'select',
                'label' => __('Post Author', 'datamachine-events'),
                'description' => __('Select which WordPress user to publish events under.', 'datamachine-events'),
                'options' => $user_options,
            ],
        ];
        
        // Add dynamic taxonomy fields using core WordPressSettingsHandler
        // Venue is excluded because it has a custom handler registered in EventUpsert.php
        $taxonomy_fields = WordPressSettingsHandler::get_taxonomy_fields([
            'post_type' => 'datamachine_events',
            'exclude_taxonomies' => ['venue'],
            'field_suffix' => '_selection',
            'first_options' => [
                'skip' => __('Skip', 'datamachine-events'),
                'ai_decides' => __('AI Decides', 'datamachine-events')
            ],
            'description_template' => __('Configure %1$s assignment: Skip to exclude from AI instructions, let AI choose, or select specific %2$s.', 'datamachine-events'),
            'default' => 'skip'
        ]);
        return array_merge($fields, $taxonomy_fields);
    }
    
    /**
     * Sanitize Event Upsert handler settings for Data Machine integration
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
            'post_author' => absint($raw_settings['post_author'] ?? 0),
            'include_images' => !empty($raw_settings['include_images']),
        ];
        
        // Validate post status
        $valid_statuses = ['draft', 'publish', 'pending', 'private'];
        if (!in_array($sanitized['post_status'], $valid_statuses)) {
            $sanitized['post_status'] = 'draft';
        }
        
        // Sanitize dynamic taxonomy selections using core WordPressSettingsHandler
        // Venue is excluded because it has a custom handler registered in EventUpsert.php
        $sanitized = array_merge($sanitized, WordPressSettingsHandler::sanitize_taxonomy_fields($raw_settings, [
            'post_type' => 'datamachine_events',
            'exclude_taxonomies' => ['venue'],
            'field_suffix' => '_selection',
            'allowed_values' => ['skip', 'ai_decides'],
            'default_value' => 'skip'
        ]));
        
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
            'number' => 20
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
