<?php
/**
 * Check events for block attribute / post meta drift.
 *
 * Event data lives in two places: the Event Details block attributes
 * and post meta (_datamachine_event_datetime, _datamachine_event_end_datetime).
 * This command finds events where the block has dates but meta is missing.
 *
 * Usage:
 *   wp datamachine-events check meta-sync
 *   wp datamachine-events check meta-sync --format=json
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.14.0
 */

namespace DataMachineEvents\Cli\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckMetaSyncCommand {

	use EventQueryTrait;

	/**
	 * Check for block attribute / post meta drift.
	 *
	 * Finds events where the Event Details block has date data but
	 * the corresponding post meta keys are missing or empty.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Max events to show.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--fix]
	 * : Resync meta from block attributes for all affected events.
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
	 *     wp datamachine-events check meta-sync
	 *     wp datamachine-events check meta-sync --fix
	 *     wp datamachine-events check meta-sync --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$limit  = (int) ( $assoc_args['limit'] ?? 25 );
		$fix    = isset( $assoc_args['fix'] );
		$format = $assoc_args['format'] ?? 'table';

		// Direct scan — the Abilities API has a permission_callback that
		// requires manage_options, which isn't loaded in WP-CLI context.
		// This CLI command already IS the admin interface, so skip the
		// ability layer and query directly.
		$missing = $this->find_missing_meta_sync( $limit );

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode(
				array(
					'missing_meta_sync' => $missing,
					'count'             => count( $missing ),
				),
				JSON_PRETTY_PRINT
			) );
			return;
		}

		\WP_CLI::log( sprintf( '--- Missing Meta Sync (%d) ---', count( $missing ) ) );

		if ( empty( $missing ) ) {
			\WP_CLI::success( 'All events have synced meta.' );
			return;
		}

		$table = array();
		foreach ( array_slice( $missing, 0, $limit ) as $item ) {
			$table[] = array(
				'ID'    => $item['id'] ?? $item['post_id'] ?? '',
				'Title' => mb_substr( $item['title'] ?? '', 0, 45 ),
				'Date'  => $item['date'] ?? $item['block_start_date'] ?? '',
				'Venue' => mb_substr( $item['venue'] ?? '', 0, 25 ),
			);
		}

		$this->output_results( $table, $format );
		\WP_CLI::log( '' );

		if ( $fix ) {
			$this->resync_meta( $missing );
		} else {
			\WP_CLI::log( 'Run with --fix to resync meta, or use: wp datamachine-events resync-meta' );
		}
	}

	/**
	 * Manual fallback: find events where block has dates but meta is empty.
	 *
	 * @param int $limit Max results.
	 * @return array Events with missing meta.
	 */
	private function find_missing_meta_sync( int $limit ): array {
		$events = $this->query_events( 'all', 365 );
		$result = array();

		foreach ( $events as $event ) {
			if ( count( $result ) >= $limit ) {
				break;
			}

			$attrs = $this->extract_block_attributes( $event->ID );

			if ( empty( $attrs['startDate'] ) ) {
				continue;
			}

			$meta_start = get_post_meta( $event->ID, '_datamachine_event_datetime', true );

			if ( empty( $meta_start ) ) {
				$result[] = array(
					'id'    => $event->ID,
					'title' => $event->post_title,
					'date'  => $attrs['startDate'],
					'venue' => $this->get_venue_name( $event->ID ),
				);
			}
		}

		return $result;
	}

	/**
	 * Resync post meta from block attributes.
	 *
	 * @param array $events Events with missing meta.
	 */
	private function resync_meta( array $events ): void {
		$fixed = 0;

		foreach ( $events as $event ) {
			$post_id = $event['id'] ?? $event['post_id'] ?? 0;
			if ( ! $post_id ) {
				continue;
			}

			$attrs = $this->extract_block_attributes( (int) $post_id );

			if ( empty( $attrs['startDate'] ) ) {
				continue;
			}

			$start_datetime = $attrs['startDate'];
			if ( ! empty( $attrs['startTime'] ) ) {
				$start_datetime .= ' ' . $attrs['startTime'];
			}

			update_post_meta( (int) $post_id, '_datamachine_event_datetime', $start_datetime );

			if ( ! empty( $attrs['endDate'] ) ) {
				$end_datetime = $attrs['endDate'];
				if ( ! empty( $attrs['endTime'] ) ) {
					$end_datetime .= ' ' . $attrs['endTime'];
				}
				update_post_meta( (int) $post_id, '_datamachine_event_end_datetime', $end_datetime );
			}

			++$fixed;
			\WP_CLI::log( sprintf( 'Synced: %d — %s', $post_id, $event['title'] ?? '' ) );
		}

		\WP_CLI::success( sprintf( 'Resynced meta for %d event(s).', $fixed ) );
	}
}
