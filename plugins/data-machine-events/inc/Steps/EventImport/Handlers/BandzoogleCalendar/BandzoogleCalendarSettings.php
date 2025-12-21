<?php
/**
 * Bandzoogle Calendar Event Import Handler Settings
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\BandzoogleCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\BandzoogleCalendar;

use DataMachineEvents\Steps\EventImport\Handlers\VenueFieldsTrait;

if (!defined('ABSPATH')) {
    exit;
}

class BandzoogleCalendarSettings {

    use VenueFieldsTrait;

    public static function get_fields(array $current_config = []): array {
        $handler_fields = [
            'calendar_url' => [
                'type' => 'text',
                'label' => __('Calendar URL', 'datamachine-events'),
                'description' => __('Bandzoogle calendar page URL (example: https://elephantroom.com/calendar).', 'datamachine-events'),
                'placeholder' => __('https://elephantroom.com/calendar', 'datamachine-events'),
                'required' => true,
            ],
        ];

        $filter_fields = [
            'search' => [
                'type' => 'text',
                'label' => __('Include Keywords', 'datamachine-events'),
                'description' => __('Only import events containing any of these keywords (comma-separated). Leave empty to import all.', 'datamachine-events'),
                'placeholder' => __('concert, live music, jazz', 'datamachine-events'),
                'required' => false,
            ],
            'exclude_keywords' => [
                'type' => 'text',
                'label' => __('Exclude Keywords', 'datamachine-events'),
                'description' => __('Skip events containing any of these keywords (comma-separated).', 'datamachine-events'),
                'placeholder' => __('cornhole, trivia, karaoke', 'datamachine-events'),
                'required' => false,
            ],
        ];

        $venue_fields = self::get_venue_fields();

        return array_merge($handler_fields, $venue_fields, $filter_fields);
    }

    public static function sanitize(array $raw_settings): array {
        $handler_settings = [
            'calendar_url' => sanitize_text_field($raw_settings['calendar_url'] ?? ''),
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
            'calendar_url' => '',
            'search' => '',
            'exclude_keywords' => '',
        ];

        $venue_defaults = self::get_venue_field_defaults();

        return array_merge($handler_defaults, $venue_defaults);
    }
}
