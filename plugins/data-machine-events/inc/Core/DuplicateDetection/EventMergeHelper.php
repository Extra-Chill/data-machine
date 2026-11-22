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

use DataMachine\Core\Database\PostIdentityReservations\PostIdentityReservations;
use DataMachineEvents\Core\EventOwnership;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;

defined( 'ABSPATH' ) || exit;

class EventMergeHelper {

	/**
	 * Post meta on a merged-away loser naming the canonical winner.
	 *
	 * Re-imports of the loser's source resolve to the winner instead of
	 * republishing the trashed duplicate (see EventUpsert).
	 */
	public const MERGED_INTO_META_KEY = '_datamachine_event_merged_into';

	/**
	 * Merge a duplicate event pair.
	 *
	 * Trashes the loser after transferring its URL history to the winner.
	 * Optionally forward-merges the ticket URL into the winner when the winner
	 * has none and the loser has one. Returns a structured result so callers
	 * can report on what happened.
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
		if ( 'publish' !== $winner->post_status ) {
			$result['error'] = 'Winner must be published before receiving redirect history.';
			return $result;
		}

		$merge_ticket_url        = $opts['merge_ticket_url'] ?? true;
		$winner_ticket_values    = get_post_meta( $winner_id, EVENT_TICKET_URL_META_KEY, false );
		$loser_old_slug_values   = get_post_meta( $loser_id, '_wp_old_slug', false );
		$transferred_slugs       = self::transferOldSlugs( $winner, $loser );
		$ticket_url_was_modified = false;

		if ( is_wp_error( $transferred_slugs ) ) {
			$result['error'] = $transferred_slugs->get_error_message();
			return $result;
		}

		if ( is_array( $loser_old_slug_values ) && ! empty( $loser_old_slug_values ) ) {
			// delete_post_meta() has no failure mode that leaves rows behind, so
			// the former re-read-verify loop here was unreachable at runtime.
			delete_post_meta( $loser_id, '_wp_old_slug' );
		}

		if ( $merge_ticket_url ) {
			$winner_ticket = reset( $winner_ticket_values );
			$loser_ticket  = get_post_meta( $loser_id, EVENT_TICKET_URL_META_KEY, true );

			if ( ! empty( $loser_ticket ) && empty( $winner_ticket ) ) {
				if ( false === update_post_meta( $winner_id, EVENT_TICKET_URL_META_KEY, $loser_ticket ) ) {
					self::removeTransferredSlugs( $winner_id, $transferred_slugs );
					self::restoreMetaValues( $loser_id, '_wp_old_slug', $loser_old_slug_values );
					self::restoreMetaValues( $winner_id, EVENT_TICKET_URL_META_KEY, $winner_ticket_values );
					$result['error'] = 'Failed to transfer winner metadata.';
					return $result;
				}
				$ticket_url_was_modified = true;
			}
		}

		$handoff = self::handoffSourceIdentities( $winner_id, $loser_id );
		$trashed = false;
		if ( ! is_wp_error( $handoff ) ) {
			update_post_meta( $loser_id, self::MERGED_INTO_META_KEY, $winner_id );
			$trashed = wp_trash_post( $loser_id );
		}
		if ( ! $trashed ) {
			if ( ! is_wp_error( $handoff ) ) {
				$repository = new PostIdentityReservations();
				$rollback   = $repository->transfer_post_reservations( $winner_id, $loser_id, $handoff['reservations'] );
				if ( is_wp_error( $rollback ) ) {
					do_action( 'datamachine_log', 'error', 'Failed to roll back merged source reservations.', array(
						'winner_id' => $winner_id,
						'loser_id'  => $loser_id,
					) );
				}
				self::removeIdentityLinks( $winner_id, $handoff['links'] );
			}
			delete_post_meta( $loser_id, self::MERGED_INTO_META_KEY );
			self::removeTransferredSlugs( $winner_id, $transferred_slugs );
			self::restoreMetaValues( $loser_id, '_wp_old_slug', $loser_old_slug_values );
			if ( $ticket_url_was_modified ) {
				self::restoreMetaValues( $winner_id, EVENT_TICKET_URL_META_KEY, $winner_ticket_values );
			}
			$result['error'] = is_wp_error( $handoff ) ? $handoff->get_error_message() : sprintf( 'Failed to trash post %d.', $loser_id );
			return $result;
		}

		$result['ticket_url_merged'] = $ticket_url_was_modified;
		$result['trashed']           = true;
		$result['success']           = true;

		return $result;
	}

	/**
	 * Preserve all source claims and durable reservations on the canonical post.
	 *
	 * Also used by the historical merge backfill. Returns only newly added links
	 * and moved reservations, allowing a failed trash to undo this attempt only.
	 *
	 * @return array|\WP_Error
	 */
	public static function handoffSourceIdentities( int $winner_id, int $loser_id ): array|\WP_Error {
		$identities = array_merge(
			get_post_meta( $loser_id, EventUpsert::SOURCE_IDENTITY_META_KEY, false ),
			get_post_meta( $loser_id, EventOwnership::LINKED_SOURCE_IDENTITY_META_KEY, false )
		);
		$links      = array();
		foreach ( array_unique( array_filter( array_map( 'strval', $identities ) ) ) as $identity ) {
			if ( EventOwnership::linksSourceIdentity( $winner_id, $identity ) ) {
				continue;
			}
			EventOwnership::linkSourceIdentity( $winner_id, $identity );
			if ( ! EventOwnership::linksSourceIdentity( $winner_id, $identity ) ) {
				self::removeIdentityLinks( $winner_id, $links );
				return new \WP_Error( 'event_identity_transfer_failed', 'Failed to transfer event source identity.' );
			}
			$links[] = $identity;
		}
		$repository   = new PostIdentityReservations();
		$reservations = $repository->transfer_post_reservations( $loser_id, $winner_id );
		if ( is_wp_error( $reservations ) ) {
			self::removeIdentityLinks( $winner_id, $links );
			return $reservations;
		}
		return array(
			'links'        => $links,
			'reservations' => $reservations,
		);
	}

	/**
	 * Return a merged loser's own source claims when its winner was trashed.
	 *
	 * Merge hands the loser's reservations to the winner. If the winner is later
	 * trashed (not merged elsewhere), re-importing the loser's source restores
	 * the loser, so its own primary identity must point back at it. Only the
	 * loser's primary identity moves; nothing the winner owned is touched.
	 *
	 * @return bool Whether any claim was reclaimed.
	 */
	public static function reclaimFromTrashedWinner( int $loser_id ): bool {
		$winner_id = (int) get_post_meta( $loser_id, self::MERGED_INTO_META_KEY, true );
		if ( $winner_id <= 0 || 'trash' !== get_post_status( $loser_id ) || 'trash' !== get_post_status( $winner_id ) || self::resolveMergedWinner( $loser_id ) > 0 ) {
			return false;
		}
		$identity = (string) get_post_meta( $loser_id, EventUpsert::SOURCE_IDENTITY_META_KEY, true );
		if ( '' === $identity ) {
			return false;
		}
		$normalized = PostIdentityReservations::normalize_identity(
			get_post_type( $loser_id ),
			array(
				'key'   => EventUpsert::SOURCE_IDENTITY_META_KEY,
				'value' => $identity,
			)
		);
		if ( is_wp_error( $normalized ) ) {
			return false;
		}
		$moved = ( new PostIdentityReservations() )->transfer_post_reservations( $winner_id, $loser_id, array( $normalized['identity_hash'] ) );
		if ( is_wp_error( $moved ) ) {
			return false;
		}
		if ( $identity !== (string) get_post_meta( $winner_id, EventUpsert::SOURCE_IDENTITY_META_KEY, true ) ) {
			self::removeIdentityLinks( $winner_id, array( $identity ) );
		}
		return true;
	}

	/** Remove only source identity links created by this attempt. */
	private static function removeIdentityLinks( int $post_id, array $identities ): void {
		foreach ( $identities as $identity ) {
			delete_post_meta( $post_id, EventOwnership::LINKED_SOURCE_IDENTITY_META_KEY, $identity );
		}
	}

	/**
	 * Resolve the live canonical winner for a merged-away event.
	 *
	 * Follows merge chains (A merged into B, later B into C) and returns 0
	 * when the post was not merged, or its winner is no longer published.
	 *
	 * @param int $post_id Possibly merged-away event post ID.
	 * @return int Published winner post ID, or 0.
	 */
	public static function resolveMergedWinner( int $post_id ): int {
		$seen = array();
		while ( $post_id > 0 && ! isset( $seen[ $post_id ] ) && 'trash' === get_post_status( $post_id ) ) {
			$seen[ $post_id ] = true;
			$post_id          = (int) get_post_meta( $post_id, self::MERGED_INTO_META_KEY, true );
		}

		return ( $post_id > 0 && ! isset( $seen[ $post_id ] ) && 'publish' === get_post_status( $post_id ) ) ? $post_id : 0;
	}

	/**
	 * Transfer the loser's routable slug history to the canonical winner.
	 *
	 * WordPress core's wp_old_slug_redirect() resolves these values without a
	 * plugin-owned redirect service.
	 *
	 * @param \WP_Post $winner Canonical event post.
	 * @param \WP_Post $loser  Duplicate event post being removed.
	 * @return string[]|\WP_Error Slugs newly added to the winner, or an error.
	 */
	private static function transferOldSlugs( \WP_Post $winner, \WP_Post $loser ): array|\WP_Error {
		$original_values = get_post_meta( $winner->ID, '_wp_old_slug', false );
		$winner_slugs    = array_merge(
			array( $winner->post_name ),
			$original_values
		);
		$loser_slugs     = array_merge(
			array( $loser->post_name ),
			get_post_meta( $loser->ID, '_wp_old_slug', false )
		);
		$slugs           = array_values( array_unique( array_filter( array_map( 'sanitize_title', $loser_slugs ) ) ) );
		$existing        = array_values( array_unique( array_filter( array_map( 'sanitize_title', $winner_slugs ) ) ) );
		$transferred     = array();

		foreach ( array_diff( $slugs, $existing ) as $slug ) {
			if ( ! add_post_meta( $winner->ID, '_wp_old_slug', $slug ) ) {
				self::removeTransferredSlugs( $winner->ID, $transferred );
				return new \WP_Error( 'event_slug_transfer_failed', 'Failed to transfer event URL history.' );
			}
			$transferred[] = $slug;
		}

		return $transferred;
	}

	/** Remove only old-slug values added by the current merge attempt. */
	private static function removeTransferredSlugs( int $post_id, array $slugs ): void {
		foreach ( $slugs as $slug ) {
			delete_post_meta( $post_id, '_wp_old_slug', $slug );
		}
	}

	/** Restore all values for metadata mutated before trashing the loser. */
	private static function restoreMetaValues( int $post_id, string $meta_key, array $values ): void {
		delete_post_meta( $post_id, $meta_key );
		foreach ( $values as $value ) {
			add_post_meta( $post_id, $meta_key, $value );
		}
	}
}
