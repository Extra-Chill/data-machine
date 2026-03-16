<?php
/**
 * Event Quality Audit Abilities
 *
 * Unified event quality audit focused on operator-facing diagnostics:
 * missing venue, missing start date, missing start time, probable duplicate
 * clusters, and culprit flow summaries.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Utilities\EventIdentifierGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventQualityAuditAbilities {

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
				'data-machine-events/event-quality-audit',
				array(
					'label'               => __( 'Event Quality Audit', 'data-machine-events' ),
					'description'         => __( 'Unified event quality audit with flow-aware diagnostics.', 'data-machine-events' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'scope'      => array(
								'type'        => 'string',
								'enum'        => array( 'upcoming', 'all', 'past' ),
								'description' => 'Which events to audit.',
							),
							'days_ahead' => array(
								'type'        => 'integer',
								'description' => 'Days to look ahead for upcoming scope.',
							),
							'flow_id'    => array(
								'type'        => 'integer',
								'description' => 'Optional flow ID filter.',
							),
							'location_term_id' => array(
								'type'        => 'integer',
								'description' => 'Optional location term ID filter.',
							),
							'issue'      => array(
								'type'        => 'string',
								'enum'        => array( 'all', 'missing_start_date', 'missing_start_time', 'missing_venue', 'duplicates' ),
								'description' => 'Optional issue filter.',
							),
							'limit'      => array(
								'type'        => 'integer',
								'description' => 'Max rows to return per category.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'total_scanned'          => array( 'type' => 'integer' ),
							'scope'                  => array( 'type' => 'string' ),
							'missing_start_date'     => array( 'type' => 'object' ),
							'missing_start_time'     => array( 'type' => 'object' ),
							'missing_venue'          => array( 'type' => 'object' ),
							'probable_duplicates'    => array( 'type' => 'object' ),
							'culprit_flows'          => array( 'type' => 'array' ),
							'message'                => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeAudit' ),
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

	public function executeAudit( array $input ): array {
		$scope      = $input['scope'] ?? 'upcoming';
		$days_ahead = (int) ( $input['days_ahead'] ?? self::DEFAULT_DAYS_AHEAD );
		$flow_id    = (int) ( $input['flow_id'] ?? 0 );
		$location   = (int) ( $input['location_term_id'] ?? 0 );
		$issue      = $input['issue'] ?? 'all';
		$limit      = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );

		if ( $days_ahead <= 0 ) {
			$days_ahead = self::DEFAULT_DAYS_AHEAD;
		}

		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		$events = $this->queryEvents( $scope, $days_ahead, $flow_id, $location );
		if ( is_wp_error( $events ) ) {
			return array( 'error' => $events->get_error_message() );
		}

		$missing_start_date  = array();
		$missing_start_time  = array();
		$missing_venue       = array();
		$duplicate_groups    = array();
		$culprit_flow_counts = array();
		$by_duplicate_key    = array();

		foreach ( $events as $event ) {
			$block_attrs = $this->extractBlockAttributes( $event->ID );
			$venue_name  = $this->getVenueName( $event->ID );
			$flow_id     = (int) get_post_meta( $event->ID, '_datamachine_post_flow_id', true );

			$info = array(
				'id'         => $event->ID,
				'title'      => $event->post_title,
				'startDate'  => $block_attrs['startDate'] ?? '',
				'startTime'  => $block_attrs['startTime'] ?? '',
				'venue'      => $venue_name,
				'flow_id'    => $flow_id,
				'flow_name'  => $this->getFlowName( $flow_id ),
			);

			if ( empty( $info['startDate'] ) ) {
				$missing_start_date[] = $info;
				$this->incrementFlowCount( $culprit_flow_counts, $flow_id, $info['flow_name'] );
			}

			if ( empty( $info['startTime'] ) ) {
				$missing_start_time[] = $info;
				$this->incrementFlowCount( $culprit_flow_counts, $flow_id, $info['flow_name'] );
			}

			if ( empty( $venue_name ) ) {
				$missing_venue[] = $info;
				$this->incrementFlowCount( $culprit_flow_counts, $flow_id, $info['flow_name'] );
			}

			$duplicate_key = $this->buildDuplicateClusterKey( $event->post_title, $venue_name, $info['startDate'] );
			if ( ! empty( $duplicate_key ) ) {
				$by_duplicate_key[ $duplicate_key ][] = $info;
			}
		}

		foreach ( $by_duplicate_key as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			$duplicate_groups[] = array(
				'count'  => count( $group ),
				'date'   => $group[0]['startDate'] ?? '',
				'title'  => $group[0]['title'] ?? '',
				'venue'  => $group[0]['venue'] ?? '',
				'events' => $group,
			);

			foreach ( $group as $item ) {
				$this->incrementFlowCount( $culprit_flow_counts, (int) ( $item['flow_id'] ?? 0 ), $item['flow_name'] ?? '' );
			}
		}

		usort(
			$duplicate_groups,
			static fn( $a, $b ) => ( $b['count'] <=> $a['count'] ) ?: strcmp( (string) $a['date'], (string) $b['date'] )
		);

		usort(
			$culprit_flow_counts,
			static fn( $a, $b ) => ( $b['count'] <=> $a['count'] ) ?: strcmp( (string) $a['flow_name'], (string) $b['flow_name'] )
		);

		$message_parts = array();
		if ( ! empty( $missing_start_date ) && ( 'all' === $issue || 'missing_start_date' === $issue ) ) {
			$message_parts[] = count( $missing_start_date ) . ' missing start date';
		}
		if ( ! empty( $missing_start_time ) && ( 'all' === $issue || 'missing_start_time' === $issue ) ) {
			$message_parts[] = count( $missing_start_time ) . ' missing start time';
		}
		if ( ! empty( $missing_venue ) && ( 'all' === $issue || 'missing_venue' === $issue ) ) {
			$message_parts[] = count( $missing_venue ) . ' missing venue';
		}
		if ( ! empty( $duplicate_groups ) && ( 'all' === $issue || 'duplicates' === $issue ) ) {
			$message_parts[] = count( $duplicate_groups ) . ' probable duplicate groups';
		}

		$missing_start_date_result = ( 'all' === $issue || 'missing_start_date' === $issue )
			? array(
				'count'  => count( $missing_start_date ),
				'events' => array_slice( $missing_start_date, 0, $limit ),
			)
			: array( 'count' => 0, 'events' => array() );

		$missing_start_time_result = ( 'all' === $issue || 'missing_start_time' === $issue )
			? array(
				'count'  => count( $missing_start_time ),
				'events' => array_slice( $missing_start_time, 0, $limit ),
			)
			: array( 'count' => 0, 'events' => array() );

		$missing_venue_result = ( 'all' === $issue || 'missing_venue' === $issue )
			? array(
				'count'  => count( $missing_venue ),
				'events' => array_slice( $missing_venue, 0, $limit ),
			)
			: array( 'count' => 0, 'events' => array() );

		$duplicate_result = ( 'all' === $issue || 'duplicates' === $issue )
			? array(
				'count'  => count( $duplicate_groups ),
				'groups' => array_slice( $duplicate_groups, 0, $limit ),
			)
			: array( 'count' => 0, 'groups' => array() );

		return array(
			'total_scanned'       => count( $events ),
			'scope'               => $scope,
			'missing_start_date'  => $missing_start_date_result,
			'missing_start_time'  => $missing_start_time_result,
			'missing_venue'       => $missing_venue_result,
			'probable_duplicates' => $duplicate_result,
			'culprit_flows'       => array_slice( $culprit_flow_counts, 0, $limit ),
			'message'             => empty( $message_parts )
				? 'No major quality issues found.'
				: 'Found issues: ' . implode( ', ', $message_parts ) . '.',
		);
	}

	private function queryEvents( string $scope, int $days_ahead, int $flow_id = 0, int $location_term_id = 0 ): array|\WP_Error {
		$args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => '_datamachine_event_datetime',
			'order'          => 'ASC',
		);

		$today = current_time( 'Y-m-d' );
		if ( 'upcoming' === $scope ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_datamachine_event_datetime',
					'value'   => array( $today . ' 00:00:00', gmdate( 'Y-m-d 23:59:59', strtotime( '+' . $days_ahead . ' days', strtotime( $today ) ) ) ),
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				),
			);
		} elseif ( 'past' === $scope ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_datamachine_event_datetime',
					'value'   => $today . ' 00:00:00',
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			);
		}

		if ( $flow_id > 0 ) {
			$args['meta_query'][] = array(
				'key'     => '_datamachine_post_flow_id',
				'value'   => (string) $flow_id,
				'compare' => '=',
			);
		}

		if ( $location_term_id > 0 ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'location',
					'field'    => 'term_id',
					'terms'    => array( $location_term_id ),
				),
			);
		}

		$posts = get_posts( $args );
		return is_array( $posts ) ? $posts : array();
	}

	private function extractBlockAttributes( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$blocks = parse_blocks( $post->post_content );
		foreach ( $blocks as $block ) {
			if ( 'data-machine-events/event-details' === ( $block['blockName'] ?? '' ) ) {
				return $block['attrs'] ?? array();
			}
		}

		return array();
	}

	private function getVenueName( int $post_id ): string {
		$venue_terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'names' ) );
		return ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? (string) $venue_terms[0] : '';
	}

	private function getFlowName( int $flow_id ): string {
		if ( $flow_id <= 0 ) {
			return '';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';
		$name  = $wpdb->get_var( $wpdb->prepare( "SELECT flow_name FROM {$table} WHERE flow_id = %d", $flow_id ) );

		return is_string( $name ) ? $name : '';
	}

	private function buildDuplicateClusterKey( string $title, string $venue, string $start_date ): string {
		if ( '' === $start_date || '' === $venue ) {
			return '';
		}

		if ( ! EventIdentifierGenerator::hasSpecificTitleSignal( $title ) && EventIdentifierGenerator::isLowConfidenceTitle( $title ) ) {
			return '';
		}

		return md5( strtolower( $start_date ) . '|' . strtolower( $venue ) . '|' . strtolower( trim( wp_strip_all_tags( $title ) ) ) );
	}

	private function incrementFlowCount( array &$counts, int $flow_id, string $flow_name ): void {
		$key = (string) $flow_id;
		if ( ! isset( $counts[ $key ] ) ) {
			$counts[ $key ] = array(
				'flow_id'   => $flow_id,
				'flow_name' => $flow_name,
				'count'     => 0,
			);
		}

		++$counts[ $key ]['count'];
	}
}
