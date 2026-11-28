<?php
/**
 * WP-CLI command for series-end leak repair.
 *
 * Wraps SeriesEndRepairAbilities for CLI consumption. Detects and repairs
 * published events whose Event Details block carries a series-wide endDate
 * (recurring series / tour final date) with no occurrenceDates — the
 * ingestion half of issue #199.
 *
 * Usage examples:
 *   wp data-machine-events repair-series-ends
 *   wp data-machine-events repair-series-ends --execute
 *   wp data-machine-events repair-series-ends --max-span-hours=48 --execute
 *   wp data-machine-events repair-series-ends --scope=all --format=json
 *
 * @package DataMachineEvents\Cli
 * @since   0.64.0
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\SeriesEndRepairAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeriesEndRepairCommand {

	/**
	 * Detect and strip fabricated series-range ends from published occurrences.
	 *
	 * Dry run by default. Strips the endDate/endTime block attributes; the
	 * save_post sync then rewrites the datamachine_event_dates row with a
	 * NULL end. No replacement end is invented.
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Actually apply the changes. Default is dry-run mode.
	 *
	 * [--limit=<number>]
	 * : Maximum events to process. Default: all (-1).
	 *
	 * [--scope=<scope>]
	 * : Which published events to scan.
	 * ---
	 * default: upcoming
	 * options:
	 *   - upcoming
	 *   - past
	 *   - all
	 * ---
	 *
	 * [--max-span-hours=<hours>]
	 * : Strip ends on occurrences spanning more than this many hours with
	 * no occurrenceDates.
	 * ---
	 * default: 48
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format (table or json). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview what would change (dry run)
	 *     $ wp data-machine-events repair-series-ends
	 *
	 *     # Apply to all upcoming published occurrences
	 *     $ wp data-machine-events repair-series-ends --execute
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// wp-cli stubs unconditionally define WP_CLI, so static analysis cannot
		// see this guard; argv distinguishes the real CLI runtime.
		if ( empty( $_SERVER['argv'] ) ) {
			return;
		}

		$execute = isset( $assoc_args['execute'] );

		$abilities = new SeriesEndRepairAbilities();
		$result    = $abilities->executeRepair(
			array(
				'dry_run'        => ! $execute,
				'limit'          => (int) ( $assoc_args['limit'] ?? -1 ),
				'scope'          => $assoc_args['scope'] ?? 'upcoming',
				'max_span_hours' => (int) ( $assoc_args['max-span-hours'] ?? 48 ),
			)
		);

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$mode = $execute ? 'EXECUTE' : 'DRY RUN';
		\WP_CLI::log( "Mode: {$mode}" );
		\WP_CLI::log( sprintf( 'Scanned: %d', $result['scanned'] ?? 0 ) );
		\WP_CLI::log( sprintf(
			'Flagged (span > %d hours, no occurrenceDates): %d',
			$result['max_span_hours'] ?? 48,
			$result['repaired'] ?? 0
		) );
		\WP_CLI::log( '' );

		if ( ! empty( $result['changes'] ) ) {
			$rows = array();
			foreach ( $result['changes'] as $change ) {
				$rows[] = array(
					'ID'    => $change['post_id'] ?? 0,
					'Title' => mb_substr( (string) ( $change['title'] ?? '' ), 0, 45 ),
					'Strip' => (string) ( $change['stripped_end'] ?? '' ),
					'Hours' => (int) ( $change['span_hours'] ?? 0 ),
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Title', 'Strip', 'Hours' ) );
			\WP_CLI::log( '' );
		}

		\WP_CLI::log( $result['message'] ?? '' );

		if ( ! $execute && ( $result['repaired'] ?? 0 ) > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry run only — re-run with --execute to strip these ends.' );
		}
	}
}
