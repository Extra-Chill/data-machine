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
				'data-machine-events/find-missing-meta-sync',
				array(
					'label'               => __( 'Find Missing Meta Sync', 'data-machine-events' ),
					'description'         => __( 'Detect events where block has data but meta sync failed', 'data-machine-events' ),
					'category'            => 'datamachine-events/events',
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
				'data-machine-events/resync-event-meta',
				array(
					'label'               => __( 'Resync Event Meta', 'data-machine-events' ),
					'description'         => __( 'Re-trigger meta sync for specified events', 'data-machine-events' ),
					'category'            => 'datamachine-events/events',
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
	 * Find events where the event_dates table has no row (meta sync failed).
	 *
	 * Uses a direct SQL query against the event_dates table instead of loading
	 * all 45K+ events into memory. Identifies events with missing date rows
	 * and cross-references block content for startDate validation.
	 *
	 * @param array $input Input parameters with optional 'limit'
	 * @return array Events with missing meta sync
	 */
	public function executeFindMissingMetaSync( array $input ): array {
		$limit = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );

		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		// Find events missing from the event_dates table via LEFT JOIN.
		// This avoids loading all 45K+ events as WP_Post objects.
		global $wpdb;
		$ed_table = \DataMachineEvents\Core\EventDatesTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidate_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$ed_table} ed ON p.ID = ed.post_id
				WHERE p.post_type = %s
				AND p.post_status = 'publish'
				AND ed.post_id IS NULL
				ORDER BY p.post_date DESC
				LIMIT %d",
				Event_Post_Type::POST_TYPE,
				$limit * 10 // Over-fetch to account for events without block attributes.
			)
		);

		if ( empty( $candidate_ids ) ) {
			return array(
				'count'  => 0,
				'events' => array(),
			);
		}

		// Get total count of events missing from event_dates table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_missing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				LEFT JOIN {$ed_table} ed ON p.ID = ed.post_id
				WHERE p.post_type = %s
				AND p.post_status = 'publish'
				AND ed.post_id IS NULL",
				Event_Post_Type::POST_TYPE
			)
		);

		// Filter candidates to those with block attributes containing startDate.
		$missing_sync = array();
		foreach ( $candidate_ids as $post_id ) {
			if ( count( $missing_sync ) >= $limit ) {
				break;
			}

			$block_attrs = $this->extractBlockAttributes( (int) $post_id );
			$start_date  = $block_attrs['startDate'] ?? '';

			if ( empty( $start_date ) ) {
				continue;
			}

			$venue_terms = wp_get_post_terms( (int) $post_id, 'venue', array( 'fields' => 'names' ) );
			$venue_name  = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0] : '';

			$missing_sync[] = array(
				'id'        => (int) $post_id,
				'title'     => get_the_title( $post_id ),
				'date'      => $start_date,
				'startTime' => $block_attrs['startTime'] ?? '',
				'venue'     => $venue_name,
			);
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
	 * @return array|\WP_Error Results with per-event success/failure and summary
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

			$before_dates      = \DataMachineEvents\Core\EventDatesTable::get( $event_id );
			$before_datetime   = $before_dates ? $before_dates->start_datetime : '';
			$before_end        = $before_dates ? $before_dates->end_datetime : '';
			$before_ticket_url = get_post_meta( $event_id, '_datamachine_ticket_url', true );

			$before = array(
				'_datamachine_event_datetime'     => ! empty( $before_datetime ) ? $before_datetime : null,
				'_datamachine_event_end_datetime' => ! empty( $before_end ) ? $before_end : null,
				'_datamachine_ticket_url'         => $before_ticket_url ? $before_ticket_url : null,
			);

			if ( ! $dry_run ) {
				\DataMachineEvents\Core\data_machine_events_sync_datetime_meta( $event_id, $post, true );
			}

			$after_dates      = $dry_run ? null : \DataMachineEvents\Core\EventDatesTable::get( $event_id );
			$after_datetime   = $dry_run ? $this->calculateExpectedDatetime( $block_attrs ) : ( $after_dates ? $after_dates->start_datetime : '' );
			$after_end        = $dry_run ? $this->calculateExpectedEndDatetime( $block_attrs ) : ( $after_dates ? $after_dates->end_datetime : '' );
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
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
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
	 * Used for dry-run preview. Mirrors event-dates-sync.php logic:
	 * - Has endDate + endTime: use them.
	 * - Has endDate, no endTime: use sentinel 23:59:59.
	 * - Has endTime, no endDate: same day as start.
	 * - Neither: no end meta (empty string).
	 *
	 * @param array $attrs Block attributes
	 * @return string Expected end datetime value, or empty if none.
	 */
	private function calculateExpectedEndDatetime( array $attrs ): string {
		$start_date = $attrs['startDate'] ?? '';
		$end_date   = $attrs['endDate'] ?? '';
		$end_time   = $attrs['endTime'] ?? '';

		if ( empty( $start_date ) ) {
			return '';
		}

		$end_time_parts = explode( ':', $end_time );
		if ( $end_time && count( $end_time_parts ) === 2 ) {
			$end_time .= ':00';
		}

		if ( $end_date ) {
			$effective_end_time = $end_time ? $end_time : '23:59:59';
			return $end_date . ' ' . $effective_end_time;
		}

		if ( $end_time ) {
			return $start_date . ' ' . $end_time;
		}

		// No end date or time — no end meta should exist.
		return '';
	}
}
