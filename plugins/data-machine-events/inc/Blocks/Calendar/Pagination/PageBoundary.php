<?php
/**
 * Page Boundary Calculator
 *
 * Computes pagination boundaries for date-grouped event calendars.
 * Handles unique date computation, page splitting based on both
 * day count and event count thresholds.
 *
 * @package DataMachineEvents\Blocks\Calendar\Pagination
 * @since   0.14.0
 */

namespace DataMachineEvents\Blocks\Calendar\Pagination;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageBoundary {

	const DAYS_PER_PAGE             = 5;
	const MIN_EVENTS_FOR_PAGINATION = 20;

	/**
	 * Get date boundaries for a specific page.
	 *
	 * Pages must contain at least DAYS_PER_PAGE days AND at least
	 * MIN_EVENTS_FOR_PAGINATION events. The day that crosses
	 * the event threshold is included in full (never split days).
	 *
	 * @param array $unique_dates    Ordered array of unique dates.
	 * @param int   $page            Page number (1-based).
	 * @param int   $total_events    Total event count.
	 * @param array $events_per_date Event counts keyed by date.
	 * @return array ['start_date' => 'Y-m-d', 'end_date' => 'Y-m-d', 'max_pages' => int]
	 */
	public static function get_date_boundaries_for_page( array $unique_dates, int $page, int $total_events = 0, array $events_per_date = array() ): array {
		$total_days = count( $unique_dates );

		if ( 0 === $total_days ) {
			return array(
				'start_date' => '',
				'end_date'   => '',
				'max_pages'  => 0,
			);
		}

		if ( $total_events > 0 && $total_events < self::MIN_EVENTS_FOR_PAGINATION ) {
			return array(
				'start_date' => $unique_dates[0],
				'end_date'   => $unique_dates[ $total_days - 1 ],
				'max_pages'  => 1,
			);
		}

		if ( empty( $events_per_date ) ) {
			$max_pages = (int) ceil( $total_days / self::DAYS_PER_PAGE );
			$page      = max( 1, min( $page, $max_pages ) );

			$start_index = ( $page - 1 ) * self::DAYS_PER_PAGE;
			$end_index   = min( $start_index + self::DAYS_PER_PAGE - 1, $total_days - 1 );

			return array(
				'start_date' => $unique_dates[ $start_index ],
				'end_date'   => $unique_dates[ $end_index ],
				'max_pages'  => $max_pages,
			);
		}

		$page_boundaries      = array();
		$current_page_start   = 0;
		$cumulative_events    = 0;
		$days_in_current_page = 0;

		for ( $i = 0; $i < $total_days; $i++ ) {
			$date               = $unique_dates[ $i ];
			$cumulative_events += $events_per_date[ $date ] ?? 0;
			++$days_in_current_page;

			$is_last_date   = ( $i === $total_days - 1 );
			$meets_minimums = ( $days_in_current_page >= self::DAYS_PER_PAGE && $cumulative_events >= self::MIN_EVENTS_FOR_PAGINATION );

			if ( $meets_minimums || $is_last_date ) {
				$page_boundaries[]    = array(
					'start' => $current_page_start,
					'end'   => $i,
				);
				$current_page_start   = $i + 1;
				$cumulative_events    = 0;
				$days_in_current_page = 0;
			}
		}

		$max_pages = count( $page_boundaries );
		$page      = max( 1, min( $page, $max_pages ) );
		$boundary  = $page_boundaries[ $page - 1 ];

		return array(
			'start_date' => $unique_dates[ $boundary['start'] ],
			'end_date'   => $unique_dates[ $boundary['end'] ],
			'max_pages'  => $max_pages,
		);
	}
}
