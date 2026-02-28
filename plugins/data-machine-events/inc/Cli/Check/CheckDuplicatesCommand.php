<?php
/**
 * Check for duplicate events.
 *
 * Groups events by date, then uses fuzzy title matching to find
 * probable duplicates that slipped through the dedup pipeline.
 *
 * Usage:
 *   wp datamachine-events check duplicates
 *   wp datamachine-events check duplicates --scope=all
 *   wp datamachine-events check duplicates --format=json
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.14.0
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Utilities\EventIdentifierGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckDuplicatesCommand {

	use EventQueryTrait;

	/**
	 * Check for duplicate events.
	 *
	 * Groups events by date and uses fuzzy title matching to identify
	 * probable duplicates that weren't caught during import.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scope>]
	 * : Which events to scan.
	 * ---
	 * default: upcoming
	 * options:
	 *   - upcoming
	 *   - past
	 *   - all
	 * ---
	 *
	 * [--days-ahead=<days>]
	 * : Days to look ahead for upcoming scope.
	 * ---
	 * default: 90
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Max duplicate groups to show.
	 * ---
	 * default: 25
	 * ---
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
	 *     wp datamachine-events check duplicates
	 *     wp datamachine-events check duplicates --scope=all
	 *     wp datamachine-events check duplicates --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$scope      = $assoc_args['scope'] ?? 'upcoming';
		$days_ahead = (int) ( $assoc_args['days-ahead'] ?? 90 );
		$limit      = (int) ( $assoc_args['limit'] ?? 25 );
		$format     = $assoc_args['format'] ?? 'table';

		$events = $this->query_events( $scope, $days_ahead );

		if ( empty( $events ) ) {
			\WP_CLI::success( "No events found ({$scope} scope)." );
			return;
		}

		\WP_CLI::log( sprintf( 'Scanning %d events for duplicates (%s scope)...', count( $events ), $scope ) );

		// Group events by date (YYYY-MM-DD)
		$by_date = array();
		foreach ( $events as $event ) {
			$start_meta = get_post_meta( $event->ID, '_datamachine_event_datetime', true );
			$date       = $start_meta ? substr( $start_meta, 0, 10 ) : '';

			if ( empty( $date ) ) {
				continue;
			}

			$by_date[ $date ][] = $event;
		}

		$duplicate_groups = array();

		foreach ( $by_date as $date => $date_events ) {
			if ( count( $date_events ) < 2 ) {
				continue;
			}

			// Pre-fetch venue names for all events on this date.
			$venue_cache = array();
			foreach ( $date_events as $event ) {
				$venue_cache[ $event->ID ] = $this->get_venue_name( $event->ID );
			}

			// Compare all pairs of events on the same date.
			$matched_ids = array();

			for ( $i = 0, $count = count( $date_events ); $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$event_a = $date_events[ $i ];
					$event_b = $date_events[ $j ];

					if ( isset( $matched_ids[ $event_b->ID ] ) ) {
						continue;
					}

					if ( ! EventIdentifierGenerator::titlesMatch( $event_a->post_title, $event_b->post_title ) ) {
						continue;
					}

					$venue_a = $venue_cache[ $event_a->ID ];
					$venue_b = $venue_cache[ $event_b->ID ];

					// Require same or similar venue to avoid false positives
					// (e.g. "Free Week" events at different venues are NOT duplicates).
					if ( ! EventIdentifierGenerator::venuesMatch( $venue_a, $venue_b ) ) {
						continue;
					}

					$duplicate_groups[] = array(
						'date'    => $date,
						'event_a' => array(
							'id'    => $event_a->ID,
							'title' => $event_a->post_title,
							'venue' => $venue_a,
						),
						'event_b' => array(
							'id'    => $event_b->ID,
							'title' => $event_b->post_title,
							'venue' => $venue_b,
						),
					);

					$matched_ids[ $event_b->ID ] = true;
				}
			}
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode(
				array(
					'total_scanned'    => count( $events ),
					'scope'            => $scope,
					'duplicate_groups' => array_slice( $duplicate_groups, 0, $limit ),
					'count'            => count( $duplicate_groups ),
				),
				JSON_PRETTY_PRINT
			) );
			return;
		}

		\WP_CLI::log( '' );

		if ( empty( $duplicate_groups ) ) {
			\WP_CLI::success( sprintf( 'No duplicates found across %d events.', count( $events ) ) );
			return;
		}

		\WP_CLI::log( sprintf( '--- Probable Duplicates (%d groups) ---', count( $duplicate_groups ) ) );
		\WP_CLI::log( '' );

		$table = array();
		foreach ( array_slice( $duplicate_groups, 0, $limit ) as $group ) {
			$table[] = array(
				'Date'    => $group['date'],
				'ID_A'    => $group['event_a']['id'],
				'Title_A' => mb_substr( $group['event_a']['title'], 0, 35 ),
				'Venue_A' => mb_substr( $group['event_a']['venue'], 0, 20 ),
				'ID_B'    => $group['event_b']['id'],
				'Title_B' => mb_substr( $group['event_b']['title'], 0, 35 ),
				'Venue_B' => mb_substr( $group['event_b']['venue'], 0, 20 ),
			);
		}

		$this->output_results( $table, $format, array( 'Date', 'ID_A', 'Title_A', 'Venue_A', 'ID_B', 'Title_B', 'Venue_B' ) );

		if ( count( $duplicate_groups ) > $limit ) {
			\WP_CLI::log( sprintf( '... and %d more groups', count( $duplicate_groups ) - $limit ) );
		}

		\WP_CLI::log( '' );
		\WP_CLI::warning( sprintf( '%d probable duplicate group(s) found. Review and trash the extras manually.', count( $duplicate_groups ) ) );
	}
}
