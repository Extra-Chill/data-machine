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
 * including calendar URL, event filtering, and import limits.
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
                'description' => __('Public Google Calendar .ics feed URL (e.g., https://calendar.google.com/calendar/ical/[calendar-id]/public/basic.ics)', 'datamachine-events'),
                'placeholder' => 'https://calendar.google.com/calendar/ical/example@gmail.com/public/basic.ics',
                'value' => $current_config['calendar_url'] ?? '',
                'required' => true,
                'validation' => [
                    'required' => true,
                    'url' => true
                ]
            ],
            'future_events_only' => [
                'type' => 'checkbox',
                'label' => __('Future Events Only', 'datamachine-events'),
                'description' => __('Only import events that start in the future. Past events will be skipped.', 'datamachine-events'),
                'value' => $current_config['future_events_only'] ?? true,
                'default' => true
            ],
            'event_limit' => [
                'type' => 'number',
                'label' => __('Event Limit', 'datamachine-events'),
                'description' => __('Maximum number of events to process per execution. Leave empty for no limit.', 'datamachine-events'),
                'placeholder' => '50',
                'value' => $current_config['event_limit'] ?? 50,
                'min' => 1,
                'max' => 200,
                'step' => 1
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

        // Sanitize future events only checkbox
        $sanitized['future_events_only'] = !empty($config['future_events_only']);

        // Sanitize event limit
        $event_limit = intval($config['event_limit'] ?? 50);
        $sanitized['event_limit'] = max(1, min(200, $event_limit));

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

        // Validate calendar URL
        if (empty($config['calendar_url'])) {
            $errors['calendar_url'] = __('Calendar URL is required.', 'datamachine-events');
        } elseif (!filter_var($config['calendar_url'], FILTER_VALIDATE_URL)) {
            $errors['calendar_url'] = __('Please enter a valid URL.', 'datamachine-events');
        } elseif (!str_ends_with($config['calendar_url'], '.ics') && !str_contains($config['calendar_url'], 'calendar.google.com')) {
            $errors['calendar_url'] = __('URL must be a valid .ics calendar feed or Google Calendar URL.', 'datamachine-events');
        }

        // Validate event limit
        $event_limit = intval($config['event_limit'] ?? 0);
        if ($event_limit < 1 || $event_limit > 200) {
            $errors['event_limit'] = __('Event limit must be between 1 and 200.', 'datamachine-events');
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
            'description' => __('Import events from public Google Calendar .ics feeds', 'datamachine-events'),
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