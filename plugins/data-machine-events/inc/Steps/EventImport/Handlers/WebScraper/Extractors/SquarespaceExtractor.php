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
        if (!preg_match('/Static\.SQUARESPACE_CONTEXT\s*=\s*(\{.*?\});\s*<\/script>/is', $html, $matches)) {
            // Try a looser match if the above fails
            if (!preg_match('/Static\.SQUARESPACE_CONTEXT\s*=\s*(\{.*?\});/is', $html, $matches)) {
                return [];
            }
        }

        $json_content = trim($matches[1]);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return [];
        }

        $raw_items = $this->findItemsRecursive($data);
        if (empty($raw_items)) {
            return [];
        }

        $events = [];
        foreach ($raw_items as $raw_item) {
            $normalized = $this->normalizeItem($raw_item);
            if (!empty($normalized['title'])) {
                $events[] = $normalized;
            }
        }

        return $events;
    }

    public function getMethod(): string {
        return 'squarespace';
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
     * @return array Standardized event data
     */
    private function normalizeItem(array $item): array {
        $event = [
            'title' => $this->sanitizeText($item['title'] ?? ''),
            'description' => $this->cleanHtml($item['description'] ?? $item['body'] ?? ''),
        ];

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
