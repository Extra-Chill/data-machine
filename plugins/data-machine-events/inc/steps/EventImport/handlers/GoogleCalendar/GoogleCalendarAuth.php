<?php
/**
 * Google Calendar Event Import Authentication Provider
 *
 * Minimal authentication provider for Google Calendar public .ics feeds.
 * Since public calendars don't require authentication, this provider
 * mainly validates URLs and provides connection testing.
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
 * GoogleCalendarAuth class
 *
 * Provides minimal authentication interface for Google Calendar integration.
 * Focuses on URL validation and connection testing rather than API credentials.
 *
 * @since 1.0.0
 */
class GoogleCalendarAuth {

    /**
     * Constructor
     * Pure filter-based architecture - no dependencies.
     */
    public function __construct() {
        // No constructor dependencies - all services accessed via filters
    }

    /**
     * Get authentication fields for Google Calendar
     *
     * Since public .ics feeds don't require authentication,
     * we provide connection testing and URL validation.
     *
     * @param array $current_config Current authentication configuration
     * @return array Authentication fields configuration
     */
    public function get_fields(array $current_config = []): array {
        return [
            'test_url' => [
                'type' => 'text',
                'label' => __('Test Calendar URL', 'datamachine-events'),
                'description' => __('Enter a public Google Calendar .ics URL to test the connection.', 'datamachine-events'),
                'placeholder' => 'https://calendar.google.com/calendar/ical/example@gmail.com/public/basic.ics',
                'value' => $current_config['test_url'] ?? '',
                'validation' => [
                    'url' => true
                ]
            ]
        ];
    }

    /**
     * Test authentication/connection
     *
     * For Google Calendar, this tests if the .ics URL is accessible
     * and returns valid calendar data.
     *
     * @param array $config Authentication configuration
     * @return array Test result with 'success' boolean and 'message' string
     */
    public function test_connection(array $config): array {
        $test_url = trim($config['test_url'] ?? '');

        if (empty($test_url)) {
            return [
                'success' => false,
                'message' => __('Please enter a calendar URL to test.', 'datamachine-events')
            ];
        }

        // Validate URL format
        if (!filter_var($test_url, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'message' => __('Invalid URL format.', 'datamachine-events')
            ];
        }

        // Test if URL is accessible
        $response = wp_remote_get($test_url, [
            'timeout' => 30,
            'user-agent' => 'Data Machine Events/1.0 (WordPress)',
            'headers' => [
                'Accept' => 'text/calendar,text/plain,*/*'
            ]
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Connection failed: %s', 'datamachine-events'),
                    $response->get_error_message()
                )
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('HTTP error %d: Unable to access calendar.', 'datamachine-events'),
                    $status_code
                )
            ];
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return [
                'success' => false,
                'message' => __('Calendar URL returned empty content.', 'datamachine-events')
            ];
        }

        // Basic validation of iCal format
        if (!str_contains($body, 'BEGIN:VCALENDAR')) {
            return [
                'success' => false,
                'message' => __('URL does not contain valid calendar data.', 'datamachine-events')
            ];
        }

        // Try to parse with the iCal library
        try {
            if (class_exists('ICal\ICal')) {
                $ical = new \ICal\ICal();
                $ical->initString($body);
                $events = $ical->events();
                $event_count = count($events);

                return [
                    'success' => true,
                    'message' => sprintf(
                        __('Connection successful! Found %d events in the calendar.', 'datamachine-events'),
                        $event_count
                    ),
                    'data' => [
                        'event_count' => $event_count,
                        'calendar_name' => $ical->calendarName() ?? __('Unknown Calendar', 'datamachine-events')
                    ]
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Calendar parsing failed: %s', 'datamachine-events'),
                    $e->getMessage()
                )
            ];
        }

        // Fallback success if iCal library not available
        return [
            'success' => true,
            'message' => __('Calendar URL is accessible and contains calendar data.', 'datamachine-events')
        ];
    }

    /**
     * Sanitize authentication configuration
     *
     * @param array $config Raw configuration from form submission
     * @return array Sanitized configuration
     */
    public function sanitize_config(array $config): array {
        $sanitized = [];

        // Sanitize test URL
        $test_url = trim($config['test_url'] ?? '');
        if (!empty($test_url)) {
            $sanitized['test_url'] = esc_url_raw($test_url);
        }

        return $sanitized;
    }

    /**
     * Get authentication provider information
     *
     * @return array Provider metadata for UI display
     */
    public function get_provider_info(): array {
        return [
            'name' => __('Google Calendar (Public)', 'datamachine-events'),
            'description' => __('Access public Google Calendar feeds via .ics URLs', 'datamachine-events'),
            'icon' => 'calendar',
            'color' => '#4285f4',
            'auth_type' => 'url_based',
            'requires_credentials' => false
        ];
    }
}