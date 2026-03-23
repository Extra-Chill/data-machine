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

use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageBoundary {

	const DAYS_PER_PAGE             = 5;
	const MIN_EVENTS_FOR_PAGINATION = 20;

	/**
	 * Get unique event dates for pagination calculations (cached).
	 *
	 * Multi-day events are expanded to count on each spanned date.
	 *
	 * @deprecated Use CalendarAbilities::get_unique_event_dates() instead. Moved in 0.16.0.
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
	 * Fetches start/end dates (without post IDs) and uses DATE() in SQL
	 * to minimize data transfer. Multi-day events are properly expanded
	 * to count on each spanned date. This reduces cold query time from
	 * ~960ms to ~500ms at 25,000 events by eliminating the ID column
	 * and gmdate() parsing in PHP.
	 *
	 * @deprecated Use CalendarAbilities::compute_unique_event_dates() instead. Moved in 0.16.0.
	 *
	 * @param array $params Query parameters.
	 * @return array Event dates data.
	 */
	private static function compute_unique_event_dates( array $params ): array {
		global $wpdb;

		$show_past_param = $params['show_past'] ?? false;
		$current_date    = current_time( 'Y-m-d' );
		$ed_table        = \DataMachineEvents\Core\EventDatesTable::table_name();

		// Build WHERE clauses from params for taxonomy/location filtering.
		$where_clauses = array(
			"p.post_type = 'data_machine_events'",
			"p.post_status = 'publish'",
		);
		$join_clauses  = array();
		$query_values  = array();

		if ( ! $show_past_param ) {
			$where_clauses[] = 'ed.start_datetime >= %s';
			$query_values[]  = $current_date . ' 00:00:00';
		}

		// Handle taxonomy archive filter (any taxonomy: artist, venue, location, etc.).
		$archive_taxonomy = $params['archive_taxonomy'] ?? '';
		$archive_term_id  = $params['archive_term_id'] ?? 0;

		if ( $archive_taxonomy && $archive_term_id ) {
			$join_clauses[]  = "INNER JOIN {$wpdb->term_relationships} tr_archive ON p.ID = tr_archive.object_id";
			$join_clauses[]  = "INNER JOIN {$wpdb->term_taxonomy} tt_archive ON tr_archive.term_taxonomy_id = tt_archive.term_taxonomy_id";
			$where_clauses[] = 'tt_archive.taxonomy = %s';
			$query_values[]  = $archive_taxonomy;
			$where_clauses[] = 'tt_archive.term_id = %d';
			$query_values[]  = (int) $archive_term_id;
		}

		// Handle additional taxonomy filters from the filter bar.
		$tax_filters = $params['tax_filters'] ?? array();
		$filter_index = 0;
		foreach ( $tax_filters as $taxonomy_slug => $term_ids ) {
			if ( empty( $term_ids ) || ! is_array( $term_ids ) ) {
				continue;
			}

			$alias_tr = 'tr_filter_' . $filter_index;
			$alias_tt = 'tt_filter_' . $filter_index;

			$join_clauses[]  = "INNER JOIN {$wpdb->term_relationships} {$alias_tr} ON p.ID = {$alias_tr}.object_id";
			$join_clauses[]  = "INNER JOIN {$wpdb->term_taxonomy} {$alias_tt} ON {$alias_tr}.term_taxonomy_id = {$alias_tt}.term_taxonomy_id";
			$where_clauses[] = "{$alias_tt}.taxonomy = %s";
			$query_values[]  = sanitize_key( $taxonomy_slug );

			$placeholders    = implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) );
			$where_clauses[] = "{$alias_tt}.term_id IN ({$placeholders})";
			foreach ( $term_ids as $term_id ) {
				$query_values[] = (int) $term_id;
			}

			++$filter_index;
		}

		$joins = implode( ' ', $join_clauses );
		$where = implode( ' AND ', $where_clauses );

		// Fetch start/end dates without IDs — DATE() in SQL avoids gmdate() in PHP.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			empty( $query_values )
				? "SELECT DATE(ed.start_datetime) AS start_date, DATE(ed.end_datetime) AS end_date
				   FROM {$wpdb->posts} p
				   INNER JOIN {$ed_table} ed ON p.ID = ed.post_id
				   {$joins}
				   WHERE {$where}
				   ORDER BY ed.start_datetime ASC"
				: $wpdb->prepare(
					"SELECT DATE(ed.start_datetime) AS start_date, DATE(ed.end_datetime) AS end_date
					FROM {$wpdb->posts} p
					INNER JOIN {$ed_table} ed ON p.ID = ed.post_id
					{$joins}
					WHERE {$where}
					ORDER BY ed.start_datetime ASC",
					...$query_values
				)
		);

		$total_events    = count( $rows );
		$events_per_date = array();

		foreach ( $rows as $row ) {
			$events_per_date[ $row->start_date ] = ( $events_per_date[ $row->start_date ] ?? 0 ) + 1;

			// Multi-day: expand to each spanned date after the start.
			if ( $row->end_date && $row->end_date > $row->start_date ) {
				$current = new \DateTime( $row->start_date );
				$current->modify( '+1 day' );
				$end_dt = new \DateTime( $row->end_date );

				while ( $current <= $end_dt ) {
					$date = $current->format( 'Y-m-d' );

					if ( ! $show_past_param && $date < $current_date ) {
						$current->modify( '+1 day' );
						continue;
					}

					$events_per_date[ $date ] = ( $events_per_date[ $date ] ?? 0 ) + 1;
					$current->modify( '+1 day' );
				}
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
