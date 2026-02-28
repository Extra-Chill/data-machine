<?php
/**
 * Shared helpers for check subcommands.
 *
 * Provides event querying, block attribute extraction, and common CLI output
 * patterns used across all `wp datamachine-events check *` commands.
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.14.0
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\Event_Post_Type;

trait EventQueryTrait {

	/**
	 * Query events by scope.
	 *
	 * @param string $scope      'upcoming', 'past', or 'all'.
	 * @param int    $days_ahead Days to look ahead for upcoming scope.
	 * @return \WP_Post[] Array of post objects.
	 */
	private function query_events( string $scope, int $days_ahead = 90 ): array {
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
			$args['order'] = 'DESC';
		}

		$query = new \WP_Query( $args );

		return $query->posts;
	}

	/**
	 * Extract Event Details block attributes from a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Block attributes or empty array.
	 */
	private function extract_block_attributes( int $post_id ): array {
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
	 * Get venue name for an event.
	 *
	 * @param int $post_id Post ID.
	 * @return string Venue name or empty string.
	 */
	private function get_venue_name( int $post_id ): string {
		$venue_terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'names' ) );

		if ( is_wp_error( $venue_terms ) || empty( $venue_terms ) ) {
			return '';
		}

		return $venue_terms[0];
	}

	/**
	 * Build standard event info array.
	 *
	 * @param \WP_Post $event      Post object.
	 * @param array    $block_attrs Block attributes.
	 * @param string   $venue_name  Venue name.
	 * @return array Standardized event info.
	 */
	private function build_event_info( \WP_Post $event, array $block_attrs, string $venue_name ): array {
		return array(
			'id'    => $event->ID,
			'title' => $event->post_title,
			'date'  => $block_attrs['startDate'] ?? '',
			'venue' => $venue_name,
		);
	}

	/**
	 * Sort events by date (ascending for upcoming, descending for past).
	 *
	 * @param array  $events Array of event info arrays with 'date' key.
	 * @param string $scope  'upcoming', 'past', or 'all'.
	 */
	private function sort_by_date( array &$events, string $scope ): void {
		if ( 'past' === $scope ) {
			usort( $events, fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );
		} else {
			usort( $events, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
		}
	}

	/**
	 * Output a standard check table.
	 *
	 * @param array  $items  Items to display.
	 * @param string $format Output format (table, json, csv).
	 * @param array  $columns Column keys to display.
	 */
	private function output_results( array $items, string $format, array $columns = array( 'ID', 'Title', 'Date', 'Venue' ) ): void {
		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $items, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( empty( $items ) ) {
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $columns );
	}
}
