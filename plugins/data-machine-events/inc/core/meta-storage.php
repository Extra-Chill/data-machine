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

			if ( $start_date ) {
				// Combine into MySQL DATETIME format: "2024-12-25 14:30:00".
				$datetime = $start_date . ' ' . $start_time;
				update_post_meta( $post_id, EVENT_DATETIME_META_KEY, $datetime );
			} else {
				// No date found, delete meta if it exists.
				delete_post_meta( $post_id, EVENT_DATETIME_META_KEY );
			}
			break;
		}
	}
}
add_action( 'save_post', __NAMESPACE__ . '\\datamachine_events_sync_datetime_meta', 10, 3 );