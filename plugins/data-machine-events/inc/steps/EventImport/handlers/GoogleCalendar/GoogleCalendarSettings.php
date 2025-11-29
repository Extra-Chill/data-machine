<?php
/**
 * Google Calendar Event Import Handler Settings
 *
 * Defines settings fields and sanitization for Google Calendar event import handler.
 * Part of the modular handler architecture for Data Machine integration.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GoogleCalendarSettings class
 *
 * Provides configuration fields for Google Calendar .ics integration
 * including calendar URL and event filtering.
 *
 * @since 1.0.0
 */
class GoogleCalendarSettings {

    /**
     * Constructor
     * Pure filter-based architecture - no dependencies.
     */
    public function __construct() {
        // No constructor dependencies - all services accessed via filters
    }

    /**
     * Get settings fields for Google Calendar event import handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Settings fields configuration
     */
    public function get_fields(array $current_config = []): array {
        return [
            'calendar_url' => [
                'type' => 'text',
                'label' => __('Calendar URL', 'datamachine-events'),
                'description' => __('Public Google Calendar .ics feed URL (e.g., https://calendar.google.com/calendar/ical/[calendar-id]/public/basic.ics) or leave empty and provide a Calendar ID below.', 'datamachine-events'),
                'placeholder' => 'https://calendar.google.com/calendar/ical/example@gmail.com/public/basic.ics',
                'value' => $current_config['calendar_url'] ?? '',
                'required' => false,
                'validation' => [
                    'required' => false,
                    'url' => true
                ]
            ],
            'calendar_id' => [
                'type' => 'text',
                'label' => __('Calendar ID', 'datamachine-events'),
                'description' => __('Public Google Calendar ID (e.g., example@gmail.com or en.uk#holiday@group.v.calendar.google.com). This will be converted to a public .ics URL if a URL is not provided.', 'datamachine-events'),
                'placeholder' => 'example@gmail.com',
                'value' => $current_config['calendar_id'] ?? '',
                'required' => false,
                'validation' => [
                    'required' => false,
                    'string' => true
                ]
            ],
            'future_events_only' => [
                'type' => 'checkbox',
                'label' => __('Future Events Only', 'datamachine-events'),
                'description' => __('Only import events that start in the future. Past events will be skipped.', 'datamachine-events'),
                'value' => $current_config['future_events_only'] ?? true,
                'default' => true
            ]
        ];
    }

    /**
     * Sanitize and validate handler configuration
     *
     * @param array $config Raw configuration from form submission
     * @return array Sanitized and validated configuration
     */
    public function sanitize_config(array $config): array {
        $sanitized = [];

        // Sanitize calendar URL
        $calendar_url = trim($config['calendar_url'] ?? '');
        if (!empty($calendar_url)) {
            $sanitized['calendar_url'] = esc_url_raw($calendar_url);

            // Validate URL format
            if (!filter_var($sanitized['calendar_url'], FILTER_VALIDATE_URL)) {
                $sanitized['calendar_url'] = '';
            }

            // Validate .ics extension
            if (!empty($sanitized['calendar_url']) && !str_ends_with($sanitized['calendar_url'], '.ics')) {
                // Allow Google Calendar URLs that might not end in .ics
                if (!str_contains($sanitized['calendar_url'], 'calendar.google.com')) {
                    $sanitized['calendar_url'] = '';
                }
            }
        }

        // Sanitize calendar ID
        $calendar_id = trim($config['calendar_id'] ?? '');
        if (!empty($calendar_id)) {
            $sanitized['calendar_id'] = sanitize_text_field($calendar_id);

            // If there's no explicit calendar_url, try to derive one from the calendar ID
            if (empty($sanitized['calendar_url'])) {
                if (GoogleCalendarUtils::is_calendar_url_like($sanitized['calendar_id']) && preg_match('/^https?:\/\//i', $sanitized['calendar_id'])) {
                    $sanitized['calendar_url'] = esc_url_raw($sanitized['calendar_id']);
                } else {
                    $sanitized['calendar_url'] = GoogleCalendarUtils::generate_ics_url_from_calendar_id($sanitized['calendar_id']);
                }
            }
        }

        // Sanitize future events only checkbox
        $sanitized['future_events_only'] = !empty($config['future_events_only']);

        return $sanitized;
    }

    /**
     * Validate configuration for completeness
     *
     * @param array $config Configuration to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validate_config(array $config): array {
        $errors = [];

        // Validate calendar URL or calendar ID (at least one required)
        $calendar_url = trim($config['calendar_url'] ?? '');
        $calendar_id = trim($config['calendar_id'] ?? '');

        if (empty($calendar_url) && empty($calendar_id)) {
            $errors['calendar_url'] = __('Calendar URL or Calendar ID is required.', 'datamachine-events');
        } else {
            if (!empty($calendar_url)) {
                if (!filter_var($calendar_url, FILTER_VALIDATE_URL)) {
                    $errors['calendar_url'] = __('Please enter a valid URL.', 'datamachine-events');
                } elseif (!str_ends_with($calendar_url, '.ics') && !str_contains($calendar_url, 'calendar.google.com')) {
                    $errors['calendar_url'] = __('URL must be a valid .ics calendar feed or Google Calendar URL.', 'datamachine-events');
                }
            }

            if (!empty($calendar_id)) {
                // If calendar_id is a URL, encourage using the URL field instead
                if (filter_var($calendar_id, FILTER_VALIDATE_URL)) {
                    $errors['calendar_id'] = __('Calendar ID appears to be a URL; provide it in the Calendar URL field instead.', 'datamachine-events');
                } elseif (preg_match('/\s/', $calendar_id)) {
                    $errors['calendar_id'] = __('Calendar ID is invalid; it should not contain spaces.', 'datamachine-events');
                } elseif (strlen($calendar_id) > 255) {
                    $errors['calendar_id'] = __('Calendar ID is too long.', 'datamachine-events');
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get handler display information
     *
     * @return array Handler metadata for UI display
     */
    public function get_handler_info(): array {
        return [
            'name' => __('Google Calendar', 'datamachine-events'),
            'description' => __('Import events from public Google Calendar .ics feeds – accepts either a public .ics URL or a calendar ID, which will be converted to an .ics feed URL.', 'datamachine-events'),
            'icon' => 'calendar-alt',
            'color' => '#4285f4',
            'supports' => [
                'recurring_events' => true,
                'venue_data' => true,
                'timezone_handling' => true,
                'future_filtering' => true
            ]
        ];
    }
}