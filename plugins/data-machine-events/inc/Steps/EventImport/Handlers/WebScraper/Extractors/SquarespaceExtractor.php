<?php
/**
 * Squarespace extractor.
 *
 * Extracts event data from Squarespace platform websites by parsing the embedded
 * Static.SQUARESPACE_CONTEXT JSON structure.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

if (!defined('ABSPATH')) {
    exit;
}

class SquarespaceExtractor extends BaseExtractor {

    public function canExtract(string $html): bool {
        return strpos($html, 'Static.SQUARESPACE_CONTEXT') !== false;
    }

    public function extract(string $html, string $source_url): array {
        $data = $this->fetchJsonData($html, $source_url);

        if (empty($data)) {
            return [];
        }

        // 1. Check for top-level 'upcoming' array (common in Squarespace event collections)
        if (isset($data['upcoming']) && is_array($data['upcoming']) && !empty($data['upcoming'])) {
            $raw_items = $data['upcoming'];
        }

        if (empty($raw_items)) {
            // 2. Check for top-level 'past' array if no upcoming events
            if (isset($data['past']) && is_array($data['past']) && !empty($data['past'])) {
                $raw_items = $data['past'];
            }
        }

        if (empty($raw_items)) {
            // 3. Try recursive search for items in collection structure
            $raw_items = $this->findItemsRecursive($data);
        }

        if (empty($raw_items)) {
            // 4. Fallback to parsing HTML directly if JSON is empty
            $raw_items = $this->parseHtmlItems($html);
        }

        if (empty($raw_items)) {
            // 5. Check for Summary Blocks or Gallery items in SQUARESPACE_CONTEXT
            $raw_items = $this->findBlockItems($data);
        }

        if (empty($raw_items)) {
            // 6. Check for upcomingEvents in website (common in some templates)
            if (isset($data['website']['upcomingEvents']) && is_array($data['website']['upcomingEvents'])) {
                $raw_items = $data['website']['upcomingEvents'];
            }
        }

        if (empty($raw_items)) {
            return [];
        }

        // Extract venue info from page context as fallback
        $page_venue = \DataMachineEvents\Steps\EventImport\Handlers\WebScraper\PageVenueExtractor::extract($html, $source_url);

        $events = [];
        foreach ($raw_items as $raw_item) {
            $normalized = $this->normalizeItem($raw_item, $page_venue);
            if (!empty($normalized['title'])) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    /**
     * Parse events from Squarespace HTML list view (e.g., eventlist-event).
     *
     * @param string $html Page HTML
     * @return array Array of raw item-like structures
     */
    private function parseHtmlItems(string $html): array {
        $items = [];
        
        // Find all article tags with eventlist-event class
        if (!preg_match_all('/<article[^>]+class="[^"]*eventlist-event[^"]*"[^>]*>(.*?)<\/article>/is', $html, $matches)) {
            return [];
        }

        foreach ($matches[1] as $index => $article_html) {
            $item = [
                'title' => '',
                'startDate' => '',
                'fullUrl' => '',
                'assetUrl' => '',
                'description' => '',
            ];

            // Title and Link
            if (preg_match('/<h1[^>]*class="eventlist-title"[^>]*>.*?<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $article_html, $title_matches)) {
                $item['fullUrl'] = $title_matches[1];
                $item['title'] = wp_strip_all_tags($title_matches[2]);
            }

            // Date (from time tag)
            if (preg_match('/<time[^>]+datetime="([^"]+)"/i', $article_html, $date_matches)) {
                $item['startDate'] = $date_matches[1];
            }

            // Image
            if (preg_match('/<img[^>]+data-src="([^"]+)"/i', $article_html, $img_matches)) {
                $item['assetUrl'] = $img_matches[1];
            }

            if (!empty($item['title'])) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Fetch Squarespace data via JSON API or HTML context.
     */
    private function fetchJsonData(string $html, string $source_url): array {
        // 1. Try JSON API first (most reliable for large pages)
        $json_url = add_query_arg('format', 'json', $source_url);
        $response = \DataMachine\Core\HttpClient::get($json_url, [
            'timeout' => 30,
            'context' => 'Squarespace Extractor JSON API',
        ]);

        if ($response['success'] && !empty($response['data'])) {
            $data = json_decode($response['data'], true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
                // Check if this is an events collection page (has upcoming/past arrays)
                if (isset($data['upcoming']) || isset($data['past'])) {
                    return $data;
                }

                // 2. Check if page has a Summary Block referencing an events collection
                $events_collection_url = $this->findEventsCollectionUrl($html, $source_url);
                if ($events_collection_url) {
                    $collection_response = \DataMachine\Core\HttpClient::get($events_collection_url, [
                        'timeout' => 30,
                        'context' => 'Squarespace Extractor Events Collection',
                    ]);

                    if ($collection_response['success'] && !empty($collection_response['data'])) {
                        $collection_data = json_decode($collection_response['data'], true);
                        if (json_last_error() === JSON_ERROR_NONE && !empty($collection_data)) {
                            return $collection_data;
                        }
                    }
                }

                return $data;
            }
        }

        // 3. Fallback to extracting from HTML using string search (avoids regex backtracking)
        $start_token = 'Static.SQUARESPACE_CONTEXT = ';
        $pos = strpos($html, $start_token);
        if ($pos === false) {
            return [];
        }

        $json_part = substr($html, $pos + strlen($start_token));
        
        // Find the first semicolon that isn't inside a string
        // Simple approach: look for }; or } followed by </script>
        if (preg_match('/^(\{.*?\});\s*(?:<\/script>|window)/s', $json_part, $matches)) {
            $data = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Find events collection URL from Summary Block references in HTML.
     *
     * Summary Blocks on Squarespace pages reference source collections via collectionId.
     * This method extracts that ID and constructs the collection's JSON URL.
     */
    private function findEventsCollectionUrl(string $html, string $source_url): ?string {
        // Look for Summary Block with showPastOrUpcomingEvents setting (indicates events collection)
        if (!preg_match('/data-block-json="([^"]*showPastOrUpcomingEvents[^"]*)"/', $html, $matches)) {
            return null;
        }

        $block_json = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        $block_data = json_decode($block_json, true);

        if (empty($block_data['collectionId'])) {
            return null;
        }

        // Get the collection URL by fetching the site's navigation/collections
        // For now, try common event collection paths
        $parsed = parse_url($source_url);
        $base_url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        // Try common Squarespace event collection paths
        $common_paths = [
            '/events',
            '/event-listings', 
            '/calendar',
            '/shows',
            '/upcoming-events',
            '/live-events',
        ];

        foreach ($common_paths as $path) {
            // Skip if it's the current URL
            $current_path = $parsed['path'] ?? '';
            if (rtrim($path, '/') === rtrim($current_path, '/')) {
                continue;
            }

            $test_url = $base_url . $path . '?format=json';
            $response = \DataMachine\Core\HttpClient::get($test_url, [
                'timeout' => 10,
                'context' => 'Squarespace Extractor Collection Discovery',
            ]);

            if ($response['success'] && !empty($response['data'])) {
                $test_data = json_decode($response['data'], true);
                // Check if this collection has the events we're looking for
                if (isset($test_data['upcoming']) && !empty($test_data['upcoming'])) {
                    return $test_url;
                }
            }
        }

        return null;
    }

    public function getMethod(): string {
        return 'squarespace';
    }

    /**
     * Look for items inside blocks (Summary Blocks, etc) in the data structure.
     */
    private function findBlockItems(array $data): array {
        if (isset($data['website']['upcomingEvents']) && is_array($data['website']['upcomingEvents'])) {
            return $data['website']['upcomingEvents'];
        }

        // Search for blocks that might contain items
        if (isset($data['blocks']) && is_array($data['blocks'])) {
            foreach ($data['blocks'] as $block) {
                if (isset($block['items']) && is_array($block['items'])) {
                    return $block['items'];
                }
            }
        }

        return [];
    }

    /**
     * Recursively search for Squarespace items array in JSON structure.
     * Looks for 'userItems' or 'items' within collections.
     *
     * @param array $data JSON data structure
     * @return array Items array or empty array
     */
    private function findItemsRecursive(array $data): array {
        // Specific Squarespace patterns
        if (isset($data['collection']['userItems']) && is_array($data['collection']['userItems'])) {
            return $data['collection']['userItems'];
        }
        
        if (isset($data['collection']['items']) && is_array($data['collection']['items'])) {
            return $data['collection']['items'];
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // If we find an array named 'items' or 'userItems' at any level, it might be what we want
                if (($key === 'items' || $key === 'userItems') && !empty($value) && isset($value[0]['title'])) {
                    return $value;
                }
                
                $result = $this->findItemsRecursive($value);
                if (!empty($result)) {
                    return $result;
                }
            }
        }

        return [];
    }

    /**
     * Normalize Squarespace item to standardized format.
     *
     * @param array $item Raw Squarespace item object
     * @param array $page_venue Venue info extracted from page context
     * @return array Standardized event data
     */
    private function normalizeItem(array $item, array $page_venue): array {
        $event = [
            'title' => $this->sanitizeText($item['title'] ?? ''),
            'description' => $this->cleanHtml($item['description'] ?? $item['body'] ?? ''),
            'venue' => $page_venue['venue'] ?? '',
            'venueAddress' => $page_venue['venueAddress'] ?? '',
            'venueCity' => $page_venue['venueCity'] ?? '',
            'venueState' => $page_venue['venueState'] ?? '',
            'venueZip' => $page_venue['venueZip'] ?? '',
            'venueCountry' => $page_venue['venueCountry'] ?? 'US',
            'venueTimezone' => $page_venue['venueTimezone'] ?? '',
            'source_url' => '',
        ];

        // Extract venue from event's location object (takes priority over page_venue)
        $this->parseEventLocation($event, $item);

        // Set source URL
        if (!empty($item['fullUrl'])) {
            $event['source_url'] = $item['fullUrl'];
        }

        $this->parseScheduling($event, $item);
        $this->parseTicketing($event, $item);
        $this->parseImage($event, $item);

        return $event;
    }

    /**
     * Parse venue info from event's location object.
     */
    private function parseEventLocation(array &$event, array $item): void {
        if (empty($item['location']) || !is_array($item['location'])) {
            return;
        }

        $location = $item['location'];

        if (!empty($location['addressTitle'])) {
            $event['venue'] = $this->sanitizeText($location['addressTitle']);
        }

        if (!empty($location['addressLine1'])) {
            $event['venueAddress'] = $this->sanitizeText($location['addressLine1']);
        }

        // Parse addressLine2 for city, state, zip (format: "City, ST, ZIPCODE")
        if (!empty($location['addressLine2'])) {
            $parts = array_map('trim', explode(',', $location['addressLine2']));
            if (count($parts) >= 1) {
                $event['venueCity'] = $this->sanitizeText($parts[0]);
            }
            if (count($parts) >= 2) {
                $event['venueState'] = $this->sanitizeText($parts[1]);
            }
            if (count($parts) >= 3) {
                $event['venueZip'] = $this->sanitizeText($parts[2]);
            }
        }

        if (!empty($location['addressCountry'])) {
            $event['venueCountry'] = $this->sanitizeText($location['addressCountry']);
        }
    }

    /**
     * Parse scheduling data from Squarespace item.
     *
     * Squarespace stores timestamps in UTC. This method converts them to local
     * timezone using the venueTimezone extracted from the page context.
     */
    private function parseScheduling(array &$event, array $item): void {
        $timezone = $event['venueTimezone'] ?? '';

        if (!empty($item['startDate'])) {
            $parsed = $this->parseSquarespaceTimestamp($item['startDate'], $timezone);
            $event['startDate'] = $parsed['date'];
            $event['startTime'] = $parsed['time'];
        } elseif (!empty($item['publishOn'])) {
            $parsed = $this->parseSquarespaceTimestamp($item['publishOn'], $timezone);
            $event['startDate'] = $parsed['date'];
            $event['startTime'] = $parsed['time'];
        }

        if (!empty($item['endDate'])) {
            $parsed = $this->parseSquarespaceTimestamp($item['endDate'], $timezone);
            $event['endDate'] = $parsed['date'];
            $event['endTime'] = $parsed['time'];
        }

        // Fallback: search description for dates if not found
        if (empty($event['startDate'])) {
            $this->extractDateFromText($event, $event['description']);
        }
    }

    /**
     * Parse Squarespace timestamp (milliseconds UTC or ISO string) to local timezone.
     *
     * @param mixed $value Timestamp in milliseconds, seconds, or ISO string
     * @param string $timezone IANA timezone identifier
     * @return array{date: string, time: string, timezone: string}
     */
    private function parseSquarespaceTimestamp($value, string $timezone): array {
        if (is_numeric($value)) {
            return $this->parseUtcTimestamp($value, $timezone);
        }

        return $this->parseDatetime((string) $value, $timezone);
    }

    /**
     * Parse ticketing data from Squarespace item.
     */
    private function parseTicketing(array &$event, array $item): void {
        // Check for buttonLink pattern
        if (!empty($item['button']['buttonLink'])) {
            $event['ticketUrl'] = esc_url_raw($item['button']['buttonLink']);
        } elseif (!empty($item['clickthroughUrl'])) {
            $event['ticketUrl'] = esc_url_raw($item['clickthroughUrl']);
        }
    }

    /**
     * Parse image data from Squarespace item.
     */
    private function parseImage(array &$event, array $item): void {
        if (!empty($item['assetUrl'])) {
            $event['imageUrl'] = esc_url_raw($item['assetUrl']);
        } elseif (!empty($item['image']['assetUrl'])) {
            $event['imageUrl'] = esc_url_raw($item['image']['assetUrl']);
        }
    }

    /**
     * Attempt to extract date from text if structure is missing it.
     */
    private function extractDateFromText(array &$event, string $text): void {
        if (empty($text)) return;
        
        // Simple regex for common date formats in descriptions
        // e.g., "January 15, 2026" or "Jan 15"
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';
        if (preg_match('/(' . $months . ')\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?/i', $text, $matches)) {
            $month = $matches[1];
            $day = $matches[2];
            $year = !empty($matches[3]) ? $matches[3] : date('Y');
            
            try {
                $dt = new \DateTime("$month $day $year");
                // If it's in the past, assume next year
                if ($dt < new \DateTime('today') && empty($matches[3])) {
                    $dt->modify('+1 year');
                }
                $event['startDate'] = $dt->format('Y-m-d');
            } catch (\Exception $e) {}
        }
    }

}
