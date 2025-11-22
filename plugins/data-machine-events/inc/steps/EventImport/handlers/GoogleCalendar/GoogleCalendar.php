<?php
/**
 * Google Calendar .ics integration with single-item processing
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar;

use ICal\ICal;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachine\Core\DataPacket;
use DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar\GoogleCalendarUtils;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-item processing with Google Calendar .ics integration
 */
class GoogleCalendar extends EventImportHandler {

    public function __construct() {
        parent::__construct('google_calendar');
    }

    /**
     * Execute fetch logic
     */
    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $this->log('info', 'Starting event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'flow_step_id' => $flow_step_id
        ]);

        // Resolve calendar from handler configuration (support calendar_url or calendar_id; prefer URL)
        $calendar_url = trim($config['calendar_url'] ?? '');
        $calendar_id = trim($config['calendar_id'] ?? '');

        if (empty($calendar_url) && !empty($calendar_id)) {
            if (GoogleCalendarUtils::is_calendar_url_like($calendar_id) && preg_match('/^https?:\/\//i', $calendar_id)) {
                $calendar_url = $calendar_id;
            } else {
                $calendar_url = GoogleCalendarUtils::generate_ics_url_from_calendar_id($calendar_id);
            }
        }

        if (empty($calendar_url)) {
            $this->log('error', 'Google Calendar URL or ID not configured', ['calendar_id' => $calendar_id]);
            return $this->emptyResponse() ?? [];
        }

        // Validate URL format
        if (!filter_var($calendar_url, FILTER_VALIDATE_URL)) {
            $this->log('error', 'Invalid Google Calendar URL format', ['url' => $calendar_url, 'calendar_id' => $calendar_id]);
            return $this->emptyResponse() ?? [];
        }

        // Fetch and parse .ics feed
        $events = $this->fetch_calendar_events($calendar_url, $config);
        if (empty($events)) {
            $this->log('info', 'No events found in Google Calendar feed');
            return $this->emptyResponse() ?? [];
        }

        // Process single event (Data Machine single-item model)
        foreach ($events as $ical_event) {
            // Extract and standardize event data
            $standardized_event = $this->map_ical_event($ical_event, $config);

            if (empty($standardized_event['title'])) {
                continue;
            }

            // Create unique identifier for processed items tracking
            $event_identifier = \DataMachineEvents\Utilities\EventIdentifierGenerator::generate(
                $standardized_event['title'],
                $standardized_event['startDate'] ?? '',
                $standardized_event['venue'] ?? ''
            );

            // Check if already processed
            if ($this->isItemProcessed($event_identifier, $flow_step_id)) {
                continue;
            }

            // Apply future date filter
            if (!empty($standardized_event['startDate']) && strtotime($standardized_event['startDate']) < strtotime('today')) {
                continue;
            }

            // Found eligible event - mark as processed
            $this->markItemProcessed($event_identifier, $flow_step_id, $job_id);

            $this->log('info', 'Found eligible event', [
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

            EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);

            // Remove venue metadata from event data (move to separate structure)
            unset($standardized_event['venueAddress'], $standardized_event['venueCity'],
                  $standardized_event['venueState'], $standardized_event['venueZip'],
                  $standardized_event['venueCountry'], $standardized_event['venuePhone'],
                  $standardized_event['venueWebsite'], $standardized_event['venueCoordinates']);

            // Create DataPacket
            $dataPacket = new DataPacket(
                [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'google_calendar'
                    ], JSON_PRETTY_PRINT)
                ],
                [
                    'source_type' => 'google_calendar',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_id,
                    'original_title' => $standardized_event['title'] ?? '',
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time()
                ],
                'event_import'
            );

            return $this->successResponse([$dataPacket]);
        }

        // No eligible events found
        return $this->emptyResponse() ?? [];
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

            if (!empty($standardized_event['address'])) {
                $standardized_event['venueAddress'] = $standardized_event['address'];
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