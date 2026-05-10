<?php
/**
 * Calendar REST API Controller
 *
 * Thin wrapper around CalendarAbilities for REST API access.
 * All business logic delegated to CalendarAbilities.
 *
 * Wraps the response in a top-level full-response cache to mitigate
 * crawler-driven DOS on `?past=1` historical archive variants. See
 * Extra-Chill/data-machine-events#246 — Pinterestbot iterating every
 * venue/artist archive with distinct geo params produced one expensive
 * query per request because the underlying bucket cache was keyed
 * without geo params.
 */

namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use DataMachineEvents\Abilities\CalendarAbilities;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
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
		$envelope         = $calendar_request->toAbilitiesArgs();

		// Editors with `manage_options` always bypass the cache so they
		// see fresh data immediately after publishing / editing events.
		// Anonymous traffic (the DOS-vector path) uses the cache.
		$bypass_cache = current_user_can( 'manage_options' );

		$cache_key = CalendarCache::generate_full_response_key( $envelope );

		if ( ! $bypass_cache ) {
			$cached = CalendarCache::get_full_response( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return rest_ensure_response( $cached );
			}
		}

		$abilities = new CalendarAbilities();
		$result    = $abilities->executeGetCalendarPage( $envelope );

		$response_body = array(
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
		);

		if ( ! $bypass_cache ) {
			CalendarCache::set_full_response(
				$cache_key,
				$response_body,
				CalendarCache::ttl_for_envelope( $envelope )
			);
		}

		return rest_ensure_response( $response_body );
	}
}
