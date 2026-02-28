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

use WP_Query;
use DataMachineEvents\Blocks\Calendar\Query\EventQueryBuilder;
use DataMachineEvents\Blocks\Calendar\Data\EventHydrator;
use DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper;
use DataMachineEvents\Blocks\Calendar\Display\EventRenderer;
use DataMachineEvents\Blocks\Calendar\Pagination;
use DataMachineEvents\Blocks\Calendar\Pagination\PageBoundary;
use DataMachineEvents\Blocks\Calendar\Template_Loader;

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

		$date_data         = PageBoundary::get_unique_event_dates( $base_params );
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

		$query_args   = EventQueryBuilder::build_query_args( $query_params );
		$events_query = new WP_Query( $query_args );

		$event_counts = EventQueryBuilder::get_event_counts();

		$paged_events      = DateGrouper::build_paged_events( $events_query );
		$paged_date_groups = DateGrouper::group_events_by_date(
			$paged_events,
			$show_past,
			$range_start,
			$range_end
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
			'event_count'       => $events_query->post_count,
			'date_boundaries'   => array(
				'start_date' => $date_boundaries['start_date'],
				'end_date'   => $date_boundaries['end_date'],
			),
			'event_counts'      => array(
				'past'   => $event_counts['past'],
				'future' => $event_counts['future'],
			),
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
				$events_query->post_count,
				$total_event_count,
				$event_counts
			);
		}

		wp_reset_postdata();

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

			$serialized[ $date_key ] = array(
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
		array $event_counts
	): array {
		$events_html = EventRenderer::render_date_groups( $paged_date_groups, $gaps_detected, $include_gaps );

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
}
