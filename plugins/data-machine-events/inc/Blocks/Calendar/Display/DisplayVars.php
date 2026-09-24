<?php
// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
/**
 * Display Variables Builder
 *
 * Builds display-ready variables from raw event data. Handles time
 * formatting, unicode decoding, sentinel time detection, and
 * multi-day label generation.
 *
 * @package DataMachineEvents\Blocks\Calendar\Display
 * @since   0.14.0
 */

namespace DataMachineEvents\Blocks\Calendar\Display;

use DateTime;
use DateTimeZone;
use DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper;
use DataMachineEvents\Blocks\Calendar\Grouping\MultiDayResolver;
use DataMachineEvents\Core\DateTimeParser;
use function DataMachineEvents\Core\data_machine_events_is_gated_ticket_url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DisplayVars {

	/**
	 * Build display variables for an event.
	 *
	 * @param array $event_data      Event data from block attributes.
	 * @param array $display_context Optional display context for multi-day events.
	 * @return array Display variables.
	 */
	public static function build( array $event_data, array $display_context = array() ): array {
		$start_date = $event_data['startDate'] ?? '';
		$start_time = $event_data['startTime'] ?? '';
		$end_date   = $event_data['endDate'] ?? '';
		$end_time   = $event_data['endTime'] ?? '';

		// Ticketmaster compliance (issue #816): affiliate ticket URLs must
		// never appear in raw HTML/JSON. `ticket_url` is emptied when the
		// URL routes through the first-party redirect; consumers gate a
		// `data-ticket-ref` button on `is_affiliate_ticket` instead — the
		// ref is the event's own post ID, already known independently by
		// every caller of this method.
		//
		// Issue #818: "routes through the first-party redirect" now includes
		// canonical-stored monetized URLs (wrapper assembled at resolve
		// time), not just stored wrappers — the gate is what preserves
		// monetization once rows are migrated to canonical storage.
		$raw_ticket_url      = (string) ( $event_data['ticketUrl'] ?? '' );
		$is_affiliate_ticket = data_machine_events_is_gated_ticket_url( $raw_ticket_url );

		$formatted_time_display = '';
		$iso_start_date         = '';
		$multi_day_label        = '';

		$event_tz           = DateGrouper::get_event_timezone( $event_data );
		$start_datetime_obj = $start_date
			? DateTimeParser::safeCreate( $start_date . ' ' . $start_time, $event_tz )
			: null;

		// A malformed/placeholder startDate that predates the prevention layers
		// cannot be formatted. Degrade gracefully — return empty display vars for
		// the date fields rather than throwing on the render path. See #394.
		if ( $start_datetime_obj instanceof DateTime ) {
			$iso_start_date = $start_datetime_obj->format( 'c' );

			$is_multi_day    = ! empty( $display_context['is_multi_day'] );
			$is_continuation = ! empty( $display_context['is_continuation'] );

			$end_datetime_obj = ( $is_multi_day && ! empty( $end_date ) )
				? DateTimeParser::safeCreate( $end_date, $event_tz )
				: null;

			if ( $is_multi_day && ! empty( $end_date ) && $end_datetime_obj instanceof DateTime ) {
				if ( $is_continuation ) {
					$formatted_time_display = sprintf(
						/* translators: %s: end date. Example: "Ongoing · ends Mar 22" */
						__( 'Ongoing · ends %s', 'data-machine-events' ),
						$end_datetime_obj->format( 'M j' )
					);
				} else {
					$multi_day_label = sprintf(
						__( 'through %s', 'data-machine-events' ),
						$end_datetime_obj->format( 'M j' )
					);
					// Multi-day events keep the start time only — the badge
					// carries the span. This also keeps the portable
					// calendar-occurrence contract bytes stable for
					// multi-day-classified occurrences.
					$formatted_time_display = self::format_time_range( $start_datetime_obj, $end_date, $end_time, $event_tz, false );
				}
			} else {
				$formatted_time_display = self::format_time_range( $start_datetime_obj, $end_date, $end_time, $event_tz );
			}
		}

		return array(
			'formatted_time_display' => $formatted_time_display,
			'venue_name'             => self::decode_unicode( $event_data['venue'] ?? '' ),
			'performer_name'         => self::decode_unicode( $event_data['performer'] ?? '' ),
			'iso_start_date'         => $iso_start_date,
			'show_performer'         => false,
			'show_price'             => $event_data['showPrice'] ?? true,
			'show_ticket_link'       => $event_data['showTicketLink'] ?? true,
			'ticket_url'             => $is_affiliate_ticket ? '' : $raw_ticket_url,
			'is_affiliate_ticket'    => $is_affiliate_ticket,
			'multi_day_label'        => $multi_day_label,
			'is_continuation'        => $display_context['is_continuation'] ?? false,
			'is_multi_day'           => $display_context['is_multi_day'] ?? false,
		);
	}

	/**
	 * Format time range for display.
	 *
	 * Formats start and end times into a readable range. When both formatted
	 * times share a common trailing token (e.g. the "AM"/"PM" meridiem),
	 * only shows it once (e.g., "7:30 - 10:00 PM").
	 *
	 * A range is printed for same-day events and, when
	 * $allow_same_night_range is set, for same-night events whose end lands
	 * on the following calendar day before the next-day cutoff (a
	 * 9 PM → 2 AM bar show reads "9:00 PM - 2:00 AM", #833). Genuinely
	 * multi-day events keep the start time only — the "through <date>"
	 * badge carries the span instead — so multi-day-classified occurrences
	 * pass false and their serialized shape is unchanged.
	 *
	 * @param DateTime     $start_datetime_obj     Start datetime object.
	 * @param string       $end_date               End date (Y-m-d format).
	 * @param string       $end_time               End time (H:i:s format).
	 * @param DateTimeZone $event_tz               Event timezone.
	 * @param bool         $allow_same_night_range Whether a same-night next-day end may render as a range.
	 * @param string       $time_format            PHP date() format for each side of the range.
	 *                                              Defaults to the calendar card's fixed
	 *                                              12-hour display (unchanged for existing
	 *                                              callers); other surfaces pass the site's
	 *                                              configured `time_format` option (#860).
	 * @return string Formatted time display.
	 */
	public static function format_time_range( DateTime $start_datetime_obj, string $end_date, string $end_time, DateTimeZone $event_tz, bool $allow_same_night_range = true, string $time_format = 'g:i A' ): string {
		$start_formatted_full = $start_datetime_obj->format( $time_format );

		if ( empty( $end_date ) || empty( $end_time ) || self::is_sentinel_end_time( $end_time ) ) {
			return $start_formatted_full;
		}

		$end_datetime_obj = DateTimeParser::safeCreate( $end_date . ' ' . $end_time, $event_tz );

		// A malformed end date can't form a range; just show the start time.
		if ( ! $end_datetime_obj instanceof DateTime ) {
			return $start_formatted_full;
		}

		// Don't show a range when start and end are identical (no real end time).
		if ( $start_datetime_obj->format( 'Y-m-d H:i' ) === $end_datetime_obj->format( 'Y-m-d H:i' ) ) {
			return $start_formatted_full;
		}
		$is_same_day = $start_datetime_obj->format( 'Y-m-d' ) === $end_datetime_obj->format( 'Y-m-d' );
		if ( ! $is_same_day && ( ! $allow_same_night_range || ! MultiDayResolver::is_same_night_end( $start_datetime_obj->format( 'Y-m-d' ), $end_date, $end_time ) ) ) {
			return $start_formatted_full;
		}

		$end_formatted_full = $end_datetime_obj->format( $time_format );

		return self::combine_time_range( $start_formatted_full, $end_formatted_full );
	}

	/**
	 * Join two already-formatted time strings into a range, collapsing a
	 * shared trailing token so a same-period range reads "6:30 - 9:00 PM"
	 * instead of "6:30 PM - 9:00 PM".
	 *
	 * Works for any `$time_format` (not just the fixed 'g:i A' the calendar
	 * card uses) because it compares the *formatted output*, not the format
	 * string: it looks at the last whitespace-delimited token of each side
	 * and only collapses it when both sides produced the identical token and
	 * that token is not itself part of the clock digits (e.g. a 24-hour
	 * format like "18:30" has no separate trailing token, so nothing is
	 * collapsed and both sides print in full).
	 *
	 * @param string $start_formatted Fully formatted start time.
	 * @param string $end_formatted   Fully formatted end time.
	 * @return string Combined range.
	 */
	private static function combine_time_range( string $start_formatted, string $end_formatted ): string {
		$start_parts = explode( ' ', $start_formatted );
		$end_parts   = explode( ' ', $end_formatted );

		$start_last_token = end( $start_parts );
		$end_last_token   = end( $end_parts );

		if ( count( $start_parts ) > 1 && $start_last_token === $end_last_token && ! preg_match( '/\d/', $start_last_token ) ) {
			array_pop( $start_parts );
			return implode( ' ', $start_parts ) . ' - ' . $end_formatted;
		}

		return $start_formatted . ' - ' . $end_formatted;
	}

	/**
	 * Check if end time is the sentinel value used for SQL date range queries.
	 *
	 * When events have endDate but no endTime, event-dates-sync.php stores 23:59:59
	 * to ensure proper date range filtering. This should not display to users.
	 *
	 * @param string $time Time string in HH:MM or HH:MM:SS format.
	 * @return bool True if time is the 23:59 sentinel value.
	 */
	public static function is_sentinel_end_time( string $time ): bool {
		$normalized = substr( $time, 0, 5 );
		return '23:59' === $normalized;
	}



	/**
	 * Decode unicode escape sequences in strings.
	 *
	 * @param string $str Input string.
	 * @return string Decoded string.
	 */
	public static function decode_unicode( string $str ): string {
		return html_entity_decode(
			preg_replace( '/\\\\u([0-9a-fA-F]{4})/', '&#x$1;', $str ),
			ENT_NOQUOTES,
			'UTF-8'
		);
	}
}
