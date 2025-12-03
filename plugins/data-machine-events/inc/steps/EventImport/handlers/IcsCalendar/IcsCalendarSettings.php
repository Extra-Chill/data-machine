<?php
/**
 * ICS Calendar Feed Event Import Handler Settings
 *
 * Defines settings fields and sanitization for ICS calendar feed import handler.
 * No authentication required - works with any public ICS/iCal feed URL.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\IcsCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\IcsCalendar;

use DataMachineEvents\Steps\EventImport\Handlers\VenueFieldsTrait;

if (!defined('ABSPATH')) {
    exit;
}

class IcsCalendarSettings {

    use VenueFieldsTrait;

    public function __construct() {
    }

    /**
     * Get settings fields for ICS calendar feed import handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        $handler_fields = [
            'feed_url' => [
                'type' => 'text',
                'label' => __('Feed URL', 'datamachine-events'),
                'description' => __('ICS/iCal feed URL. Supports webcal:// and https:// protocols.', 'datamachine-events'),
                'placeholder' => __('https://tockify.com/api/feeds/ics/calendar-name', 'datamachine-events'),
                'required' => true
            ],
        ];

        $venue_fields = self::get_venue_fields();

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

        return array_merge($handler_fields, $venue_fields, $filter_fields);
    }

    /**
     * Sanitize ICS calendar handler settings
     *
     * @param array $raw_settings Raw settings input
     * @return array Sanitized settings
     */
    public static function sanitize(array $raw_settings): array {
        $feed_url = trim($raw_settings['feed_url'] ?? '');

        if (str_starts_with($feed_url, 'webcal://')) {
            $feed_url = 'https://' . substr($feed_url, 9);
        }

        $handler_settings = [
            'feed_url' => esc_url_raw($feed_url),
            'search' => sanitize_text_field($raw_settings['search'] ?? ''),
            'exclude_keywords' => sanitize_text_field($raw_settings['exclude_keywords'] ?? '')
        ];

        $venue_settings = self::sanitize_venue_fields($raw_settings);

        $settings = array_merge($handler_settings, $venue_settings);

        return self::save_venue_on_settings_save($settings);
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
        $handler_defaults = [
            'feed_url' => '',
            'search' => '',
            'exclude_keywords' => ''
        ];

        $venue_defaults = self::get_venue_field_defaults();

        return array_merge($handler_defaults, $venue_defaults);
    }
}
