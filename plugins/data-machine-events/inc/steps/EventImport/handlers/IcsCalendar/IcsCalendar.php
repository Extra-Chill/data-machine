<?php
/**
 * ICS Calendar Feed Integration
 *
 * Imports events from any ICS/iCal feed URL (Tockify, Outlook, Apple Calendar, etc.).
 * Single-item processing pattern with EventIdentifierGenerator for deduplication.
 * No authentication required - works with public calendar feeds.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\IcsCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\IcsCalendar;

use ICal\ICal;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

class IcsCalendar extends EventImportHandler {

    use HandlerRegistrationTrait;

    public function __construct() {
        parent::__construct('ics_calendar');

        self::registerHandler(
            'ics_calendar',
            'event_import',
            self::class,
            __('ICS Calendar Feed', 'datamachine-events'),
            __('Import events from any ICS/iCal feed (Tockify, Outlook, Apple Calendar, etc.)', 'datamachine-events'),
            false,
            null,
            IcsCalendarSettings::class,
            null
        );
    }

    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $this->log('info', 'Starting ICS calendar feed import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'flow_step_id' => $flow_step_id
        ]);

        $feed_url = $this->normalize_feed_url($config['feed_url'] ?? '');

        if (empty($feed_url)) {
            $this->log('error', 'ICS feed URL not configured');
            return $this->emptyResponse() ?? [];
        }

        if (!filter_var($feed_url, FILTER_VALIDATE_URL)) {
            $this->log('error', 'Invalid ICS feed URL format', ['url' => $feed_url]);
            return $this->emptyResponse() ?? [];
        }

        $events = $this->fetch_calendar_events($feed_url, $config);
        if (empty($events)) {
            $this->log('info', 'No events found in ICS feed');
            return $this->emptyResponse() ?? [];
        }

        $this->log('info', 'Processing ICS calendar events', [
            'events_available' => count($events),
            'pipeline_id' => $pipeline_id
        ]);

        foreach ($events as $ical_event) {
            $standardized_event = $this->map_ical_event($ical_event, $config);

            if (empty($standardized_event['title'])) {
                continue;
            }

            $search_text = $standardized_event['title'] . ' ' . ($standardized_event['description'] ?? '');

            if (!$this->applyKeywordSearch($search_text, $config['search'] ?? '')) {
                continue;
            }

            if ($this->applyExcludeKeywords($search_text, $config['exclude_keywords'] ?? '')) {
                continue;
            }

            $event_identifier = EventIdentifierGenerator::generate(
                $standardized_event['title'],
                $standardized_event['startDate'] ?? '',
                $standardized_event['venue'] ?? ''
            );

            if ($this->isItemProcessed($event_identifier, $flow_step_id)) {
                continue;
            }

            if ($this->isPastEvent($standardized_event['startDate'] ?? '')) {
                continue;
            }

            $this->markItemProcessed($event_identifier, $flow_step_id, $job_id);

            $this->log('info', 'Found eligible ICS calendar event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate'],
                'venue' => $standardized_event['venue']
            ]);

            $venue_metadata = $this->extractVenueMetadata($standardized_event);

            EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);

            $this->stripVenueMetadataFromEvent($standardized_event);

            $dataPacket = new DataPacket(
                [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'ics_calendar'
                    ], JSON_PRETTY_PRINT)
                ],
                [
                    'source_type' => 'ics_calendar',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_id,
                    'original_title' => $standardized_event['title'],
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time()
                ],
                'event_import'
            );

            return $this->successResponse([$dataPacket]);
        }

        $this->log('info', 'No eligible ICS calendar events found');
        return $this->emptyResponse() ?? [];
    }

    /**
     * Normalize feed URL by converting webcal:// to https://
     */
    private function normalize_feed_url(string $url): string {
        $url = trim($url);

        if (str_starts_with($url, 'webcal://')) {
            $url = 'https://' . substr($url, 9);
        }

        return $url;
    }

    /**
     * Fetch and parse calendar events from ICS feed URL
     */
    private function fetch_calendar_events(string $feed_url, array $config): array {
        try {
            $ical = new ICal($feed_url, [
                'defaultSpan' => 2,
                'defaultTimeZone' => 'UTC',
                'defaultWeekStart' => 'MO',
                'skipRecurrence' => false,
                'useTimeZoneWithRRules' => false,
                'filterDaysBefore' => 1,
            ]);

            $events = $ical->events();

            $this->log('info', 'ICS Calendar: Successfully fetched events', [
                'total_events' => count($events),
                'feed_url' => $feed_url
            ]);

            return $events;

        } catch (\Exception $e) {
            $this->log('error', 'ICS Calendar: Failed to fetch or parse feed', [
                'feed_url' => $feed_url,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Map iCal event to standardized event format
     */
    private function map_ical_event($ical_event, array $config): array {
        $standardized_event = [
            'title' => sanitize_text_field($ical_event->summary ?? ''),
            'description' => sanitize_textarea_field($ical_event->description ?? ''),
            'startDate' => '',
            'endDate' => '',
            'startTime' => '',
            'endTime' => '',
            'venue' => '',
            'venueAddress' => '',
            'venueCity' => '',
            'venueState' => '',
            'venueZip' => '',
            'venueCountry' => '',
            'ticketUrl' => esc_url_raw($ical_event->url ?? ''),
            'image' => '',
            'price' => '',
            'performer' => '',
            'organizer' => sanitize_text_field($ical_event->organizer ?? ''),
            'source_url' => esc_url_raw($ical_event->url ?? '')
        ];

        if (!empty($ical_event->dtstart)) {
            $start_datetime = $ical_event->dtstart;
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

        if (!empty($ical_event->dtend)) {
            $end_datetime = $ical_event->dtend;
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

        $location = $ical_event->location ?? '';
        if (!empty($location)) {
            $location_parts = explode(',', $location, 2);
            $standardized_event['venue'] = sanitize_text_field(trim($location_parts[0]));
            if (isset($location_parts[1])) {
                $standardized_event['venueAddress'] = sanitize_text_field(trim($location_parts[1]));
            } else {
                $standardized_event['venueAddress'] = sanitize_text_field($location);
            }
        }

        if (!empty($config['venue_name'])) {
            $standardized_event['venue'] = sanitize_text_field($config['venue_name']);
        }
        if (!empty($config['venue_address'])) {
            $standardized_event['venueAddress'] = sanitize_text_field($config['venue_address']);
        }
        if (!empty($config['venue_city'])) {
            $standardized_event['venueCity'] = sanitize_text_field($config['venue_city']);
        }
        if (!empty($config['venue_state'])) {
            $standardized_event['venueState'] = sanitize_text_field($config['venue_state']);
        }
        if (!empty($config['venue_zip'])) {
            $standardized_event['venueZip'] = sanitize_text_field($config['venue_zip']);
        }
        if (!empty($config['venue_country'])) {
            $standardized_event['venueCountry'] = sanitize_text_field($config['venue_country']);
        }

        return $standardized_event;
    }
}
