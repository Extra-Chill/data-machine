<?php
/**
 * Event Identity Writer
 *
 * Writes identity rows to the PostIdentityIndex table whenever an event
 * post is created or updated. Called from EventUpsert after successful
 * create/update, and from a save_post hook for manual edits.
 *
 * @package DataMachineEvents\Core\DuplicateDetection
 * @since   0.18.0
 */

namespace DataMachineEvents\Core\DuplicateDetection;

use DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex;
use DataMachineEvents\Core\Event_Post_Type;
use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;

defined( 'ABSPATH' ) || exit;

class EventIdentityWriter {

	/**
	 * Register hooks to keep identity rows in sync with event posts.
	 */
	public static function register(): void {
		// Update identity row when event meta is saved (covers both create and update).
		add_action( 'updated_post_meta', array( static::class, 'onMetaChange' ), 10, 4 );
		add_action( 'added_post_meta', array( static::class, 'onMetaChange' ), 10, 4 );
	}

	/**
	 * React to postmeta changes and sync the identity index.
	 *
	 * Triggered when _datamachine_event_datetime or _datamachine_ticket_url
	 * is written/updated. Rebuilds the full identity row from current state.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function onMetaChange( $meta_id, $post_id, $meta_key, $meta_value ): void {
		// Only react to event identity meta keys.
		if ( EVENT_DATETIME_META_KEY !== $meta_key && EVENT_TICKET_URL_META_KEY !== $meta_key ) {
			return;
		}

		// Only for event posts.
		$post_type = get_post_type( $post_id );
		if ( Event_Post_Type::POST_TYPE !== $post_type ) {
			return;
		}

		self::syncIdentityRow( (int) $post_id );
	}

	/**
	 * Write or update the identity row for an event post.
	 *
	 * Reads current postmeta + taxonomy state and builds the identity fields.
	 * Can be called directly from EventUpsert for immediate sync, or
	 * from the meta change hook for passive sync.
	 *
	 * @param int         $post_id    Event post ID.
	 * @param string|null $title      Title override (avoids extra get_the_title call).
	 * @param string|null $ticket_url Ticket URL override (avoids extra meta read).
	 */
	public static function syncIdentityRow( int $post_id, ?string $title = null, ?string $ticket_url = null ): void {
		if ( ! class_exists( PostIdentityIndex::class ) ) {
			return;
		}

		$datetime = get_post_meta( $post_id, EVENT_DATETIME_META_KEY, true );
		if ( empty( $datetime ) ) {
			return;
		}

		// Extract date-only.
		$event_date = '';
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $datetime, $matches ) ) {
			$event_date = $matches[1];
		}

		if ( empty( $event_date ) ) {
			return;
		}

		// Resolve title.
		if ( null === $title ) {
			$title = get_the_title( $post_id );
		}

		// Resolve venue term ID.
		$venue_term_id = 0;
		$venue_terms   = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) {
			$venue_term_id = (int) $venue_terms[0];
		}

		// Resolve ticket URL.
		if ( null === $ticket_url ) {
			$ticket_url = get_post_meta( $post_id, EVENT_TICKET_URL_META_KEY, true );
		}

		// Compute title hash.
		$title_hash = EventDuplicateStrategy::computeTitleHash( $title );

		$index = new PostIdentityIndex();
		$index->upsert(
			$post_id,
			array(
				'post_type'     => Event_Post_Type::POST_TYPE,
				'event_date'    => $event_date,
				'venue_term_id' => $venue_term_id,
				'ticket_url'    => $ticket_url ?: null,
				'title_hash'    => $title_hash,
			)
		);
	}

	/**
	 * Backfill identity rows for existing events.
	 *
	 * Called during migration or via CLI. Processes events in batches.
	 *
	 * @param int      $batch_size Number of events per batch.
	 * @param callable $progress   Optional progress callback (receives count).
	 * @return int Total events processed.
	 */
	public static function backfill( int $batch_size = 500, ?callable $progress = null ): int {
		$index     = new PostIdentityIndex();
		$total     = 0;
		$offset    = 0;

		while ( true ) {
			$missing = $index->find_missing_post_ids( Event_Post_Type::POST_TYPE, $batch_size, $offset );

			if ( empty( $missing ) ) {
				break;
			}

			foreach ( $missing as $post_id ) {
				self::syncIdentityRow( $post_id );
				++$total;
			}

			if ( $progress ) {
				$progress( $total );
			}

			// Don't increment offset — find_missing_post_ids returns new results
			// as we fill in identity rows, effectively shrinking the gap.
			// But safety valve: if we got a full batch, there might be more.
			if ( count( $missing ) < $batch_size ) {
				break;
			}
		}

		return $total;
	}
}
