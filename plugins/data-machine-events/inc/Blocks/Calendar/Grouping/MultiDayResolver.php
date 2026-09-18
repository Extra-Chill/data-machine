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
use DataMachineEvents\Core\DateTimeParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MultiDayResolver {

	/**
	 * Maximum start→end day distance that is expanded as one continuous
	 * event. Genuine continuous multi-day events on a music calendar
	 * (festivals, residencies) finish within about two weeks; spans beyond
	 * this are, in production data, recurring series whose series-wide end
	 * datetime leaked onto a single occurrence row at ingest (#199).
	 *
	 * Measured 2026-09-18: of 45 upcoming published rows spanning more than
	 * 48 hours, 30 span more than 7 days, 16 more than 30 days, and 6 more
	 * than 180 days — all recurring shapes (open mics, jams, seasonal
	 * promos), none genuine continuous festivals.
	 */
	const CONTINUOUS_SPAN_LIMIT = 14;

	/**
	 * When a span exceeds CONTINUOUS_SPAN_LIMIT and cannot be derived as a
	 * weekly recurrence, the event renders on at most this many leading
	 * calendar days instead of its full range. Bounds the damage a bad row
	 * can do (281 junk days → at most 7) without inventing a pattern the
	 * data does not support.
	 */
	const TRUNCATED_SPAN_WINDOW = 7;

	/**
	 * Check if an event spans multiple days.
	 *
	 * Events ending before the next_day_cutoff time on the following day
	 * are treated as single-day events (typical late-night shows). See
	 * is_same_night_end() for the shared same-night rule. See #833.
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
		if ( ! DateTimeParser::isValidYmd( $start_date ) || ! DateTimeParser::isValidYmd( $end_date ) ) {
			return false;
		}

		$start = new DateTime( $start_date );
		$end   = new DateTime( $end_date );
		$diff  = $start->diff( $end )->days;

		if ( 1 === $diff && self::is_same_night_end( $start_date, $end_date, $end_time ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if an event's end falls on the same "night" as its start.
	 *
	 * The shared same-night rule (#833): an end on the calendar day after
	 * the start, with a time-of-day before the next-day cutoff (default
	 * 05:00 site time via Settings_Page::get_next_day_cutoff()), is a
	 * late-night continuation of the same evening — a 9 PM → 2 AM bar show,
	 * not a multi-day event. Same-calendar-day ends are trivially the same
	 * night and are handled by callers directly; ends on the start date or
	 * later than start + 1 day, or with an unknown/absent end time, are
	 * never classified as same-night here.
	 *
	 * Consumed by both is_multi_day() (classification) and
	 * DisplayVars::format_time_range() (time-string rendering) so the two
	 * cannot drift.
	 *
	 * @since 0.62.0
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @param string $end_time   End time (H:i or H:i:s). May be empty.
	 * @return bool True if the end belongs to the same night as the start.
	 */
	public static function is_same_night_end( string $start_date, string $end_date, string $end_time ): bool {
		if ( empty( $start_date ) || empty( $end_date ) || empty( $end_time ) ) {
			return false;
		}

		if ( ! DateTimeParser::isValidYmd( $start_date ) || ! DateTimeParser::isValidYmd( $end_date ) ) {
			return false;
		}

		$start = DateTime::createFromFormat( 'Y-m-d', $start_date );
		$end   = DateTime::createFromFormat( 'Y-m-d', $end_date );

		if ( ! $start || ! $end || 1 !== (int) $start->diff( $end )->days ) {
			return false;
		}

		return self::time_to_seconds( $end_time ) < self::time_to_seconds( Settings_Page::get_next_day_cutoff() );
	}

	/**
	 * Convert an H:i / H:i:s time string to seconds since midnight.
	 *
	 * @param string $time Time string.
	 * @return int Seconds.
	 */
	private static function time_to_seconds( string $time ): int {
		$parts = explode( ':', $time );

		return ( (int) $parts[0] * 3600 ) + ( (int) ( $parts[1] ?? 0 ) * 60 );
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
		if ( ! DateTimeParser::isValidYmd( $start_date ) || ! DateTimeParser::isValidYmd( $end_date ) ) {
			if ( DateTimeParser::isValidYmd( $start_date ) ) {
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
	 * Resolve the calendar dates an event should appear on (#199).
	 *
	 * Decision policy for the ambiguous middle ground between "genuine
	 * continuous multi-day event" and "recurring series whose series-wide
	 * end leaked onto this row at ingest":
	 *
	 *   1. Span within CONTINUOUS_SPAN_LIMIT days → expand every calendar
	 *      day. Everything a real festival or residency looks like fits
	 *      comfortably inside this window, so no guessing is needed.
	 *   2. Longer span with start and end on the same weekday → derive
	 *      weekly occurrences (start date, +7 days, ... through the end).
	 *      A multi-week span bounded by the same weekday is overwhelmingly
	 *      a weekly series in production data — "Monday Night Funk Jam"
	 *      Apr 6 → May 11 renders on six Mondays instead of all 36 days —
	 *      and genuinely continuous multi-week same-weekday events are
	 *      rare enough that the occasional misclassification costs two
	 *      correctly-labelled listings instead of a month of "Ongoing"
	 *      clutter.
	 *   3. Longer span with mismatched weekdays → truncate to the first
	 *      TRUNCATED_SPAN_WINDOW days. The data cannot distinguish a
	 *      recurring series from a long residency here, so no pattern is
	 *      invented; the row still appears (with its real start time) on a
	 *      bounded window instead of blanketing the calendar.
	 *
	 * Malformed dates fall through to get_date_range()'s existing
	 * degradation path.
	 *
	 * @param string       $start_date Start date (Y-m-d).
	 * @param string       $end_date   End date (Y-m-d).
	 * @param DateTimeZone $event_tz   Event timezone.
	 * @return array Array of date strings (Y-m-d), ascending.
	 */
	public static function get_display_dates( string $start_date, string $end_date, DateTimeZone $event_tz ): array {
		if ( ! DateTimeParser::isValidYmd( $start_date ) || ! DateTimeParser::isValidYmd( $end_date ) ) {
			return self::get_date_range( $start_date, $end_date, $event_tz );
		}

		$start = new DateTime( $start_date );
		$end   = new DateTime( $end_date );
		$diff  = (int) $start->diff( $end )->days;

		if ( $diff <= self::CONTINUOUS_SPAN_LIMIT ) {
			return self::get_date_range( $start_date, $end_date, $event_tz );
		}

		if ( self::is_likely_weekly_recurrence( $start_date, $end_date ) ) {
			return self::get_weekly_occurrence_dates( $start_date, $end_date );
		}

		return array_slice(
			self::get_date_range( $start_date, $end_date, $event_tz ),
			0,
			self::TRUNCATED_SPAN_WINDOW
		);
	}

	/**
	 * Whether a long span bounded by the same weekday is treated as a
	 * weekly recurring series for display grouping.
	 *
	 * Only spans beyond CONTINUOUS_SPAN_LIMIT days qualify — shorter spans
	 * are expanded continuously without needing the heuristic.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @return bool True when the span reads as a weekly recurrence.
	 */
	public static function is_likely_weekly_recurrence( string $start_date, string $end_date ): bool {
		if ( ! DateTimeParser::isValidYmd( $start_date ) || ! DateTimeParser::isValidYmd( $end_date ) ) {
			return false;
		}

		$start = new DateTime( $start_date );
		$end   = new DateTime( $end_date );

		if ( (int) $start->diff( $end )->days <= self::CONTINUOUS_SPAN_LIMIT ) {
			return false;
		}

		return $start->format( 'w' ) === $end->format( 'w' );
	}

	/**
	 * Derive weekly occurrence dates from a long same-weekday span.
	 *
	 * Returns the start date plus every seventh day through the end date,
	 * inclusive.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d), same weekday as start.
	 * @return array Array of date strings (Y-m-d), ascending.
	 */
	public static function get_weekly_occurrence_dates( string $start_date, string $end_date ): array {
		if ( ! DateTimeParser::isValidYmd( $start_date ) || ! DateTimeParser::isValidYmd( $end_date ) ) {
			return array();
		}

		$dates  = array();
		$cursor = new DateTime( $start_date );
		$end    = new DateTime( $end_date );

		while ( $cursor <= $end ) {
			$dates[] = $cursor->format( 'Y-m-d' );
			$cursor->modify( '+7 days' );
		}

		return $dates;
	}
}
