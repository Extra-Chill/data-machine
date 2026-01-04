<?php
/**
 * Embedded Calendar extractor.
 *
 * Extracts event data from pages with embedded Google Calendar iframes by
 * detecting the embed, extracting the calendar ID, and fetching the public ICS feed.
 * Uses PageVenueExtractor to get venue info from page context when not in calendar data.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use ICal\ICal;
use DataMachine\Core\HttpClient;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor;

if (!defined('ABSPATH')) {
    exit;
}

class EmbeddedCalendarExtractor implements ExtractorInterface {

    public function canExtract(string $html): bool {
        return strpos($html, 'calendar.google.com/calendar/embed') !== false;
    }

    public function extract(string $html, string $source_url): array {
        $calendar_data = $this->extractCalendarData($html);

        if (empty($calendar_data['calendar_id'])) {
            return [];
        }

        $ics_url = $this->generateIcsUrl($calendar_data['calendar_id']);
        $ics_content = $this->fetchIcsContent($ics_url);

        if (empty($ics_content)) {
            return [];
        }

        $ical_events = $this->parseIcsContent($ics_content);

        if (empty($ical_events)) {
            return [];
        }

        // Extract venue info from page context (fallback for calendar events that lack venue data)
        $page_venue = PageVenueExtractor::extract($html, $source_url);

        // Prefer timezone from embed URL, fall back to page context
        $timezone = $calendar_data['timezone'] ?: $page_venue['venueTimezone'];

        $events = [];
        foreach ($ical_events as $ical_event) {
            $normalized = $this->normalizeEvent($ical_event, $page_venue, $timezone);
            if (!empty($normalized['title'])) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    public function getMethod(): string {
        return 'embedded_calendar';
    }

    /**
     * Extract calendar ID and timezone from Google Calendar embed iframe.
     */
    private function extractCalendarData(string $html): array {
        $data = [
            'calendar_id' => '',
            'timezone' => '',
        ];

        if (!preg_match('/<iframe[^>]+src=["\']([^"\']*calendar\.google\.com\/calendar\/embed[^"\']*)["\'][^>]*>/i', $html, $matches)) {
            return $data;
        }

        $iframe_src = html_entity_decode($matches[1]);
        $parsed_url = parse_url($iframe_src);

        if (empty($parsed_url['query'])) {
            return $data;
        }

        parse_str($parsed_url['query'], $query_params);

        if (!empty($query_params['src'])) {
            $data['calendar_id'] = $query_params['src'];
        }

        if (!empty($query_params['ctz'])) {
            $data['timezone'] = $query_params['ctz'];
        }

        return $data;
    }

    /**
     * Generate public ICS URL from calendar ID.
     */
    private function generateIcsUrl(string $calendar_id): string {
        $encoded_id = urlencode($calendar_id);
        return "https://calendar.google.com/calendar/ical/{$encoded_id}/public/basic.ics";
    }

    /**
     * Fetch ICS content from URL.
     */
    private function fetchIcsContent(string $url): string {
        $result = HttpClient::get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'text/calendar, text/plain',
            ],
            'context' => 'Embedded Calendar Extractor',
        ]);

        if (!$result['success'] || $result['status_code'] !== 200) {
            return '';
        }

        return $result['data'] ?? '';
    }

    /**
     * Parse ICS content using ICal library.
     */
    private function parseIcsContent(string $ics_content): array {
        try {
            $ical = new ICal(false, [
                'defaultSpan' => 2,
                'defaultTimeZone' => 'UTC',
                'defaultWeekStart' => 'MO',
                'skipRecurrence' => false,
                'useTimeZoneWithRRules' => false,
                'filterDaysBefore' => 1,
            ]);

            $ical->initString($ics_content);

            return $ical->events() ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Normalize iCal event to standardized format.
     *
     * Uses page venue as fallback when calendar event lacks venue data.
     */
    private function normalizeEvent($ical_event, array $page_venue, string $default_timezone): array {
        $event = [
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
            'venueCountry' => 'US',
            'venueTimezone' => $default_timezone,
            'ticketUrl' => esc_url_raw($ical_event->url ?? ''),
            'source_url' => esc_url_raw($ical_event->url ?? ''),
            'organizer' => sanitize_text_field($ical_event->organizer ?? ''),
        ];

        // Check if calendar event has location data
        $has_calendar_venue = !empty($ical_event->location);

        if ($has_calendar_venue) {
            $location = $ical_event->location;
            $location_parts = explode(',', $location, 2);
            $event['venue'] = sanitize_text_field(trim($location_parts[0]));
            if (isset($location_parts[1])) {
                $event['venueAddress'] = sanitize_text_field(trim($location_parts[1]));
            }
        } else {
            // Use page venue as fallback
            $event['venue'] = $page_venue['venue'] ?? '';
            $event['venueAddress'] = $page_venue['venueAddress'] ?? '';
            $event['venueCity'] = $page_venue['venueCity'] ?? '';
            $event['venueState'] = $page_venue['venueState'] ?? '';
            $event['venueZip'] = $page_venue['venueZip'] ?? '';
            $event['venueCountry'] = $page_venue['venueCountry'] ?? 'US';
        }

        $this->parseDateTimes($event, $ical_event, $default_timezone);

        return $event;
    }

    /**
     * Parse date/time values from iCal event.
     */
    private function parseDateTimes(array &$event, $ical_event, string $default_timezone): void {
        if (!empty($ical_event->dtstart)) {
            $start = $ical_event->dtstart;

            if ($start instanceof \DateTime) {
                $event['startDate'] = $start->format('Y-m-d');
                $event['startTime'] = $start->format('H:i');

                if (empty($event['venueTimezone'])) {
                    $tz = $start->getTimezone();
                    if ($tz && $tz->getName() !== 'UTC' && $tz->getName() !== 'Z') {
                        $event['venueTimezone'] = $tz->getName();
                    }
                }
            } elseif (is_string($start)) {
                $parsed = $this->parseDateTimeString($start, $default_timezone);
                $event['startDate'] = $parsed['date'];
                $event['startTime'] = $parsed['time'];
            }
        }

        if (!empty($ical_event->dtend)) {
            $end = $ical_event->dtend;

            if ($end instanceof \DateTime) {
                $event['endDate'] = $end->format('Y-m-d');
                $event['endTime'] = $end->format('H:i');
            } elseif (is_string($end)) {
                $parsed = $this->parseDateTimeString($end, $default_timezone);
                $event['endDate'] = $parsed['date'];
                $event['endTime'] = $parsed['time'];
            }
        }

        if (!empty($ical_event->dtstart_tz) && empty($event['venueTimezone'])) {
            $event['venueTimezone'] = $ical_event->dtstart_tz;
        }
    }

    /**
     * Parse date/time string to components.
     */
    private function parseDateTimeString(string $datetime_str, string $default_timezone): array {
        $result = ['date' => '', 'time' => ''];

        try {
            $tz = !empty($default_timezone) ? new \DateTimeZone($default_timezone) : null;
            $dt = new \DateTime($datetime_str, $tz);
            $result['date'] = $dt->format('Y-m-d');
            $result['time'] = $dt->format('H:i');
        } catch (\Exception $e) {
            if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $datetime_str, $matches)) {
                $result['date'] = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
            }
            if (preg_match('/T(\d{2})(\d{2})(\d{2})/', $datetime_str, $matches)) {
                $result['time'] = $matches[1] . ':' . $matches[2];
            }
        }

        return $result;
    }
}
