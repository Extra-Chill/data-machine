<?php
/**
 * DoStuff Media API Event Import Handler Settings
 *
 * Defines settings fields and sanitization for DoStuff Media API import handler.
 * No authentication required - works with public JSON feeds.
 *
 * @deprecated 0.9.8 Use Universal Web Scraper handler with DoStuff Media URLs instead
 * @package DataMachineEvents\Steps\EventImport\Handlers\DoStuffMediaApi
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\DoStuffMediaApi;

if (!defined('ABSPATH')) {
    exit;
}

class DoStuffMediaApiSettings {

    public function __construct() {
    }

    /**
     * Get settings fields for DoStuff Media API import handler
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
                    '<strong>This handler is deprecated.</strong> Please use <strong>Universal Web Scraper</strong> with your DoStuff Media JSON feed URL instead. Existing flows using this handler will continue to work.',
                    'datamachine-events'
                ),
            ],
        ];

        $handler_fields = [
            'feed_url' => [
                'type' => 'text',
                'label' => __('Feed URL', 'datamachine-events'),
                'description' => __('DoStuff Media JSON feed URL (e.g., http://events.waterloorecords.com/events.json)', 'datamachine-events'),
                'placeholder' => __('http://events.venue-name.com/events.json', 'datamachine-events'),
                'required' => true
            ],
        ];

        $filter_fields = [
            'search' => [
                'type' => 'text',
                'label' => __('Include Keywords', 'datamachine-events'),
                'description' => __('Only import events containing any of these keywords (comma-separated). Leave empty to import all.', 'datamachine-events'),
                'placeholder' => __('concert, live music, band', 'datamachine-events'),
                'required' => false
            ],
            'exclude_keywords' => [
                'type' => 'text',
                'label' => __('Exclude Keywords', 'datamachine-events'),
                'description' => __('Skip events containing any of these keywords (comma-separated).', 'datamachine-events'),
                'placeholder' => __('trivia, karaoke, brunch, bingo', 'datamachine-events'),
                'required' => false
            ]
        ];

        return array_merge($deprecation_notice, $handler_fields, $filter_fields);
    }

    /**
     * Sanitize DoStuff Media API handler settings
     *
     * @param array $raw_settings Raw settings input
     * @return array Sanitized settings
     */
    public static function sanitize(array $raw_settings): array {
        $feed_url = trim($raw_settings['feed_url'] ?? '');

        return [
            'feed_url' => esc_url_raw($feed_url),
            'search' => sanitize_text_field($raw_settings['search'] ?? ''),
            'exclude_keywords' => sanitize_text_field($raw_settings['exclude_keywords'] ?? '')
        ];
    }

    /**
     * Determine if authentication is required
     *
     * @param array $current_config Current configuration values
     * @return bool True if authentication is required
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
            'feed_url' => '',
            'search' => '',
            'exclude_keywords' => ''
        ];
    }
}
