<?php
/**
 * Eventbrite Event Import Handler Settings
 * 
 * Defines settings fields and sanitization for Eventbrite event import handler.
 * Requires only an organizer page URL - no API authentication needed.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Eventbrite
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Eventbrite;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EventbriteSettings class
 * 
 * Provides configuration fields for Eventbrite organizer page imports.
 * Uses public JSON-LD structured data extraction - no API key required.
 */
class EventbriteSettings {
    
    /**
     * Get settings fields for Eventbrite event import handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        return [
            'organizer_url' => [
                'type' => 'text',
                'label' => __('Organizer Page URL', 'datamachine-events'),
                'description' => __('Enter the full Eventbrite organizer page URL (e.g., https://www.eventbrite.com/o/lo-fi-brewing-14959647606). Events are extracted from the public page - no API key required.', 'datamachine-events'),
                'placeholder' => __('https://www.eventbrite.com/o/organizer-name-12345678', 'datamachine-events'),
            ],
        ];
    }
    
    /**
     * Sanitize Eventbrite handler settings.
     *
     * @param array $raw_settings Raw settings input.
     * @return array Sanitized settings.
     */
    public static function sanitize(array $raw_settings): array {
        $organizer_url = esc_url_raw($raw_settings['organizer_url'] ?? '');
        
        if (!empty($organizer_url) && strpos($organizer_url, 'eventbrite.com') === false) {
            $organizer_url = '';
        }
        
        return [
            'organizer_url' => $organizer_url,
        ];
    }
    
    /**
     * Determine if authentication is required.
     *
     * @param array $current_config Current configuration values.
     * @return bool True if authentication is required.
     */
    public static function requires_authentication(array $current_config = []): bool {
        return false;
    }
    
    /**
     * Get default values for all settings.
     *
     * @return array Default values.
     */
    public static function get_defaults(): array {
        return [
            'organizer_url' => '',
        ];
    }
}
