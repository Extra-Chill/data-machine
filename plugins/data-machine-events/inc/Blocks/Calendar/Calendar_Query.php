<?php
/**
 * Calendar Query — Facade
 *
 * Thin delegation layer preserving the existing public API while
 * forwarding all work to focused modules. CalendarAbilities and any
 * other callers continue to use Calendar_Query::method() unchanged.
 *
 * Phase 2 will have callers use the modules directly, at which point
 * this file can be deleted.
 *
 * @package DataMachineEvents\Blocks\Calendar
 * @since   0.14.0
 * @see     Query\EventQueryBuilder
 * @see     Data\EventHydrator
 * @see     Grouping\DateGrouper
 * @see     Grouping\MultiDayResolver
 * @see     Display\DisplayVars
 * @see     Display\EventRenderer
 * @see     Pagination\PageBoundary
 * @see     Cache\CalendarCache
 */

namespace DataMachineEvents\Blocks\Calendar;

use WP_Query;
use DataMachineEvents\Blocks\Calendar\Query\EventQueryBuilder;
use DataMachineEvents\Blocks\Calendar\Data\EventHydrator;
use DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper;
use DataMachineEvents\Blocks\Calendar\Display\DisplayVars;
use DataMachineEvents\Blocks\Calendar\Display\EventRenderer;
use DataMachineEvents\Blocks\Calendar\Pagination\PageBoundary;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Calendar_Query {

	/**
	 * Cache key prefix — kept for backward compatibility with Cache_Invalidator.
	 *
	 * @deprecated Use CalendarCache::PREFIX directly.
	 */
	const CACHE_PREFIX = 'datamachine_cal_';

	/**
	 * Build WP_Query arguments for calendar events.
	 *
	 * @param array $params Query parameters.
	 * @return array WP_Query arguments.
	 */
	public static function build_query_args( array $params ): array {
		return EventQueryBuilder::build_query_args( $params );
	}

	/**
	 * Get past and future event counts.
	 *
	 * @return array ['past' => int, 'future' => int]
	 */
	public static function get_event_counts(): array {
		return EventQueryBuilder::get_event_counts();
	}

	/**
	 * Parse event data from post, hydrating from authoritative sources.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array|null Event data array or null if not found.
	 */
	public static function parse_event_data( \WP_Post $post ): ?array {
		return EventHydrator::parse_event_data( $post );
	}

	/**
	 * Build paged events array from WP_Query.
	 *
	 * @param WP_Query $query Events query.
	 * @return array Array of event items.
	 */
	public static function build_paged_events( WP_Query $query ): array {
		return DateGrouper::build_paged_events( $query );
	}

	/**
	 * Group events by date, expanding multi-day events.
	 *
	 * @param array  $paged_events Array of event items.
	 * @param bool   $show_past    Whether showing past events.
	 * @param string $date_start   Optional start date boundary.
	 * @param string $date_end     Optional end date boundary.
	 * @return array Date-grouped events.
	 */
	public static function group_events_by_date( array $paged_events, bool $show_past = false, string $date_start = '', string $date_end = '' ): array {
		return DateGrouper::group_events_by_date( $paged_events, $show_past, $date_start, $date_end );
	}

	/**
	 * Build display variables for an event.
	 *
	 * @param array $event_data      Event data from block attributes.
	 * @param array $display_context Optional display context.
	 * @return array Display variables.
	 */
	public static function build_display_vars( array $event_data, array $display_context = array() ): array {
		return DisplayVars::build( $event_data, $display_context );
	}

	/**
	 * Decode unicode escape sequences in strings.
	 *
	 * @param string $str Input string.
	 * @return string Decoded string.
	 */
	public static function decode_unicode( string $str ): string {
		return DisplayVars::decode_unicode( $str );
	}

	/**
	 * Detect time gaps between date groups.
	 *
	 * @param array $date_groups Date-grouped events.
	 * @return array Map of date_key => gap_days.
	 */
	public static function detect_time_gaps( array $date_groups ): array {
		return DateGrouper::detect_time_gaps( $date_groups );
	}

	/**
	 * Render date groups as HTML.
	 *
	 * @param array $paged_date_groups Date-grouped events.
	 * @param array $gaps_detected     Time gaps.
	 * @param bool  $include_gaps      Whether to render gap separators.
	 * @return string Rendered HTML.
	 */
	public static function render_date_groups(
		array $paged_date_groups,
		array $gaps_detected = array(),
		bool $include_gaps = true
	): string {
		return EventRenderer::render_date_groups( $paged_date_groups, $gaps_detected, $include_gaps );
	}

	/**
	 * Get unique event dates for pagination calculations.
	 *
	 * @param array $params Query parameters.
	 * @return array Event dates data.
	 */
	public static function get_unique_event_dates( array $params ): array {
		return PageBoundary::get_unique_event_dates( $params );
	}

	/**
	 * Get date boundaries for a specific page.
	 *
	 * @param array $unique_dates    Ordered array of unique dates.
	 * @param int   $page            Page number (1-based).
	 * @param int   $total_events    Total event count.
	 * @param array $events_per_date Event counts keyed by date.
	 * @return array Date boundaries.
	 */
	public static function get_date_boundaries_for_page( array $unique_dates, int $page, int $total_events = 0, array $events_per_date = array() ): array {
		return PageBoundary::get_date_boundaries_for_page( $unique_dates, $page, $total_events, $events_per_date );
	}
}
