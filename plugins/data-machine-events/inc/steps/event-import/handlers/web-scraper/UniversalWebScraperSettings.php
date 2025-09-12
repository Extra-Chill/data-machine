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
            'venueAddress' => [
                'type' => 'text',
                'label' => __('Venue Address', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue address from page content.', 'dm-events'),
                'placeholder' => '970 Morrison Drive',
                'required' => false,
            ],
            'venueCity' => [
                'type' => 'text',
                'label' => __('Venue City', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue city from page content.', 'dm-events'),
                'placeholder' => 'Charleston',
                'required' => false,
            ],
            'venueState' => [
                'type' => 'text',
                'label' => __('Venue State', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue state from page content.', 'dm-events'),
                'placeholder' => 'SC',
                'required' => false,
            ],
            'venueZip' => [
                'type' => 'text',
                'label' => __('Venue Zip Code', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue zip code from page content.', 'dm-events'),
                'placeholder' => '29403',
                'required' => false,
            ],
            'venueCountry' => [
                'type' => 'text',
                'label' => __('Venue Country', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue country from page content.', 'dm-events'),
                'placeholder' => 'US',
                'required' => false,
            ],
            'venuePhone' => [
                'type' => 'text',
                'label' => __('Venue Phone', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue phone from page content.', 'dm-events'),
                'placeholder' => '(843) 817-6925',
                'required' => false,
            ],
            'venueWebsite' => [
                'type' => 'url',
                'label' => __('Venue Website', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue website from page content.', 'dm-events'),
                'placeholder' => 'https://www.theroyalamerican.com',
                'required' => false,
            ],
            'venueCoordinates' => [
                'type' => 'text',
                'label' => __('Venue Coordinates', 'dm-events'),
                'description' => __('Leave blank to let AI extract venue coordinates from page content. Format: latitude,longitude', 'dm-events'),
                'placeholder' => '32.7900,-79.9400',
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
            'venueAddress' => sanitize_text_field($raw_settings['venueAddress'] ?? ''),
            'venueCity' => sanitize_text_field($raw_settings['venueCity'] ?? ''),
            'venueState' => sanitize_text_field($raw_settings['venueState'] ?? ''),
            'venueZip' => sanitize_text_field($raw_settings['venueZip'] ?? ''),
            'venueCountry' => sanitize_text_field($raw_settings['venueCountry'] ?? ''),
            'venuePhone' => sanitize_text_field($raw_settings['venuePhone'] ?? ''),
            'venueWebsite' => esc_url_raw($raw_settings['venueWebsite'] ?? ''),
            'venueCoordinates' => sanitize_text_field($raw_settings['venueCoordinates'] ?? '')
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
            'venueAddress' => '',
            'venueCity' => '',
            'venueState' => '',
            'venueZip' => '',
            'venueCountry' => '',
            'venuePhone' => '',
            'venueWebsite' => '',
            'venueCoordinates' => ''
        ];
    }
}