<?php
/**
 * Upcoming Filter — centralized "upcoming" and "past" event date conditions.
 *
 * Single source of truth for the SQL conditions that determine whether
 * an event is upcoming or past. All consumers (DateFilter, EventDateQueryAbilities,
 * FilterAbilities) delegate here.
 *
 * Definition:
 *   upcoming = start >= $datetime  OR  end >= $datetime
 *   past     = start <  $datetime  AND (end < $datetime OR end IS NULL)
 *
 * effective_end is the stored COALESCE(end_datetime, start_datetime).
 * Valid event ends are on/after their starts, so this is equivalent to the
 * original OR predicate and permits a status_effective_end range scan.
 *
 * @package DataMachineEvents\Blocks\Calendar\Query
 * @since   0.25.0
 * @see     https://github.com/Extra-Chill/data-machine-events/issues/175
 */

namespace DataMachineEvents\Blocks\Calendar\Query;

use DataMachineEvents\Core\EventDatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpcomingFilter {

	/**
	 * Build a WHERE clause for upcoming events.
	 *
	 * For use inside posts_clauses filters where the outer query already
	 * joins the event_dates table as "ed".
	 *
	 * @param string $datetime MySQL datetime to compare against.
	 * @return string Prepared WHERE fragment (without leading AND).
	 */
	public static function upcoming_where( string $datetime ): string {
		global $wpdb;

		return $wpdb->prepare(
			"(ed.post_status = 'publish' AND ed.effective_end >= %s)",
			$datetime
		);
	}

	/**
	 * Build a WHERE clause for upcoming events with a bounded lookahead.
	 *
	 * @param string $datetime MySQL datetime for the lower bound (now).
	 * @param string $end_date MySQL datetime for the upper bound.
	 * @return string Prepared WHERE fragment (without leading AND).
	 */
	public static function upcoming_bounded_where( string $datetime, string $end_date ): string {
		global $wpdb;

		return $wpdb->prepare(
			"(ed.post_status = 'publish' AND ed.effective_end >= %s AND ed.start_datetime <= %s)",
			$datetime,
			$end_date
		);
	}

	/**
	 * Build a WHERE clause for a date range start.
	 *
	 * Captures events that start on/after the given datetime OR are
	 * still in progress at that datetime.
	 *
	 * @param string $start_dt MySQL datetime for range start.
	 * @return string Prepared WHERE fragment (without leading AND).
	 */
	public static function range_start_where( string $start_dt ): string {
		global $wpdb;

		return $wpdb->prepare(
			'(ed.effective_end >= %s)',
			$start_dt
		);
	}

	/**
	 * Build a WHERE clause for past events.
	 *
	 * @param string $datetime MySQL datetime to compare against.
	 * @return string Prepared WHERE fragment (without leading AND).
	 */
	public static function past_where( string $datetime ): string {
		global $wpdb;

		return $wpdb->prepare(
			'(ed.start_datetime < %s AND (ed.end_datetime < %s OR ed.end_datetime IS NULL))',
			$datetime,
			$datetime
		);
	}

	/**
	 * Raw SQL fragments for upcoming events (for direct $wpdb queries).
	 *
	 * Uses ed.post_status = 'publish' to avoid joining the posts table.
	 * Set $include_status to false if the caller already joins posts and
	 * filters post_status there.
	 *
	 * @param bool   $include_status Whether to include post_status filter. Default true.
	 * @param string $join_column    Column to join ed.post_id on. Default 'p.ID'.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function upcoming_sql( bool $include_status = true, string $join_column = 'p.ID' ): array {
		$table         = EventDatesTable::table_name();
		$status_clause = $include_status ? " AND ed.post_status = 'publish'" : '';

		return array(
			'joins'       => "INNER JOIN {$table} ed ON {$join_column} = ed.post_id",
			'where'       => "(ed.effective_end >= %s){$status_clause}",
			'param_count' => 1,
		);
	}

	/**
	 * Raw SQL fragments for past events (for direct $wpdb queries).
	 *
	 * @param bool   $include_status Whether to include post_status filter. Default true.
	 * @param string $join_column    Column to join ed.post_id on. Default 'p.ID'.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function past_sql( bool $include_status = true, string $join_column = 'p.ID' ): array {
		$table         = EventDatesTable::table_name();
		$status_clause = $include_status ? " AND ed.post_status = 'publish'" : '';

		return array(
			'joins'       => "INNER JOIN {$table} ed ON {$join_column} = ed.post_id",
			'where'       => "(ed.start_datetime < %s AND (ed.end_datetime < %s OR ed.end_datetime IS NULL)){$status_clause}",
			'param_count' => 2,
		);
	}

	/**
	 * Raw SQL fragments for a date range filter (for direct $wpdb queries).
	 *
	 * @param bool   $include_status Whether to include post_status filter. Default true.
	 * @param string $join_column    Column to join ed.post_id on. Default 'p.ID'.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function date_range_sql( bool $include_status = true, string $join_column = 'p.ID' ): array {
		$table         = EventDatesTable::table_name();
		$status_clause = $include_status ? " AND ed.post_status = 'publish'" : '';

		return array(
			'joins'       => "INNER JOIN {$table} ed ON {$join_column} = ed.post_id",
			'where'       => "(ed.start_datetime >= %s AND ed.start_datetime <= %s){$status_clause}",
			'param_count' => 2,
		);
	}
}
