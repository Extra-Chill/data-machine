<?php
/**
 * WP-CLI command for event title noise repair.
 *
 * Wraps TitleNoiseRepairAbilities for CLI consumption. Strips schedule and
 * price noise ("6-9pm", "Door @ 7. Show @ 8.", "$20") from published event
 * titles (issue #932). Companion to the upsert-time cleaner: the upsert path
 * stops new noisy titles, this repairs existing ones.
 *
 * Usage examples:
 *   wp data-machine-events repair-title-noise
 *   wp data-machine-events repair-title-noise --execute
 *   wp data-machine-events repair-title-noise --scope=all --format=json
 *
 * @package DataMachineEvents\Cli
 * @since   0.68.0
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\TitleNoiseRepairAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TitleNoiseRepairCommand {

	/**
	 * Strip schedule and price noise from published event titles.
	 *
	 * Dry run by default. Only post_title changes. The end time and price the
	 * title carried are shown for review but not written, so stored
	 * structured fields are never overwritten by a guess.
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Actually apply the changes. Default is dry-run mode.
	 *
	 * [--limit=<number>]
	 * : Maximum events to scan. Default: all (-1).
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
	 * [--format=<format>]
	 * : Output format (table or json). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview what would change (dry run)
	 *     $ wp data-machine-events repair-title-noise
	 *
	 *     # Apply to upcoming events
	 *     $ wp data-machine-events repair-title-noise --execute
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

		$abilities = new TitleNoiseRepairAbilities();
		$result    = $abilities->executeRepair(
			array(
				'dry_run' => ! $execute,
				'limit'   => (int) ( $assoc_args['limit'] ?? -1 ),
				'scope'   => $assoc_args['scope'] ?? 'upcoming',
			)
		);

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$mode = $execute ? 'EXECUTE' : 'DRY RUN';
		\WP_CLI::log( "Mode: {$mode}" );
		\WP_CLI::log( sprintf( 'Scanned: %d', $result['scanned'] ?? 0 ) );
		\WP_CLI::log( sprintf( 'Noisy titles: %d', $result['repaired'] ?? 0 ) );
		\WP_CLI::log( '' );

		if ( ! empty( $result['changes'] ) ) {
			$rows = array();
			foreach ( $result['changes'] as $change ) {
				$rows[] = array(
					'ID'    => $change['post_id'] ?? 0,
					'Old'   => mb_substr( (string) ( $change['old_title'] ?? '' ), 0, 50 ),
					'New'   => mb_substr( (string) ( $change['new_title'] ?? '' ), 0, 40 ),
					'End'   => $change['end_time'] ?? '',
					'Price' => $change['price'] ?? '',
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Old', 'New', 'End', 'Price' ) );
			\WP_CLI::log( '' );
		}

		\WP_CLI::log( $result['message'] ?? '' );
	}
}
