<?php
/**
 * WP-CLI command for batch time fixes
 *
 * Wraps BatchTimeFixAbilities for CLI consumption. Enables programmatic
 * correction of events with systematic timezone or offset issues.
 *
 * Usage examples:
 *   wp datamachine-events batch-time-fix --venue="Armadillo Den" --before="2026-01-15" --offset="+6h" --dry-run
 *   wp datamachine-events batch-time-fix --venue="Continental Club,Starlight" --after="2025-12-01" --offset="-1h"
 *   wp datamachine-events batch-time-fix --venue="Venue Name" --before="2026-01-01" --where-time="01:00" --new-time="19:00"
 *
 * @package DataMachineEvents\Cli
 * @since 0.9.16
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\BatchTimeFixAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BatchTimeFixCommand {

	private const DEFAULT_LIMIT = 100;

	/**
	 * Fix event times in batch with offset correction or explicit replacement.
	 *
	 * ## OPTIONS
	 *
	 * --venue=<venues>
	 * : Required. Venue name(s) to filter by, comma-separated for multiple.
	 *
	 * [--before=<date>]
	 * : Filter events imported before this date (YYYY-MM-DD). At least one of before/after required.
	 *
	 * [--after=<date>]
	 * : Filter events imported after this date (YYYY-MM-DD). At least one of before/after required.
	 *
	 * [--source-pattern=<pattern>]
	 * : Filter by source URL pattern (SQL LIKE syntax, e.g., %.ics).
	 *
	 * [--where-time=<time>]
	 * : Only fix events with this specific current startTime (HH:MM).
	 *
	 * [--offset=<offset>]
	 * : Time offset to apply (e.g., +6h, -1h, +30m). Either offset or new-time required.
	 *
	 * [--new-time=<time>]
	 * : Explicit new time to set (HH:MM). Requires --where-time.
	 *
	 * [--dry-run]
	 * : Preview changes without applying. Default behavior if neither --dry-run nor --execute specified.
	 *
	 * [--execute]
	 * : Actually apply the changes. Use after verifying with --dry-run.
	 *
	 * [--limit=<number>]
	 * : Maximum events to process. Default: 100.
	 *
	 * [--format=<format>]
	 * : Output format (table or json). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview fixes for events at Armadillo Den imported before Jan 15, applying +6h offset
	 *     $ wp datamachine-events batch-time-fix --venue="Armadillo Den" --before="2026-01-15" --offset="+6h" --dry-run
	 *
	 *     # Fix multiple venues with same offset
	 *     $ wp datamachine-events batch-time-fix --venue="Armadillo Den,Continental Club" --before="2026-01-15" --offset="+6h" --execute
	 *
	 *     # Filter by source URL pattern (ICS feeds)
	 *     $ wp datamachine-events batch-time-fix --venue="Starlight Motor Inn" --before="2026-01-15" --source-pattern="%.ics" --offset="+6h"
	 *
	 *     # Replace specific time with explicit value
	 *     $ wp datamachine-events batch-time-fix --venue="Some Venue" --before="2026-01-01" --where-time="01:00" --new-time="19:00" --execute
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$venue          = $assoc_args['venue'] ?? '';
		$before         = $assoc_args['before'] ?? '';
		$after          = $assoc_args['after'] ?? '';
		$source_pattern = $assoc_args['source-pattern'] ?? '';
		$where_time     = $assoc_args['where-time'] ?? '';
		$offset         = $assoc_args['offset'] ?? '';
		$new_time       = $assoc_args['new-time'] ?? '';
		$limit          = (int) ( $assoc_args['limit'] ?? self::DEFAULT_LIMIT );
		$format         = $assoc_args['format'] ?? 'table';

		$execute = isset( $assoc_args['execute'] );
		$dry_run = ! $execute;

		if ( empty( $venue ) ) {
			\WP_CLI::error( '--venue parameter is required' );
		}

		if ( empty( $before ) && empty( $after ) ) {
			\WP_CLI::error( 'At least one date filter (--before or --after) is required' );
		}

		if ( empty( $offset ) && empty( $new_time ) ) {
			\WP_CLI::error( 'Either --offset or --new-time parameter is required' );
		}

		if ( ! empty( $new_time ) && empty( $where_time ) ) {
			\WP_CLI::error( '--where-time is required when using --new-time (to prevent accidental overwrites)' );
		}

		$abilities = new BatchTimeFixAbilities();
		$result    = $abilities->executeBatchTimeFix(
			array(
				'venue'          => $venue,
				'before'         => $before,
				'after'          => $after,
				'source_pattern' => $source_pattern,
				'where_time'     => $where_time,
				'offset'         => $offset,
				'new_time'       => $new_time,
				'dry_run'        => $dry_run,
				'limit'          => $limit,
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
	 * @param array $data   Result data from abilities.
	 * @param bool  $dry_run Whether this was a dry run.
	 */
	private function outputTable( array $data, bool $dry_run ): void {
		$mode = $dry_run ? 'DRY RUN' : 'EXECUTE';
		\WP_CLI::log( "Mode: {$mode}" );
		\WP_CLI::log( 'Total Matched: ' . $data['total_matched'] );
		\WP_CLI::log( '' );

		$events = $data['events'] ?? array();

		if ( empty( $events ) ) {
			\WP_CLI::log( 'No events matched the specified filters.' );
			return;
		}

		$table_data = array();
		foreach ( $events as $event ) {
			$table_data[] = array(
				'ID'           => $event['id'],
				'Title'        => mb_substr( $event['title'], 0, 35 ),
				'Venue'        => mb_substr( $event['venue'] ?? '', 0, 20 ),
				'Date'         => $event['startDate'] ?? 'N/A',
				'Current Time' => $event['current_time'] ?? 'N/A',
				'New Time'     => $event['new_time'] ?? 'N/A',
				'Status'       => $event['status'],
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$table_data,
			array( 'ID', 'Title', 'Venue', 'Date', 'Current Time', 'New Time', 'Status' )
		);

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Summary: ' . $data['message'] );

		if ( $dry_run && $data['total_matched'] > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( 'This was a dry run. Add --execute to apply changes.' );
		}
	}
}
