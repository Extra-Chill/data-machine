<?php
/**
 * GoDaddy Calendar JSON Handler Settings
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\GoDaddyCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\GoDaddyCalendar;

use DataMachineEvents\Steps\EventImport\Handlers\VenueFieldsTrait;

if (!defined('ABSPATH')) {
    exit;
}

class GoDaddyCalendarSettings {

    use VenueFieldsTrait;

    public static function get_fields(array $current_config = []): array {
        $handler_fields = [
            'events_url' => [
                'type' => 'text',
                'label' => __('Events JSON URL', 'datamachine-events'),
                'description' => __(
                    'Paste the full GoDaddy calendar JSON URL. Find it in DevTools: open the calendar page → DevTools → Network → reload → filter for "calendar" or "secureserver" → click the request to /v1/events/... → copy Request URL.',
                    'datamachine-events'
                ),
                'placeholder' => __('https://calendar.apps.secureserver.net/v1/events/{websiteId}/{pageId}/{widgetId}', 'datamachine-events'),
                'required' => true,
            ],
        ];

        $venue_fields = self::get_venue_fields();

        $filter_fields = [
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

        return array_merge($handler_fields, $venue_fields, $filter_fields);
    }

    public static function sanitize(array $raw_settings): array {
        $handler_settings = [
            'events_url' => esc_url_raw(trim($raw_settings['events_url'] ?? '')),
            'search' => sanitize_text_field($raw_settings['search'] ?? ''),
            'exclude_keywords' => sanitize_text_field($raw_settings['exclude_keywords'] ?? ''),
        ];

        $venue_settings = self::sanitize_venue_fields($raw_settings);
        $settings = array_merge($handler_settings, $venue_settings);

        return self::save_venue_on_settings_save($settings);
    }

    public static function requires_authentication(array $current_config = []): bool {
        return false;
    }

    public static function get_defaults(): array {
        $handler_defaults = [
            'events_url' => '',
            'search' => '',
            'exclude_keywords' => '',
        ];

        $venue_defaults = self::get_venue_field_defaults();

        return array_merge($handler_defaults, $venue_defaults);
    }
}
