<?php
/**
 * Move Event Abilities
 *
 * Moves a single event post from one venue to another, recording an
 * audit-log entry capturing the from/to venue, reason, and actor. This is
 * the named primitive for "the show moved venues" — for generic block
 * attribute updates use data-machine-events/update-event; for duplicate
 * cleanup use data-machine-events/delete-event (issue #286).
 *
 * Behavior:
 *  1. Validate event post exists and is the correct post type.
 *  2. Validate to_venue term exists in the 'venue' taxonomy.
 *  3. Capture the current ("from") venue term before changing anything.
 *  4. Reuse EventUpdateAbilities to perform the actual venue swap so the
 *     block-update + taxonomy logic is not duplicated.
 *  5. Append a record to post meta '_dme_venue_history' and fire the
 *     'dme_event_venue_changed' action so listeners (analytics, audit
 *     log, etc.) can react.
 *
 * @package DataMachineEvents\Abilities
 * @since   0.39.0
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;

defined( 'ABSPATH' ) || exit;

class MoveEventAbilities {

	/**
	 * Post-meta key storing the venue-change history array.
	 *
	 * Each entry is an associative array:
	 *   array(
	 *     'from_venue_id'   => int,
	 *     'from_venue_name' => string,
	 *     'to_venue_id'     => int,
	 *     'to_venue_name'   => string,
	 *     'reason'          => string,
	 *     'actor_id'        => int,
	 *     'recorded_at'     => string ISO 8601 UTC,
	 *   )
	 */
	public const VENUE_HISTORY_META_KEY = '_dme_venue_history';

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
				'data-machine-events/move-event',
				array(
					'label'               => __( 'Move Event', 'data-machine-events' ),
					'description'         => __( 'Move a single event post to a new venue and record an audit-log entry capturing the from/to venue and reason.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'event', 'to_venue' ),
						'properties' => array(
							'event'    => array(
								'type'        => 'integer',
								'description' => 'Event post ID to move.',
							),
							'to_venue' => array(
								'type'        => 'integer',
								'description' => 'Destination venue term ID. Must exist in the venue taxonomy.',
							),
							'reason'   => array(
								'type'        => 'string',
								'description' => 'Free-form rationale recorded in the venue history (e.g. "moved from Firefly to Refinery per @qrisg").',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'event_id'    => array( 'type' => 'integer' ),
							'from_venue'  => array(
								'type'       => 'object',
								'properties' => array(
									'id'   => array( 'type' => 'integer' ),
									'name' => array( 'type' => 'string' ),
								),
							),
							'to_venue'    => array(
								'type'       => 'object',
								'properties' => array(
									'id'   => array( 'type' => 'integer' ),
									'name' => array( 'type' => 'string' ),
								),
							),
							'start_date'  => array( 'type' => 'string' ),
							'recorded_at' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'execute' ),
					'permission_callback' => AbilityPermissions::canWrite(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute the move.
	 *
	 * @param array $input {
	 *     @type int    $event    Required. Event post ID.
	 *     @type int    $to_venue Required. Destination venue term ID.
	 *     @type string $reason   Optional. Free-form rationale.
	 * }
	 * @return array|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error {
		$post_id     = (int) ( $input['event'] ?? 0 );
		$to_venue_id = (int) ( $input['to_venue'] ?? 0 );
		$reason      = trim( (string) ( $input['reason'] ?? '' ) );

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'invalid_event',
				'event (post ID) is required and must be a positive integer.',
				array( 'status' => 400 )
			);
		}

		if ( $to_venue_id <= 0 ) {
			return new \WP_Error(
				'invalid_to_venue',
				'to_venue (venue term ID) is required and must be a positive integer.',
				array( 'status' => 400 )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'event_not_found',
				sprintf( 'Event post %d not found or wrong post_type.', $post_id ),
				array( 'status' => 404 )
			);
		}

		$to_term = get_term( $to_venue_id, 'venue' );
		if ( ! $to_term || is_wp_error( $to_term ) ) {
			return new \WP_Error(
				'to_venue_not_found',
				sprintf( 'Venue term %d not found in venue taxonomy.', $to_venue_id ),
				array( 'status' => 404 )
			);
		}

		$from_venue = $this->captureCurrentVenue( $post_id );

		if ( null !== $from_venue && $from_venue['id'] === $to_venue_id ) {
			return new \WP_Error(
				'venue_unchanged',
				sprintf( 'Event %d is already assigned to venue %d.', $post_id, $to_venue_id ),
				array( 'status' => 400 )
			);
		}

		// Reuse EventUpdateAbilities so the block-update + taxonomy assignment
		// logic stays in one place. Calling executeUpdateEvent with just the
		// venue field triggers updateVenue() and skips the block rewrite.
		$update_abilities = new EventUpdateAbilities();
		$update_result    = $update_abilities->executeUpdateEvent(
			array(
				'event' => $post_id,
				'venue' => $to_venue_id,
			)
		);

		if ( is_wp_error( $update_result ) ) {
			return $update_result;
		}

		$results = $update_result['results'][0] ?? array();
		if ( ( $results['status'] ?? '' ) !== 'updated' || ! in_array( 'venue', $results['updated_fields'] ?? array(), true ) ) {
			$warnings = $results['warnings'] ?? array();
			$error    = $results['error'] ?? ( ! empty( $warnings ) ? implode( '; ', $warnings ) : 'Venue assignment did not complete.' );
			return new \WP_Error( 'venue_update_failed', $error, array( 'status' => 500 ) );
		}

		$recorded_at = gmdate( 'c' );
		$actor_id    = get_current_user_id();

		$history_entry = array(
			'from_venue_id'   => $from_venue['id'] ?? 0,
			'from_venue_name' => $from_venue['name'] ?? '',
			'to_venue_id'     => $to_venue_id,
			'to_venue_name'   => $to_term->name,
			'reason'          => $reason,
			'actor_id'        => $actor_id,
			'recorded_at'     => $recorded_at,
		);

		$history   = get_post_meta( $post_id, self::VENUE_HISTORY_META_KEY, true );
		$history   = is_array( $history ) ? $history : array();
		$history[] = $history_entry;
		update_post_meta( $post_id, self::VENUE_HISTORY_META_KEY, $history );

		/**
		 * Fires after an event has been moved to a new venue.
		 *
		 * @param int    $post_id     Event post ID.
		 * @param array  $from_venue  array{id:int,name:string}|array{} The previous venue, if any.
		 * @param array  $to_venue    array{id:int,name:string} The new venue.
		 * @param string $reason      Free-form rationale supplied by the caller.
		 * @param int    $actor_id    User ID that initiated the move (0 if none).
		 * @param string $recorded_at ISO 8601 UTC timestamp.
		 */
		do_action(
			'dme_event_venue_changed',
			$post_id,
			$from_venue ?? array(),
			array(
				'id'   => $to_venue_id,
				'name' => $to_term->name,
			),
			$reason,
			$actor_id,
			$recorded_at
		);

		do_action(
			'datamachine_log',
			'info',
			'Moved event to new venue.',
			array(
				'event_id'      => $post_id,
				'from_venue_id' => $from_venue['id'] ?? 0,
				'to_venue_id'   => $to_venue_id,
				'reason'        => $reason,
			)
		);

		$start_date = $this->resolveStartDate( $post );

		return array(
			'event_id'    => $post_id,
			'from_venue'  => $from_venue ?? array(
				'id'   => 0,
				'name' => '',
			),
			'to_venue'    => array(
				'id'   => $to_venue_id,
				'name' => $to_term->name,
			),
			'start_date'  => $start_date,
			'recorded_at' => $recorded_at,
		);
	}

	/**
	 * Capture the event's current venue term (if any) before the move.
	 *
	 * @param int $post_id Event post ID.
	 * @return array{id:int,name:string}|null Null when the event has no venue assigned.
	 */
	private function captureCurrentVenue( int $post_id ): ?array {
		$terms = get_the_terms( $post_id, 'venue' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return null;
		}

		$term = reset( $terms );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return array(
			'id'   => (int) $term->term_id,
			'name' => $term->name,
		);
	}

	/**
	 * Resolve the event's startDate from the event-details block, if available.
	 *
	 * @param \WP_Post $post
	 * @return string Empty string when no startDate is set.
	 */
	private function resolveStartDate( \WP_Post $post ): string {
		$blocks = parse_blocks( $post->post_content );
		foreach ( $blocks as $block ) {
			if ( 'data-machine-events/event-details' === ( $block['blockName'] ?? '' ) ) {
				return (string) ( $block['attrs']['startDate'] ?? '' );
			}
		}
		return '';
	}
}
