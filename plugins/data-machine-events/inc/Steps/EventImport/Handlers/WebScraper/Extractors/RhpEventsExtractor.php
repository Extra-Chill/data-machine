<?php
/**
 * RHP Events extractor.
 *
 * Extracts event data from websites using the RHP Events WordPress plugin by parsing
 * the structured HTML event listings with consistent CSS class patterns.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor;

if (!defined('ABSPATH')) {
    exit;
}

class RhpEventsExtractor extends BaseExtractor {

    public function canExtract(string $html): bool {
        return strpos($html, 'rhpSingleEvent') !== false
            && strpos($html, 'rhp-event') !== false;
    }

    public function extract(string $html, string $source_url): array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $event_nodes = $xpath->query("//*[contains(@class, 'rhpSingleEvent')]");

        if ($event_nodes->length === 0) {
            return [];
        }

        $page_venue = PageVenueExtractor::extract($html, $source_url);

        $current_year = $this->detectYear($xpath);
        $events = [];

        foreach ($event_nodes as $event_node) {
            $normalized = $this->normalizeEvent($xpath, $event_node, $current_year, $source_url);
            if (!empty($normalized['title'])) {
                $normalized = $this->mergePageVenueData($normalized, $page_venue);
                $events[] = $normalized;
            }
        }

        return $events;
    }

    /**
     * Merge page-level venue data into event for missing fields.
     *
     * @param array $event Event data
     * @param array $page_venue Page-level venue data from PageVenueExtractor
     * @return array Event with merged venue data
     */
    private function mergePageVenueData(array $event, array $page_venue): array {
        $address_fields = ['venueAddress', 'venueCity', 'venueState', 'venueZip', 'venueCountry'];

        foreach ($address_fields as $field) {
            if (empty($event[$field]) && !empty($page_venue[$field])) {
                $event[$field] = $page_venue[$field];
            }
        }

        if (empty($event['venue']) && !empty($page_venue['venue'])) {
            $event['venue'] = $page_venue['venue'];
        }

        return $event;
    }

    public function getMethod(): string {
        return 'rhp_events';
    }

    /**
     * Detect year from month separator or use current year.
     *
     * RHP Events displays month separators like "December 2025" which include the year.
     */
    private function detectYear(\DOMXPath $xpath): int {
        $month_separators = $xpath->query("//*[contains(@class, 'rhp-events-list-separator-month')]");

        foreach ($month_separators as $separator) {
            $text = trim($separator->textContent);
            if (preg_match('/\b(20\d{2})\b/', $text, $matches)) {
                return (int) $matches[1];
            }
        }

        return (int) date('Y');
    }

    /**
     * Normalize RHP event node to standardized format.
     */
    private function normalizeEvent(\DOMXPath $xpath, \DOMElement $node, int $year, string $source_url): array {
        $event = [
            'title' => $this->extractTitle($xpath, $node),
            'description' => '', // RHP list view doesn't include descriptions
        ];

        $this->parseDate($event, $xpath, $node, $year);
        $this->parseTime($event, $xpath, $node);
        $this->parseVenue($event, $xpath, $node);
        $this->parsePrice($event, $xpath, $node);
        $this->parseImage($event, $xpath, $node);
        $this->parseLinks($event, $xpath, $node, $source_url);
        $this->parseAgeRestriction($event, $xpath, $node);

        return $event;
    }

    /**
     * Extract event title.
     */
    private function extractTitle(\DOMXPath $xpath, \DOMElement $node): string {
        $selectors = [
            ".//*[contains(@class, 'rhp-event__title--list')]",
            ".//h2[contains(@class, 'eventTitle')]",
            ".//*[contains(@class, 'eventTitleDiv')]//a",
        ];

        foreach ($selectors as $selector) {
            $title_node = $xpath->query($selector, $node)->item(0);
            if ($title_node) {
                return $this->sanitizeText($title_node->textContent);
            }
        }

        return '';
    }

    /**
     * Parse date from event node.
     *
     * RHP displays dates like "Fri, Dec 26" without year.
     */
    private function parseDate(array &$event, \DOMXPath $xpath, \DOMElement $node, int $year): void {
        $date_node = $xpath->query(".//*[contains(@class, 'singleEventDate')]", $node)->item(0);
        if (!$date_node) {
            return;
        }

        $date_text = trim($date_node->textContent);
        // Pattern: "Fri, Dec 26" or "Sat, Dec 27"
        if (preg_match('/\w+,?\s*(\w+)\s+(\d{1,2})/', $date_text, $matches)) {
            $month = $matches[1];
            $day = $matches[2];

            $date_string = "{$month} {$day}, {$year}";
            $timestamp = strtotime($date_string);

            if ($timestamp !== false) {
                // If the parsed date is in the past, try next year
                if ($timestamp < strtotime('-1 day')) {
                    $timestamp = strtotime("{$month} {$day}, " . ($year + 1));
                }
                $event['startDate'] = date('Y-m-d', $timestamp);
            }
        }
    }

    /**
     * Parse time from event node.
     *
     * RHP displays times like "Doors: 7 pm | Show: 8 pm"
     */
    private function parseTime(array &$event, \DOMXPath $xpath, \DOMElement $node): void {
        $time_node = $xpath->query(".//*[contains(@class, 'rhp-event__time-text--list')]", $node)->item(0);
        if (!$time_node) {
            return;
        }

        $time_text = trim($time_node->textContent);

        // Extract doors time
        if (preg_match('/doors[:\s]*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i', $time_text, $matches)) {
            $event['doorsTime'] = $this->normalizeTime($matches[1]);
        }

        // Extract show time as start time
        if (preg_match('/show[:\s]*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i', $time_text, $matches)) {
            $event['startTime'] = $this->normalizeTime($matches[1]);
        } elseif (!empty($event['doorsTime'])) {
            // If no show time, use doors time as start
            $event['startTime'] = $event['doorsTime'];
        }
    }

    /**
     * Normalize time string to H:i format.
     */
    private function normalizeTime(string $time): string {
        $time = strtolower(trim($time));

        // Add :00 if no minutes
        if (!strpos($time, ':')) {
            $time = preg_replace('/(\d+)\s*(am|pm)?/i', '$1:00 $2', $time);
        }

        $timestamp = strtotime($time);
        if ($timestamp !== false) {
            return date('H:i', $timestamp);
        }

        return '';
    }

    /**
     * Parse venue from event node.
     *
     * RHP displays venue in the tagline area.
     */
    private function parseVenue(array &$event, \DOMXPath $xpath, \DOMElement $node): void {
        $venue_node = $xpath->query(".//*[contains(@class, 'eventTagLine')]", $node)->item(0);
        if ($venue_node) {
            $event['venue'] = $this->sanitizeText($venue_node->textContent);
        }
    }

    /**
     * Parse price from event node.
     *
     * RHP displays prices like "$12.70" or "$24.20 / Day Of : $30.05"
     */
    private function parsePrice(array &$event, \DOMXPath $xpath, \DOMElement $node): void {
        $price_node = $xpath->query(".//*[contains(@class, 'rhp-event__cost-text--list')]", $node)->item(0);
        if (!$price_node) {
            return;
        }

        $price_text = trim($price_node->textContent);

        // Extract first price (advance price)
        if (preg_match('/\$[\d,]+(?:\.\d{2})?/', $price_text, $matches)) {
            $event['price'] = $this->sanitizeText($matches[0]);
        }

        // Store full price text for context
        $event['priceDescription'] = $this->sanitizeText($price_text);
    }

    /**
     * Parse image from event node.
     */
    private function parseImage(array &$event, \DOMXPath $xpath, \DOMElement $node): void {
        $selectors = [
            ".//img[contains(@class, 'eventListImage')]",
            ".//img[contains(@class, 'rhp-event__image')]",
            ".//*[contains(@class, 'rhp-event-thumb')]//img",
        ];

        foreach ($selectors as $selector) {
            $img_node = $xpath->query($selector, $node)->item(0);
            if ($img_node && $img_node->hasAttribute('src')) {
                $event['imageUrl'] = esc_url_raw($img_node->getAttribute('src'));
                return;
            }
        }
    }

    /**
     * Parse ticket and event URLs.
     */
    private function parseLinks(array &$event, \DOMXPath $xpath, \DOMElement $node, string $source_url): void {
        // Ticket URL - look for Buy Tickets link
        $ticket_node = $xpath->query(".//*[contains(@class, 'rhp-event-cta')]//a[contains(@href, 'etix') or contains(@href, 'ticket') or contains(text(), 'Ticket')]", $node)->item(0);
        if ($ticket_node && $ticket_node->hasAttribute('href')) {
            $event['ticketUrl'] = esc_url_raw($ticket_node->getAttribute('href'));
        }

        // Event detail URL - look for More Info link or title link
        $detail_selectors = [
            ".//*[contains(@class, 'eventMoreInfo')]//a",
            ".//*[contains(@class, 'eventTitleDiv')]//a",
            ".//a[contains(@class, 'url')]",
        ];

        foreach ($detail_selectors as $selector) {
            $detail_node = $xpath->query($selector, $node)->item(0);
            if ($detail_node && $detail_node->hasAttribute('href')) {
                $href = $detail_node->getAttribute('href');
                // Make absolute if relative
                if (strpos($href, 'http') !== 0) {
                    $parsed = parse_url($source_url);
                    $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
                    $href = $base . '/' . ltrim($href, '/');
                }
                $event['eventUrl'] = esc_url_raw($href);
                break;
            }
        }
    }

    /**
     * Parse age restriction.
     */
    private function parseAgeRestriction(array &$event, \DOMXPath $xpath, \DOMElement $node): void {
        $age_node = $xpath->query(".//*[contains(@class, 'rhp-event__age-restriction')]", $node)->item(0);
        if ($age_node) {
            $event['ageRestriction'] = $this->sanitizeText($age_node->textContent);
        }
    }
}
