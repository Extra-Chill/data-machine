<?php
/**
 * Centralized datetime parsing with timezone awareness for event imports.
 *
 * Provides consistent datetime handling across all event import handlers.
 * Single source of truth for parsing various datetime formats and converting
 * between timezones.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

use DateTime;
use DateTimeZone;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DateTimeParser {

	/**
	 * Parse UTC datetime and convert to target timezone.
	 *
	 * Use when API returns UTC times with a separate timezone field.
	 * Example: Dice.fm returns "2026-01-04T02:30:00Z" with timezone "America/Chicago"
	 *
	 * @param string $datetime UTC datetime string (e.g., "2026-01-04T02:30:00Z")
	 * @param string $timezone Target IANA timezone (e.g., "America/Chicago")
	 * @return array{date: string, time: string, timezone: string}
	 */
	public static function parseUtc( string $datetime, string $timezone ): array {
		$result = self::emptyResult();

		if ( empty( $datetime ) ) {
			return $result;
		}

		if ( ! self::isValidTimezone( $timezone ) ) {
			return $result;
		}

		try {
			$dt = new DateTime( $datetime, new DateTimeZone( 'UTC' ) );
			$dt->setTimezone( new DateTimeZone( $timezone ) );

			$result['date']     = $dt->format( 'Y-m-d' );
			$result['time']     = $dt->format( 'H:i' );
			$result['timezone'] = $timezone;
		} catch ( Exception $e ) {
			// Invalid datetime format, return empty result
		}

		return $result;
	}

	/**
	 * Parse local datetime that's already in venue timezone.
	 *
	 * Use when API returns local date/time separately.
	 * Example: Ticketmaster returns localDate "2026-01-15" and localTime "19:30"
	 *
	 * @param string $date Date string (e.g., "2026-01-15")
	 * @param string $time Time string (e.g., "19:30" or "19:30:00")
	 * @param string $timezone IANA timezone identifier
	 * @return array{date: string, time: string, timezone: string}
	 */
	public static function parseLocal( string $date, string $time, string $timezone ): array {
		$result = self::emptyResult();

		if ( empty( $date ) ) {
			return $result;
		}

		if ( ! empty( $timezone ) && ! self::isValidTimezone( $timezone ) ) {
			$timezone = '';
		}

		try {
			$datetime_string = $date;
			if ( ! empty( $time ) ) {
				$datetime_string .= ' ' . $time;
			}

			$tz = ! empty( $timezone ) ? new DateTimeZone( $timezone ) : null;
			$dt = new DateTime( $datetime_string, $tz );

			$result['date']     = $dt->format( 'Y-m-d' );
			$result['time']     = ! empty( $time ) ? $dt->format( 'H:i' ) : '';
			$result['timezone'] = $timezone;
		} catch ( Exception $e ) {
			// Invalid datetime format, return empty result
		}

		return $result;
	}

	/**
	 * Parse ISO 8601 datetime with embedded timezone.
	 *
	 * Use when datetime string includes timezone offset.
	 * Example: Eventbrite returns "2026-01-15T19:30:00-06:00"
	 *
	 * @param string $datetime ISO 8601 string (e.g., "2026-01-15T19:30:00-06:00")
	 * @return array{date: string, time: string, timezone: string}
	 */
	public static function parseIso( string $datetime ): array {
		$result = self::emptyResult();

		if ( empty( $datetime ) ) {
			return $result;
		}

		try {
			$dt = new DateTime( $datetime );
			$tz = $dt->getTimezone();

			$result['date']     = $dt->format( 'Y-m-d' );
			$result['time']     = $dt->format( 'H:i' );
			$result['timezone'] = $tz ? $tz->getName() : '';
		} catch ( Exception $e ) {
			// Invalid datetime format, return empty result
		}

		return $result;
	}

	/**
	 * Parse ICS datetime with calendar timezone context.
	 *
	 * Handles ICS-specific datetime formats:
	 * - UTC with Z suffix: "20230809T000000Z"
	 * - Plain datetime: "20230809T000000"
	 * - Uses calendar timezone (from VTIMEZONE section) when no explicit event timezone
	 *
	 * @param string $datetime ICS datetime string (e.g., "20230809T000000Z")
	 * @param string $calendar_timezone Calendar timezone from VTIMEZONE section (e.g., "America/Chicago")
	 * @return array{date: string, time: string, timezone: string}
	 */
	public static function parseIcs( string $datetime, string $calendar_timezone = 'UTC' ): array {
		$result = self::emptyResult();

		if ( empty( $datetime ) ) {
			return $result;
		}

		try {
			$dt      = new DateTime( $datetime, new DateTimeZone( 'UTC' ) );
			$tz      = $dt->getTimezone();
			$tz_name = $tz ? $tz->getName() : '';

			$has_embedded_tz = self::hasEmbeddedTimezone( $datetime );

			if ( ! $has_embedded_tz && ! empty( $calendar_timezone ) && self::isValidTimezone( $calendar_timezone ) ) {
				$dt->setTimezone( new DateTimeZone( $calendar_timezone ) );
				$tz_name = $calendar_timezone;
			}

			$result['date']     = $dt->format( 'Y-m-d' );
			$result['time']     = $dt->format( 'H:i' );
			$result['timezone'] = $tz_name;
		} catch ( Exception $e ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Parse datetime with automatic format detection.
	 *
	 * Attempts to parse any datetime string and extract timezone if present.
	 * Falls back to provided timezone if datetime has no embedded timezone.
	 *
	 * @param string $datetime Datetime string in any parseable format
	 * @param string $fallback_timezone Timezone to use if not embedded in datetime
	 * @return array{date: string, time: string, timezone: string}
	 */
	public static function parse( string $datetime, string $fallback_timezone = '' ): array {
		$result = self::emptyResult();

		if ( empty( $datetime ) ) {
			return $result;
		}

		try {
			$dt      = new DateTime( $datetime );
			$tz      = $dt->getTimezone();
			$tz_name = $tz ? $tz->getName() : '';

			// Check if timezone was actually embedded or just defaulted
			$has_embedded_tz = self::hasEmbeddedTimezone( $datetime );

			if ( ! $has_embedded_tz && ! empty( $fallback_timezone ) && self::isValidTimezone( $fallback_timezone ) ) {
				$dt->setTimezone( new DateTimeZone( $fallback_timezone ) );
				$tz_name = $fallback_timezone;
			}

			$result['date']     = $dt->format( 'Y-m-d' );
			$result['time']     = $dt->format( 'H:i' );
			$result['timezone'] = $tz_name;
		} catch ( Exception $e ) {
			// Invalid datetime format, return empty result
		}

		return $result;
	}

	/**
	 * Validate IANA timezone identifier.
	 *
	 * @param string $timezone Timezone to validate
	 * @return bool True if valid IANA timezone
	 */
	public static function isValidTimezone( string $timezone ): bool {
		if ( empty( $timezone ) ) {
			return false;
		}

		try {
			new DateTimeZone( $timezone );
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Check if datetime string has embedded timezone information.
	 *
	 * @param string $datetime Datetime string to check
	 * @return bool True if timezone is embedded
	 */
	private static function hasEmbeddedTimezone( string $datetime ): bool {
		// Z suffix means UTC, not an embedded timezone that should be preserved
		// We want to convert UTC times to calendar timezone
		if ( preg_match( '/Z$/i', $datetime ) ) {
			return false;
		}

		// Check for offset like +00:00, -05:00, +0530
		if ( preg_match( '/[+-]\d{2}:?\d{2}$/', $datetime ) ) {
			return true;
		}

		// Check for timezone abbreviation or name in string
		if ( preg_match( '/\s[A-Z]{2,5}$/', $datetime ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get empty result array.
	 *
	 * @return array{date: string, time: string, timezone: string}
	 */
	private static function emptyResult(): array {
		return array(
			'date'     => '',
			'time'     => '',
			'timezone' => '',
		);
	}
}
