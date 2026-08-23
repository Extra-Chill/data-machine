<?php
/**
 * Audit and repair event-date status drift.
 *
 * @package DataMachineEvents\Cli\Check
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\EventDatesTable;

defined( 'ABSPATH' ) || exit;

class CheckEventDateStatusCommand {

	/**
	 * Audit or repair one bounded batch of status mismatches and orphans.
	 *
	 * Defaults to a read-only dry run. Pass the returned cursor to resume the
	 * scan; each invocation processes at most one batch without a transaction.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Update mismatched statuses and delete orphaned/wrong-type date rows.
	 *
	 * [--after-id=<post-id>]
	 * : Resume after this event-date post ID.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--batch-size=<count>]
	 * : Maximum candidates to inspect (1-1000).
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events check event-date-status
	 *     wp data-machine-events check event-date-status --apply --batch-size=250
	 *     wp data-machine-events check event-date-status --apply --after-id=12345
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$apply      = isset( $assoc_args['apply'] );
		$after_id   = max( 0, (int) ( $assoc_args['after-id'] ?? 0 ) );
		$batch_size = max( 1, min( 1000, (int) ( $assoc_args['batch-size'] ?? 100 ) ) );
		$format     = (string) ( $assoc_args['format'] ?? 'table' );
		$batch      = EventDatesTable::find_status_drift_batch( $after_id, $batch_size );
		$rows       = $batch['rows'];

		foreach ( $rows as &$row ) {
			$row['result'] = $apply
				? EventDatesTable::repair_status_drift_row( $row['post_id'] )
				: 'would_' . $row['action'];
		}
		unset( $row );

		$summary = array(
			'apply'       => $apply,
			'count'       => count( $rows ),
			'input_cursor' => $after_id,
			'next_cursor' => $batch['next_cursor'],
			'has_more'    => $batch['has_more'],
			'rows'        => $rows,
		);

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $summary, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( ! empty( $rows ) ) {
			\WP_CLI\Utils\format_items(
				$format,
				$rows,
				array( 'post_id', 'indexed_status', 'canonical_status', 'canonical_post_type', 'result' )
			);
		}

		\WP_CLI::log(
			sprintf(
				'%s %d candidate(s). Next cursor: %d. More mismatches: %s.',
				$apply ? 'Processed' : 'Found',
				count( $rows ),
				$batch['next_cursor'],
				$batch['has_more'] ? 'yes' : 'no'
			)
		);

		if ( ! $apply ) {
			\WP_CLI::log( sprintf( 'DRY RUN - no changes made. Re-run with --apply --after-id=%d to repair this batch.', $after_id ) );
		}
	}
}
