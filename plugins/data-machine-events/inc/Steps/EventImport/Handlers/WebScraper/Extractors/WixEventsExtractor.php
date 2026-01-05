<?php
/**
 * Wix Events extractor.
 *
 * Extracts event data from Wix platform websites by parsing the embedded
 * wix-warmup-data JSON structure containing event listings.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if (!defined('ABSPATH')) {
    exit;
}

class WixEventsExtractor extends BaseExtractor {

    public function canExtract(string $html): bool {
        return strpos($html, 'id="wix-warmup-data"') !== false
            || strpos($html, "id='wix-warmup-data'") !== false;
    }

    public function extract(string $html, string $source_url): array {
        if (!preg_match('/<script[^>]+id=["\']wix-warmup-data["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return [];
        }

        $json_content = trim($matches[1]);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return [];
        }

        $raw_events = $this->findEventsRecursive($data);
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
        return 'wix_events';
    }

    /**
     * Recursively search for Wix events array in JSON structure.
     *
     * @param array $data JSON data structure
     * @return array Events array or empty array
     */
    private function findEventsRecursive(array $data): array {
        if (isset($data['events']) && isset($data['events']['events']) && is_array($data['events']['events'])) {
            return $data['events']['events'];
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $result = $this->findEventsRecursive($value);
                if (!empty($result)) {
                    return $result;
                }
            }
        }

        return [];
    }

    /**
     * Normalize Wix event to standardized format.
     *
     * @param array $wix_event Raw Wix event object
     * @return array Standardized event data
     */
    private function normalizeEvent(array $wix_event): array {
        $event = [
            'title' => $this->sanitizeText($wix_event['title'] ?? ''),
            'description' => $this->cleanHtml($wix_event['description'] ?? $wix_event['about'] ?? ''),
        ];

        $this->parseScheduling($event, $wix_event);
        $this->parseLocation($event, $wix_event);
        $this->parseTicketing($event, $wix_event);
        $this->parseImage($event, $wix_event);

        return $event;
    }

    /**
     * Parse scheduling data from Wix event.
     */
    private function parseScheduling(array &$event, array $wix_event): void {
        $scheduling = $wix_event['scheduling']['config'] ?? [];
        $timezone_id = $scheduling['timeZoneId'] ?? '';

        if (!empty($scheduling['startDate'])) {
            $start_parsed = $this->parseUtcDatetime($scheduling['startDate'], $timezone_id);
            $event['startDate'] = $start_parsed['date'];
            $event['startTime'] = $start_parsed['time'];
        }

        if (!empty($scheduling['endDate'])) {
            $end_parsed = $this->parseUtcDatetime($scheduling['endDate'], $timezone_id);
            $event['endDate'] = $end_parsed['date'];
            $event['endTime'] = $end_parsed['time'];
        }

        if (!empty($timezone_id) && $this->isValidTimezone($timezone_id)) {
            $event['venueTimezone'] = $timezone_id;
        }
    }

    /**
     * Parse location data from Wix event.
     */
    private function parseLocation(array &$event, array $wix_event): void {
        $location = $wix_event['location'] ?? [];
        if (empty($location)) {
            return;
        }

        $event['venue'] = $this->sanitizeText($location['name'] ?? '');
        $event['venueAddress'] = $this->sanitizeText($location['address'] ?? '');

        $full_address = $location['fullAddress'] ?? [];
        if (!empty($full_address)) {
            $event['venueCity'] = $this->sanitizeText($full_address['city'] ?? '');
            $event['venueState'] = $this->sanitizeText($full_address['subdivision'] ?? '');
            $event['venueZip'] = $this->sanitizeText($full_address['postalCode'] ?? '');
            $event['venueCountry'] = $this->sanitizeText($full_address['country'] ?? '');

            $street = $full_address['streetAddress'] ?? [];
            if (!empty($street) && is_array($street)) {
                $street_parts = array_filter([
                    $street['number'] ?? '',
                    $street['name'] ?? ''
                ]);
                if (!empty($street_parts)) {
                    $event['venueAddress'] = $this->sanitizeText(implode(' ', $street_parts));
                }
            }
        }

        $coords = $location['coordinates'] ?? [];
        if (!empty($coords['lat']) && !empty($coords['lng'])) {
            $event['venueCoordinates'] = $coords['lat'] . ',' . $coords['lng'];
        }
    }

    /**
     * Parse ticketing data from Wix event.
     */
    private function parseTicketing(array &$event, array $wix_event): void {
        $registration = $wix_event['registration'] ?? [];
        $external_url = $registration['external']['registration'] ?? '';
        if (!empty($external_url)) {
            $event['ticketUrl'] = esc_url_raw($external_url);
        }
    }

    /**
     * Parse image data from Wix event.
     */
    private function parseImage(array &$event, array $wix_event): void {
        $main_image = $wix_event['mainImage'] ?? [];
        if (!empty($main_image['url'])) {
            $event['imageUrl'] = esc_url_raw($main_image['url']);
        }
    }

}
