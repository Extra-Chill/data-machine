<?php
/**
 * Event Identifier Generator Utility
 *
 * Provides consistent event identifier generation across all import handlers.
 * Normalizes event data (title, venue, date) to create stable identifiers that
 * remain consistent across minor variations in source data.
 *
 * @package DataMachineEvents\Utilities
 * @since   0.2.0
 */

namespace DataMachineEvents\Utilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Event identifier generation with normalization
 */
class EventIdentifierGenerator {

    /**
     * Generate event identifier from normalized event data
     *
     * Creates stable identifier based on title, start date, and venue.
     * Normalizes text to handle variations like:
     * - "The Blue Note" vs "Blue Note"
     * - "Foo Bar" vs "foo bar"
     * - Extra whitespace variations
     *
     * @param string $title     Event title
     * @param string $startDate Event start date (YYYY-MM-DD)
     * @param string $venue     Venue name
     * @return string MD5 hash identifier
     */
    public static function generate(string $title, string $startDate, string $venue): string {
        $normalized_title = self::normalize_text($title);
        $normalized_venue = self::normalize_text($venue);

        return md5($normalized_title . $startDate . $normalized_venue);
    }

    /**
     * Normalize text for consistent identifier generation
     *
     * Applies transformations:
     * - Lowercase
     * - Trim whitespace
     * - Collapse multiple spaces to single space
     * - Remove common article prefixes ("the ", "a ", "an ")
     *
     * @param string $text Text to normalize
     * @return string Normalized text
     */
    private static function normalize_text(string $text): string {
        // Lowercase
        $text = strtolower($text);

        // Trim and collapse whitespace
        $text = trim(preg_replace('/\s+/', ' ', $text));

        // Remove common article prefixes
        $text = preg_replace('/^(the|a|an)\s+/i', '', $text);

        return $text;
    }
}
