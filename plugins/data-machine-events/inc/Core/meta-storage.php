<?php
/**
 * Event DateTime Meta Storage
 *
 * Core plugin feature that stores event datetime in post meta for efficient SQL queries.
 * Monitors Event Details block changes and syncs to post meta automatically.
 *
 * @package DataMachine_Events
 */

namespace DataMachineEvents\Core;

const EVENT_DATETIME_META_KEY     = '_datamachine_event_datetime';
const EVENT_END_DATETIME_META_KEY = '_datamachine_event_end_datetime';
const EVENT_TICKET_URL_META_KEY   = '_datamachine_ticket_url';

/**
 * Normalize ticket URL for consistent duplicate detection
 *
 * Strips query parameters (UTM tracking, etc.) and trailing slashes
 * to ensure the same ticket page matches regardless of tracking params.
 *
 * @param string $url Raw ticket URL
 * @return string Normalized URL (scheme + host + path only)
 */
function datamachine_normalize_ticket_url( string $url ): string {
	if ( empty( $url ) ) {
		return '';
	}

	$parsed = wp_parse_url( $url );
	if ( ! $parsed || empty( $parsed['host'] ) ) {
		return esc_url_raw( $url );
	}

	$scheme     = $parsed['scheme'] ?? 'https';
	$normalized = $scheme . '://' . $parsed['host'];
	if ( ! empty( $parsed['path'] ) ) {
		$normalized .= $parsed['path'];
	}

	return rtrim( $normalized, '/' );
}

/**
 * Sync event datetime to post meta on save
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update.
 */
function datamachine_events_sync_datetime_meta( $post_id, $post, $update ) {
	// Only for datamachine_events post type.
	if ( Event_Post_Type::POST_TYPE !== $post->post_type ) {
		return;
	}

	// Avoid infinite loops during autosave.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	// Parse blocks to extract event details from Event Details block.
	$blocks = parse_blocks( $post->post_content );

	foreach ( $blocks as $block ) {
		if ( 'datamachine-events/event-details' === $block['blockName'] ) {
			$start_date = $block['attrs']['startDate'] ?? '';
			$start_time = $block['attrs']['startTime'] ?? '00:00:00';
			$end_date   = $block['attrs']['endDate'] ?? '';
			$end_time   = $block['attrs']['endTime'] ?? '';

			$start_time_parts = explode( ':', $start_time );
			if ( count( $start_time_parts ) === 2 ) {
				$start_time .= ':00';
			}

			$end_time_parts = explode( ':', $end_time );
			if ( $end_time && count( $end_time_parts ) === 2 ) {
				$end_time .= ':00';
			}

			if ( $start_date ) {
				$datetime = $start_date . ' ' . $start_time;
				update_post_meta( $post_id, EVENT_DATETIME_META_KEY, $datetime );

				if ( $end_date ) {
					$effective_end_time = $end_time ? $end_time : '23:59:59';
					$end_datetime_val   = $end_date . ' ' . $effective_end_time;
				} else {
					try {
						$start_dt = new \DateTime( $datetime );
						$start_dt->modify( '+3 hours' );
						$end_datetime_val = $start_dt->format( 'Y-m-d H:i:s' );
					} catch ( \Exception $e ) {
						$end_datetime_val = $datetime;
					}
				}
				update_post_meta( $post_id, EVENT_END_DATETIME_META_KEY, $end_datetime_val );
			} else {
				// No date found, delete meta if it exists.
				delete_post_meta( $post_id, EVENT_DATETIME_META_KEY );
				delete_post_meta( $post_id, EVENT_END_DATETIME_META_KEY );
			}

			// Sync ticket URL for duplicate detection queries.
			$ticket_url = $block['attrs']['ticketUrl'] ?? '';
			if ( $ticket_url ) {
				update_post_meta( $post_id, EVENT_TICKET_URL_META_KEY, datamachine_normalize_ticket_url( $ticket_url ) );
			} else {
				delete_post_meta( $post_id, EVENT_TICKET_URL_META_KEY );
			}

			break;
		}
	}
}
add_action( 'save_post', __NAMESPACE__ . '\\datamachine_events_sync_datetime_meta', 10, 3 );
