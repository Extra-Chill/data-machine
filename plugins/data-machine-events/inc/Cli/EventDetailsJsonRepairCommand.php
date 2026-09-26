<?php
/**
 * WP-CLI: repair Event Details blocks with corrupted comment JSON (#870).
 *
 *   wp data-machine-events repair-event-details-json
 *   wp data-machine-events repair-event-details-json --execute
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\BlockAttributeJsonRepairAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventDetailsJsonRepairCommand {

	/**
	 * Recover Event Details attributes whose comment JSON lost its escaping.
	 *
	 * Dry run by default.
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Apply the repair.
	 *
	 * [--limit=<number>]
	 * : Maximum events to repair. Default: all.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( empty( $_SERVER['argv'] ) ) {
			return;
		}
		$execute = isset( $assoc_args['execute'] );
		$result  = ( new BlockAttributeJsonRepairAbilities() )->executeRepair(
			array(
				'dry_run' => ! $execute,
				'limit'   => (int) ( $assoc_args['limit'] ?? -1 ),
			)
		);
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}
		\WP_CLI::log( 'Mode: ' . ( $execute ? 'EXECUTE' : 'DRY RUN' ) );
		if ( ! empty( $result['changes'] ) ) {
			$rows = array();
			foreach ( $result['changes'] as $change ) {
				$rows[] = array(
					'ID'        => $change['post_id'],
					'Title'     => mb_substr( (string) $change['title'], 0, 45 ),
					'Performer' => mb_substr( (string) $change['performer'], 0, 40 ),
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Title', 'Performer' ) );
		}
		\WP_CLI::log( $result['message'] );
		if ( ! $execute && $result['repaired'] > 0 ) {
			\WP_CLI::log( 'Dry run only — re-run with --execute to apply.' );
		}
	}
}
