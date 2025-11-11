<?php
/**
 * REST API Endpoints
 *
 * Central file for all datamachine-events REST API functionality.
 *
 * @package DataMachineEvents
 */

/**
 * Register REST API endpoints
 */
function datamachine_events_register_rest_routes() {
	register_rest_route(
		'datamachine-events/v1',
		'/calendar',
		array(
			'methods'             => 'GET',
			'callback'            => 'datamachine_events_calendar_endpoint',
			'permission_callback' => '__return_true',
			'args'                => array(
				'event_search' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_start'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'date_end'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'tax_filter'   => array(
					'type' => 'object',
				),
				'paged'        => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'past'         => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'datamachine_events_register_rest_routes' );

/**
 * Calendar endpoint callback
 *
 * Returns filtered events with pagination and navigation data.
 *
 * @param WP_REST_Request $request The REST API request.
 * @return WP_REST_Response
 */
function datamachine_events_calendar_endpoint( $request ) {
	$events_per_page = get_option( 'posts_per_page', 10 );
	$current_page    = max( 1, $request->get_param( 'paged' ) );
	$show_past       = '1' === $request->get_param( 'past' );

	// Extract filter parameters
	$search_query = $request->get_param( 'event_search' );
	$date_start   = $request->get_param( 'date_start' );
	$date_end     = $request->get_param( 'date_end' );
	$tax_filters  = $request->get_param( 'tax_filter' );

	// Build WP_Query args for SQL-based pagination
	$query_args = array(
		'post_type'      => 'datamachine_events',
		'post_status'    => 'publish',
		'posts_per_page' => $events_per_page,
		'paged'          => $current_page,
		'meta_key'       => '_dm_event_datetime',
		'orderby'        => 'meta_value',
		'order'          => $show_past ? 'DESC' : 'ASC',
	);

	// Meta query for past/future filtering and date ranges
	$meta_query = array( 'relation' => 'AND' );

	// Past or future events
	$current_datetime = current_time( 'mysql' );
	if ( $show_past ) {
		$meta_query[] = array(
			'key'     => '_dm_event_datetime',
			'value'   => $current_datetime,
			'compare' => '<',
			'type'    => 'DATETIME',
		);
	} else {
		$meta_query[] = array(
			'key'     => '_dm_event_datetime',
			'value'   => $current_datetime,
			'compare' => '>=',
			'type'    => 'DATETIME',
		);
	}

	// Date range filters
	if ( ! empty( $date_start ) ) {
		$meta_query[] = array(
			'key'     => '_dm_event_datetime',
			'value'   => $date_start . ' 00:00:00',
			'compare' => '>=',
			'type'    => 'DATETIME',
		);
	}
	if ( ! empty( $date_end ) ) {
		$meta_query[] = array(
			'key'     => '_dm_event_datetime',
			'value'   => $date_end . ' 23:59:59',
			'compare' => '<=',
			'type'    => 'DATETIME',
		);
	}

	$query_args['meta_query'] = $meta_query;

	// Auto-detect taxonomy archives
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

	// Taxonomy filters from URL
	if ( ! empty( $tax_filters ) && is_array( $tax_filters ) ) {
		$tax_query             = isset( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
		$tax_query['relation'] = 'AND';

		foreach ( $tax_filters as $taxonomy => $term_ids ) {
			$term_ids      = is_array( $term_ids ) ? $term_ids : array( $term_ids );
			$tax_query[]   = array(
				'taxonomy' => sanitize_key( $taxonomy ),
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $term_ids ),
				'operator' => 'IN',
			);
		}

		$query_args['tax_query'] = $tax_query;
	}

	// Search query
	if ( ! empty( $search_query ) ) {
		$query_args['s'] = $search_query;
	}

	// Allow external filtering of query args
	$query_args = apply_filters( 'datamachine_events_calendar_query_args', $query_args, array(), null );

	// Execute SQL-based query
	$events_query = new WP_Query( $query_args );

	// Pagination data
	$total_events = $events_query->found_posts;
	$max_pages    = $events_query->max_num_pages;

	// Execute separate queries for past/future event counts
	// Used by navigation component to show/hide "Past Events" and "Upcoming Events" buttons
	// posts_per_page = 1 for efficiency (only need found_posts count, not actual events)
	// fields = 'ids' for performance (only fetch IDs, not full post objects)
	$future_count_args = array(
		'post_type'      => 'datamachine_events',
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => 1,
		'meta_query'     => array(
			array(
				'key'     => '_dm_event_datetime',
				'value'   => $current_datetime,
				'compare' => '>=',
				'type'    => 'DATETIME',
			),
		),
	);
	$future_query      = new WP_Query( $future_count_args );
	$future_count      = $future_query->found_posts;

	$past_count_args = array(
		'post_type'      => 'datamachine_events',
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => 1,
		'meta_query'     => array(
			array(
				'key'     => '_dm_event_datetime',
				'value'   => $current_datetime,
				'compare' => '<',
				'type'    => 'DATETIME',
			),
		),
	);
	$past_query      = new WP_Query( $past_count_args );
	$past_count      = $past_query->found_posts;

	// Build response with HTML components
	ob_start();

	// Capture events HTML
	if ( $events_query->have_posts() ) {
		// Build paged_events array for display (parse blocks only for current page)
		$paged_events = array();
		while ( $events_query->have_posts() ) {
			$events_query->the_post();
			$event_post = get_post();

			// Parse blocks to extract event data (only for current page events)
			$blocks     = parse_blocks( $event_post->post_content );
			$event_data = null;
			foreach ( $blocks as $block ) {
				if ( 'datamachine-events/event-details' === $block['blockName'] ) {
					$event_data = $block['attrs'];
					break;
				}
			}

			if ( $event_data && ! empty( $event_data['startDate'] ) ) {
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

		// Group events by date
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

		// Sort date groups chronologically (DESC for past events, ASC for upcoming)
		uksort(
			$paged_date_groups,
			function ( $a, $b ) use ( $show_past ) {
				if ( $show_past ) {
					return strcmp( $b, $a ); // Most recent first for past events
				} else {
					return strcmp( $a, $b ); // Soonest first for upcoming events
				}
			}
		);

		// Render events HTML
		foreach ( $paged_date_groups as $date_key => $date_group ) :
			$date_obj        = $date_group['date_obj'];
			$events_for_date = $date_group['events'];

			$day_number          = (int) $date_obj->format( 'w' );
			$day_name            = $date_obj->format( 'l' );
			$day_of_week         = strtolower( $day_name );
			$formatted_date_label = $date_obj->format( 'l, F jS' );

			// Render date group header using template loader
			// Template variables: date_obj, day_of_week, formatted_date_label
			\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
				'date-group',
				array(
					'date_obj'             => $date_obj,
					'day_of_week'          => $day_of_week,
					'formatted_date_label' => $formatted_date_label,
				)
			);
			?>

			<div class="dm-events-wrapper">
				<?php
				foreach ( $events_for_date as $event_item ) :
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
						'show_venue'           => $event_data['showVenue'] ?? true,
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
					endforeach;
				?>
			</div><!-- .dm-events-wrapper -->
			<?php

			echo '</div><!-- .dm-date-group -->';

			endforeach;
	} else {
		\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template( 'no-events' );
	}

	$events_html = ob_get_clean();

	// Capture pagination HTML
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

	// Capture counter HTML
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

	// Capture navigation HTML
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

	// Build JSON response
	return rest_ensure_response(
		array(
			'success'      => true,
			'html'         => $events_html,
			'pagination'   => array(
				'html'         => $pagination_html,
				'current_page' => $current_page,
				'max_pages'    => $max_pages,
				'total_events' => $total_events,
			),
			'counter'      => $counter_html,
			'navigation'   => array(
				'html'         => $navigation_html,
				'past_count'   => $past_count,
				'future_count' => $future_count,
				'show_past'    => $show_past,
			),
		)
	);
}
