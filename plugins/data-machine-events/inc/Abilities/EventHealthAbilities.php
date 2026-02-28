<?php
/**
 * Event Health Abilities
 *
 * Scans events for data quality issues: missing time, suspicious midnight start,
 * late night start (midnight-4am), suspicious 11:59pm end time, missing venue,
 * missing description, or missing meta sync (block has data but post meta failed).
 *
 * Provides abilities for CLI/REST/MCP consumption.
 * Chat tool wrapper lives in inc/Api/Chat/Tools/EventHealthCheck.php.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventHealthAbilities {

	private const DEFAULT_LIMIT      = 25;
	private const DEFAULT_DAYS_AHEAD = 90;

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
				'datamachine-events/event-health-check',
				array(
					'label'               => __( 'Event Health Check', 'datamachine-events' ),
					'description'         => __( 'Scan events for data quality issues', 'datamachine-events' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'scope'      => array(
								'type'        => 'string',
								'enum'        => array( 'upcoming', 'all', 'past' ),
								'description' => 'Which events to check: "upcoming" (default), "all", or "past"',
							),
							'days_ahead' => array(
								'type'        => 'integer',
								'description' => 'Days to look ahead for upcoming scope (default: 90)',
							),
							'limit'      => array(
								'type'        => 'integer',
								'description' => 'Max events to return per issue category (default: 25)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'total_scanned'       => array( 'type' => 'integer' ),
							'scope'               => array( 'type' => 'string' ),
							'missing_time'        => array(
								'type'       => 'object',
								'properties' => array(
									'count'  => array( 'type' => 'integer' ),
									'events' => array( 'type' => 'array' ),
								),
							),
							'midnight_time'       => array(
								'type'       => 'object',
								'properties' => array(
									'count'  => array( 'type' => 'integer' ),
									'events' => array( 'type' => 'array' ),
								),
							),
							'late_night_time'     => array(
								'type'       => 'object',
								'properties' => array(
									'count'  => array( 'type' => 'integer' ),
									'events' => array( 'type' => 'array' ),
								),
							),
							'suspicious_end_time' => array(
								'type'       => 'object',
								'properties' => array(
									'count'  => array( 'type' => 'integer' ),
									'events' => array( 'type' => 'array' ),
								),
							),
							'missing_venue'       => array(
								'type'       => 'object',
								'properties' => array(
									'count'  => array( 'type' => 'integer' ),
									'events' => array( 'type' => 'array' ),
								),
							),
							'missing_description' => array(
								'type'       => 'object',
								'properties' => array(
									'count'  => array( 'type' => 'integer' ),
									'events' => array( 'type' => 'array' ),
								),
							),
							'broken_timezone'     => array(
								'type'       => 'object',
								'properties' => array(
									'count'  => array( 'type' => 'integer' ),
									'events' => array( 'type' => 'array' ),
								),
							),
							'invalid_encoding'    => array(
								'type'       => 'object',
								'properties' => array(
									'count'  => array( 'type' => 'integer' ),
									'events' => array( 'type' => 'array' ),
								),
							),
							'message'             => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeHealthCheck' ),
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
	 * Execute health check.
	 *
	 * @param array $input Input parameters with optional 'scope', 'days_ahead', 'limit'
	 * @return array Health check results with category counts and event lists
	 */
	public function executeHealthCheck( array $input ): array {
		$scope      = $input['scope'] ?? 'upcoming';
		$days_ahead = (int) ( $input['days_ahead'] ?? self::DEFAULT_DAYS_AHEAD );
		$limit      = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );

		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LIMIT;
		}
		if ( $days_ahead <= 0 ) {
			$days_ahead = self::DEFAULT_DAYS_AHEAD;
		}

		$events = $this->queryEvents( $scope, $days_ahead );

		if ( is_wp_error( $events ) ) {
			return array(
				'error' => 'Failed to query events: ' . $events->get_error_message(),
			);
		}

		if ( empty( $events ) ) {
			return array(
				'total_scanned' => 0,
				'scope'         => $scope,
				'message'       => 'No events found matching the specified scope.',
			);
		}

		$missing_time        = array();
		$midnight_time       = array();
		$late_night_time     = array();
		$suspicious_end_time = array();
		$missing_venue       = array();
		$missing_description = array();
		$broken_timezone     = array();
		$invalid_encoding    = array();
		$no_venue_count      = 0;

		foreach ( $events as $event ) {
			$block_attrs = $this->extractBlockAttributes( $event->ID );
			$venue_terms = wp_get_post_terms( $event->ID, 'venue', array( 'fields' => 'names' ) );
			$venue_name  = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0] : '';

			$event_info = array(
				'id'    => $event->ID,
				'title' => $event->post_title,
				'date'  => $block_attrs['startDate'] ?? '',
				'venue' => $venue_name,
			);

			$start_time = $block_attrs['startTime'] ?? '';
			$end_time   = $block_attrs['endTime'] ?? '';

			if ( empty( $start_time ) ) {
				$missing_time[] = $event_info;
			} elseif ( '00:00' === $start_time || '00:00:00' === $start_time ) {
				$midnight_time[] = $event_info;
			} elseif ( $this->isLateNightTime( $start_time ) ) {
				$late_night_time[] = $event_info;
			}

			if ( $this->isSuspiciousEndTime( $end_time ) ) {
				$suspicious_end_time[] = $event_info;
			}

			if ( empty( $venue_name ) ) {
				$missing_venue[] = $event_info;
			}

			$description = $this->extractDescriptionFromInnerBlocks( $event->ID );
			if ( empty( trim( $description ) ) ) {
				$missing_description[] = $event_info;
			}

			$encoding_fields = $this->checkEncodingIssues( $block_attrs );
			if ( ! empty( $encoding_fields ) ) {
				$event_info['affected_fields'] = $encoding_fields;
				$invalid_encoding[]            = $event_info;
			}
		}

		$broken_timezone = array();
		$no_venue_count  = 0;
		$ability         = wp_get_ability( 'datamachine-events/find-broken-timezone-events' );
		if ( $ability ) {
			$result = $ability->execute(
				array(
					'scope' => $scope,
					'limit' => $limit,
				)
			);

			if ( ! is_wp_error( $result ) ) {
				$broken_timezone = $result['broken_events'] ?? array();
				$no_venue_count  = $result['no_venue_count'] ?? 0;
			}
		}

		$missing_meta_sync = array();
		$meta_sync_ability = wp_get_ability( 'datamachine-events/find-missing-meta-sync' );
		if ( $meta_sync_ability ) {
			$meta_sync_result = $meta_sync_ability->execute( array( 'limit' => $limit ) );

			if ( ! is_wp_error( $meta_sync_result ) ) {
				$missing_meta_sync = $meta_sync_result['events'] ?? array();
			}
		}

		$total = count( $events );

		$sort_by_date = fn( $a, $b ) => strcmp( $a['date'], $b['date'] );
		if ( 'past' === $scope ) {
			$sort_by_date = fn( $a, $b ) => strcmp( $b['date'], $a['date'] );
		}

		usort( $missing_time, $sort_by_date );
		usort( $midnight_time, $sort_by_date );
		usort( $late_night_time, $sort_by_date );
		usort( $suspicious_end_time, $sort_by_date );
		usort( $missing_venue, $sort_by_date );
		usort( $missing_description, $sort_by_date );
		usort( $invalid_encoding, $sort_by_date );

		$message_parts = array();
		if ( ! empty( $missing_time ) ) {
			$message_parts[] = count( $missing_time ) . ' missing time';
		}
		if ( ! empty( $midnight_time ) ) {
			$message_parts[] = count( $midnight_time ) . ' suspicious midnight';
		}
		if ( ! empty( $late_night_time ) ) {
			$message_parts[] = count( $late_night_time ) . ' late night (midnight-4am)';
		}
		if ( ! empty( $suspicious_end_time ) ) {
			$message_parts[] = count( $suspicious_end_time ) . ' suspicious 11:59pm end';
		}
		if ( ! empty( $missing_venue ) ) {
			$message_parts[] = count( $missing_venue ) . ' missing venue';
		}
		if ( ! empty( $missing_description ) ) {
			$message_parts[] = count( $missing_description ) . ' missing description';
		}
		if ( count( $broken_timezone ) > 0 ) {
			$message_parts[] = count( $broken_timezone ) . ' missing timezone';
		}
		if ( ! empty( $invalid_encoding ) ) {
			$message_parts[] = count( $invalid_encoding ) . ' invalid encoding';
		}
		if ( ! empty( $missing_meta_sync ) ) {
			$message_parts[] = count( $missing_meta_sync ) . ' missing meta sync';
		}
		if ( $no_venue_count > 0 ) {
			$message_parts[] = $no_venue_count . ' no venue';
		}

		if ( empty( $message_parts ) ) {
			$message = "All {$total} events have complete data.";
		} else {
			$message = 'Found issues: ' . implode( ', ', $message_parts ) . '. Use update_event tool to fix.';
		}

		return array(
			'total_scanned'       => $total,
			'scope'               => $scope,
			'missing_time'        => array(
				'count'  => count( $missing_time ),
				'events' => array_slice( $missing_time, 0, $limit ),
			),
			'midnight_time'       => array(
				'count'  => count( $midnight_time ),
				'events' => array_slice( $midnight_time, 0, $limit ),
			),
			'late_night_time'     => array(
				'count'  => count( $late_night_time ),
				'events' => array_slice( $late_night_time, 0, $limit ),
			),
			'suspicious_end_time' => array(
				'count'  => count( $suspicious_end_time ),
				'events' => array_slice( $suspicious_end_time, 0, $limit ),
			),
			'missing_venue'       => array(
				'count'  => count( $missing_venue ),
				'events' => array_slice( $missing_venue, 0, $limit ),
			),
			'missing_description' => array(
				'count'  => count( $missing_description ),
				'events' => array_slice( $missing_description, 0, $limit ),
			),
			'broken_timezone'     => array(
				'count'  => count( $broken_timezone ),
				'events' => array_slice( $broken_timezone, 0, $limit ),
			),
			'invalid_encoding'    => array(
				'count'  => count( $invalid_encoding ),
				'events' => array_slice( $invalid_encoding, 0, $limit ),
			),
			'missing_meta_sync'   => array(
				'count'  => count( $missing_meta_sync ),
				'events' => array_slice( $missing_meta_sync, 0, $limit ),
			),
			'message'             => $message,
		);
	}

	/**
	 * Query events based on scope.
	 *
	 * @param string $scope 'upcoming', 'past', or 'all'
	 * @param int $days_ahead Days to look ahead for upcoming scope
	 * @return array|\WP_Error Array of WP_Post objects or WP_Error
	 */
	private function queryEvents( string $scope, int $days_ahead ): array|\WP_Error {
		$args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => Event_Post_Type::EVENT_DATE_META_KEY,
			'order'          => 'ASC',
		);

		$now = current_time( 'Y-m-d H:i:s' );

		if ( 'upcoming' === $scope ) {
			$end_date           = gmdate( 'Y-m-d H:i:s', strtotime( "+{$days_ahead} days" ) );
			$args['meta_query'] = array(
				array(
					'key'     => Event_Post_Type::EVENT_DATE_META_KEY,
					'value'   => array( $now, $end_date ),
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				),
			);
		} elseif ( 'past' === $scope ) {
			$args['meta_query'] = array(
				array(
					'key'     => Event_Post_Type::EVENT_DATE_META_KEY,
					'value'   => $now,
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			);
			$args['order']      = 'DESC';
		}

		$query = new \WP_Query( $args );

		return $query->posts;
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
	 * Extract description content from InnerBlocks (paragraph blocks).
	 *
	 * Descriptions are stored as core/paragraph InnerBlocks inside the
	 * event-details block, not as a block attribute.
	 *
	 * @param int $post_id Event post ID
	 * @return string Combined plain text from paragraph blocks
	 */
	private function extractDescriptionFromInnerBlocks( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$blocks = parse_blocks( $post->post_content );

		foreach ( $blocks as $block ) {
			if ( 'datamachine-events/event-details' !== $block['blockName'] ) {
				continue;
			}

			if ( empty( $block['innerBlocks'] ) ) {
				return '';
			}

			$text_parts = array();
			foreach ( $block['innerBlocks'] as $inner ) {
				if ( 'core/paragraph' === $inner['blockName'] && ! empty( $inner['innerHTML'] ) ) {
					$text_parts[] = wp_strip_all_tags( $inner['innerHTML'] );
				}
			}

			return implode( ' ', $text_parts );
		}

		return '';
	}

	/**
	 * Check if time falls in late-night window (00:01-03:59).
	 *
	 * @param string $time Time string in HH:MM or HH:MM:SS format
	 * @return bool True if time is between 00:01 and 03:59
	 */
	private function isLateNightTime( string $time ): bool {
		if ( empty( $time ) ) {
			return false;
		}

		$hour   = (int) substr( $time, 0, 2 );
		$minute = (int) substr( $time, 3, 2 );

		if ( 0 === $hour && $minute > 0 ) {
			return true;
		}

		return $hour >= 1 && $hour <= 3;
	}

	/**
	 * Check if end time is suspicious 11:59pm (likely default/placeholder).
	 *
	 * @param string $time Time string in HH:MM or HH:MM:SS format
	 * @return bool True if time is 23:59
	 */
	private function isSuspiciousEndTime( string $time ): bool {
		if ( empty( $time ) ) {
			return false;
		}

		return '23:59' === $time || '23:59:00' === $time;
	}

	/**
	 * Check for Unicode encoding issues in block attributes.
	 *
	 * Detects escaped unicode sequences like \u00a3 that should be
	 * rendered as actual characters (£).
	 *
	 * @param array $attrs Block attributes to check
	 * @return array List of affected field names, empty if none
	 */
	private function checkEncodingIssues( array $attrs ): array {
		$fields_to_check = array( 'price', 'venue', 'address' );
		$affected_fields = array();

		foreach ( $fields_to_check as $field ) {
			if ( empty( $attrs[ $field ] ) ) {
				continue;
			}
			if ( preg_match( '/\\\\u[0-9a-fA-F]{4}/', $attrs[ $field ] ) ) {
				$affected_fields[] = $field;
			}
		}

		return $affected_fields;
	}
}
