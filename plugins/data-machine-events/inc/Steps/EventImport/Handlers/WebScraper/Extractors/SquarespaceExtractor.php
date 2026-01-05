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

class SquarespaceExtractor implements ExtractorInterface {

    public function canExtract(string $html): bool {
        return strpos($html, 'Static.SQUARESPACE_CONTEXT') !== false;
    }

    public function extract(string $html, string $source_url): array {
        $data = $this->fetchJsonData($html, $source_url);

        if (empty($data)) {
            return [];
        }

        $raw_items = $this->findItemsRecursive($data);
        if (empty($raw_items)) {
            // 3. Fallback to parsing HTML directly if JSON is empty
            $raw_items = $this->parseHtmlItems($html);
        }

        if (empty($raw_items)) {
            // 4. Check for Summary Blocks or Gallery items in SQUARESPACE_CONTEXT
            $raw_items = $this->findBlockItems($data);
        }

        if (empty($raw_items)) {
            // 5. Check for upcomingEvents in website (common in some templates)
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
                return $data;
            }
        }

        // 2. Fallback to extracting from HTML using string search (avoids regex backtracking)
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
     * Parse scheduling data from Squarespace item.
     */
    private function parseScheduling(array &$event, array $item): void {
        // Squarespace events often have startDate and endDate in milliseconds or ISO format
        if (!empty($item['startDate'])) {
            $this->setDateAndTime($event, $item['startDate'], 'start');
        } elseif (!empty($item['publishOn'])) {
            $this->setDateAndTime($event, $item['publishOn'], 'start');
        }

        if (!empty($item['endDate'])) {
            $this->setDateAndTime($event, $item['endDate'], 'end');
        }
        
        // Fallback: search description for dates if not found
        if (empty($event['startDate'])) {
            $this->extractDateFromText($event, $event['description']);
        }
    }

    /**
     * Set date and time from timestamp or string.
     */
    private function setDateAndTime(array &$event, $value, string $prefix): void {
        try {
            // Handle millisecond timestamps (common in JS/Squarespace)
            if (is_numeric($value) && $value > 1000000000000) {
                $value = (int)($value / 1000);
            }
            
            $dt = new \DateTime(is_numeric($value) ? "@$value" : $value);
            $event[$prefix . 'Date'] = $dt->format('Y-m-d');
            $event[$prefix . 'Time'] = $dt->format('H:i');
        } catch (\Exception $e) {
            // Ignore parse errors
        }
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

    private function sanitizeText(string $text): string {
        return sanitize_text_field(trim($text));
    }

    private function cleanHtml(string $html): string {
        return wp_kses_post(trim($html));
    }
}
