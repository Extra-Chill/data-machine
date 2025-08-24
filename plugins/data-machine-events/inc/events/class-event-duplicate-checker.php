<?php
/**
 * Event Duplicate Checker for Chill Events
 *
 * Utility for preventing duplicate event creation based on title and start date matching.
 * Used by Data Machine import handlers to avoid creating duplicate events.
 *
 * @package ChillEvents\Events
 * @since 1.0.0
 */

namespace ChillEvents\Events;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks for existing events to prevent duplicates during import operations
 * 
 * Compares event title and start date using case-insensitive matching.
 * 
 * @since 1.0.0
 */
class EventDuplicateChecker {
    /**
     * Check if an event already exists by title and start date.
     *
     * @param string $title      Event title
     * @param string $start_date Event start date (Y-m-d or Y-m-d H:i:s)
     * @return bool True if duplicate exists, false otherwise
     */
    public static function event_exists($title, $start_date) {
        global $wpdb;
        $start_date_formatted = date('Y-m-d H:i:s', strtotime($start_date));
        
        $query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'chill_events'
               AND p.post_status = 'publish'
               AND pm.meta_key = '_chill_event_start_date'
               AND pm.meta_value = %s
               AND LOWER(p.post_title) = LOWER(%s)",
            $start_date_formatted,
            $title
        );
        
        $result = $wpdb->get_var($query);
        return !empty($result);
    }
}

/**
 * Usage:
 *   if (EventDuplicateChecker::event_exists($title, $start_date)) {
 *       // Duplicate found, handle accordingly
 *   }
 */ 