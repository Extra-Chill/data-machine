<?php
/**
 * WP-CLI command for affiliate redirect repair
 *
 * Wraps AffiliateRedirectRepairAbilities for CLI consumption. Detects and
 * repairs published events whose affiliate redirect parameter lost its URL
 * punctuation (e.g. `?u=httpswww.ticketmaster.comevent...`).
 *
 * Usage examples:
 *   wp data-machine-events repair-affiliate-redirects
 *   wp data-machine-events repair-affiliate-redirects --execute
 *   wp data-machine-events repair-affiliate-redirects --limit=50 --execute
 *   wp data-machine-events repair-affiliate-redirects --scope=past
 *
 * @package DataMachineEvents\Cli
 * @since 0.63.0
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\AffiliateRedirectRepairAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AffiliateRedirectRepairCommand {

	private const DEFAULT_LIMIT = -1;

	/**
	 * Detect and repair corrupted affiliate redirect destinations.
	 *
	 * Dry run by default. Rows whose destination cannot be reconstructed
	 * with confidence are skipped and reported, never guessed.
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
	 *   - all
	 *   - upcoming
	 *   - past
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format (table or json). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview what would change (dry run)
	 *     $ wp data-machine-events repair-affiliate-redirects
	 *
	 *     # Apply changes to all events
	 *     $ wp data-machine-events repair-affiliate-redirects --execute
	 *
	 *     # Test with limited batch
	 *     $ wp data-machine-events repair-affiliate-redirects --limit=50 --execute
	 *
	 *     # JSON output for scripting
	 *     $ wp data-machine-events repair-affiliate-redirects --format=json
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
		$dry_run = ! $execute;
		$limit   = (int) ( $assoc_args['limit'] ?? self::DEFAULT_LIMIT );
		$scope   = $assoc_args['scope'] ?? 'all';
		$format  = $assoc_args['format'] ?? 'table';

		$abilities = new AffiliateRedirectRepairAbilities();
		$result    = $abilities->executeRepair(
			array(
				'dry_run' => $dry_run,
				'limit'   => $limit,
				'scope'   => $scope,
			)
		);

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->outputTable( $result, $dry_run );
	}

	/**
	 * Output results as formatted table.
	 *
	 * @param array $result  Result data from abilities.
	 * @param bool  $dry_run Whether this was a dry run.
	 */
	private function outputTable( array $result, bool $dry_run ): void {
		$mode = $dry_run ? 'DRY RUN' : 'EXECUTE';
		\WP_CLI::log( "Mode: {$mode}" );
		\WP_CLI::log( sprintf( 'Scanned: %d', $result['scanned'] ?? 0 ) );
		\WP_CLI::log( '' );

		$changes = $result['changes'] ?? array();
		$skipped = $result['skipped'] ?? array();

		if ( empty( $changes ) && empty( $skipped ) ) {
			\WP_CLI::success( 'No corrupted affiliate redirect URLs found.' );
			return;
		}

		if ( ! empty( $changes ) ) {
			$table_data = array();
			foreach ( $changes as $change ) {
				$table_data[] = array(
					'ID'          => $change['post_id'],
					'Title'       => mb_substr( $change['title'], 0, 40 ),
					'Attribute'   => $change['attribute'],
					'Old'         => mb_substr( $change['old'], 0, 50 ),
					'New'         => mb_substr( $change['new'], 0, 50 ),
					'Destination' => mb_substr( $change['destination'], 0, 50 ),
				);
			}

			\WP_CLI\Utils\format_items(
				'table',
				$table_data,
				array( 'ID', 'Title', 'Attribute', 'Old', 'New', 'Destination' )
			);
			\WP_CLI::log( '' );
		}

		if ( ! empty( $skipped ) ) {
			\WP_CLI::log( '--- Skipped as ambiguous (never guessed) ---' );
			$skipped_rows = array();
			foreach ( $skipped as $row ) {
				$skipped_rows[] = array(
					'ID'        => $row['post_id'],
					'Title'     => mb_substr( $row['title'], 0, 40 ),
					'Attribute' => $row['attribute'],
					'Value'     => mb_substr( $row['value'], 0, 60 ),
					'Reason'    => $row['reason'],
				);
			}
			\WP_CLI\Utils\format_items(
				'table',
				$skipped_rows,
				array( 'ID', 'Title', 'Attribute', 'Value', 'Reason' )
			);
			\WP_CLI::log( '' );
		}

		\WP_CLI::success( $result['message'] );

		if ( $dry_run && ( $result['repaired'] ?? 0 ) > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( 'This was a dry run. Add --execute to apply changes.' );
		}
	}
}
