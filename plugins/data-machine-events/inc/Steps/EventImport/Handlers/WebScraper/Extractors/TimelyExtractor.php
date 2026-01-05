<?php
/**
 * Timely Event Discovery extractor.
 *
 * Extracts event data from WordPress sites using the Time.ly Event Discovery plugin
 * by parsing the embedded FullCalendar.js events array and dialog elements.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if (!defined('ABSPATH')) {
    exit;
}

class TimelyExtractor extends BaseExtractor {

    public function canExtract(string $html): bool {
        $has_fullcalendar = strpos($html, 'FullCalendar.Calendar') !== false;
        $has_timely_classes = strpos($html, 'tw-cal-event') !== false
            || strpos($html, 'tw-event-dialog') !== false;
        $has_plugin_path = strpos($html, 'event-discovery') !== false;

        return $has_fullcalendar && ($has_timely_classes || $has_plugin_path);
    }

    public function extract(string $html, string $source_url): array {
        $raw_events = $this->extractEventsArray($html);
        if (empty($raw_events)) {
            return [];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $events = [];
        foreach ($raw_events as $raw_event) {
            $normalized = $this->normalizeEvent($raw_event, $xpath, $source_url);
            if (!empty($normalized['title']) && !empty($normalized['startDate'])) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    public function getMethod(): string {
        return 'timely';
    }

    /**
     * Extract events array from inline FullCalendar script.
     *
     * The plugin embeds events as a JavaScript array in the page, not valid JSON.
     * Uses regex to extract and parse each event object.
     *
     * @param string $html HTML content
     * @return array Parsed event objects
     */
    private function extractEventsArray(string $html): array {
        if (!preg_match('/events:\s*\[([\s\S]*?)\],\s*eventColor/i', $html, $matches)) {
            if (!preg_match('/events:\s*\[([\s\S]*?)\]\s*,\s*(?:eventColor|timeFormat|eventContent)/i', $html, $matches)) {
                return [];
            }
        }

        $events_content = trim($matches[1]);
        if (empty($events_content)) {
            return [];
        }

        return $this->parseJsObjectArray($events_content);
    }

    /**
     * Parse JavaScript object array notation into PHP array.
     *
     * Handles unquoted keys, single quotes, trailing commas, and embedded HTML.
     *
     * @param string $js_content JavaScript array content
     * @return array Parsed objects
     */
    private function parseJsObjectArray(string $js_content): array {
        $events = [];

        // Match individual event objects: { ... }
        // Use a pattern that captures balanced braces
        if (!preg_match_all('/\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s', $js_content, $object_matches)) {
            return [];
        }

        foreach ($object_matches[0] as $object_str) {
            $event = $this->parseJsObject($object_str);
            if (!empty($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Parse a single JavaScript object into PHP array.
     *
     * @param string $object_str JavaScript object string including braces
     * @return array Parsed key-value pairs
     */
    private function parseJsObject(string $object_str): array {
        $event = [];

        // Extract key-value pairs
        // Pattern matches: key: 'value' or key: "value" or key: value
        $patterns = [
            // String values with single quotes (may contain HTML with double quotes)
            "/(\w+)\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/s",
            // String values with double quotes
            '/(\w+)\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s',
            // Unquoted values (booleans, numbers)
            '/(\w+)\s*:\s*([^,\n\r\'\"{}]+?)(?=\s*[,}])/s',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $object_str, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $key = $match[1];
                    $value = $match[2];

                    // Don't overwrite if already set (first match wins)
                    if (!isset($event[$key])) {
                        $event[$key] = $this->cleanValue($value);
                    }
                }
            }
        }

        return $event;
    }

    /**
     * Clean extracted value.
     *
     * @param string $value Raw value
     * @return string Cleaned value
     */
    private function cleanValue(string $value): string {
        $value = trim($value);
        // Unescape escaped quotes
        $value = str_replace(["\\'", '\\"'], ["'", '"'], $value);
        // Decode HTML entities
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $value;
    }

    /**
     * Normalize raw event data to standardized format.
     *
     * @param array $raw_event Raw event from script
     * @param \DOMXPath $xpath XPath object for dialog lookup
     * @param string $source_url Source URL for context
     * @return array Normalized event data
     */
    private function normalizeEvent(array $raw_event, \DOMXPath $xpath, string $source_url): array {
        $event = [
            'title' => $this->sanitizeText($raw_event['title'] ?? ''),
        ];

        // Parse date
        if (!empty($raw_event['start'])) {
            $event['startDate'] = $this->parseDate($raw_event['start']);
        }

        // Parse time from displayTime (e.g., "Show: 8:30 PM")
        if (!empty($raw_event['displayTime'])) {
            $time = $this->parseDisplayTime($raw_event['displayTime']);
            if ($time) {
                $event['startTime'] = $time;
            }
        }

        // Parse doors time
        if (!empty($raw_event['doors'])) {
            $doors_time = $this->parseDisplayTime($raw_event['doors']);
            if ($doors_time) {
                $event['doorsTime'] = $doors_time;
            }
        }

        // Extract image URL from img tag
        if (!empty($raw_event['imageUrl'])) {
            $event['imageUrl'] = $this->extractImageSrc($raw_event['imageUrl']);
        }

        // Get dialog details (description, ticket URL, etc.)
        $event_id = $raw_event['id'] ?? '';
        if (!empty($event_id)) {
            $this->parseDialogDetails($event, $xpath, $event_id);
        }

        // Build metadata for AI context
        $metadata = $this->buildMetadata($raw_event);
        if (!empty($metadata)) {
            $event['metadata'] = $metadata;
        }

        // Event URL - construct from source URL and dialog anchor
        if (!empty($raw_event['url']) && strpos($raw_event['url'], '#') === 0) {
            $event['eventUrl'] = rtrim($source_url, '/') . $raw_event['url'];
        }

        return $event;
    }

    /**
     * Parse ISO date string.
     *
     * @param string $date Date string (YYYY-MM-DD)
     * @return string Formatted date or empty string
     */
    private function parseDate(string $date): string {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        $parsed = $this->parseDatetime($date);
        return $parsed['date'];
    }

    /**
     * Parse display time string to 24-hour format.
     *
     * @param string $time_str Time string (e.g., "Show: 8:30 PM", "7:00 PM", "8 pm")
     * @return string Time in H:i format or empty string
     */
    private function parseDisplayTime(string $time_str): string {
        // Remove "Show:" or "Doors:" prefix
        $time_str = preg_replace('/^(show|doors)\s*:\s*/i', '', trim($time_str));

        if (empty($time_str)) {
            return '';
        }

        // Handle formats like "8:30 PM", "7 PM", "8pm"
        if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $time_str, $matches)) {
            $hour = (int) $matches[1];
            $minute = !empty($matches[2]) ? $matches[2] : '00';
            $ampm = !empty($matches[3]) ? strtolower($matches[3]) : 'pm'; // Default to PM for concerts

            if ($ampm === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($ampm === 'am' && $hour === 12) {
                $hour = 0;
            }

            return sprintf('%02d:%s', $hour, $minute);
        }

        return '';
    }

    /**
     * Extract src attribute from img tag string.
     *
     * @param string $img_tag HTML img tag
     * @return string Image URL or empty string
     */
    private function extractImageSrc(string $img_tag): string {
        if (preg_match('/src=["\']([^"\']+)["\']/', $img_tag, $matches)) {
            return esc_url_raw($matches[1]);
        }
        return '';
    }

    /**
     * Parse additional details from event dialog element.
     *
     * @param array $event Event array to populate
     * @param \DOMXPath $xpath XPath object
     * @param string $event_id Event ID for dialog lookup
     */
    private function parseDialogDetails(array &$event, \DOMXPath $xpath, string $event_id): void {
        $dialog_id = 'tw-event-dialog-' . $event_id;

        // Find dialog by ID
        $dialog = $xpath->query("//*[@id='{$dialog_id}']")->item(0);
        if (!$dialog) {
            return;
        }

        // Extract description
        $description_selectors = [
            ".//*[contains(@class, 'tw-full-description')]",
            ".//*[contains(@class, 'tw-description')]",
            ".//*[contains(@class, 'tw-truncated-description')]",
        ];

        foreach ($description_selectors as $selector) {
            $desc_node = $xpath->query($selector, $dialog)->item(0);
            if ($desc_node) {
                $description = $this->cleanHtml($desc_node->textContent);
                if (!empty($description)) {
                    $event['description'] = $description;
                    break;
                }
            }
        }

        // Extract ticket URL
        $ticket_selectors = [
            ".//*[contains(@class, 'tw-buy-tix-btn')]//a",
            ".//a[contains(@href, 'ticket')]",
            ".//a[contains(@class, 'button')]",
        ];

        foreach ($ticket_selectors as $selector) {
            $ticket_node = $xpath->query($selector, $dialog)->item(0);
            if ($ticket_node && $ticket_node->hasAttribute('href')) {
                $href = $ticket_node->getAttribute('href');
                if (!empty($href) && $href !== '#') {
                    $event['ticketUrl'] = esc_url_raw($href);
                    break;
                }
            }
        }

        // Extract venue from dialog
        $venue_node = $xpath->query(".//*[contains(@class, 'tw-calendar-venue')]", $dialog)->item(0);
        if (!$venue_node) {
            $venue_node = $xpath->query(".//*[contains(@class, 'tw-cal-full-venue')]", $dialog)->item(0);
        }
        if ($venue_node) {
            $event['venue'] = $this->sanitizeText($venue_node->textContent);
        }

        // Extract price info
        $price_node = $xpath->query(".//*[contains(@class, 'tw-info-price')]", $dialog)->item(0);
        if ($price_node) {
            $event['price'] = $this->sanitizeText($price_node->textContent);
        }

        // Extract full date/time from dialog (may be more detailed)
        $datetime_node = $xpath->query(".//*[contains(@class, 'tw-event-date-complete')]", $dialog)->item(0);
        if ($datetime_node) {
            $event['dateTimeDisplay'] = $this->sanitizeText($datetime_node->textContent);
        }

        $time_complete_node = $xpath->query(".//*[contains(@class, 'tw-event-time-complete')]", $dialog)->item(0);
        if ($time_complete_node) {
            $event['timeDisplay'] = $this->sanitizeText($time_complete_node->textContent);
        }
    }

    /**
     * Build metadata array for AI context.
     *
     * Includes all non-standard fields that may be useful for AI processing.
     *
     * @param array $raw_event Raw event data
     * @return array Metadata key-value pairs
     */
    private function buildMetadata(array $raw_event): array {
        $metadata = [];

        // Include showIndicator (stage/location info)
        if (!empty($raw_event['showIndicator'])) {
            $metadata['showIndicator'] = $this->sanitizeText($raw_event['showIndicator']);
        }

        // Include allDay flag
        if (isset($raw_event['allDay'])) {
            $metadata['allDay'] = $raw_event['allDay'] === 'true' || $raw_event['allDay'] === true;
        }

        // Include any other custom fields
        $standard_fields = ['id', 'title', 'start', 'end', 'displayTime', 'doors', 'imageUrl', 'url', 'allDay', 'showIndicator'];
        foreach ($raw_event as $key => $value) {
            if (!in_array($key, $standard_fields) && !empty($value)) {
                $metadata[$key] = is_string($value) ? $this->sanitizeText($value) : $value;
            }
        }

        return $metadata;
    }

}
