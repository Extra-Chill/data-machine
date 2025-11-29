<?php
/**
 * ICS Calendar Feed Event Import Handler Settings
 *
 * Defines settings fields and sanitization for ICS calendar feed import handler.
 * No authentication required - works with any public ICS/iCal feed URL.
 * Includes address-autocomplete for venue configuration with batch field population.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\IcsCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\IcsCalendar;

if (!defined('ABSPATH')) {
    exit;
}

class IcsCalendarSettings {

    public function __construct() {
    }

    /**
     * Get settings fields for ICS calendar feed import handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        return [
            'feed_url' => [
                'type' => 'text',
                'label' => __('Feed URL', 'datamachine-events'),
                'description' => __('ICS/iCal feed URL. Supports webcal:// and https:// protocols.', 'datamachine-events'),
                'placeholder' => __('https://tockify.com/api/feeds/ics/calendar-name', 'datamachine-events'),
                'required' => true
            ],
            'venue_name' => [
                'type' => 'text',
                'label' => __('Venue Name', 'datamachine-events'),
                'description' => __('Override venue name for all events from this feed.', 'datamachine-events'),
                'placeholder' => __('Tin Roof Charleston', 'datamachine-events'),
                'required' => false
            ],
            'venue_address' => [
                'type' => 'address-autocomplete',
                'label' => __('Venue Address', 'datamachine-events'),
                'description' => __('Start typing to search. Auto-fills city, state, zip, country.', 'datamachine-events'),
                'placeholder' => __('Search address...', 'datamachine-events'),
                'required' => false
            ],
            'venue_city' => [
                'type' => 'text',
                'label' => __('City', 'datamachine-events'),
                'description' => __('Auto-filled from address selection.', 'datamachine-events'),
                'placeholder' => __('Charleston', 'datamachine-events'),
                'required' => false
            ],
            'venue_state' => [
                'type' => 'text',
                'label' => __('State', 'datamachine-events'),
                'description' => __('Auto-filled from address selection.', 'datamachine-events'),
                'placeholder' => __('South Carolina', 'datamachine-events'),
                'required' => false
            ],
            'venue_zip' => [
                'type' => 'text',
                'label' => __('ZIP Code', 'datamachine-events'),
                'description' => __('Auto-filled from address selection.', 'datamachine-events'),
                'placeholder' => __('29407', 'datamachine-events'),
                'required' => false
            ],
            'venue_country' => [
                'type' => 'text',
                'label' => __('Country', 'datamachine-events'),
                'description' => __('Auto-filled from address selection. Two-letter country code.', 'datamachine-events'),
                'placeholder' => __('US', 'datamachine-events'),
                'required' => false
            ],
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

        return [
            'feed_url' => esc_url_raw($feed_url),
            'venue_name' => sanitize_text_field($raw_settings['venue_name'] ?? ''),
            'venue_address' => sanitize_text_field($raw_settings['venue_address'] ?? ''),
            'venue_city' => sanitize_text_field($raw_settings['venue_city'] ?? ''),
            'venue_state' => sanitize_text_field($raw_settings['venue_state'] ?? ''),
            'venue_zip' => sanitize_text_field($raw_settings['venue_zip'] ?? ''),
            'venue_country' => sanitize_text_field($raw_settings['venue_country'] ?? ''),
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
            'venue_name' => '',
            'venue_address' => '',
            'venue_city' => '',
            'venue_state' => '',
            'venue_zip' => '',
            'venue_country' => '',
            'search' => '',
            'exclude_keywords' => ''
        ];
    }
}
