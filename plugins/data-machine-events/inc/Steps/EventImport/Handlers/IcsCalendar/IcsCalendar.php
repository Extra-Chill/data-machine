<?php
/**
 * ICS Calendar Feed Integration
 *
 * Imports events from any ICS/iCal feed URL (Tockify, Outlook, Apple Calendar, etc.).
 * Single-item processing pattern with EventIdentifierGenerator for deduplication.
 * No authentication required - works with public calendar feeds.
 *
 * @deprecated 0.9.8 Use Universal Web Scraper handler with ICS URLs instead
 * @package DataMachineEvents\Steps\EventImport\Handlers\IcsCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\IcsCalendar;

use ICal\ICal;
use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineEvents\Core\DateTimeParser;

if (!defined('ABSPATH')) {
    exit;
}

class IcsCalendar extends EventImportHandler {

    use HandlerRegistrationTrait;

    public function __construct() {
        parent::__construct('ics_calendar');

        if (function_exists('trigger_error')) {
            trigger_error(
                'ICS Calendar handler is deprecated. Use Universal Web Scraper handler with ICS feed URLs instead.',
                E_USER_DEPRECATED
            );
        }

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

    protected function executeFetch(array $config, ExecutionContext $context): array {
        $context->log('info', 'IcsCalendar: Starting ICS calendar feed import');

        $feed_url = $this->normalize_feed_url($config['feed_url'] ?? '');

        if (empty($feed_url)) {
            $context->log('error', 'IcsCalendar: Feed URL not configured');
            return [];
        }

        if (!filter_var($feed_url, FILTER_VALIDATE_URL)) {
            $context->log('error', 'IcsCalendar: Invalid feed URL format', ['url' => $feed_url]);
            return [];
        }

        [$events, $ical] = $this->fetch_calendar_events($feed_url, $config, $context);
        if (empty($events)) {
            $context->log('info', 'IcsCalendar: No events found in feed');
            return [];
        }

        $context->log('info', 'IcsCalendar: Processing calendar events', [
            'events_available' => count($events)
        ]);

        foreach ($events as $ical_event) {
            $standardized_event = $this->map_ical_event($ical_event, $ical, $config);

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

            $event_identifier = EventIdentifierGenerator::generate(
                $standardized_event['title'],
                $standardized_event['startDate'] ?? '',
                $standardized_event['venue'] ?? ''
            );

            if ($this->checkItemProcessed($context, $event_identifier)) {
                continue;
            }

            if ($this->isPastEvent($standardized_event['startDate'] ?? '')) {
                continue;
            }

            $this->markItemAsProcessed($context, $event_identifier);

            $context->log('info', 'IcsCalendar: Found eligible event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate'],
                'venue' => $standardized_event['venue']
            ]);

            $venue_metadata = $this->extractVenueMetadata($standardized_event);
            $this->storeEventContext($context, $standardized_event);
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
                    'pipeline_id' => $context->getPipelineId(),
                    'flow_id' => $context->getFlowId(),
                    'original_title' => $standardized_event['title'],
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time()
                ],
                'event_import'
            );

            return [$dataPacket];
        }

        $context->log('info', 'IcsCalendar: No eligible events found');
        return [];
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
    private function fetch_calendar_events(string $feed_url, array $config, ExecutionContext $context): array {
        try {
            $ical = new ICal($feed_url, [
                'defaultSpan' => 2,
                'defaultTimeZone' => $config['venue_timezone'] ?? 'UTC',
                'defaultWeekStart' => 'MO',
                'skipRecurrence' => false,
                'useTimeZoneWithRRules' => false,
                'filterDaysBefore' => 1,
            ]);

            $events = $ical->sortEventsWithOrder($ical->events(), SORT_ASC);

            $context->log('info', 'IcsCalendar: Successfully fetched events', [
                'total_events' => count($events),
                'feed_url' => $feed_url
            ]);

            return [$events, $ical];

        } catch (\Exception $e) {
            $context->log('error', 'IcsCalendar: Failed to fetch or parse feed', [
                'feed_url' => $feed_url,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Map iCal event to standardized event format
     */
    private function map_ical_event($ical_event, $ical, array $config): array {
        $calendar_timezone = $this->extract_calendar_timezone($ical);
        $event_timezone = $calendar_timezone ?: $this->extract_event_timezone($ical_event);
        
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
            'venueTimezone' => $event_timezone,
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
                $tz = $start_datetime->getTimezone();
                $tz_name = $tz ? $tz->getName() : '';

                $standardized_event['startDate'] = $start_datetime->format('Y-m-d');

                if ($tz_name !== 'UTC' && $tz_name !== 'Z') {
                    $standardized_event['venueTimezone'] = $tz_name;
                    $standardized_event['startTime'] = $start_datetime->format('H:i');
                } elseif (!empty($calendar_timezone) && $calendar_timezone !== 'UTC') {
                    $standardized_event['venueTimezone'] = $calendar_timezone;
                    $start_datetime->setTimezone(new \DateTimeZone($calendar_timezone));
                    $standardized_event['startTime'] = $start_datetime->format('H:i');
                } else {
                    $standardized_event['startTime'] = $start_datetime->format('H:i');
                }
            } elseif (is_string($start_datetime)) {
                $parsed = DateTimeParser::parseIcs($start_datetime, $calendar_timezone);
                $standardized_event['startDate'] = $parsed['date'];
                $standardized_event['startTime'] = $parsed['time'];
                $standardized_event['venueTimezone'] = $parsed['timezone'];
            }
        }

        if (!empty($ical_event->dtend)) {
            $end_datetime = $ical_event->dtend;
            if ($end_datetime instanceof \DateTime) {
                $tz = $end_datetime->getTimezone();
                $tz_name = $tz ? $tz->getName() : '';

                $standardized_event['endDate'] = $end_datetime->format('Y-m-d');

                if ($tz_name !== 'UTC' && $tz_name !== 'Z') {
                    $standardized_event['venueTimezone'] = $tz_name;
                    $standardized_event['endTime'] = $end_datetime->format('H:i');
                } elseif (!empty($calendar_timezone) && $calendar_timezone !== 'UTC') {
                    $standardized_event['venueTimezone'] = $calendar_timezone;
                    $end_datetime->setTimezone(new \DateTimeZone($calendar_timezone));
                    $standardized_event['endTime'] = $end_datetime->format('H:i');
                } else {
                    $standardized_event['endTime'] = $end_datetime->format('H:i');
                }
            } elseif (is_string($end_datetime)) {
                $parsed = DateTimeParser::parseIcs($end_datetime, $calendar_timezone);
                $standardized_event['endDate'] = $parsed['date'];
                $standardized_event['endTime'] = $parsed['time'];
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

    /**
     * Extract timezone from iCal calendar (VTIMEZONE section).
     *
     * @param ICal $ical Parsed iCal object
     * @return string IANA timezone identifier or empty string
     */
    private function extract_calendar_timezone($ical): string {
        if (!$ical instanceof ICal) {
            return '';
        }

        $calendar_timezone = $ical->calendarTimeZone() ?? '';

        if (!empty($calendar_timezone) && $calendar_timezone !== 'UTC') {
            return $calendar_timezone;
        }

        return '';
    }

    /**
     * Extract timezone from iCal event
     *
     * Checks dtstart_tz property and falls back to DateTime timezone.
     * Returns empty string for UTC (to avoid storing redundant data).
     *
     * @param object $ical_event Parsed iCal event object
     * @return string IANA timezone identifier or empty string
     */
    private function extract_event_timezone($ical_event): string {
        if (!empty($ical_event->dtstart_tz)) {
            return $ical_event->dtstart_tz;
        }

        if (!empty($ical_event->dtstart) && $ical_event->dtstart instanceof \DateTime) {
            $tz = $ical_event->dtstart->getTimezone();
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
