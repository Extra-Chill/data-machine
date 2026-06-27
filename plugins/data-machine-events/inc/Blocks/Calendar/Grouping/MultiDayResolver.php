<?php
/**
 * Multi-Day Event Resolver
 *
 * Determines whether events span multiple days and generates
 * the full date range for multi-day expansions. Handles the
 * next-day cutoff logic for late-night shows.
 *
 * @package DataMachineEvents\Blocks\Calendar\Grouping
 * @since   0.14.0
 */

namespace DataMachineEvents\Blocks\Calendar\Grouping;

use DateTime;
use DateTimeZone;
use DataMachineEvents\Admin\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MultiDayResolver {

	/**
	 * Check if an event spans multiple days.
	 *
	 * Events ending before the next_day_cutoff time on the following day
	 * are treated as single-day events (typical late-night shows).
	 *
	 * @param array $event_data Event data array.
	 * @return bool True if event spans multiple days.
	 */
	public static function is_multi_day( array $event_data ): bool {
		$start_date = $event_data['startDate'] ?? '';
		$end_date   = $event_data['endDate'] ?? '';
		$end_time   = $event_data['endTime'] ?? '';

		if ( empty( $start_date ) || empty( $end_date ) ) {
			return false;
		}

		if ( $start_date === $end_date ) {
			return false;
		}

		// Placeholder / TBD dates (e.g. "2026-07-??") are not parseable and
		// cannot be expanded into a date range. Treat them as single-day so
		// the calendar still renders rather than throwing a fatal.
		if ( ! self::is_valid_date( $start_date ) || ! self::is_valid_date( $end_date ) ) {
			return false;
		}

		$start = new DateTime( $start_date );
		$end   = new DateTime( $end_date );
		$diff  = $start->diff( $end )->days;

		if ( 1 === $diff && ! empty( $end_time ) ) {
			$cutoff         = Settings_Page::get_next_day_cutoff();
			$cutoff_parts   = explode( ':', $cutoff );
			$cutoff_seconds = ( (int) $cutoff_parts[0] * 3600 ) + ( (int) ( $cutoff_parts[1] ?? 0 ) * 60 );

			$end_time_parts = explode( ':', $end_time );
			$end_seconds    = ( (int) $end_time_parts[0] * 3600 ) + ( (int) ( $end_time_parts[1] ?? 0 ) * 60 );

			if ( $end_seconds < $cutoff_seconds ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Generate all dates an event spans.
	 *
	 * @param string       $start_date Start date (Y-m-d).
	 * @param string       $end_date   End date (Y-m-d).
	 * @param DateTimeZone $event_tz   Event timezone.
	 * @return array Array of date strings (Y-m-d).
	 */
	public static function get_date_range( string $start_date, string $end_date, DateTimeZone $event_tz ): array {
		$dates = array();

		// Guard against placeholder / malformed dates that would throw a
		// DateMalformedStringException. is_multi_day() already filters these,
		// but get_date_range() is a public entry point in its own right.
		if ( ! self::is_valid_date( $start_date ) || ! self::is_valid_date( $end_date ) ) {
			if ( self::is_valid_date( $start_date ) ) {
				$dates[] = $start_date;
			}

			return $dates;
		}

		$start = new DateTime( $start_date, $event_tz );
		$end   = new DateTime( $end_date, $event_tz );

		$max_days  = 90;
		$day_count = 0;

		while ( $start <= $end && $day_count < $max_days ) {
			$dates[] = $start->format( 'Y-m-d' );
			$start->modify( '+1 day' );
			++$day_count;
		}

		return $dates;
	}

	/**
	 * Strictly validate a Y-m-d date string.
	 *
	 * Rejects placeholder / TBD values (e.g. "2026-07-??") and any string
	 * that does not represent a real calendar date, so callers can avoid
	 * passing unparseable input into DateTime and triggering a
	 * DateMalformedStringException.
	 *
	 * @param string $date Date string to validate.
	 * @return bool True when $date is a valid Y-m-d calendar date.
	 */
	private static function is_valid_date( string $date ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}
}
