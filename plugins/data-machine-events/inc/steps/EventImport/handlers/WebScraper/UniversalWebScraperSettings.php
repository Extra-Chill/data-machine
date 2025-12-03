<?php
/**
 * Universal Web Scraper Settings
 *
 * Configuration for AI-powered web scraping with venue management.
 * Uses VenueFieldsTrait for standardized venue field definitions and taxonomy integration.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachineEvents\Steps\EventImport\Handlers\VenueFieldsTrait;

if (!defined('ABSPATH')) {
    exit;
}

class UniversalWebScraperSettings {

    use VenueFieldsTrait;

    /**
     * Get settings fields for Universal Web Scraper handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        $handler_fields = [
            'source_url' => [
                'type' => 'url',
                'label' => __('Website URL', 'datamachine-events'),
                'description' => __('URL of the webpage containing events. The AI will analyze the page and extract event information automatically.', 'datamachine-events'),
                'placeholder' => 'https://venue.com/events',
                'required' => true,
            ],
        ];

        $venue_fields = self::get_venue_fields();

        return array_merge($handler_fields, $venue_fields);
    }

    /**
     * Sanitize Universal Web Scraper handler settings
     *
     * @param array $raw_settings Raw settings input
     * @return array Sanitized settings
     */
    public static function sanitize(array $raw_settings): array {
        $handler_settings = [
            'source_url' => esc_url_raw($raw_settings['source_url'] ?? ''),
        ];

        $venue_settings = self::sanitize_venue_fields($raw_settings);

        $settings = array_merge($handler_settings, $venue_settings);

        return self::save_venue_on_settings_save($settings);
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
        $handler_defaults = [
            'source_url' => '',
        ];

        $venue_defaults = self::get_venue_field_defaults();

        return array_merge($handler_defaults, $venue_defaults);
    }
}
