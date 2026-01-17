<?php
/**
 * Eventbrite Event Import Handler Settings
 *
 * Defines settings fields and sanitization for Eventbrite event import handler.
 * Requires only an organizer page URL - no API authentication needed.
 *
 * @deprecated 0.9.8 Use Universal Web Scraper handler with Eventbrite URLs instead
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
        $deprecation_notice = [
            '_deprecated_notice' => [
                'type' => 'html',
                'label' => __('Important Notice', 'datamachine-events'),
                'description' => __(
                    '<strong>This handler is deprecated.</strong> Please use <strong>Universal Web Scraper</strong> with your Eventbrite organizer page URL instead. Existing flows using this handler will continue to work.',
                    'datamachine-events'
                ),
            ],
        ];

        $handler_fields = [
            'organizer_url' => [
                'type' => 'text',
                'label' => __('Organizer Page URL', 'datamachine-events'),
                'description' => __('Enter full Eventbrite organizer page URL (e.g., https://www.eventbrite.com/o/lo-fi-brewing-14959647606). Events are extracted from the public page - no API key required.', 'datamachine-events'),
                'placeholder' => __('https://www.eventbrite.com/o/organizer-name-12345678', 'datamachine-events'),
            ],
            'search' => [
                'type' => 'text',
                'label' => __('Include Keywords', 'datamachine-events'),
                'description' => __('Only import events containing any of these keywords (comma-separated). Leave empty to import all.', 'datamachine-events'),
                'placeholder' => __('concert, live music, band', 'datamachine-events'),
                'required' => false,
            ],
            'exclude_keywords' => [
                'type' => 'text',
                'label' => __('Exclude Keywords', 'datamachine-events'),
                'description' => __('Skip events containing any of these keywords (comma-separated).', 'datamachine-events'),
                'placeholder' => __('trivia, karaoke, brunch, bingo', 'datamachine-events'),
                'required' => false,
            ],
        ];

        return array_merge($deprecation_notice, $handler_fields);
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
            'search' => sanitize_text_field($raw_settings['search'] ?? ''),
            'exclude_keywords' => sanitize_text_field($raw_settings['exclude_keywords'] ?? ''),
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
            'search' => '',
            'exclude_keywords' => '',
        ];
    }
}
