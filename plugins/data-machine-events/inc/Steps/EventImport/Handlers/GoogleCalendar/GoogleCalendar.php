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
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar\GoogleCalendarUtils;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-item processing with Google Calendar .ics integration
 */
class GoogleCalendar extends EventImportHandler {

    use HandlerRegistrationTrait;

    public function __construct() {
        parent::__construct('google_calendar');

        self::registerHandler(
            'google_calendar',
            'event_import',
            self::class,
            __('Google Calendar Events', 'datamachine-events'),
            __('Import events from Google Calendar via public .ics feed', 'datamachine-events'),
            true,
            GoogleCalendarAuth::class,
            GoogleCalendarSettings::class,
            null
        );
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
            return [];
        }

        // Validate URL format
        if (!filter_var($calendar_url, FILTER_VALIDATE_URL)) {
            $this->log('error', 'Invalid Google Calendar URL format', ['url' => $calendar_url, 'calendar_id' => $calendar_id]);
            return [];
        }

        // Fetch and parse .ics feed
        $events = $this->fetch_calendar_events($calendar_url, $config);
        if (empty($events)) {
            $this->log('info', 'No events found in Google Calendar feed');
            return [];
        }

        // Process single event (Data Machine single-item model)
        foreach ($events as $ical_event) {
            // Extract and standardize event data
            $standardized_event = $this->map_ical_event($ical_event, $config);

            if (empty($standardized_event['title'])) {
                continue;
            }

            if ($this->shouldSkipEventTitle($standardized_event['title'])) {
                continue;
            }

            $search_text = $standardized_event['title'] . ' ' . ($standardized_event['description'] ?? '');

            if (!$this->applyKeywordSearch($search_text, $config['search'] ?? '')) {
                continue;
            }

            if ($this->applyExcludeKeywords($search_text, $config['exclude_keywords'] ?? '')) {
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

            if ($this->isPastEvent($standardized_event['startDate'] ?? '')) {
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

            $venue_metadata = $this->extractVenueMetadata($standardized_event);

            EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);

            $this->stripVenueMetadataFromEvent($standardized_event);

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

            return [$dataPacket];
        }

        // No eligible events found
        return [];
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

            $this->log('info', 'Google Calendar: Successfully fetched events', [
                'total_events' => count($events),
                'calendar_url' => $calendar_url
            ]);

            return $events;

        } catch (\Exception $e) {
            $this->log('error', 'Google Calendar: Failed to fetch or parse calendar', [
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
        $event_timezone = $this->extract_event_timezone($ical_event);
        
        $standardized_event = [
            'title' => sanitize_text_field($ical_event['SUMMARY'] ?? ''),
            'description' => sanitize_textarea_field($ical_event['DESCRIPTION'] ?? ''),
            'startDate' => '',
            'endDate' => '',
            'startTime' => '',
            'endTime' => '',
            'venue' => '',
            'address' => '',
            'venueTimezone' => $event_timezone,
            'ticketUrl' => esc_url_raw($ical_event['URL'] ?? ''),
            'image' => '',
            'price' => '',
            'performer' => '',
            'organizer' => sanitize_text_field($ical_event['ORGANIZER'] ?? ''),
            'source_url' => esc_url_raw($ical_event['URL'] ?? '')
        ];

        if (!empty($ical_event['DTSTART'])) {
            $start_datetime = $ical_event['DTSTART'];
            if ($start_datetime instanceof \DateTime) {
                $standardized_event['startDate'] = $start_datetime->format('Y-m-d');
                $standardized_event['startTime'] = $start_datetime->format('H:i');
                if (empty($event_timezone)) {
                    $tz = $start_datetime->getTimezone();
                    if ($tz) {
                        $standardized_event['venueTimezone'] = $tz->getName();
                    }
                }
            } elseif (is_string($start_datetime)) {
                $parsed = $this->parseDateTimeIso($start_datetime);
                $standardized_event['startDate'] = $parsed['date'];
                $standardized_event['startTime'] = $parsed['time'];
                if (empty($standardized_event['venueTimezone']) && !empty($parsed['timezone'])) {
                    $standardized_event['venueTimezone'] = $parsed['timezone'];
                }
            }
        }

        if (!empty($ical_event['DTEND'])) {
            $end_datetime = $ical_event['DTEND'];
            if ($end_datetime instanceof \DateTime) {
                $standardized_event['endDate'] = $end_datetime->format('Y-m-d');
                $standardized_event['endTime'] = $end_datetime->format('H:i');
            } elseif (is_string($end_datetime)) {
                $parsed = $this->parseDateTimeIso($end_datetime);
                $standardized_event['endDate'] = $parsed['date'];
                $standardized_event['endTime'] = $parsed['time'];
            }
        }

        $location = $ical_event['LOCATION'] ?? '';
        if (!empty($location)) {
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

        if (!empty($config['organizer_name'])) {
            $standardized_event['organizer'] = sanitize_text_field($config['organizer_name']);
        }
        if (!empty($config['organizer_url'])) {
            $standardized_event['organizerUrl'] = esc_url_raw($config['organizer_url']);
        }
        if (!empty($config['organizer_type'])) {
            $standardized_event['organizerType'] = sanitize_text_field($config['organizer_type']);
        }

        return $standardized_event;
    }

    /**
     * Extract timezone from iCal event array
     *
     * @param array $ical_event iCal event array
     * @return string IANA timezone identifier or empty string
     */
    private function extract_event_timezone(array $ical_event): string {
        if (!empty($ical_event['DTSTART_TZ'])) {
            return $ical_event['DTSTART_TZ'];
        }

        if (!empty($ical_event['DTSTART']) && $ical_event['DTSTART'] instanceof \DateTime) {
            $tz = $ical_event['DTSTART']->getTimezone();
            if ($tz) {
                $tz_name = $tz->getName();
                if ($tz_name !== 'UTC' && $tz_name !== 'Z') {
                    return $tz_name;
                }
            }
        }

        return '';
    }
}