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
use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_END_DATETIME_META_KEY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventQueryBuilder {

	/**
	 * Build WP_Query arguments for calendar events.
	 *
	 * @param array $params Query parameters.
	 * @return array WP_Query arguments.
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
		 *
		 * @param array|null $tax_query_override The base tax_query constraint (null if none).
		 * @param array      $context Request context.
		 * @return array|null Modified tax_query constraint or null to remove constraint.
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
			'meta_key'       => EVENT_DATETIME_META_KEY,
			'orderby'        => 'meta_value',
			'order'          => $params['show_past'] ? 'DESC' : 'ASC',
		);

		$meta_query       = array( 'relation' => 'AND' );
		$current_datetime = current_time( 'mysql' );
		$has_date_range   = ! empty( $params['date_start'] ) || ! empty( $params['date_end'] );

		if ( $params['show_past'] && ! $params['user_date_range'] ) {
			$meta_query[] = array(
				'key'     => EVENT_END_DATETIME_META_KEY,
				'value'   => $current_datetime,
				'compare' => '<',
				'type'    => 'DATETIME',
			);
		} elseif ( ! $params['show_past'] && ! $params['user_date_range'] ) {
			$meta_query[] = array(
				'key'     => EVENT_END_DATETIME_META_KEY,
				'value'   => $current_datetime,
				'compare' => '>=',
				'type'    => 'DATETIME',
			);
		}

		if ( ! empty( $params['date_start'] ) ) {
			$start_datetime = ! empty( $params['time_start'] )
				? $params['date_start'] . ' ' . $params['time_start']
				: $params['date_start'] . ' 00:00:00';

			// Include events that START on/after the boundary OR that END on/after it.
			// This ensures multi-day events that started before the page boundary
			// but span into it are still returned.
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => EVENT_DATETIME_META_KEY,
					'value'   => $start_datetime,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
				array(
					'key'     => EVENT_END_DATETIME_META_KEY,
					'value'   => $start_datetime,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
			);
		}

		if ( ! empty( $params['date_end'] ) ) {
			$end_datetime = ! empty( $params['time_end'] )
				? $params['date_end'] . ' ' . $params['time_end']
				: $params['date_end'] . ' 23:59:59';

			$meta_query[] = array(
				'key'     => EVENT_DATETIME_META_KEY,
				'value'   => $end_datetime,
				'compare' => '<=',
				'type'    => 'DATETIME',
			);
		}

		$query_args['meta_query'] = $meta_query;

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
					// No venues within radius — force empty result set.
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

		return apply_filters( 'data_machine_events_calendar_query_args', $query_args, $params );
	}

	/**
	 * Get past and future event counts (cached).
	 *
	 * @return array ['past' => int, 'future' => int]
	 */
	public static function get_event_counts(): array {
		$cache_key = 'datamachine_cal_counts';
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

		$future_query = new WP_Query(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => EVENT_END_DATETIME_META_KEY,
						'value'   => $current_datetime,
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		$past_query = new WP_Query(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'     => EVENT_END_DATETIME_META_KEY,
						'value'   => $current_datetime,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		return array(
			'past'   => $past_query->found_posts,
			'future' => $future_query->found_posts,
		);
	}
}
