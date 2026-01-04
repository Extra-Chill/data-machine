<?php
/**
 * GoDaddy Calendar extractor.
 *
 * Extracts event data from GoDaddy Website Builder calendar JSON endpoints.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if (!defined('ABSPATH')) {
    exit;
}

class GoDaddyExtractor implements ExtractorInterface {

    public function canExtract(string $html): bool {
        // Check if content is GoDaddy JSON or if HTML contains GoDaddy calendar identifiers
        $is_json = strpos(trim($html), '{') === 0;
        if ($is_json) {
            $data = json_decode($html, true);
            return isset($data['events']) && is_array($data['events']);
        }

        return strpos($html, 'godaddy.com') !== false 
            || strpos($html, 'vnext-events') !== false
            || strpos($html, 'events-api.godaddy.com') !== false;
    }

    public function extract(string $html, string $source_url): array {
        $data = json_decode($html, true);
        
        // If not direct JSON, we might need to find the API URL (placeholder for future enhancement)
        // For now, we support direct JSON endpoints which is what the legacy handler did.
        if (!is_array($data) || !isset($data['events'])) {
            return [];
        }

        $events = [];
        foreach ($data['events'] as $raw_event) {
            if (!is_array($raw_event)) {
                continue;
            }

            $events[] = $this->mapEvent($raw_event, $source_url);
        }

        return $events;
    }

    public function getMethod(): string {
        return 'godaddy';
    }

    /**
     * Map GoDaddy raw event to standard format.
     */
    private function mapEvent(array $raw, string $source_url): array {
        $start = $this->parseIsoDatetime($raw['start'] ?? '');
        $end = $this->parseIsoDatetime($raw['end'] ?? '');

        return [
            'title' => sanitize_text_field($raw['title'] ?? ''),
            'description' => wp_kses_post($raw['desc'] ?? ''),
            'startDate' => $start['date'],
            'endDate' => $end['date'] ?: $start['date'],
            'startTime' => $start['time'],
            'endTime' => $end['time'],
            'venue' => sanitize_text_field($raw['location'] ?? ''),
            'ticketUrl' => '', // GoDaddy JSON usually doesn't have a direct ticket URL in this block
            'imageUrl' => '',
            'eventType' => 'Event',
            'source_url' => $source_url,
        ];
    }

    /**
     * Parse ISO datetime strings into date and time parts.
     */
    private function parseIsoDatetime(string $datetime): array {
        $datetime = trim($datetime);
        if ($datetime === '') {
            return ['date' => '', 'time' => ''];
        }

        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            return ['date' => '', 'time' => ''];
        }

        $date = date('Y-m-d', $timestamp);
        $time = date('H:i', $timestamp);

        // If it doesn't contain 'T', it's likely an all-day event or date-only
        if (!str_contains($datetime, 'T')) {
            $time = '';
        }

        return ['date' => $date, 'time' => $time];
    }
}
