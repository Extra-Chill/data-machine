<?php
/**
 * Ticketmaster Event Import Handler Settings
 * 
 * Defines settings fields and sanitization for Ticketmaster event import handler.
 * Part of the modular handler architecture for Data Machine integration.
 *
 * @package ChillEvents\Steps\EventImport\Handlers\Ticketmaster
 * @since 1.0.0
 */

namespace ChillEvents\Steps\EventImport\Handlers\Ticketmaster;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * TicketmasterSettings class
 * 
 * Configuration fields for Ticketmaster Discovery API event import.
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
            'city' => [
                'type' => 'text',
                'label' => __('City', 'chill-events'),
                'description' => __('City to search for events (e.g., Charleston, New York)', 'chill-events'),
                'placeholder' => __('Charleston', 'chill-events'),
            ],
            'state_code' => [
                'type' => 'text',
                'label' => __('State Code', 'chill-events'),
                'description' => __('Two-letter state code (e.g., SC, CA, NY)', 'chill-events'),
                'placeholder' => __('SC', 'chill-events'),
            ],
            'country_code' => [
                'type' => 'text',
                'label' => __('Country Code', 'chill-events'),
                'description' => __('Two-letter country code (e.g., US, CA, GB)', 'chill-events'),
                'placeholder' => __('US', 'chill-events'),
            ],
            'start_date' => [
                'type' => 'text',
                'label' => __('Start Date', 'chill-events'),
                'description' => __('Start date for event search in ISO format (YYYY-MM-DDTHH:mm:ssZ). Leave empty for current date.', 'chill-events'),
                'placeholder' => __('2024-01-01T00:00:00Z', 'chill-events'),
            ],
            'genre' => [
                'type' => 'text',
                'label' => __('Genre ID', 'chill-events'),
                'description' => __('Ticketmaster Genre ID for filtering (e.g., KnvZfZ7vAeA for Music). Leave empty for all genres.', 'chill-events'),
                'placeholder' => __('KnvZfZ7vAeA', 'chill-events'),
            ],
            'venue_id' => [
                'type' => 'text',
                'label' => __('Venue ID', 'chill-events'),
                'description' => __('Specific Ticketmaster Venue ID to search. Leave empty to search all venues.', 'chill-events'),
                'placeholder' => __('KovZpZAJledA', 'chill-events'),
            ]
        ];
    }
}