<?php
/**
 * `wp data-machine-events check merge-duplicate-venues`
 *
 * One-time migration that consolidates duplicate venue terms produced
 * before PR #252 (address-aware venue resolution) and before issue #276
 * (ampersand / HTML-entity / apostrophe + suite-suffix normalization)
 * shipped.
 *
 * Scans every venue term, groups them by normalized name AND normalized
 * address+city, and for each cluster picks the oldest term (lowest ID)
 * as the winner. Loser terms are smart-merged into the winner via
 * VenueMergeHelper: post-term relationships are reassigned, flow
 * handler_config references are rewritten, then the loser term is
 * deleted.
 *
 * Operator surface for issue #276. Mirrors the dry-run / apply shape of
 * CleanDuplicatesCommand and the table/csv/json output shape of
 * CheckMergedBillsCommand.
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.35.0
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\DuplicateDetection\VenueMergeHelper;

defined( 'ABSPATH' ) || exit;

class CheckMergeDuplicateVenuesCommand {

	/**
	 * Scan for and (optionally) merge duplicate venue term clusters.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be merged without writing. This is the default
	 *   behavior — pass --apply to actually commit changes.
	 *
	 * [--apply]
	 * : Actually perform the merges. Without this flag the command
	 *   behaves as --dry-run.
	 *
	 * [--limit=<count>]
	 * : Cap the number of clusters processed per run. Keeps single-run
	 *   scope bounded for ops review.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for the per-cluster table.
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
	 *     wp data-machine-events check merge-duplicate-venues --dry-run
	 *     wp data-machine-events check merge-duplicate-venues --apply --limit=10
	 *     wp data-machine-events check merge-duplicate-venues --dry-run --format=csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$apply  = isset( $assoc_args['apply'] );
		$limit  = max( 1, (int) ( $assoc_args['limit'] ?? 50 ) );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		// Default to dry-run unless --apply is passed.
		$dry_run = ! $apply;

		$clusters = $this->find_clusters();

		if ( empty( $clusters ) ) {
			\WP_CLI::success( 'No duplicate venue clusters detected.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Detected %d cluster(s) of duplicate venue terms.', count( $clusters ) ) );

		if ( count( $clusters ) > $limit ) {
			\WP_CLI::log( sprintf( 'Processing first %d clusters this run (use --limit=N to change).', $limit ) );
			$clusters = array_slice( $clusters, 0, $limit );
		}

		$rows = array();

		foreach ( $clusters as $cluster ) {
			$row = $this->process_cluster( $cluster, $dry_run );
			$rows[] = $row;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			array(
				'cluster_key',
				'winner_id',
				'winner_name',
				'loser_ids',
				'loser_names',
				'total_posts_reassigned',
				'total_flows_reassigned',
				'action_taken',
			)
		);

		if ( $dry_run ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'DRY RUN — no changes made. Re-run with --apply to commit.' );
			return;
		}

		$total_posts = array_sum( array_column( $rows, 'total_posts_reassigned' ) );
		$total_flows = array_sum( array_column( $rows, 'total_flows_reassigned' ) );

		\WP_CLI::success(
			sprintf(
				'Processed %d cluster(s). Reassigned %d post(s) and %d flow handler_config reference(s).',
				count( $rows ),
				$total_posts,
				$total_flows
			)
		);
	}

	/**
	 * Walk every venue term and group by normalized name OR normalized
	 * address+city. Returns only clusters with >=2 terms.
	 *
	 * The address key intentionally includes the city to keep two
	 * different "123 Main St" venues (different cities) apart.
	 *
	 * @return array<int,array{key:string,term_ids:array<int,int>,terms:array}>
	 */
	private function find_clusters(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$by_name    = array();
		$by_address = array();

		foreach ( $terms as $term ) {
			$name_key = Venue_Taxonomy::normalize_venue_name_for_matching( $term->name );
			if ( strlen( $name_key ) >= 3 ) {
				$by_name[ $name_key ][] = $term;
			}

			$address = (string) get_term_meta( $term->term_id, '_venue_address', true );
			$city    = (string) get_term_meta( $term->term_id, '_venue_city', true );

			if ( '' === $address || '' === $city ) {
				continue;
			}

			$addr_key = sprintf(
				'%s|%s',
				Venue_Taxonomy::normalize_address_for_matching( $address ),
				strtolower( trim( $city ) )
			);

			if ( '|' === $addr_key || str_starts_with( $addr_key, '|' ) ) {
				continue;
			}

			$by_address[ $addr_key ][] = $term;
		}

		$clusters     = array();
		$seen_term_ids = array();

		// Emit name-clusters first, then address-clusters. Each term is
		// emitted in at most one cluster — once seen via name, the
		// address loop ignores it.
		foreach ( array( 'name' => $by_name, 'addr' => $by_address ) as $kind => $groups ) {
			foreach ( $groups as $key => $group_terms ) {
				if ( count( $group_terms ) < 2 ) {
					continue;
				}

				$ids = array();
				foreach ( $group_terms as $t ) {
					$ids[] = (int) $t->term_id;
				}

				// Drop terms we've already clustered via the name pass.
				$ids = array_values( array_diff( $ids, $seen_term_ids ) );

				if ( count( $ids ) < 2 ) {
					continue;
				}

				$cluster_terms = array_values(
					array_filter(
						$group_terms,
						static fn( $t ) => in_array( (int) $t->term_id, $ids, true )
					)
				);

				$clusters[] = array(
					'key'      => $kind . ':' . $key,
					'term_ids' => $ids,
					'terms'    => $cluster_terms,
				);

				foreach ( $ids as $tid ) {
					$seen_term_ids[] = $tid;
				}
			}
		}

		return $clusters;
	}

	/**
	 * Pick winner/losers for a cluster and dispatch the merge (or describe
	 * it under dry-run). Returns one row for the output table.
	 *
	 * @param array $cluster Cluster from find_clusters().
	 * @param bool  $dry_run Whether to skip writes.
	 * @return array Row for format_items().
	 */
	private function process_cluster( array $cluster, bool $dry_run ): array {
		$ids   = $cluster['term_ids'];
		$terms = $cluster['terms'];

		sort( $ids );
		$winner_id = (int) $ids[0];
		$loser_ids = array_slice( $ids, 1 );

		$name_by_id = array();
		foreach ( $terms as $t ) {
			$name_by_id[ (int) $t->term_id ] = (string) $t->name;
		}

		$winner_name = $name_by_id[ $winner_id ] ?? '';
		$loser_names = array_map( static fn( $id ) => $name_by_id[ $id ] ?? '', $loser_ids );

		$row = array(
			'cluster_key'            => $cluster['key'],
			'winner_id'              => $winner_id,
			'winner_name'            => $winner_name,
			'loser_ids'              => implode( ',', $loser_ids ),
			'loser_names'            => implode( ' || ', $loser_names ),
			'total_posts_reassigned' => 0,
			'total_flows_reassigned' => 0,
			'action_taken'           => $dry_run ? 'dry-run' : '',
		);

		if ( $dry_run ) {
			return $row;
		}

		$skipped       = false;
		$total_posts   = 0;
		$total_flows   = 0;
		$skip_reasons  = array();
		$error_seen    = false;

		foreach ( $loser_ids as $loser_id ) {
			$result = VenueMergeHelper::merge( $winner_id, $loser_id );

			if ( ! empty( $result['skipped_reason'] ) ) {
				$skipped        = true;
				$skip_reasons[] = sprintf( '%d: %s', $loser_id, $result['skipped_reason'] );
				continue;
			}

			if ( ! $result['success'] ) {
				$error_seen = true;
				\WP_CLI::warning(
					sprintf(
						'Failed to merge loser %d into winner %d: %s',
						$loser_id,
						$winner_id,
						$result['error'] ?? 'unknown error'
					)
				);
				continue;
			}

			$total_posts += $result['posts_reassigned'];
			$total_flows += $result['flows_reassigned'];
		}

		$row['total_posts_reassigned'] = $total_posts;
		$row['total_flows_reassigned'] = $total_flows;

		if ( $skipped && 0 === $total_posts && 0 === $total_flows ) {
			$row['action_taken'] = 'skipped: ' . implode( '; ', $skip_reasons );
		} elseif ( $error_seen ) {
			$row['action_taken'] = 'partial';
		} else {
			$row['action_taken'] = 'merged';
		}

		return $row;
	}
}
