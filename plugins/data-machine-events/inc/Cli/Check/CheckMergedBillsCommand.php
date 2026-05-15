<?php
/**
 * `wp data-machine-events check merged-bills`
 *
 * Scanner for merged-bill duplicate candidates: same venue + same exact
 * start_datetime + different titles, scored on lineup overlap and other
 * signals. High-confidence pairs are queued into datamachine_pending_actions
 * for the agent decision step to resolve.
 *
 * Operator surface for issue #256. Mirrors the shape of CleanDuplicatesCommand.
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.34.0
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Abilities\MergedBillDetectAbilities;

defined( 'ABSPATH' ) || exit;

class CheckMergedBillsCommand {

	/**
	 * Scan upcoming events for merged-bill duplicate pairs.
	 *
	 * ## OPTIONS
	 *
	 * [--days-ahead=<days>]
	 * : How many days ahead to scan.
	 * ---
	 * default: 90
	 * ---
	 *
	 * [--threshold=<score>]
	 * : Minimum score to queue a pair. Mutual lineup mention is +5; the
	 *   default threshold of 5 makes that the minimum bar.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--limit=<count>]
	 * : Max pairs to queue this run.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would be queued without writing to pending_actions.
	 *
	 * [--format=<format>]
	 * : Output format for the per-pair table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events check merged-bills --dry-run
	 *     wp data-machine-events check merged-bills --threshold=5 --limit=20
	 *     wp data-machine-events check merged-bills --days-ahead=180 --dry-run --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$input = array(
			'days_ahead' => (int) ( $assoc_args['days-ahead'] ?? 90 ),
			'threshold'  => (int) ( $assoc_args['threshold'] ?? 5 ),
			'limit'      => (int) ( $assoc_args['limit'] ?? 50 ),
			'dry_run'    => isset( $assoc_args['dry-run'] ),
		);

		$format = (string) ( $assoc_args['format'] ?? 'table' );

		$ability = new MergedBillDetectAbilities();
		$result  = $ability->execute( $input );

		\WP_CLI::log(
			sprintf(
				'Evaluated %d candidate pair(s). Threshold = %d. Skipped (already decided): %d.',
				$result['scanned_pairs'],
				$result['threshold'],
				$result['skipped_decided']
			)
		);

		$rows = array();
		foreach ( $result['pairs'] as $pair ) {
			$signals  = $pair['signals'] ?? array();
			$tag_bits = array();
			if ( ! empty( $signals['mutual_lineup_mention'] ) ) {
				$tag_bits[] = 'lineup';
			}
			if ( ! empty( $signals['identical_end'] ) ) {
				$tag_bits[] = 'end';
			}
			if ( ! empty( $signals['matching_price'] ) ) {
				$tag_bits[] = 'price';
			}
			if ( ! empty( $signals['matching_source_host'] ) ) {
				$tag_bits[] = 'host';
			}

			$rows[] = array(
				'post_a'   => $pair['post_a_id'],
				'title_a'  => mb_substr( (string) $pair['post_a_title'], 0, 35 ),
				'post_b'   => $pair['post_b_id'],
				'title_b'  => mb_substr( (string) $pair['post_b_title'], 0, 35 ),
				'venue'    => $pair['venue_term_id'],
				'start'    => $pair['start_datetime'],
				'score'    => $pair['score'],
				'signals'  => implode( ',', $tag_bits ),
				'queued'   => $pair['score'] >= $result['threshold'] && ! $result['dry_run'] ? 'yes' : 'no',
			);
		}

		if ( ! empty( $rows ) ) {
			\WP_CLI\Utils\format_items(
				$format,
				$rows,
				array( 'post_a', 'title_a', 'post_b', 'title_b', 'venue', 'start', 'score', 'signals', 'queued' )
			);
		}

		if ( $result['dry_run'] ) {
			\WP_CLI::log( 'DRY RUN — no rows written to datamachine_pending_actions.' );
			return;
		}

		\WP_CLI::success(
			sprintf( 'Queued %d pair(s) into datamachine_pending_actions (kind=%s).', $result['queued'], MergedBillDetectAbilities::PENDING_ACTION_KIND )
		);
	}
}
