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
use DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventDateQueryAbilities {

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
					'category'            => 'datamachine',
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
								'properties' => array(
									'lat'    => array( 'type' => 'number' ),
									'lng'    => array( 'type' => 'number' ),
									'radius' => array( 'type' => 'number' ),
									'unit'   => array( 'type' => 'string', 'enum' => array( 'mi', 'km' ) ),
								),
							),
							'exclude'     => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'integer' ),
								'description' => 'Post IDs to exclude.',
							),
							'per_page'    => array(
								'type'        => 'integer',
								'description' => 'Posts per page. -1 for all. Default: -1.',
							),
							'fields'      => array(
								'type'        => 'string',
								'enum'        => array( 'all', 'ids', 'count' ),
								'description' => 'Return format: all (WP_Post objects), ids (post IDs), count (just total). Default: all.',
							),
							'order'       => array(
								'type'        => 'string',
								'enum'        => array( 'ASC', 'DESC' ),
								'description' => 'Sort direction for event start_datetime. Default: ASC.',
							),
							'status'      => array(
								'type'        => 'string',
								'description' => 'Post status. Default: publish.',
							),
							'meta_query'  => array(
								'type'        => 'array',
								'description' => 'Additional meta_query clauses (for non-date meta like ticket_url, flow_id).',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'posts'      => array(
								'type'        => 'array',
								'description' => 'WP_Post objects, post IDs, or empty (for count mode).',
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
					'execute_callback'    => array( $this, 'executeQueryEvents' ),
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

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute the query-events ability.
	 *
	 * @param array $input Input parameters.
	 * @return array { posts: array, total: int, post_count: int }
	 */
	public function executeQueryEvents( array $input ): array {
		$scope      = $input['scope'] ?? 'upcoming';
		$date_start = $input['date_start'] ?? '';
		$date_end   = $input['date_end'] ?? '';
		$date_match = $input['date_match'] ?? '';
		$days_ahead = (int) ( $input['days_ahead'] ?? 0 );
		$time_start = $input['time_start'] ?? '';
		$time_end   = $input['time_end'] ?? '';
		$tax_filters = is_array( $input['tax_filters'] ?? null ) ? $input['tax_filters'] : array();
		$search     = $input['search'] ?? '';
		$geo        = is_array( $input['geo'] ?? null ) ? $input['geo'] : array();
		$exclude    = is_array( $input['exclude'] ?? null ) ? array_map( 'absint', $input['exclude'] ) : array();
		$per_page   = (int) ( $input['per_page'] ?? -1 );
		$fields     = $input['fields'] ?? 'all';
		$order      = strtoupper( $input['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
		$status     = $input['status'] ?? 'publish';
		$meta_query = is_array( $input['meta_query'] ?? null ) ? $input['meta_query'] : array();

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

		// Geo filter (venue proximity).
		if ( ! empty( $geo['lat'] ) && ! empty( $geo['lng'] ) ) {
			$geo_lat    = (float) $geo['lat'];
			$geo_lng    = (float) $geo['lng'];
			$geo_radius = (float) ( $geo['radius'] ?? 25 );
			$geo_unit   = $geo['unit'] ?? 'mi';

			if ( class_exists( 'DataMachineEvents\\Blocks\\Calendar\\Geo_Query' )
				&& \DataMachineEvents\Blocks\Calendar\Geo_Query::validate_params( $geo_lat, $geo_lng, $geo_radius ) ) {

				$nearby_venue_ids = \DataMachineEvents\Blocks\Calendar\Geo_Query::get_venue_ids_within_radius(
					$geo_lat, $geo_lng, $geo_radius, $geo_unit
				);

				$tax_query             = isset( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
				$tax_query['relation'] = 'AND';
				$tax_query[]           = array(
					'taxonomy' => 'venue',
					'field'    => 'term_id',
					'terms'    => ! empty( $nearby_venue_ids ) ? $nearby_venue_ids : array( 0 ),
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
					$end_date = gmdate( 'Y-m-d 23:59:59', strtotime( "+{$days_ahead} days" ) );
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
			if ( empty( $date_match ) ) {
				$clauses['orderby'] = "ed.start_datetime {$order}";
			}

			return $clauses;
		};
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
