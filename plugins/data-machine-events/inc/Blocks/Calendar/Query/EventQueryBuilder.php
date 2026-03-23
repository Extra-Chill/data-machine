<?php
/**
 * Event Query Builder
 *
 * Builds WP_Query arguments for calendar events. Handles meta queries
 * (date filtering), taxonomy queries (venue, promoter, archive constraints),
 * geo-filtering, and search.
 *
 * @package DataMachineEvents\Blocks\Calendar\Query
 * @since   0.14.0
 */

namespace DataMachineEvents\Blocks\Calendar\Query;

use WP_Query;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Blocks\Calendar\Geo_Query;
use DataMachineEvents\Core\EventDatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @deprecated 0.24.0 Use data-machine-events/query-events ability
 *             (EventDateQueryAbilities::executeQueryEvents) instead.
 */
class EventQueryBuilder {

	/**
	 * Build WP_Query arguments for calendar events.
	 *
	 * Date filtering and ordering use posts_clauses filters on the
	 * event_dates table instead of meta_query. Returns both the query
	 * args and a cleanup callable that MUST be invoked after the
	 * WP_Query runs to remove the posts_clauses filters.
	 *
	 * @param array $params Query parameters.
	 * @return array{ args: array, cleanup: callable } WP_Query arguments and cleanup function.
	 */
	public static function build_query_args( array $params ): array {
		$defaults = array(
			'show_past'          => false,
			'search_query'       => '',
			'date_start'         => '',
			'date_end'           => '',
			'time_start'         => '',
			'time_end'           => '',
			'tax_filters'        => array(),
			'tax_query_override' => null,
			'archive_taxonomy'   => '',
			'archive_term_id'    => 0,
			'source'             => 'unknown',
			'user_date_range'    => false,
			'geo_lat'            => '',
			'geo_lng'            => '',
			'geo_radius'         => 25,
			'geo_radius_unit'    => 'mi',
		);

		$params = wp_parse_args( $params, $defaults );

		/**
		 * Filter the base query constraint for calendar events.
		 */
		$params['tax_query_override'] = apply_filters(
			'data_machine_events_calendar_base_query',
			$params['tax_query_override'],
			array(
				'archive_taxonomy' => $params['archive_taxonomy'],
				'archive_term_id'  => $params['archive_term_id'],
				'source'           => $params['source'],
			)
		);

		$query_args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			// Ordering handled by posts_clauses filter below.
			'orderby'        => 'none',
		);

		// Collect all posts_clauses filter callbacks for cleanup.
		$filters = array();

		$current_datetime = current_time( 'mysql' );

		// Apply date filter + ordering via posts_clauses.
		if ( $params['show_past'] && ! $params['user_date_range'] ) {
			$filters[] = DateFilter::apply_past_filter( $current_datetime );
			$filters[] = DateFilter::apply_date_orderby( 'DESC' );
		} elseif ( ! $params['show_past'] && ! $params['user_date_range'] ) {
			$filters[] = DateFilter::apply_upcoming_filter( $current_datetime );
			$filters[] = DateFilter::apply_date_orderby( 'ASC' );
		} else {
			// user_date_range mode — just apply ordering.
			$filters[] = DateFilter::apply_date_orderby( $params['show_past'] ? 'DESC' : 'ASC' );
		}

		// Date range boundaries via posts_clauses.
		if ( ! empty( $params['date_start'] ) || ! empty( $params['date_end'] ) ) {
			$date_start = $params['date_start'];
			$date_end   = $params['date_end'];
			$time_start = $params['time_start'];
			$time_end   = $params['time_end'];

			$date_range_filter = function ( $clauses ) use ( $date_start, $date_end, $time_start, $time_end ) {
				global $wpdb;
				$table = EventDatesTable::table_name();

				if ( strpos( $clauses['join'], $table ) === false ) {
					$clauses['join'] .= " INNER JOIN {$table} AS ed ON {$wpdb->posts}.ID = ed.post_id";
				}

				if ( ! empty( $date_start ) ) {
					$start_datetime = ! empty( $time_start )
						? $date_start . ' ' . $time_start
						: $date_start . ' 00:00:00';

					$clauses['where'] .= $wpdb->prepare(
						' AND (ed.start_datetime >= %s OR ed.end_datetime >= %s)',
						$start_datetime,
						$start_datetime
					);
				}

				if ( ! empty( $date_end ) ) {
					$end_datetime = ! empty( $time_end )
						? $date_end . ' ' . $time_end
						: $date_end . ' 23:59:59';

					$clauses['where'] .= $wpdb->prepare(
						' AND ed.start_datetime <= %s',
						$end_datetime
					);
				}

				return $clauses;
			};

			add_filter( 'posts_clauses', $date_range_filter );
			$filters[] = $date_range_filter;
		}

		if ( $params['tax_query_override'] ) {
			$query_args['tax_query'] = $params['tax_query_override'];
		}

		// Geo-filter: find venues within radius and inject as tax_query constraint.
		if ( ! empty( $params['geo_lat'] ) && ! empty( $params['geo_lng'] ) ) {
			$geo_lat    = (float) $params['geo_lat'];
			$geo_lng    = (float) $params['geo_lng'];
			$geo_radius = (float) ( $params['geo_radius'] ?? 25 );
			$geo_unit   = $params['geo_radius_unit'] ?? 'mi';

			if ( Geo_Query::validate_params( $geo_lat, $geo_lng, $geo_radius ) ) {
				$nearby_venue_ids = Geo_Query::get_venue_ids_within_radius( $geo_lat, $geo_lng, $geo_radius, $geo_unit );

				$tax_query             = isset( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
				$tax_query['relation'] = 'AND';

				if ( ! empty( $nearby_venue_ids ) ) {
					$tax_query[] = array(
						'taxonomy' => 'venue',
						'field'    => 'term_id',
						'terms'    => $nearby_venue_ids,
						'operator' => 'IN',
					);
				} else {
					$tax_query[] = array(
						'taxonomy' => 'venue',
						'field'    => 'term_id',
						'terms'    => array( 0 ),
						'operator' => 'IN',
					);
				}

				$query_args['tax_query'] = $tax_query;
			}
		}

		if ( ! empty( $params['tax_filters'] ) && is_array( $params['tax_filters'] ) ) {
			$tax_query             = isset( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
			$tax_query['relation'] = 'AND';

			foreach ( $params['tax_filters'] as $taxonomy => $term_ids ) {
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

		if ( ! empty( $params['search_query'] ) ) {
			$query_args['s'] = $params['search_query'];
		}

		$query_args = apply_filters( 'data_machine_events_calendar_query_args', $query_args, $params );

		// Return args + cleanup callable that removes all posts_clauses filters.
		$cleanup = function () use ( $filters ) {
			foreach ( $filters as $filter ) {
				remove_filter( 'posts_clauses', $filter );
			}
		};

		return array(
			'args'    => $query_args,
			'cleanup' => $cleanup,
		);
	}

	/**
	 * Get past and future event counts (cached).
	 *
	 * @return array ['past' => int, 'future' => int]
	 */
	public static function get_event_counts(): array {
		$cache_key = 'data-machine_cal_counts';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = self::compute_event_counts();

		set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Compute past and future event counts (uncached).
	 *
	 * @return array ['past' => int, 'future' => int]
	 */
	private static function compute_event_counts(): array {
		$current_datetime = current_time( 'mysql' );

		$upcoming_filter = DateFilter::apply_upcoming_filter( $current_datetime );
		$future_query    = new WP_Query(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		remove_filter( 'posts_clauses', $upcoming_filter );

		$past_filter = DateFilter::apply_past_filter( $current_datetime );
		$past_query  = new WP_Query(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		remove_filter( 'posts_clauses', $past_filter );

		return array(
			'past'   => $past_query->found_posts,
			'future' => $future_query->found_posts,
		);
	}
}
