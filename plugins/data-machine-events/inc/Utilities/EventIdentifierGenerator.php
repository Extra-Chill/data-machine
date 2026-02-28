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

		// Split on common delimiters that typically separate main event from tour/opener info.
		// Dashes are already normalized to ASCII hyphen by normalize_dashes(), so we match
		// the ASCII equivalents here (not unicode originals).
		// Note: standalone hyphen omitted to preserve band names like "Run-DMC".
		$delimiters = array(
			' - ',           // ASCII hyphen with spaces (normalized from em/en dash)
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

		// Find the first delimiter occurrence to extract the headliner/core.
		$best_pos       = PHP_INT_MAX;
		$best_delimiter = null;

		foreach ( $delimiters as $delimiter ) {
			$pos = strpos( $text, $delimiter );
			if ( false !== $pos && $pos > 0 && $pos < $best_pos ) {
				$best_pos       = $pos;
				$best_delimiter = $delimiter;
			}
		}

		if ( null !== $best_delimiter ) {
			$parts = explode( $best_delimiter, $text, 2 );
			$text  = $parts[0];
		}

		// Comma-separated artist lists: treat first segment as the headliner.
		// "Comfort Club, Valories, Barb" → "Comfort Club"
		// Only split if the part before the first comma is substantial (>2 chars).
		if ( strpos( $text, ',' ) !== false ) {
			$comma_parts = explode( ',', $text, 2 );
			$first       = trim( $comma_parts[0] );
			if ( strlen( $first ) > 2 ) {
				$text = $first;
			}
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
	 * Compare two venue names for semantic match
	 *
	 * Returns true if venues are the same after normalization.
	 * Handles common variations:
	 * - "The Windjammer" vs "The Windjammer — NÜTRL Beach Stage"
	 * - "Buck's Backyard" vs "Buck's Backyard (Indoor)"
	 * - "Brooklyn Bowl - Nashville" vs "Brooklyn Bowl Nashville"
	 * - "C-Boy's Heart &amp; Soul" vs "C-Boy's Heart & Soul"
	 * - "Chess Club & Bar" vs "Chess Club & Beer Garden"
	 *
	 * Does NOT match genuinely different venues:
	 * - "The Basement" vs "The Basement East"
	 *
	 * @param string $venue1 First venue name
	 * @param string $venue2 Second venue name
	 * @return bool True if venues represent the same place
	 */
	public static function venuesMatch( string $venue1, string $venue2 ): bool {
		// Empty venues can't be confirmed as matching.
		if ( '' === $venue1 || '' === $venue2 ) {
			return false;
		}

		$norm1 = self::normalize_venue( $venue1 );
		$norm2 = self::normalize_venue( $venue2 );

		// Exact match after full normalization.
		if ( $norm1 === $norm2 ) {
			return true;
		}

		// Compare base names (parentheticals and dash-suffixes stripped).
		$base1 = self::normalize_venue( self::strip_venue_qualifiers( $venue1 ) );
		$base2 = self::normalize_venue( self::strip_venue_qualifiers( $venue2 ) );

		if ( $base1 === $base2 && strlen( $base1 ) >= 3 ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize a venue name for comparison
	 *
	 * @param string $venue Venue name
	 * @return string Normalized venue name
	 */
	private static function normalize_venue( string $venue ): string {
		// Decode HTML entities: &amp; → &, &#038; → &
		$text = html_entity_decode( $venue, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalize unicode dashes to ASCII hyphen.
		$text = self::normalize_dashes( $text );

		// Lowercase.
		$text = strtolower( $text );

		// Remove articles.
		$text = preg_replace( '/^(the|a|an)\s+/i', '', $text );

		// Remove non-alphanumeric (keep spaces for word boundaries).
		$text = preg_replace( '/[^a-z0-9\s]/', '', $text );

		// Collapse whitespace and trim.
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return $text;
	}

	/**
	 * Strip stage/room qualifiers from a raw venue name
	 *
	 * Removes parenthetical suffixes and dash-separated qualifiers BEFORE
	 * normalization, so the base venue name can be compared cleanly.
	 *
	 * Examples:
	 * - "Buck's Backyard (Indoor)" → "Buck's Backyard"
	 * - "The Windjammer (NÜTRL Beach Stage)" → "The Windjammer"
	 * - "Swanson's Warehouse — Radio Room" → "Swanson's Warehouse"
	 * - "The Windjammer — NÜTRL Beach Stage" → "The Windjammer"
	 * - "Brooklyn Bowl - Nashville" → "Brooklyn Bowl"
	 * - "The Basement East" → "The Basement East" (no qualifier to strip)
	 *
	 * @param string $venue Raw venue name
	 * @return string Venue name with qualifiers removed
	 */
	private static function strip_venue_qualifiers( string $venue ): string {
		// Decode HTML entities first so we work with clean text.
		$text = html_entity_decode( $venue, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalize dashes so we can match consistently.
		$text = self::normalize_dashes( $text );

		// Strip parenthetical suffixes: "(Indoor)", "(NÜTRL Beach Stage)"
		$text = preg_replace( '/\s*\(.*\)\s*$/', '', $text );

		// Strip dash-separated suffixes: " - Nashville", " - Radio Room"
		// Only strip if there's substantial text before the dash (≥3 chars).
		$dash_pos = strpos( $text, ' - ' );
		if ( false !== $dash_pos && $dash_pos >= 3 ) {
			$text = substr( $text, 0, $dash_pos );
		}

		return trim( $text );
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

		// Exact match.
		if ( $core1 === $core2 ) {
			return true;
		}

		// One core is a prefix of the other (covers venue name appended to title).
		// "colombian jazz experience" vs "colombian jazz experience sahara"
		$shorter = strlen( $core1 ) <= strlen( $core2 ) ? $core1 : $core2;
		$longer  = strlen( $core1 ) <= strlen( $core2 ) ? $core2 : $core1;

		if ( strlen( $shorter ) >= 5 && str_starts_with( $longer, $shorter ) ) {
			return true;
		}

		return false;
	}
}
