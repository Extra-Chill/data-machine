<?php
/**
 * WordPress Events extractor.
 *
 * Extracts event data from WordPress sites using REST API endpoints.
 * Supports Tribe Events Calendar, generic WP REST events, and auto-discovery.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if (!defined('ABSPATH')) {
    exit;
}

class WordPressExtractor extends BaseExtractor {

    public function canExtract(string $html): bool {
        $trimmed = trim($html);

        // Check if content is JSON (direct API response)
        if (strpos($trimmed, '{') === 0 || strpos($trimmed, '[') === 0) {
            $data = json_decode($trimmed, true);
            if (is_array($data)) {
                // Tribe v1 format
                if (isset($data['events']) && isset($data['rest_url'])) {
                    return true;
                }
                // WP REST array format
                if (!empty($data) && isset($data[0]['id'])) {
                    if (isset($data[0]['start_date']) || isset($data[0]['meta']['_EventStartDate'])) {
                        return true;
                    }
                }
            }
        }

        // Check HTML for WordPress/Tribe Events indicators
        return strpos($html, 'tribe-events') !== false
            || strpos($html, '/wp-json/tribe/events/') !== false
            || strpos($html, 'rel="https://api.w.org/"') !== false
            || (strpos($html, 'wp-content') !== false && strpos($html, 'tribe_events') !== false);
    }

    public function extract(string $html, string $source_url): array {
        $trimmed = trim($html);

        // Try direct JSON parsing first
        if (strpos($trimmed, '{') === 0 || strpos($trimmed, '[') === 0) {
            $data = json_decode($trimmed, true);
            if (is_array($data) && json_last_error() === JSON_ERROR_NONE) {
                return $this->extractFromJson($data, $source_url);
            }
        }

        // Auto-discover REST API from HTML page
        $api_url = $this->discoverApiEndpoint($html, $source_url);
        if (empty($api_url)) {
            return [];
        }

        $json = $this->fetchJson($api_url);
        if (empty($json)) {
            return [];
        }

        return $this->extractFromJson($json, $source_url);
    }

    public function getMethod(): string {
        return 'wordpress';
    }

    /**
     * Extract events from parsed JSON data.
     */
    private function extractFromJson(array $data, string $source_url): array {
        $format = $this->detectApiFormat($data);
        $raw_events = $this->extractEventsFromResponse($data, $format);

        if (empty($raw_events)) {
            return [];
        }

        $events = [];
        foreach ($raw_events as $raw_event) {
            $normalized = $this->mapEvent($raw_event, $format, $source_url);
            if (!empty($normalized['title']) && !empty($normalized['startDate'])) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    /**
     * Discover the REST API endpoint from HTML page.
     */
    private function discoverApiEndpoint(string $html, string $source_url): ?string {
        $parsed = parse_url($source_url);
        $base_url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        // Look for Tribe Events REST API link
        if (preg_match('#/wp-json/tribe/events/v1/events#', $html)) {
            return $base_url . '/wp-json/tribe/events/v1/events?per_page=100';
        }

        // Look for WP REST API discovery link
        if (preg_match('#<link[^>]+rel=["\']https://api\.w\.org/["\'][^>]+href=["\']([^"\']+)["\']#i', $html, $m)) {
            $api_base = $m[1];
            // Try Tribe endpoint first
            $tribe_url = rtrim($api_base, '/') . '/tribe/events/v1/events?per_page=100';
            $tribe_json = $this->fetchJson($tribe_url);
            if (!empty($tribe_json)) {
                return $tribe_url;
            }
        }

        // Fallback: construct Tribe endpoint from base URL
        if (strpos($html, 'tribe-events') !== false) {
            return $base_url . '/wp-json/tribe/events/v1/events?per_page=100';
        }

        return null;
    }

    /**
     * Detect the API format from response structure.
     */
    private function detectApiFormat(array $response): string {
        // Tribe v1 format: { "events": [...], "rest_url": "..." }
        if (isset($response['events']) && isset($response['rest_url'])) {
            return 'tribe_v1';
        }

        // Array of events
        if (is_array($response) && !empty($response) && isset($response[0]['id'])) {
            // Tribe via WP REST: has _EventStartDate meta
            if (isset($response[0]['start_date']) || isset($response[0]['meta']['_EventStartDate'])) {
                return 'tribe_wp';
            }
            return 'generic_wp';
        }

        return 'generic_wp';
    }

    /**
     * Extract events array from API response.
     */
    private function extractEventsFromResponse(array $response, string $format): array {
        if ($format === 'tribe_v1') {
            return $response['events'] ?? [];
        }

        if (is_array($response) && !empty($response) && isset($response[0])) {
            return $response;
        }

        return [];
    }

    /**
     * Map event to standard format based on detected API format.
     */
    private function mapEvent(array $event, string $format, string $source_url): array {
        return match ($format) {
            'tribe_v1' => $this->mapTribeV1Event($event, $source_url),
            'tribe_wp' => $this->mapTribeWpEvent($event, $source_url),
            default => $this->mapGenericEvent($event, $source_url),
        };
    }

    /**
     * Map Tribe Events Calendar v1 API event.
     */
    private function mapTribeV1Event(array $event, string $source_url): array {
        $start_date = '';
        $start_time = '';
        $end_date = '';
        $end_time = '';

        if (!empty($event['start_date'])) {
            $parsed = $this->parseDatetime($event['start_date']);
            $start_date = $parsed['date'];
            $start_time = $parsed['time'];
        }

        if (!empty($event['end_date'])) {
            $parsed = $this->parseDatetime($event['end_date']);
            $end_date = $parsed['date'];
            $end_time = $parsed['time'];
        }

        // Prefer detailed time if available
        if (!empty($event['start_date_details']['hour']) && !empty($event['start_date_details']['minutes'])) {
            $start_time = sprintf('%02d:%02d', $event['start_date_details']['hour'], $event['start_date_details']['minutes']);
        }
        if (!empty($event['end_date_details']['hour']) && !empty($event['end_date_details']['minutes'])) {
            $end_time = sprintf('%02d:%02d', $event['end_date_details']['hour'], $event['end_date_details']['minutes']);
        }

        // All-day events have no time
        if (!empty($event['all_day'])) {
            $start_time = '';
            $end_time = '';
        }

        $venue = $this->extractTribeVenue($event);
        $organizer = $this->extractTribeOrganizer($event);

        return [
            'title' => $this->sanitizeText($event['title'] ?? ''),
            'description' => $this->cleanHtml($event['description'] ?? ''),
            'startDate' => $start_date,
            'endDate' => $end_date ?: $start_date,
            'startTime' => $start_time,
            'endTime' => $end_time,
            'venue' => $this->sanitizeText($venue['venue']),
            'venueAddress' => $this->sanitizeText($venue['venueAddress']),
            'venueCity' => $this->sanitizeText($venue['venueCity']),
            'venueState' => $this->sanitizeText($venue['venueState']),
            'venueZip' => $this->sanitizeText($venue['venueZip']),
            'venueCountry' => sanitize_text_field($venue['venueCountry']),
            'venuePhone' => sanitize_text_field($venue['venuePhone']),
            'venueWebsite' => esc_url_raw($venue['venueWebsite']),
            'organizer' => sanitize_text_field($organizer['organizer']),
            'organizerUrl' => esc_url_raw($organizer['organizerUrl']),
            'price' => $this->sanitizeText($event['cost'] ?? ''),
            'ticketUrl' => esc_url_raw($event['website'] ?? $event['url'] ?? ''),
            'imageUrl' => esc_url_raw($event['image']['url'] ?? ''),
            'eventType' => 'Event',
            'source_url' => $source_url,
        ];
    }

    /**
     * Extract venue data from Tribe v1 event.
     */
    private function extractTribeVenue(array $event): array {
        $venue = $event['venue'] ?? [];

        $coordinates = '';
        if (!empty($venue['geo_lat']) && !empty($venue['geo_lng'])) {
            $coordinates = $venue['geo_lat'] . ',' . $venue['geo_lng'];
        }

        return [
            'venue' => $venue['venue'] ?? '',
            'venueAddress' => $venue['address'] ?? '',
            'venueCity' => $venue['city'] ?? '',
            'venueState' => $venue['state'] ?? $venue['province'] ?? '',
            'venueZip' => $venue['zip'] ?? $venue['postal_code'] ?? '',
            'venueCountry' => $venue['country'] ?? '',
            'venuePhone' => $venue['phone'] ?? '',
            'venueWebsite' => $venue['website'] ?? $venue['url'] ?? '',
            'venueCoordinates' => $coordinates,
        ];
    }

    /**
     * Extract organizer data from Tribe v1 event.
     */
    private function extractTribeOrganizer(array $event): array {
        $organizer = $event['organizer'] ?? [];

        if (isset($organizer[0])) {
            $organizer = $organizer[0];
        }

        return [
            'organizer' => $organizer['organizer'] ?? '',
            'organizerUrl' => $organizer['website'] ?? $organizer['url'] ?? '',
        ];
    }

    /**
     * Map Tribe Events via standard WP REST namespace.
     */
    private function mapTribeWpEvent(array $event, string $source_url): array {
        $title = $event['title']['rendered'] ?? $event['title'] ?? '';
        $description = $event['content']['rendered'] ?? $event['description'] ?? '';

        $start_date = '';
        $start_time = '';
        $end_date = '';
        $end_time = '';

        $meta = $event['meta'] ?? [];
        if (!empty($meta['_EventStartDate'])) {
            $parsed = $this->parseDatetime($meta['_EventStartDate']);
            $start_date = $parsed['date'];
            $start_time = $parsed['time'];
        }
        if (!empty($meta['_EventEndDate'])) {
            $parsed = $this->parseDatetime($meta['_EventEndDate']);
            $end_date = $parsed['date'];
            $end_time = $parsed['time'];
        }

        $coordinates = '';
        if (!empty($meta['_VenueLat']) && !empty($meta['_VenueLng'])) {
            $coordinates = $meta['_VenueLat'] . ',' . $meta['_VenueLng'];
        }

        $image_url = $event['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '';

        return [
            'title' => $this->sanitizeText($title),
            'description' => $this->cleanHtml($description),
            'startDate' => $start_date,
            'endDate' => $end_date ?: $start_date,
            'startTime' => $start_time,
            'endTime' => $end_time,
            'venue' => $this->sanitizeText($meta['_VenueName'] ?? ''),
            'venueAddress' => $this->sanitizeText($meta['_VenueAddress'] ?? ''),
            'venueCity' => $this->sanitizeText($meta['_VenueCity'] ?? ''),
            'venueState' => $this->sanitizeText($meta['_VenueState'] ?? $meta['_VenueProvince'] ?? ''),
            'venueZip' => $this->sanitizeText($meta['_VenueZip'] ?? ''),
            'venueCountry' => $this->sanitizeText($meta['_VenueCountry'] ?? ''),
            'venueCoordinates' => $coordinates,
            'price' => $this->sanitizeText($meta['_EventCost'] ?? ''),
            'ticketUrl' => esc_url_raw($meta['_EventURL'] ?? $event['link'] ?? ''),
            'imageUrl' => esc_url_raw($image_url),
            'eventType' => 'Event',
            'source_url' => $source_url,
        ];
    }

    /**
     * Map generic WordPress REST API event.
     */
    private function mapGenericEvent(array $event, string $source_url): array {
        $title = $event['title']['rendered'] ?? $event['title'] ?? $event['name'] ?? '';
        $description = $event['content']['rendered'] ?? $event['description'] ?? $event['body'] ?? '';

        $start_date = '';
        $start_time = '';
        $end_date = '';
        $end_time = '';

        $date_fields = ['start_date', 'event_date', 'date', 'startDate'];
        foreach ($date_fields as $field) {
            if (!empty($event[$field])) {
                $parsed = $this->parseDatetime($event[$field]);
                if (!empty($parsed['date'])) {
                    $start_date = $parsed['date'];
                    $start_time = $parsed['time'];
                    break;
                }
            }
        }

        $end_date_fields = ['end_date', 'endDate'];
        foreach ($end_date_fields as $field) {
            if (!empty($event[$field])) {
                $parsed = $this->parseDatetime($event[$field]);
                if (!empty($parsed['date'])) {
                    $end_date = $parsed['date'];
                    $end_time = $parsed['time'];
                    break;
                }
            }
        }

        $image_url = $event['_embedded']['wp:featuredmedia'][0]['source_url']
            ?? $event['image']['url']
            ?? (is_string($event['image'] ?? null) ? $event['image'] : '')
            ?? '';

        return [
            'title' => sanitize_text_field($title),
            'description' => wp_kses_post($description),
            'startDate' => $start_date,
            'endDate' => $end_date ?: $start_date,
            'startTime' => $start_time,
            'endTime' => $end_time,
            'venue' => sanitize_text_field($event['venue'] ?? $event['venue_name'] ?? $event['location'] ?? ''),
            'venueAddress' => sanitize_text_field($event['address'] ?? ''),
            'venueCity' => sanitize_text_field($event['city'] ?? ''),
            'venueState' => sanitize_text_field($event['state'] ?? ''),
            'venueZip' => sanitize_text_field($event['zip'] ?? $event['postal_code'] ?? ''),
            'venueCountry' => sanitize_text_field($event['country'] ?? ''),
            'price' => sanitize_text_field($event['cost'] ?? $event['price'] ?? ''),
            'ticketUrl' => esc_url_raw($event['website'] ?? $event['url'] ?? $event['link'] ?? ''),
            'imageUrl' => esc_url_raw($image_url),
            'eventType' => 'Event',
            'source_url' => $source_url,
        ];
    }

    /**
     * Fetch JSON from URL.
     */
    private function fetchJson(string $url): ?array {
        $result = HttpClient::get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'context' => 'WordPress Extractor',
        ]);

        if (!$result['success'] || ($result['status_code'] ?? 0) !== 200) {
            return null;
        }

        $data = json_decode($result['data'] ?? '', true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : null;
    }
}
