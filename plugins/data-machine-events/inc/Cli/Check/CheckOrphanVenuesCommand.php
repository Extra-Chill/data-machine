<?php
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Existing callback contracts, trusted identifiers, and renderer boundaries are reviewed and intentional.
/**
 * `wp data-machine-events check orphan-venues`
 *
 * Audit + repair pass for venue terms whose `wp_term_taxonomy.count = 0`.
 * Per issue #277, the network-wide audit found 278 of 3,765 venue terms
 * (7%) in this state on events.extrachill.com — either every post that
 * was tagged with them got deleted, or they were created as side effects
 * of failed event upserts. They are noise in the verdict-log + qualify
 * pipeline.
 *
 * Behavior per orphan candidate, in order:
 *
 *   1. VERIFY the count cache. `wp_term_taxonomy.count` is a cached
 *      value; an actual `wp_term_relationships` join can disagree if
 *      the cache went stale. If we find a real relationship, refresh
 *      the cache via wp_update_term_count_now() and skip the term —
 *      it is not actually orphaned.
 *
 *   2. PROTECT terms referenced by active flows. A flow whose
 *      flow_config JSON points at this term_id is using it — the term
 *      just has not produced events yet. Flag with
 *      _venue_orphan_protected_by_flow = <flow_id> and skip deletion.
 *
 *   3. REAL ORPHANS — cache accurate and no flow refs. Default behavior
 *      is FLAG-NOT-DELETE: stamp _venue_orphan_flagged_at with the
 *      current Unix timestamp and leave the term in place. The
 *      operator decides whether to delete later via --delete-orphans.
 *
 *   4. PROTECTED FROM DELETION even with --delete-orphans:
 *      - VenueMergeHelper::NO_MERGE_META_KEY (`_venue_no_merge=1`) — a
 *        general "do not auto-modify this term" opt-out. We treat it as
 *        an anti-delete signal too.
 *      - `_venue_orphan_protected_by_flow` set by step 2.
 *
 * Default `--dry-run`; require `--apply` to commit. `--delete-orphans`
 * is opt-in even with `--apply`.
 *
 * @package DataMachineEvents\Cli\Check
 * @since   0.38.0
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Core\DuplicateDetection\VenueMergeHelper;

defined( 'ABSPATH' ) || exit;

class CheckOrphanVenuesCommand {

	/**
	 * Term meta key stamped on real orphans the operator should review.
	 * Value is the current Unix timestamp at the time of flagging.
	 */
	public const ORPHAN_FLAGGED_META_KEY = '_venue_orphan_flagged_at';

	/**
	 * Term meta key stamped on orphans that are referenced by an active
	 * flow. Value is the flow_id holding the reference.
	 */
	public const ORPHAN_PROTECTED_BY_FLOW_META_KEY = '_venue_orphan_protected_by_flow';

	/**
	 * Audit + repair venue terms with wp_term_taxonomy.count = 0.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be flagged/protected/deleted without writing.
	 *   Default behavior — pass --apply to commit changes.
	 *
	 * [--apply]
	 * : Actually perform the flagging / deletion / cache-refresh work.
	 *
	 * [--delete-orphans]
	 * : Opt-in to DELETING orphan venue terms. Without this flag, real
	 *   orphans are only flagged via term meta and remain in place.
	 *   No effect under --dry-run.
	 *
	 * [--limit=<count>]
	 * : Cap the number of orphan candidates processed per run.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for the per-term table.
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
	 *     wp data-machine-events check orphan-venues --dry-run
	 *     wp data-machine-events check orphan-venues --apply
	 *     wp data-machine-events check orphan-venues --apply --delete-orphans
	 *     wp data-machine-events check orphan-venues --dry-run --format=csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$apply          = isset( $assoc_args['apply'] );
		$delete_orphans = isset( $assoc_args['delete-orphans'] );
		$limit          = max( 1, (int) ( $assoc_args['limit'] ?? 100 ) );
		$format         = (string) ( $assoc_args['format'] ?? 'table' );

		$dry_run = ! $apply;

		$candidates = $this->find_candidates();

		if ( empty( $candidates ) ) {
			\WP_CLI::success( 'No orphan venue terms detected.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Detected %d candidate orphan venue term(s).', count( $candidates ) ) );

		if ( count( $candidates ) > $limit ) {
			\WP_CLI::log( sprintf( 'Processing first %d this run (use --limit=N to change).', $limit ) );
			$candidates = array_slice( $candidates, 0, $limit );
		}

		$rows = array();

		foreach ( $candidates as $term ) {
			$rows[] = $this->process_candidate( $term, $dry_run, $delete_orphans );
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			array(
				'term_id',
				'term_name',
				'action_taken',
				'reason',
			)
		);

		if ( $dry_run ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'DRY RUN — no changes made. Re-run with --apply to commit.' );
			return;
		}

		$summary = array(
			'count_refreshed'   => 0,
			'protected_by_flow' => 0,
			'flagged'           => 0,
			'deleted'           => 0,
			'protected'         => 0,
		);

		foreach ( $rows as $row ) {
			$action = (string) $row['action_taken'];
			if ( isset( $summary[ $action ] ) ) {
				++$summary[ $action ];
			}
		}

		\WP_CLI::success(
			sprintf(
				'Processed %d term(s): %d cache-refreshed, %d protected by flow, %d flagged, %d deleted, %d protected from deletion.',
				count( $rows ),
				$summary['count_refreshed'],
				$summary['protected_by_flow'],
				$summary['flagged'],
				$summary['deleted'],
				$summary['protected']
			)
		);
	}

	/**
	 * Find every venue term whose `wp_term_taxonomy.count` is zero.
	 * Returns WP_Term objects so the caller can read meta + name.
	 *
	 * @return \WP_Term[]
	 */
	private function find_candidates(): array {
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

		$candidates = array();
		foreach ( $terms as $term ) {
			if ( 0 === (int) $term->count ) {
				$candidates[] = $term;
			}
		}

		return $candidates;
	}

	/**
	 * Process one candidate orphan. Returns one row for the output
	 * table with the action taken and a short reason.
	 *
	 * @param \WP_Term $term           Candidate term.
	 * @param bool     $dry_run        Skip writes.
	 * @param bool     $delete_orphans Whether --delete-orphans was passed.
	 * @return array<string,mixed>
	 */
	private function process_candidate( \WP_Term $term, bool $dry_run, bool $delete_orphans ): array {
		$row = array(
			'term_id'      => (int) $term->term_id,
			'term_name'    => (string) $term->name,
			'action_taken' => '',
			'reason'       => '',
		);

		// Step 1: Verify the count cache against the real
		// term_relationships join. If a real relationship exists, the
		// cache is stale — refresh it and exit without flagging.
		$real_count = $this->real_relationship_count( (int) $term->term_id );

		if ( $real_count > 0 ) {
			if ( ! $dry_run ) {
				$this->persist_refreshed_count(
					(int) $term->term_id,
					(int) $term->term_taxonomy_id,
					$real_count
				);
			}
			$row['action_taken'] = 'count_refreshed';
			$row['reason']       = sprintf( 'stale cache: %d real relationships found', $real_count );
			return $row;
		}

		// Step 2: Active-flow protection. A flow holding a reference to
		// this term_id means an operator wired it intentionally — do
		// not delete even if --delete-orphans is set.
		$flow_id = $this->find_flow_referencing( (int) $term->term_id );

		if ( null !== $flow_id ) {
			if ( ! $dry_run ) {
				update_term_meta(
					(int) $term->term_id,
					self::ORPHAN_PROTECTED_BY_FLOW_META_KEY,
					$flow_id
				);
			}
			$row['action_taken'] = 'protected_by_flow';
			$row['reason']       = sprintf( 'referenced by flow_id %d', $flow_id );
			return $row;
		}

		// Step 3: --delete-orphans protections (only relevant if the
		// operator actually wants to delete). We check these BEFORE
		// flagging so a term flagged in a previous run that an operator
		// has since marked _venue_no_merge does not get force-deleted
		// on a later --apply --delete-orphans run.
		if ( $delete_orphans ) {
			$no_merge = (int) get_term_meta(
				(int) $term->term_id,
				VenueMergeHelper::NO_MERGE_META_KEY,
				true
			);

			$existing_flow_protection = (int) get_term_meta(
				(int) $term->term_id,
				self::ORPHAN_PROTECTED_BY_FLOW_META_KEY,
				true
			);

			if ( $no_merge > 0 ) {
				$row['action_taken'] = 'protected';
				$row['reason']       = 'opt-out flag set (_venue_no_merge)';
				return $row;
			}

			if ( $existing_flow_protection > 0 ) {
				$row['action_taken'] = 'protected';
				$row['reason']       = sprintf(
					'previously flagged as flow-protected (flow_id %d)',
					$existing_flow_protection
				);
				return $row;
			}

			// Real orphan + opt-in delete + no protections → delete.
			if ( ! $dry_run ) {
				$deleted = wp_delete_term( (int) $term->term_id, 'venue' );
				if ( is_wp_error( $deleted ) || true !== $deleted ) {
					$row['action_taken'] = 'flagged';
					$row['reason']       = 'wp_delete_term failed; falling back to flag';

					update_term_meta(
						(int) $term->term_id,
						self::ORPHAN_FLAGGED_META_KEY,
						time()
					);
					return $row;
				}
			}

			$row['action_taken'] = 'deleted';
			$row['reason']       = 'real orphan; --delete-orphans opt-in';
			return $row;
		}

		// Step 4: Default — flag the term, leave it in place.
		if ( ! $dry_run ) {
			update_term_meta(
				(int) $term->term_id,
				self::ORPHAN_FLAGGED_META_KEY,
				time()
			);
		}

		$row['action_taken'] = 'flagged';
		$row['reason']       = 'real orphan; flag-only (operator decides deletion)';
		return $row;
	}

	/**
	 * Persist a refreshed `wp_term_taxonomy.count` value for a term
	 * whose cache was stale (count=0 with real relationship rows).
	 *
	 * Issue #284: `wp_update_term_count_now()` calls were observed to
	 * not actually persist on production — 99 stale-cache terms were
	 * detected, the function was called against each, but the cached
	 * `count` column did not move. The exact failure mode (object-cache
	 * interception, taxonomy-callback indirection, post-status filter
	 * mismatch) is hard to pin down across environments, so we bypass
	 * the WP helper entirely:
	 *
	 *   1. Write the real count directly to `wp_term_taxonomy.count`
	 *      via `$wpdb->update()`. This sidesteps any custom
	 *      `update_count_callback` registered against the taxonomy
	 *      and any post-status filtering applied by the default
	 *      counter.
	 *   2. Invalidate the surrounding term + object cache so the next
	 *      `get_term()` call reads the fresh DB value rather than the
	 *      stale cached value.
	 *
	 * @param int $term_id          Venue term ID.
	 * @param int $term_taxonomy_id Venue term_taxonomy_id.
	 * @param int $real_count       Real relationship count for the term.
	 * @return void
	 */
	private function persist_refreshed_count( int $term_id, int $term_taxonomy_id, int $real_count ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->term_taxonomy,
			array( 'count' => $real_count ),
			array( 'term_taxonomy_id' => $term_taxonomy_id ),
			array( '%d' ),
			array( '%d' )
		);

		clean_term_cache( $term_id, 'venue', true );
		wp_cache_delete( $term_id, 'terms' );
	}

	/**
	 * Query the real `wp_term_relationships` count for a term. Bypasses
	 * the cached `wp_term_taxonomy.count`.
	 *
	 * @param int $term_id Venue term ID.
	 * @return int Real relationship count.
	 */
	private function real_relationship_count( int $term_id ): int {
		global $wpdb;

		$tt_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
				 WHERE term_id = %d AND taxonomy = 'venue'",
				$term_id
			)
		);

		if ( $tt_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships}
				 WHERE term_taxonomy_id = %d",
				$tt_id
			)
		);
	}

	/**
	 * Find the first flow whose flow_config JSON references this term_id
	 * via a `"venue":"<term_id>"` field (flat or nested). Returns the
	 * flow_id, or null when no flow references the term.
	 *
	 * Mirrors the LIKE-based discovery in
	 * VenueMergeHelper::reassign_flow_handler_configs() so the protection
	 * matches the exact set of flow shapes the merge primitive rewrites.
	 *
	 * The flows table lives in the Data Machine core schema and is not
	 * guaranteed to exist in unit-test environments; this method returns
	 * null if the table is absent.
	 *
	 * @param int $term_id Venue term ID.
	 * @return int|null Flow ID that references this term, or null.
	 */
	private function find_flow_referencing( int $term_id ): ?int {
		global $wpdb;

		$table  = $wpdb->prefix . 'datamachine_flows';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return null;
		}

		$as_string = (string) $term_id;

		// Two shapes — quoted ("venue":"123") for handler_config that
		// stores ids as strings (the common case) and unquoted
		// ("venue":123) for any future shape that stores ints.
		$flow_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT flow_id FROM {$table}
				 WHERE flow_config LIKE %s
				 OR    flow_config LIKE %s
				 ORDER BY flow_id ASC
				 LIMIT 1",
				'%"venue":"' . $wpdb->esc_like( $as_string ) . '"%',
				'%"venue":' . $wpdb->esc_like( $as_string ) . '%'
			)
		);

		return $flow_id ? (int) $flow_id : null;
	}
}
