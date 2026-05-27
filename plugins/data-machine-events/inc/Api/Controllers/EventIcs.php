<?php
/**
 * EventIcs REST controller.
 *
 * Serves a single event as an iCalendar (.ics) download. This endpoint
 * EXPECTS to be hit as a top-level browser navigation (the "Apple Calendar /
 * Download (.ics)" item in the Add-to-Calendar dropdown is a plain `<a download>`
 * link). The BrowserNavigationGuard pattern used by /events/calendar does
 * NOT apply here.
 *
 * Returns raw `text/calendar` (not JSON), so we short-circuit the REST
 * serializer via `rest_pre_serve_request`.
 *
 * @package DataMachineEvents\Api\Controllers
 * @since   0.40.0
 */

namespace DataMachineEvents\Api\Controllers;

defined( 'ABSPATH' ) || exit;

use DataMachineEvents\EventActions\IcsBuilder;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class EventIcs {

	/**
	 * GET /datamachine/v1/events/{id}/ics
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function download( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'invalid_event_id',
				__( 'Invalid event ID.', 'data-machine-events' ),
				array( 'status' => 404 )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'event_not_found',
				__( 'Event not found.', 'data-machine-events' ),
				array( 'status' => 404 )
			);
		}

		if ( defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) && DATA_MACHINE_EVENTS_POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'event_not_found',
				__( 'Event not found.', 'data-machine-events' ),
				array( 'status' => 404 )
			);
		}

		if ( ! class_exists( IcsBuilder::class ) ) {
			require_once DATA_MACHINE_EVENTS_PLUGIN_DIR . 'inc/EventActions/IcsBuilder.php';
		}

		$body = IcsBuilder::build( $post_id );
		if ( '' === $body ) {
			return new WP_Error(
				'event_ics_unavailable',
				__( 'Could not build .ics for this event.', 'data-machine-events' ),
				array( 'status' => 404 )
			);
		}

		$slug     = $post->post_name ? $post->post_name : ( 'event-' . $post_id );
		$filename = sanitize_file_name( $slug . '.ics' );

		// Short-circuit the JSON serializer: emit text/calendar directly.
		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $response ) use ( $body, $filename ) {
				if ( $served ) {
					return $served;
				}
				if ( ! headers_sent() ) {
					header( 'Content-Type: text/calendar; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
					header( 'Cache-Control: public, max-age=300' );
				}
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return true;
			},
			10,
			2
		);

		// The body of this response is ignored because the filter above
		// already echoed the real payload; it just needs to be a 200.
		return new WP_REST_Response( null, 200 );
	}
}
