<?php
/**
 * Date Grouper
 *
 * Groups events by date, expanding multi-day events across their date
 * range and handling occurrence dates for recurring events.
 *
 * @package DataMachineEvents\Blocks\Calendar\Grouping
 * @since   0.14.0
 */

namespace DataMachineEvents\Blocks\Calendar\Grouping;

use DateTime;
use DateTimeZone;
use WP_Query;
use DataMachineEvents\Blocks\Calendar\Data\EventHydrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DateGrouper {

	/**
	 * Build paged events array from WP_Query.
	 *
	 * @param WP_Query $query Events query.
	 * @return array Array of event items with post, datetime, and event_data.
	 */
	public static function build_paged_events( WP_Query $query ): array {
		$paged_events = array();

		if ( ! $query->have_posts() ) {
			return $paged_events;
		}

		while ( $query->have_posts() ) {
			$query->the_post();
			$event_post = get_post();
			$event_data = EventHydrator::parse_event_data( $event_post );

			if ( $event_data ) {
				$start_time     = $event_data['startTime'] ?? '00:00:00';
				$event_tz       = self::get_event_timezone( $event_data );
				$event_datetime = new DateTime(
					$event_data['startDate'] . ' ' . $start_time,
					$event_tz
				);

				$paged_events[] = array(
					'post'       => $event_post,
					'datetime'   => $event_datetime,
					'event_data' => $event_data,
				);
			}
		}

		wp_reset_postdata();

		return $paged_events;
	}

	/**
	 * Group events by date, expanding multi-day events across their date range.
	 *
	 * @param array  $paged_events Array of event items.
	 * @param bool   $show_past    Whether showing past events (affects sort order).
	 * @param string $date_start   Optional start date boundary (Y-m-d).
	 * @param string $date_end     Optional end date boundary (Y-m-d).
	 * @return array Date-grouped events.
	 */
	public static function group_events_by_date( array $paged_events, bool $show_past = false, string $date_start = '', string $date_end = '' ): array {
		$date_groups = array();

		foreach ( $paged_events as $event_item ) {
			$event_data = $event_item['event_data'];
			$start_date = $event_data['startDate'] ?? '';
			$end_date   = $event_data['endDate'] ?? $start_date;

			if ( empty( $start_date ) ) {
				continue;
			}

			$event_tz     = self::get_event_timezone( $event_data );
			$is_multi_day = MultiDayResolver::is_multi_day( $event_data );

			// Use explicit occurrence dates if provided, otherwise expand full range.
			$occurrence_dates     = $event_data['occurrenceDates'] ?? array();
			$has_occurrence_dates = ! empty( $occurrence_dates ) && is_array( $occurrence_dates );

			if ( $has_occurrence_dates ) {
				$event_dates = $occurrence_dates;
			} elseif ( $is_multi_day ) {
				$event_dates = MultiDayResolver::get_date_range( $start_date, $end_date, $event_tz );
			} else {
				$event_dates = array( $start_date );
			}

			// Filter out past dates when show_past is false.
			if ( ! $show_past && ( $has_occurrence_dates || $is_multi_day ) ) {
				$current_date = current_time( 'Y-m-d' );
				$event_dates  = array_filter(
					$event_dates,
					function ( $date ) use ( $current_date ) {
						return $date >= $current_date;
					}
				);
			}

			// Filter to page date boundaries if provided.
			if ( $date_start || $date_end ) {
				$event_dates = array_filter(
					$event_dates,
					function ( $date ) use ( $date_start, $date_end ) {
						if ( $date_start && $date < $date_start ) {
							return false;
						}
						if ( $date_end && $date > $date_end ) {
							return false;
						}
						return true;
					}
				);
			}

			foreach ( $event_dates as $index => $date_key ) {
				$display_datetime_obj = new DateTime( $date_key . ' 00:00:00', $event_tz );

				if ( ! isset( $date_groups[ $date_key ] ) ) {
					$date_groups[ $date_key ] = array(
						'date_obj' => $display_datetime_obj,
						'events'   => array(),
					);
				}

				// Events with explicit occurrence dates are NOT continuations.
				$is_continuation = $has_occurrence_dates ? false : ( $date_key !== $start_date );

				$display_item                    = $event_item;
				$display_item['display_context'] = array(
					'is_multi_day'        => $has_occurrence_dates ? false : $is_multi_day,
					'is_start_day'        => $has_occurrence_dates ? true : ( $date_key === $start_date ),
					'is_end_day'          => $has_occurrence_dates ? true : ( $date_key === $end_date ),
					'is_continuation'     => $is_continuation,
					'display_date'        => $date_key,
					'original_start_date' => $start_date,
					'original_end_date'   => $end_date,
					'day_number'          => $index + 1,
					'total_days'          => count( $event_dates ),
				);

				$date_groups[ $date_key ]['events'][] = $display_item;
			}
		}

		// Allow reordering events within each day group.
		foreach ( $date_groups as $date_key => &$date_group ) {
			$date_group['events'] = apply_filters(
				'data_machine_events_day_group_events',
				$date_group['events'],
				$date_key,
				array(
					'date_obj'  => $date_group['date_obj'],
					'show_past' => $show_past,
				)
			);
		}
		unset( $date_group );

		uksort(
			$date_groups,
			function ( $a, $b ) use ( $show_past ) {
				return $show_past ? strcmp( $b, $a ) : strcmp( $a, $b );
			}
		);

		return $date_groups;
	}

	/**
	 * Detect time gaps between date groups for carousel mode.
	 *
	 * @param array $date_groups Date-grouped events.
	 * @return array Map of date_key => gap_days for gaps >= 2 days.
	 */
	public static function detect_time_gaps( array $date_groups ): array {
		$gaps          = array();
		$previous_date = null;

		foreach ( $date_groups as $date_key => $date_group ) {
			if ( null !== $previous_date ) {
				$current_date = new DateTime( $date_key, wp_timezone() );
				$days_diff    = $current_date->diff( $previous_date )->days;

				if ( $days_diff > 1 ) {
					$gaps[ $date_key ] = $days_diff;
				}
			}
			$previous_date = new DateTime( $date_key, wp_timezone() );
		}

		return $gaps;
	}

	/**
	 * Get DateTimeZone for an event.
	 *
	 * Uses venue timezone if available, falls back to WordPress site timezone.
	 *
	 * @param array $event_data Event data array.
	 * @return DateTimeZone Timezone for the event.
	 */
	public static function get_event_timezone( array $event_data ): DateTimeZone {
		$tz_string = $event_data['venueTimezone'] ?? '';

		if ( ! empty( $tz_string ) ) {
			try {
				return new DateTimeZone( $tz_string );
			} catch ( \Exception $e ) {
				// Invalid timezone, fall through to default.
			}
		}

		return wp_timezone();
	}
}
