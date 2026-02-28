<?php
/**
 * WP-CLI command for detecting events with suspicious duration spans.
 *
 * Finds events where the date range suggests a recurring event was scraped
 * as a single span (e.g. weekly residency stored as Jan 1 - Jun 30), or where
 * occurrenceDates should be used instead of a start/end range.
 *
 * Usage examples:
 *   wp datamachine-events check duration
 *   wp datamachine-events check duration --max-days=7
 *   wp datamachine-events check duration --scope=all
 *   wp datamachine-events check duration --trash
 *   wp datamachine-events check duration --format=json
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.14.0
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\Event_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckDurationCommand {

	private const DEFAULT_MAX_DAYS = 14;

	/**
	 * Check for events with suspicious duration spans.
	 *
	 * Scans published events and flags any where the date range exceeds
	 * the threshold. Shows details so you can decide whether to trash,
	 * convert to occurrenceDates, or keep as-is (legitimate festival).
	 *
	 * ## OPTIONS
	 *
	 * [--max-days=<days>]
	 * : Flag events spanning more than this many days.
	 * ---
	 * default: 14
	 * ---
	 *
	 * [--scope=<scope>]
	 * : Which events to scan: upcoming, past, or all.
	 * ---
	 * default: upcoming
	 * options:
	 *   - upcoming
	 *   - past
	 *   - all
	 * ---
	 *
	 * [--trash]
	 * : Trash flagged events (interactive confirmation per event).
	 *
	 * [--trash-all]
	 * : Trash all flagged events without confirmation.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Find events spanning more than 14 days
	 *     wp datamachine-events check-duration
	 *
	 *     # Stricter: flag anything over 7 days
	 *     wp datamachine-events check-duration --max-days=7
	 *
	 *     # Scan all events (including past)
	 *     wp datamachine-events check-duration --scope=all
	 *
	 *     # Output as JSON for scripting
	 *     wp datamachine-events check-duration --format=json
	 *
	 *     # Trash all flagged events without prompts
	 *     wp datamachine-events check-duration --trash-all
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		$max_days  = (int) ( $assoc_args['max-days'] ?? self::DEFAULT_MAX_DAYS );
		$scope     = $assoc_args['scope'] ?? 'upcoming';
		$format    = $assoc_args['format'] ?? 'table';
		$trash     = isset( $assoc_args['trash'] );
		$trash_all = isset( $assoc_args['trash-all'] );

		$events = $this->find_long_span_events( $max_days, $scope );

		if ( empty( $events ) ) {
			\WP_CLI::success( "No events found spanning more than {$max_days} days ({$scope} scope)." );
			return;
		}

		\WP_CLI::log( sprintf(
			'Found %d event(s) spanning more than %d days (%s scope):',
			count( $events ),
			$max_days,
			$scope
		) );
		\WP_CLI::log( '' );

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $events, JSON_PRETTY_PRINT ) );
			return;
		}

		$table_data = array();
		foreach ( $events as $event ) {
			$table_data[] = array(
				'ID'       => $event['id'],
				'Title'    => mb_substr( $event['title'], 0, 45 ),
				'Start'    => $event['start_date'],
				'End'      => $event['end_date'],
				'Days'     => $event['span_days'],
				'Venue'    => mb_substr( $event['venue'], 0, 25 ),
				'Pipeline' => $event['pipeline_id'] ?: '—',
			);
		}

		if ( 'csv' === $format ) {
			\WP_CLI\Utils\format_items( 'csv', $table_data, array_keys( $table_data[0] ) );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $table_data, array_keys( $table_data[0] ) );
		\WP_CLI::log( '' );

		if ( $trash_all ) {
			$this->trash_events( $events );
		} elseif ( $trash ) {
			\WP_CLI::log( 'Use --trash-all to trash all flagged events, or trash individually:' );
			\WP_CLI::log( '' );
			foreach ( $events as $event ) {
				\WP_CLI::log( sprintf(
					'  wp --allow-root --url=events.extrachill.com post update %d --post_status=trash',
					$event['id']
				) );
			}
		} else {
			\WP_CLI::log( sprintf(
				'Tip: Run with --trash-all to trash these, or --max-days=%d to adjust threshold.',
				$max_days
			) );
		}
	}

	/**
	 * Find events where end_date - start_date exceeds the threshold.
	 *
	 * @param int    $max_days Maximum allowed span in days.
	 * @param string $scope    'upcoming', 'past', or 'all'.
	 * @return array Array of flagged event data.
	 */
	private function find_long_span_events( int $max_days, string $scope ): array {
		global $wpdb;

		$now = current_time( 'Y-m-d H:i:s' );

		$where_scope = '';
		if ( 'upcoming' === $scope ) {
			$where_scope = $wpdb->prepare( ' AND end_meta.meta_value >= %s', $now );
		} elseif ( 'past' === $scope ) {
			$where_scope = $wpdb->prepare( ' AND end_meta.meta_value < %s', $now );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_content,
					start_meta.meta_value AS start_dt,
					end_meta.meta_value AS end_dt,
					DATEDIFF(end_meta.meta_value, start_meta.meta_value) AS span_days,
					handler_meta.meta_value AS pipeline_id
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} start_meta
					ON p.ID = start_meta.post_id AND start_meta.meta_key = '_datamachine_event_datetime'
				INNER JOIN {$wpdb->postmeta} end_meta
					ON p.ID = end_meta.post_id AND end_meta.meta_key = '_datamachine_event_end_datetime'
				LEFT JOIN {$wpdb->postmeta} handler_meta
					ON p.ID = handler_meta.post_id AND handler_meta.meta_key = '_datamachine_post_pipeline_id'
				WHERE p.post_type = %s
					AND p.post_status = 'publish'
					AND DATEDIFF(end_meta.meta_value, start_meta.meta_value) > %d
					{$where_scope}
				ORDER BY span_days DESC",
				Event_Post_Type::POST_TYPE,
				$max_days
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$events = array();
		foreach ( $results as $row ) {
			$venue = '';
			$terms = wp_get_object_terms( (int) $row['ID'], 'venue', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$venue = $terms[0];
			}

			$events[] = array(
				'id'          => (int) $row['ID'],
				'title'       => $row['post_title'],
				'start_date'  => substr( $row['start_dt'], 0, 10 ),
				'end_date'    => substr( $row['end_dt'], 0, 10 ),
				'span_days'   => (int) $row['span_days'],
				'venue'       => $venue,
				'pipeline_id' => $row['pipeline_id'] ?: '',
				'edit_url'    => get_edit_post_link( (int) $row['ID'], 'raw' ),
			);
		}

		return $events;
	}

	/**
	 * Trash all flagged events.
	 *
	 * @param array $events Array of event data with 'id' keys.
	 */
	private function trash_events( array $events ): void {
		$trashed = 0;
		foreach ( $events as $event ) {
			$result = wp_trash_post( $event['id'] );
			if ( $result ) {
				++$trashed;
				\WP_CLI::log( sprintf( 'Trashed: %d — %s (%d days)', $event['id'], $event['title'], $event['span_days'] ) );
			} else {
				\WP_CLI::warning( sprintf( 'Failed to trash: %d — %s', $event['id'], $event['title'] ) );
			}
		}

		\WP_CLI::success( sprintf( 'Trashed %d of %d flagged events.', $trashed, count( $events ) ) );
	}
}
