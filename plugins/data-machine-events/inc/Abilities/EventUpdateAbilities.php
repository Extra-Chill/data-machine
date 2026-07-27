<?php
/**
 * Event Update Abilities
 *
 * Updates event block attributes and venue assignment. Supports single or batch updates.
 * Uses DateTimeParser for flexible datetime input handling.
 *
 * Provides abilities for CLI/REST/MCP consumption.
 * Chat tool wrapper lives in inc/Api/Chat/Tools/UpdateEvent.php.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\DateTimeParser;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventSchemaProvider;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventUpdateAbilities {
	public const SOURCE_ABILITY_NAME = 'data-machine-events/update-source-event';

	private const BLOCK_NAME       = 'data-machine-events/event-details';
	private const UPDATABLE_FIELDS = array(
		'startDate',
		'startTime',
		'endDate',
		'endTime',
		'occurrenceDates',
		'price',
		'priceCurrency',
		'ticketUrl',
		'offerAvailability',
		'validFrom',
		'performer',
		'performerType',
		'organizer',
		'organizerType',
		'organizerUrl',
		'eventStatus',
		'previousStartDate',
		'eventType',
	);

	private static bool $registered = false;

	/** @var array|null Active source-owned transaction context. */
	private $source_context;

	/** @var bool Whether the source-owned transaction is active. */
	private $source_transaction_active = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/update-event',
				array(
					'label'               => __( 'Update Event', 'data-machine-events' ),
					'description'         => __( 'Update event details including dates, times, venue, and metadata', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'event'           => array(
								'type'        => 'integer',
								'description' => 'Single event post ID to update',
							),
							'events'          => array(
								'type'        => 'array',
								'description' => 'Array of event updates. Each item must have "event" (post ID) plus fields to update.',
							),
							'startDate'       => array(
								'type'        => 'string',
								'description' => 'Start date (any parseable format, normalized to YYYY-MM-DD)',
							),
							'startTime'       => array(
								'type'        => 'string',
								'description' => 'Start time (any parseable format like "8pm", "20:00", normalized to HH:MM)',
							),
							'endDate'         => array(
								'type'        => 'string',
								'description' => 'End date (any parseable format, normalized to YYYY-MM-DD)',
							),
							'endTime'         => array(
								'type'        => 'string',
								'description' => 'End time (any parseable format, normalized to HH:MM)',
							),
							'venue'           => array(
								'type'        => 'integer',
								'description' => 'Existing venue term ID to assign',
							),
							'description'     => array(
								'type'        => 'string',
								'description' => 'Event description (HTML allowed)',
							),
							'price'           => array(
								'type'        => 'string',
								'description' => 'Ticket price (e.g., "$25" or "$20 adv / $25 door")',
							),
							'ticketUrl'       => array(
								'type'        => 'string',
								'description' => 'URL to purchase tickets',
							),
							'performer'       => array(
								'type'        => 'string',
								'description' => 'Performer name',
							),
							'performerType'   => array(
								'type'        => 'string',
								'enum'        => array( 'Person', 'PerformingGroup', 'MusicGroup' ),
								'description' => 'Performer type: Person, PerformingGroup, or MusicGroup',
							),
							'eventStatus'     => array(
								'type'        => 'string',
								'enum'        => array( 'EventScheduled', 'EventPostponed', 'EventCancelled', 'EventRescheduled' ),
								'description' => 'Event status',
							),
							'eventType'       => array(
								'type'        => 'string',
								'enum'        => array( 'Event', 'MusicEvent', 'Festival', 'ComedyEvent', 'DanceEvent', 'TheaterEvent', 'SportsEvent', 'ExhibitionEvent' ),
								'description' => 'Event type for Schema.org',
							),
							'occurrenceDates' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Array of specific dates (YYYY-MM-DD) when the event occurs',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'results' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'post_id'        => array( 'type' => 'integer' ),
										'title'          => array( 'type' => 'string' ),
										'status'         => array( 'type' => 'string' ),
										'updated_fields' => array( 'type' => 'array' ),
										'warnings'       => array( 'type' => 'array' ),
										'error'          => array( 'type' => 'string' ),
										'error_code'     => array( 'type' => 'string' ),
										'error_data'     => array( 'type' => 'object' ),
										'error_status'   => array( 'type' => 'integer' ),
									),
								),
							),
							'summary' => array(
								'type'       => 'object',
								'properties' => array(
									'updated' => array( 'type' => 'integer' ),
									'failed'  => array( 'type' => 'integer' ),
									'total'   => array( 'type' => 'integer' ),
								),
							),
							'message' => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeUpdateEvent' ),
					'permission_callback' => AbilityPermissions::canWrite(),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
			wp_register_ability(
				self::SOURCE_ABILITY_NAME,
				array(
					'label'               => __( 'Update Source-Owned Event', 'data-machine-events' ),
					'description'         => __( 'Atomically updates one exact source-owned canonical event.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => $this->getSourceInputSchema(),
					'output_schema'       => $this->getSourceOutputSchema(),
					'execute_callback'    => array( $this, 'executeUpdateSourceEvent' ),
					'permission_callback' => array( $this, 'canUpdateSourceEvent' ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
						'annotations'  => array(
							'readonly'    => false,
							'destructive' => true,
							'idempotent'  => true,
						),
					),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Check the narrow source-owned update boundary.
	 *
	 * Consumers grant only source identities they own. The execute callback
	 * repeats this check so direct WP_Ability execution cannot bypass it.
	 */
	public function canUpdateSourceEvent( array $input = array() ): bool {
		/**
		 * Filter permission for one exact source-owned canonical event update.
		 *
		 * @param bool  $allowed Whether this operation is allowed. Default false.
		 * @param array $input   Validated source-event update input.
		 */
		return (bool) apply_filters( 'datamachine_events_update_source_event_permission', false, $input );
	}

	/** Atomically update one event after exact source and fingerprint verification. */
	public function executeUpdateSourceEvent( array $input ): array|\WP_Error {
		if ( ! $this->canUpdateSourceEvent( $input ) ) {
			return new \WP_Error(
				'source_event_update_forbidden',
				'You are not authorized to update this source-owned event.',
				array(
					'status'    => 403,
					'retryable' => false,
				)
			);
		}

		$verified = $this->verifySourceInput( $input );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$update = array( 'event' => $verified['event_id'] );
		foreach ( array_merge( self::UPDATABLE_FIELDS, array( 'venue', 'description' ) ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$update[ $field ] = $input[ $field ];
			}
		}

		$this->source_context = array(
			'event_id'             => $verified['event_id'],
			'source'               => $verified['source'],
			'source_id'            => $verified['source_id'],
			'source_identity'      => $verified['source_identity'],
			'expected_fingerprint' => $verified['fingerprint'],
		);
		try {
			$result = $this->updateSingleEvent( $update );
		} catch ( \Throwable $throwable ) {
			$error = new \WP_Error(
				'source_event_update_exception',
				'The source-owned event update failed unexpectedly.',
				array(
					'status'    => 500,
					'retryable' => true,
					'cause'     => get_class( $throwable ),
				)
			);
			if ( $this->source_transaction_active ) {
				$rollback = $this->rollbackSourceTransaction( $verified['event_id'] );
				if ( is_wp_error( $rollback ) ) {
					$error = $rollback;
				}
			}
			$post   = get_post( $verified['event_id'] );
			$result = $post instanceof \WP_Post ? $this->updateErrorResult( $post, $error ) : array(
				'status'       => 'failed',
				'error'        => $error->get_error_message(),
				'error_code'   => $error->get_error_code(),
				'error_status' => (int) ( $error->get_error_data()['status'] ?? 500 ),
				'error_data'   => (array) $error->get_error_data(),
			);
		} finally {
			if ( $this->source_transaction_active ) {
				$this->rollbackSourceTransaction( $verified['event_id'] );
			}
			$this->source_context = null;
		}

		if ( 'failed' === ( $result['status'] ?? '' ) ) {
			$data              = (array) ( $result['error_data'] ?? array() );
			$data['status']    = (int) ( $result['error_status'] ?? $data['status'] ?? 500 );
			$data['retryable'] = ! empty( $data['retryable'] ) || $data['status'] >= 500;
			if ( ! isset( $data['fingerprint'] ) ) {
				$current = $this->eventFingerprint( $verified['event_id'], $verified['source'], $verified['source_id'], $verified['source_identity'] );
				if ( ! is_wp_error( $current ) ) {
					$data['fingerprint'] = $current;
				}
			}
			return new \WP_Error( (string) ( $result['error_code'] ?? 'source_event_update_failed' ), (string) ( $result['error'] ?? 'Source-owned event update failed.' ), $data );
		}

		$fingerprint = $this->eventFingerprint( $verified['event_id'], $verified['source'], $verified['source_id'], $verified['source_identity'] );
		if ( is_wp_error( $fingerprint ) ) {
			return $fingerprint;
		}

		return array(
			'success'              => true,
			'event_id'             => $verified['event_id'],
			'action'               => 'no_change' === ( $result['status'] ?? '' ) ? 'no_change' : 'updated',
			'previous_fingerprint' => $verified['fingerprint'],
			'fingerprint'          => $fingerprint,
			'updated_fields'       => array_values( (array) ( $result['updated_fields'] ?? array() ) ),
		);
	}

	/**
	 * Execute event update.
	 *
	 * @param array $input Input parameters with 'event' or 'events' and fields to update
	 * @return array|\WP_Error Update results with status for each event
	 */
	public function executeUpdateEvent( array $input ): array|\WP_Error {
		$events_to_update = $this->normalizeInput( $input );

		if ( empty( $events_to_update ) ) {
			return new \WP_Error( 'missing_event', 'Either "event" (single post ID) or "events" (array) parameter is required', array( 'status' => 400 ) );
		}

		$results       = array();
		$updated_count = 0;
		$failed_count  = 0;

		foreach ( $events_to_update as $event_update ) {
			$result    = $this->updateSingleEvent( $event_update );
			$results[] = $result;

			if ( 'updated' === $result['status'] ) {
				++$updated_count;
			} else {
				++$failed_count;
			}
		}

		$total   = count( $events_to_update );
		$message = $this->buildSummaryMessage( $updated_count, $failed_count );

		return array(
			'results' => $results,
			'summary' => array(
				'updated' => $updated_count,
				'failed'  => $failed_count,
				'total'   => $total,
			),
			'message' => $message,
		);
	}

	/**
	 * Normalize input to array of event updates.
	 *
	 * @param array $parameters Raw parameters
	 * @return array Array of event update arrays
	 */
	private function normalizeInput( array $parameters ): array {
		if ( ! empty( $parameters['events'] ) && is_array( $parameters['events'] ) ) {
			return $parameters['events'];
		}

		if ( ! empty( $parameters['event'] ) ) {
			$single_update = array( 'event' => (int) $parameters['event'] );

			foreach ( self::UPDATABLE_FIELDS as $field ) {
				if ( array_key_exists( $field, $parameters ) ) {
					$single_update[ $field ] = $parameters[ $field ];
				}
			}

			if ( array_key_exists( 'venue', $parameters ) ) {
				$single_update['venue'] = (int) $parameters['venue'];
			}

			if ( array_key_exists( 'description', $parameters ) ) {
				$single_update['description'] = $parameters['description'];
			}

			return array( $single_update );
		}

		return array();
	}

	/**
	 * Update a single event.
	 *
	 * @param array $event_update Event update data with 'event' key for post ID
	 * @return array Result with status, updated_fields, warnings, etc.
	 */
	private function updateSingleEvent( array $event_update ): array {
		$post_id = (int) ( $event_update['event'] ?? 0 );

		if ( $post_id <= 0 ) {
			return array(
				'event'  => $event_update['event'] ?? null,
				'status' => 'failed',
				'error'  => 'Invalid or missing event post ID',
			);
		}

		$post = get_post( $post_id );
		if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return array(
				'event'  => $post_id,
				'status' => 'failed',
				'error'  => 'Event not found or invalid post type',
			);
		}

		$venue_requested = array_key_exists( 'venue', $event_update );
		if ( ! is_array( $this->source_context ) && $venue_requested && count( $event_update ) > 2 ) {
			return $this->updateErrorResult(
				$post,
				new \WP_Error(
					'event_update_mixed_venue_content_unsupported',
					'Combined venue and event-detail updates require the source-owned atomic update ability.',
					array( 'status' => 409 )
				)
			);
		}

		$updated_fields = array();
		$warnings       = array();

		$blocks      = parse_blocks( $post->post_content );
		$block_index = $this->findEventBlockIndex( $blocks );

		if ( null === $block_index ) {
			return array(
				'post_id' => $post_id,
				'title'   => $post->post_title,
				'status'  => 'failed',
				'error'   => 'Event details block not found in post content',
			);
		}

		$existing_attrs = $blocks[ $block_index ]['attrs'] ?? array();
		$new_attrs      = $this->buildUpdatedAttributes( $existing_attrs, $event_update, $updated_fields );

		if ( array_key_exists( 'description', $event_update ) ) {
			$description_value = $event_update['description'] ?? '';
			$inner_blocks      = $this->generateDescriptionInnerBlocks( $description_value );
			if ( ( $blocks[ $block_index ]['innerBlocks'] ?? array() ) !== $inner_blocks ) {
				$this->updateBlockInnerBlocks( $blocks[ $block_index ], $inner_blocks );
				$updated_fields[] = 'description';
			}
		}

		$requested_venue_id = $venue_requested ? absint( $event_update['venue'] ) : 0;
		$previous_venue_ids = wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $previous_venue_ids ) ) {
			return $this->updateErrorResult(
				$post,
				new \WP_Error(
					'event_venue_read_failed',
					'The existing event venue could not be read safely.',
					array(
						'status'         => 503,
						'database_error' => $previous_venue_ids->get_error_message(),
						'cause'          => $previous_venue_ids->get_error_code(),
					)
				)
			);
		}
		$previous_venue_ids   = array_values( array_unique( array_filter( array_map( 'absint', $previous_venue_ids ) ) ) );
		$next_venue_id        = 1 === count( $previous_venue_ids ) ? (int) reset( $previous_venue_ids ) : 0;
		$requested_venue_term = $venue_requested ? get_term( $requested_venue_id, 'venue' ) : null;
		if ( $venue_requested && $requested_venue_term && ! is_wp_error( $requested_venue_term ) ) {
			$next_venue_id = $requested_venue_id;
		}
		$venue_changed = $venue_requested && array( $requested_venue_id ) !== $previous_venue_ids;

		if ( empty( $updated_fields ) && ! $venue_changed && ! is_array( $this->source_context ) ) {
			return array(
				'post_id' => $post_id,
				'title'   => $post->post_title,
				'status'  => 'no_change',
				'message' => 'No fields provided to update',
			);
		}

		$context          = array(
			'invocation_id'      => wp_generate_uuid4(),
			'post_id'            => $post_id,
			'post_status'        => (string) $post->post_status,
			'event'              => $new_attrs,
			'next_venue_id'      => $next_venue_id,
			'previous_venue_ids' => $previous_venue_ids,
		);
		$lifecycle_result = null;

		try {
			$preflight = apply_filters( 'datamachine_events_before_event_update_persistence', true, $context );
			if ( false === $preflight ) {
				$preflight = new \WP_Error( 'event_update_persistence_denied', 'Event update persistence was denied.', array( 'status' => 403 ) );
			}
			if ( is_wp_error( $preflight ) ) {
				$lifecycle_result = $this->updateErrorResult( $post, $preflight );
				return $lifecycle_result;
			}
			if ( is_array( $this->source_context ) ) {
				$transaction = $this->beginSourceTransaction( $post_id );
				if ( is_wp_error( $transaction ) ) {
					$lifecycle_result = $this->updateErrorResult( $post, $transaction );
					return $lifecycle_result;
				}
			}

			if ( $venue_changed ) {
				$venue_result = $this->updateVenue( $post_id, $requested_venue_id );
				if ( ! $venue_result['success'] ) {
					$error            = $venue_result['error'] ?? new \WP_Error( 'event_venue_assignment_failed', (string) ( $venue_result['warning'] ?? 'Failed to assign venue.' ) );
					$lifecycle_result = $this->updateErrorResult( $post, $error );
					return $this->rollbackSourceResult( $post_id, $lifecycle_result );
				}
				$updated_fields[] = 'venue';
			}

			if ( ! empty( array_diff( $updated_fields, array( 'venue' ) ) ) ) {
				$blocks[ $block_index ]['attrs'] = $new_attrs;
				$new_content                     = serialize_blocks( $blocks );
				$dates_error                     = null;
				$capture_dates_error             = static function ( \WP_Error $error, int $failed_post_id ) use ( &$dates_error, $post_id ): void {
					if ( $post_id === $failed_post_id ) {
						$dates_error = $error;
					}
				};
				add_action( 'datamachine_event_dates_sync_failed', $capture_dates_error, 10, 2 );
				try {
					$update_result = wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => $new_content,
						),
						true
					);
				} finally {
					remove_action( 'datamachine_event_dates_sync_failed', $capture_dates_error, 10 );
				}

				if ( is_wp_error( $update_result ) ) {
					$lifecycle_result = $this->updateErrorResult( $post, $update_result, 'Failed to update post: ' );
					return $this->rollbackSourceResult( $post_id, $lifecycle_result );
				}
				if ( $dates_error instanceof \WP_Error ) {
					$lifecycle_result = $this->updateErrorResult( $post, $dates_error );
					return $this->rollbackSourceResult( $post_id, $lifecycle_result );
				}
			}

			$lifecycle_result = array(
				'post_id'        => $post_id,
				'title'          => $post->post_title,
				'status'         => empty( $updated_fields ) ? 'no_change' : 'updated',
				'updated_fields' => $updated_fields,
				'warnings'       => $warnings,
			);
			if ( $this->source_transaction_active ) {
				$committed = $this->commitSourceTransaction( $post_id );
				if ( is_wp_error( $committed ) ) {
					$lifecycle_result = $this->updateErrorResult( $post, $committed );
				}
			}
			return $lifecycle_result;
		} finally {
			do_action( 'datamachine_events_after_event_update_persistence', $context, $lifecycle_result );
		}
	}

	/** Build one structured failed update item without discarding error status. */
	private function updateErrorResult( \WP_Post $post, \WP_Error $error, string $prefix = '' ): array {
		$data           = $error->get_error_data();
		$data           = is_array( $data ) ? $data : array();
		$status         = (int) ( $data['status'] ?? 500 );
		$data['status'] = $status;

		return array(
			'post_id'      => (int) $post->ID,
			'title'        => $post->post_title,
			'status'       => 'failed',
			'error'        => $prefix . $error->get_error_message(),
			'error_code'   => $error->get_error_code(),
			'error_data'   => $data,
			'error_status' => $status,
		);
	}

	/**
	 * Find the index of the event details block.
	 *
	 * @param array $blocks Parsed blocks
	 * @return int|null Block index or null if not found
	 */
	private function findEventBlockIndex( array $blocks ): ?int {
		foreach ( $blocks as $index => $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Build updated attributes by merging new values into existing.
	 *
	 * @param array $existing_attrs Current block attributes
	 * @param array $event_update Update data
	 * @param array &$updated_fields Reference to track which fields were updated
	 * @return array Merged attributes
	 */
	private function buildUpdatedAttributes( array $existing_attrs, array $event_update, array &$updated_fields ): array {
		$new_attrs = $existing_attrs;

		foreach ( self::UPDATABLE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $event_update ) ) {
				continue;
			}

			$value = $event_update[ $field ];

			// Handle array fields (like occurrenceDates)
			if ( 'occurrenceDates' === $field ) {
				if ( is_array( $value ) ) {
					$value = array_values( array_filter( $value, 'is_string' ) );
					if ( ( $existing_attrs[ $field ] ?? array() ) !== $value ) {
						$new_attrs[ $field ] = $value;
						$updated_fields[]    = $field;
					}
				}
				continue;
			}

			if ( in_array( $field, array( 'startDate', 'endDate' ), true ) ) {
				$parsed = DateTimeParser::parse( $value );
				if ( ! empty( $parsed['date'] ) ) {
					$value = $parsed['date'];
				}
			}

			if ( in_array( $field, array( 'startTime', 'endTime' ), true ) ) {
				$parsed = DateTimeParser::parse( "2000-01-01 {$value}" );
				if ( ! empty( $parsed['time'] ) ) {
					$value = $parsed['time'];
				}
			}

			if ( 'description' === $field ) {
				$value = wp_kses_post( $value );
			}

			if ( 'ticketUrl' === $field ) {
				$value = esc_url_raw( $value );
			}

			if ( 'performerType' === $field && ! in_array( $value, EventSchemaProvider::PERFORMER_TYPES, true ) ) {
				continue;
			}

			if ( 'eventStatus' === $field && ! in_array( $value, EventSchemaProvider::EVENT_STATUSES, true ) ) {
				continue;
			}

			if ( 'eventType' === $field && ! in_array( $value, EventSchemaProvider::EVENT_TYPES, true ) ) {
				continue;
			}

			if ( ( $existing_attrs[ $field ] ?? null ) !== $value ) {
				$new_attrs[ $field ] = $value;
				$updated_fields[]    = $field;
			}
		}

		return $new_attrs;
	}

	/**
	 * Update venue taxonomy assignment.
	 *
	 * This lifecycle covers direct venue changes owned by the event update
	 * ability. Upsert venue reconciliation remains covered by EventUpsert's
	 * broader permission and completion lifecycle and does not duplicate these hooks.
	 *
	 * @param int $post_id Event post ID
	 * @param int $venue_id Venue term ID
	 * @return array Result with 'success' and optionally 'warning'
	 */
	private function updateVenue( int $post_id, int $venue_id ): array {
		$term = get_term( $venue_id, 'venue' );

		if ( ! $term || is_wp_error( $term ) ) {
			$error = new \WP_Error(
				'event_venue_assignment_failed',
				"Venue ID {$venue_id} was not found.",
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
			return array(
				'success' => false,
				'warning' => $error->get_error_message(),
				'error'   => $error,
			);
		}

		$context            = 'event_update_ability';
		$next_venue_ids     = array( absint( $venue_id ) );
		$previous_venue_ids = wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );
		$previous_venue_ids = is_wp_error( $previous_venue_ids )
			? array()
			: array_values( array_unique( array_filter( array_map( 'absint', $previous_venue_ids ) ) ) );
		$result             = null;

		try {
			/**
			 * Filters whether a direct event venue mutation may proceed.
			 *
			 * @param bool|\WP_Error $preflight         Permission result.
			 * @param int            $post_id           Event post ID.
			 * @param int[]          $next_venue_ids    Venue term IDs to assign.
			 * @param int[]          $previous_venue_ids Previously assigned venue term IDs.
			 * @param string         $context           Mutation context.
			 */
			$preflight = apply_filters(
				'datamachine_events_before_event_venue_mutation',
				true,
				$post_id,
				$next_venue_ids,
				$previous_venue_ids,
				$context
			);
			if ( false === $preflight ) {
				$preflight = new \WP_Error( 'event_venue_mutation_denied', 'Venue mutation was denied.', array( 'status' => 403 ) );
			}

			if ( is_wp_error( $preflight ) ) {
				$result = $preflight;

				return array(
					'success' => false,
					'warning' => 'Failed to assign venue: ' . $preflight->get_error_message(),
					'error'   => $preflight,
				);
			}

			$result = wp_set_post_terms( $post_id, $next_venue_ids, 'venue' );

			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'warning' => 'Failed to assign venue: ' . $result->get_error_message(),
					'error'   => $result,
				);
			}
			$result = array_values( array_unique( array_filter( array_map( 'absint', $result ) ) ) );

			return array( 'success' => true );
		} finally {
			/**
			 * Fires after a direct event venue mutation attempt.
			 *
			 * @param int             $post_id           Event post ID.
			 * @param int[]           $next_venue_ids     Requested venue term IDs.
			 * @param int[]           $previous_venue_ids Previously assigned venue term IDs.
			 * @param string          $context            Mutation context.
			 * @param int[]|\WP_Error|null $result         Canonical assigned term-taxonomy IDs, an error, or null.
			 */
			do_action(
				'datamachine_events_after_event_venue_mutation',
				$post_id,
				$next_venue_ids,
				$previous_venue_ids,
				$context,
				$result
			);
		}
	}

	/** Verify immutable source ownership and the caller's exact observed state. */
	private function verifySourceInput( array $input ) {
		$event_id    = absint( $input['event'] ?? 0 );
		$source      = trim( (string) ( $input['source'] ?? '' ) );
		$source_id   = trim( (string) ( $input['source_id'] ?? '' ) );
		$identity    = strtolower( trim( (string) ( $input['source_identity'] ?? '' ) ) );
		$fingerprint = strtolower( trim( (string) ( $input['expected_fingerprint'] ?? '' ) ) );
		$post        = get_post( $event_id );

		if ( $event_id < 1 || ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'source_event_not_found',
				'The source-owned event was not found.',
				array(
					'status'    => 404,
					'retryable' => false,
				)
			);
		}
		if ( '' === $source || '' === $source_id || ! preg_match( '/^[a-f0-9]{64}$/', $identity ) || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return new \WP_Error(
				'source_event_update_input_invalid',
				'Source, source_id, source_identity, and a SHA-256 expected fingerprint are required.',
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
		}
		foreach ( array( 'startDate', 'endDate' ) as $field ) {
			if ( isset( $input[ $field ] ) && '' !== (string) $input[ $field ] && empty( DateTimeParser::parse( (string) $input[ $field ] )['date'] ) ) {
				return new \WP_Error( 'source_event_update_input_invalid', "{$field} is not a valid date.", array(
					'status'    => 400,
					'retryable' => false,
				) );
			}
		}
		foreach ( array( 'startTime', 'endTime' ) as $field ) {
			if ( isset( $input[ $field ] ) && '' !== (string) $input[ $field ] && empty( DateTimeParser::parse( '2000-01-01 ' . (string) $input[ $field ] )['time'] ) ) {
				return new \WP_Error( 'source_event_update_input_invalid', "{$field} is not a valid time.", array(
					'status'    => 400,
					'retryable' => false,
				) );
			}
		}
		foreach ( (array) ( $input['occurrenceDates'] ?? array() ) as $date ) {
			if ( ! is_string( $date ) || empty( DateTimeParser::parse( $date )['date'] ) ) {
				return new \WP_Error( 'source_event_update_input_invalid', 'occurrenceDates contains an invalid date.', array(
					'status'    => 400,
					'retryable' => false,
				) );
			}
		}
		if ( ! hash_equals( hash( 'sha256', $source . "\0" . $source_id ), $identity )
			|| get_post_meta( $event_id, EventUpsert::SOURCE_NAME_META_KEY, true ) !== $source
			|| get_post_meta( $event_id, EventUpsert::SOURCE_ID_META_KEY, true ) !== $source_id
			|| get_post_meta( $event_id, EventUpsert::SOURCE_IDENTITY_META_KEY, true ) !== $identity ) {
			return new \WP_Error(
				'source_event_identity_mismatch',
				'The event does not belong to the supplied source identity.',
				array(
					'status'    => 409,
					'retryable' => false,
				)
			);
		}
		$current = $this->eventFingerprint( $event_id, $source, $source_id, $identity );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! hash_equals( $current, $fingerprint ) ) {
			return new \WP_Error( 'source_event_fingerprint_conflict', 'The event changed since the caller observed it.', array(
				'status'      => 409,
				'retryable'   => false,
				'fingerprint' => $current,
			) );
		}

		return array(
			'event_id'        => $event_id,
			'source'          => $source,
			'source_id'       => $source_id,
			'source_identity' => $identity,
			'fingerprint'     => $current,
		);
	}

	/** Begin the transaction after publication preflight has acquired its lock. */
	private function beginSourceTransaction( int $post_id ) {
		global $wpdb;
		if ( false === $this->transactionQuery( 'START TRANSACTION' ) ) {
			return $this->sourceTransactionError( 'source_event_transaction_start_failed', 'The atomic event update could not start.' );
		}
		$this->source_transaction_active = true;
		$locked                          = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact event transaction lock.
		if ( $post_id !== (int) $locked || '' !== (string) $wpdb->last_error ) {
			$rollback = $this->rollbackSourceTransaction( $post_id );
			return is_wp_error( $rollback ) ? $rollback : $this->sourceTransactionError( 'source_event_lock_failed', 'The source-owned event could not be locked.' );
		}
		$wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock source identity metadata and manual event metadata until commit.
		$wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE object_id = %d FOR UPDATE", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock current venue relationship until commit.
		if ( '' !== (string) $wpdb->last_error ) {
			$rollback = $this->rollbackSourceTransaction( $post_id );
			return is_wp_error( $rollback ) ? $rollback : $this->sourceTransactionError( 'source_event_lock_failed', 'The source-owned event state could not be locked.' );
		}
		clean_post_cache( $post_id );
		$verified = $this->verifySourceInput(
			array(
				'event'                => $post_id,
				'source'               => $this->source_context['source'],
				'source_id'            => $this->source_context['source_id'],
				'source_identity'      => $this->source_context['source_identity'],
				'expected_fingerprint' => $this->source_context['expected_fingerprint'],
			)
		);
		if ( is_wp_error( $verified ) ) {
			$rollback = $this->rollbackSourceTransaction( $post_id );
			return is_wp_error( $rollback ) ? $rollback : $verified;
		}
		return true;
	}

	/** Build a stable retryable database-control failure. */
	private function sourceTransactionError( string $code, string $message ): \WP_Error {
		global $wpdb;
		return new \WP_Error(
			$code,
			$message,
			array(
				'status'         => 503,
				'retryable'      => true,
				'database_error' => $wpdb->last_error,
			)
		);
	}

	/** Commit without guessing after an uncertain database result. */
	private function commitSourceTransaction( int $post_id ) {
		global $wpdb;
		$result                          = $this->transactionQuery( 'COMMIT' );
		$this->source_transaction_active = false;
		clean_post_cache( $post_id );
		if ( false !== $result ) {
			return true;
		}
		$quarantine = $this->quarantineSourceConnection( $post_id );
		return new \WP_Error(
			'source_event_commit_uncertain',
			'The atomic event update outcome could not be confirmed; read the event fingerprint before retrying.',
			array(
				'status'               => 503,
				'retryable'            => true,
				'database_error'       => $wpdb->last_error,
				'connection_closed'    => $quarantine['closed'],
				'connection_recovered' => $quarantine['recovered'],
			)
		);
	}

	/** Roll back one active source update and invalidate rolled-back caches. */
	private function rollbackSourceTransaction( int $post_id ) {
		global $wpdb;
		if ( $this->source_transaction_active ) {
			$result                          = $this->transactionQuery( 'ROLLBACK' );
			$this->source_transaction_active = false;
			if ( false === $result ) {
				$quarantine = $this->quarantineSourceConnection( $post_id );
				return new \WP_Error(
					'source_event_rollback_uncertain',
					'The database did not confirm the atomic event rollback; read the event fingerprint before retrying.',
					array(
						'status'               => 503,
						'retryable'            => true,
						'database_error'       => $wpdb->last_error,
						'connection_closed'    => $quarantine['closed'],
						'connection_recovered' => $quarantine['recovered'],
					)
				);
			}
		}
		clean_post_cache( $post_id );
		clean_object_term_cache( $post_id, Event_Post_Type::POST_TYPE );
		return true;
	}

	/** Roll back before returning an existing structured update failure. */
	private function rollbackSourceResult( int $post_id, array $result ): array {
		$rollback = $this->rollbackSourceTransaction( $post_id );
		if ( ! is_wp_error( $rollback ) ) {
			return $result;
		}
		$post = get_post( $post_id );
		return $post ? $this->updateErrorResult( $post, $rollback ) : $result;
	}

	/** Execute transaction control SQL through an overridable test seam. */
	protected function transactionQuery( string $sql ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL -- Table name from $wpdb->prefix, not user input.
		return $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/** Close an uncertain database session before attempting a fresh connection. */
	private function quarantineSourceConnection( int $post_id ): array {
		global $wpdb;
		$closed    = empty( $wpdb->dbh ) || ( is_callable( array( $wpdb, 'close' ) ) && true === $wpdb->close() );
		$recovered = $closed && true === $wpdb->check_connection( false );
		if ( $recovered ) {
			clean_post_cache( $post_id );
			clean_object_term_cache( $post_id, Event_Post_Type::POST_TYPE );
		}
		return array(
			'closed'    => $closed,
			'recovered' => $recovered,
		);
	}

	/** Build a stable fingerprint for all persisted event and source ownership state. */
	public static function fingerprintForEvent( int $event_id, string $source, string $source_id, string $source_identity = '' ) {
		$post = get_post( $event_id );
		if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
			return new \WP_Error(
				'source_event_not_found',
				'The source-owned event was not found.',
				array(
					'status'    => 404,
					'retryable' => false,
				)
			);
		}
		$stored_source   = (string) get_post_meta( $event_id, EventUpsert::SOURCE_NAME_META_KEY, true );
		$stored_id       = (string) get_post_meta( $event_id, EventUpsert::SOURCE_ID_META_KEY, true );
		$stored_identity = (string) get_post_meta( $event_id, EventUpsert::SOURCE_IDENTITY_META_KEY, true );
		$source_identity = '' === $source_identity ? hash( 'sha256', $source . "\0" . $source_id ) : $source_identity;
		if ( $stored_source !== $source || $stored_id !== $source_id || ! hash_equals( $stored_identity, $source_identity ) ) {
			return new \WP_Error(
				'source_event_identity_mismatch',
				'The event does not belong to the supplied source identity.',
				array(
					'status'    => 409,
					'retryable' => false,
				)
			);
		}
		$venues = wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $venues ) ) {
			return new \WP_Error( 'source_event_venue_read_failed', 'The event venue could not be fingerprinted.', array(
				'status'    => 503,
				'retryable' => true,
				'cause'     => $venues->get_error_code(),
			) );
		}
		$venues = array_values( array_unique( array_map( 'absint', (array) $venues ) ) );
		sort( $venues, SORT_NUMERIC );
		$payload = wp_json_encode(
			array(
				'event_id'        => $event_id,
				'post_status'     => $post->post_status,
				'post_title'      => $post->post_title,
				'post_content'    => $post->post_content,
				'venue_ids'       => $venues,
				'source'          => $stored_source,
				'source_id'       => $stored_id,
				'source_identity' => $stored_identity,
			)
		);
		return false === $payload ? new \WP_Error( 'source_event_fingerprint_failed', 'The event fingerprint could not be encoded.', array(
			'status'    => 500,
			'retryable' => true,
		) ) : hash( 'sha256', $payload );
	}

	/** Instance wrapper for the public fingerprint helper. */
	private function eventFingerprint( int $event_id, string $source, string $source_id, string $source_identity ) {
		return self::fingerprintForEvent( $event_id, $source, $source_id, $source_identity );
	}

	/** Return the strict source-owned update input contract. */
	private function getSourceInputSchema(): array {
		$string = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'event'                => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'source'               => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'source_id'            => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'source_identity'      => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'expected_fingerprint' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'startDate'            => $string,
				'startTime'            => $string,
				'endDate'              => $string,
				'endTime'              => $string,
				'occurrenceDates'      => array(
					'type'  => 'array',
					'items' => $string,
				),
				'venue'                => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'description'          => $string,
				'price'                => $string,
				'priceCurrency'        => $string,
				'ticketUrl'            => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'offerAvailability'    => $string,
				'validFrom'            => $string,
				'performer'            => $string,
				'performerType'        => array(
					'type' => 'string',
					'enum' => array( 'Person', 'PerformingGroup', 'MusicGroup' ),
				),
				'organizer'            => $string,
				'organizerType'        => $string,
				'organizerUrl'         => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'eventStatus'          => array(
					'type' => 'string',
					'enum' => array( 'EventScheduled', 'EventPostponed', 'EventCancelled', 'EventRescheduled' ),
				),
				'previousStartDate'    => $string,
				'eventType'            => array(
					'type' => 'string',
					'enum' => array( 'Event', 'MusicEvent', 'Festival', 'ComedyEvent', 'DanceEvent', 'TheaterEvent', 'SportsEvent', 'ExhibitionEvent' ),
				),
			),
			'required'             => array( 'event', 'source', 'source_id', 'source_identity', 'expected_fingerprint' ),
			'minProperties'        => 6,
			'additionalProperties' => false,
		);
	}

	/** Return the stable source-owned update result contract. */
	private function getSourceOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'success'              => array( 'type' => 'boolean' ),
				'event_id'             => array( 'type' => 'integer' ),
				'action'               => array(
					'type' => 'string',
					'enum' => array( 'updated', 'no_change' ),
				),
				'previous_fingerprint' => array( 'type' => 'string' ),
				'fingerprint'          => array( 'type' => 'string' ),
				'updated_fields'       => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
					'maxItems' => 30,
				),
			),
			'required'             => array( 'success', 'event_id', 'action', 'previous_fingerprint', 'fingerprint', 'updated_fields' ),
			'additionalProperties' => false,
		);
	}

	private function buildSummaryMessage( int $updated, int $failed ): string {
		$parts = array();

		if ( $updated > 0 ) {
			$parts[] = "Updated {$updated} event" . ( 1 !== $updated ? 's' : '' );
		}

		if ( $failed > 0 ) {
			$parts[] = "{$failed} failed";
		}

		if ( empty( $parts ) ) {
			return 'No events processed';
		}

		return implode( ', ', $parts );
	}

	/**
	 * Generate paragraph InnerBlocks from HTML description.
	 *
	 * Descriptions are stored as core/paragraph InnerBlocks inside the
	 * event-details block, not as a block attribute.
	 *
	 * @param string $description HTML description content
	 * @return array Array of paragraph block structures
	 */
	private function generateDescriptionInnerBlocks( string $description ): array {
		if ( empty( $description ) ) {
			return array();
		}

		$description = wp_kses_post( $description );
		$paragraphs  = preg_split( '/<\/p>\s*<p[^>]*>|<\/p>\s*<p>|\n\n+/', $description );

		$blocks = array();
		foreach ( $paragraphs as $para ) {
			$para = preg_replace( '/^<p[^>]*>|<\/p>$/', '', trim( $para ) );
			$para = trim( $para );

			if ( ! empty( $para ) ) {
				$html     = '<p>' . $para . '</p>';
				$blocks[] = array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => $html,
					'innerContent' => array( $html ),
				);
			}
		}

		return $blocks;
	}

	/**
	 * Update block InnerBlocks with new paragraph content.
	 *
	 * @param array $block The event-details block (by reference)
	 * @param array $inner_blocks New paragraph blocks to set
	 */
	private function updateBlockInnerBlocks( array &$block, array $inner_blocks ): void {
		$block['innerBlocks'] = $inner_blocks;

		$inner_content = array( '<div class="wp-block-data-machine-events-event-details">' );
		foreach ( $inner_blocks as $_ ) {
			$inner_content[] = null;
		}
		$inner_content[] = '</div>';

		$block['innerContent'] = $inner_content;
		$block['innerHTML']    = '<div class="wp-block-data-machine-events-event-details"></div>';
	}
}
