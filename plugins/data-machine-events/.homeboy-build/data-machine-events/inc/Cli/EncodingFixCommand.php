<?php
/**
 * WP-CLI command for fixing encoding issues
 *
 * Wraps EncodingFixAbilities for CLI consumption. Enables programmatic
 * repair of events with escaped unicode sequences in block attributes.
 *
 * Usage examples:
 *   wp data-machine-events fix-encoding --dry-run
 *   wp data-machine-events fix-encoding --execute
 *   wp data-machine-events fix-encoding --scope=all --execute
 *
 * @package DataMachineEvents\Cli
 * @since 0.10.10
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\EncodingFixAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EncodingFixCommand {

	private const DEFAULT_LIMIT = 100;

	/**
	 * Fix Unicode encoding issues in event block attributes.
	 *
	 * Detects and repairs escaped unicode sequences like \u00a3 to proper
	 * UTF-8 characters (e.g., currency symbols).
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scope>]
	 * : Which events to scan: "upcoming" (default), "all", or "past".
	 *
	 * [--limit=<number>]
	 * : Maximum events to process. Default: 100.
	 *
	 * [--dry-run]
	 * : Preview changes without applying. Default behavior if neither --dry-run nor --execute specified.
	 *
	 * [--execute]
	 * : Actually apply the fixes. Use after verifying with --dry-run.
	 *
	 * [--format=<format>]
	 * : Output format (table or json). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview encoding fixes for upcoming events
	 *     $ wp data-machine-events fix-encoding --dry-run
	 *
	 *     # Apply fixes to first 10 events
	 *     $ wp data-machine-events fix-encoding --execute --limit=10
	 *
	 *     # Check all events (past and future)
	 *     $ wp data-machine-events fix-encoding --scope=all --dry-run
	 *
	 *     # Get JSON output for scripting
	 *     $ wp data-machine-events fix-encoding --scope=upcoming --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$scope   = $assoc_args['scope'] ?? 'upcoming';
		$limit   = (int) ( $assoc_args['limit'] ?? self::DEFAULT_LIMIT );
		$format  = $assoc_args['format'] ?? 'table';
		$execute = isset( $assoc_args['execute'] );
		$dry_run = ! $execute;

		$valid_scopes = array( 'upcoming', 'all', 'past' );
		if ( ! in_array( $scope, $valid_scopes, true ) ) {
			\WP_CLI::error( 'Invalid scope. Must be one of: ' . implode( ', ', $valid_scopes ) );
		}

		$abilities = new EncodingFixAbilities();
		$result    = $abilities->executeEncodingFix(
			array(
				'scope'   => $scope,
				'limit'   => $limit,
				'dry_run' => $dry_run,
			)
		);

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
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
		\WP_CLI::log( 'Total Scanned: ' . $data['total_scanned'] );
		\WP_CLI::log( 'Encoding Issues Found: ' . $data['total_matched'] );
		\WP_CLI::log( '' );

		$events = $data['events'] ?? array();

		if ( empty( $events ) ) {
			\WP_CLI::success( 'No encoding issues found.' );
			return;
		}

		$table_data = array();
		foreach ( $events as $event ) {
			$changes_preview = array();
			foreach ( $event['changes'] as $field => $change ) {
				$before            = mb_substr( $change['before'], 0, 20 );
				$after             = mb_substr( $change['after'], 0, 20 );
				$changes_preview[] = "{$field}: {$before} -> {$after}";
			}

			$table_data[] = array(
				'ID'      => $event['id'],
				'Title'   => mb_substr( $event['title'], 0, 35 ),
				'Fields'  => implode( ', ', $event['affected_fields'] ),
				'Preview' => implode( '; ', $changes_preview ),
				'Status'  => $event['status'],
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$table_data,
			array( 'ID', 'Title', 'Fields', 'Preview', 'Status' )
		);

		\WP_CLI::log( '' );
		\WP_CLI::log( $data['message'] );

		if ( $dry_run && $data['total_matched'] > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( 'This was a dry run. Add --execute to apply fixes.' );
		}
	}
}
