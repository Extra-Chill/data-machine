<?php
/**
 * WP-CLI database migrations.
 *
 * @package DataMachine\Cli\Commands
 */

namespace DataMachine\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachine\Core\Database\Migrations\MigrationRunner;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and apply registered Data Machine migrations.
 */
class MigrateCommand extends BaseCommand {

	/**
	 * List registered migrations and their progress on this site.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine migrate status
	 *
	 * @subcommand status
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );
		$format = $assoc_args['format'] ?? 'table';
		$rows   = MigrationRunner::status();

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

			return;
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'        => $row['id'] ?? '',
				'status'    => $row['status'] ?? '',
				'remaining' => $row['remaining'] ?? '',
				'cursor'    => $row['cursor'] ?? '',
				'label'     => $row['label'] ?? '',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'status', 'remaining', 'cursor', 'label' ) );
	}

	/**
	 * Apply one bounded batch of one migration.
	 *
	 * Re-run until status is complete. One invocation never walks an entire
	 * table: --limit caps the batch, and a lease prevents overlapping runs.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<id>]
	 * : Migration id. Omit to run the first incomplete migration.
	 *
	 * [--limit=<count>]
	 * : Maximum candidate rows this invocation may touch (1-1000).
	 * ---
	 * default: 250
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format: table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine migrate run
	 *     wp datamachine migrate run --id=jobs.status-reason-backfill --limit=250
	 *
	 * @subcommand run
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Keyed arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $args );
		$limit  = max( 1, min( 1000, (int) ( $assoc_args['limit'] ?? 250 ) ) );
		$format = $assoc_args['format'] ?? 'table';
		$id     = isset( $assoc_args['id'] ) ? (string) $assoc_args['id'] : '';
		$result = '' === $id
			? MigrationRunner::runNext( $limit )
			: MigrationRunner::run( $id, $limit );

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			foreach ( array( 'id', 'code', 'status', 'cursor', 'scanned', 'migrated', 'remaining', 'error' ) as $field ) {
				if ( ! array_key_exists( $field, $result ) ) {
					continue;
				}
				WP_CLI::log( sprintf( '%-12s %s', ucfirst( $field ) . ':', (string) $result[ $field ] ) );
			}
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( (string) ( $result['error'] ?? 'Migration failed.' ) );
		}

		if ( 'all_complete' === ( $result['code'] ?? '' ) || ! empty( $result['complete'] ) ) {
			WP_CLI::success( 'Migration complete.' );

			return;
		}

		WP_CLI::log( 'Batch complete. Re-run until status is complete.' );
	}
}
