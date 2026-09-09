<?php
/**
 * Check events for venue-related data quality issues.
 *
 * Detects: missing venue assignment, missing venue timezone,
 * and events at venues without geocoded coordinates.
 *
 * Also reports (and with --apply, performs) the missing-venue repair:
 * resolving each venue-less event's venue from its own event-details
 * block attributes and assigning the matched term. Dry run by default;
 * terms are never created. See MissingVenueRepairer (#803).
 *
 * Usage:
 *   wp data-machine-events check venues
 *   wp data-machine-events check venues --scope=all
 *   wp data-machine-events check venues --format=json
 *   wp data-machine-events check venues --scope=all --apply
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.14.0
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\MissingVenueRepairer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckVenuesCommand {

	use EventQueryTrait;

	/**
	 * Check events for venue-related issues.
	 *
	 * Scans events for missing venue assignment, venues without timezone,
	 * and venues without geocoded coordinates.
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
	 * : Max items to show per category.
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
	 * [--apply]
	 * : Assign matched venue terms to venue-less events. Without this flag
	 * the repair is a dry run: identical reporting, no writes. Terms are
	 * never created.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events check venues
	 *     wp data-machine-events check venues --scope=all
	 *     wp data-machine-events check venues --scope=all --apply
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$scope      = $assoc_args['scope'] ?? 'upcoming';
		$days_ahead = (int) ( $assoc_args['days-ahead'] ?? 90 );
		$limit      = (int) ( $assoc_args['limit'] ?? 25 );
		$format     = $assoc_args['format'] ?? 'table';
		$apply      = isset( $assoc_args['apply'] );

		$events = $this->query_events( $scope, $days_ahead );

		if ( empty( $events ) ) {
			\WP_CLI::success( "No events found ({$scope} scope)." );
			return;
		}

		$missing_venue = array();

		foreach ( $events as $event ) {
			$attrs      = $this->extract_block_attributes( $event->ID );
			$venue_name = $this->get_venue_name( $event->ID );
			$info       = $this->build_event_info( $event, $attrs, $venue_name );

			if ( empty( $venue_name ) ) {
				$missing_venue[] = $info;
			}
		}

		$this->sort_by_date( $missing_venue, $scope );

		// Missing-venue repair pass: resolve identities from each event's own
		// block attrs; assign matched terms only with --apply (#803).
		$repairer        = new MissingVenueRepairer();
		$repair          = $repairer->repair( $scope, $days_ahead, $apply );

		// Broken timezone: delegate to existing ability if available
		$broken_timezone = array();
		$no_venue_count  = 0;
		$ability         = wp_get_ability( 'data-machine-events/find-broken-timezone-events' );
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

		// Missing geocode: scan venue terms
		$missing_geocode = $this->find_venues_missing_geocode( $limit );

		if ( 'json' === $format ) {
			\WP_CLI::log( (string) wp_json_encode(
				array(
					'total_scanned'   => count( $events ),
					'scope'           => $scope,
					'missing_venue'   => array_slice( $missing_venue, 0, $limit ),
					'repair'          => $repair,
					'broken_timezone' => array_slice( $broken_timezone, 0, $limit ),
					'missing_geocode' => array_slice( $missing_geocode, 0, $limit ),
					'no_venue_count'  => $no_venue_count,
				),
				JSON_PRETTY_PRINT
			) );
			return;
		}

		$total_issues = count( $missing_venue ) + count( $broken_timezone ) + count( $missing_geocode );

		\WP_CLI::log( sprintf( 'Scanned %d events (%s scope)', count( $events ), $scope ) );
		\WP_CLI::log( '' );

		// Missing venue (events without venue term)
		\WP_CLI::log( sprintf( '--- Missing Venue (%d) ---', count( $missing_venue ) ) );
		if ( empty( $missing_venue ) ) {
			\WP_CLI::log( 'None.' );
		} else {
			$table = array();
			foreach ( array_slice( $missing_venue, 0, $limit ) as $item ) {
				$table[] = array(
					'ID'    => $item['id'],
					'Title' => mb_substr( $item['title'], 0, 45 ),
					'Date'  => $item['date'],
				);
			}
			$this->output_results( $table, $format, array( 'ID', 'Title', 'Date' ) );
		}
		\WP_CLI::log( '' );

		// Missing-venue repair report (#803).
		$repair_mode = $apply ? 'APPLIED' : 'DRY RUN';
		\WP_CLI::log( sprintf( '--- Missing Venue Repair (%s) ---', $repair_mode ) );
		\WP_CLI::log(
			sprintf(
				'Scanned %d; missing %d; matched %d (assigned %d); ambiguous %d; no match %d; empty %d.',
				$repair['scanned'],
				$repair['missing'],
				$repair['matched'],
				$repair['assigned'],
				$repair['ambiguous'],
				$repair['no_match'],
				$repair['empty']
			)
		);

		if ( ! empty( $repair['candidates'] ) ) {
			$table = array();
			foreach ( array_slice( $repair['candidates'], 0, $limit ) as $candidate ) {
				$table[] = array(
					'ID'      => $candidate['post_id'],
					'Venue'   => mb_substr( (string) $candidate['venue_name'], 0, 35 ),
					'Address' => mb_substr( (string) $candidate['address'], 0, 35 ),
					'Status'  => $candidate['match_status'],
					'Term'    => mb_substr( (string) $candidate['term_name'], 0, 30 ),
				);
			}
			$this->output_results( $table, $format, array( 'ID', 'Venue', 'Address', 'Status', 'Term' ) );
		}

		if ( ! $apply && $repair['matched'] > 0 ) {
			\WP_CLI::log( 'Re-run with --apply to assign the matched venue terms.' );
		}
		\WP_CLI::log( '' );

		// Broken timezone
		\WP_CLI::log( sprintf( '--- Missing Venue Timezone (%d) ---', count( $broken_timezone ) ) );
		if ( empty( $broken_timezone ) ) {
			\WP_CLI::log( 'None.' );
		} else {
			$table = array();
			foreach ( array_slice( $broken_timezone, 0, $limit ) as $item ) {
				$table[] = array(
					'ID'    => $item['id'] ?? $item['event_id'] ?? '',
					'Title' => mb_substr( $item['title'] ?? '', 0, 45 ),
					'Date'  => $item['date'] ?? '',
					'Venue' => mb_substr( $item['venue'] ?? '', 0, 25 ),
				);
			}
			$this->output_results( $table, $format );
		}
		\WP_CLI::log( '' );

		// Missing geocode (venue terms without coordinates)
		\WP_CLI::log( sprintf( '--- Venues Missing Coordinates (%d) ---', count( $missing_geocode ) ) );
		if ( empty( $missing_geocode ) ) {
			\WP_CLI::log( 'None.' );
		} else {
			$this->output_results(
				array_slice( $missing_geocode, 0, $limit ),
				$format,
				array( 'ID', 'Name', 'Events' )
			);
		}
		\WP_CLI::log( '' );

		if ( 0 === $total_issues ) {
			\WP_CLI::success( 'No venue issues found.' );
		} else {
			\WP_CLI::warning( sprintf( '%d venue issue(s) found.', $total_issues ) );
		}
	}

	/**
	 * Find venue terms missing coordinates.
	 *
	 * @param int $limit Max results.
	 * @return array Venue info arrays.
	 */
	private function find_venues_missing_geocode( int $limit ): array {
		$venues = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => true,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $venues ) || empty( $venues ) ) {
			return array();
		}

		$missing = array();

		foreach ( $venues as $venue ) {
			$coords = get_term_meta( $venue->term_id, 'coordinates', true );

			if ( empty( $coords ) ) {
				$missing[] = array(
					'ID'     => $venue->term_id,
					'Name'   => $venue->name,
					'Events' => $venue->count,
				);
			}
		}

		// Sort by event count descending (most impactful first)
		usort( $missing, fn( $a, $b ) => $b['Events'] - $a['Events'] );

		return array_slice( $missing, 0, $limit );
	}
}
