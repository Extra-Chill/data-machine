<?php
/**
 * Event Data Manager
 *
 * Handles synchronization between Event Details blocks and meta fields for performance optimization.
 * Block attributes serve as the single source of truth, with meta fields maintained for efficient queries.
 *
 * @package ChillEvents\Events
 * @since 1.0.0
 */

namespace ChillEvents\Events;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Synchronizes Event Details block data to meta fields for efficient calendar queries
 * 
 * Maintains block-first architecture while enabling performant date-based event queries.
 * 
 * @since 1.0.0
 */
class Event_Data_Manager {
    /**
     * Initialize the data manager
     */
    public function __construct() {
        add_action('save_post_chill_events', array($this, 'sync_block_data_to_meta_index'), 10, 2);
    }

    /**
     * On post save, parse the Event Details block and save the start date
     * to a meta field for efficient sorting.
     *
     * @param int     $post_id The ID of the post being saved.
     * @param \WP_Post $post    The post object.
     */
    public function sync_block_data_to_meta_index($post_id, $post) {
        // Security checks: nonce is checked by WP, but we should check for autosave/revisions.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        // No need to check permissions, 'save_post_{post_type}' only fires if the user has edit rights.

        // Check if the post has blocks
        if (has_blocks($post->post_content)) {
            $blocks = parse_blocks($post->post_content);
            $start_datetime_utc = null;

            foreach ($blocks as $block) {
                if ('chill-events/event-details' === $block['blockName']) {
                    $attributes = $block['attrs'];
                    
                    if (!empty($attributes['startDate'])) {
                        // Combine date and time, defaulting time if it's missing
                        $time_part = !empty($attributes['startTime']) ? $attributes['startTime'] : '00:00:00';
                        $datetime_string = $attributes['startDate'] . ' ' . $time_part;
                        
                        // Create a DateTime object and format it to UTC for consistent sorting
                        try {
                            $start_datetime_obj = new \DateTime($datetime_string);
                            $start_datetime_utc = $start_datetime_obj->format('Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            // Invalid date format, do nothing
                            $start_datetime_utc = null;
                        }
                    }
                    break; // Found our block, no need to continue looping
                }
            }

            if ($start_datetime_utc) {
                update_post_meta($post_id, '_chill_event_start_date_utc', $start_datetime_utc);
            } else {
                // If there's no valid start date in the block, remove the meta key
                delete_post_meta($post_id, '_chill_event_start_date_utc');
            }
        }
    }
} 