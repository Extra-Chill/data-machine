<?php
/**
 * Batch Time Fix Abilities
 *
 * Provides batch time correction for events with systematic timezone/offset issues.
 * Filters by venue (required), date range (required), and optionally source URL pattern.
 * Supports offset-based fixes (+6h, -1h) or explicit time replacement.
 *
 * Abilities API integration pattern:
 * - Registers ability via wp_register_ability() on wp_abilities_api_init hook
 * - Static $registered flag prevents duplicate registration when instantiated multiple times
 * - execute_callback receives validated input, returns structured result
 * - permission_callback enforces admin capability requirement
 *
 * @package DataMachineEvents\Abilities
 * @since 0.9.16
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BatchTimeFixAbilities {

	private const DEFAULT_LIMIT = 100;
	private const BLOCK_NAME    = 'datamachine-events/event-details';

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbility();
			self::$registered = true;
		}
	}

	private function registerAbility(): void {
		add_action(
			'wp_abilities_api_init',
			function () {
				wp_register_ability(
					'datamachine-events/batch-time-fix',
					array(
						'label'               => __( 'Batch Time Fix', 'datamachine-events' ),
						'description'         => __( 'Batch fix event times with offset correction or explicit replacement', 'datamachine-events' ),
						'category'            => 'datamachine',
						'input_schema'        => array(
							'type'       => 'object',
							'required'   => array( 'venue' ),
							'properties' => array(
								'venue'          => array(
									'type'        => 'string',
									'description' => 'Venue name(s) to filter by, comma-separated for multiple',
								),
								'before'         => array(
									'type'        => 'string',
									'description' => 'Filter events imported before this date (YYYY-MM-DD)',
								),
								'after'          => array(
									'type'        => 'string',
									'description' => 'Filter events imported after this date (YYYY-MM-DD)',
								),
								'source_pattern' => array(
									'type'        => 'string',
									'description' => 'Filter by source URL pattern (SQL LIKE syntax, e.g., %.ics)',
								),
								'where_time'     => array(
									'type'        => 'string',
									'description' => 'Only fix events with this specific current startTime (HH:MM)',
								),
								'offset'         => array(
									'type'        => 'string',
									'description' => 'Time offset to apply (e.g., +6h, -1h, +30m)',
								),
								'new_time'       => array(
									'type'        => 'string',
									'description' => 'Explicit new time to set (HH:MM). Use with where_time.',
								),
								'dry_run'        => array(
									'type'        => 'boolean',
									'description' => 'Preview changes without applying (default: true)',
								),
								'limit'          => array(
									'type'        => 'integer',
									'description' => 'Maximum events to process (default: 100)',
								),
							),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'dry_run'       => array( 'type' => 'boolean' ),
								'total_matched' => array( 'type' => 'integer' ),
								'events'        => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'id'           => array( 'type' => 'integer' ),
											'title'        => array( 'type' => 'string' ),
											'venue'        => array( 'type' => 'string' ),
											'startDate'    => array( 'type' => 'string' ),
											'current_time' => array( 'type' => 'string' ),
											'new_time'     => array( 'type' => 'string' ),
											'status'       => array( 'type' => 'string' ),
										),
									),
								),
								'summary'       => array(
									'type'       => 'object',
									'properties' => array(
										'matched' => array( 'type' => 'integer' ),
										'updated' => array( 'type' => 'integer' ),
										'skipped' => array( 'type' => 'integer' ),
										'failed'  => array( 'type' => 'integer' ),
									),
								),
								'message'       => array( 'type' => 'string' ),
							),
						),
						'execute_callback'    => array( $this, 'executeBatchTimeFix' ),
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
						'meta'                => array( 'show_in_rest' => true ),
					)
				);
			}
		);
	}

	/**
	 * Execute batch time fix.
	 *
	 * @param array $input Input parameters
	 * @return array Results with matched events and fix status
	 */
	public function executeBatchTimeFix( array $input ): array {
		$venue          = $input['venue'] ?? '';
		$before         = $input['before'] ?? '';
		$after          = $input['after'] ?? '';
		$source_pattern = $input['source_pattern'] ?? '';
		$where_time     = $input['where_time'] ?? '';
		$offset         = $input['offset'] ?? '';
		$new_time       = $input['new_time'] ?? '';
		$dry_run        = $input['dry_run'] ?? true;
		$limit          = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );

		if ( empty( $venue ) ) {
			return array( 'error' => 'Venue parameter is required' );
		}

		if ( empty( $before ) && empty( $after ) ) {
			return array( 'error' => 'At least one date filter (before or after) is required' );
		}

		if ( empty( $offset ) && empty( $new_time ) ) {
			return array( 'error' => 'Either offset or new_time parameter is required' );
		}

		if ( ! empty( $new_time ) && empty( $where_time ) ) {
			return array( 'error' => 'where_time is required when using new_time (to prevent accidental overwrites)' );
		}

		$venue_term_ids = $this->resolveVenueTermIds( $venue );
		if ( empty( $venue_term_ids ) ) {
			return array( 'error' => "No matching venues found for: {$venue}" );
		}

		$events = $this->queryEvents( $venue_term_ids, $before, $after, $source_pattern, $where_time, $limit );

		if ( is_wp_error( $events ) ) {
			return array( 'error' => 'Query failed: ' . $events->get_error_message() );
		}

		if ( empty( $events ) ) {
			return array(
				'dry_run'       => $dry_run,
				'total_matched' => 0,
				'events'        => array(),
				'summary'       => array(
					'matched' => 0,
					'updated' => 0,
					'skipped' => 0,
					'failed'  => 0,
				),
				'message'       => 'No events matched the specified filters.',
			);
		}

		$results       = array();
		$updated_count = 0;
		$skipped_count = 0;
		$failed_count  = 0;

		foreach ( $events as $event ) {
			$block_attrs  = $this->extractBlockAttributes( $event->ID );
			$current_time = $block_attrs['startTime'] ?? '';
			$start_date   = $block_attrs['startDate'] ?? '';

			$venue_terms = wp_get_post_terms( $event->ID, 'venue', array( 'fields' => 'names' ) );
			$venue_name  = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0] : '';

			if ( empty( $current_time ) ) {
				$results[] = array(
					'id'           => $event->ID,
					'title'        => $event->post_title,
					'venue'        => $venue_name,
					'startDate'    => $start_date,
					'current_time' => 'N/A',
					'new_time'     => 'N/A',
					'status'       => 'skipped - no current time',
				);
				++$skipped_count;
				continue;
			}

			$calculated_new_time = $this->calculateNewTime( $current_time, $offset, $new_time );

			if ( null === $calculated_new_time ) {
				$results[] = array(
					'id'           => $event->ID,
					'title'        => $event->post_title,
					'venue'        => $venue_name,
					'startDate'    => $start_date,
					'current_time' => $current_time,
					'new_time'     => 'N/A',
					'status'       => 'skipped - invalid offset or time',
				);
				++$skipped_count;
				continue;
			}

			if ( $current_time === $calculated_new_time ) {
				$results[] = array(
					'id'           => $event->ID,
					'title'        => $event->post_title,
					'venue'        => $venue_name,
					'startDate'    => $start_date,
					'current_time' => $current_time,
					'new_time'     => $calculated_new_time,
					'status'       => 'skipped - no change needed',
				);
				++$skipped_count;
				continue;
			}

			$status = 'would update';
			if ( ! $dry_run ) {
				$update_result = $this->updateEventTime( $event->ID, $calculated_new_time );
				$status        = $update_result ? 'updated' : 'failed';
				if ( $update_result ) {
					++$updated_count;
				} else {
					++$failed_count;
				}
			}

			$results[] = array(
				'id'           => $event->ID,
				'title'        => $event->post_title,
				'venue'        => $venue_name,
				'startDate'    => $start_date,
				'current_time' => $current_time,
				'new_time'     => $calculated_new_time,
				'status'       => $status,
			);
		}

		$matched_count = count( $events );
		$message       = $this->buildSummaryMessage( $dry_run, $matched_count, $updated_count, $skipped_count, $failed_count );

		return array(
			'dry_run'       => $dry_run,
			'total_matched' => $matched_count,
			'events'        => $results,
			'summary'       => array(
				'matched' => $matched_count,
				'updated' => $updated_count,
				'skipped' => $skipped_count,
				'failed'  => $failed_count,
			),
			'message'       => $message,
		);
	}

	/**
	 * Resolve venue names to term IDs.
	 *
	 * @param string $venue Comma-separated venue names or slugs
	 * @return array Term IDs
	 */
	private function resolveVenueTermIds( string $venue ): array {
		$venue_names = array_map( 'trim', explode( ',', $venue ) );
		$term_ids    = array();

		foreach ( $venue_names as $name ) {
			$term = get_term_by( 'name', $name, 'venue' );
			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $name ), 'venue' );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$term_ids[] = $term->term_id;
			}
		}

		return $term_ids;
	}

	/**
	 * Query events matching filters.
	 *
	 * @param array  $venue_term_ids Venue term IDs
	 * @param string $before Import date before
	 * @param string $after Import date after
	 * @param string $source_pattern Source URL pattern
	 * @param string $where_time Filter by current time
	 * @param int    $limit Maximum results
	 * @return array|\WP_Error
	 */
	private function queryEvents( array $venue_term_ids, string $before, string $after, string $source_pattern, string $where_time, int $limit ): array|\WP_Error {
		$args = array(
			'post_type'      => Event_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'tax_query'      => array(
				array(
					'taxonomy' => 'venue',
					'field'    => 'term_id',
					'terms'    => $venue_term_ids,
				),
			),
		);

		$date_query = array();
		if ( ! empty( $before ) ) {
			$date_query['before'] = $before;
		}
		if ( ! empty( $after ) ) {
			$date_query['after'] = $after;
		}
		if ( ! empty( $date_query ) ) {
			$args['date_query'] = array( $date_query );
		}

		if ( ! empty( $source_pattern ) ) {
			$args['meta_query']   = $args['meta_query'] ?? array();
			$args['meta_query'][] = array(
				'key'     => '_datamachine_source_url',
				'value'   => $source_pattern,
				'compare' => 'LIKE',
			);
		}

		$query  = new \WP_Query( $args );
		$events = $query->posts;

		if ( ! empty( $where_time ) && ! empty( $events ) ) {
			$events = array_filter(
				$events,
				function ( $event ) use ( $where_time ) {
					$attrs      = $this->extractBlockAttributes( $event->ID );
					$start_time = $attrs['startTime'] ?? '';
					$normalized = $this->normalizeTime( $start_time );
					$where_norm = $this->normalizeTime( $where_time );
					return $normalized === $where_norm;
				}
			);
			$events = array_values( $events );
		}

		return $events;
	}

	/**
	 * Extract Event Details block attributes.
	 *
	 * @param int $post_id Post ID
	 * @return array Block attributes
	 */
	private function extractBlockAttributes( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$blocks = parse_blocks( $post->post_content );
		foreach ( $blocks as $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				return $block['attrs'] ?? array();
			}
		}

		return array();
	}

	/**
	 * Calculate new time based on offset or explicit replacement.
	 *
	 * @param string $current_time Current time (HH:MM or HH:MM:SS)
	 * @param string $offset Offset string (+6h, -1h, +30m)
	 * @param string $new_time Explicit new time
	 * @return string|null New time in HH:MM format, or null on error
	 */
	private function calculateNewTime( string $current_time, string $offset, string $new_time ): ?string {
		if ( ! empty( $new_time ) ) {
			return $this->normalizeTime( $new_time );
		}

		if ( empty( $offset ) ) {
			return null;
		}

		$offset_seconds = $this->parseOffset( $offset );
		if ( null === $offset_seconds ) {
			return null;
		}

		$normalized = $this->normalizeTime( $current_time );
		if ( empty( $normalized ) ) {
			return null;
		}

		$timestamp     = strtotime( "2000-01-01 {$normalized}:00" );
		$new_timestamp = $timestamp + $offset_seconds;

		return gmdate( 'H:i', $new_timestamp );
	}

	/**
	 * Parse offset string to seconds.
	 *
	 * @param string $offset Offset string (+6h, -1h, +30m, -2h30m)
	 * @return int|null Offset in seconds
	 */
	private function parseOffset( string $offset ): ?int {
		$offset = trim( $offset );
		if ( empty( $offset ) ) {
			return null;
		}

		$sign = 1;
		if ( str_starts_with( $offset, '-' ) ) {
			$sign   = -1;
			$offset = substr( $offset, 1 );
		} elseif ( str_starts_with( $offset, '+' ) ) {
			$offset = substr( $offset, 1 );
		}

		$total_seconds = 0;

		if ( preg_match( '/(\d+)h/i', $offset, $matches ) ) {
			$total_seconds += (int) $matches[1] * 3600;
		}

		if ( preg_match( '/(\d+)m/i', $offset, $matches ) ) {
			$total_seconds += (int) $matches[1] * 60;
		}

		if ( 0 === $total_seconds ) {
			return null;
		}

		return $sign * $total_seconds;
	}

	/**
	 * Normalize time to HH:MM format.
	 *
	 * @param string $time Time string
	 * @return string Normalized time or empty string
	 */
	private function normalizeTime( string $time ): string {
		$time = trim( $time );
		if ( empty( $time ) ) {
			return '';
		}

		if ( preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $matches ) ) {
			return sprintf( '%02d:%02d', (int) $matches[1], (int) $matches[2] );
		}

		return '';
	}

	/**
	 * Update event's startTime in block attributes.
	 *
	 * @param int    $post_id Post ID
	 * @param string $new_time New time in HH:MM format
	 * @return bool Success
	 */
	private function updateEventTime( int $post_id, string $new_time ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$blocks      = parse_blocks( $post->post_content );
		$block_index = null;

		foreach ( $blocks as $index => $block ) {
			if ( self::BLOCK_NAME === $block['blockName'] ) {
				$block_index = $index;
				break;
			}
		}

		if ( null === $block_index ) {
			return false;
		}

		$blocks[ $block_index ]['attrs']['startTime'] = $new_time;
		$new_content                                  = serialize_blocks( $blocks );

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);

		return ! is_wp_error( $result );
	}

	/**
	 * Build summary message.
	 *
	 * @param bool $dry_run Whether this is a dry run
	 * @param int  $matched Total matched
	 * @param int  $updated Updated count
	 * @param int  $skipped Skipped count
	 * @param int  $failed Failed count
	 * @return string Summary message
	 */
	private function buildSummaryMessage( bool $dry_run, int $matched, int $updated, int $skipped, int $failed ): string {
		if ( $dry_run ) {
			$would_update = $matched - $skipped;
			return "Dry run: {$matched} events matched, {$would_update} would be updated, {$skipped} would be skipped. Run without --dry-run to apply.";
		}

		$parts = array();
		if ( $updated > 0 ) {
			$parts[] = "{$updated} updated";
		}
		if ( $skipped > 0 ) {
			$parts[] = "{$skipped} skipped";
		}
		if ( $failed > 0 ) {
			$parts[] = "{$failed} failed";
		}

		if ( empty( $parts ) ) {
			return 'No events processed.';
		}

		return implode( ', ', $parts );
	}
}
