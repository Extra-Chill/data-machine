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

if ( ! defined( 'ABSPATH' ) ) {
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
	public static function generate( string $title, string $startDate, string $venue ): string {
		$normalized_title = self::normalize_text( $title );
		$normalized_venue = self::normalize_text( $venue );

		return md5( $normalized_title . $startDate . $normalized_venue );
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
	private static function normalize_text( string $text ): string {
		// Normalize unicode dashes to ASCII hyphen
		$text = self::normalize_dashes( $text );

		// Lowercase
		$text = strtolower( $text );

		// Trim and collapse whitespace
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		// Remove common article prefixes
		$text = preg_replace( '/^(the|a|an)\s+/i', '', $text );

		return $text;
	}

	/**
	 * Normalize unicode dash characters to ASCII hyphen
	 *
	 * Scraped titles commonly use en dashes (–), em dashes (—), or other
	 * unicode dash variants interchangeably with ASCII hyphens (-).
	 * Normalizing prevents false dedup mismatches like:
	 * "bbno$ - The Internet Explorer Tour" vs "bbno$ – The Internet Explorer Tour"
	 *
	 * @param string $text Input text
	 * @return string Text with all dashes normalized to ASCII hyphen
	 */
	private static function normalize_dashes( string $text ): string {
		$unicode_dashes = array(
			"\u{2010}", // hyphen
			"\u{2011}", // non-breaking hyphen
			"\u{2012}", // figure dash
			"\u{2013}", // en dash
			"\u{2014}", // em dash
			"\u{2015}", // horizontal bar
			"\u{FE58}", // small em dash
			"\u{FE63}", // small hyphen-minus
			"\u{FF0D}", // fullwidth hyphen-minus
		);

		return str_replace( $unicode_dashes, '-', $text );
	}

	/**
	 * Extract core identifying portion of event title
	 *
	 * Strips tour names, supporting acts, and normalizes for comparison.
	 * Used for fuzzy matching across sources with different title formats.
	 *
	 * Examples:
	 * - "Andy Frasco & the U.N. — Growing Pains Tour with Candi Jenkins" → "andy frasco u.n."
	 * - "Andy Frasco & The U.N." → "andy frasco u.n."
	 * - "Jazz Night: Holiday Special" → "jazz night"
	 *
	 * @param string $title Event title
	 * @return string Core title for comparison
	 */
	public static function extractCoreTitle( string $title ): string {
		$text = strtolower( self::normalize_dashes( $title ) );

		// Split on common delimiters that typically separate main event from tour/opener info
		// Note: standalone hyphen omitted to preserve band names like "Run-DMC"
		$delimiters = array(
			' — ',           // em dash with spaces
			'—',             // em dash
			' – ',           // en dash with spaces
			'–',             // en dash
			' : ',           // colon with spaces
			': ',            // colon
			' | ',           // pipe
			'|',             // pipe
			' featuring ',
			' feat. ',
			' feat ',
			' ft. ',
			' ft ',
			' with ',
			' w/ ',
			' + ',
		);

		// Find the rightmost delimiter position to maximize extracted title
		$best_pos       = -1;
		$best_delimiter = null;

		foreach ( $delimiters as $delimiter ) {
			$pos = strpos( $text, $delimiter );
			if ( false !== $pos && $pos > $best_pos ) {
				$best_pos       = $pos;
				$best_delimiter = $delimiter;
			}
		}

		if ( null !== $best_delimiter ) {
			$parts = explode( $best_delimiter, $text, 2 );
			$text  = $parts[0];
		}

		// Remove articles at word boundaries
		$text = preg_replace( '/\b(the|a|an)\b/i', '', $text );

		// Remove non-alphanumeric characters (keep spaces)
		$text = preg_replace( '/[^a-z0-9\s]/i', '', $text );

		// Collapse whitespace and trim
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		// If result is too short, return normalized original instead
		if ( strlen( $text ) < 3 ) {
			return self::normalize_text( $title );
		}

		return $text;
	}

	/**
	 * Compare two event titles for semantic match
	 *
	 * Returns true if core titles match after extraction and normalization.
	 * Used for cross-source duplicate detection where titles may vary.
	 *
	 * @param string $title1 First event title
	 * @param string $title2 Second event title
	 * @return bool True if titles represent the same event
	 */
	public static function titlesMatch( string $title1, string $title2 ): bool {
		$core1 = self::extractCoreTitle( $title1 );
		$core2 = self::extractCoreTitle( $title2 );

		return $core1 === $core2;
	}
}
