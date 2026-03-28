<?php
/**
 * Check events for Unicode encoding issues.
 *
 * Detects escaped unicode sequences like \u00a3 that should be
 * rendered as actual characters.
 *
 * Usage:
 *   wp data-machine-events check encoding
 *   wp data-machine-events check encoding --scope=all
 *   wp data-machine-events check encoding --format=json
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.14.0
 */

namespace DataMachineEvents\Cli\Check;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckEncodingCommand {

	use EventQueryTrait;

	/**
	 * Check events for Unicode encoding issues.
	 *
	 * Scans event block attributes for escaped unicode sequences
	 * that should have been decoded during import.
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
	 * : Max events to show.
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
	 *     wp data-machine-events check encoding
	 *     wp data-machine-events check encoding --scope=all --format=json
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

		$invalid_encoding = array();

		foreach ( $events as $event ) {
			$attrs           = $this->extract_block_attributes( $event->ID );
			$encoding_fields = $this->check_encoding( $attrs );

			if ( ! empty( $encoding_fields ) ) {
				$venue_name         = $this->get_venue_name( $event->ID );
				$info               = $this->build_event_info( $event, $attrs, $venue_name );
				$info['fields']     = implode( ', ', $encoding_fields );
				$invalid_encoding[] = $info;
			}
		}

		$this->sort_by_date( $invalid_encoding, $scope );

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode(
				array(
					'total_scanned'    => count( $events ),
					'scope'            => $scope,
					'invalid_encoding' => array_slice( $invalid_encoding, 0, $limit ),
				),
				JSON_PRETTY_PRINT
			) );
			return;
		}

		\WP_CLI::log( sprintf( 'Scanned %d events (%s scope)', count( $events ), $scope ) );
		\WP_CLI::log( '' );

		\WP_CLI::log( sprintf( '--- Invalid Unicode Encoding (%d) ---', count( $invalid_encoding ) ) );

		if ( empty( $invalid_encoding ) ) {
			\WP_CLI::success( 'No encoding issues found.' );
			return;
		}

		$table = array();
		foreach ( array_slice( $invalid_encoding, 0, $limit ) as $item ) {
			$table[] = array(
				'ID'     => $item['id'],
				'Title'  => mb_substr( $item['title'], 0, 40 ),
				'Date'   => $item['date'],
				'Venue'  => mb_substr( $item['venue'], 0, 20 ),
				'Fields' => $item['fields'],
			);
		}

		$this->output_results( $table, $format, array( 'ID', 'Title', 'Date', 'Venue', 'Fields' ) );

		if ( count( $invalid_encoding ) > $limit ) {
			\WP_CLI::log( sprintf( '... and %d more', count( $invalid_encoding ) - $limit ) );
		}

		\WP_CLI::log( '' );
		\WP_CLI::warning( sprintf( '%d event(s) with encoding issues.', count( $invalid_encoding ) ) );
		\WP_CLI::log( 'Fix with: wp data-machine-events fix-encoding' );
	}

	/**
	 * Check for escaped unicode sequences in block attributes.
	 *
	 * @param array $attrs Block attributes.
	 * @return array Affected field names.
	 */
	private function check_encoding( array $attrs ): array {
		$fields_to_check = array( 'price', 'venue', 'address', 'performer', 'organizer' );
		$affected        = array();

		foreach ( $fields_to_check as $field ) {
			if ( empty( $attrs[ $field ] ) ) {
				continue;
			}
			if ( preg_match( '/\\\\u[0-9a-fA-F]{4}/', $attrs[ $field ] ) ) {
				$affected[] = $field;
			}
		}

		return $affected;
	}
}
