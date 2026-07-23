<?php
/**
 * Event Identifier Generator Utility
 *
 * Provides consistent event identifier generation across all import handlers.
 * Normalizes event data (title, venue, date) to create stable identifiers that
 * remain consistent across minor variations in source data.
 *
 * Title normalization and fuzzy matching are delegated to the core
 * SimilarityEngine (DataMachine\Core\Similarity\SimilarityEngine).
 * Venue matching remains here — it's event-domain-specific.
 *
 * @package DataMachineEvents\Utilities
 * @since   0.2.0
 */

namespace DataMachineEvents\Utilities;

use DataMachine\Core\Similarity\SimilarityEngine;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

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
	 * Creates stable source identifier based on title, local start datetime, and venue.
	 * Normalizes text to handle variations like:
	 * - "The Blue Note" vs "Blue Note"
	 * - "Foo Bar" vs "foo bar"
	 * - Extra whitespace variations
	 *
	 * @param string $title     Event title.
	 * @param string $startDate Event start date or datetime.
	 * @param string $venue     Venue name.
	 * @param string $startTime Optional local start time.
	 * @param string $timezone  Optional venue timezone used to localize an absolute datetime.
	 * @return string MD5 hash identifier
	 */
	public static function generate( string $title, string $startDate, string $venue, string $startTime = '', string $timezone = '' ): string {
		$normalized_title = SimilarityEngine::normalizeBasic( $title );
		$normalized_venue = SimilarityEngine::normalizeBasic( $venue );
		$normalized_start = self::normalizeStartDateTime( $startDate, $startTime, $timezone );

		return md5( $normalized_title . $normalized_start . $normalized_venue );
	}

	/**
	 * Generate the date-only identifier used before time-aware source identity.
	 *
	 * Kept solely for bounded processed-history transition checks.
	 *
	 * @param string $title     Event title.
	 * @param string $startDate Event start date.
	 * @param string $venue     Venue name.
	 * @return string Legacy MD5 hash identifier.
	 */
	public static function generateLegacy( string $title, string $startDate, string $venue ): string {
		$normalized_title = SimilarityEngine::normalizeBasic( $title );
		$normalized_venue = SimilarityEngine::normalizeBasic( $venue );
		$date_only        = self::normalizeStartDateTime( $startDate );

		return md5( $normalized_title . $date_only . $normalized_venue );
	}

	/**
	 * Normalize a source start value to local wall-clock identity.
	 *
	 * Separate date/time values are interpreted as already local to the venue.
	 * Datetimes with an embedded offset are converted to the supplied venue timezone.
	 * The timezone name itself is not hashed: equivalent representations of the same
	 * local event time must produce the same source identity. Genuine date-only values
	 * retain a deterministic YYYY-MM-DD identity.
	 *
	 * @param string $startDate Event start date or datetime.
	 * @param string $startTime Optional local start time.
	 * @param string $timezone  Optional venue timezone.
	 * @return string Normalized local datetime, date, or deterministic raw fallback.
	 */
	public static function normalizeStartDateTime( string $startDate, string $startTime = '', string $timezone = '' ): string {
		$start_date = trim( $startDate );
		$start_time = trim( $startTime );

		if ( '' === $start_date ) {
			return '';
		}

		$datetime_string = '' !== $start_time ? $start_date . ' ' . $start_time : $start_date;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $start_date ) ) {
			return trim( preg_replace( '/\s+/', ' ', $datetime_string ) );
		}

		if ( '' === $start_time && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
			return $start_date;
		}

		$timezone_object = null;
		if ( '' !== $timezone ) {
			try {
				$timezone_object = new DateTimeZone( $timezone );
			} catch ( Exception $e ) {
				$timezone_object = null;
			}
		}

		try {
			$datetime = new DateTimeImmutable( $datetime_string, $timezone_object );
			if ( null !== $timezone_object && self::hasEmbeddedTimezone( $datetime_string ) ) {
				$datetime = $datetime->setTimezone( $timezone_object );
			}

			return $datetime->format( 'Y-m-d H:i' );
		} catch ( Exception $e ) {
			return trim( preg_replace( '/\s+/', ' ', $datetime_string ) );
		}
	}

	/**
	 * Determine whether a datetime carries an explicit timezone or offset.
	 *
	 * @param string $datetime Datetime value.
	 * @return bool True when timezone information is embedded.
	 */
	private static function hasEmbeddedTimezone( string $datetime ): bool {
		return (bool) preg_match( '/(?:Z|[+-]\d{2}:?\d{2}|\b[A-Za-z_]+\/[A-Za-z_]+)\s*$/', trim( $datetime ) );
	}

	/**
	 * Classify event identity confidence.
	 *
	 * High confidence requires date and venue. Medium confidence allows strong
	 * title + date, but weak/generic titles remain low confidence.
	 *
	 * @param string $title     Event title.
	 * @param string $startDate Event start date/datetime.
	 * @param string $venue     Venue name.
	 * @return string One of high|medium|low.
	 */
	public static function getIdentityConfidence( string $title, string $startDate, string $venue ): string {
		if ( '' === trim( $title ) || '' === trim( $startDate ) ) {
			return 'low';
		}

		if ( self::isLowConfidenceTitle( $title ) ) {
			return 'low';
		}

		if ( '' !== trim( $venue ) ) {
			return 'high';
		}

		if ( self::hasSpecificTitleSignal( $title ) ) {
			return 'medium';
		}

		return 'low';
	}

	/**
	 * Determine whether a title is too generic for aggressive dedupe.
	 *
	 * @param string $title Event title.
	 * @return bool True when the title is too weak/generic.
	 */
	public static function isLowConfidenceTitle( string $title ): bool {
		$normalized = SimilarityEngine::normalizeTitle( $title );

		if ( '' === $normalized ) {
			return true;
		}

		$token_count = count( array_filter( explode( ' ', $normalized ) ) );
		if ( $token_count <= 1 ) {
			return true;
		}

		if ( $token_count <= 2 && ! self::hasStrongTokenSignal( $normalized ) ) {
			return true;
		}

		if ( self::looksLikeScheduleBlob( $title ) && $token_count <= 6 ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether title has enough specificity to be medium confidence.
	 *
	 * @param string $title Event title.
	 * @return bool True when title appears specific enough.
	 */
	public static function hasSpecificTitleSignal( string $title ): bool {
		$normalized = SimilarityEngine::normalizeTitle( $title );
		if ( '' === $normalized ) {
			return false;
		}

		$token_count = count( array_filter( explode( ' ', $normalized ) ) );

		return $token_count >= 3 && ! self::isLowConfidenceTitle( $title );
	}

	/**
	 * Check whether a normalized title includes at least one strong token.
	 *
	 * Strong tokens are length-based and generic-agnostic. We deliberately avoid
	 * hardcoded event/festival words here.
	 *
	 * @param string $normalized_title Normalized title.
	 * @return bool True when any token appears specific enough.
	 */
	private static function hasStrongTokenSignal( string $normalized_title ): bool {
		$tokens = array_filter( explode( ' ', $normalized_title ) );

		foreach ( $tokens as $token ) {
			if ( strlen( $token ) >= 5 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect schedule/blob style titles structurally.
	 *
	 * This catches titles dominated by repeated time ranges or delimiter-heavy
	 * lineup formatting without hardcoding event-specific vocabulary.
	 *
	 * @param string $title Raw title.
	 * @return bool True when the title looks like a schedule blob.
	 */
	private static function looksLikeScheduleBlob( string $title ): bool {
		$raw = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( preg_match_all( '/\b\d{1,2}(?::\d{2})?\s*(?:am|pm)\b/i', $raw ) >= 2 ) {
			return true;
		}

		if ( preg_match_all( '/(?:,|\/|\||;|\s[-—]\s)/u', $raw ) >= 4 ) {
			return true;
		}

		return false;
	}

	/**
	 * Extract core identifying portion of event title
	 *
	 * Delegates to the unified SimilarityEngine which consolidates the
	 * normalization logic from this class and core's DuplicateDetection.
	 *
	 * @param string $title Event title
	 * @return string Core title for comparison
	 */
	public static function extractCoreTitle( string $title ): string {
		return SimilarityEngine::normalizeTitle( $title );
	}

	/**
	 * Compare two event titles for semantic match
	 *
	 * Delegates to the unified SimilarityEngine which runs exact,
	 * prefix, and Levenshtein strategies.
	 *
	 * @param string $title1 First event title
	 * @param string $title2 Second event title
	 * @return bool True if titles represent the same event
	 */
	public static function titlesMatch( string $title1, string $title2 ): bool {
		return SimilarityEngine::titlesMatch( $title1, $title2 )->match;
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
		$text = SimilarityEngine::normalizeDashes( $text );

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
		$text = SimilarityEngine::normalizeDashes( $text );

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
}
