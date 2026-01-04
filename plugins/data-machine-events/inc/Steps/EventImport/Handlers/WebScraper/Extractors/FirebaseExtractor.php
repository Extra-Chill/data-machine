<?php
/**
 * Firebase Realtime Database extractor.
 *
 * Extracts event data from websites using Firebase Realtime Database by
 * detecting Firebase SDK scripts, extracting the database URL from embedded
 * config, and fetching events directly from the Firebase REST API.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if (!defined('ABSPATH')) {
    exit;
}

class FirebaseExtractor implements ExtractorInterface {

    public function canExtract(string $html): bool {
        return strpos($html, 'firebase-database.js') !== false
            || strpos($html, 'firebase-app.js') !== false
            || strpos($html, 'firebaseio.com') !== false;
    }

    public function extract(string $html, string $source_url): array {
        $database_url = $this->extractDatabaseUrl($html, $source_url);
        if (empty($database_url)) {
            return [];
        }

        $events_data = $this->fetchEventsFromFirebase($database_url);
        if (empty($events_data)) {
            return [];
        }

        $events = [];
        foreach ($events_data as $event_id => $event) {
            // Handle both { id: { metadata: {...} } } and { id: { ...metadata... } }
            $metadata = $event['metadata'] ?? $event;

            if (isset($metadata['isPublished']) && !$metadata['isPublished']) {
                continue;
            }

            // Some implementations might not have isPublished, look for title as minimum
            if (empty($metadata['title']) && isset($event['title'])) {
                $metadata = $event;
            }

            $normalized = $this->normalizeEvent($metadata, (string)$event_id);
            if (!empty($normalized['title'])) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    public function getMethod(): string {
        return 'firebase';
    }

    /**
     * Extract Firebase database URL from embedded JS config or external scripts.
     *
     * @param string $html Page HTML
     * @param string $source_url Source URL for resolving relative script paths
     * @return string|null Database URL or null if not found
     */
    private function extractDatabaseUrl(string $html, string $source_url): ?string {
        // 1. Try embedded config first
        if (preg_match('/databaseURL\s*:\s*["\']([^"\']+firebaseio\.com)["\']/', $html, $matches)) {
            return rtrim($matches[1], '/');
        }

        // 2. Look for local script tags and sniff them
        if (preg_match_all('/<script[^>]+src=["\']([^"\']+\.js[^"\']*)["\']/', $html, $matches)) {
            $base_url = rtrim(dirname($source_url), '/') . '/';
            $parsed_source = parse_url($source_url);
            $host_url = ($parsed_source['scheme'] ?? 'https') . '://' . ($parsed_source['host'] ?? '');

            foreach ($matches[1] as $script_src) {
                // Ignore external library CDNs to save time, focus on local scripts
                if (strpos($script_src, 'http') === 0 && strpos($script_src, $host_url) === false) {
                    continue;
                }

                $script_url = $script_src;
                if (strpos($script_src, '//') === 0) {
                    $script_url = ($parsed_source['scheme'] ?? 'https') . ':' . $script_src;
                } elseif (strpos($script_src, '/') === 0) {
                    $script_url = $host_url . $script_src;
                } elseif (strpos($script_src, 'http') !== 0) {
                    $script_url = $base_url . $script_src;
                }

                $result = HttpClient::get($script_url, [
                    'timeout' => 10,
                    'context' => 'Firebase Extractor Script Sniffing'
                ]);

                if ($result['success'] && !empty($result['data'])) {
                    if (preg_match('/databaseURL\s*:\s*["\']([^"\']+firebaseio\.com)["\']/', $result['data'], $inner_matches)) {
                        return rtrim($inner_matches[1], '/');
                    }
                }
            }
        }

        return null;
    }

    /**
     * Fetch events from Firebase REST API.
     *
     * @param string $database_url Firebase database URL
     * @return array|null Events data or null on failure
     */
    private function fetchEventsFromFirebase(string $database_url): ?array {
        $events_url = $database_url . '/events.json';

        $result = HttpClient::get($events_url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'context' => 'Firebase Extractor'
        ]);

        if (!$result['success'] || empty($result['data'])) {
            return null;
        }

        $data = json_decode($result['data'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Normalize Firebase event to standard format.
     *
     * @param array $metadata Event metadata from Firebase
     * @param string $event_id Firebase event ID
     * @return array Normalized event data
     */
    private function normalizeEvent(array $metadata, string $event_id): array {
        $event = [
            'title' => $this->sanitizeText($metadata['title'] ?? ''),
            'description' => $this->cleanDescription($metadata['longDescription'] ?? $metadata['shortDescription'] ?? $metadata['description'] ?? ''),
        ];

        $this->parseDate($event, $metadata);
        $this->parseTicketing($event, $metadata);
        $this->parseImage($event, $metadata);
        $this->parsePrice($event, $metadata);

        $event['sourceId'] = $event_id;

        return $event;
    }

    /**
     * Parse date from Firebase JS date string.
     *
     * Firebase stores dates like: "Wed Sep 11 2024 18:30:00 GMT-0500 (Central Daylight Time)"
     */
    private function parseDate(array &$event, array $metadata): void {
        $date_string = $metadata['date'] ?? '';
        if (empty($date_string)) {
            return;
        }

        $date_string = preg_replace('/\s*\([^)]+\)\s*$/', '', $date_string);

        try {
            $dt = new \DateTime($date_string);
            $event['startDate'] = $dt->format('Y-m-d');
            $event['startTime'] = $dt->format('H:i');
        } catch (\Exception $e) {
            $timestamp = strtotime($date_string);
            if ($timestamp !== false) {
                $event['startDate'] = date('Y-m-d', $timestamp);
                $event['startTime'] = date('H:i', $timestamp);
            }
        }
    }

    /**
     * Parse ticketing data from Firebase event.
     */
    private function parseTicketing(array &$event, array $metadata): void {
        if (!empty($metadata['ticketLink'])) {
            $event['ticketUrl'] = esc_url_raw($metadata['ticketLink']);
        }

        if (!empty($metadata['fbLink'])) {
            $event['facebookUrl'] = esc_url_raw($metadata['fbLink']);
        }
    }

    /**
     * Parse image from Firebase event.
     */
    private function parseImage(array &$event, array $metadata): void {
        if (!empty($metadata['posterUrl'])) {
            $event['imageUrl'] = esc_url_raw($metadata['posterUrl']);
        }
    }

    /**
     * Parse price from Firebase event.
     */
    private function parsePrice(array &$event, array $metadata): void {
        $price = $metadata['door'] ?? $metadata['price'] ?? $metadata['cost'] ?? '';
        if (!empty($price)) {
            $event['price'] = $this->sanitizeText((string)$price);
        }
    }

    private function sanitizeText(string $text): string {
        return sanitize_text_field(trim($text));
    }

    private function cleanDescription(string $text): string {
        return wp_kses_post(trim($text));
    }
}
