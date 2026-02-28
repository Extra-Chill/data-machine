<?php
/**
 * Event Query Abilities
 *
 * Query events by venue with filtering options.
 * Provides abilities for CLI/REST/MCP consumption.
 * Chat tool wrapper lives in inc/Api/Chat/Tools/GetVenueEvents.php.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventQueryAbilities {

	const DEFAULT_LIMIT = 25;
	const MAX_LIMIT     = 100;

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
				'data-machine-events/get-venue-events',
				array(
					'label'               => __( 'Get Venue Events', 'data-machine-events' ),
					'description'         => __( 'Query events for a specific venue', 'data-machine-events' ),
					'category'            => 'datamachine',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'venue' ),
						'properties' => array(
							'venue'               => array(
								'type'        => 'string',
								'description' => 'Venue identifier (term ID, name, or slug)',
							),
							'limit'               => array(
								'type'        => 'integer',
								'description' => 'Maximum events to return (default: 25, max: 100)',
							),
							'status'              => array(
								'type'        => 'string',
								'enum'        => array( 'any', 'publish', 'future', 'draft', 'pending', 'private' ),
								'description' => 'Post status filter (default: any)',
							),
							'published_before'    => array(
								'type'        => 'string',
								'description' => 'Only return events published before this date (YYYY-MM-DD format)',
							),
							'published_after'     => array(
								'type'        => 'string',
								'description' => 'Only return events published after this date (YYYY-MM-DD format)',
							),
							'include_description' => array(
								'type'        => 'boolean',
								'description' => 'Include full description text in output (default: false)',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'venue'          => array(
								'type'       => 'object',
								'properties' => array(
									'term_id'      => array( 'type' => 'integer' ),
									'name'         => array( 'type' => 'string' ),
									'slug'         => array( 'type' => 'string' ),
									'total_events' => array( 'type' => 'integer' ),
									'venue_data'   => array( 'type' => 'object' ),
								),
							),
							'events'         => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'post_id'     => array( 'type' => 'integer' ),
										'title'       => array( 'type' => 'string' ),
										'status'      => array( 'type' => 'string' ),
										'published'   => array( 'type' => 'string' ),
										'start_date'  => array( 'type' => 'string' ),
										'end_date'    => array( 'type' => 'string' ),
										'permalink'   => array( 'type' => 'string' ),
										'description' => array( 'type' => 'string' ),
									),
								),
							),
							'returned_count' => array( 'type' => 'integer' ),
							'message'        => array( 'type' => 'string' ),
						),
					),
					'execute_callback'    => array( $this, 'executeGetVenueEvents' ),
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

	public function executeGetVenueEvents( array $input ): array {
		$venue_identifier = $input['venue'] ?? null;

		if ( empty( $venue_identifier ) ) {
			return array(
				'error' => 'venue parameter is required',
			);
		}

		$term = $this->resolveVenue( $venue_identifier );
		if ( ! $term ) {
			return array(
				'error' => "Venue '{$venue_identifier}' not found",
			);
		}

		$limit  = isset( $input['limit'] ) ? min( max( 1, (int) $input['limit'] ), self::MAX_LIMIT ) : self::DEFAULT_LIMIT;
		$status = $input['status'] ?? 'any';

		$valid_statuses = array( 'any', 'publish', 'future', 'draft', 'pending', 'private' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			$status = 'any';
		}

		$date_query = array();
		if ( ! empty( $input['published_before'] ) ) {
			$date_query[] = array(
				'before'    => $input['published_before'],
				'inclusive' => false,
			);
		}
		if ( ! empty( $input['published_after'] ) ) {
			$date_query[] = array(
				'after'     => $input['published_after'],
				'inclusive' => true,
			);
		}

		$query_args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => $status,
			'posts_per_page' => $limit,
			'orderby'        => 'meta_value',
			'meta_key'       => '_datamachine_event_datetime',
			'order'          => 'DESC',
			'tax_query'      => array(
				array(
					'taxonomy' => 'venue',
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			),
		);

		if ( ! empty( $date_query ) ) {
			$query_args['date_query'] = $date_query;
		}

		$query               = new \WP_Query( $query_args );
		$events              = array();
		$include_description = ! empty( $input['include_description'] );

		foreach ( $query->posts as $post ) {
			$start_date = get_post_meta( $post->ID, '_datamachine_event_datetime', true );
			$end_date   = get_post_meta( $post->ID, '_datamachine_event_end_datetime', true );

			$event_data = array(
				'post_id'    => $post->ID,
				'title'      => $post->post_title,
				'status'     => $post->post_status,
				'published'  => $post->post_date,
				'start_date' => $start_date ? $start_date : null,
				'end_date'   => $end_date ? $end_date : null,
				'permalink'  => get_permalink( $post->ID ),
			);

			if ( $include_description ) {
				$event_data['description'] = $this->extractDescription( $post->ID );
			}

			$events[] = $event_data;
		}

		$venue_data  = Venue_Taxonomy::get_venue_data( $term->term_id );
		$total_count = $term->count;

		return array(
			'venue'          => array(
				'term_id'      => $term->term_id,
				'name'         => $term->name,
				'slug'         => $term->slug,
				'total_events' => $total_count,
				'venue_data'   => $venue_data,
			),
			'events'         => $events,
			'returned_count' => count( $events ),
			'message'        => sprintf(
				"Found %d events for venue '%s' (showing %d)",
				$total_count,
				$term->name,
				count( $events )
			),
		);
	}

	private function resolveVenue( string $identifier ): ?\WP_Term {
		if ( is_numeric( $identifier ) ) {
			$term = get_term( (int) $identifier, 'venue' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		$term = get_term_by( 'name', $identifier, 'venue' );
		if ( $term ) {
			return $term;
		}

		$term = get_term_by( 'slug', $identifier, 'venue' );
		if ( $term ) {
			return $term;
		}

		return null;
	}

	/**
	 * Extract description from Event Details block InnerBlocks.
	 *
	 * @param int $post_id Post ID.
	 * @return string Description text (plain text, paragraphs joined with newlines).
	 */
	private function extractDescription( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$blocks = parse_blocks( $post->post_content );

		foreach ( $blocks as $block ) {
			if ( 'data-machine-events/event-details' !== $block['blockName'] ) {
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

			return implode( "\n\n", $text_parts );
		}

		return '';
	}
}
