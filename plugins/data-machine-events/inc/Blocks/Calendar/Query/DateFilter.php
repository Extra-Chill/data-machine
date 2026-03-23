<?php
/**
 * Date Filter — centralized "upcoming" vs "past" event definition.
 *
 * Single source of truth for the condition that determines whether an
 * event is upcoming or past. Exposes the logic in two formats:
 *
 *  - meta_query():    WP_Query meta_query arrays (for EventQueryBuilder)
 *  - sql():           Raw SQL joins + WHERE fragments (for Taxonomy_Helper,
 *                     PageBoundary, calendar-stats, and any other raw query)
 *
 * Definition:
 *   upcoming = start >= $datetime  OR  end >= $datetime
 *   past     = start <  $datetime  AND (end < $datetime OR end IS NULL)
 *
 * @package DataMachineEvents\Blocks\Calendar\Query
 * @since   0.19.0
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

			$date_where = $wpdb->prepare(
				'(ed.start_datetime >= %s OR ed.end_datetime >= %s)',
				$datetime,
				$datetime
			);
			$clauses['where'] .= " AND {$date_where}";

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

			$date_where = $wpdb->prepare(
				'(ed.start_datetime < %s AND (ed.end_datetime < %s OR ed.end_datetime IS NULL))',
				$datetime,
				$datetime
			);
			$clauses['where'] .= " AND {$date_where}";

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
	 * Uses ed.post_status = 'publish' to avoid joining the posts table.
	 * Set $include_status to false if the caller already joins posts and
	 * filters post_status there (e.g. WP_Query consumers).
	 *
	 * @param bool   $include_status Whether to include post_status filter. Default true.
	 * @param string $join_column    Column to join ed.post_id on. Default 'p.ID'.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function upcoming_sql( bool $include_status = true, string $join_column = 'p.ID' ): array {
		$table = EventDatesTable::table_name();
		$status_clause = $include_status ? " AND ed.post_status = 'publish'" : '';

		return array(
			'joins'       => "INNER JOIN {$table} ed ON {$join_column} = ed.post_id",
			'where'       => "(ed.start_datetime >= %s OR ed.end_datetime >= %s){$status_clause}",
			'param_count' => 2,
		);
	}

	/**
	 * Raw SQL fragments for past events.
	 *
	 * @param bool   $include_status Whether to include post_status filter. Default true.
	 * @param string $join_column    Column to join ed.post_id on. Default 'p.ID'.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function past_sql( bool $include_status = true, string $join_column = 'p.ID' ): array {
		$table = EventDatesTable::table_name();
		$status_clause = $include_status ? " AND ed.post_status = 'publish'" : '';

		return array(
			'joins'       => "INNER JOIN {$table} ed ON {$join_column} = ed.post_id",
			'where'       => "(ed.start_datetime < %s AND (ed.end_datetime < %s OR ed.end_datetime IS NULL)){$status_clause}",
			'param_count' => 2,
		);
	}

	/**
	 * Raw SQL fragments for a date range filter.
	 *
	 * @param bool   $include_status Whether to include post_status filter. Default true.
	 * @param string $join_column    Column to join ed.post_id on. Default 'p.ID'.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function date_range_sql( bool $include_status = true, string $join_column = 'p.ID' ): array {
		$table = EventDatesTable::table_name();
		$status_clause = $include_status ? " AND ed.post_status = 'publish'" : '';

		return array(
			'joins'       => "INNER JOIN {$table} ed ON {$join_column} = ed.post_id",
			'where'       => "(ed.start_datetime >= %s AND ed.start_datetime <= %s){$status_clause}",
			'param_count' => 2,
		);
	}
}
