<?php
namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_Query;
use WP_REST_Request;
use DateTime;

/**
 * Calendar API controller
 */
class Calendar {
	/**
	 * Calendar endpoint implementation (moved from old rest-api.php)
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function calendar( WP_REST_Request $request ) {
		$events_per_page = get_option( 'posts_per_page', 10 );
		$current_page    = max( 1, $request->get_param( 'paged' ) );
		$show_past       = '1' === $request->get_param( 'past' );

		$search_query = $request->get_param( 'event_search' );
		$date_start   = $request->get_param( 'date_start' );
		$date_end     = $request->get_param( 'date_end' );
		$tax_filters  = $request->get_param( 'tax_filter' );

		$query_args = array(
			'post_type'      => 'datamachine_events',
			'post_status'    => 'publish',
			'posts_per_page' => $events_per_page,
			'paged'          => $current_page,
			'meta_key'       => '_datamachine_event_datetime',
			'orderby'        => 'meta_value',
			'order'          => $show_past ? 'DESC' : 'ASC',
		);

		$meta_query = array( 'relation' => 'AND' );
		$current_datetime = current_time( 'mysql' );
		$has_date_range = ( ! empty( $date_start ) || ! empty( $date_end ) );

		if ( $show_past ) {
			$meta_query[] = array(
				'key'     => '_datamachine_event_datetime',
				'value'   => $current_datetime,
				'compare' => '<',
				'type'    => 'DATETIME',
			);
		} elseif ( ! $has_date_range ) {
			$meta_query[] = array(
				'key'     => '_datamachine_event_datetime',
				'value'   => $current_datetime,
				'compare' => '>=',
				'type'    => 'DATETIME',
			);
		}

		if ( ! empty( $date_start ) ) {
			$meta_query[] = array(
				'key'     => '_datamachine_event_datetime',
				'value'   => $date_start . ' 00:00:00',
				'compare' => '>=',
				'type'    => 'DATETIME',
			);
		}
		if ( ! empty( $date_end ) ) {
			$meta_query[] = array(
				'key'     => '_datamachine_event_datetime',
				'value'   => $date_end . ' 23:59:59',
				'compare' => '<=',
				'type'    => 'DATETIME',
			);
		}

		$query_args['meta_query'] = $meta_query;

		// Auto-detect taxonomy archives (preserve behavior)
		if ( is_tax() ) {
			$term = get_queried_object();
			if ( $term && isset( $term->taxonomy ) && isset( $term->term_id ) ) {
				$query_args['tax_query'] = array(
					array(
						'taxonomy' => $term->taxonomy,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				);
			}
		}

		if ( ! empty( $tax_filters ) && is_array( $tax_filters ) ) {
			$tax_query             = isset( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
			$tax_query['relation'] = 'AND';

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

		if ( ! empty( $search_query ) ) {
			$query_args['s'] = $search_query;
		}

		$query_args = apply_filters( 'datamachine_events_calendar_query_args', $query_args, array(), null );

		$events_query = new WP_Query( $query_args );
		$total_events = $events_query->found_posts;
		$max_pages    = $events_query->max_num_pages;

		// Counts for navigation
		$future_count_args = array(
			'post_type'      => 'datamachine_events',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'     => '_datamachine_event_datetime',
					'value'   => $current_datetime,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
			),
		);
		$future_query = new WP_Query( $future_count_args );
		$future_count = $future_query->found_posts;

		$past_count_args = array(
			'post_type'      => 'datamachine_events',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'     => '_datamachine_event_datetime',
					'value'   => $current_datetime,
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			),
		);
		$past_query = new WP_Query( $past_count_args );
		$past_count = $past_query->found_posts;

		// Build HTML via templates (same approach as before)
		ob_start();

		if ( $events_query->have_posts() ) {
			$paged_events = array();
			while ( $events_query->have_posts() ) {
				$events_query->the_post();
				$event_post = get_post();

				$blocks     = parse_blocks( $event_post->post_content );
				$event_data = null;
				foreach ( $blocks as $block ) {
					if ( 'datamachine-events/event-details' === $block['blockName'] ) {
						$event_data = $block['attrs'];
						break;
					}
				}

				if ( ( empty( $event_data ) || empty( $event_data['startDate'] ) ) ) {
					$meta_datetime = get_post_meta( $event_post->ID, '_datamachine_event_datetime', true );
					if ( $meta_datetime ) {
						$date_part = date( 'Y-m-d', strtotime( $meta_datetime ) );
						$time_part = date( 'H:i:s', strtotime( $meta_datetime ) );
						$event_data = (array) $event_data;
						$event_data['startDate'] = $date_part;
						if ( empty( $event_data['startTime'] ) ) {
							$event_data['startTime'] = $time_part;
						}
					}
				}

				if ( ! empty( $event_data['startDate'] ) ) {
					$start_time     = $event_data['startTime'] ?? '00:00:00';
					$event_datetime = new DateTime( $event_data['startDate'] . ' ' . $start_time );

					$paged_events[] = array(
						'post'       => $event_post,
						'datetime'   => $event_datetime,
						'event_data' => $event_data,
					);
				}
			}
			wp_reset_postdata();

			// Grouping and rendering (keeps original Template_Loader usage)
			$paged_date_groups = array();
			foreach ( $paged_events as $event_item ) {
				$event_data = $event_item['event_data'];
				$start_date = $event_data['startDate'] ?? '';

				if ( ! empty( $start_date ) ) {
					$start_time         = $event_data['startTime'] ?? '00:00:00';
					$start_datetime_obj = new DateTime( $start_date . ' ' . $start_time );
					$date_key           = $start_datetime_obj->format( 'Y-m-d' );

					if ( ! isset( $paged_date_groups[ $date_key ] ) ) {
						$paged_date_groups[ $date_key ] = array(
							'date_obj' => $start_datetime_obj,
							'events'   => array(),
						);
					}

					$paged_date_groups[ $date_key ]['events'][] = $event_item;
				}
			}

			// Sort date groups
			uksort(
				$paged_date_groups,
				function ( $a, $b ) use ( $show_past ) {
					if ( $show_past ) {
						return strcmp( $b, $a );
					} else {
						return strcmp( $a, $b );
					}
				}
			);

			if ( empty( $paged_date_groups ) ) {
				\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template( 'no-events' );
			} else {
				foreach ( $paged_date_groups as $date_key => $date_group ) {
					$date_obj        = $date_group['date_obj'];
					$events_for_date = $date_group['events'];

					$day_number           = (int) $date_obj->format( 'w' );
					$day_name             = $date_obj->format( 'l' );
					$day_of_week          = strtolower( $day_name );
					$formatted_date_label = $date_obj->format( 'l, F jS' );

					\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
						'date-group',
						array(
							'date_obj'             => $date_obj,
							'day_of_week'          => $day_of_week,
							'formatted_date_label' => $formatted_date_label,
						)
					);
					?>
					<div class="datamachine-events-wrapper">
						<?php
						foreach ( $events_for_date as $event_item ) {
							$event_post = $event_item['post'];
							$event_data = $event_item['event_data'];

							global $post;
							$post = $event_post;
							setup_postdata( $post );

							$start_date     = $event_data['startDate'] ?? '';
							$start_time     = $event_data['startTime'] ?? '';
							$venue_name     = $event_data['venue'] ?? '';
							$performer_name = $event_data['performer'] ?? '';

							$formatted_start_time = '';
							$iso_start_date       = '';
							if ( $start_date ) {
								$start_datetime_obj   = new DateTime( $start_date . ' ' . $start_time );
								$formatted_start_time = $start_datetime_obj->format( 'g:i A' );
								$iso_start_date       = $start_datetime_obj->format( 'c' );
							}

							$display_vars = array(
								'formatted_start_time' => $formatted_start_time,
								'venue_name'           => $venue_name,
								'performer_name'       => $performer_name,
								'iso_start_date'       => $iso_start_date,
								
								'show_performer'       => $event_data['showPerformer'] ?? true,
								'show_price'           => $event_data['showPrice'] ?? true,
								'show_ticket_link'     => $event_data['showTicketLink'] ?? true,
							);

							\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
								'event-item',
								array(
									'event_post'   => $event_post,
									'event_data'   => $event_data,
									'display_vars' => $display_vars,
								)
							);
						}
						?>
					</div><!-- .datamachine-events-wrapper -->
					<?php
					echo '</div><!-- .datamachine-date-group -->';
				}
			}
		} else {
			\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template( 'no-events' );
		}

		$events_html = ob_get_clean();

		// Pagination / counter / navigation
		ob_start();
		\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
			'pagination',
			array(
				'current_page'      => $current_page,
				'max_pages'         => $max_pages,
				'show_past'         => $show_past,
				'enable_pagination' => true,
			)
		);
		$pagination_html = ob_get_clean();

		ob_start();
		\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
			'results-counter',
			array(
				'current_page'    => $current_page,
				'total_events'    => $total_events,
				'events_per_page' => $events_per_page,
			)
		);
		$counter_html = ob_get_clean();

		ob_start();
		\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
			'navigation',
			array(
				'show_past'           => $show_past,
				'past_events_count'   => $past_count,
				'future_events_count' => $future_count,
			)
		);
		$navigation_html = ob_get_clean();

		return rest_ensure_response(
			array(
				'success'    => true,
				'html'       => $events_html,
				'pagination' => array(
					'html'         => $pagination_html,
					'current_page' => $current_page,
					'max_pages'    => $max_pages,
					'total_events' => $total_events,
				),
				'counter'    => $counter_html,
				'navigation' => array(
					'html'         => $navigation_html,
					'past_count'   => $past_count,
					'future_count' => $future_count,
					'show_past'    => $show_past,
				),
			)
		);
	}
}
