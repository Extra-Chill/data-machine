<?php
/**
 * Gigwell extractor.
 *
 * Extracts event data from venues using the Gigwell booking platform by detecting
 * the <gigwell-gigstream> custom element and fetching events from their public API.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if (!defined('ABSPATH')) {
    exit;
}

class GigwellExtractor extends BaseExtractor {

    const API_BASE = 'https://api.gigwell.com/api/gigs';

    public function canExtract(string $html): bool {
        return strpos($html, '<gigwell-gigstream') !== false
            || strpos($html, 'connect.gigwell.com/gigstream') !== false;
    }

    public function extract(string $html, string $source_url): array {
        $agency_id = $this->extractAgencyId($html);

        if (empty($agency_id)) {
            return [];
        }

        $api_response = $this->fetchEvents($agency_id);
        if (empty($api_response)) {
            return [];
        }

        $raw_events = $api_response['results'] ?? [];

        if (empty($raw_events)) {
            return [];
        }

        $events = [];
        foreach ($raw_events as $raw_event) {
            $normalized = $this->normalizeEvent($raw_event);
            if (!empty($normalized['title'])) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    public function getMethod(): string {
        return 'gigwell';
    }

    /**
     * Extract agency ID from Gigwell embed.
     *
     * Looks for the agency attribute in <gigwell-gigstream> custom element.
     * Example: <gigwell-gigstream identity-id="315632" agency="315637" settings="b8txja">
     *
     * @param string $html Page HTML
     * @return string|null Agency ID or null
     */
    private function extractAgencyId(string $html): ?string {
        // Primary: agency attribute in gigwell-gigstream element
        if (preg_match('/<gigwell-gigstream[^>]+agency=["\'](\d+)["\']/', $html, $matches)) {
            return $matches[1];
        }

        // Fallback: agency in URL parameters or JS config
        if (preg_match('/agency[=:][\s"\']*(\d+)/', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Fetch events from Gigwell API.
     *
     * @param string $agency_id Gigwell agency ID
     * @return array API response data or empty array
     */
    private function fetchEvents(string $agency_id): array {
        $url = self::API_BASE . '?agencies=' . urlencode($agency_id) . '&limit=100&direction=ASC';

        $result = HttpClient::get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'context' => 'Gigwell Extractor',
        ]);

        if (!$result['success'] || $result['status_code'] !== 200) {
            return [];
        }

        $data = json_decode($result['data'], true);
        return (json_last_error() === JSON_ERROR_NONE) ? $data : [];
    }

    /**
     * Normalize Gigwell event to standardized format.
     *
     * @param array $event Raw event from Gigwell API
     * @return array Normalized event data
     */
    private function normalizeEvent(array $event): array {
        $title = $event['artistTitle'] ?? '';
        if (empty($title) && !empty($event['artists'])) {
            $title = is_array($event['artists']) ? implode(', ', $event['artists']) : $event['artists'];
        }

        // Use summary as title if available (some events have specific names)
        if (!empty($event['summary'])) {
            $title = $event['summary'];
        }

        $description = $event['description'] ?? '';

        // Parse datetime - Gigwell returns UTC ISO 8601 with timezone info
        $start_date = '';
        $start_time = '';
        $timezone = $event['eventTimeZone'] ?? 'America/New_York';

        if (!empty($event['startDateTime'])) {
            $parsed = $this->parseUtcDatetime($event['startDateTime'], $timezone);
            $start_date = $parsed['date'];
            $start_time = $parsed['time'];
        } elseif (!empty($event['localDate'])) {
            // Fallback to localDate if startDateTime not available
            $start_date = $event['localDate'];
        }

        // Build venue data from Gigwell fields
        $venue_name = $event['venueTitle'] ?? '';
        $venue_city = $event['eventCity'] ?? '';
        $venue_state = $event['eventState'] ?? $event['eventStateName'] ?? '';
        $venue_zip = $event['eventZipCode'] ?? '';
        $venue_country = $event['eventCountry'] ?? 'US';
        $venue_address = $event['eventAddress'] ?? '';

        // Ticket URL - prefer ticketUrl, fall back to rsvpUrl
        $ticket_url = '';
        if (!empty($event['ticketUrl'])) {
            $ticket_url = $event['ticketUrl'];
        } elseif (!empty($event['rsvpUrl'])) {
            $ticket_url = $event['rsvpUrl'];
        }

        return [
            'title' => $this->sanitizeText($title),
            'description' => $this->cleanHtml($description),
            'startDate' => $start_date,
            'endDate' => '',
            'startTime' => $start_time,
            'endTime' => '',
            'venue' => $this->sanitizeText($venue_name),
            'venueAddress' => $this->sanitizeText($venue_address),
            'venueCity' => $this->sanitizeText($venue_city),
            'venueState' => $this->sanitizeText($venue_state),
            'venueZip' => $this->sanitizeText($venue_zip),
            'venueCountry' => $this->sanitizeText($venue_country),
            'venueTimezone' => $timezone,
            'ticketUrl' => !empty($ticket_url) ? esc_url_raw($ticket_url) : '',
            'performer' => $this->sanitizeText($event['artistTitle'] ?? ''),
            'sourceId' => (string)($event['id'] ?? ''),
        ];
    }
}
