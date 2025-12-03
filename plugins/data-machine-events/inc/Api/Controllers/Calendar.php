<?php
namespace DataMachineEvents\Api\Controllers;

defined('ABSPATH') || exit;

use WP_Query;
use WP_REST_Request;
use DataMachineEvents\Blocks\Calendar\Calendar_Query;
use DataMachineEvents\Blocks\Calendar\Pagination;
use DataMachineEvents\Blocks\Calendar\Template_Loader;

/**
 * Calendar API controller
 */
class Calendar {

	/**
	 * Calendar endpoint implementation
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function calendar(WP_REST_Request $request) {
		Template_Loader::init();

		$current_page = max(1, (int) $request->get_param('paged'));
		$show_past = '1' === $request->get_param('past');

		$search_query = $request->get_param('event_search');
		$user_date_start = $request->get_param('date_start');
		$user_date_end = $request->get_param('date_end');
		$tax_filters = $request->get_param('tax_filter');

		$tax_query_override = null;
		if (is_tax()) {
			$term = get_queried_object();
			if ($term && isset($term->taxonomy) && isset($term->term_id)) {
				$tax_query_override = [
					[
						'taxonomy' => $term->taxonomy,
						'field' => 'term_id',
						'terms' => $term->term_id,
					],
				];
			}
		}

		$base_params = [
			'show_past' => $show_past,
			'search_query' => $search_query ?? '',
			'date_start' => $user_date_start ?? '',
			'date_end' => $user_date_end ?? '',
			'tax_filters' => is_array($tax_filters) ? $tax_filters : [],
			'tax_query_override' => $tax_query_override,
		];

		$unique_dates = Calendar_Query::get_unique_event_dates($base_params);
		$date_boundaries = Calendar_Query::get_date_boundaries_for_page($unique_dates, $current_page);

		$max_pages = $date_boundaries['max_pages'];
		$current_page = max(1, min($current_page, max(1, $max_pages)));

		$query_params = $base_params;
		if (!empty($date_boundaries['start_date']) && !empty($date_boundaries['end_date'])) {
			if (empty($user_date_start)) {
				$query_params['date_start'] = $date_boundaries['start_date'];
			}
			if (empty($user_date_end)) {
				$query_params['date_end'] = $date_boundaries['end_date'];
			}
		}

		$query_args = Calendar_Query::build_query_args($query_params);
		$events_query = new WP_Query($query_args);

		$total_events = count($unique_dates);

		$event_counts = Calendar_Query::get_event_counts();
		$past_count = $event_counts['past'];
		$future_count = $event_counts['future'];

		$paged_events = Calendar_Query::build_paged_events($events_query);
		$paged_date_groups = Calendar_Query::group_events_by_date($paged_events, $show_past);

		ob_start();

		if (!empty($paged_date_groups)) {
			foreach ($paged_date_groups as $date_key => $date_group) {
				$date_obj = $date_group['date_obj'];
				$events_for_date = $date_group['events'];

				$day_of_week = strtolower($date_obj->format('l'));
				$formatted_date_label = $date_obj->format('l, F jS');

			\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
				'date-group',
				[
					'date_obj' => $date_obj,
					'day_of_week' => $day_of_week,
					'formatted_date_label' => $formatted_date_label,
					'events_count' => count($events_for_date),
				]
			);
				?>
				<div class="datamachine-events-wrapper">
					<?php
					foreach ($events_for_date as $event_item) {
						$event_post = $event_item['post'];
						$event_data = $event_item['event_data'];

						global $post;
						$post = $event_post;
						setup_postdata($post);

						$display_vars = Calendar_Query::build_display_vars($event_data);

						\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
							'event-item',
							[
								'event_post' => $event_post,
								'event_data' => $event_data,
								'display_vars' => $display_vars,
							]
						);
					}
					?>
				</div><!-- .datamachine-events-wrapper -->
				<?php
				echo '</div><!-- .datamachine-date-group -->';
			}
		} else {
			\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template('no-events');
		}

		$events_html = ob_get_clean();

		$pagination_html = Pagination::render_pagination($current_page, $max_pages, $show_past);

		ob_start();
		\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
			'results-counter',
			[
				'page_start_date' => $date_boundaries['start_date'],
				'page_end_date' => $date_boundaries['end_date'],
				'event_count' => $events_query->post_count,
			]
		);
		$counter_html = ob_get_clean();

		ob_start();
		\DataMachineEvents\Blocks\Calendar\Template_Loader::include_template(
			'navigation',
			[
				'show_past' => $show_past,
				'past_events_count' => $past_count,
				'future_events_count' => $future_count,
			]
		);
		$navigation_html = ob_get_clean();

		return rest_ensure_response(
			[
				'success' => true,
				'html' => $events_html,
				'pagination' => [
					'html' => $pagination_html,
					'current_page' => $current_page,
					'max_pages' => $max_pages,
					'total_events' => $total_events,
				],
				'counter' => $counter_html,
				'navigation' => [
					'html' => $navigation_html,
					'past_count' => $past_count,
					'future_count' => $future_count,
					'show_past' => $show_past,
				],
			]
		);
	}
}
