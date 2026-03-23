<?php
/**
 * Calendar Abilities
 *
 * Provides calendar data and HTML rendering via WordPress Abilities API.
 * Single source of truth for calendar page data used by render.php and CLI/MCP consumers.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DateTime;
use DataMachineEvents\Blocks\Calendar\Query\ScopeResolver;
use DataMachineEvents\Blocks\Calendar\Data\EventHydrator;
use DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper;
use DataMachineEvents\Blocks\Calendar\Display\EventRenderer;
use DataMachineEvents\Blocks\Calendar\Pagination;
use DataMachineEvents\Blocks\Calendar\Pagination\PageBoundary;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Blocks\Calendar\Template_Loader;
use DataMachineEvents\Core\EventDatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CalendarAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/get-calendar-page',
				array(
					'label'               => __( 'Get Calendar Page', 'data-machine-events' ),
					'description'         => __( 'Query paginated calendar events with optional filtering and HTML rendering', 'data-machine-events' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'paged'            => array(
								'type'        => 'integer',
								'description' => 'Page number (default: 1)',
							),
							'past'             => array(
								'type'        => 'boolean',
								'description' => 'Show past events (default: false)',
							),
							'event_search'     => array(
								'type'        => 'string',
								'description' => 'Search query string',
							),
							'date_start'       => array(
								'type'        => 'string',
								'description' => 'Start date filter (Y-m-d format)',
							),
							'date_end'         => array(
								'type'        => 'string',
								'description' => 'End date filter (Y-m-d format)',
							),
							'tax_filter'       => array(
								'type'        => 'object',
								'description' => 'Taxonomy filters [taxonomy => [term_ids]]',
							),
							'archive_taxonomy' => array(
								'type'        => 'string',
								'description' => 'Archive constraint taxonomy slug',
							),
							'archive_term_id'  => array(
								'type'        => 'integer',
								'description' => 'Archive constraint term ID',
							),
							'include_html'     => array(
								'type'        => 'boolean',
								'description' => 'Return rendered HTML (default: true)',
							),
							'include_gaps'     => array(
								'type'        => 'boolean',
								'description' => 'Include time-gap separators (default: true)',
							),
							'scope'            => array(
								'type'        => 'string',
								'description' => 'Time scope: today, tonight, this-weekend, this-week (overrides date_start/date_end when set)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'paged_date_groups' => array(
								'type'        => 'array',
								'description' => 'Date-grouped event data',
							),
							'gaps_detected'     => array(
								'type'        => 'object',
								'description' => 'Time gaps between dates [date_key => gap_days]',
							),
							'current_page'      => array( 'type' => 'integer' ),
							'max_pages'         => array( 'type' => 'integer' ),
							'total_event_count' => array( 'type' => 'integer' ),
							'event_count'       => array( 'type' => 'integer' ),
							'date_boundaries'   => array(
								'type'       => 'object',
								'properties' => array(
									'start_date' => array( 'type' => 'string' ),
									'end_date'   => array( 'type' => 'string' ),
								),
							),
							'event_counts'      => array(
								'type'       => 'object',
								'properties' => array(
									'past'   => array( 'type' => 'integer' ),
									'future' => array( 'type' => 'integer' ),
								),
							),
							'html'              => array(
								'type'       => 'object',
								'properties' => array(
									'events'     => array( 'type' => 'string' ),
									'pagination' => array( 'type' => 'string' ),
									'counter'    => array( 'type' => 'string' ),
									'navigation' => array( 'type' => 'string' ),
								),
							),
						),
					),
					'execute_callback'    => array( $this, 'executeGetCalendarPage' ),
					'permission_callback' => '__return_true',
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute get-calendar-page ability
	 *
	 * @param array $input Input parameters
	 * @return array Calendar page data with optional HTML
	 */
	public function executeGetCalendarPage( array $input ): array {
		$current_page = max( 1, (int) ( $input['paged'] ?? 1 ) );
		$show_past    = ! empty( $input['past'] );
		$include_html = $input['include_html'] ?? true;
		$include_gaps = $input['include_gaps'] ?? true;

		$search_query    = $input['event_search'] ?? '';
		$user_date_start = $input['date_start'] ?? '';
		$user_date_end   = $input['date_end'] ?? '';
		$tax_filters     = is_array( $input['tax_filter'] ?? null ) ? $input['tax_filter'] : array();

		// Resolve scope to date boundaries when user hasn't set explicit dates.
		$scope          = $input['scope'] ?? '';
		$scope_resolved = null;
		if ( $scope && empty( $user_date_start ) && empty( $user_date_end ) ) {
			$scope_resolved = ScopeResolver::resolve( $scope );
			if ( $scope_resolved ) {
				$user_date_start = $scope_resolved['date_start'];
				$user_date_end   = $scope_resolved['date_end'];
			}
		}

		$archive_taxonomy = sanitize_key( $input['archive_taxonomy'] ?? '' );
		$archive_term_id  = absint( $input['archive_term_id'] ?? 0 );

		$tax_query_override = null;
		if ( $archive_taxonomy && $archive_term_id ) {
			$tax_query_override = array(
				array(
					'taxonomy' => $archive_taxonomy,
					'field'    => 'term_id',
					'terms'    => $archive_term_id,
				),
			);
		}

		$base_params = array(
			'show_past'          => $show_past,
			'search_query'       => $search_query,
			'date_start'         => $user_date_start,
			'date_end'           => $user_date_end,
			'time_start'         => $scope_resolved['time_start'] ?? '',
			'time_end'           => $scope_resolved['time_end'] ?? '',
			'tax_filters'        => $tax_filters,
			'tax_query_override' => $tax_query_override,
			'archive_taxonomy'   => $archive_taxonomy,
			'archive_term_id'    => $archive_term_id,
			'source'             => 'ability',
			'user_date_range'    => ! empty( $user_date_start ) || ! empty( $user_date_end ),
			'geo_lat'            => $input['geo_lat'] ?? '',
			'geo_lng'            => $input['geo_lng'] ?? '',
			'geo_radius'         => $input['geo_radius'] ?? 25,
			'geo_radius_unit'    => $input['geo_radius_unit'] ?? 'mi',
		);

		$date_data         = self::get_unique_event_dates( $base_params );
		$unique_dates      = $date_data['dates'];
		$total_event_count = $date_data['total_events'];
		$events_per_date   = $date_data['events_per_date'];

		$date_boundaries = PageBoundary::get_date_boundaries_for_page(
			$unique_dates,
			$current_page,
			$total_event_count,
			$events_per_date
		);

		$max_pages    = $date_boundaries['max_pages'];
		$current_page = max( 1, min( $current_page, max( 1, $max_pages ) ) );

		$query_params = $base_params;
		$range_start  = '';
		$range_end    = '';

		if ( ! empty( $date_boundaries['start_date'] ) && ! empty( $date_boundaries['end_date'] ) ) {
			$range_start = $show_past ? $date_boundaries['end_date'] : $date_boundaries['start_date'];
			$range_end   = $show_past ? $date_boundaries['start_date'] : $date_boundaries['end_date'];

			if ( empty( $user_date_start ) ) {
				$query_params['date_start'] = $range_start;
			}
			if ( empty( $user_date_end ) ) {
				$query_params['date_end'] = $range_end;
			}
		}

		// Determine progressive rendering: only query the first day's events
		// when the page has enough events to benefit from deferred loading.
		$progressive    = $input['progressive'] ?? false;
		$deferred_dates = array();

		if ( $progressive && $range_start && $range_end ) {
			// Get the dates within this page's range.
			$page_dates = array_filter(
				$unique_dates,
				function ( $d ) use ( $range_start, $range_end ) {
					return $d >= $range_start && $d <= $range_end;
				}
			);
			$page_dates = array_values( $page_dates );

			// Only go progressive if enough events on this page.
			$page_event_total = 0;
			foreach ( $page_dates as $d ) {
				$page_event_total += $events_per_date[ $d ] ?? 0;
			}

			if ( $page_event_total >= EventRenderer::PROGRESSIVE_THRESHOLD && count( $page_dates ) > 1 ) {
				// Query only the first day.
				$first_date                 = $page_dates[0];
				$query_params['date_start'] = $first_date;
				$query_params['date_end']   = $first_date;
				$deferred_dates             = array_slice( $page_dates, 1 );
			}
		}

		// Build ability input from query_params.
		$ability_input = array(
			'scope'       => $query_params['show_past'] ? 'past' : 'upcoming',
			'tax_filters' => $query_params['tax_filters'],
			'search'      => $query_params['search_query'],
			'order'       => $query_params['show_past'] ? 'DESC' : 'ASC',
		);

		// Date range overrides scope.
		if ( ! empty( $query_params['date_start'] ) || ! empty( $query_params['date_end'] ) ) {
			$ability_input['date_start'] = $query_params['date_start'];
			$ability_input['date_end']   = $query_params['date_end'];
			$ability_input['time_start'] = $query_params['time_start'] ?? '';
			$ability_input['time_end']   = $query_params['time_end'] ?? '';
			// When user provides explicit dates, don't add scope filter.
			if ( $query_params['user_date_range'] ) {
				$ability_input['scope'] = 'all';
			}
		}

		// Taxonomy archive constraint.
		if ( ! empty( $query_params['archive_taxonomy'] ) && ! empty( $query_params['archive_term_id'] ) ) {
			$ability_input['tax_filters'][ $query_params['archive_taxonomy'] ] = array( (int) $query_params['archive_term_id'] );
		}

		// Apply calendar_base_query filter.
		$tax_query_override = apply_filters(
			'data_machine_events_calendar_base_query',
			null,
			array(
				'archive_taxonomy' => $query_params['archive_taxonomy'],
				'archive_term_id'  => $query_params['archive_term_id'],
				'source'           => 'ability',
			)
		);
		if ( $tax_query_override ) {
			foreach ( $tax_query_override as $clause ) {
				if ( isset( $clause['taxonomy'] ) && isset( $clause['terms'] ) ) {
					$ability_input['tax_filters'][ $clause['taxonomy'] ] = (array) $clause['terms'];
				}
			}
		}

		// Geo.
		if ( ! empty( $query_params['geo_lat'] ) && ! empty( $query_params['geo_lng'] ) ) {
			$ability_input['geo'] = array(
				'lat'    => (float) $query_params['geo_lat'],
				'lng'    => (float) $query_params['geo_lng'],
				'radius' => (float) ( $query_params['geo_radius'] ?? 25 ),
				'unit'   => $query_params['geo_radius_unit'] ?? 'mi',
			);
		}

		$event_date_query = new \DataMachineEvents\Abilities\EventDateQueryAbilities();
		$query_result     = $event_date_query->executeQueryEvents( $ability_input );

		$event_counts = self::compute_event_counts_via_ability();

		// Build paged_events from ability result posts (replaces DateGrouper::build_paged_events
		// which requires a WP_Query object — we have raw WP_Post objects from the ability).
		$paged_events = self::build_paged_events_from_posts( $query_result['posts'] );
		$paged_date_groups = DateGrouper::group_events_by_date(
			$paged_events,
			$show_past,
			$query_params['date_start'],
			$query_params['date_end']
		);

		$gaps_detected = array();
		if ( $include_gaps && ! empty( $paged_date_groups ) ) {
			$gaps_detected = DateGrouper::detect_time_gaps( $paged_date_groups );
		}

		$result = array(
			'paged_date_groups' => $this->serializeDateGroups( $paged_date_groups ),
			'gaps_detected'     => $gaps_detected,
			'current_page'      => $current_page,
			'max_pages'         => $max_pages,
			'total_event_count' => $total_event_count,
			'event_count'       => $query_result['post_count'],
			'date_boundaries'   => array(
				'start_date' => $date_boundaries['start_date'],
				'end_date'   => $date_boundaries['end_date'],
			),
			'event_counts'      => array(
				'past'   => $event_counts['past'],
				'future' => $event_counts['future'],
			),
			'deferred_dates'    => $deferred_dates,
		);

		if ( $include_html ) {
			Template_Loader::init();
			$result['html'] = $this->renderHtml(
				$paged_date_groups,
				$gaps_detected,
				$include_gaps,
				$current_page,
				$max_pages,
				$show_past,
				$date_boundaries,
				$query_result['post_count'],
				$total_event_count,
				$event_counts,
				$deferred_dates,
				$events_per_date
			);
		}

		wp_reset_postdata();

		return $result;
	}

	/**
	 * Build paged events array from raw WP_Post objects.
	 *
	 * Mirrors DateGrouper::build_paged_events() but operates on a plain
	 * array of WP_Post objects instead of requiring a WP_Query instance.
	 *
	 * @param array $posts Array of WP_Post objects.
	 * @return array Array of event items with post, datetime, and event_data.
	 */
	private static function build_paged_events_from_posts( array $posts ): array {
		$paged_events = array();

		foreach ( $posts as $event_post ) {
			$event_data = EventHydrator::parse_event_data( $event_post );

			if ( $event_data ) {
				$start_time     = $event_data['startTime'] ?? '00:00:00';
				$event_tz       = DateGrouper::get_event_timezone( $event_data );
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

		return $paged_events;
	}

	/**
	 * Compute past/future event counts via the query-events ability (cached).
	 *
	 * @return array ['past' => int, 'future' => int]
	 */
	private static function compute_event_counts_via_ability(): array {
		$cache_key = 'data-machine_cal_counts';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$ability = new \DataMachineEvents\Abilities\EventDateQueryAbilities();

		$future = $ability->executeQueryEvents( array( 'scope' => 'upcoming', 'fields' => 'count' ) );
		$past   = $ability->executeQueryEvents( array( 'scope' => 'past', 'fields' => 'count' ) );

		$result = array(
			'past'   => $past['total'],
			'future' => $future['total'],
		);

		set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Serialize date groups for JSON output
	 *
	 * @param array $paged_date_groups Date-grouped events
	 * @return array Serialized date groups
	 */
	private function serializeDateGroups( array $paged_date_groups ): array {
		$serialized = array();

		foreach ( $paged_date_groups as $date_key => $date_group ) {
			$events = array();
			foreach ( $date_group['events'] as $event_item ) {
				$events[] = array(
					'post_id'         => $event_item['post']->ID,
					'title'           => $event_item['post']->post_title,
					'event_data'      => $event_item['event_data'],
					'display_context' => $event_item['display_context'] ?? array(),
				);
			}

			$serialized[] = array(
				'date'   => $date_key,
				'events' => $events,
			);
		}

		return $serialized;
	}

	/**
	 * Render HTML for calendar components
	 *
	 * @param array $paged_date_groups Date-grouped events
	 * @param array $gaps_detected Time gaps
	 * @param bool  $include_gaps Whether to include gap separators
	 * @param int   $current_page Current page number
	 * @param int   $max_pages Maximum pages
	 * @param bool  $show_past Whether showing past events
	 * @param array $date_boundaries Date boundary data
	 * @param int   $event_count Events on this page
	 * @param int   $total_event_count Total events across all pages
	 * @param array $event_counts Past/future counts
	 * @param array $deferred_dates Dates to render as deferred shells
	 * @param array $events_per_date Event counts per date for deferred shells
	 * @return array HTML strings for each component
	 */
	private function renderHtml(
		array $paged_date_groups,
		array $gaps_detected,
		bool $include_gaps,
		int $current_page,
		int $max_pages,
		bool $show_past,
		array $date_boundaries,
		int $event_count,
		int $total_event_count,
		array $event_counts,
		array $deferred_dates = array(),
		array $events_per_date = array()
	): array {
		$events_html = EventRenderer::render_date_groups( $paged_date_groups, $gaps_detected, $include_gaps, $deferred_dates, $events_per_date );

		$pagination_html = Pagination::render_pagination( $current_page, $max_pages, $show_past );

		ob_start();
		Template_Loader::include_template(
			'results-counter',
			array(
				'page_start_date' => $date_boundaries['start_date'],
				'page_end_date'   => $date_boundaries['end_date'],
				'event_count'     => $event_count,
				'total_events'    => $total_event_count,
			)
		);
		$counter_html = ob_get_clean();

		ob_start();
		Template_Loader::include_template(
			'navigation',
			array(
				'show_past'           => $show_past,
				'past_events_count'   => $event_counts['past'],
				'future_events_count' => $event_counts['future'],
			)
		);
		$navigation_html = ob_get_clean();

		return array(
			'events'     => $events_html,
			'pagination' => $pagination_html,
			'counter'    => $counter_html,
			'navigation' => $navigation_html,
		);
	}

	/**
	 * Get unique event dates for pagination calculations (cached).
	 *
	 * Multi-day events are expanded to count on each spanned date.
	 *
	 * @param array $params Query parameters.
	 * @return array {
	 *     @type array $dates           Ordered array of unique date strings (Y-m-d).
	 *     @type int   $total_events    Total number of matching events.
	 *     @type array $events_per_date Event counts keyed by date.
	 * }
	 */
	private static function get_unique_event_dates( array $params ): array {
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
	 * to count on each spanned date.
	 *
	 * @param array $params Query parameters.
	 * @return array Event dates data.
	 */
	private static function compute_unique_event_dates( array $params ): array {
		global $wpdb;

		$show_past_param = $params['show_past'] ?? false;
		$current_date    = current_time( 'Y-m-d' );
		$ed_table        = EventDatesTable::table_name();

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
		$tax_filters  = $params['tax_filters'] ?? array();
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
}
