<?php
/**
 * Base extractor abstract class.
 *
 * Provides centralized datetime parsing utilities using DateTimeParser
 * for consistent timezone handling across all extractors.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors
 * @since   0.8.27
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors;

use DataMachineEvents\Core\DateTimeParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseExtractor implements ExtractorInterface {

	/**
	 * Parse UTC timestamp and convert to local timezone.
	 *
	 * Use when data source provides Unix timestamps in UTC.
	 * Handles both seconds and milliseconds.
	 *
	 * @param int|string $timestamp Unix timestamp (seconds or milliseconds)
	 * @param string $timezone IANA timezone identifier (e.g., "America/Chicago")
	 * @return array{date: string, time: string, timezone: string}
	 */
	protected function parseUtcTimestamp( $timestamp, string $timezone ): array {
		if ( empty( $timestamp ) || ! is_numeric( $timestamp ) ) {
			return array(
				'date'     => '',
				'time'     => '',
				'timezone' => '',
			);
		}

		$ts = (int) $timestamp;

		if ( $ts > 1000000000000 ) {
			$ts = (int) ( $ts / 1000 );
		}

		$utc_datetime = gmdate( 'Y-m-d\TH:i:s\Z', $ts );
		return DateTimeParser::parseUtc( $utc_datetime, $timezone );
	}

	/**
	 * Parse ISO 8601 datetime string with embedded timezone.
	 *
	 * Use when data source provides datetime strings like "2026-01-15T19:30:00-06:00".
	 *
	 * @param string $datetime ISO 8601 datetime string
	 * @return array{date: string, time: string, timezone: string}
	 */
	protected function parseIsoDatetime( string $datetime ): array {
		return DateTimeParser::parseIso( $datetime );
	}

	/**
	 * Parse UTC datetime string and convert to local timezone.
	 *
	 * Use when data source provides UTC strings with a separate timezone field.
	 * Example: Dice.fm returns "2026-01-04T02:30:00Z" with timezone "America/Chicago"
	 *
	 * @param string $datetime UTC datetime string
	 * @param string $timezone IANA timezone identifier
	 * @return array{date: string, time: string, timezone: string}
	 */
	protected function parseUtcDatetime( string $datetime, string $timezone ): array {
		return DateTimeParser::parseUtc( $datetime, $timezone );
	}

	/**
	 * Parse local datetime (already in venue timezone).
	 *
	 * Use when data source provides date/time that's already local.
	 * Example: Ticketmaster returns localDate "2026-01-15" and localTime "19:30"
	 *
	 * @param string $date Date string (Y-m-d)
	 * @param string $time Time string (H:i or H:i:s)
	 * @param string $timezone IANA timezone identifier
	 * @return array{date: string, time: string, timezone: string}
	 */
	protected function parseLocalDatetime( string $date, string $time, string $timezone ): array {
		return DateTimeParser::parseLocal( $date, $time, $timezone );
	}

	/**
	 * Auto-detect datetime format and parse accordingly.
	 *
	 * Use when datetime format is unknown or varies. Attempts to parse any
	 * datetime string and extract timezone if present. Falls back to provided
	 * timezone if datetime has no embedded timezone.
	 *
	 * @param string $datetime Datetime string in any format
	 * @param string $fallback_timezone Timezone to use if not embedded
	 * @return array{date: string, time: string, timezone: string}
	 */
	protected function parseDatetime( string $datetime, string $fallback_timezone = '' ): array {
		return DateTimeParser::parse( $datetime, $fallback_timezone );
	}

	/**
	 * Validate IANA timezone identifier.
	 *
	 * @param string $timezone Timezone to validate
	 * @return bool True if valid IANA timezone
	 */
	protected function isValidTimezone( string $timezone ): bool {
		return DateTimeParser::isValidTimezone( $timezone );
	}

	/**
	 * Sanitize text field.
	 *
	 * @param string $text Text to sanitize
	 * @return string Sanitized text
	 */
	protected function sanitizeText( string $text ): string {
		return sanitize_text_field( trim( $text ) );
	}

	/**
	 * Clean HTML content for descriptions.
	 *
	 * @param string $html HTML content
	 * @return string Cleaned HTML with allowed tags
	 */
	protected function cleanHtml( string $html ): string {
		return wp_kses_post( trim( $html ) );
	}

	/**
	 * Parse a clean time string to 24-hour format.
	 *
	 * Use for well-formatted time strings like "7:00 pm", "8pm", "19:30".
	 * For extracting times from longer text, use extractTimeFromText() instead.
	 *
	 * @since 0.9.17
	 * @param string $time_str Time string (e.g., "7:00 pm", "8pm", "19:30")
	 * @return string Time in H:i format or empty string if parsing fails
	 */
	protected function parseTimeString( string $time_str ): string {
		$time_str = strtolower( trim( $time_str ) );

		if ( empty( $time_str ) ) {
			return '';
		}

		$time_str = preg_replace( '/^(show|doors)\s*:\s*/i', '', $time_str );

		$timestamp = strtotime( $time_str );
		if ( false !== $timestamp ) {
			return gmdate( 'H:i', $timestamp );
		}

		if ( preg_match( '/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $time_str, $matches ) ) {
			$hour   = (int) $matches[1];
			$minute = ! empty( $matches[2] ) ? $matches[2] : '00';
			$ampm   = ! empty( $matches[3] ) ? strtolower( $matches[3] ) : 'pm';

			if ( 'pm' === $ampm && $hour < 12 ) {
				$hour += 12;
			} elseif ( 'am' === $ampm && 12 === $hour ) {
				$hour = 0;
			}

			return sprintf( '%02d:%s', $hour, $minute );
		}

		return '';
	}

	/**
	 * Extract time from descriptive text.
	 *
	 * Parses common time patterns found in event descriptions like "DOORS AT 8PM",
	 * "11AM DOORS", "SHOWTIME 9:30PM", "Show: 8:30 PM", etc.
	 *
	 * @since 0.9.17
	 * @param string $text Text to search for time patterns
	 * @return string|null Time in H:i format or null if not found
	 */
	protected function extractTimeFromText( string $text ): ?string {
		$patterns = array(
			'/DOORS\s*(?:AT\s*)?(\d{1,2})(?::(\d{2}))?\s*(AM|PM)/i',
			'/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)\s*DOORS/i',
			'/SHOW(?:TIME)?\s*(?:AT\s*)?(\d{1,2})(?::(\d{2}))?\s*(AM|PM)/i',
			'/(\d{1,2})(?::(\d{2}))?\s*(AM|PM)\s*SHOW(?:TIME)?/i',
			'/START(?:S)?\s*(?:AT\s*)?(\d{1,2})(?::(\d{2}))?\s*(AM|PM)/i',
			'/(?:^|\s)(\d{1,2})(?::(\d{2}))?\s*(AM|PM)(?:\s|$|,)/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text, $matches ) ) {
				$hour    = (int) $matches[1];
				$minutes = isset( $matches[2] ) && '' !== $matches[2] ? $matches[2] : '00';
				$period  = strtoupper( $matches[3] );

				if ( 'PM' === $period && $hour < 12 ) {
					$hour += 12;
				} elseif ( 'AM' === $period && 12 === $hour ) {
					$hour = 0;
				}

				return sprintf( '%02d:%s', $hour, $minutes );
			}
		}

		return null;
	}
}
