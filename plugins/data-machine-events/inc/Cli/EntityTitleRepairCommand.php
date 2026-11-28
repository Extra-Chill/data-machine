<?php
/**
 * WP-CLI command for entity-bearing title repair.
 *
 * Wraps EntityTitleRepairAbilities for CLI consumption. Detects published
 * events whose post_title stores HTML entity references (issue #844) and
 * decodes them. Companion to the ingestion decode contract: the upsert path
 * stops new entity-bearing rows, this repairs existing ones.
 *
 * Usage examples:
 *   wp data-machine-events repair-entity-titles
 *   wp data-machine-events repair-entity-titles --execute
 *   wp data-machine-events repair-entity-titles --scope=upcoming --format=json
 *
 * @package DataMachineEvents\Cli
 * @since   0.65.0
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\EntityTitleRepairAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntityTitleRepairCommand {

	/**
	 * Decode HTML entities stored in published event titles.
	 *
	 * Dry run by default. The replacement title is the entity-decoded UTF-8
	 * of the stored title; title-derived dedup hashes are verified to be
	 * unchanged (they hash the entity-decoded form already) and any drift is
	 * reported rather than silently rewritten.
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
	 * default: all
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
	 *     $ wp data-machine-events repair-entity-titles
	 *
	 *     # Apply to all published events
	 *     $ wp data-machine-events repair-entity-titles --execute
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

		$abilities = new EntityTitleRepairAbilities();
		$result    = $abilities->executeRepair(
			array(
				'dry_run' => ! $execute,
				'limit'   => (int) ( $assoc_args['limit'] ?? -1 ),
				'scope'   => $assoc_args['scope'] ?? 'all',
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
		\WP_CLI::log( sprintf( 'Entity-bearing titles: %d', $result['repaired'] ?? 0 ) );
		\WP_CLI::log( '' );

		if ( ! empty( $result['changes'] ) ) {
			$rows = array();
			foreach ( $result['changes'] as $change ) {
				$rows[] = array(
					'ID'      => $change['post_id'] ?? 0,
					'Old'     => mb_substr( (string) ( $change['old_title'] ?? '' ), 0, 45 ),
					'New'     => mb_substr( (string) ( $change['new_title'] ?? '' ), 0, 45 ),
					'Hash OK' => ( $change['hash_stable'] ?? false ) ? 'yes' : 'DRIFT',
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Old', 'New', 'Hash OK' ) );
			\WP_CLI::log( '' );
		}

		\WP_CLI::log( $result['message'] ?? '' );

		if ( ! $execute && ( $result['repaired'] ?? 0 ) > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry run only — re-run with --execute to decode these titles.' );
		}

		if ( ( $result['identity_hash_drift'] ?? 0 ) > 0 ) {
			\WP_CLI::warning( 'Title hash drift detected — investigate identity index rows before trusting dedup.' );
		}
	}
}
