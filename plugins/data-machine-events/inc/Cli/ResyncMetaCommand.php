<?php
/**
 * WP-CLI command for meta sync repair
 *
 * Wraps MetaSyncAbilities for CLI consumption. Detects events where block
 * attributes exist but post meta sync failed, and repairs them.
 *
 * Usage examples:
 *   wp datamachine-events resync-meta 4222
 *   wp datamachine-events resync-meta 4222,4223,4224
 *   wp datamachine-events resync-meta 4222 --dry-run
 *   wp datamachine-events resync-meta --all-broken
 *   wp datamachine-events resync-meta --all-broken --execute
 *
 * @package DataMachineEvents\Cli
 * @since 0.11.3
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\MetaSyncAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ResyncMetaCommand {

	private const DEFAULT_LIMIT = 100;

	/**
	 * Re-sync event datetime meta from block attributes.
	 *
	 * Fixes events where block has startDate/startTime but post meta
	 * (_datamachine_event_datetime) was never synced.
	 *
	 * ## OPTIONS
	 *
	 * [<event_ids>]
	 * : Comma-separated list of event IDs to resync.
	 *
	 * [--all-broken]
	 * : Find and repair all events with missing meta sync.
	 *
	 * [--dry-run]
	 * : Preview changes without applying them (default).
	 *
	 * [--execute]
	 * : Actually apply the changes.
	 *
	 * [--limit=<number>]
	 * : Maximum events to process when using --all-broken. Default: 100.
	 *
	 * [--format=<format>]
	 * : Output format (table or json). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview fixing a single event (dry run)
	 *     $ wp datamachine-events resync-meta 4222 --dry-run
	 *
	 *     # Fix a single event
	 *     $ wp datamachine-events resync-meta 4222 --execute
	 *
	 *     # Fix multiple specific events
	 *     $ wp datamachine-events resync-meta 4222,4223,4224 --execute
	 *
	 *     # Find all broken events (detection only)
	 *     $ wp datamachine-events resync-meta --all-broken --dry-run
	 *
	 *     # Fix all broken events
	 *     $ wp datamachine-events resync-meta --all-broken --execute
	 *
	 *     # JSON output for scripting
	 *     $ wp datamachine-events resync-meta --all-broken --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$all_broken = isset( $assoc_args['all-broken'] );
		$execute    = isset( $assoc_args['execute'] );
		$dry_run    = ! $execute;
		$limit      = (int) ( $assoc_args['limit'] ?? self::DEFAULT_LIMIT );
		$format     = $assoc_args['format'] ?? 'table';

		$abilities = new MetaSyncAbilities();

		if ( empty( $args ) && ! $all_broken ) {
			\WP_CLI::error( 'Provide event IDs or use --all-broken flag.' );
		}

		$event_ids = array();

		if ( $all_broken ) {
			$find_result = $abilities->executeFindMissingMetaSync( array( 'limit' => $limit ) );
			$event_ids   = array_column( $find_result['events'] ?? array(), 'id' );

			if ( empty( $event_ids ) ) {
				\WP_CLI::success( 'No events found with missing meta sync.' );
				return;
			}

			\WP_CLI::log( "Found {$find_result['count']} events with missing meta sync." );
			\WP_CLI::log( '' );
		} else {
			$event_ids = array_map( 'absint', explode( ',', $args[0] ) );
			$event_ids = array_filter( $event_ids );

			if ( empty( $event_ids ) ) {
				\WP_CLI::error( 'No valid event IDs provided.' );
			}
		}

		$result = $abilities->executeResyncEventMeta(
			array(
				'event_ids' => $event_ids,
				'dry_run'   => $dry_run,
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

		$results = $result['results'] ?? array();

		if ( empty( $results ) ) {
			\WP_CLI::log( 'No events to process.' );
			return;
		}

		$table_data = array();
		foreach ( $results as $item ) {
			$status = $item['success'] ? 'OK' : 'FAILED';
			$error  = $item['error'] ?? '';

			$before_datetime = $item['before']['_datamachine_event_datetime'] ?? '(none)';
			$after_datetime  = $item['after']['_datamachine_event_datetime'] ?? '(none)';

			$table_data[] = array(
				'ID'     => $item['id'],
				'Title'  => mb_substr( $item['title'], 0, 35 ),
				'Status' => $status,
				'Before' => $before_datetime,
				'After'  => $after_datetime,
				'Error'  => mb_substr( $error, 0, 25 ),
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$table_data,
			array( 'ID', 'Title', 'Status', 'Before', 'After', 'Error' )
		);

		\WP_CLI::log( '' );

		$summary = $result['summary'] ?? array();
		$synced  = $summary['synced'] ?? 0;
		$failed  = $summary['failed'] ?? 0;
		$total   = $summary['total'] ?? 0;

		$verb = $dry_run ? 'Would sync' : 'Synced';
		\WP_CLI::log( "{$verb}: {$synced}/{$total}" );

		if ( $failed > 0 ) {
			\WP_CLI::warning( "Failed: {$failed}" );
		}

		if ( $dry_run && $synced > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( 'This was a dry run. Add --execute to apply changes.' );
		} elseif ( ! $dry_run && $synced > 0 ) {
			\WP_CLI::success( 'Meta sync complete.' );
		}
	}
}
