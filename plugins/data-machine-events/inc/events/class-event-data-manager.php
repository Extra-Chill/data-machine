<?php
/**
 * Event Data Manager
 *
 * Handles synchronization between Event Details blocks and meta fields for performance optimization.
 * Block attributes serve as the single source of truth, with meta fields maintained for efficient queries.
 *
 * @package DmEvents\Events
 * @since 1.0.0
 */

namespace DmEvents\Events;

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
     * Debug logging helper
     */
    private function debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("DEBUG Event_Data_Manager: " . $message);
        }
    }
    
    /**
     * Initialize the data manager
     */
    public function __construct() {
        $this->debug_log("Constructor called, registering save_post_dm_events hook");
        add_action('save_post_dm_events', array($this, 'sync_block_data_to_meta_index'), 10, 2);
    }
    
    /**
     * Static method to sync event meta for programmatic post creation
     * 
     * @param int $post_id Post ID
     */
    public static function sync_event_meta($post_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("DEBUG Event_Data_Manager: Static sync_event_meta() called for post_id: {$post_id}");
        }
        
        try {
            if (!$post_id || !is_numeric($post_id)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("DEBUG Event_Data_Manager: Invalid post_id in static method: " . print_r($post_id, true));
                }
                return;
            }
            
            $post = get_post($post_id);
            if (!$post) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("DEBUG Event_Data_Manager: Could not retrieve post for ID: {$post_id}");
                }
                return;
            }
            
            if ($post->post_type !== 'dm_events') {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("DEBUG Event_Data_Manager: Post is not dm_events type: " . $post->post_type);
                }
                return;
            }
            
            $instance = new self();
            $instance->sync_block_data_to_meta_index($post_id, $post);
            
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("DEBUG Event_Data_Manager: Exception in static sync_event_meta(): " . $e->getMessage());
            }
        }
    }

    /**
     * On post save, parse the Event Details block and save the start date
     * to a meta field for efficient sorting.
     *
     * @param int     $post_id The ID of the post being saved.
     * @param \WP_Post $post    The post object.
     */
    public function sync_block_data_to_meta_index($post_id, $post) {
        $this->debug_log("sync_block_data_to_meta_index() called for post_id: {$post_id}");
        
        // Capture any output that might interfere with JSON response
        ob_start();
        
        try {
            // Validate inputs
            if (!$post_id || !is_numeric($post_id)) {
                $this->debug_log("Invalid post_id: " . print_r($post_id, true));
                ob_end_clean();
                return;
            }
            
            if (!$post || !is_object($post)) {
                $this->debug_log("Invalid post object");
                ob_end_clean();
                return;
            }
            
            // Security checks: nonce is checked by WP, but we should check for autosave/revisions.
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                $this->debug_log("Skipping sync - DOING_AUTOSAVE is true");
                ob_end_clean();
                return;
            }
            if (wp_is_post_revision($post_id)) {
                $this->debug_log("Skipping sync - post is a revision");
                ob_end_clean();
                return;
            }
            // No need to check permissions, 'save_post_{post_type}' only fires if the user has edit rights.

            if (!isset($post->post_content)) {
                $this->debug_log("Post content not set");
                ob_end_clean();
                return;
            }
            
            // Check if required WordPress functions are available
            if (!function_exists('has_blocks')) {
                $this->debug_log("has_blocks() function not available");
                ob_end_clean();
                return;
            }
            
            if (!function_exists('parse_blocks')) {
                $this->debug_log("parse_blocks() function not available");
                ob_end_clean();
                return;
            }
            
            if (!function_exists('update_post_meta')) {
                $this->debug_log("update_post_meta() function not available");
                ob_end_clean();
                return;
            }

            // Check if the post has blocks
            if (has_blocks($post->post_content)) {
                $blocks = parse_blocks($post->post_content);
                
                if (!is_array($blocks)) {
                    $this->debug_log("parse_blocks() returned non-array: " . gettype($blocks));
                    ob_end_clean();
                    return;
                }
                
                $this->debug_log("Found " . count($blocks) . " blocks");
                $start_datetime_utc = null;

                foreach ($blocks as $index => $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    
                    if ('dm-events/event-details' === $block['blockName']) {
                        $this->debug_log("Found event-details block at index {$index}");
                        
                        $attributes = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
                        
                        if (!empty($attributes['startDate'])) {
                            // Combine date and time, defaulting time if it's missing
                            $time_part = !empty($attributes['startTime']) ? $attributes['startTime'] : '00:00:00';
                            $datetime_string = $attributes['startDate'] . ' ' . $time_part;
                            
                            $this->debug_log("Processing datetime: {$datetime_string}");
                            
                            // Create a DateTime object and format it to UTC for consistent sorting
                            try {
                                $start_datetime_obj = new \DateTime($datetime_string);
                                $start_datetime_utc = $start_datetime_obj->format('Y-m-d H:i:s');
                            } catch (\Exception $e) {
                                $this->debug_log("DateTime creation failed: " . $e->getMessage());
                                $start_datetime_utc = null;
                            }
                        }
                        break; // Found our block, no need to continue looping
                    }
                }

                if ($start_datetime_utc) {
                    $this->debug_log("Updating meta with UTC date: {$start_datetime_utc}");
                    update_post_meta($post_id, '_dm_event_start_date_utc', $start_datetime_utc);
                    
                    // Sync additional meta fields for REST API and backwards compatibility
                    $this->sync_additional_meta_fields($post_id, $attributes);
                } else {
                    $this->debug_log("No valid start date, removing meta");
                    delete_post_meta($post_id, '_dm_event_start_date_utc');
                    
                    // Clean up additional meta fields when no event data
                    $this->cleanup_additional_meta_fields($post_id);
                }
                
            } else {
                $this->debug_log("Post has no blocks, removing meta if exists");
                delete_post_meta($post_id, '_dm_event_start_date_utc');
                
                // Clean up additional meta fields when no blocks
                $this->cleanup_additional_meta_fields($post_id);
            }
            
        } catch (\Exception $e) {
            $this->debug_log("Exception: " . $e->getMessage());
            // Don't re-throw to avoid breaking the save process
        }
        
        // Clean up any output buffer to prevent JSON response issues
        $output = ob_get_clean();
        if (!empty($output)) {
            $this->debug_log("Unexpected output captured: " . $output);
        }
    }
    
    /**
     * Sync additional meta fields for REST API and backwards compatibility
     * 
     * @param int $post_id Post ID
     * @param array $attributes Block attributes
     */
    private function sync_additional_meta_fields(int $post_id, array $attributes) {
        $this->debug_log("Syncing additional meta fields for REST API");
        
        // Meta field mappings: block attribute => meta field
        $meta_mappings = [
            'startDate' => '_dm_event_start_date',
            'endDate' => '_dm_event_end_date', 
            'venue' => '_dm_event_venue_name',
            'artist' => '_dm_event_artist_name',
            'price' => '_dm_event_price',
            'ticketUrl' => '_dm_event_ticket_url'
        ];
        
        foreach ($meta_mappings as $attr_key => $meta_key) {
            $value = isset($attributes[$attr_key]) ? sanitize_text_field($attributes[$attr_key]) : '';
            
            if (!empty($value)) {
                update_post_meta($post_id, $meta_key, $value);
                $this->debug_log("Updated meta {$meta_key}: {$value}");
            } else {
                delete_post_meta($post_id, $meta_key);
                $this->debug_log("Cleared empty meta {$meta_key}");
            }
        }
    }
    
    /**
     * Clean up additional meta fields when event data is removed
     * 
     * @param int $post_id Post ID
     */
    private function cleanup_additional_meta_fields(int $post_id) {
        $this->debug_log("Cleaning up additional meta fields");
        
        $meta_fields = [
            '_dm_event_start_date',
            '_dm_event_end_date',
            '_dm_event_venue_name', 
            '_dm_event_artist_name',
            '_dm_event_price',
            '_dm_event_ticket_url'
        ];
        
        foreach ($meta_fields as $meta_key) {
            delete_post_meta($post_id, $meta_key);
        }
    }
} 