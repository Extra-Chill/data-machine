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
}
