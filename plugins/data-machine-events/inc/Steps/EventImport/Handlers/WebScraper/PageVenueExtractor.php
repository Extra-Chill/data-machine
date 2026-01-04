<?php
/**
 * Page Venue Extractor
 *
 * Reusable utility for extracting venue information from page HTML.
 * Looks for venue name in page title, address in footer, and timezone
 * from various page metadata sources.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

if (!defined('ABSPATH')) {
    exit;
}

class PageVenueExtractor {

    /**
     * US state abbreviations for address parsing.
     */
    private const US_STATES = 'AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY';

    /**
     * Words to filter out when extracting venue name from title.
     */
    private const TITLE_FILTER_WORDS = ['events', 'calendar', 'shows', 'upcoming events', 'concerts', 'schedule'];

    /**
     * Extract venue information from page HTML.
     *
     * @param string $html Page HTML content
     * @param string $source_url Source URL for context
     * @return array Venue data with keys: venue, venueAddress, venueCity, venueState, venueZip, venueCountry, venueTimezone
     */
    public static function extract(string $html, string $source_url = ''): array {
        $venue = [
            'venue' => '',
            'venueAddress' => '',
            'venueCity' => '',
            'venueState' => '',
            'venueZip' => '',
            'venueCountry' => 'US',
            'venueTimezone' => '',
        ];

        $venue['venue'] = self::extractVenueName($html);
        $venue['venueTimezone'] = self::extractTimezone($html);

        $address_data = self::extractAddressFromFooter($html);
        $venue = array_merge($venue, $address_data);

        return $venue;
    }

    /**
     * Extract venue name from page title.
     *
     * Parses the <title> tag and filters out common event-related words
     * to find the actual venue/site name.
     *
     * @param string $html Page HTML content
     * @return string Venue name or empty string
     */
    public static function extractVenueName(string $html): string {
        if (!preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $matches)) {
            return '';
        }

        $title = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');

        $separators = [' — ', ' - ', ' | ', ': '];
        foreach ($separators as $sep) {
            if (strpos($title, $sep) !== false) {
                $parts = explode($sep, $title);

                foreach ($parts as $part) {
                    $part = trim($part);
                    $lower = strtolower($part);

                    if (!in_array($lower, self::TITLE_FILTER_WORDS, true)) {
                        return sanitize_text_field($part);
                    }
                }
            }
        }

        return sanitize_text_field($title);
    }

    /**
     * Extract timezone from page metadata.
     *
     * Checks multiple sources:
     * - Squarespace context JSON
     * - Generic timezone JSON properties
     * - Meta tags
     *
     * @param string $html Page HTML content
     * @return string IANA timezone identifier or empty string
     */
    public static function extractTimezone(string $html): string {
        // Squarespace context
        if (preg_match('/Static\.SQUARESPACE_CONTEXT\s*=\s*\{[^}]*"timeZone"\s*:\s*"([^"]+)"/s', $html, $matches)) {
            return $matches[1];
        }

        // Generic JSON timezone property
        if (preg_match('/"timezone"\s*:\s*"([^"]+)"/i', $html, $matches)) {
            $tz = $matches[1];
            // Validate it looks like an IANA timezone
            if (strpos($tz, '/') !== false) {
                return $tz;
            }
        }

        // Meta tag timezone
        if (preg_match('/<meta[^>]+name=["\']timezone["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Extract address information from page footer.
     *
     * Looks for common footer patterns and extracts:
     * - Street address
     * - City, State, ZIP
     *
     * @param string $html Page HTML content
     * @return array Address data with keys: venueAddress, venueCity, venueState, venueZip
     */
    public static function extractAddressFromFooter(string $html): array {
        $data = [
            'venueAddress' => '',
            'venueCity' => '',
            'venueState' => '',
            'venueZip' => '',
        ];

        $footer_html = self::findFooterContent($html);

        if (empty($footer_html)) {
            return $data;
        }

        $footer_text = wp_strip_all_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $footer_html));

        $data['venueAddress'] = self::extractStreetAddress($footer_text);
        $csz = self::extractCityStateZip($footer_text);
        $data = array_merge($data, $csz);

        return $data;
    }

    /**
     * Find footer content from HTML.
     *
     * @param string $html Page HTML content
     * @return string Footer HTML content or empty string
     */
    private static function findFooterContent(string $html): string {
        // Standard <footer> tag
        if (preg_match('/<footer[^>]*>(.*?)<\/footer>/is', $html, $matches)) {
            return $matches[1];
        }

        // Section with footer ID
        if (preg_match('/<section[^>]*id="footer[^"]*"[^>]*>(.*?)<\/section>/is', $html, $matches)) {
            return $matches[1];
        }

        // Squarespace footer sections
        if (preg_match('/id="footer-sections"[^>]*>(.*?)$/is', $html, $matches)) {
            return $matches[1];
        }

        // Div with footer class
        if (preg_match('/<div[^>]*class="[^"]*footer[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Extract street address from text.
     *
     * @param string $text Text to search
     * @return string Street address or empty string
     */
    private static function extractStreetAddress(string $text): string {
        // Look for common street suffixes
        $street_pattern = '/(\d+[^,\n]+(?:Street|St|Avenue|Ave|Road|Rd|Drive|Dr|Boulevard|Blvd|Lane|Ln|Way|Court|Ct|Circle|Cir|Highway|Hwy|Pkwy|Parkway)[^,\n]*)/i';

        if (preg_match($street_pattern, $text, $matches)) {
            return sanitize_text_field(trim($matches[1]));
        }

        // Fallback: number followed by words (potential address)
        if (preg_match('/(\d+\s+[A-Za-z0-9\s]+)/m', $text, $matches)) {
            $potential = trim($matches[1]);
            if (strlen($potential) > 10 && strlen($potential) < 100) {
                return sanitize_text_field($potential);
            }
        }

        return '';
    }

    /**
     * Extract city, state, and ZIP from text.
     *
     * @param string $text Text to search
     * @return array With keys: venueCity, venueState, venueZip
     */
    private static function extractCityStateZip(string $text): array {
        $data = [
            'venueCity' => '',
            'venueState' => '',
            'venueZip' => '',
        ];

        // Pattern matches "City, ST 12345" or "City ST 12345" on a single line
        // [A-Za-z ]+ captures city name (letters and spaces only, no newlines)
        $pattern = '/^([A-Za-z ]+),?\s*(' . self::US_STATES . ')\s+(\d{5}(?:-\d{4})?)/im';

        if (preg_match($pattern, $text, $matches)) {
            $data['venueCity'] = sanitize_text_field(trim($matches[1]));
            $data['venueState'] = strtoupper(trim($matches[2]));
            $data['venueZip'] = sanitize_text_field(trim($matches[3]));
        }

        return $data;
    }
}
