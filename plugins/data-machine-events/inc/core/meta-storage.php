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

const EVENT_DATETIME_META_KEY = '_datamachine_event_datetime';
const EVENT_END_DATETIME_META_KEY = '_datamachine_event_end_datetime';

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

			if ( $start_date ) {
				// Combine into MySQL DATETIME format: "2024-12-25 14:30:00".
				$datetime = $start_date . ' ' . $start_time;
				update_post_meta( $post_id, EVENT_DATETIME_META_KEY, $datetime );

				// Calculate End DateTime.
				if ( $end_date ) {
					// If end date exists, use it. Default to end of day if no time provided.
					$effective_end_time = $end_time ? $end_time : '23:59:59';
					$end_datetime_val   = $end_date . ' ' . $effective_end_time;
				} else {
					// No end date. Calculate start + 3 hours.
					try {
						$start_dt = new \DateTime( $datetime );
						$start_dt->modify( '+3 hours' );
						$end_datetime_val = $start_dt->format( 'Y-m-d H:i:s' );
					} catch ( \Exception $e ) {
						// Fallback to start time if calculation fails.
						$end_datetime_val = $datetime;
					}
				}
				update_post_meta( $post_id, EVENT_END_DATETIME_META_KEY, $end_datetime_val );
			} else {
				// No date found, delete meta if it exists.
				delete_post_meta( $post_id, EVENT_DATETIME_META_KEY );
				delete_post_meta( $post_id, EVENT_END_DATETIME_META_KEY );
			}
			break;
		}
	}
}
add_action( 'save_post', __NAMESPACE__ . '\\datamachine_events_sync_datetime_meta', 10, 3 );