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

use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_END_DATETIME_META_KEY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DateFilter {

	/**
	 * WP_Query meta_query for upcoming events.
	 *
	 * @param string $datetime MySQL datetime to compare against.
	 * @return array meta_query clause.
	 */
	public static function upcoming_meta_query( string $datetime ): array {
		return array(
			'relation'    => 'OR',
			'event_start' => array(
				'key'     => EVENT_DATETIME_META_KEY,
				'value'   => $datetime,
				'compare' => '>=',
			),
			'event_end'   => array(
				'key'     => EVENT_END_DATETIME_META_KEY,
				'value'   => $datetime,
				'compare' => '>=',
			),
		);
	}

	/**
	 * WP_Query meta_query for past events.
	 *
	 * @param string $datetime MySQL datetime to compare against.
	 * @return array meta_query clauses (two entries for AND nesting).
	 */
	public static function past_meta_query( string $datetime ): array {
		return array(
			'relation'    => 'AND',
			'event_start' => array(
				'key'     => EVENT_DATETIME_META_KEY,
				'value'   => $datetime,
				'compare' => '<',
			),
			array(
				'relation'  => 'OR',
				'event_end' => array(
					'key'     => EVENT_END_DATETIME_META_KEY,
					'value'   => $datetime,
					'compare' => '<',
				),
				array(
					'key'     => EVENT_END_DATETIME_META_KEY,
					'compare' => 'NOT EXISTS',
				),
			),
		);
	}

	/**
	 * Raw SQL fragments for upcoming events.
	 *
	 * Returns JOIN and WHERE clauses. The caller must supply `$wpdb`
	 * table references and call `$wpdb->prepare()` on the WHERE.
	 *
	 * The returned WHERE uses `%s` placeholders — pass `$datetime` twice
	 * as the corresponding prepare() values.
	 *
	 * @param string $postmeta_table Full postmeta table name (e.g. $wpdb->postmeta).
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function upcoming_sql( string $postmeta_table ): array {
		$start_key = esc_sql( EVENT_DATETIME_META_KEY );
		$end_key   = esc_sql( EVENT_END_DATETIME_META_KEY );

		return array(
			'joins'       => "INNER JOIN {$postmeta_table} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '{$start_key}'"
				. " LEFT JOIN {$postmeta_table} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = '{$end_key}'",
			'where'       => '(pm_start.meta_value >= %s OR pm_end.meta_value >= %s)',
			'param_count' => 2,
		);
	}

	/**
	 * Raw SQL fragments for past events.
	 *
	 * @param string $postmeta_table Full postmeta table name.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function past_sql( string $postmeta_table ): array {
		$start_key = esc_sql( EVENT_DATETIME_META_KEY );
		$end_key   = esc_sql( EVENT_END_DATETIME_META_KEY );

		return array(
			'joins'       => "INNER JOIN {$postmeta_table} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '{$start_key}'"
				. " LEFT JOIN {$postmeta_table} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = '{$end_key}'",
			'where'       => '(pm_start.meta_value < %s AND (pm_end.meta_value < %s OR pm_end.meta_value IS NULL))',
			'param_count' => 2,
		);
	}

	/**
	 * Raw SQL fragments for a date range filter.
	 *
	 * Filters events whose start_datetime falls within the range.
	 * Also includes the standard start/end JOIN for consistency.
	 *
	 * @param string $postmeta_table Full postmeta table name.
	 * @return array{joins: string, where: string, param_count: int}
	 */
	public static function date_range_sql( string $postmeta_table ): array {
		$start_key = esc_sql( EVENT_DATETIME_META_KEY );
		$end_key   = esc_sql( EVENT_END_DATETIME_META_KEY );

		return array(
			'joins'       => "INNER JOIN {$postmeta_table} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '{$start_key}'"
				. " LEFT JOIN {$postmeta_table} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = '{$end_key}'",
			'where'       => '(pm_start.meta_value >= %s AND pm_start.meta_value <= %s)',
			'param_count' => 2,
		);
	}
}
