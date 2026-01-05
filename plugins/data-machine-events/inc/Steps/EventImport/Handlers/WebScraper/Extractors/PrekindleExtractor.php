<?php
/**
 * Prekindle extractor.
 *
 * Extracts event data from Prekindle platform by detecting the org_id
 * and fetching the widget content which contains both JSON-LD and precise times in HTML.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if (!defined('ABSPATH')) {
    exit;
}

class PrekindleExtractor extends BaseExtractor {

    const WIDGET_BASE = 'https://www.prekindle.com/organizer-grid-widget-main/id/';

    public function canExtract(string $html): bool {
        return strpos($html, 'prekindle.com') !== false 
            || strpos($html, 'pk-cal-widget') !== false
            || strpos($html, 'data-org-id') !== false;
    }

    public function extract(string $html, string $source_url): array {
        $org_id = $this->extractOrgId($html, $source_url);
        
        if (empty($org_id)) {
            return [];
        }

        $widget_html = $this->fetchWidgetHtml($org_id);
        if (empty($widget_html)) {
            return [];
        }

        $raw_events = $this->extractJsonLdEvents($widget_html);
        if (empty($raw_events)) {
            return [];
        }

        $times_by_title = $this->extractEventTimesByTitle($widget_html);
        
        $events = [];
        foreach ($raw_events as $raw_event) {
            $normalized = $this->normalizeEvent($raw_event, $times_by_title);
            if (!empty($normalized['title'])) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    public function getMethod(): string {
        return 'prekindle';
    }

    /**
     * Extract org_id from HTML or URL.
     */
    private function extractOrgId(string $html, string $source_url): ?string {
        // 1. Check URL (e.g. prekindle.com/organizer/ID or similar)
        if (preg_match('/prekindle\.com\/[^\/]+\/(\d+)/', $source_url, $matches)) {
            return $matches[1];
        }

        // 2. Check HTML for data-org-id (common in widgets)
        if (preg_match('/data-org-id=["\'](\d+)["\']/', $html, $matches)) {
            return $matches[1];
        }

        // 3. Check for widget loader script URL
        if (preg_match('/prekindle\.com\/widget\/id\/(\d+)/', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Fetch the widget HTML which contains the JSON-LD and time blocks.
     */
    private function fetchWidgetHtml(string $org_id): string {
        $url = self::WIDGET_BASE . urlencode($org_id) . '/?fp=false&thumbs=false&style=null';

        $result = HttpClient::get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'text/html',
            ],
            'context' => 'Prekindle Extractor',
        ]);

        return ($result['success'] && $result['status_code'] === 200) ? $result['data'] : '';
    }

    /**
     * Extract JSON-LD events from the widget HTML.
     */
    private function extractJsonLdEvents(string $html): array {
        if (!preg_match('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return [];
        }

        $data = json_decode($matches[1], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return $data['@graph'];
        }

        return is_array($data) ? (isset($data[0]) ? $data : [$data]) : [];
    }

    /**
     * Extract event times from the HTML blocks as the JSON-LD often lacks them.
     */
    private function extractEventTimesByTitle(string $html): array {
        $map = [];
        if (!preg_match_all('#<div[^>]+name=["\']pk-eachevent["\'][^>]*>#i', $html, $starts, PREG_OFFSET_CAPTURE)) {
            return $map;
        }

        $event_blocks = $starts[0];
        $count = count($event_blocks);

        for ($i = 0; $i < $count; $i++) {
            $start_offset = $event_blocks[$i][1];
            $end_offset = ($i + 1 < $count) ? $event_blocks[$i + 1][1] : strlen($html);
            $block_html = substr($html, $start_offset, $end_offset - $start_offset);

            if (preg_match('#<div[^>]*class=["\']pk-headline["\'][^>]*>(.*?)</div>#is', $block_html, $title_match)) {
                $title = trim(wp_strip_all_tags(html_entity_decode($title_match[1], ENT_QUOTES | ENT_HTML5)));
                if (!empty($title) && preg_match('#<div[^>]*class=["\']pk-times["\'][^>]*>\s*<div[^>]*>(.*?)</div>#is', $block_html, $time_match)) {
                    $time_text = trim(wp_strip_all_tags(html_entity_decode($time_match[1], ENT_QUOTES | ENT_HTML5)));
                    $map[strtolower($title)] = $time_text;
                }
            }
        }

        return $map;
    }

    /**
     * Normalize Prekindle event to standard format.
     */
    private function normalizeEvent(array $raw, array $times_map): array {
        $title = $raw['name'] ?? '';
        $start_date = $raw['startDate'] ?? '';
        $end_date = $raw['endDate'] ?? '';
        
        $start_time = '';
        $title_key = strtolower(trim($title));
        if (isset($times_map[$title_key])) {
            $start_time = $this->parseStartTime($times_map[$title_key]);
        }

        $location = $raw['location'] ?? [];
        $address = $location['address'] ?? [];
        $offers = $raw['offers'] ?? [];

        return [
            'title' => sanitize_text_field($title),
            'description' => wp_kses_post($raw['description'] ?? ''),
            'startDate' => $this->formatDate($start_date),
            'endDate' => $this->formatDate($end_date),
            'startTime' => $start_time,
            'endTime' => '',
            'venue' => sanitize_text_field($location['name'] ?? ''),
            'venueAddress' => sanitize_text_field($address['streetAddress'] ?? ''),
            'venueCity' => sanitize_text_field($address['addressLocality'] ?? ''),
            'venueState' => sanitize_text_field($address['addressRegion'] ?? ''),
            'venueZip' => sanitize_text_field($address['postalCode'] ?? ''),
            'venueCountry' => sanitize_text_field($address['addressCountry'] ?? 'US'),
            'ticketUrl' => esc_url_raw($offers['url'] ?? ($raw['url'] ?? '')),
            'imageUrl' => esc_url_raw($raw['image'] ?? ''),
            'price' => sanitize_text_field($offers['price'] ?? ($offers['lowPrice'] ?? '')),
            'organizer' => sanitize_text_field($raw['organizer']['name'] ?? ($raw['organizer'] ?? '')),
        ];
    }

    private function formatDate(string $date_str): string {
        if (empty($date_str)) return '';
        try {
            return (new \DateTime($date_str))->format('Y-m-d');
        } catch (\Exception $e) {
            return '';
        }
    }

    private function parseStartTime(string $time_text): string {
        if (preg_match('/(?:Start|Doors)\s+(\d{1,2}:\d{2}\s*(?:am|pm))/i', $time_text, $m)) {
            $ts = strtotime($m[1]);
            return $ts ? date('H:i', $ts) : '';
        }
        if (preg_match('/(\d{1,2}:\d{2}\s*(?:am|pm))/i', $time_text, $m)) {
            $ts = strtotime($m[1]);
            return $ts ? date('H:i', $ts) : '';
        }
        return '';
    }
}
