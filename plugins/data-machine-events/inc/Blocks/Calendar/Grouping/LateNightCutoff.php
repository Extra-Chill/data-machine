<?php
/**
 * Late-Night Calendar Cutoff
 *
 * Buckets after-midnight shows under the previous calendar day for display
 * purposes. A 1:00 AM Sunday show is "Saturday night" to humans, even though
 * its literal start_datetime is Sunday.
 *
 * The underlying datetime is never modified — only the calendar grouping
 * key changes. Single-event pages, structured data, ICS exports, and any
 * datetime-aware consumer keep seeing the real start time.
 *
 * @package DataMachineEvents\Blocks\Calendar\Grouping
 * @since   0.31.0
 */

namespace DataMachineEvents\Blocks\Calendar\Grouping;

use DateTime;
use DateTimeZone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LateNightCutoff {

	/**
	 * Default cutoff hour. Events between 00:00 and (cutoff - 1):59 belong
	 * to the previous calendar day. 5 matches Bandsintown / Songkick; RA
	 * uses 6. 0 disables the feature.
	 */
	private const DEFAULT_CUTOFF_HOUR = 5;

	/**
	 * Resolve the active cutoff hour.
	 *
	 * Filterable via `data_machine_events_late_night_cutoff_hour`. Return
	 * 0 to disable late-night bucketing entirely. Values >= 12 are clamped
	 * to 5 to prevent absurd cutoffs (a 1pm cutoff would push afternoon
	 * shows backward).
	 *
	 * @return int Hour in [0, 12). 0 means feature is disabled.
	 */
	public static function cutoff_hour(): int {
		/**
		 * Filter the late-night calendar cutoff hour.
		 *
		 * Events with a start time between 00:00 and (cutoff - 1):59 are
		 * displayed under the previous calendar day. Return 0 to disable.
		 *
		 * @since 0.31.0
		 *
		 * @param int $cutoff_hour Default 5. Range [0, 12).
		 */
		$hour = (int) apply_filters(
			'data_machine_events_late_night_cutoff_hour',
			self::DEFAULT_CUTOFF_HOUR
		);

		if ( $hour < 0 || $hour >= 12 ) {
			return self::DEFAULT_CUTOFF_HOUR;
		}

		return $hour;
	}

	/**
	 * Compute the calendar display date for an event datetime.
	 *
	 * Subtracts the cutoff window: events whose hour is below the cutoff
	 * are returned with the previous calendar day. Everything else
	 * returns its native date.
	 *
	 * @param DateTime $event_datetime Event start datetime (in venue tz).
	 * @return string Display date in Y-m-d.
	 */
	public static function display_date( DateTime $event_datetime ): string {
		$cutoff = self::cutoff_hour();

		if ( $cutoff <= 0 ) {
			return $event_datetime->format( 'Y-m-d' );
		}

		$hour = (int) $event_datetime->format( 'G' );
		if ( $hour >= $cutoff ) {
			return $event_datetime->format( 'Y-m-d' );
		}

		// Clone to avoid mutating the caller's DateTime instance.
		$shifted = clone $event_datetime;
		$shifted->modify( '-1 day' );

		return $shifted->format( 'Y-m-d' );
	}

	/**
	 * Compute the display date from a raw Y-m-d + H:i:s pair.
	 *
	 * Pure-string path used by the SQL bucketing layer where a DateTime
	 * round-trip would be wasteful. Identical semantics to display_date().
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $start_time H:i or H:i:s. May be empty.
	 * @return string Display date in Y-m-d.
	 */
	public static function display_date_from_strings( string $start_date, string $start_time ): string {
		$cutoff = self::cutoff_hour();

		if ( $cutoff <= 0 || empty( $start_time ) ) {
			return $start_date;
		}

		// Parse hour from "HH:MM" or "HH:MM:SS".
		$hour = (int) substr( $start_time, 0, 2 );
		if ( $hour >= $cutoff ) {
			return $start_date;
		}

		// Subtract a day. DateTime handles month/year rollovers.
		$dt = DateTime::createFromFormat( 'Y-m-d', $start_date );
		if ( false === $dt ) {
			return $start_date;
		}
		$dt->modify( '-1 day' );

		return $dt->format( 'Y-m-d' );
	}

	/**
	 * Build a SQL DATE() expression that respects the cutoff.
	 *
	 * Used by aggregating queries (calendar bucket counts) that need to
	 * group by display date rather than literal start date. Returns the
	 * raw SQL fragment for the cutoff-shifted date.
	 *
	 * Equivalent to:
	 *   DATE(start_datetime - INTERVAL <cutoff> HOUR)
	 *
	 * Falls back to plain DATE(start_datetime) when the feature is
	 * disabled (cutoff = 0).
	 *
	 * @param string $column Fully-qualified datetime column (e.g. "ed.start_datetime").
	 * @return string SQL expression yielding a DATE.
	 */
	public static function sql_display_date_expression( string $column ): string {
		$cutoff = self::cutoff_hour();

		if ( $cutoff <= 0 ) {
			return "DATE({$column})";
		}

		return sprintf( 'DATE(%s - INTERVAL %d HOUR)', $column, $cutoff );
	}
}
