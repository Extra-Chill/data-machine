<?php
/**
 * Ticketmaster Event Import Handler Settings
 * 
 * Defines settings fields and sanitization for Ticketmaster event import handler.
 * Part of the modular handler architecture for Data Machine integration.
 *
 * @package DmEvents\Steps\EventImport\Handlers\Ticketmaster
 * @since 1.0.0
 */

namespace DmEvents\Steps\EventImport\Handlers\Ticketmaster;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * TicketmasterSettings class
 * 
 * Provides configuration fields for Ticketmaster Discovery API integration
 * including location, classification, and API parameter settings.
 * 
 * @since 1.0.0
 */
class TicketmasterSettings {
    
    /**
     * Constructor
     * Pure filter-based architecture - no dependencies.
     */
    public function __construct() {
        // No constructor dependencies - all services accessed via filters
    }
    
    /**
     * Get settings fields for Ticketmaster event import handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        return [
            'classification_type' => [
                'type' => 'select',
                'label' => __('Event Type', 'dm-events'),
                'description' => __('Select the type of events to import. Options are fetched dynamically from Ticketmaster API.', 'dm-events'),
                'options' => Ticketmaster::get_classifications_for_dropdown($current_config),
            ],
            'location' => [
                'type' => 'text',
                'label' => __('Location Coordinates', 'dm-events'),
                'description' => __('Enter coordinates as "latitude,longitude" (e.g., "32.7765,-79.9311"). To get coordinates: Go to maps.google.com, find your location, right-click, and copy the numbers.', 'dm-events'),
                'placeholder' => __('32.7765,-79.9311', 'dm-events'),
            ],
            'radius' => [
                'type' => 'text',
                'label' => __('Search Radius (Miles)', 'dm-events'),
                'description' => __('Search radius in miles around the specified location. Default is 50 miles.', 'dm-events'),
                'placeholder' => __('50', 'dm-events'),
            ],
            'start_date' => [
                'type' => 'text',
                'label' => __('Start Date', 'dm-events'),
                'description' => __('Start date for event search in ISO format (YYYY-MM-DDTHH:mm:ssZ). Leave empty for current date.', 'dm-events'),
                'placeholder' => __('2024-01-01T00:00:00Z', 'dm-events'),
            ],
            'genre' => [
                'type' => 'text',
                'label' => __('Genre ID (Advanced)', 'dm-events'),
                'description' => __('Optional: Specific Ticketmaster Genre ID for sub-filtering within the selected event type (e.g., KnvZfZ7vAeA for Rock music). Leave empty for all genres within the event type.', 'dm-events'),
                'placeholder' => __('KnvZfZ7vAeA', 'dm-events'),
            ],
            'venue_id' => [
                'type' => 'text',
                'label' => __('Venue ID', 'dm-events'),
                'description' => __('Specific Ticketmaster Venue ID to search. Leave empty to search all venues.', 'dm-events'),
                'placeholder' => __('KovZpZAJledA', 'dm-events'),
            ]
        ];
    }
    
    /**
     * Sanitize Ticketmaster handler settings.
     *
     * @param array $raw_settings Raw settings input.
     * @return array Sanitized settings.
     */
    public static function sanitize(array $raw_settings): array {
        return [
            'classification_type' => sanitize_text_field($raw_settings['classification_type'] ?? ''),
            'location' => sanitize_text_field($raw_settings['location'] ?? ''),
            'radius' => sanitize_text_field($raw_settings['radius'] ?? '50'),
            'start_date' => sanitize_text_field($raw_settings['start_date'] ?? ''),
            'genre' => sanitize_text_field($raw_settings['genre'] ?? ''),
            'venue_id' => sanitize_text_field($raw_settings['venue_id'] ?? '')
        ];
    }
    
    /**
     * Determine if authentication is required.
     *
     * @param array $current_config Current configuration values.
     * @return bool True if authentication is required.
     */
    public static function requires_authentication(array $current_config = []): bool {
        return true; // Ticketmaster requires API key authentication
    }
    
    /**
     * Get default values for all settings.
     *
     * @return array Default values.
     */
    public static function get_defaults(): array {
        return [
            'classification_type' => 'all',
            'location' => '32.7765,-79.9311',
            'radius' => '50',
            'start_date' => '',
            'genre' => '',
            'venue_id' => ''
        ];
    }
}