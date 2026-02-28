<?php
/**
 * WP-CLI command for ticket URL resync
 *
 * Wraps TicketUrlResyncAbilities for CLI consumption. Re-normalizes ticket URL
 * meta from block content to recover from the v0.8.39 normalization bug.
 *
 * Usage examples:
 *   wp data-machine-events resync-ticket-urls
 *   wp data-machine-events resync-ticket-urls --execute
 *   wp data-machine-events resync-ticket-urls --future-only --execute
 *   wp data-machine-events resync-ticket-urls --limit=50 --execute
 *
 * @package DataMachineEvents\Cli
 * @since 0.10.11
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\TicketUrlResyncAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TicketUrlResyncCommand {

	private const DEFAULT_LIMIT = -1;

	/**
	 * Re-normalize ticket URL meta from block content.
	 *
	 * Recovers from the v0.8.39 bug that stripped identity parameters from
	 * affiliate URLs (e.g., Ticketmaster evyy.net ?u= parameter).
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Actually apply the changes. Default is dry-run mode.
	 *
	 * [--future-only]
	 * : Only process events with future start dates.
	 *
	 * [--limit=<number>]
	 * : Maximum events to process. Default: all (-1).
	 *
	 * [--format=<format>]
	 * : Output format (table or json). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview what would change (dry run)
	 *     $ wp data-machine-events resync-ticket-urls
	 *
	 *     # Apply changes to all events
	 *     $ wp data-machine-events resync-ticket-urls --execute
	 *
	 *     # Only fix future events
	 *     $ wp data-machine-events resync-ticket-urls --future-only --execute
	 *
	 *     # Test with limited batch
	 *     $ wp data-machine-events resync-ticket-urls --limit=50 --execute
	 *
	 *     # JSON output for scripting
	 *     $ wp data-machine-events resync-ticket-urls --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$execute     = isset( $assoc_args['execute'] );
		$dry_run     = ! $execute;
		$future_only = isset( $assoc_args['future-only'] );
		$limit       = (int) ( $assoc_args['limit'] ?? self::DEFAULT_LIMIT );
		$format      = $assoc_args['format'] ?? 'table';

		$abilities = new TicketUrlResyncAbilities();
		$result    = $abilities->executeResync(
			array(
				'dry_run'     => $dry_run,
				'limit'       => $limit,
				'future_only' => $future_only,
			)
		);

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
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
		\WP_CLI::log( '' );

		$changes = $result['changes'] ?? array();

		if ( empty( $changes ) ) {
			\WP_CLI::log( 'No ticket URLs need updating.' );
			\WP_CLI::log( "Skipped: {$result['skipped']}" );
			return;
		}

		$table_data = array();
		foreach ( $changes as $change ) {
			$table_data[] = array(
				'ID'    => $change['post_id'],
				'Title' => mb_substr( $change['title'], 0, 40 ),
				'Old'   => mb_substr( $change['old'], 0, 50 ),
				'New'   => mb_substr( $change['new'], 0, 50 ),
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$table_data,
			array( 'ID', 'Title', 'Old', 'New' )
		);

		\WP_CLI::log( '' );
		\WP_CLI::success( $result['message'] );

		if ( $dry_run && $result['updated'] > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( 'This was a dry run. Add --execute to apply changes.' );
		}
	}
}
