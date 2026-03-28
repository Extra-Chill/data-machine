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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventUpdateAbilities {

	private const BLOCK_NAME = 'data-machine-events/event-details';

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
					'category'            => 'datamachine',
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
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
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
	 * Execute event update.
	 *
	 * @param array $input Input parameters with 'event' or 'events' and fields to update
	 * @return array Update results with status for each event
	 */
	public function executeUpdateEvent( array $input ): array {
		$events_to_update = $this->normalizeInput( $input );

		if ( empty( $events_to_update ) ) {
			return array(
				'error' => 'Either "event" (single post ID) or "events" (array) parameter is required',
			);
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

		if ( ! empty( $event_update['venue'] ) ) {
			$venue_result = $this->updateVenue( $post_id, (int) $event_update['venue'] );
			if ( $venue_result['success'] ) {
				$updated_fields[] = 'venue';
			} else {
				$warnings[] = $venue_result['warning'];
			}
		}

		if ( array_key_exists( 'description', $event_update ) ) {
			$description_value = $event_update['description'] ?? '';
			$inner_blocks      = $this->generateDescriptionInnerBlocks( $description_value );
			$this->updateBlockInnerBlocks( $blocks[ $block_index ], $inner_blocks );
			$updated_fields[] = 'description';
		}

		if ( empty( $updated_fields ) && empty( $warnings ) ) {
			return array(
				'post_id' => $post_id,
				'title'   => $post->post_title,
				'status'  => 'no_change',
				'message' => 'No fields provided to update',
			);
		}

		if ( ! empty( $updated_fields ) && array( 'venue' ) !== $updated_fields ) {
			$blocks[ $block_index ]['attrs'] = $new_attrs;
			$new_content                     = serialize_blocks( $blocks );

			$update_result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				),
				true
			);

			if ( is_wp_error( $update_result ) ) {
				return array(
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'status'  => 'failed',
					'error'   => 'Failed to update post: ' . $update_result->get_error_message(),
				);
			}
		}

		return array(
			'post_id'        => $post_id,
			'title'          => $post->post_title,
			'status'         => 'updated',
			'updated_fields' => $updated_fields,
			'warnings'       => $warnings,
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
					$new_attrs[ $field ] = array_values( array_filter( $value, 'is_string' ) );
					$updated_fields[]    = $field;
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

			$new_attrs[ $field ] = $value;
			$updated_fields[]    = $field;
		}

		return $new_attrs;
	}

	/**
	 * Update venue taxonomy assignment.
	 *
	 * @param int $post_id Event post ID
	 * @param int $venue_id Venue term ID
	 * @return array Result with 'success' and optionally 'warning'
	 */
	private function updateVenue( int $post_id, int $venue_id ): array {
		$term = get_term( $venue_id, 'venue' );

		if ( ! $term || is_wp_error( $term ) ) {
			return array(
				'success' => false,
				'warning' => "Venue ID {$venue_id} not found, skipped venue assignment",
			);
		}

		$result = wp_set_post_terms( $post_id, array( $venue_id ), 'venue' );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'warning' => 'Failed to assign venue: ' . $result->get_error_message(),
			);
		}

		return array( 'success' => true );
	}

	/**
	 * Build summary message for results.
	 *
	 * @param int $updated Number of successfully updated events
	 * @param int $failed Number of failed updates
	 * @return string Human-readable summary
	 */
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
