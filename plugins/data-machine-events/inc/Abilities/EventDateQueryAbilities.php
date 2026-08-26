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
use DataMachineEvents\Core\DateTimeParser;
use DataMachineEvents\Blocks\Calendar\Query\ScopeResolver;
use DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventDateQueryAbilities {
	private const CAPTURE_IDS_QUERY_VAR       = '_data_machine_events_capture_ids_sql';
	private const CAPTURE_COUNT_QUERY_VAR     = '_data_machine_events_capture_count_sql';
	private const CAPTURE_AGGREGATE_QUERY_VAR = '_data_machine_events_capture_aggregate_sql';
	private const MAX_PUBLIC_RESULTS          = 100;
	private const DEFAULT_PUBLIC_RESULTS      = 50;
	private const TAXONOMY_CANDIDATE_BATCH    = 100;

	private static bool $registered      = false;
	private ?array $prefilteredQueryArgs = null;

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
							'page'        => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Results page. Default: 1.',
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
								'description' => 'Total matching events.',
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
		$page        = max( 1, (int) ( $input['page'] ?? 1 ) );
		$fields      = $input['fields'] ?? 'all';
		$capture_sql = ! empty( $input[ self::CAPTURE_IDS_QUERY_VAR ] )
			|| ! empty( $input[ self::CAPTURE_COUNT_QUERY_VAR ] )
			|| ! empty( $input[ self::CAPTURE_AGGREGATE_QUERY_VAR ] );
		$order       = $capture_sql
			? ''
			: ( strtoupper( $input['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC' );
		$status      = $input['status'] ?? 'publish';
		$meta_query  = is_array( $input['meta_query'] ?? null ) ? $input['meta_query'] : array();

		if ( '' !== $date_match && ! DateTimeParser::isValidYmd( $date_match ) ) {
			return array(
				'posts'      => array(),
				'total'      => 0,
				'post_count' => 0,
			);
		}

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

		if ( 'count' === $fields && empty( $input[ self::CAPTURE_COUNT_QUERY_VAR ] ) ) {
			$input['date_start'] = $date_start;
			$input['date_end']   = $date_end;
			$input['time_start'] = $time_start;
			$input['time_end']   = $time_end;

			return $this->executeCountQuery( $input );
		}

		// Build WP_Query args.
		$query_args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'no_found_rows'  => true, // Avoid deprecated SQL_CALC_FOUND_ROWS; use separate count.
			'orderby'        => 'none', // Ordering via posts_clauses.
		);

		if ( 'ids' === $fields ) {
			$query_args['fields'] = 'ids';
		}

		if ( ! empty( $input[ self::CAPTURE_IDS_QUERY_VAR ] ) ) {
			$query_args[ self::CAPTURE_IDS_QUERY_VAR ] = true;
		}

		if ( ! empty( $input[ self::CAPTURE_COUNT_QUERY_VAR ] ) ) {
			$query_args[ self::CAPTURE_COUNT_QUERY_VAR ] = true;
		}

		if ( ! empty( $input[ self::CAPTURE_AGGREGATE_QUERY_VAR ] ) ) {
			$query_args[ self::CAPTURE_AGGREGATE_QUERY_VAR ] = $input[ self::CAPTURE_AGGREGATE_QUERY_VAR ];
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
				$term_ids    = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
				$tax_query[] = array(
					'taxonomy' => sanitize_key( $taxonomy ),
					'field'    => 'term_id',
					'terms'    => $term_ids,
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

		$clauses_filter = $this->buildDateClauses(
			$scope,
			$date_start,
			$date_end,
			$date_match,
			$days_ahead,
			$time_start,
			$time_end,
			$order,
			$status,
			! empty( $input[ self::CAPTURE_COUNT_QUERY_VAR ] ),
			is_array( $input[ self::CAPTURE_AGGREGATE_QUERY_VAR ] ?? null ) ? $input[ self::CAPTURE_AGGREGATE_QUERY_VAR ] : array()
		);
		add_filter( 'posts_clauses', $clauses_filter );
		$filters[] = $clauses_filter;

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
		$base_query_args = $query_args;
		$prefiltered     = $this->consumePrefilteredQueryArgs();
		$query_args      = is_array( $prefiltered )
			? $prefiltered
			: (array) apply_filters( 'data_machine_events_calendar_query_args', $query_args, $input );

		if ( $query_args === $base_query_args && $this->canUseBoundedTaxonomyCandidates( $input, $tax_filters, $scope, $status, $per_page, $page ) ) {
			remove_filter( 'posts_clauses', $clauses_filter );

			return $this->executeBoundedTaxonomyQuery( $tax_filters, $exclude, $per_page, $page, $fields, $order );
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		// Cleanup filters immediately.
		foreach ( $filters as $f ) {
			remove_filter( 'posts_clauses', $f );
		}

		$posts = $query->posts;
		$total = $query->post_count;

		if ( -1 === $per_page ) {
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
	 * Whether a query can use taxonomy/date candidates before post hydration.
	 *
	 * Consumer-modified, unbounded, operational, and compound query shapes stay
	 * on WP_Query so this optimization cannot bypass their constraints.
	 *
	 * @param array  $input       Raw ability input.
	 * @param array  $tax_filters Taxonomy filters.
	 * @param string $scope       Date scope.
	 * @param mixed  $status      Requested post status.
	 * @param int    $per_page    Page size.
	 * @param int    $page        Page number.
	 * @return bool
	 */
	private function canUseBoundedTaxonomyCandidates( array $input, array $tax_filters, string $scope, $status, int $per_page, int $page ): bool {
		return 'upcoming' === $scope
			&& 'publish' === $status
			&& $per_page > 0
			&& 1 === $page
			&& 1 === count( $tax_filters )
			&& empty( $input['date_start'] )
			&& empty( $input['date_end'] )
			&& empty( $input['date_match'] )
			&& empty( $input['days_ahead'] )
			&& empty( $input['time_scope'] )
			&& empty( $input['search'] )
			&& empty( $input['geo'] )
			&& empty( $input['meta_query'] )
			&& empty( $input[ self::CAPTURE_IDS_QUERY_VAR ] )
			&& empty( $input[ self::CAPTURE_COUNT_QUERY_VAR ] )
			&& empty( $input[ self::CAPTURE_AGGREGATE_QUERY_VAR ] )
			&& in_array( $input['fields'] ?? 'all', array( 'all', 'ids' ), true );
	}

	/**
	 * Query bounded taxonomy/date candidates, then validate canonical posts.
	 *
	 * The event-date status column and relationship index identify ordered
	 * candidates without reading every matching posts row. Small WP_Query batches
	 * retain canonical post type/status eligibility and fill through stale rows,
	 * preserving the exact page rather than trusting denormalized status blindly.
	 *
	 * @param array  $tax_filters One taxonomy mapped to term IDs.
	 * @param int[]  $exclude     Post IDs to exclude.
	 * @param int    $per_page    Page size.
	 * @param int    $page        Page number.
	 * @param string $fields      all|ids.
	 * @param string $order       ASC|DESC.
	 * @return array { posts: array, total: int, post_count: int }
	 */
	private function executeBoundedTaxonomyQuery( array $tax_filters, array $exclude, int $per_page, int $page, string $fields, string $order ): array {
		global $wpdb;

		$taxonomy         = sanitize_key( (string) array_key_first( $tax_filters ) );
		$term_ids         = (array) reset( $tax_filters );
		$taxonomy_ids     = $this->resolveTermTaxonomyIds( $taxonomy, $term_ids );
		$target_count     = $per_page * $page;
		$candidate_offset = 0;
		$valid_ids        = array();
		$table            = EventDatesTable::table_name();
		$order            = 'DESC' === $order ? 'DESC' : 'ASC';

		if ( empty( $taxonomy_ids ) ) {
			return array(
				'posts'      => array(),
				'total'      => 0,
				'post_count' => 0,
			);
		}

		$taxonomy_placeholders = implode( ',', array_fill( 0, count( $taxonomy_ids ), '%d' ) );
		$exclude               = array_values( array_unique( array_filter( array_map( 'absint', $exclude ) ) ) );
		$exclude_sql           = '';
		if ( ! empty( $exclude ) ) {
			$exclude_sql = ' AND tr.object_id NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
		}

		$now         = current_time( 'mysql' );
		$valid_count = 0;
		do {
			$params = array_merge( $taxonomy_ids, $exclude, array( $now, $now, self::TAXONOMY_CANDIDATE_BATCH, $candidate_offset ) );
			$sql    = "SELECT ed.post_id
				FROM {$wpdb->term_relationships} tr FORCE INDEX (term_taxonomy_id)
				INNER JOIN {$table} ed ON ed.post_id = tr.object_id
				WHERE tr.term_taxonomy_id IN ({$taxonomy_placeholders}){$exclude_sql}
					AND ed.post_status = 'publish'
					AND (ed.start_datetime >= %s OR ed.end_datetime >= %s)
				GROUP BY ed.post_id, ed.start_datetime
				ORDER BY ed.start_datetime {$order}, ed.post_id {$order}
				LIMIT %d OFFSET %d";

			$prepared_sql    = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifiers and sort direction are trusted; all values use placeholders.
			$candidate_ids   = array_map(
				'intval',
				$wpdb->get_col( $prepared_sql ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded indexed candidate query; canonical posts are validated below.
			);
			$candidate_count = count( $candidate_ids );

			if ( empty( $candidate_ids ) ) {
				break;
			}

			$validated = get_posts(
				array(
					'post_type'      => Event_Post_Type::POST_TYPE,
					'post_status'    => 'publish',
					'post__in'       => $candidate_ids,
					'posts_per_page' => count( $candidate_ids ),
					'orderby'        => 'post__in',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$validated = array_fill_keys( array_map( 'intval', $validated ), true );

			foreach ( $candidate_ids as $candidate_id ) {
				if ( isset( $validated[ $candidate_id ] ) ) {
					$valid_ids[] = $candidate_id;
				}
			}

			$candidate_offset += $candidate_count;
			$valid_count       = count( $valid_ids );
		} while ( self::TAXONOMY_CANDIDATE_BATCH === $candidate_count && $valid_count < $target_count );

		$page_ids = array_slice( $valid_ids, ( $page - 1 ) * $per_page, $per_page );
		$posts    = $page_ids;
		if ( 'all' === $fields && ! empty( $page_ids ) ) {
			_prime_post_caches( $page_ids, true, true );
			$posts = array_values( array_filter( array_map( 'get_post', $page_ids ) ) );
		}

		return array(
			'posts'      => $posts,
			'total'      => count( $posts ),
			'post_count' => count( $posts ),
		);
	}

	/**
	 * Resolve term IDs to the exact term-taxonomy IDs used by WP_Tax_Query.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param array  $term_ids Term IDs.
	 * @return int[]
	 */
	private function resolveTermTaxonomyIds( string $taxonomy, array $term_ids ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			foreach ( $term_ids as $term_id ) {
				$children = get_term_children( $term_id, $taxonomy );
				if ( is_wp_error( $children ) ) {
					return array();
				}
				$term_ids = array_merge( $term_ids, $children );
			}
			$term_ids = array_values( array_unique( array_map( 'absint', $term_ids ) ) );
		}

		$taxonomy_ids = get_terms(
			array(
				'taxonomy'               => $taxonomy,
				'include'                => $term_ids,
				'fields'                 => 'tt_ids',
				'hide_empty'             => false,
				'number'                 => 0,
				'orderby'                => 'none',
				'update_term_meta_cache' => false,
			)
		);

		if ( is_wp_error( $taxonomy_ids ) ) {
			return array();
		}

		$taxonomy_ids = array_values( array_unique( array_map( 'absint', $taxonomy_ids ) ) );
		sort( $taxonomy_ids, SORT_NUMERIC );

		return $taxonomy_ids;
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
		return $this->buildMatchingSql( $input, false );
	}

	/**
	 * Build a selective grouped term-count query for a canonical event slice.
	 *
	 * This is intentionally limited to the MySQL shapes that can be expressed
	 * directly over the event-date and taxonomy indexes. Unsupported or
	 * customized requests return an empty string so callers retain WP_Query and
	 * wp_get_object_terms() as the exact fallback.
	 *
	 * @param array    $input      Query-events ability input.
	 * @param string[] $taxonomies Taxonomies whose direct assignments are counted.
	 * @return string Prepared grouped SQL, or an empty string when unsupported.
	 */
	public function buildMatchingTermCountSql( array $input, array $taxonomies ): string {
		global $wpdb;

		$db_engine     = defined( 'DB_ENGINE' ) ? strtolower( (string) constant( 'DB_ENGINE' ) ) : '';
		$database_type = defined( 'DATABASE_TYPE' ) ? strtolower( (string) constant( 'DATABASE_TYPE' ) ) : '';
		$taxonomies    = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $taxonomies ),
					'taxonomy_exists'
				)
			)
		);
		if (
			'sqlite' === $db_engine
			|| 'sqlite' === $database_type
			|| true !== $wpdb->is_mysql
			|| empty( $taxonomies )
			|| 'publish' !== ( $input['status'] ?? 'publish' )
			|| ! empty( $input['search'] )
			|| ! empty( $input['geo'] )
			|| ! empty( $input['exclude'] )
			|| ! empty( $input['meta_query'] )
			|| ! empty( $input['scope_token'] )
			|| ! empty( $input['time_scope'] )
			|| $this->hasCustomizedMatchingQuery( $input )
		) {
			return '';
		}

		$where        = array( "ed.post_status = 'publish'" );
		$where_values = array();
		$scope        = $input['scope'] ?? 'upcoming';
		$now          = current_time( 'mysql' );

		if ( ! empty( $input['date_match'] ) ) {
			if ( ! DateTimeParser::isValidYmd( $input['date_match'] ) ) {
				return '';
			}
			$start          = $input['date_match'] . ' 00:00:00';
			$end            = ( new \DateTimeImmutable( $start ) )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
			$where[]        = 'ed.start_datetime >= %s AND ed.start_datetime < %s';
			$where_values[] = $start;
			$where_values[] = $end;
		} elseif ( ! empty( $input['date_start'] ) || ! empty( $input['date_end'] ) ) {
			if ( ! empty( $input['date_start'] ) ) {
				$start   = $input['date_start'] . ' ' . ( ! empty( $input['time_start'] ) ? $input['time_start'] : '00:00:00' );
				$where[] = UpcomingFilter::range_start_where( $start );
			}
			if ( ! empty( $input['date_end'] ) ) {
				$end            = $input['date_end'] . ' ' . ( ! empty( $input['time_end'] ) ? $input['time_end'] : '23:59:59' );
				$where[]        = 'ed.start_datetime <= %s';
				$where_values[] = $end;
			}
		} elseif ( 'upcoming' === $scope ) {
			$days_ahead = (int) ( $input['days_ahead'] ?? 0 );
			if ( $days_ahead > 0 ) {
				$end     = current_datetime()->modify( "+{$days_ahead} days" )->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );
				$where[] = UpcomingFilter::upcoming_bounded_where( $now, $end );
			} else {
				$where[] = UpcomingFilter::upcoming_where( $now );
			}
		} elseif ( 'past' === $scope ) {
			$where[] = UpcomingFilter::past_where( $now );
		}

		$tax_filters = is_array( $input['tax_filters'] ?? null ) ? $input['tax_filters'] : array();
		foreach ( $tax_filters as $taxonomy => $term_ids ) {
			$taxonomy_ids = $this->resolveTermTaxonomyIds( sanitize_key( $taxonomy ), (array) $term_ids );
			if ( empty( $taxonomy_ids ) ) {
				return '';
			}

			$placeholders = implode( ',', array_fill( 0, count( $taxonomy_ids ), '%d' ) );
			$where[]      = "EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} filter_tr
				WHERE filter_tr.object_id = ed.post_id
					AND filter_tr.term_taxonomy_id IN ({$placeholders})
			)";
			$where_values = array_merge( $where_values, $taxonomy_ids );
		}

		$taxonomy_placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );
		$table                 = EventDatesTable::table_name();
		$values                = array_merge(
			$taxonomies,
			array( Event_Post_Type::POST_TYPE, 'publish' ),
			$where_values
		);
		$sql                   = "SELECT count_tt.taxonomy, count_tt.term_id, COUNT(DISTINCT ed.post_id) AS event_count
			FROM {$table} ed
			INNER JOIN {$wpdb->posts} p ON p.ID = ed.post_id
			INNER JOIN {$wpdb->term_relationships} count_tr ON count_tr.object_id = ed.post_id
			INNER JOIN {$wpdb->term_taxonomy} count_tt ON count_tt.term_taxonomy_id = count_tr.term_taxonomy_id
				AND count_tt.taxonomy IN ({$taxonomy_placeholders})
			WHERE p.post_type = %s AND p.post_status = %s
				AND " . implode( ' AND ', $where ) . '
			GROUP BY count_tt.taxonomy, count_tt.term_id';

		return $wpdb->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Trusted tables; all values use placeholders.
	}

	/**
	 * Determine whether registered query callbacks change the canonical SQL.
	 *
	 * The callback-free query is captured without execution, then compared with
	 * the live callback result. This keeps registered identity callbacks eligible
	 * while forcing every effective query customization onto the canonical path.
	 */
	private function hasCustomizedMatchingQuery( array $input ): bool {
		global $wp_filter;

		$hooks      = array(
			'data_machine_events_calendar_query_args',
			'parse_query',
			'parse_tax_query',
			'pre_get_posts',
			'posts_selection',
			'posts_search',
			'posts_search_orderby',
			'posts_where',
			'posts_join',
			'posts_where_paged',
			'posts_groupby',
			'posts_join_paged',
			'posts_orderby',
			'posts_distinct',
			'posts_fields',
			'post_limits',
			'posts_clauses',
			'posts_where_request',
			'posts_groupby_request',
			'posts_join_request',
			'posts_orderby_request',
			'posts_distinct_request',
			'posts_fields_request',
			'post_limits_request',
			'posts_clauses_request',
			'posts_request',
			'posts_request_ids',
			'posts_pre_query',
		);
		$registered = array();
		foreach ( $hooks as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$registered[ $hook ] = $wp_filter[ $hook ]->callbacks;
				remove_all_filters( $hook );
			}
		}

		try {
			$baseline = $this->buildMatchingPostIdsSql( $input );
		} finally {
			foreach ( $registered as $hook => $callbacks ) {
				$added = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
				remove_all_filters( $hook );
				foreach ( array( $callbacks, $added ) as $callback_set ) {
					foreach ( $callback_set as $priority => $priority_callbacks ) {
						foreach ( $priority_callbacks as $callback ) {
							add_filter( $hook, $callback['function'], $priority, $callback['accepted_args'] );
						}
					}
				}
			}
		}

		if ( empty( $registered ) ) {
			return false;
		}

		$short_circuited = false;
		$observer        = static function ( $posts, $query ) use ( &$short_circuited ) {
			if ( null !== $posts && $query->get( self::CAPTURE_IDS_QUERY_VAR ) ) {
				$short_circuited = true;
			}
			return $posts;
		};
		add_filter( 'posts_pre_query', $observer, PHP_INT_MAX, 2 );
		try {
			$customized = $this->buildMatchingPostIdsSql( $input );
		} finally {
			remove_filter( 'posts_pre_query', $observer, PHP_INT_MAX );
		}

		return $short_circuited || $baseline !== $customized;
	}

	/**
	 * Build canonical SQL that groups matching events by two date expressions.
	 *
	 * The expressions are trusted internal SQL fragments over the canonical `ed`
	 * event-date alias. Queries without duplicating joins use COUNT(*); taxonomy,
	 * meta, and consumer joins retain an exact distinct-post count.
	 *
	 * @param array  $input            Query-events ability input.
	 * @param string $start_expression SQL expression for the start bucket.
	 * @param string $end_expression   SQL expression for the end bucket.
	 * @return string SQL selecting start_date, end_date, and bucket_count.
	 */
	public function buildMatchingEventDateAggregateSql( array $input, string $start_expression, string $end_expression ): string {
		$input['fields']                            = 'ids';
		$input['per_page']                          = -1;
		$input[ self::CAPTURE_AGGREGATE_QUERY_VAR ] = array(
			'start_expression' => $start_expression,
			'end_expression'   => $end_expression,
		);

		$this->prefilteredQueryArgs = null;
		$selective                  = $this->buildSelectiveTaxonomyAggregateSql( $input, $start_expression, $end_expression );
		if ( '' !== $selective ) {
			return $selective;
		}

		$request = '';

		$capture = static function ( $posts, $query ) use ( &$request ) {
			if ( ! $query->get( self::CAPTURE_AGGREGATE_QUERY_VAR ) ) {
				return $posts;
			}

			$request = $query->request;
			return array();
		};

		add_filter( 'posts_pre_query', $capture, PHP_INT_MAX, 2 );

		try {
			$this->executeQueryEvents( $input );
		} finally {
			remove_filter( 'posts_pre_query', $capture, PHP_INT_MAX );
			$this->prefilteredQueryArgs = null;
		}

		return $request;
	}

	/**
	 * Build the selective taxonomy aggregate used by simple Calendar archives.
	 *
	 * Compound, customized, and portable-database requests retain the canonical
	 * WP_Query path above. The selective path expands hierarchical descendants
	 * through the same resolver used by bounded event rows, then applies indexed
	 * taxonomy membership without multiplying event-date rows.
	 */
	private function buildSelectiveTaxonomyAggregateSql( array $input, string $start_expression, string $end_expression ): string {
		global $wpdb;

		$db_engine     = defined( 'DB_ENGINE' ) ? strtolower( (string) constant( 'DB_ENGINE' ) ) : '';
		$database_type = defined( 'DATABASE_TYPE' ) ? strtolower( (string) constant( 'DATABASE_TYPE' ) ) : '';
		$tax_filters   = is_array( $input['tax_filters'] ?? null ) ? $input['tax_filters'] : array();
		if (
			'sqlite' === $db_engine
			|| 'sqlite' === $database_type
			|| true !== $wpdb->is_mysql
			|| 1 !== count( $tax_filters )
			|| empty( reset( $tax_filters ) )
			|| 'publish' !== ( $input['status'] ?? 'publish' )
			|| ! empty( $input['search'] )
			|| ! empty( $input['geo'] )
			|| ! empty( $input['exclude'] )
			|| ! empty( $input['meta_query'] )
			|| ! empty( $input['scope_token'] )
			|| ! empty( $input['time_scope'] )
		) {
			return '';
		}

		$taxonomy     = sanitize_key( (string) array_key_first( $tax_filters ) );
		$taxonomy_ids = $this->resolveTermTaxonomyIds( $taxonomy, (array) reset( $tax_filters ) );
		if ( empty( $taxonomy_ids ) ) {
			return '';
		}

		$where        = array( "ed.post_status = 'publish'" );
		$where_values = array();
		$scope        = $input['scope'] ?? 'upcoming';
		$now          = current_time( 'mysql' );

		if ( ! empty( $input['date_match'] ) ) {
			if ( ! DateTimeParser::isValidYmd( $input['date_match'] ) ) {
				return '';
			}
			$start          = $input['date_match'] . ' 00:00:00';
			$end            = ( new \DateTimeImmutable( $start ) )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
			$where[]        = 'ed.start_datetime >= %s AND ed.start_datetime < %s';
			$where_values[] = $start;
			$where_values[] = $end;
		} elseif ( ! empty( $input['date_start'] ) || ! empty( $input['date_end'] ) ) {
			if ( ! empty( $input['date_start'] ) ) {
				$start   = $input['date_start'] . ' ' . ( ! empty( $input['time_start'] ) ? $input['time_start'] : '00:00:00' );
				$where[] = UpcomingFilter::range_start_where( $start );
			}
			if ( ! empty( $input['date_end'] ) ) {
				$end            = $input['date_end'] . ' ' . ( ! empty( $input['time_end'] ) ? $input['time_end'] : '23:59:59' );
				$where[]        = 'ed.start_datetime <= %s';
				$where_values[] = $end;
			}
		} elseif ( 'upcoming' === $scope ) {
			$days_ahead = (int) ( $input['days_ahead'] ?? 0 );
			if ( $days_ahead > 0 ) {
				$end     = current_datetime()->modify( "+{$days_ahead} days" )->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );
				$where[] = UpcomingFilter::upcoming_bounded_where( $now, $end );
			} else {
				$where[] = UpcomingFilter::upcoming_where( $now );
			}
		} elseif ( 'past' === $scope ) {
			$where[] = UpcomingFilter::past_where( $now );
		}
		if ( $this->hasCustomizedAggregateQueryArgs( $input, $tax_filters ) ) {
			return '';
		}

		$placeholders = implode( ',', array_fill( 0, count( $taxonomy_ids ), '%d' ) );
		$table        = EventDatesTable::table_name();
		$values       = array_merge( array( Event_Post_Type::POST_TYPE, 'publish' ), $taxonomy_ids, $where_values );
		$sql          = "SELECT {$start_expression} AS start_date, {$end_expression} AS end_date, COUNT(*) AS bucket_count
			FROM {$table} ed
			INNER JOIN {$wpdb->posts} p ON p.ID = ed.post_id
			WHERE p.post_type = %s AND p.post_status = %s
				AND EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} tr
					WHERE tr.object_id = ed.post_id AND tr.term_taxonomy_id IN ({$placeholders})
				)
				AND " . implode( ' AND ', $where ) . "
			GROUP BY {$start_expression}, {$end_expression}";

		return $wpdb->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Trusted table/expression fragments; all values use placeholders.
	}

	/** Determine whether registered query callbacks actually constrain this aggregate. */
	private function hasCustomizedAggregateQueryArgs( array $input, array $tax_filters ): bool {
		if ( false === has_filter( 'data_machine_events_calendar_query_args' ) ) {
			return false;
		}

		$query_args                                      = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'paged'          => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'no_found_rows'  => true,
			'orderby'        => 'none',
			'fields'         => 'ids',
			'tax_query'      => array( 'relation' => 'AND' ),
		);
		$query_args[ self::CAPTURE_AGGREGATE_QUERY_VAR ] = $input[ self::CAPTURE_AGGREGATE_QUERY_VAR ];

		foreach ( $tax_filters as $taxonomy => $term_ids ) {
			$query_args['tax_query'][] = array(
				'taxonomy' => sanitize_key( $taxonomy ),
				'field'    => 'term_id',
				'terms'    => array_values( array_unique( array_filter( array_map( 'absint', (array) $term_ids ) ) ) ),
				'operator' => 'IN',
			);
		}

		$filtered = (array) apply_filters( 'data_machine_events_calendar_query_args', $query_args, $input );
		if ( $filtered === $query_args ) {
			return false;
		}

		$this->prefilteredQueryArgs = $filtered;
		return true;
	}

	/** Consume the one-shot aggregate fallback handoff before WP_Query can re-enter. */
	private function consumePrefilteredQueryArgs(): ?array {
		$prefiltered                = $this->prefilteredQueryArgs;
		$this->prefilteredQueryArgs = null;
		return $prefiltered;
	}

	/**
	 * Execute an exact count through the canonical WP_Query constraint path.
	 *
	 * @param array $input Query-events ability input.
	 * @return array{posts: array, total: int, post_count: int}
	 */
	private function executeCountQuery( array $input ): array {
		global $wpdb;

		$sql   = $this->buildMatchingSql( $input, true );
		$total = '' === $sql ? 0 : (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'posts'      => array(),
			'total'      => $total,
			'post_count' => 0,
		);
	}

	/**
	 * Build canonical matching IDs or count SQL without executing WP_Query.
	 *
	 * @param array $input Query-events ability input.
	 * @param bool  $count Whether to select a distinct count instead of IDs.
	 * @return string Generated SQL, or an empty string.
	 */
	private function buildMatchingSql( array $input, bool $count ): string {
		global $wpdb;

		$request             = '';
		$input['fields']     = $count ? 'count' : 'ids';
		$input['per_page']   = -1;
		$query_var           = $count ? self::CAPTURE_COUNT_QUERY_VAR : self::CAPTURE_IDS_QUERY_VAR;
		$input[ $query_var ] = true;

		$capture  = static function ( $posts, $query ) use ( &$request, $query_var ) {
			if ( ! $query->get( $query_var ) ) {
				return $posts;
			}

			$request = $query->request;
			return array();
		};
		$fields   = static function ( $sql_fields, $query ) use ( $wpdb, $query_var, $count ) {
			if ( ! $query->get( $query_var ) ) {
				return $sql_fields;
			}

			return $count ? "COUNT(DISTINCT {$wpdb->posts}.ID)" : "{$wpdb->posts}.ID";
		};
		$distinct = static function ( $sql_distinct, $query ) use ( $query_var, $count ) {
			if ( ! $query->get( $query_var ) ) {
				return $sql_distinct;
			}

			return $count ? '' : 'DISTINCT';
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

		if ( $count && 1 !== preg_match( '/^\s*SELECT\s+COUNT\(/i', $request ) ) {
			return "SELECT COUNT(*) FROM ({$request}) matching";
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
	 * @param mixed  $status     Requested WordPress post status.
	 * @param bool   $count_sql  Whether clauses are building a direct count.
	 * @param array  $aggregate  Optional trusted date aggregate expressions.
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
		string $order,
		$status,
		bool $count_sql,
		array $aggregate = array()
	): callable {
		return function ( $clauses ) use ( $scope, $date_start, $date_end, $date_match, $days_ahead, $time_start, $time_end, $order, $status, $count_sql, $aggregate ) {
			global $wpdb;
			$table = EventDatesTable::table_name();

			// A count over the one-to-one event-date join cannot duplicate posts.
			// Keep DISTINCT when taxonomy/meta joins are already present.
			$can_count_rows_directly = '' === trim( $clauses['join'] ) && empty( $clauses['groupby'] );
			$count_rows_directly     = ( $count_sql || ! empty( $aggregate ) ) && $can_count_rows_directly;

			// JOIN — only add once.
			if ( strpos( $clauses['join'], $table ) === false ) {
				$join_type        = $count_sql && $can_count_rows_directly ? 'STRAIGHT_JOIN' : 'INNER JOIN';
				$clauses['join'] .= " {$join_type} {$table} AS ed ON {$wpdb->posts}.ID = ed.post_id";
			}

			// Taxonomy and consumer joins may match more than one relationship for
			// a post. Calendar rows represent canonical events, never join rows.
			if ( ! empty( $aggregate ) ) {
				$start_expression    = $aggregate['start_expression'];
				$end_expression      = $aggregate['end_expression'];
				$bucket_count        = $count_rows_directly ? 'COUNT(*)' : "COUNT(DISTINCT {$wpdb->posts}.ID)";
				$clauses['fields']   = "{$start_expression} AS start_date, {$end_expression} AS end_date, {$bucket_count} AS bucket_count";
				$clauses['distinct'] = '';
				$clauses['groupby']  = "{$start_expression}, {$end_expression}";
			} elseif ( $count_sql ) {
				$clauses['fields']   = $count_rows_directly ? 'COUNT(*)' : "{$wpdb->posts}.ID";
				$clauses['distinct'] = $count_rows_directly ? '' : 'DISTINCT';
			} else {
				$clauses['distinct'] = 'DISTINCT';
			}
			if ( 'publish' === $status ) {
				$clauses['where'] .= " AND ed.post_status = 'publish'";
			}

			$now = current_time( 'mysql' );

			// Exact date match takes priority (dedup queries). Use a half-open
			// datetime range so MySQL can use the start_datetime index.
			if ( ! empty( $date_match ) ) {
				$start             = $date_match . ' 00:00:00';
				$end               = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +1 day' ) );
				$clauses['where'] .= $wpdb->prepare( ' AND ed.start_datetime >= %s AND ed.start_datetime < %s', $start, $end );
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

			// Post ID breaks datetime ties so bounded pages never overlap or skip
			// candidates because MySQL chose a different equal-value row order.
			if ( '' !== $order ) {
				$clauses['orderby'] = "ed.start_datetime {$order}, {$wpdb->posts}.ID {$order}";
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
