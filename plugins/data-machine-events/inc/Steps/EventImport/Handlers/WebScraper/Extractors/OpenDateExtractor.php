<?php
/**
 * OpenDate extractor.
 *
 * Extracts event data from OpenDate.io calendar pages by parsing listing HTML
 * to find event detail URLs, then fetching each detail page to extract complete
 * Schema.org JSON-LD event data including full venue address and coordinates.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachine\Core\HttpClient;

if (!defined('ABSPATH')) {
    exit;
}

class OpenDateExtractor implements ExtractorInterface {

    public function canExtract(string $html): bool {
        return strpos($html, 'confirm-card') !== false
            || strpos($html, 'ODEmbed') !== false;
    }

    public function extract(string $html, string $source_url): array {
        $event_urls = $this->parseListingPage($html);
        if (empty($event_urls)) {
            return [];
        }

        $events = [];
        foreach ($event_urls as $event_url) {
            $detail_html = $this->fetchDetailPage($event_url);
            if (empty($detail_html)) {
                continue;
            }

            $jsonld = $this->extractJsonLd($detail_html);
            if (empty($jsonld)) {
                continue;
            }

            $coordinates = $this->extractCoordinates($detail_html);
            $event = $this->normalizeEvent($jsonld, $coordinates, $event_url);

            if (!empty($event['title'])) {
                $events[] = $event;
            }
        }

        return $events;
    }

    public function getMethod(): string {
        return 'opendate';
    }

    /**
     * Parse listing page HTML to extract event detail page URLs.
     *
     * @param string $html Listing page HTML
     * @return array Event detail page URLs
     */
    private function parseListingPage(string $html): array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<meta charset="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $urls = [];

        $cards = $xpath->query('//*[contains(@class, "confirm-card")]//a[contains(@class, "stretched-link")]');
        if ($cards === false) {
            return [];
        }

        foreach ($cards as $card) {
            if (!($card instanceof \DOMElement)) {
                continue;
            }

            $href = $card->getAttribute('href');
            if (empty($href)) {
                continue;
            }

            if (strpos($href, 'http') !== 0) {
                $href = 'https://app.opendate.io' . $href;
            }

            $urls[] = $href;
        }

        return $urls;
    }

    /**
     * Fetch event detail page HTML.
     *
     * @param string $url Detail page URL
     * @return string|null HTML content or null on failure
     */
    private function fetchDetailPage(string $url): ?string {
        $result = HttpClient::get($url, [
            'timeout' => 30,
            'browser_mode' => true,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
            'context' => 'OpenDate Extractor'
        ]);

        if (!$result['success'] || empty($result['data'])) {
            return null;
        }

        return $result['data'];
    }

    /**
     * Extract JSON-LD Event data from detail page HTML.
     *
     * @param string $html Detail page HTML
     * @return array|null Parsed JSON-LD Event data or null
     */
    private function extractJsonLd(string $html): ?array {
        if (!preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $json_content) {
            $data = json_decode(trim($json_content), true);
            if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
                continue;
            }

            if (isset($data['@type']) && $data['@type'] === 'Event') {
                return $data;
            }
        }

        return null;
    }

    /**
     * Extract coordinates from Google Static Maps URL in HTML.
     *
     * @param string $html Detail page HTML
     * @return string|null Coordinates as "lat,lng" or null
     */
    private function extractCoordinates(string $html): ?string {
        if (preg_match('/maps\.googleapis\.com\/maps\/api\/staticmap\?center=([0-9.-]+),([0-9.-]+)/', $html, $matches)) {
            return $matches[1] . ',' . $matches[2];
        }

        return null;
    }

    /**
     * Normalize JSON-LD Event to standard format.
     *
     * @param array $jsonld JSON-LD Event data
     * @param string|null $coordinates Extracted coordinates
     * @param string $source_url Event detail page URL
     * @return array Normalized event data
     */
    private function normalizeEvent(array $jsonld, ?string $coordinates, string $source_url): array {
        $event = [
            'title' => $jsonld['name'] ?? '',
            'description' => $jsonld['description'] ?? '',
        ];

        $this->parseDates($event, $jsonld);
        $this->parseLocation($event, $jsonld, $coordinates);
        $this->parseOffers($event, $jsonld, $source_url);
        $this->parsePerformers($event, $jsonld);
        $this->parseImage($event, $jsonld);

        return $event;
    }

    /**
     * Parse date/time from JSON-LD event.
     */
    private function parseDates(array &$event, array $jsonld): void {
        if (!empty($jsonld['startDate'])) {
            $start_datetime = $jsonld['startDate'];
            $event['startDate'] = date('Y-m-d', strtotime($start_datetime));
            $parsed_time = date('H:i', strtotime($start_datetime));
            $event['startTime'] = $parsed_time !== '00:00' ? $parsed_time : '';
        }

        if (!empty($jsonld['endDate'])) {
            $end_datetime = $jsonld['endDate'];
            $event['endDate'] = date('Y-m-d', strtotime($end_datetime));
            $event['endTime'] = date('H:i', strtotime($end_datetime));
        }
    }

    /**
     * Parse location from JSON-LD event.
     */
    private function parseLocation(array &$event, array $jsonld, ?string $coordinates): void {
        if (empty($jsonld['location'])) {
            return;
        }

        $location = $jsonld['location'];
        $event['venue'] = $location['name'] ?? '';

        if (!empty($location['address'])) {
            $address = $location['address'];
            $event['venueAddress'] = $address['streetAddress'] ?? '';
            $event['venueCity'] = $address['addressLocality'] ?? '';
            $event['venueState'] = $address['addressRegion'] ?? '';
            $event['venueZip'] = $address['postalCode'] ?? '';
            $event['venueCountry'] = $address['addressCountry'] ?? '';
        }

        if (!empty($location['geo'])) {
            $geo = $location['geo'];
            $lat = $geo['latitude'] ?? '';
            $lng = $geo['longitude'] ?? '';
            if ($lat && $lng) {
                $event['venueCoordinates'] = $lat . ',' . $lng;
            }
        } elseif ($coordinates) {
            $event['venueCoordinates'] = $coordinates;
        }
    }

    /**
     * Parse offers/pricing from JSON-LD event.
     */
    private function parseOffers(array &$event, array $jsonld, string $source_url): void {
        if (empty($jsonld['offers'])) {
            $event['ticketUrl'] = $source_url;
            return;
        }

        $offers = $jsonld['offers'];
        if (is_array($offers) && isset($offers[0])) {
            $offers = $offers[0];
        }

        $event['price'] = $offers['price'] ?? '';
        $event['ticketUrl'] = $offers['url'] ?? $source_url;
    }

    /**
     * Parse performers from JSON-LD event.
     */
    private function parsePerformers(array &$event, array $jsonld): void {
        if (empty($jsonld['performer'])) {
            return;
        }

        $performers = $jsonld['performer'];
        if (!is_array($performers)) {
            $event['performer'] = $performers;
            return;
        }

        if (isset($performers['name'])) {
            $event['performer'] = $performers['name'];
            return;
        }

        $names = [];
        foreach ($performers as $performer) {
            if (isset($performer['name'])) {
                $names[] = $performer['name'];
            }
        }

        if (!empty($names)) {
            $event['performer'] = implode(', ', $names);
        }
    }

    /**
     * Parse image from JSON-LD event.
     */
    private function parseImage(array &$event, array $jsonld): void {
        if (empty($jsonld['image'])) {
            return;
        }

        $image = $jsonld['image'];
        if (is_array($image)) {
            $event['imageUrl'] = $image[0] ?? '';
        } else {
            $event['imageUrl'] = $image;
        }
    }
}
