<?php
/**
 * CLI command for adding a city to the events calendar.
 *
 * Delegates to the datamachine-events/add-city ability.
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\CityAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AddCityCommand {

	/**
	 * Add a city to the events calendar.
	 *
	 * Geocodes the city, creates a pipeline with event_import → AI → update steps,
	 * and sets up Ticketmaster and Dice.fm flows.
	 *
	 * ## OPTIONS
	 *
	 * <city>
	 * : City name with state, e.g. "Nashville, TN" or "Portland, OR"
	 *
	 * [--radius=<miles>]
	 * : Ticketmaster search radius in miles.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--interval=<interval>]
	 * : Scheduling interval for flows.
	 * ---
	 * default: every_6_hours
	 * options:
	 *   - manual
	 *   - hourly
	 *   - every_2_hours
	 *   - every_4_hours
	 *   - every_6_hours
	 *   - every_12_hours
	 *   - daily
	 * ---
	 *
	 * [--skip-dice]
	 * : Skip creating a Dice.fm flow.
	 *
	 * [--dry-run]
	 * : Preview what would be created without making changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-events add-city "Nashville, TN"
	 *     wp datamachine-events add-city "New York, NY" --radius=25
	 *     wp datamachine-events add-city "Portland, OR" --dry-run
	 *     wp datamachine-events add-city "Denver, CO" --interval=every_4_hours --skip-dice
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		$city = $args[0] ?? '';
		if ( empty( $city ) ) {
			\WP_CLI::error( 'City name is required. Example: wp datamachine-events add-city "Nashville, TN"' );
			return;
		}

		$input = array(
			'city'      => $city,
			'radius'    => $assoc_args['radius'] ?? '50',
			'interval'  => $assoc_args['interval'] ?? 'every_6_hours',
			'skip_dice' => isset( $assoc_args['skip-dice'] ),
			'dry_run'   => isset( $assoc_args['dry-run'] ),
		);

		$abilities = new CityAbilities();
		$result    = $abilities->executeAddCity( $input );

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
			return;
		}

		if ( ! empty( $input['dry_run'] ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( '🔍 DRY RUN — Preview:' );
			\WP_CLI::log( '' );
			\WP_CLI::log( '  City:        ' . $result['city'] );
			\WP_CLI::log( '  Label:       ' . $result['city_label'] );
			\WP_CLI::log( '  Coordinates: ' . $result['coordinates'] );
			\WP_CLI::log( '  Resolved:    ' . $result['display_name'] );
			\WP_CLI::log( '  Pipeline:    ' . $result['pipeline'] );
			\WP_CLI::log( '  Interval:    ' . $result['interval'] );
			\WP_CLI::log( '' );
			\WP_CLI::log( '  Flows to create:' );
			foreach ( $result['flows'] as $flow ) {
				\WP_CLI::log( '    - ' . $flow['name'] . ' (' . $flow['handler'] . ')' );
			}
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Run without --dry-run to create.' );
			return;
		}

		\WP_CLI::success( $result['message'] );
		\WP_CLI::log( '' );
		\WP_CLI::log( '  Coordinates: ' . $result['coordinates'] );
		\WP_CLI::log( '  Pipeline ID: ' . $result['pipeline_id'] );
		\WP_CLI::log( '' );

		foreach ( $result['flows'] as $flow ) {
			$status = $flow['status'] ?? 'unknown';
			if ( 'created' === $status ) {
				\WP_CLI::log( '  ✓ ' . $flow['name'] . ' (flow ID: ' . ( $flow['flow_id'] ?? 'n/a' ) . ')' );
			} else {
				\WP_CLI::warning( '  ✗ ' . $flow['name'] . ': ' . ( $flow['error'] ?? 'unknown error' ) );
			}
		}

		\WP_CLI::log( '' );
	}
}
