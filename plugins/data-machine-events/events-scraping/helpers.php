<?php
/**
 * helpers.php
 * 
 * Contains helper functions for event aggregation and posting.
 */

/**
 * Check if an event already exists based on title and start date.
 *
 * @param string $title      The title of the event.
 * @param string $start_date The start date of the event in 'Y-m-d H:i:s' format.
 *
 * @return bool True if the event exists, false otherwise.
 */
function event_already_exists($title, $start_date) {
    global $wpdb;

    // Convert start_date to the exact format stored in wp_postmeta
    $start_date_formatted = date('Y-m-d H:i:s', strtotime($start_date));

    // Use exact matching instead of LIKE
    $query = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = 'tribe_events' 
        AND p.post_status = 'publish'
        AND pm.meta_key = '_EventStartDate' 
        AND pm.meta_value = %s
        AND LOWER(p.post_title) = LOWER(%s)",
        $start_date_formatted,
        $title
    );

    $matches = $wpdb->get_var($query);

    return ($matches > 0);
}


/**
 * Filter events to include only future events.
 *
 * @param array $events An array of events.
 *
 * @return array An array of future events.
 */
function filter_future_events($events) {
    return array_filter($events, function($event) {
        $eventDate = strtotime($event['start_date']);
        return $eventDate > current_time('timestamp');
    });
}

/**
 * Retrieve the Ticketmaster API key from wp-config.php.
 *
 * @return string The Ticketmaster API key.
 */
function get_ticketmaster_api_key() {
    return defined('TICKETMASTER_API_KEY') ? TICKETMASTER_API_KEY : '';
}

/**
 * Retrieve the Events Calendar API authorization from wp-config.php.
 *
 * @return string The Events Calendar API authorization string.
 */
function get_events_calendar_auth() {
    return defined('EVENTS_CALENDAR_AUTH') ? EVENTS_CALENDAR_AUTH : '';
}

/**
 * Handle deletion of posts by removing their event IDs from the posted list.
 *
 * @param int $post_id The ID of the post being deleted.
 */
function update_posted_event_ids_on_deletion($post_id) {
    // Check if the post is an event post.
    if (get_post_type($post_id) === 'tribe_events') {
        $posted_ids = get_option('posted_ticketmaster_event_ids', []);
        $event_id   = get_post_meta($post_id, '_EventID', true); // Assuming you store Ticketmaster's event ID as post meta.

        // Remove the event ID from the array of posted IDs.
        if (($key = array_search($event_id, $posted_ids)) !== false) {
            unset($posted_ids[$key]);
            update_option('posted_ticketmaster_event_ids', array_values($posted_ids)); // Update the posted IDs.

            // Delete all meta associated with the event.
            $meta_keys = ['_EventVenueID', '_EventOrganizerID', '_EventPrice']; // Add more meta keys as needed.
            foreach ($meta_keys as $meta_key) {
                delete_post_meta($post_id, $meta_key);
            }

            // Log the cleanup process.
            error_log("Cleaned up meta for deleted event ID: {$event_id}");
        }
    }
}
add_action('before_delete_post', 'update_posted_event_ids_on_deletion');
