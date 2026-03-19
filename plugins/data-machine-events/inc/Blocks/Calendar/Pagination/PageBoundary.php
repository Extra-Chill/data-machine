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

use WP_Query;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Blocks\Calendar\Query\EventQueryBuilder;
use DataMachineEvents\Blocks\Calendar\Data\EventHydrator;
use DataMachineEvents\Blocks\Calendar\Grouping\MultiDayResolver;
use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_END_DATETIME_META_KEY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageBoundary {

	const DAYS_PER_PAGE             = 5;
	const MIN_EVENTS_FOR_PAGINATION = 20;

	/**
	 * Get unique event dates for pagination calculations (cached).
	 *
	 * Expands multi-day events to count on each day they span.
	 *
	 * @param array $params Query parameters.
	 * @return array {
	 *     @type array $dates           Ordered array of unique date strings (Y-m-d).
	 *     @type int   $total_events    Total number of matching events.
	 *     @type array $events_per_date Event counts keyed by date.
	 * }
	 */
	public static function get_unique_event_dates( array $params ): array {
		$cache_key = CalendarCache::generate_key( $params, 'dates' );
		$cached    = CalendarCache::get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = self::compute_unique_event_dates( $params );

		CalendarCache::set( $cache_key, $result, CalendarCache::TTL_DATES );

		return $result;
	}

	/**
	 * Compute unique event dates (uncached).
	 *
	 * Uses a single SQL query with a JOIN on postmeta to fetch all event
	 * start/end datetimes at once, then computes date grouping in PHP.
	 * This replaces the N+1 pattern of WP_Query + per-event get_post_meta
	 * which caused ~8,000+ queries at 12,500 events.
	 *
	 * @param array $params Query parameters.
	 * @return array Event dates data.
	 */
	private static function compute_unique_event_dates( array $params ): array {
		global $wpdb;

		$show_past_param = $params['show_past'] ?? false;
		$current_date    = current_time( 'Y-m-d' );

		// Build WHERE clauses from params for taxonomy/location filtering.
		$where_clauses = array(
			"p.post_type = 'data_machine_events'",
			"p.post_status = 'publish'",
			"pm_start.meta_key = '" . esc_sql( EVENT_DATETIME_META_KEY ) . "'",
		);
		$join_clauses  = array();
		$query_values  = array();

		if ( ! $show_past_param ) {
			$where_clauses[] = 'pm_start.meta_value >= %s';
			$query_values[]  = $current_date . ' 00:00:00';
		}

		// Handle location filter from params.
		if ( ! empty( $params['location'] ) ) {
			$join_clauses[]  = "INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id";
			$join_clauses[]  = "INNER JOIN {$wpdb->term_taxonomy} tt_filter ON tr.term_taxonomy_id = tt_filter.term_taxonomy_id";
			$where_clauses[] = "tt_filter.taxonomy = 'location'";
			$where_clauses[] = 'tt_filter.term_id = %d';
			$query_values[]  = (int) $params['location'];
		}

		$joins = implode( ' ', $join_clauses );
		$where = implode( ' AND ', $where_clauses );

		// Single query: fetch all event IDs with start/end datetimes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			empty( $query_values )
				? "SELECT p.ID, pm_start.meta_value AS start_datetime, pm_end.meta_value AS end_datetime
				   FROM {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id
				   LEFT JOIN {$wpdb->postmeta} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = '" . esc_sql( EVENT_END_DATETIME_META_KEY ) . "'
				   {$joins}
				   WHERE {$where}
				   ORDER BY pm_start.meta_value ASC"
				: $wpdb->prepare(
					"SELECT p.ID, pm_start.meta_value AS start_datetime, pm_end.meta_value AS end_datetime
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id
					LEFT JOIN {$wpdb->postmeta} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = '" . esc_sql( EVENT_END_DATETIME_META_KEY ) . "'
					{$joins}
					WHERE {$where}
					ORDER BY pm_start.meta_value ASC",
					...$query_values
				)
		);

		$total_events    = count( $rows );
		$events_per_date = array();

		foreach ( $rows as $row ) {
			$start_date = gmdate( 'Y-m-d', strtotime( $row->start_datetime ) );
			$end_date   = $row->end_datetime ? gmdate( 'Y-m-d', strtotime( $row->end_datetime ) ) : $start_date;

			if ( $start_date === $end_date ) {
				$events_per_date[ $start_date ] = ( $events_per_date[ $start_date ] ?? 0 ) + 1;
				continue;
			}

			// Multi-day: expand date range in PHP.
			$event_dates = MultiDayResolver::get_date_range( $start_date, $end_date, wp_timezone() );

			if ( ! $show_past_param ) {
				$event_dates = array_filter(
					$event_dates,
					function ( $date ) use ( $current_date ) {
						return $date >= $current_date;
					}
				);
			}

			foreach ( $event_dates as $date ) {
				$events_per_date[ $date ] = ( $events_per_date[ $date ] ?? 0 ) + 1;
			}
		}

		if ( $show_past_param ) {
			krsort( $events_per_date );
		} else {
			ksort( $events_per_date );
		}

		return array(
			'dates'           => array_keys( $events_per_date ),
			'total_events'    => $total_events,
			'events_per_date' => $events_per_date,
		);
	}

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
