<?php
/**
 * Calendar REST API Controller
 *
 * Thin wrapper around CalendarAbilities for REST API access.
 * All business logic delegated to CalendarAbilities.
 */

namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DataMachineEvents\Abilities\CalendarAbilities;

/**
 * Calendar API controller
 */
class Calendar {

	/**
	 * Calendar endpoint implementation
	 *
	 * @param WP_REST_Request $request REST request object
	 * @return \WP_REST_Response
	 */
	public function calendar( WP_REST_Request $request ) {
		$abilities = new CalendarAbilities();
		$result    = $abilities->executeGetCalendarPage(
			array(
				'paged'            => $request->get_param( 'paged' ) ?? 1,
				'past'             => '1' === $request->get_param( 'past' ),
				'event_search'     => $request->get_param( 'event_search' ) ?? '',
				'date_start'       => $request->get_param( 'date_start' ) ?? '',
				'date_end'         => $request->get_param( 'date_end' ) ?? '',
				'tax_filter'       => $request->get_param( 'tax_filter' ) ?? array(),
				'archive_taxonomy' => $request->get_param( 'archive_taxonomy' ) ?? '',
				'archive_term_id'  => $request->get_param( 'archive_term_id' ) ?? 0,
				'geo_lat'          => $request->get_param( 'lat' ) ?? '',
				'geo_lng'          => $request->get_param( 'lng' ) ?? '',
				'geo_radius'       => $request->get_param( 'radius' ) ?? 25,
				'geo_radius_unit'  => $request->get_param( 'radius_unit' ) ?? 'mi',
				'include_html'     => true,
				'include_gaps'     => true,
			)
		);

		return rest_ensure_response(
			array(
				'success'    => true,
				'html'       => $result['html']['events'],
				'pagination' => array(
					'html'         => $result['html']['pagination'],
					'current_page' => $result['current_page'],
					'max_pages'    => $result['max_pages'],
					'total_events' => $result['total_event_count'],
				),
				'counter'    => $result['html']['counter'],
				'navigation' => array(
					'html'         => $result['html']['navigation'],
					'past_count'   => $result['event_counts']['past'],
					'future_count' => $result['event_counts']['future'],
					'show_past'    => ! empty( $request->get_param( 'past' ) ),
				),
			)
		);
	}
}
