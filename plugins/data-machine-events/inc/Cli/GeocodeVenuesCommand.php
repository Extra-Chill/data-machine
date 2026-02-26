<?php
/**
 * WP-CLI command for venue geocoding
 *
 * Thin wrapper that delegates to geocoding abilities.
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\GeocodingAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GeocodeVenuesCommand {

	/**
	 * Batch geocode venues missing coordinates.
	 *
	 * ## OPTIONS
	 *
	 * [--venue-id=<id>]
	 * : Geocode a specific venue by term ID.
	 *
	 * [--force]
	 * : Re-geocode even if coordinates already exist.
	 *
	 * [--dry-run]
	 * : Show what would be geocoded without doing it.
	 *
	 * [--limit=<number>]
	 * : Max venues to process (default: 50).
	 *
	 * [--format=<format>]
	 * : Output format (table or json). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Geocode all venues missing coordinates
	 *     wp datamachine-events geocode-venues
	 *
	 *     # Geocode a specific venue
	 *     wp datamachine-events geocode-venues --venue-id=3597
	 *
	 *     # Dry run to see what would be processed
	 *     wp datamachine-events geocode-venues --dry-run
	 *
	 *     # Force re-geocode all venues
	 *     wp datamachine-events geocode-venues --force --limit=10
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$input = array(
			'force'   => isset( $assoc_args['force'] ),
			'dry_run' => isset( $assoc_args['dry-run'] ),
		);

		if ( isset( $assoc_args['venue-id'] ) ) {
			$input['venue_id'] = (int) $assoc_args['venue-id'];
		}

		if ( isset( $assoc_args['limit'] ) ) {
			$input['limit'] = (int) $assoc_args['limit'];
		}

		$abilities = new GeocodingAbilities();
		$result    = $abilities->executeGeocodeVenues( $input );

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		// Table output.
		$results = $result['results'] ?? array();

		if ( empty( $results ) ) {
			\WP_CLI::success( 'No venues to process. ' . ( $result['message'] ?? '' ) );
			return;
		}

		$table_data = array();
		foreach ( $results as $item ) {
			$row = array(
				'ID'      => $item['term_id'],
				'Name'    => mb_substr( $item['name'], 0, 35 ),
				'Address' => mb_substr( $item['address'] ?? '', 0, 40 ),
				'Action'  => $item['action'],
			);

			if ( isset( $item['coordinates'] ) ) {
				$row['Coordinates'] = $item['coordinates'];
			}

			$table_data[] = $row;
		}

		$columns = array( 'ID', 'Name', 'Address', 'Action' );
		if ( ! $input['dry_run'] ) {
			$columns[] = 'Coordinates';
		}

		\WP_CLI\Utils\format_items( 'table', $table_data, $columns );
		\WP_CLI::log( '' );
		\WP_CLI::success( $result['message'] );
	}
}
