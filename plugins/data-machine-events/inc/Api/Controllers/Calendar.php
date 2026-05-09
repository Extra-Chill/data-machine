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
use DataMachineEvents\Blocks\Calendar\Query\CalendarRequest;

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
		$calendar_request = CalendarRequest::fromRestRequest( $request );
		$abilities        = new CalendarAbilities();
		$result           = $abilities->executeGetCalendarPage( $calendar_request->toAbilitiesArgs() );

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
