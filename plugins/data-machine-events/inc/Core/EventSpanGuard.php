<?php
/**
 * Event Span Guard
 *
 * Shared policy for the maximum plausible span of a single event occurrence's
 * start-to-end datetime. Series-wide ends (a recurring series' final date
 * stamped onto every occurrence) are the dominant fabrication class behind
 * multi-day garbage on the calendar — see issue #199.
 *
 * The guard is intentionally a pure predicate: it does not write, log, or
 * mutate. Each write site (event-dates-sync derivation, EventDatesTable
 * storage) owns its logging and its strip/reject response so context stays
 * local to the layer that has it.
 *
 * @package DataMachineEvents\Core
 * @since   0.64.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventSpanGuard {

	/**
	 * Default maximum plausible span for one occurrence, in hours.
	 *
	 * 336 hours = 14 days. Long enough for every real multi-day festival
	 * (the longest mainstream residency format, Summerfest, is ~11 days);
	 * short enough that every measured series leak in issue #199 (minimum
	 * 160 hours, maximum 6,744) trips it.
	 */
	private const DEFAULT_MAX_SPAN_HOURS = 336;

	/**
	 * The maximum plausible occurrence span, in hours.
	 *
	 * Filterable via `data_machine_events_max_event_span_hours`. Values below
	 * 1 fall back to the default so a misconfigured filter cannot silently
	 * disable the guard or strip every end.
	 *
	 * @return int Hours.
	 */
	public static function max_span_hours(): int {
		/**
		 * Filter the maximum plausible start-to-end span for a single event
		 * occurrence, in hours. Ends beyond this span are treated as
		 * fabricated series ranges and stripped before storage.
		 *
		 * @param int $hours Maximum span in hours (default 336 = 14 days).
		 */
		$hours = (int) apply_filters( 'data_machine_events_max_event_span_hours', self::DEFAULT_MAX_SPAN_HOURS );

		// A filtered value below 1 falls back to the default rather than
		// clamping to 1: a misconfigured filter must not turn the guard into
		// a strip-everything surprise.
		return $hours >= 1 ? $hours : self::DEFAULT_MAX_SPAN_HOURS;
	}

	/**
	 * Whether an end datetime is plausible for its start.
	 *
	 * Unparseable inputs return true: malformed dates are a different defect
	 * class with their own rejection path (see #394/#395), and silently
	 * stripping them here would mask upstream extraction failures.
	 *
	 * @param string   $start_datetime MySQL datetime string.
	 * @param string   $end_datetime   MySQL datetime string.
	 * @param int|null $max_hours      Optional explicit threshold override (hours).
	 * @return bool True when the end is within the maximum span.
	 */
	public static function is_end_plausible( string $start_datetime, string $end_datetime, ?int $max_hours = null ): bool {
		$start_ts = strtotime( $start_datetime );
		$end_ts   = strtotime( $end_datetime );

		if ( false === $start_ts || false === $end_ts ) {
			return true;
		}

		$hours = $max_hours ?? self::max_span_hours();

		return ( $end_ts - $start_ts ) <= max( 1, $hours ) * HOUR_IN_SECONDS;
	}

	/**
	 * Derive the start-to-end span in hours from Event Details block attrs.
	 *
	 * Mirrors the event-dates-sync derivation (a present endDate with no
	 * endTime defaults to 23:59:59) so detectors measure the same span the
	 * datamachine_event_dates row carries. Null when start or end is missing,
	 * unparseable, or the end does not exceed the start.
	 *
	 * @param array $attrs Block attributes (startDate/startTime/endDate/endTime).
	 * @return int|null Span in hours, rounded up, or null when not measurable.
	 */
	public static function span_hours_from_block_attrs( array $attrs ): ?int {
		$start_date = (string) ( $attrs['startDate'] ?? '' );
		$end_date   = (string) ( $attrs['endDate'] ?? '' );

		if ( '' === $start_date || '' === $end_date ) {
			return null;
		}

		$start_time = (string) ( $attrs['startTime'] ?? '' );
		$end_time   = (string) ( $attrs['endTime'] ?? '' );

		$start_ts = strtotime( $start_date . ' ' . ( '' !== $start_time ? $start_time : '00:00:00' ) );
		$end_ts   = strtotime( $end_date . ' ' . ( '' !== $end_time ? $end_time : '23:59:59' ) );

		if ( false === $start_ts || false === $end_ts || $end_ts <= $start_ts ) {
			return null;
		}

		return (int) ceil( ( $end_ts - $start_ts ) / HOUR_IN_SECONDS );
	}
}
