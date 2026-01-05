<?php
/**
 * AEG/AXS extractor.
 *
 * Extracts event data from AEG venue JSON feeds served via aegwebprod.blob.core.windows.net.
 * Detects data-file attributes in HTML pages or parses direct JSON feed responses.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if (!defined('ABSPATH')) {
    exit;
}

class AegAxsExtractor extends BaseExtractor {

    private const JSON_HOST = 'aegwebprod.blob.core.windows.net';

    public function canExtract(string $html): bool {
        if (strpos($html, 'data-file="https://' . self::JSON_HOST) !== false) {
            return true;
        }

        if ($this->isAegJson($html)) {
            return true;
        }

        return false;
    }

    public function extract(string $html, string $source_url): array {
        $json_data = null;

        if ($this->isAegJson($html)) {
            $json_data = json_decode($html, true);
        } else {
            $json_url = $this->extractJsonUrl($html);
            if (!$json_url) {
                return [];
            }

            $response = wp_remote_get($json_url, ['timeout' => 30]);
            if (is_wp_error($response)) {
                return [];
            }

            $json_data = json_decode(wp_remote_retrieve_body($response), true);
        }

        if (!is_array($json_data) || empty($json_data['events'])) {
            return [];
        }

        $events = [];
        foreach ($json_data['events'] as $raw_event) {
            $normalized = $this->normalizeEvent($raw_event);
            if (!empty($normalized['title'])) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    public function getMethod(): string {
        return 'aeg_axs';
    }

    private function isAegJson(string $content): bool {
        $trimmed = ltrim($content);
        if ($trimmed[0] !== '{') {
            return false;
        }

        $data = json_decode($content, true);
        return is_array($data)
            && isset($data['meta']['total'])
            && isset($data['events'])
            && is_array($data['events']);
    }

    private function extractJsonUrl(string $html): ?string {
        if (preg_match('/data-file="(https:\/\/' . preg_quote(self::JSON_HOST, '/') . '[^"]+)"/', $html, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function normalizeEvent(array $raw): array {
        $event = [
            'title'       => $this->buildTitle($raw),
            'description' => $this->extractDescription($raw),
        ];

        $this->parseEventDateTime($event, $raw);
        $this->parseVenue($event, $raw);
        $this->parsePrice($event, $raw);
        $this->parseImage($event, $raw);
        $this->parseTicketing($event, $raw);
        $this->parseAgeRestriction($event, $raw);

        return $event;
    }

    private function buildTitle(array $raw): string {
        $title_data = $raw['title'] ?? [];
        $parts = [];

        if (!empty($title_data['headlinersText'])) {
            $parts[] = trim($title_data['headlinersText']);
        }

        if (!empty($title_data['supportingText'])) {
            $parts[] = 'with ' . trim($title_data['supportingText']);
        }

        if (!empty($title_data['tour'])) {
            $parts[] = '- ' . trim($title_data['tour']);
        }

        return sanitize_text_field(implode(' ', $parts));
    }

    private function extractDescription(array $raw): string {
        $description = $raw['description'] ?? $raw['bio'] ?? '';
        return wp_kses_post(trim($description));
    }

    private function parseEventDateTime(array &$event, array $raw): void {
        $timezone = $raw['eventDateTimeZone'] ?? '';

        if (!empty($raw['eventDateTimeISO'])) {
            $parsed = $this->parseUtcDatetime($raw['eventDateTimeISO'], $timezone);
            $event['startDate'] = $parsed['date'];
            $event['startTime'] = $parsed['time'];
        }

        if (!empty($raw['doorDateTime']) && !empty($timezone)) {
            $doors_parsed = $this->parseUtcDatetime($raw['doorDateTime'], $timezone);
            $event['doorsTime'] = $doors_parsed['time'];
        }

        if (!empty($timezone) && $this->isValidTimezone($timezone)) {
            $event['venueTimezone'] = $timezone;
        }
    }

    private function parseVenue(array &$event, array $raw): void {
        $venue = $raw['venue'] ?? [];

        if (!empty($venue['title'])) {
            $event['venue'] = sanitize_text_field($venue['title']);
        }

        if (!empty($venue['address'])) {
            $event['venueAddress'] = sanitize_text_field($venue['address']);
        }

        if (!empty($venue['city'])) {
            $event['venueCity'] = sanitize_text_field($venue['city']);
        }

        if (!empty($venue['state'])) {
            $event['venueState'] = sanitize_text_field($venue['state']);
        }

        if (!empty($venue['postalCode'])) {
            $event['venueZip'] = sanitize_text_field($venue['postalCode']);
        }

        if (!empty($venue['countryCode'])) {
            $event['venueCountry'] = sanitize_text_field($venue['countryCode']);
        }
    }

    private function parsePrice(array &$event, array $raw): void {
        $low = isset($raw['ticketPriceLow']) ? (float) $raw['ticketPriceLow'] : 0;
        $high = isset($raw['ticketPriceHigh']) ? (float) $raw['ticketPriceHigh'] : 0;

        if ($low <= 0 && $high <= 0) {
            return;
        }

        if ($low > 0 && $high > 0 && $low !== $high) {
            $event['price'] = '$' . number_format($low, 2) . ' - $' . number_format($high, 2);
        } elseif ($low > 0) {
            $event['price'] = '$' . number_format($low, 2);
        } elseif ($high > 0) {
            $event['price'] = '$' . number_format($high, 2);
        }
    }

    private function parseImage(array &$event, array $raw): void {
        $media = $raw['media'] ?? [];

        $preferred_sizes = ['86', '17', '18'];
        foreach ($preferred_sizes as $size_key) {
            if (!empty($media[$size_key]['file_name'])) {
                $event['imageUrl'] = esc_url_raw($media[$size_key]['file_name']);
                return;
            }
        }
    }

    private function parseTicketing(array &$event, array $raw): void {
        $ticketing = $raw['ticketing'] ?? [];

        if (!empty($ticketing['url'])) {
            $event['ticketUrl'] = esc_url_raw($ticketing['url']);
        }

        if (isset($ticketing['statusId'])) {
            $status_id = (int) $ticketing['statusId'];
            if ($status_id === 1) {
                $event['offerAvailability'] = 'InStock';
            } elseif ($status_id === 7) {
                $event['offerAvailability'] = 'SoldOut';
            }
        }
    }

    private function parseAgeRestriction(array &$event, array $raw): void {
        if (!empty($raw['age'])) {
            $event['ageRestriction'] = sanitize_text_field($raw['age']);
        }
    }
}
