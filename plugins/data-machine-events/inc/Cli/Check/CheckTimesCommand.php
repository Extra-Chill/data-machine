<?php
/**
 * Check events for time-related data quality issues.
 *
 * Detects: missing start time, suspicious midnight start (00:00),
 * late-night start (00:01–03:59), and suspicious 11:59 PM end time.
 *
 * Usage:
 *   wp datamachine-events check times
 *   wp datamachine-events check times --scope=all --limit=50
 *   wp datamachine-events check times --format=json
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.14.0
 */

namespace DataMachineEvents\Cli\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckTimesCommand {

	use EventQueryTrait;

	/**
	 * Check events for time-related issues.
	 *
	 * Scans events for missing start times, suspicious midnight starts,
	 * late-night starts (midnight–4 AM), and placeholder 11:59 PM end times.
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
	 * : Max events to show per category.
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
	 *     wp datamachine-events check times
	 *     wp datamachine-events check times --scope=all --limit=50
	 *     wp datamachine-events check times --format=json
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

		$missing_time        = array();
		$midnight_time       = array();
		$late_night_time     = array();
		$suspicious_end_time = array();

		foreach ( $events as $event ) {
			$attrs      = $this->extract_block_attributes( $event->ID );
			$venue_name = $this->get_venue_name( $event->ID );
			$info       = $this->build_event_info( $event, $attrs, $venue_name );

			$start_time = $attrs['startTime'] ?? '';
			$end_time   = $attrs['endTime'] ?? '';

			if ( empty( $start_time ) ) {
				$missing_time[] = $info;
			} elseif ( '00:00' === $start_time || '00:00:00' === $start_time ) {
				$midnight_time[] = $info;
			} elseif ( $this->is_late_night( $start_time ) ) {
				$late_night_time[] = $info;
			}

			if ( $this->is_suspicious_end( $end_time ) ) {
				$suspicious_end_time[] = $info;
			}
		}

		$this->sort_by_date( $missing_time, $scope );
		$this->sort_by_date( $midnight_time, $scope );
		$this->sort_by_date( $late_night_time, $scope );
		$this->sort_by_date( $suspicious_end_time, $scope );

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode(
				array(
					'total_scanned'       => count( $events ),
					'scope'               => $scope,
					'missing_time'        => array_slice( $missing_time, 0, $limit ),
					'midnight_time'       => array_slice( $midnight_time, 0, $limit ),
					'late_night_time'     => array_slice( $late_night_time, 0, $limit ),
					'suspicious_end_time' => array_slice( $suspicious_end_time, 0, $limit ),
				),
				JSON_PRETTY_PRINT
			) );
			return;
		}

		$total_issues = count( $missing_time ) + count( $midnight_time ) + count( $late_night_time ) + count( $suspicious_end_time );

		\WP_CLI::log( sprintf( 'Scanned %d events (%s scope)', count( $events ), $scope ) );
		\WP_CLI::log( '' );

		$this->print_category( 'Missing Start Time', $missing_time, $limit, $format );
		$this->print_category( 'Suspicious Midnight Start (00:00)', $midnight_time, $limit, $format );
		$this->print_category( 'Late Night Start (00:01–03:59)', $late_night_time, $limit, $format );
		$this->print_category( 'Suspicious 11:59 PM End', $suspicious_end_time, $limit, $format );

		if ( 0 === $total_issues ) {
			\WP_CLI::success( 'No time issues found.' );
		} else {
			\WP_CLI::warning( sprintf( '%d time issue(s) found across %d events.', $total_issues, count( $events ) ) );
		}
	}

	private function print_category( string $label, array $items, int $limit, string $format ): void {
		$count = count( $items );
		\WP_CLI::log( "--- {$label} ({$count}) ---" );

		if ( empty( $items ) ) {
			\WP_CLI::log( 'None.' );
			\WP_CLI::log( '' );
			return;
		}

		$table = array();
		foreach ( array_slice( $items, 0, $limit ) as $item ) {
			$table[] = array(
				'ID'    => $item['id'],
				'Title' => mb_substr( $item['title'], 0, 45 ),
				'Date'  => $item['date'],
				'Venue' => mb_substr( $item['venue'], 0, 25 ),
			);
		}

		$this->output_results( $table, $format );

		if ( $count > $limit ) {
			\WP_CLI::log( sprintf( '... and %d more', $count - $limit ) );
		}

		\WP_CLI::log( '' );
	}

	private function is_late_night( string $time ): bool {
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

	private function is_suspicious_end( string $time ): bool {
		if ( empty( $time ) ) {
			return false;
		}

		return '23:59' === $time || '23:59:00' === $time;
	}
}
