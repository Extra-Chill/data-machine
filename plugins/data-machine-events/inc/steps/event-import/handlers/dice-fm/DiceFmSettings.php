<?php
/**
 * Dice.fm Event Import Handler Settings
 * 
 * Defines settings fields and sanitization for Dice.fm event import handler.
 * Part of the modular handler architecture for Data Machine integration.
 *
 * @package ChillEvents\Steps\EventImport\Handlers\DiceFm
 * @since 1.0.0
 */

namespace ChillEvents\Steps\EventImport\Handlers\DiceFm;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * DiceFmSettings class
 * 
 * Configuration fields for Dice.fm API event import.
 */
class DiceFmSettings {
    
    /**
     * Constructor
     * Pure filter-based architecture - no dependencies.
     */
    public function __construct() {
        // No constructor dependencies - all services accessed via filters
    }
    
    /**
     * Get settings fields for Dice.fm event import handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        return [
            'city' => [
                'type' => 'text',
                'label' => __('City', 'chill-events'),
                'description' => __('City name to search for events (required). This is the primary filter for Dice.fm API.', 'chill-events'),
                'placeholder' => __('Charleston', 'chill-events'),
                'required' => true,
            ],
            'date_range' => [
                'type' => 'number',
                'label' => __('Date Range (Days)', 'chill-events'),
                'description' => __('Number of days to look ahead for events. Default is 90 days.', 'chill-events'),
                'default' => 90,
                'min' => 1,
                'max' => 365,
            ],
            'page_size' => [
                'type' => 'number',
                'label' => __('Page Size', 'chill-events'),
                'description' => __('Number of events to fetch per API request. Default is 100 (maximum allowed by API).', 'chill-events'),
                'default' => 100,
                'min' => 1,
                'max' => 100,
            ],
            'event_types' => [
                'type' => 'text',
                'label' => __('Event Types', 'chill-events'),
                'description' => __('Comma-separated list of event types to include (linkout,event). Default includes both.', 'chill-events'),
                'default' => 'linkout,event',
                'placeholder' => __('linkout,event', 'chill-events'),
            ]
        ];
    }
}