<?php
/**
 * EventMergeHelper
 *
 * Shared pairwise merge primitive for event posts. Trashes the loser and
 * forward-merges its ticket URL into the winner when the winner lacks one.
 *
 * Used by both:
 *   - `wp data-machine-events check clean-duplicates` (CleanDuplicatesCommand)
 *   - `data-machine-events/merge-event-posts` ability (MergeEventPostsAbilities)
 *
 * The two call sites previously had near-identical merge logic copy-pasted.
 * This helper is the single source of truth for "given a winner and loser,
 * merge them" — keeping behavior consistent across operator-driven cleanup
 * and agent-driven merged-bill resolution.
 *
 * @package DataMachineEvents\Core\DuplicateDetection
 * @since   0.34.0
 */

namespace DataMachineEvents\Core\DuplicateDetection;

use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;

defined( 'ABSPATH' ) || exit;

class EventMergeHelper {

	/**
	 * Merge a duplicate event pair.
	 *
	 * Trashes the loser. Optionally forward-merges the ticket URL into the
	 * winner when the winner has none and the loser has one. Returns a
	 * structured result so callers can report on what happened.
	 *
	 * Both IDs must refer to existing event posts. The caller is responsible
	 * for picking which post wins (e.g. oldest, longest body, has ticket URL).
	 *
	 * @param int  $winner_id Post ID to keep.
	 * @param int  $loser_id  Post ID to trash.
	 * @param array $opts {
	 *     Optional configuration.
	 *
	 *     @type bool $merge_ticket_url Whether to forward-merge the ticket URL when
	 *                                  the winner has none. Default true.
	 * }
	 * @return array{
	 *     success: bool,
	 *     winner_id: int,
	 *     loser_id: int,
	 *     trashed: bool,
	 *     ticket_url_merged: bool,
	 *     error: string|null,
	 * }
	 */
	public static function merge( int $winner_id, int $loser_id, array $opts = array() ): array {
		$result = array(
			'success'           => false,
			'winner_id'         => $winner_id,
			'loser_id'          => $loser_id,
			'trashed'           => false,
			'ticket_url_merged' => false,
			'error'             => null,
		);

		if ( $winner_id <= 0 || $loser_id <= 0 ) {
			$result['error'] = 'Invalid post IDs.';
			return $result;
		}

		if ( $winner_id === $loser_id ) {
			$result['error'] = 'Winner and loser are the same post.';
			return $result;
		}

		$winner = get_post( $winner_id );
		$loser  = get_post( $loser_id );

		if ( ! $winner || ! $loser ) {
			$result['error'] = 'One or both posts do not exist.';
			return $result;
		}

		$merge_ticket_url = $opts['merge_ticket_url'] ?? true;

		if ( $merge_ticket_url ) {
			$winner_ticket = get_post_meta( $winner_id, EVENT_TICKET_URL_META_KEY, true );
			$loser_ticket  = get_post_meta( $loser_id, EVENT_TICKET_URL_META_KEY, true );

			if ( ! empty( $loser_ticket ) && empty( $winner_ticket ) ) {
				update_post_meta( $winner_id, EVENT_TICKET_URL_META_KEY, $loser_ticket );
				$result['ticket_url_merged'] = true;
			}
		}

		$trashed = wp_trash_post( $loser_id );
		if ( ! $trashed ) {
			$result['error'] = sprintf( 'Failed to trash post %d.', $loser_id );
			return $result;
		}

		$result['trashed'] = true;
		$result['success'] = true;

		return $result;
	}
}
