<?php
/**
 * Date Filter — centralized "upcoming" vs "past" event definition.
 *
 * Single source of truth for the condition that determines whether an
 * event is upcoming or past. Exposes the logic in two formats:
 *
 *  - apply_*_filter(): posts_clauses hooks for WP_Query consumers
 *  - *_sql():          Raw SQL joins + WHERE fragments for raw queries
 *
 * Definition:
 *   upcoming = start >= $datetime  OR  end >= $datetime
 *   past     = start <  $datetime  AND (end < $datetime OR end IS NULL)
 *
 * All queries delegate to UpcomingFilter as the single source of truth
 * for event date conditions.
 *
 * @package DataMachineEvents\Blocks\Calendar\Query
 * @since   0.19.0
 * @see     UpcomingFilter
 */

namespace DataMachineEvents\Blocks\Calendar\Query;

use DataMachineEvents\Core\EventDatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DateFilter {

	/**
	 * Apply "upcoming" date filter via posts_clauses.
	 *
	 * Adds a JOIN on the event_dates table and a WHERE clause that selects
 	 * events whose start_datetime >= $datetime OR end_datetime >= $datetime.
	 * Delegates to UpcomingFilter for the WHERE clause.
	 *
	 * @param string $datetime MySQL datetime to compare against.
	 * @return callable Filter callback (must be removed after WP_Query runs).
	 */
	public static function apply_upcoming_filter( string $datetime ): callable {
		$filter = function ( $clauses ) use ( $datetime ) {
			global $wpdb;
			$table = EventDatesTable::table_name();

			if ( strpos( $clauses['join'], $table ) === false ) {
				$clauses['join'] .= " INNER JOIN {$table} AS ed ON {$wpdb->posts}.ID = ed.post_id";
			}

			$clauses['where'] .= ' AND ' . UpcomingFilter::upcoming_where( $datetime );

			return $clauses;
		};

		add_filter( 'posts_clauses', $filter, 10, 1 );
		return $filter;
	}

	/**
	 * Apply "past" date filter via posts_clauses.
	 *
	 * @param string $datetime MySQL datetime to compare against.
	 * @return callable Filter callback.
	 */
	public static function apply_past_filter( string $datetime ): callable {
		$filter = function ( $clauses ) use ( $datetime ) {
			global $wpdb;
			$table = EventDatesTable::table_name();

			if ( strpos( $clauses['join'], $table ) === false ) {
				$clauses['join'] .= " INNER JOIN {$table} AS ed ON {$wpdb->posts}.ID = ed.post_id";
			}

			$clauses['where'] .= ' AND ' . UpcomingFilter::past_where( $datetime );

			return $clauses;
		};

		add_filter( 'posts_clauses', $filter, 10, 1 );
		return $filter;
	}

	/**
	 * Apply event date ordering via posts_clauses.
	 *
	 * Ensures the ed JOIN exists and overrides ORDER BY to use ed.start_datetime.
	 *
	 * @param string $direction 'ASC' or 'DESC'.
	 * @return callable Filter callback.
	 */
	public static function apply_date_orderby( string $direction = 'ASC' ): callable {
		$direction = strtoupper( $direction ) === 'DESC' ? 'DESC' : 'ASC';

		$filter = function ( $clauses ) use ( $direction ) {
			global $wpdb;
			$table = EventDatesTable::table_name();

			if ( strpos( $clauses['join'], $table ) === false ) {
				$clauses['join'] .= " INNER JOIN {$table} AS ed ON {$wpdb->posts}.ID = ed.post_id";
			}

			$clauses['orderby'] = "ed.start_datetime {$direction}";

			return $clauses;
		};

		add_filter( 'posts_clauses', $filter, 10, 1 );
		return $filter;
	}

	/**
	 * Raw SQL fragments for upcoming events.
	 *
	 * Delegates to UpcomingFilter for index-optimized UNION ALL query.
	 *
	 * @param bool   $include_status Whether to include post_status filter. Default true.
	 * @param string $join_column    Column to join ed.post_id on. Default 'p.ID'.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function upcoming_sql( bool $include_status = true, string $join_column = 'p.ID' ): array {
		return UpcomingFilter::upcoming_sql( $include_status, $join_column );
	}

	/**
	 * Raw SQL fragments for past events.
	 *
	 * @param bool   $include_status Whether to include post_status filter. Default true.
	 * @param string $join_column    Column to join ed.post_id on. Default 'p.ID'.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function past_sql( bool $include_status = true, string $join_column = 'p.ID' ): array {
		return UpcomingFilter::past_sql( $include_status, $join_column );
	}

	/**
	 * Raw SQL fragments for a date range filter.
	 *
	 * @param bool   $include_status Whether to include post_status filter. Default true.
	 * @param string $join_column    Column to join ed.post_id on. Default 'p.ID'.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function date_range_sql( bool $include_status = true, string $join_column = 'p.ID' ): array {
		return UpcomingFilter::date_range_sql( $include_status, $join_column );
	}
}
