<?php
/**
 * VenueMergeHelper
 *
 * Shared primitive for merging a duplicate venue term (loser) into a
 * canonical venue term (winner). Used by the `wp data-machine-events
 * check merge-duplicate-venues` migration command and any future
 * agent-driven venue consolidation flows.
 *
 * Merge order is non-negotiable:
 *
 *   1. Smart-merge venue meta (fill empties only on the winner).
 *   2. Reassign post-term relationships via wp_set_object_terms() so
 *      tt_count stays in sync.
 *   3. Rewrite flow handler_config references that point at the loser.
 *   4. Delete the loser term (cascades term meta cleanup).
 *
 * Posts and flows MUST be reassigned BEFORE the loser term is deleted —
 * deleting first would orphan inbound references and break event
 * tagging on the next pipeline run.
 *
 * @package DataMachineEvents\Core\DuplicateDetection
 * @since   0.35.0
 */

namespace DataMachineEvents\Core\DuplicateDetection;

use DataMachineEvents\Core\Venue_Taxonomy;

defined( 'ABSPATH' ) || exit;

class VenueMergeHelper {

	/**
	 * Term meta key signalling that a venue should never be auto-merged.
	 * Set on either winner or loser to skip the cluster entirely.
	 */
	public const NO_MERGE_META_KEY = '_venue_no_merge';

	/**
	 * Merge a loser venue term into a winner venue term.
	 *
	 * @param int $winner_id Term ID to keep (lower IDs win in callers).
	 * @param int $loser_id  Term ID to delete after reassignment.
	 * @return array{
	 *     success: bool,
	 *     winner_id: int,
	 *     loser_id: int,
	 *     posts_reassigned: int,
	 *     flows_reassigned: int,
	 *     meta_filled: array<int,string>,
	 *     skipped_reason: string|null,
	 *     error: string|null,
	 * }
	 */
	public static function merge( int $winner_id, int $loser_id ): array {
		$result = array(
			'success'          => false,
			'winner_id'        => $winner_id,
			'loser_id'         => $loser_id,
			'posts_reassigned' => 0,
			'flows_reassigned' => 0,
			'meta_filled'      => array(),
			'skipped_reason'   => null,
			'error'            => null,
		);

		if ( $winner_id <= 0 || $loser_id <= 0 ) {
			$result['error'] = 'Invalid term IDs.';
			return $result;
		}

		if ( $winner_id === $loser_id ) {
			$result['error'] = 'Winner and loser are the same term.';
			return $result;
		}

		$winner = get_term( $winner_id, 'venue' );
		$loser  = get_term( $loser_id, 'venue' );

		if ( ! $winner || is_wp_error( $winner ) || ! $loser || is_wp_error( $loser ) ) {
			$result['error'] = 'One or both terms do not exist.';
			return $result;
		}

		// Opt-out protection: skip if either side is flagged.
		$winner_optout = (int) get_term_meta( $winner_id, self::NO_MERGE_META_KEY, true );
		$loser_optout  = (int) get_term_meta( $loser_id, self::NO_MERGE_META_KEY, true );

		if ( $winner_optout || $loser_optout ) {
			$result['skipped_reason'] = sprintf(
				'opt-out flag set (winner=%d, loser=%d)',
				$winner_optout,
				$loser_optout
			);
			return $result;
		}

		// 1. Smart-merge meta from loser into winner (fill empties only).
		$result['meta_filled'] = self::fill_empty_meta( $winner_id, $loser_id );

		// 2. Reassign every post tagged with the loser → winner.
		$result['posts_reassigned'] = self::reassign_post_terms( $winner_id, $loser_id );

		// 3. Rewrite flow handler_config references that point at loser.
		$result['flows_reassigned'] = self::reassign_flow_handler_configs( $winner_id, $loser_id );

		// 4. Delete the loser term (cascades term meta).
		$deleted = wp_delete_term( $loser_id, 'venue' );
		if ( is_wp_error( $deleted ) || true !== $deleted ) {
			$result['error'] = sprintf(
				'Failed to delete loser term %d: %s',
				$loser_id,
				is_wp_error( $deleted ) ? $deleted->get_error_message() : 'unknown error'
			);
			return $result;
		}

		// 5. Audit trail.
		do_action(
			'datamachine_log',
			'info',
			'venue_merge',
			array(
				'winner_id'        => $winner_id,
				'winner_name'      => $winner->name,
				'loser_id'         => $loser_id,
				'loser_name'       => $loser->name,
				'posts_reassigned' => $result['posts_reassigned'],
				'flows_reassigned' => $result['flows_reassigned'],
				'meta_filled'      => $result['meta_filled'],
			)
		);

		$result['success'] = true;
		return $result;
	}

	/**
	 * Fill empty winner meta from loser meta. Never overwrites a non-empty
	 * winner value. Returns the list of meta keys that were populated.
	 *
	 * @param int $winner_id Winner term ID.
	 * @param int $loser_id  Loser term ID.
	 * @return array<int,string> Meta keys filled (e.g. ["_venue_phone", "_venue_zip"]).
	 */
	private static function fill_empty_meta( int $winner_id, int $loser_id ): array {
		$filled = array();

		foreach ( Venue_Taxonomy::$meta_fields as $field => $meta_key ) {
			$winner_value = get_term_meta( $winner_id, $meta_key, true );
			if ( ! empty( $winner_value ) ) {
				continue;
			}

			$loser_value = get_term_meta( $loser_id, $meta_key, true );
			if ( empty( $loser_value ) ) {
				continue;
			}

			update_term_meta( $winner_id, $meta_key, $loser_value );
			$filled[] = $meta_key;
		}

		return $filled;
	}

	/**
	 * Reassign every post currently tagged with the loser term to the
	 * winner term. Uses wp_set_object_terms() (not raw SQL) so the
	 * taxonomy cache and tt_count stay correct.
	 *
	 * @param int $winner_id Winner term ID.
	 * @param int $loser_id  Loser term ID.
	 * @return int Number of posts reassigned.
	 */
	private static function reassign_post_terms( int $winner_id, int $loser_id ): int {
		$post_ids = get_objects_in_term( $loser_id, 'venue' );

		if ( is_wp_error( $post_ids ) || empty( $post_ids ) ) {
			return 0;
		}

		$reassigned = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			// Replace the loser term with the winner on this post, preserving
			// any other venue terms the post might happen to carry (rare but
			// possible during partial migrations). The boolean false `$append`
			// arg means we hand wp_set_object_terms the FULL desired list.
			$existing = wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );

			if ( is_wp_error( $existing ) ) {
				continue;
			}

			$existing = array_map( 'intval', $existing );
			$next     = array();

			foreach ( $existing as $tid ) {
				if ( $tid === $loser_id ) {
					$next[] = $winner_id;
				} else {
					$next[] = $tid;
				}
			}

			$next = array_values( array_unique( $next ) );

			$set = wp_set_object_terms( $post_id, $next, 'venue', false );
			if ( ! is_wp_error( $set ) ) {
				++$reassigned;
			}
		}

		return $reassigned;
	}

	/**
	 * Rewrite Data Machine flow handler_config references that point at the
	 * loser term ID. Stored shape (per VenueParameterProvider) is either:
	 *
	 *   handler_config: { "venue": "<term_id>" }
	 *   handler_config: { "universal_web_scraper": { "venue": "<term_id>" } }
	 *
	 * The Ticketmaster handler uses `venue_id` but that points at an
	 * external Ticketmaster venue identifier, NOT a WP term — we leave
	 * those alone.
	 *
	 * @param int $winner_id Winner term ID.
	 * @param int $loser_id  Loser term ID.
	 * @return int Number of flow rows updated.
	 */
	private static function reassign_flow_handler_configs( int $winner_id, int $loser_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'datamachine_flows';
		// Bail quietly if the flows table is not present (e.g. unit-test env
		// without Data Machine core schema installed).
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return 0;
		}

		$loser_str  = (string) $loser_id;
		$winner_str = (string) $winner_id;

		// Two LIKE shapes catch both flat ("venue":"123") and the nested
		// universal_web_scraper variant — the JSON substring is identical
		// for both since they both end in `"venue":"123"`.
		$flows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT flow_id, flow_config FROM {$table}
				 WHERE flow_config LIKE %s
				 OR    flow_config LIKE %s",
				'%"venue":"' . $wpdb->esc_like( $loser_str ) . '"%',
				'%"venue":' . $wpdb->esc_like( $loser_str ) . '%'
			)
		);

		if ( empty( $flows ) ) {
			return 0;
		}

		$updated = 0;

		foreach ( $flows as $row ) {
			$decoded = json_decode( $row->flow_config, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$changed = false;
			self::rewrite_venue_refs( $decoded, $loser_str, $winner_str, $changed );

			if ( ! $changed ) {
				continue;
			}

			$encoded = wp_json_encode( $decoded );
			if ( false === $encoded ) {
				continue;
			}

			$result = $wpdb->update(
				$table,
				array( 'flow_config' => $encoded ),
				array( 'flow_id' => (int) $row->flow_id ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false !== $result ) {
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * Recursively walk a decoded flow_config structure and rewrite any
	 * `venue` field whose stringified value matches the loser term ID.
	 *
	 * Strings and numerics are both handled — handler_config historically
	 * stores numeric IDs as strings, but a defensive int compare guards
	 * against any future shape change.
	 *
	 * @param array  $node       Reference to current node.
	 * @param string $loser_str  Loser term ID as string.
	 * @param string $winner_str Winner term ID as string.
	 * @param bool   $changed    Flips true if any rewrite occurred.
	 */
	private static function rewrite_venue_refs( array &$node, string $loser_str, string $winner_str, bool &$changed ): void {
		foreach ( $node as $key => &$value ) {
			if ( is_array( $value ) ) {
				self::rewrite_venue_refs( $value, $loser_str, $winner_str, $changed );
				continue;
			}

			if ( 'venue' !== $key ) {
				continue;
			}

			$as_string = (string) $value;
			if ( $as_string === $loser_str ) {
				$value   = is_int( $value ) ? (int) $winner_str : $winner_str;
				$changed = true;
			}
		}
	}
}
