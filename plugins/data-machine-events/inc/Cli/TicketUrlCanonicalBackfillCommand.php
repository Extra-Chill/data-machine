<?php
/**
 * WP-CLI command for the canonical ticket URL backfill
 *
 * Wraps TicketUrlCanonicalBackfillAbilities for CLI consumption. Converts
 * wrapper-stored affiliate ticket URLs to canonical vendor URLs in event
 * block content (issue #818). Dry-run by default.
 *
 * Usage examples:
 *   wp data-machine-events backfill-canonical-ticket-urls
 *   wp data-machine-events backfill-canonical-ticket-urls --execute
 *   wp data-machine-events backfill-canonical-ticket-urls --future-only --execute
 *   wp data-machine-events backfill-canonical-ticket-urls --limit=500 --execute
 *
 * Recommended rollout (issue #818): dry-run first, then a `--future-only
 * --execute` pass over the ~24,941 live revenue-bearing events, then the
 * archival tail in slower passes. The run is idempotent — re-running after
 * an interruption skips already-converted rows — and every converted row
 * provably round-trips byte-identically, so reverting means re-storing the
 * old wrapper from the change log (or simply leaving the row canonical:
 * both shapes resolve identically).
 *
 * @package DataMachineEvents\Cli
 * @since 0.62.1
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\TicketUrlCanonicalBackfillAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TicketUrlCanonicalBackfillCommand {

	private const DEFAULT_LIMIT = -1;

	/**
	 * Convert wrapper-stored affiliate ticket URLs to canonical URLs.
	 *
	 * ## OPTIONS
	 *
	 * [--execute]
	 * : Actually apply the changes. Default is dry-run mode.
	 *
	 * [--future-only]
	 * : Only process events with future start dates (the live, revenue-
	 *   bearing slice — recommended first pass).
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
	 *     $ wp data-machine-events backfill-canonical-ticket-urls
	 *
	 *     # First live pass: future events only
	 *     $ wp data-machine-events backfill-canonical-ticket-urls --future-only --execute
	 *
	 *     # Chunked execution
	 *     $ wp data-machine-events backfill-canonical-ticket-urls --limit=500 --execute
	 *
	 *     # JSON output for scripting
	 *     $ wp data-machine-events backfill-canonical-ticket-urls --format=json
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

		$execute     = isset( $assoc_args['execute'] );
		$dry_run     = ! $execute;
		$future_only = isset( $assoc_args['future-only'] );
		$limit       = (int) ( $assoc_args['limit'] ?? self::DEFAULT_LIMIT );
		$format      = $assoc_args['format'] ?? 'table';

		$abilities = new TicketUrlCanonicalBackfillAbilities();
		$result    = $abilities->executeBackfill(
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
			\WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->outputTable( $result, $dry_run );
	}

	/**
	 * Output results as formatted tables.
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
			\WP_CLI::log( 'No ticket URLs need converting.' );
		} else {
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
		}

		$report = $result['report'] ?? array();
		if ( ! empty( $report ) ) {
			\WP_CLI::log( 'Not converted (reported):' );
			$report_data = array();
			foreach ( $report as $item ) {
				$report_data[] = array(
					'ID'     => $item['post_id'],
					'Reason' => $item['reason'],
					'URL'    => mb_substr( $item['url'], 0, 70 ),
				);
			}
			\WP_CLI\Utils\format_items(
				'table',
				$report_data,
				array( 'ID', 'Reason', 'URL' )
			);
			\WP_CLI::log( '' );
		}

		\WP_CLI::success( $result['message'] );

		if ( $dry_run && $result['updated'] > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( 'This was a dry run. Add --execute to apply changes.' );
		}
	}
}
