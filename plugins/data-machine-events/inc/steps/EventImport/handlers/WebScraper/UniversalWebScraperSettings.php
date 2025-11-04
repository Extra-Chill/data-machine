<?php
/**
 * Universal Web Scraper Settings
 * 
 * Simple settings class with just URL field - no dropdown complexity.
 * Clean and direct configuration for AI-powered web scraping.
 *
 * @package DmEvents\Steps\EventImport\Handlers\WebScraper
 * @since 1.0.0
 */

namespace DmEvents\Steps\EventImport\Handlers\WebScraper;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * UniversalWebScraperSettings class
 * 
 * Simple configuration for universal AI-powered web scraping.
 */
class UniversalWebScraperSettings {
    
    /**
     * Get settings fields for Universal Web Scraper handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        return [
            'source_url' => [
                'type' => 'url',
                'label' => __('Website URL', 'dm-events'),
                'description' => __('URL of the webpage containing events. The AI will analyze the page and extract event information automatically.', 'dm-events'),
                'placeholder' => 'https://venue.com/events',
                'required' => true,
            ],
            'venue' => [
                'type' => 'text',
                'label' => __('Venue Name', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue name from page content.', 'dm-events'),
                'placeholder' => 'The Royal American',
                'required' => false,
            ],
            'venue_address' => [
                'type' => 'text',
                'label' => __('Venue Address', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue address from page content. Start typing to see address suggestions.', 'dm-events'),
                'placeholder' => '970 Morrison Drive',
                'required' => false,
                'attributes' => [
                    'class' => 'venue-address-autocomplete',
                    'data-city-field' => 'venue_city',
                    'data-state-field' => 'venue_state',
                    'data-zip-field' => 'venue_zip',
                    'data-country-field' => 'venue_country',
                    'data-coords-field' => 'venue_coordinates',
                ],
            ],
            'venue_city' => [
                'type' => 'text',
                'label' => __('Venue City', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue city from page content.', 'dm-events'),
                'placeholder' => 'Charleston',
                'required' => false,
            ],
            'venue_state' => [
                'type' => 'text',
                'label' => __('Venue State', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue state from page content.', 'dm-events'),
                'placeholder' => 'SC',
                'required' => false,
            ],
            'venue_zip' => [
                'type' => 'text',
                'label' => __('Venue Zip Code', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue zip code from page content.', 'dm-events'),
                'placeholder' => '29403',
                'required' => false,
            ],
            'venue_country' => [
                'type' => 'text',
                'label' => __('Venue Country', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue country from page content.', 'dm-events'),
                'placeholder' => 'US',
                'required' => false,
            ],
            'venue_phone' => [
                'type' => 'text',
                'label' => __('Venue Phone', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue phone from page content.', 'dm-events'),
                'placeholder' => '(843) 817-6925',
                'required' => false,
            ],
            'venue_website' => [
                'type' => 'url',
                'label' => __('Venue Website', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue website from page content.', 'dm-events'),
                'placeholder' => 'https://www.theroyalamerican.com',
                'required' => false,
            ],
            'venue_coordinates' => [
                'type' => 'text',
                'label' => __('Venue Coordinates', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue coordinates from page content. Format: latitude,longitude', 'dm-events'),
                'placeholder' => '32.7900,-79.9400',
                'required' => false,
            ],
            'venue_capacity' => [
                'type' => 'number',
                'label' => __('Venue Capacity', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue capacity from page content.', 'dm-events'),
                'placeholder' => '500',
                'required' => false,
            ]
        ];
    }
    
    /**
     * Sanitize Universal Web Scraper handler settings
     *
     * @param array $raw_settings Raw settings input
     * @return array Sanitized settings
     */
    public static function sanitize(array $raw_settings): array {
        return [
            'source_url' => esc_url_raw($raw_settings['source_url'] ?? ''),
            'venue' => sanitize_text_field($raw_settings['venue'] ?? ''),
            'venue_address' => sanitize_text_field($raw_settings['venue_address'] ?? ''),
            'venue_city' => sanitize_text_field($raw_settings['venue_city'] ?? ''),
            'venue_state' => sanitize_text_field($raw_settings['venue_state'] ?? ''),
            'venue_zip' => sanitize_text_field($raw_settings['venue_zip'] ?? ''),
            'venue_country' => sanitize_text_field($raw_settings['venue_country'] ?? ''),
            'venue_phone' => sanitize_text_field($raw_settings['venue_phone'] ?? ''),
            'venue_website' => esc_url_raw($raw_settings['venue_website'] ?? ''),
            'venue_coordinates' => sanitize_text_field($raw_settings['venue_coordinates'] ?? ''),
            'venue_capacity' => !empty($raw_settings['venue_capacity']) ? absint($raw_settings['venue_capacity']) : ''
        ];
    }
    
    /**
     * Universal Web Scraper doesn't require authentication
     *
     * @param array $current_config Current configuration values
     * @return bool Always false - no authentication required
     */
    public static function requires_authentication(array $current_config = []): bool {
        return false;
    }
    
    /**
     * Get default values for all settings
     *
     * @return array Default values
     */
    public static function get_defaults(): array {
        return [
            'source_url' => '',
            'venue' => '',
            'venue_address' => '',
            'venue_city' => '',
            'venue_state' => '',
            'venue_zip' => '',
            'venue_country' => '',
            'venue_phone' => '',
            'venue_website' => '',
            'venue_coordinates' => '',
            'venue_capacity' => ''
        ];
    }
}