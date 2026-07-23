<?php
/**
 * Public canonical event upsert ability.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\VenueParameterProvider;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;

defined( 'ABSPATH' ) || exit;

class EventUpsertAbilities {

	public const ABILITY_NAME = 'data-machine-events/upsert-event';

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		add_action( 'wp_abilities_api_init', array( $this, 'registerAbility' ) );
		self::$registered = true;
	}

	/**
	 * Register the public ability contract.
	 */
	public function registerAbility(): void {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Upsert Event', 'data-machine-events' ),
				'description'         => __( 'Deterministically create or update a canonical event from a stable caller-supplied source identity.', 'data-machine-events' ),
				'category'            => AbilityCategories::EVENTS,
				'input_schema'        => $this->getInputSchema(),
				'output_schema'       => $this->getOutputSchema(),
				'execute_callback'    => array( $this, 'executeUpsertEvent' ),
				'permission_callback' => AbilityPermissions::canWrite(),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute a canonical event upsert.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Normalized event result or validation failure.
	 */
	public function executeUpsertEvent( array $input ): array|\WP_Error {
		$source    = trim( sanitize_text_field( (string) ( $input['source'] ?? '' ) ) );
		$source_id = trim( sanitize_text_field( (string) ( $input['source_id'] ?? '' ) ) );
		$event     = is_array( $input['event'] ?? null ) ? $input['event'] : array();

		if ( '' === $source || '' === $source_id ) {
			return new \WP_Error( 'missing_source_identity', 'Both source and source_id are required.', array( 'status' => 400 ) );
		}

		if ( empty( $event ) ) {
			return new \WP_Error( 'missing_event', 'The event object is required.', array( 'status' => 400 ) );
		}

		$venue_error = $this->validateVenueIdentity( $event );
		if ( $venue_error ) {
			return $venue_error;
		}

		$event['source']          = $source;
		$event['source_id']       = $source_id;
		$event['source_identity'] = hash( 'sha256', $source . "\0" . $source_id );

		$config = array(
			'post_status'    => sanitize_key( (string) ( $input['post_status'] ?? 'publish' ) ),
			'post_author'    => absint( $input['post_author'] ?? 0 ),
			'include_images' => false,
		);
		if ( ! in_array( $config['post_status'], array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
			return new \WP_Error( 'invalid_post_status', 'post_status must be draft, publish, pending, or private.', array( 'status' => 400 ) );
		}

		$result = ( new EventUpsert() )->upsertCanonicalEvent( $event, $config );
		if ( empty( $result['success'] ) ) {
			$retryable  = ! empty( $result['retryable'] );
			$error_code = (string) ( $result['error_code'] ?? $result['rule'] ?? 'event_upsert_failed' );
			return new \WP_Error(
				$error_code,
				(string) ( $result['error'] ?? 'Event upsert failed.' ),
				array(
					'status'    => $retryable ? 503 : 400,
					'rule'      => $result['rule'] ?? null,
					'retryable' => $retryable,
					'transient' => $retryable,
				)
			);
		}

		$post_id     = (int) $result['data']['post_id'];
		$venue_terms = wp_get_object_terms( $post_id, 'venue' );
		$venue_term  = ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ? $venue_terms[0] : null;
		$post        = get_post( $post_id );
		$dates       = \DataMachineEvents\Core\EventDatesTable::get( $post_id );

		return array(
			'success'    => true,
			'event_id'   => $post_id,
			'event_url'  => (string) ( $result['data']['post_url'] ?? get_permalink( $post_id ) ),
			'action'     => (string) $result['data']['action'],
			'source'     => array(
				'name'     => $source,
				'id'       => $source_id,
				'identity' => $event['source_identity'],
			),
			'normalized' => array(
				'title'          => $post instanceof \WP_Post ? $post->post_title : (string) ( $event['title'] ?? '' ),
				'post_status'    => $post instanceof \WP_Post ? $post->post_status : $config['post_status'],
				'start_datetime' => $dates ? (string) $dates->start_datetime : '',
				'end_datetime'   => $dates ? (string) $dates->end_datetime : '',
				'venue'          => $venue_term instanceof \WP_Term ? $venue_term->name : '',
				'venue_id'       => $venue_term instanceof \WP_Term ? (int) $venue_term->term_id : 0,
			),
		);
	}

	/**
	 * Reject geographically ambiguous existing venue identities before writes.
	 *
	 * @param array $event Canonical event fields.
	 * @return \WP_Error|null Venue conflict error, or null when safe.
	 */
	private function validateVenueIdentity( array $event ): ?\WP_Error {
		$venue_name = trim( (string) ( $event['venue'] ?? '' ) );
		if ( '' === $venue_name ) {
			return null;
		}

		$identity = Venue_Taxonomy::resolve_venue_identity(
			$venue_name,
			VenueParameterProvider::extractFromParameters( $event )
		);
		if ( 'ambiguous' !== $identity['match_status'] ) {
			return null;
		}

		return new \WP_Error(
			'ambiguous_venue',
			'The venue name conflicts with existing geographic identity and cannot be resolved safely.',
			array(
				'status' => 409,
				'venue'  => $venue_name,
			)
		);
	}

	private function getInputSchema(): array {
		$string = array( 'type' => 'string' );

		return array(
			'type'       => 'object',
			'required'   => array( 'source', 'source_id', 'event' ),
			'properties' => array(
				'source'      => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Stable caller/source namespace.',
				),
				'source_id'   => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Stable item ID within the source namespace.',
				),
				'post_status' => array(
					'type'    => 'string',
					'enum'    => array( 'draft', 'publish', 'pending', 'private' ),
					'default' => 'publish',
				),
				'post_author' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'event'       => array(
					'type'       => 'object',
					'required'   => array( 'title', 'startDate' ),
					'properties' => array(
						'title'             => $string,
						'description'       => $string,
						'startDate'         => $string,
						'startTime'         => $string,
						'endDate'           => $string,
						'endTime'           => $string,
						'occurrenceDates'   => array(
							'type'  => 'array',
							'items' => $string,
						),
						'venue'             => $string,
						'venueAddress'      => $string,
						'venueCity'         => $string,
						'venueState'        => $string,
						'venueZip'          => $string,
						'venueCountry'      => $string,
						'venuePhone'        => $string,
						'venueWebsite'      => $string,
						'venueCoordinates'  => $string,
						'venueCapacity'     => $string,
						'venueTimezone'     => $string,
						'price'             => $string,
						'priceCurrency'     => $string,
						'ticketUrl'         => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'offerAvailability' => $string,
						'performer'         => $string,
						'performerType'     => $string,
						'organizer'         => $string,
						'organizerType'     => $string,
						'organizerUrl'      => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'eventStatus'       => $string,
						'previousStartDate' => $string,
						'eventType'         => $string,
					),
				),
			),
		);
	}

	private function getOutputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'success', 'event_id', 'event_url', 'action', 'source', 'normalized' ),
			'properties' => array(
				'success'    => array( 'type' => 'boolean' ),
				'event_id'   => array( 'type' => 'integer' ),
				'event_url'  => array( 'type' => 'string' ),
				'action'     => array(
					'type' => 'string',
					'enum' => array( 'created', 'updated', 'no_change' ),
				),
				'source'     => array(
					'type'       => 'object',
					'required'   => array( 'name', 'id', 'identity' ),
					'properties' => array(
						'name'     => array( 'type' => 'string' ),
						'id'       => array( 'type' => 'string' ),
						'identity' => array( 'type' => 'string' ),
					),
				),
				'normalized' => array(
					'type'       => 'object',
					'required'   => array( 'title', 'post_status', 'start_datetime', 'end_datetime', 'venue', 'venue_id' ),
					'properties' => array(
						'title'          => array( 'type' => 'string' ),
						'post_status'    => array( 'type' => 'string' ),
						'start_datetime' => array( 'type' => 'string' ),
						'end_datetime'   => array( 'type' => 'string' ),
						'venue'          => array( 'type' => 'string' ),
						'venue_id'       => array( 'type' => 'integer' ),
					),
				),
			),
		);
	}
}
