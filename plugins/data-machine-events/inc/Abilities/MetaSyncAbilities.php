<?php
/**
 * Meta Sync Abilities
 *
 * Detects events where block attributes exist but post meta sync failed,
 * and provides repair functionality to re-trigger meta sync.
 *
 * Addresses bug from v0.11.1 where events were created with block attributes
 * intact but _datamachine_event_datetime meta never synced.
 *
 * @package DataMachineEvents\Abilities
 * @since 0.11.3
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MetaSyncAbilities {

	private const DEFAULT_LIMIT = 50;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'datamachine-events/find-missing-meta-sync',
				array(
					'label'               => __( 'Find Missing Meta Sync', 'datamachine-events' ),
					'description'         => __( 'Detect events where block has data but meta sync failed', 'datamachine-events' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'limit' => array(
								'type'        => 'integer',
								'description' => 'Max events to return (default: 50)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'count'  => array( 'type' => 'integer' ),
							'events' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'id'        => array( 'type' => 'integer' ),
										'title'     => array( 'type' => 'string' ),
										'date'      => array( 'type' => 'string' ),
										'startTime' => array( 'type' => 'string' ),
										'venue'     => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'execute_callback'    => array( $this, 'executeFindMissingMetaSync' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array( 'show_in_rest' => true ),
				)
			);

			wp_register_ability(
				'datamachine-events/resync-event-meta',
				array(
					'label'               => __( 'Resync Event Meta', 'datamachine-events' ),
					'description'         => __( 'Re-trigger meta sync for specified events', 'datamachine-events' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'event_ids' => array(
								'oneOf'       => array(
									array( 'type' => 'integer' ),
									array(
										'type'  => 'array',
										'items' => array( 'type' => 'integer' ),
									),
								),
								'description' => 'Event ID(s) to resync',
							),
							'dry_run'   => array(
								'type'        => 'boolean',
								'description' => 'Preview without changes (default: false)',
							),
						),
						'required'   => array( 'event_ids' ),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'results' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'id'      => array( 'type' => 'integer' ),
										'title'   => array( 'type' => 'string' ),
										'success' => array( 'type' => 'boolean' ),
										'before'  => array( 'type' => 'object' ),
										'after'   => array( 'type' => 'object' ),
										'error'   => array( 'type' => 'string' ),
									),
								),
							),
							'summary' => array(
								'type'       => 'object',
								'properties' => array(
									'synced' => array( 'type' => 'integer' ),
									'failed' => array( 'type' => 'integer' ),
									'total'  => array( 'type' => 'integer' ),
								),
							),
						),
					),
					'execute_callback'    => array( $this, 'executeResyncEventMeta' ),
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
	 * Find events where block has startDate but meta is missing.
	 *
	 * @param array $input Input parameters with optional 'limit'
	 * @return array Events with missing meta sync
	 */
	public function executeFindMissingMetaSync( array $input ): array {
		$limit = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );

		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		$args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$query         = new \WP_Query( $args );
		$missing_sync  = array();
		$total_missing = 0;

		foreach ( $query->posts as $event ) {
			$block_attrs = $this->extractBlockAttributes( $event->ID );
			$start_date  = $block_attrs['startDate'] ?? '';

			if ( empty( $start_date ) ) {
				continue;
			}

			$meta_datetime = get_post_meta( $event->ID, Event_Post_Type::EVENT_DATE_META_KEY, true );

			if ( empty( $meta_datetime ) ) {
				++$total_missing;

				if ( count( $missing_sync ) < $limit ) {
					$venue_terms = wp_get_post_terms( $event->ID, 'venue', array( 'fields' => 'names' ) );
					$venue_name  = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0] : '';

					$missing_sync[] = array(
						'id'        => $event->ID,
						'title'     => $event->post_title,
						'date'      => $start_date,
						'startTime' => $block_attrs['startTime'] ?? '',
						'venue'     => $venue_name,
					);
				}
			}
		}

		return array(
			'count'  => $total_missing,
			'events' => $missing_sync,
		);
	}

	/**
	 * Re-trigger meta sync for specified events.
	 *
	 * @param array $input Input parameters with 'event_ids' and optional 'dry_run'
	 * @return array Results with per-event success/failure and summary
	 */
	public function executeResyncEventMeta( array $input ): array {
		$event_ids = $input['event_ids'] ?? array();
		$dry_run   = (bool) ( $input['dry_run'] ?? false );

		if ( ! is_array( $event_ids ) ) {
			$event_ids = array( (int) $event_ids );
		}

		$event_ids = array_map( 'absint', $event_ids );
		$event_ids = array_filter( $event_ids );

		if ( empty( $event_ids ) ) {
			return array(
				'results' => array(),
				'summary' => array(
					'synced' => 0,
					'failed' => 0,
					'total'  => 0,
				),
			);
		}

		$results = array();
		$synced  = 0;
		$failed  = 0;

		foreach ( $event_ids as $event_id ) {
			$post = get_post( $event_id );

			if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
				$results[] = array(
					'id'      => $event_id,
					'title'   => '',
					'success' => false,
					'error'   => 'Post not found or not an event',
					'before'  => array(),
					'after'   => array(),
				);
				++$failed;
				continue;
			}

			$block_attrs = $this->extractBlockAttributes( $event_id );
			$start_date  = $block_attrs['startDate'] ?? '';

			if ( empty( $start_date ) ) {
				$results[] = array(
					'id'      => $event_id,
					'title'   => $post->post_title,
					'success' => false,
					'error'   => 'No startDate in block attributes',
					'before'  => array(),
					'after'   => array(),
				);
				++$failed;
				continue;
			}

			$before_datetime   = get_post_meta( $event_id, Event_Post_Type::EVENT_DATE_META_KEY, true );
			$before_end        = get_post_meta( $event_id, '_datamachine_event_end_datetime', true );
			$before_ticket_url = get_post_meta( $event_id, '_datamachine_ticket_url', true );

			$before = array(
				'_datamachine_event_datetime'     => $before_datetime ? $before_datetime : null,
				'_datamachine_event_end_datetime' => $before_end ? $before_end : null,
				'_datamachine_ticket_url'         => $before_ticket_url ? $before_ticket_url : null,
			);

			if ( ! $dry_run ) {
				\DataMachineEvents\Core\datamachine_events_sync_datetime_meta( $event_id, $post, true );
			}

			$after_datetime   = $dry_run ? $this->calculateExpectedDatetime( $block_attrs ) : get_post_meta( $event_id, Event_Post_Type::EVENT_DATE_META_KEY, true );
			$after_end        = $dry_run ? $this->calculateExpectedEndDatetime( $block_attrs ) : get_post_meta( $event_id, '_datamachine_event_end_datetime', true );
			$after_ticket_url = $dry_run ? ( $block_attrs['ticketUrl'] ?? null ) : get_post_meta( $event_id, '_datamachine_ticket_url', true );

			$after = array(
				'_datamachine_event_datetime'     => $after_datetime ? $after_datetime : null,
				'_datamachine_event_end_datetime' => $after_end ? $after_end : null,
				'_datamachine_ticket_url'         => $after_ticket_url ? $after_ticket_url : null,
			);

			$success = ! empty( $after_datetime );

			$results[] = array(
				'id'      => $event_id,
				'title'   => $post->post_title,
				'success' => $success,
				'before'  => $before,
				'after'   => $after,
			);

			if ( $success ) {
				++$synced;
			} else {
				++$failed;
			}
		}

		return array(
			'results' => $results,
			'summary' => array(
				'synced' => $synced,
				'failed' => $failed,
				'total'  => count( $event_ids ),
			),
		);
	}

	/**
	 * Extract Event Details block attributes from post content.
	 *
	 * @param int $post_id Event post ID
	 * @return array Block attributes or empty array
	 */
	private function extractBlockAttributes( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$blocks = parse_blocks( $post->post_content );

		foreach ( $blocks as $block ) {
			if ( 'datamachine-events/event-details' === $block['blockName'] ) {
				return $block['attrs'] ?? array();
			}
		}

		return array();
	}

	/**
	 * Calculate expected datetime value from block attributes.
	 * Used for dry-run preview.
	 *
	 * @param array $attrs Block attributes
	 * @return string Expected datetime value
	 */
	private function calculateExpectedDatetime( array $attrs ): string {
		$start_date = $attrs['startDate'] ?? '';
		$start_time = $attrs['startTime'] ?? '00:00:00';

		if ( empty( $start_date ) ) {
			return '';
		}

		$start_time_parts = explode( ':', $start_time );
		if ( count( $start_time_parts ) === 2 ) {
			$start_time .= ':00';
		}

		return $start_date . ' ' . $start_time;
	}

	/**
	 * Calculate expected end datetime value from block attributes.
	 * Used for dry-run preview.
	 *
	 * @param array $attrs Block attributes
	 * @return string Expected end datetime value
	 */
	private function calculateExpectedEndDatetime( array $attrs ): string {
		$start_date = $attrs['startDate'] ?? '';
		$start_time = $attrs['startTime'] ?? '00:00:00';
		$end_date   = $attrs['endDate'] ?? '';
		$end_time   = $attrs['endTime'] ?? '';

		if ( empty( $start_date ) ) {
			return '';
		}

		$start_time_parts = explode( ':', $start_time );
		if ( count( $start_time_parts ) === 2 ) {
			$start_time .= ':00';
		}

		$end_time_parts = explode( ':', $end_time );
		if ( $end_time && count( $end_time_parts ) === 2 ) {
			$end_time .= ':00';
		}

		if ( $end_date ) {
			$effective_end_time = $end_time ? $end_time : '23:59:59';
			return $end_date . ' ' . $effective_end_time;
		}

		try {
			$start_dt = new \DateTime( $start_date . ' ' . $start_time );
			$start_dt->modify( '+3 hours' );
			return $start_dt->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return $start_date . ' ' . $start_time;
		}
	}
}
