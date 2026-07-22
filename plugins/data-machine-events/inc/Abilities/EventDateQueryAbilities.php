<?php
/**
 * Event Date Query Abilities
 *
 * The single primitive for all event date queries. Replaces inline
 * posts_clauses filters, DateFilter calls, and EventQueryBuilder
 * across the entire codebase.
 *
 * All consumers that need "give me events filtered by date scope"
 * should call this ability instead of building their own WP_Query.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.24.0
 */

namespace DataMachineEvents\Abilities;

use WP_Query;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Blocks\Calendar\Query\ScopeResolver;
use DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventDateQueryAbilities {
	private const CAPTURE_IDS_QUERY_VAR  = '_data_machine_events_capture_ids_sql';
	private const MAX_PUBLIC_RESULTS     = 100;
	private const DEFAULT_PUBLIC_RESULTS = 50;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/query-events',
				array(
					'label'               => __( 'Query Events', 'data-machine-events' ),
					'description'         => __( 'Query events filtered by date scope, taxonomy, geo, and search. The single primitive for all event date queries.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'scope'       => array(
								'type'        => 'string',
								'enum'        => array( 'upcoming', 'past', 'all' ),
								'description' => 'Date scope filter. Default: upcoming.',
							),
							'date_start'  => array(
								'type'        => 'string',
								'description' => 'Range start date (YYYY-MM-DD). Overrides scope when set.',
							),
							'date_end'    => array(
								'type'        => 'string',
								'description' => 'Range end date (YYYY-MM-DD). Overrides scope when set.',
							),
							'date_match'  => array(
								'type'        => 'string',
								'description' => 'Exact date match (YYYY-MM-DD). For duplicate detection queries.',
							),
							'days_ahead'  => array(
								'type'        => 'integer',
								'description' => 'Bounded lookahead in days for upcoming scope. 0 = unlimited.',
							),
							'time_start'  => array(
								'type'        => 'string',
								'description' => 'Time bound start (HH:MM:SS). Used with date_start.',
							),
							'time_end'    => array(
								'type'        => 'string',
								'description' => 'Time bound end (HH:MM:SS). Used with date_end.',
							),
							'time_scope'  => array(
								'type'        => 'string',
								'enum'        => array( 'today', 'tonight', 'this-weekend', 'this-week' ),
								'description' => 'Named time scope that resolves to a concrete date/time window via ScopeResolver (today, tonight, this-weekend, this-week). The resolved window is applied through the same UpcomingFilter date-range path the calendar list uses, so count and list never drift. Explicit date_start/date_end take precedence and skip resolution. #428.',
							),
							'tax_filters' => array(
								'type'        => 'object',
								'description' => 'Taxonomy filters as { taxonomy_slug: [term_ids] }.',
							),
							'search'      => array(
								'type'        => 'string',
								'description' => 'Search query string.',
							),
							'geo'         => array(
								'type'       => 'object',
								'anyOf'      => array(
									array( 'required' => array( 'lat', 'lng' ) ),
									array(
										'required'   => array( 'empty_result_behavior' ),
										'properties' => array(
											'empty_result_behavior' => array( 'enum' => array( 'ignore_geo' ) ),
										),
									),
								),
								'properties' => array(
									'lat'    => array(
										'type'    => 'number',
										'minimum' => -90,
										'maximum' => 90,
									),
									'lng'    => array(
										'type'    => 'number',
										'minimum' => -180,
										'maximum' => 180,
									),
									'radius' => array( 'type' => 'number' ),
									'unit'   => array(
										'type' => 'string',
										'enum' => array( 'mi', 'km' ),
									),
									'empty_result_behavior' => array(
										'type'        => 'string',
										'enum'        => array( 'empty', 'ignore_geo' ),
										'description' => 'Behavior when no venues are inside the radius. Default: empty. Use ignore_geo to explicitly fall back to the remaining filters.',
									),
								),
							),
							'exclude'     => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'integer' ),
								'description' => 'Post IDs to exclude.',
							),
							'per_page'    => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => self::MAX_PUBLIC_RESULTS,
								'description' => 'Events per page. Default: 50. Maximum: 100.',
							),
							'fields'      => array(
								'type'        => 'string',
								'enum'        => array( 'all', 'ids', 'count' ),
								'description' => 'Return format: all (structured events), ids (post IDs), count (just total). Default: all.',
							),
							'order'       => array(
								'type'        => 'string',
								'enum'        => array( 'ASC', 'DESC' ),
								'description' => 'Sort direction for event start_datetime. Default: ASC.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'posts'      => array(
								'type'        => 'array',
								'description' => 'Structured published events, post IDs, or empty (for count mode).',
								'items'       => array(
									'oneOf' => array(
										array( 'type' => 'integer' ),
										array(
											'type'       => 'object',
											'properties' => array(
												'event_id' => array( 'type' => 'integer' ),
												'title'    => array( 'type' => 'string' ),
												'permalink' => array( 'type' => 'string' ),
												'start_datetime' => array( 'type' => 'string' ),
												'end_datetime' => array( 'type' => array( 'string', 'null' ) ),
											),
										),
									),
								),
							),
							'total'      => array(
								'type'        => 'integer',
								'description' => 'Total matching events (found_posts).',
							),
							'post_count' => array(
								'type'        => 'integer',
								'description' => 'Number of posts returned on this page.',
							),
						),
					),
					'execute_callback'    => array( $this, 'executePublicQueryEvents' ),
					'permission_callback' => '__return_true',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute the REST-visible, publish-only event query.
	 *
	 * The internal executeQueryEvents() method intentionally retains status and
	 * meta controls for authorized operational abilities and PHP callers. This
	 * public boundary strips those controls, caps hydration, and never exposes
	 * WP_Post objects.
	 *
	 * @param array $input Public input parameters.
	 * @return array { posts: array, total: int, post_count: int }
	 */
	public function executePublicQueryEvents( array $input ): array {
		unset( $input['status'], $input['meta_query'] );

		if ( array_key_exists( 'geo', $input ) ) {
			$geo        = is_array( $input['geo'] ) ? $input['geo'] : array();
			$ignore_geo = 'ignore_geo' === ( $geo['empty_result_behavior'] ?? 'empty' );
			$valid_geo  = array_key_exists( 'lat', $geo )
				&& array_key_exists( 'lng', $geo )
				&& class_exists( 'DataMachineEvents\\Blocks\\Calendar\\Geo_Query' )
				&& \DataMachineEvents\Blocks\Calendar\Geo_Query::validate_params( $geo['lat'], $geo['lng'], $geo['radius'] ?? 25 );

			if ( ! $valid_geo && ! $ignore_geo ) {
				return array(
					'posts'      => array(),
					'total'      => 0,
					'post_count' => 0,
				);
			}
		}

		$input['status']   = 'publish';
		$input['per_page'] = min(
			self::MAX_PUBLIC_RESULTS,
			max( 1, (int) ( $input['per_page'] ?? self::DEFAULT_PUBLIC_RESULTS ) )
		);

		$result = $this->executeQueryEvents( $input );
		if ( 'all' !== ( $input['fields'] ?? 'all' ) ) {
			return $result;
		}

		$result['posts']      = array_values(
			array_filter(
				array_map( array( $this, 'serializePublicEvent' ), $result['posts'] )
			)
		);
		$result['post_count'] = count( $result['posts'] );

		return $result;
	}

	/**
	 * Execute the query-events ability.
	 *
	 * @param array $input Input parameters.
	 * @return array { posts: array, total: int, post_count: int }
	 */
	public function executeQueryEvents( array $input ): array {
		$scope       = $input['scope'] ?? 'upcoming';
		$date_start  = $input['date_start'] ?? '';
		$date_end    = $input['date_end'] ?? '';
		$date_match  = $input['date_match'] ?? '';
		$days_ahead  = (int) ( $input['days_ahead'] ?? 0 );
		$time_start  = $input['time_start'] ?? '';
		$time_end    = $input['time_end'] ?? '';
		$time_scope  = isset( $input['time_scope'] ) ? sanitize_key( $input['time_scope'] ) : '';
		$tax_filters = is_array( $input['tax_filters'] ?? null ) ? $input['tax_filters'] : array();
		$search      = $input['search'] ?? '';
		$geo         = is_array( $input['geo'] ?? null ) ? $input['geo'] : array();
		$exclude     = is_array( $input['exclude'] ?? null ) ? array_map( 'absint', $input['exclude'] ) : array();
		$per_page    = (int) ( $input['per_page'] ?? -1 );
		$fields      = $input['fields'] ?? 'all';
		$order       = ! empty( $input[ self::CAPTURE_IDS_QUERY_VAR ] )
			? ''
			: ( strtoupper( $input['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC' );
		$status      = $input['status'] ?? 'publish';
		$meta_query  = is_array( $input['meta_query'] ?? null ) ? $input['meta_query'] : array();

		// #428: resolve a named time scope (today/tonight/this-weekend/
		// this-week) to concrete date/time boundaries via ScopeResolver —
		// the same source of truth the calendar LIST uses in
		// CalendarAbilities::executeGetCalendarPage(). The resolved window
		// flows into the existing date-range WHERE branch (buildDateClauses),
		// which applies UpcomingFilter::range_start_where + start_datetime
		// upper bound, so the count is constrained by the SAME primitive the
		// list uses and the two can never drift. Explicit date_start/date_end
		// from the caller take precedence (mirrors the list path, which only
		// resolves scope "when user hasn't set explicit dates"), so a caller
		// can still pin a precise window. Bare/unscoped requests (no
		// time_scope) are untouched and keep reporting total upcoming.
		if ( '' !== $time_scope && empty( $date_start ) && empty( $date_end ) ) {
			$resolved = ScopeResolver::resolve( $time_scope );
			if ( is_array( $resolved ) ) {
				$date_start = $resolved['date_start'] ?? '';
				$date_end   = $resolved['date_end'] ?? '';
				$time_start = $resolved['time_start'] ?? '';
				$time_end   = $resolved['time_end'] ?? '';
			}
		}

		// Build WP_Query args.
		$query_args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'no_found_rows'  => true, // Avoid deprecated SQL_CALC_FOUND_ROWS; use separate count.
			'orderby'        => 'none', // Ordering via posts_clauses.
		);

		if ( 'ids' === $fields ) {
			$query_args['fields'] = 'ids';
		}

		if ( ! empty( $input[ self::CAPTURE_IDS_QUERY_VAR ] ) ) {
			$query_args[ self::CAPTURE_IDS_QUERY_VAR ] = true;
		}

		if ( 'count' === $fields ) {
			$query_args['fields']         = 'ids';
			$query_args['posts_per_page'] = 1;
		}

		if ( ! empty( $exclude ) ) {
			$query_args['post__not_in'] = $exclude;
		}

		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Taxonomy filters.
		if ( ! empty( $tax_filters ) ) {
			$tax_query = array( 'relation' => 'AND' );

			foreach ( $tax_filters as $taxonomy => $term_ids ) {
				$term_ids    = is_array( $term_ids ) ? $term_ids : array( $term_ids );
				$tax_query[] = array(
					'taxonomy' => sanitize_key( $taxonomy ),
					'field'    => 'term_id',
					'terms'    => array_map( 'absint', $term_ids ),
					'operator' => 'IN',
				);
			}

			$query_args['tax_query'] = $tax_query;
		}

		// Geo filter (venue proximity). Any non-empty invalid geo envelope fails
		// closed unless the caller explicitly requests the documented fallback.
		if ( ! empty( $geo ) ) {
			$nearby_venue_ids = array();
			$ignore_empty_geo = 'ignore_geo' === ( $geo['empty_result_behavior'] ?? 'empty' );
			$has_coordinates  = array_key_exists( 'lat', $geo ) && array_key_exists( 'lng', $geo );
			$geo_radius       = $geo['radius'] ?? 25;
			$valid_geo        = $has_coordinates
				&& class_exists( 'DataMachineEvents\\Blocks\\Calendar\\Geo_Query' )
				&& \DataMachineEvents\Blocks\Calendar\Geo_Query::validate_params( $geo['lat'], $geo['lng'], $geo_radius );

			if ( $valid_geo ) {
				$nearby_venue_ids = \DataMachineEvents\Blocks\Calendar\Geo_Query::get_venue_ids_within_radius(
					(float) $geo['lat'],
					(float) $geo['lng'],
					(float) $geo_radius,
					$geo['unit'] ?? 'mi'
				);
			}

			if ( ! empty( $nearby_venue_ids ) || ! $ignore_empty_geo ) {
				$tax_query             = isset( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
				$tax_query['relation'] = 'AND';
				$tax_query[]           = array(
					'taxonomy' => 'venue',
					'field'    => 'term_id',
					'terms'    => empty( $nearby_venue_ids ) ? array( 0 ) : $nearby_venue_ids,
					'operator' => 'IN',
				);

				$query_args['tax_query'] = $tax_query;
			}
		}

		// Build the posts_clauses filter for date filtering + ordering.
		$filters = array();

		$clauses_filter = $this->buildDateClauses( $scope, $date_start, $date_end, $date_match, $days_ahead, $time_start, $time_end, $order );
		add_filter( 'posts_clauses', $clauses_filter );
		$filters[] = $clauses_filter;

		// For count-only mode, run a lightweight count query instead.
		if ( 'count' === $fields ) {
			$query_args['no_found_rows'] = false; // Need found_posts for count mode.
		}

		/**
		 * Filter the final WP_Query args before the events query runs.
		 *
		 * Extension point for platform-specific plugins to inject additional
		 * constraints (e.g. `post__in` to scope a calendar to events a
		 * specific user has attended). Replaces the dead-code filter of the
		 * same name on the deprecated EventQueryBuilder — the active code
		 * path is now EventDateQueryAbilities::executeQueryEvents, so the
		 * extension point lives here.
		 *
		 * Keeps data-machine-events generic (no platform-specific JOINs
		 * inside this plugin) by letting consumers add WP_Query-level
		 * constraints. The second argument is the raw ability input so
		 * callbacks can branch on scope, tax_filters, search, geo, etc.
		 *
		 * @since 0.40.0
		 *
		 * @param array $query_args WP_Query arguments about to be executed.
		 * @param array $input      The full ability input array.
		 */
		$query_args = (array) apply_filters( 'data_machine_events_calendar_query_args', $query_args, $input );

		// Execute query.
		$query = new WP_Query( $query_args );

		// Cleanup filters immediately.
		foreach ( $filters as $f ) {
			remove_filter( 'posts_clauses', $f );
		}

		$posts = $query->posts;
		$total = $query->post_count;

		if ( 'count' === $fields ) {
			$posts = array();
			$total = $query->found_posts;
		} elseif ( -1 === $per_page ) {
			// When fetching all posts, post_count IS the total.
			$total = $query->post_count;
		}

		// Log unbounded full-object queries for performance monitoring.
		// These can cause massive Redis MGET calls (14K+ keys) when the
		// events table is large. Tracks which caller triggered it.
		if ( -1 === $per_page && 'all' === $fields && $query->post_count > 100 ) {
			$this->logUnboundedQuery( $input, $query->post_count );
		}

		return array(
			'posts'      => $posts,
			'total'      => $total,
			'post_count' => $query->post_count,
		);
	}

	/**
	 * Build the canonical matching-post IDs SQL without executing it.
	 *
	 * The generated query includes the same WordPress search, taxonomy, geo,
	 * date, and consumer-supplied query-argument constraints as the row query.
	 * It is suitable for use as a derived table in aggregate queries, avoiding
	 * unbounded ID arrays and large placeholder lists in PHP.
	 *
	 * @param array $input Query-events ability input.
	 * @return string SQL selecting one distinct ID column, or an empty string.
	 */
	public function buildMatchingPostIdsSql( array $input ): string {
		global $wpdb;

		$request                              = '';
		$input['fields']                      = 'ids';
		$input['per_page']                    = -1;
		$input[ self::CAPTURE_IDS_QUERY_VAR ] = true;

		$capture  = static function ( $posts, $query ) use ( &$request ) {
			if ( ! $query->get( self::CAPTURE_IDS_QUERY_VAR ) ) {
				return $posts;
			}

			$request = $query->request;
			return array();
		};
		$fields   = static function ( $sql_fields, $query ) use ( $wpdb ) {
			return $query->get( self::CAPTURE_IDS_QUERY_VAR ) ? "{$wpdb->posts}.ID" : $sql_fields;
		};
		$distinct = static function ( $sql_distinct, $query ) {
			return $query->get( self::CAPTURE_IDS_QUERY_VAR ) ? 'DISTINCT' : $sql_distinct;
		};

		add_filter( 'posts_pre_query', $capture, PHP_INT_MAX, 2 );
		add_filter( 'posts_fields', $fields, PHP_INT_MAX, 2 );
		add_filter( 'posts_distinct', $distinct, PHP_INT_MAX, 2 );

		try {
			$this->executeQueryEvents( $input );
		} finally {
			remove_filter( 'posts_pre_query', $capture, PHP_INT_MAX );
			remove_filter( 'posts_fields', $fields, PHP_INT_MAX );
			remove_filter( 'posts_distinct', $distinct, PHP_INT_MAX );
		}

		return $request;
	}

	/**
	 * Build a single posts_clauses callback that handles JOIN, WHERE, and ORDER BY.
	 *
	 * This consolidates all date logic into one filter — no stacking, no leaks.
	 *
	 * @param string $scope      upcoming|past|all
	 * @param string $date_start Range start (YYYY-MM-DD).
	 * @param string $date_end   Range end (YYYY-MM-DD).
	 * @param string $date_match Exact date match (YYYY-MM-DD).
	 * @param int    $days_ahead Bounded lookahead days.
	 * @param string $time_start Time start (HH:MM:SS).
	 * @param string $time_end   Time end (HH:MM:SS).
	 * @param string $order      ASC or DESC.
	 * @return callable The posts_clauses filter callback.
	 */
	private function buildDateClauses(
		string $scope,
		string $date_start,
		string $date_end,
		string $date_match,
		int $days_ahead,
		string $time_start,
		string $time_end,
		string $order
	): callable {
		return function ( $clauses ) use ( $scope, $date_start, $date_end, $date_match, $days_ahead, $time_start, $time_end, $order ) {
			global $wpdb;
			$table = EventDatesTable::table_name();

			// JOIN — only add once.
			if ( strpos( $clauses['join'], $table ) === false ) {
				$clauses['join'] .= " INNER JOIN {$table} AS ed ON {$wpdb->posts}.ID = ed.post_id";
			}

			$now = current_time( 'mysql' );

			// Exact date match takes priority (dedup queries).
			if ( ! empty( $date_match ) ) {
				$clauses['where'] .= $wpdb->prepare( ' AND DATE(ed.start_datetime) = %s', $date_match );
			} elseif ( ! empty( $date_start ) || ! empty( $date_end ) ) {
				// Explicit date range — delegates to UpcomingFilter.
				if ( ! empty( $date_start ) ) {
					$start_dt = ! empty( $time_start )
						? $date_start . ' ' . $time_start
						: $date_start . ' 00:00:00';

					$clauses['where'] .= ' AND ' . UpcomingFilter::range_start_where( $start_dt );
				}

				if ( ! empty( $date_end ) ) {
					$end_dt = ! empty( $time_end )
						? $date_end . ' ' . $time_end
						: $date_end . ' 23:59:59';

					$clauses['where'] .= $wpdb->prepare( ' AND ed.start_datetime <= %s', $end_dt );
				}
			} elseif ( 'upcoming' === $scope ) {
				// Canonical upcoming — delegates to UpcomingFilter.
				if ( $days_ahead > 0 ) {
					$end_date          = current_datetime()->modify( "+{$days_ahead} days" )->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );
					$clauses['where'] .= ' AND ' . UpcomingFilter::upcoming_bounded_where( $now, $end_date );
				} else {
					$clauses['where'] .= ' AND ' . UpcomingFilter::upcoming_where( $now );
				}
			} elseif ( 'past' === $scope ) {
				// Canonical past — delegates to UpcomingFilter.
				$clauses['where'] .= ' AND ' . UpcomingFilter::past_where( $now );
			}
			// 'all' scope — no date WHERE clause.

			// ORDER BY — always by start_datetime unless date_match (dedup doesn't need ordering).
			if ( empty( $date_match ) && '' !== $order ) {
				$clauses['orderby'] = "ed.start_datetime {$order}";
			}

			return $clauses;
		};
	}

	/**
	 * Serialize one published event into the bounded public contract.
	 *
	 * @param mixed $post Event post returned by WP_Query.
	 * @return array|null Structured event, or null when unavailable.
	 */
	private function serializePublicEvent( $post ): ?array {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		$dates     = EventDatesTable::get( (int) $post->ID );
		$permalink = get_permalink( $post );
		if ( ! $dates || ! is_string( $permalink ) ) {
			return null;
		}

		return array(
			'event_id'       => (int) $post->ID,
			'title'          => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'permalink'      => $permalink,
			'start_datetime' => (string) $dates->start_datetime,
			'end_datetime'   => null === $dates->end_datetime ? null : (string) $dates->end_datetime,
		);
	}

	/**
	 * Log unbounded queries that return full WP_Post objects.
	 *
	 * Records caller backtrace (2 frames) and query context to the
	 * debug log when a query returns more than 100 full post objects
	 * without pagination. Helps identify which call sites need limits.
	 *
	 * Output format: [data-machine-events] Unbounded query: {count} posts | caller: {class}::{method} | scope: {scope}
	 *
	 * @param array $input     Query input parameters.
	 * @param int   $post_count Number of posts returned.
	 */
	private function logUnboundedQuery( array $input, int $post_count ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Walk the backtrace to find the first external caller (not this class).
		$caller = 'unknown';
		$trace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 10 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

		foreach ( $trace as $frame ) {
			$class = $frame['class'] ?? '';
			// Skip self and core WP internals.
			if ( __CLASS__ === $class || 'WP_Query' === $class ) {
				continue;
			}
			$caller = $class . '::' . ( $frame['function'] ?? 'unknown' );
			break;
		}

		$scope = $input['scope'] ?? 'upcoming';
		$tax   = ! empty( $input['tax_filters'] ) ? implode( ',', array_keys( $input['tax_filters'] ) ) : 'none';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[data-machine-events] Unbounded query: %d posts | caller: %s | scope: %s | tax: %s',
				$post_count,
				$caller,
				$scope,
				$tax
			)
		);
	}
}
