<?php
/**
 * WP-CLI wrapper for bounded pipeline batch action recovery.
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\BatchActionRecoveryAbilities;

defined( 'ABSPATH' ) || exit;

class BatchActionRecoveryCommand {

	/**
	 * Review or apply one bounded recovery window.
	 *
	 * ## OPTIONS
	 *
	 * [--cursor=<action-id>]
	 * : Resume after this Action Scheduler action ID. Default: 0.
	 *
	 * [--scan-limit=<number>]
	 * : Maximum physical action rows to inspect. Default: 250; maximum: 1000.
	 *
	 * [--mutation-limit=<number>]
	 * : Hard deletion ceiling. Default: 25; maximum: 100.
	 *
	 * [--review-through=<action-id>]
	 * : Dry-run upper action ID. Required with --apply so later rows cannot enter the reviewed window.
	 *
	 * [--apply]
	 * : Apply exact reviewed mutations. Without this flag the command is a dry run.
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events recover-batch-actions --scan-limit=250
	 *     wp data-machine-events recover-batch-actions --cursor=100000 --format=json
	 *     wp data-machine-events recover-batch-actions --cursor=100000 --review-through=100250 --mutation-limit=10 --apply
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$apply  = isset( $assoc_args['apply'] );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$result = ( new BatchActionRecoveryAbilities() )->execute(
			array(
				'apply'          => $apply,
				'cursor'         => (int) ( $assoc_args['cursor'] ?? 0 ),
				'review_through' => (int) ( $assoc_args['review-through'] ?? 0 ),
				'scan_limit'     => (int) ( $assoc_args['scan-limit'] ?? 250 ),
				'mutation_limit' => (int) ( $assoc_args['mutation-limit'] ?? 25 ),
			)
		);

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( (string) $result['error'] );
			return;
		}
		if ( 'json' === $format ) {
			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				\WP_CLI::error( 'Unable to encode recovery output.' );
				return;
			}
			\WP_CLI::log( $json );
			return;
		}

		\WP_CLI::log( 'Mode: ' . ( $apply ? 'APPLY' : 'DRY RUN' ) );
		\WP_CLI::log( sprintf( 'Cursor: %d -> %d; scanned: %d', $result['cursor'], $result['next_cursor'], $result['counts']['rows_scanned'] ) );
		if ( ! empty( $result['actions'] ) ) {
			\WP_CLI\Utils\format_items( 'table', $result['actions'], array( 'action_id', 'parent_job_id', 'offset', 'disposition', 'reason' ) );
		}
		\WP_CLI::log( sprintf( 'Eligible: %d; deleted: %d; preserved: %d; races: %d; mutations: %d/%d', $result['counts']['eligible'], $result['counts']['deleted'], $result['counts']['preserved'], $result['counts']['race_skipped'], $result['mutations'], $result['mutation_limit'] ) );
		$resume = 'Recovery window complete. Resume with --cursor=' . $result['next_cursor'];
		if ( $apply ) {
			$resume .= ' --review-through=' . $result['review_through'] . ' --apply';
		}
		\WP_CLI::success( $result['has_more'] ? $resume . '.' : 'Recovery scan complete.' );
		if ( ! $apply && $result['counts']['eligible'] > 0 ) {
			\WP_CLI::warning( 'Dry run only. Review the exact IDs above, then add --review-through=' . $result['review_through'] . ' --apply to mutate only this window.' );
		}
	}
}
