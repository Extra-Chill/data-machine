<?php
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

		$formatted_time_display = '';
		$iso_start_date         = '';
		$multi_day_label        = '';

		if ( $start_date ) {
			$event_tz           = DateGrouper::get_event_timezone( $event_data );
			$start_datetime_obj = new DateTime( $start_date . ' ' . $start_time, $event_tz );
			$iso_start_date     = $start_datetime_obj->format( 'c' );

			$is_multi_day    = ! empty( $display_context['is_multi_day'] );
			$is_continuation = ! empty( $display_context['is_continuation'] );

			if ( $is_multi_day && ! empty( $end_date ) ) {
				$end_datetime_obj = new DateTime( $end_date, $event_tz );

				if ( $is_continuation ) {
					$formatted_time_display = sprintf(
						/* translators: 1: start date, 2: end date. Example: "Feb 27 – Mar 1" */
						__( '%1$s – %2$s', 'datamachine-events' ),
						$start_datetime_obj->format( 'M j' ),
						$end_datetime_obj->format( 'M j' )
					);
				} else {
					$multi_day_label = sprintf(
						__( 'through %s', 'datamachine-events' ),
						$end_datetime_obj->format( 'M j' )
					);
					$formatted_time_display = self::format_time_range( $start_datetime_obj, $end_date, $end_time, $event_tz );
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
			'multi_day_label'        => $multi_day_label,
			'is_continuation'        => $display_context['is_continuation'] ?? false,
			'is_multi_day'           => $display_context['is_multi_day'] ?? false,
		);
	}

	/**
	 * Format time range for display.
	 *
	 * Formats start and end times into a readable range. When both times share
	 * the same AM/PM period, only shows the period once (e.g., "7:30 - 10:00 PM").
	 *
	 * @param DateTime     $start_datetime_obj Start datetime object.
	 * @param string       $end_date           End date (Y-m-d format).
	 * @param string       $end_time           End time (H:i:s format).
	 * @param DateTimeZone $event_tz           Event timezone.
	 * @return string Formatted time display.
	 */
	public static function format_time_range( DateTime $start_datetime_obj, string $end_date, string $end_time, DateTimeZone $event_tz ): string {
		$start_formatted_full = $start_datetime_obj->format( 'g:i A' );

		if ( empty( $end_date ) || empty( $end_time ) || self::is_sentinel_end_time( $end_time ) ) {
			return $start_formatted_full;
		}

		$end_datetime_obj = new DateTime( $end_date . ' ' . $end_time, $event_tz );

		$is_same_day = $start_datetime_obj->format( 'Y-m-d' ) === $end_datetime_obj->format( 'Y-m-d' );
		if ( ! $is_same_day ) {
			return $start_formatted_full;
		}

		$start_period = $start_datetime_obj->format( 'A' );
		$end_period   = $end_datetime_obj->format( 'A' );

		if ( $start_period === $end_period ) {
			$start_time_only    = $start_datetime_obj->format( 'g:i' );
			$end_formatted_full = $end_datetime_obj->format( 'g:i A' );
			return $start_time_only . ' - ' . $end_formatted_full;
		}

		$end_formatted_full = $end_datetime_obj->format( 'g:i A' );
		return $start_formatted_full . ' - ' . $end_formatted_full;
	}

	/**
	 * Check if end time is the sentinel value used for SQL date range queries.
	 *
	 * When events have endDate but no endTime, meta-storage.php stores 23:59:59
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
