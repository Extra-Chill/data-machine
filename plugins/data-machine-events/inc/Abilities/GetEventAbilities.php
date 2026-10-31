<?php
/**
 * Public event detail lookup using the calendar occurrence producer.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Api\Controllers\Calendar;
use DataMachineEvents\Blocks\Calendar\Data\EventHydrator;
use DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper;
use DataMachineEvents\Core\DateTimeParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GetEventAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'data-machine-events/get-event',
			array(
				'label'               => __( 'Get Event', 'data-machine-events' ),
				'description'         => __( 'Get a published event by ID, using the calendar occurrence contract.', 'data-machine-events' ),
				'category'            => 'datamachine-events-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'event_id' ),
					'properties' => array( 'event_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'id', 'title', 'datetime', 'end_datetime', 'venue', 'performer', 'occurrence_display', 'ticket_url', 'permalink', 'content_html' ),
					'properties' => array(
						'id'                 => array( 'type' => 'integer' ),
						'title'              => array( 'type' => 'string' ),
						'datetime'           => array( 'type' => 'string' ),
						'end_datetime'       => array( 'type' => 'string' ),
						'venue'              => array( 'type' => array( 'object', 'null' ) ),
						'performer'          => array( 'type' => 'object' ),
						'occurrence_display' => array( 'type' => 'object' ),
						'ticket_url'         => array( 'type' => 'string' ),
						'permalink'          => array( 'type' => 'string' ),
						'content_html'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeGetEvent' ),
				'permission_callback' => '__return_true',
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * Return the first calendar occurrence, independent of calendar pagination/time scope.
	 *
	 * @return array|\WP_Error
	 */
	public function executeGetEvent( array $input ) {
		$event_id = (int) ( $input['event_id'] ?? 0 );
		if ( $event_id < 1 ) {
			return $this->notFound();
		}
		$post = get_post( $event_id );
		if ( ! $post || 'data_machine_events' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return $this->notFound();
		}

		$data = EventHydrator::parse_event_data( $post );
		if ( ! $data ) {
			return $this->notFound();
		}
		$timezone = DateGrouper::get_event_timezone( $data );
		$datetime = DateTimeParser::safeCreate( $data['startDate'] . ' ' . ( $data['startTime'] ?? '00:00:00' ), $timezone );
		if ( ! $datetime ) {
			return $this->notFound();
		}
		$groups = DateGrouper::group_events_by_date(
			array( array( 'post' => $post, 'event_data' => $data, 'datetime' => $datetime ) ),
			true
		);
		// Grouping sorts past dates in reverse order; detail always uses the first occurrence.
		ksort( $groups );
		$group = reset( $groups );
		if ( ! $group ) {
			return $this->notFound();
		}
		$entry = array(
			'post_id'         => $post->ID,
			'title'           => get_the_title( $post ),
			'event_data'      => $data,
			'display_context' => $group['events'][0]['display_context'],
		);
		$calendar   = new Calendar();
		$serialized = $calendar->serialize_contract_occurrence( $entry );
		$event      = $serialized['event'];
		$end        = ! empty( $data['endDate'] ) ? DateTimeParser::safeCreate( $data['endDate'] . ' ' . ( $data['endTime'] ?? '00:00:00' ), $timezone ) : null;

		return array_merge(
			$event,
			array(
				'datetime'           => $datetime->format( DATE_ATOM ),
				'end_datetime'       => $end ? $end->format( DATE_ATOM ) : '',
				'occurrence'         => $serialized['occurrence'],
				'occurrence_display' => $serialized['occurrence']['display'],
				'ticket_url'         => $event['ticket']['url'],
				'permalink'          => (string) get_permalink( $post ),
				'content_html'       => $this->renderContent( $post ),
			)
		);
	}

	/** Render dynamic blocks with the requested post, restoring the caller's context. */
	private function renderContent( \WP_Post $event ): string {
		global $post;
		$previous = $post;
		$post     = $event;
		setup_postdata( $post );
		try {
			return (string) apply_filters( 'the_content', $event->post_content );
		} finally {
			$post = $previous;
			if ( $post instanceof \WP_Post ) {
				setup_postdata( $post );
			} else {
				wp_reset_postdata();
			}
		}
	}

	private function notFound(): \WP_Error {
		return new \WP_Error( 'event_not_found', __( 'Event not found.', 'data-machine-events' ), array( 'status' => 404 ) );
	}
}
