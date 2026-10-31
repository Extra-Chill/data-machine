<?php
/**
 * Event Rating Abilities
 *
 * Ability surface for the show-rating primitive (see Core\EventRatings):
 * - data-machine-events/rate-event: the current user rates/reviews a past event.
 * - data-machine-events/get-event-ratings: aggregate plus approved reviews.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\EventRatings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventRatingAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			$review_schema = array(
				'type'       => 'object',
				'properties' => array(
					'comment_id'  => array( 'type' => 'integer' ),
					'event_id'    => array( 'type' => 'integer' ),
					'user_id'     => array( 'type' => 'integer' ),
					'author_name' => array( 'type' => 'string' ),
					'rating'      => array( 'type' => 'integer' ),
					'review'      => array( 'type' => 'string' ),
					'approved'    => array( 'type' => 'boolean' ),
					'date_gmt'    => array( 'type' => 'string' ),
					'link'        => array( 'type' => 'string' ),
				),
			);

			$summary_schema = array(
				'type'       => 'object',
				'properties' => array(
					'average' => array( 'type' => array( 'number', 'null' ) ),
					'count'   => array( 'type' => 'integer' ),
				),
			);

			wp_register_ability(
				'data-machine-events/rate-event',
				array(
					'label'               => __( 'Rate Event', 'data-machine-events' ),
					'description'         => __( 'Rate a past event from 1 to 5 as the current user, with an optional written review. Creates the user\'s review or updates it if one exists.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'event_id', 'rating' ),
						'properties' => array(
							'event_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => __( 'Event post ID.', 'data-machine-events' ),
							),
							'rating'   => array(
								'type'        => 'integer',
								'minimum'     => EventRatings::MIN_RATING,
								'maximum'     => EventRatings::MAX_RATING,
								'description' => __( 'Whole-number rating from 1 to 5.', 'data-machine-events' ),
							),
							'review'   => array(
								'type'        => 'string',
								'description' => __( 'Optional review text. Omit to keep an existing review\'s text.', 'data-machine-events' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'review'  => $review_schema,
							'created' => array( 'type' => 'boolean' ),
							'summary' => $summary_schema,
						),
					),
					'execute_callback'    => array( $this, 'executeRate' ),
					'permission_callback' => static function () {
						return is_user_logged_in();
					},
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);

			wp_register_ability(
				'data-machine-events/get-event-ratings',
				array(
					'label'               => __( 'Get Event Ratings', 'data-machine-events' ),
					'description'         => __( 'Return an event\'s rating summary and its approved reviews, oldest first. Includes the current user\'s own review and whether they may rate.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'event_id' ),
						'properties' => array(
							'event_id' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'limit'    => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 200,
								'description' => __( 'Maximum reviews to return. Default 50.', 'data-machine-events' ),
							),
							'offset'   => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'event_id'    => array( 'type' => 'integer' ),
							'rateable'    => array( 'type' => 'boolean' ),
							'can_rate'    => array( 'type' => 'boolean' ),
							'summary'     => $summary_schema,
							'reviews'     => array(
								'type'  => 'array',
								'items' => $review_schema,
							),
							'user_review' => array( 'type' => array( 'object', 'null' ) ),
						),
					),
					'execute_callback'    => array( $this, 'executeGet' ),
					'permission_callback' => '__return_true',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);

			wp_register_ability(
				'data-machine-events/get-my-event-ratings',
				array(
					'label'               => __( 'Get My Event Ratings', 'data-machine-events' ),
					'description'         => __( 'Return the current user\'s own ratings for a batch of events, keyed by event ID. Unrated events are omitted.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'event_ids' ),
						'properties' => array(
							'event_ids' => array(
								'type'     => 'array',
								'items'    => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'maxItems' => 100,
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'ratings' => array(
								'type'                 => 'object',
								'additionalProperties' => array( 'type' => 'integer' ),
							),
						),
					),
					'execute_callback'    => array( $this, 'executeGetMine' ),
					'permission_callback' => static function () {
						return is_user_logged_in();
					},
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
					),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function executeRate( array $input ) {
		$review = array_key_exists( 'review', $input ) ? (string) $input['review'] : null;
		return EventRatings::rate( get_current_user_id(), (int) ( $input['event_id'] ?? 0 ), $input['rating'] ?? null, $review );
	}

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function executeGet( array $input ) {
		$event_id = (int) ( $input['event_id'] ?? 0 );
		$post     = get_post( $event_id );
		if ( ! $post || \DataMachineEvents\Core\Event_Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'event_not_found', __( 'Event not found.', 'data-machine-events' ), array( 'status' => 404 ) );
		}

		$user_id     = get_current_user_id();
		$user_review = EventRatings::get_user_review( $user_id, $event_id );

		return array(
			'event_id'    => $event_id,
			'rateable'    => EventRatings::is_rateable_event( $event_id ),
			'can_rate'    => EventRatings::can_rate( $user_id, $event_id ),
			'summary'     => EventRatings::get_summary( $event_id ),
			'reviews'     => EventRatings::get_reviews( $event_id, (int) ( $input['limit'] ?? 50 ), (int) ( $input['offset'] ?? 0 ) ),
			'user_review' => $user_review ? EventRatings::format_review( $user_review ) : null,
		);
	}

	/**
	 * @param array $input Input.
	 * @return array{ratings: array<int|string,int>|\stdClass}
	 */
	public function executeGetMine( array $input ): array {
		$ids     = array_slice( (array) ( $input['event_ids'] ?? array() ), 0, 100 );
		$ratings = EventRatings::get_user_ratings( get_current_user_id(), $ids );

		$out = array();
		foreach ( $ratings as $event_id => $rating ) {
			$out[ (string) $event_id ] = $rating;
		}

		// An empty map must serialize as {} for clients.
		return array( 'ratings' => empty( $out ) ? new \stdClass() : $out );
	}
}
