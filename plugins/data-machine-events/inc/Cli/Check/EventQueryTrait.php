<?php
/**
 * Shared helpers for check subcommands.
 *
 * Provides event querying, block attribute extraction, and common CLI output
 * patterns used across all `wp data-machine-events check *` commands.
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.14.0
 */

namespace DataMachineEvents\Cli\Check;

trait EventQueryTrait {

	/**
	 * Query events by scope.
	 *
	 * @param string $scope      'upcoming', 'past', or 'all'.
	 * @param int    $days_ahead      Days to look ahead for upcoming scope.
	 * @param int    $location_term_id Optional location term ID.
	 * @return \WP_Post[] Array of post objects.
	 */
	private function query_events( string $scope, int $days_ahead = 90, int $location_term_id = 0 ): array {
		$input = array(
			'scope' => $scope,
			'order' => 'past' === $scope ? 'DESC' : 'ASC',
		);

		if ( 'upcoming' === $scope && $days_ahead > 0 ) {
			$input['days_ahead'] = $days_ahead;
		}

		if ( $location_term_id > 0 ) {
			$input['tax_filters'] = array( 'location' => array( $location_term_id ) );
		}

		$ability = new \DataMachineEvents\Abilities\EventDateQueryAbilities();
		$result  = $ability->executeQueryEvents( $input );

		return $result['posts'];
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
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
				return $block['attrs'];
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
	 * Parse a comma-separated reviewed candidate ID list.
	 *
	 * @param string $value Raw --reviewed value.
	 * @return string[] Unique trimmed candidate IDs.
	 */
	private function parse_reviewed_candidate_ids( string $value ): array {
		$ids = array_filter( array_map( 'trim', explode( ',', $value ) ) );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Restrict candidate actions to the reviewed candidate IDs.
	 *
	 * Fails closed when any reviewed ID is unknown, so an apply can only
	 * act on rows from the exact dry run being approved.
	 *
	 * @param array    $actions    Candidate rows keyed by candidate_id.
	 * @param string[] $reviewed   Reviewed candidate IDs.
	 * @param string   $error_code Error code for the stale-review failure.
	 * @return array|\WP_Error Reviewed actions or error.
	 */
	private function select_reviewed_actions( array $actions, array $reviewed, string $error_code = 'stale_duplicate_review' ): array|\WP_Error {
		$by_id   = array_column( $actions, null, 'candidate_id' );
		$missing = array_diff( $reviewed, array_keys( $by_id ) );

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				$error_code,
				'Reviewed candidate IDs are stale or outside the requested scope; no changes made: ' . implode( ', ', $missing )
			);
		}

		return array_values( array_intersect_key( $by_id, array_flip( $reviewed ) ) );
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
			\WP_CLI::log( (string) wp_json_encode( $items, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( empty( $items ) ) {
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $columns );
	}
}
