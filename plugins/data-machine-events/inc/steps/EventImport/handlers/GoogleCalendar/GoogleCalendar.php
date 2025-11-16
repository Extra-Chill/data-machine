<?php
/**
 * Google Calendar .ics integration with single-item processing
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar;

use ICal\ICal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-item processing with Google Calendar .ics integration
 */
class GoogleCalendar {

    /**
     * Data Machine flat parameter execution for single-item processing
     * @param array $parameters Flat parameter structure from Data Machine
     * @return array Unchanged data packet or data packet with new event
     */
    public function execute(array $payload): array {
        $job_id = $payload['job_id'] ?? 0;
        $flow_step_id = $payload['flow_step_id'] ?? '';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $engine_data = $payload['engine_data'] ?? apply_filters('datamachine_engine_data', [], $job_id);
        $flow_config = $engine_data['flow_config'] ?? [];
        $flow_step_config = $payload['flow_step_config'] ?? ($flow_config[$flow_step_id] ?? []);

        $handler_config = $flow_step_config['handler_config'] ?? [];
        $pipeline_id = $flow_step_config['pipeline_id'] ?? null;

        $this->log_info('Google Calendar Handler: Starting event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'flow_step_id' => $flow_step_id
        ]);

        // Get calendar URL from handler configuration
        $calendar_url = $handler_config['calendar_url'] ?? '';
        if (empty($calendar_url)) {
            $this->log_error('Google Calendar URL not configured');
            return $data;
        }

        // Validate URL format
        if (!filter_var($calendar_url, FILTER_VALIDATE_URL)) {
            $this->log_error('Invalid Google Calendar URL format', ['url' => $calendar_url]);
            return $data;
        }

        // Fetch and parse .ics feed
        $events = $this->fetch_calendar_events($calendar_url, $handler_config);
        if (empty($events)) {
            $this->log_info('No events found in Google Calendar feed');
            return $data;
        }

        // Process single event (Data Machine single-item model)
        foreach ($events as $ical_event) {
            // Extract and standardize event data
            $standardized_event = $this->map_ical_event($ical_event, $handler_config);

            if (empty($standardized_event['title'])) {
                continue;
            }

            // Create unique identifier for processed items tracking
            $event_identifier = md5($standardized_event['title'] . $standardized_event['startDate'] . $standardized_event['venue']);

            // Check if already processed
            $is_processed = apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'google_calendar', $event_identifier);
            if ($is_processed) {
                continue;
            }

            // Apply future date filter
            if (!empty($standardized_event['startDate']) && strtotime($standardized_event['startDate']) < strtotime('today')) {
                continue;
            }

            // Found eligible event - mark as processed and add to data packet array
            if ($flow_step_id && $job_id) {
                do_action('datamachine_mark_item_processed', $flow_step_id, 'google_calendar', $event_identifier, $job_id);
            }

            $this->log_info('Google Calendar Handler: Found eligible event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate'],
                'venue' => $standardized_event['venue'],
                'pipeline_id' => $pipeline_id
            ]);

            // Extract venue metadata separately for nested structure
            $venue_metadata = [
                'venueAddress' => $standardized_event['venueAddress'] ?? '',
                'venueCity' => $standardized_event['venueCity'] ?? '',
                'venueState' => $standardized_event['venueState'] ?? '',
                'venueZip' => $standardized_event['venueZip'] ?? '',
                'venueCountry' => $standardized_event['venueCountry'] ?? '',
                'venuePhone' => $standardized_event['venuePhone'] ?? '',
                'venueWebsite' => $standardized_event['venueWebsite'] ?? '',
                'venueCoordinates' => $standardized_event['venueCoordinates'] ?? ''
            ];

            // Remove venue metadata from event data (move to separate structure)
            unset($standardized_event['venueAddress'], $standardized_event['venueCity'],
                  $standardized_event['venueState'], $standardized_event['venueZip'],
                  $standardized_event['venueCountry'], $standardized_event['venuePhone'],
                  $standardized_event['venueWebsite'], $standardized_event['venueCoordinates']);

            // Create data packet entry following Data Machine standard
            $event_entry = [
                'type' => 'event_import',
                'handler' => 'google_calendar',
                'content' => [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'google_calendar'
                    ], JSON_PRETTY_PRINT)
                ],
                'metadata' => [
                    'source_type' => 'google_calendar',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_step_config['flow_id'] ?? null,
                    'original_title' => $standardized_event['title'] ?? '',
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time()
                ],
                'attachments' => [],
                'timestamp' => time()
            ];

            // Add to front of data packet array (newest first)
            array_unshift($data, $event_entry);
            return $data;
        }

        // No eligible events found
        return $data;
    }

    /**
     * Fetch and parse calendar events from .ics URL
     *
     * @param string $calendar_url Google Calendar .ics URL
     * @param array $config Handler configuration
     * @return array Array of parsed iCal events
     */
    private function fetch_calendar_events(string $calendar_url, array $config): array {
        try {
            $ical = new ICal($calendar_url, [
                'defaultSpan' => 2, // Default span in years
                'defaultTimeZone' => 'UTC',
                'defaultWeekStart' => 'MO',
                'skipRecurrence' => false,
                'useTimeZoneWithRRules' => false,
            ]);

            $events = $ical->events();

            // Apply event limit if configured
            $event_limit = intval($config['event_limit'] ?? 50);
            if ($event_limit > 0 && count($events) > $event_limit) {
                $events = array_slice($events, 0, $event_limit);
            }

            $this->log_info('Google Calendar: Successfully fetched events', [
                'total_events' => count($events),
                'calendar_url' => $calendar_url
            ]);

            return $events;

        } catch (\Exception $e) {
            $this->log_error('Google Calendar: Failed to fetch or parse calendar', [
                'calendar_url' => $calendar_url,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Map iCal event to standardized event format
     *
     * @param array $ical_event iCal event data
     * @param array $config Handler configuration
     * @return array Standardized event data
     */
    private function map_ical_event(array $ical_event, array $config): array {
        $standardized_event = [
            'title' => sanitize_text_field($ical_event['SUMMARY'] ?? ''),
            'description' => sanitize_textarea_field($ical_event['DESCRIPTION'] ?? ''),
            'startDate' => '',
            'endDate' => '',
            'startTime' => '',
            'endTime' => '',
            'venue' => '',
            'address' => '',
            'ticketUrl' => esc_url_raw($ical_event['URL'] ?? ''),
            'image' => '',
            'price' => '',
            'performer' => '',
            'organizer' => sanitize_text_field($ical_event['ORGANIZER'] ?? ''),
            'source_url' => esc_url_raw($ical_event['URL'] ?? '')
        ];

        // Parse start date/time
        if (!empty($ical_event['DTSTART'])) {
            $start_datetime = $ical_event['DTSTART'];
            if ($start_datetime instanceof \DateTime) {
                $standardized_event['startDate'] = $start_datetime->format('Y-m-d');
                $standardized_event['startTime'] = $start_datetime->format('H:i');
            } elseif (is_string($start_datetime)) {
                $parsed_start = strtotime($start_datetime);
                if ($parsed_start) {
                    $standardized_event['startDate'] = date('Y-m-d', $parsed_start);
                    $standardized_event['startTime'] = date('H:i', $parsed_start);
                }
            }
        }

        // Parse end date/time
        if (!empty($ical_event['DTEND'])) {
            $end_datetime = $ical_event['DTEND'];
            if ($end_datetime instanceof \DateTime) {
                $standardized_event['endDate'] = $end_datetime->format('Y-m-d');
                $standardized_event['endTime'] = $end_datetime->format('H:i');
            } elseif (is_string($end_datetime)) {
                $parsed_end = strtotime($end_datetime);
                if ($parsed_end) {
                    $standardized_event['endDate'] = date('Y-m-d', $parsed_end);
                    $standardized_event['endTime'] = date('H:i', $parsed_end);
                }
            }
        }

        // Parse location/venue data
        $location = $ical_event['LOCATION'] ?? '';
        if (!empty($location)) {
            // Try to split location into venue name and address
            $location_parts = explode(',', $location, 2);
            $standardized_event['venue'] = sanitize_text_field(trim($location_parts[0]));
            if (isset($location_parts[1])) {
                $standardized_event['address'] = sanitize_text_field(trim($location_parts[1]));
            } else {
                $standardized_event['address'] = sanitize_text_field($location);
            }
        }

        return $standardized_event;
    }

    /**
     * Log info message
     */
    private function log_info(string $message, array $context = []): void {
        do_action('datamachine_log', 'info', $message, $context);
    }

    /**
     * Log error message
     */
    private function log_error(string $message, array $context = []): void {
        do_action('datamachine_log', 'error', $message, $context);
    }
}