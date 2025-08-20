<?php
/**
 * Event Duplicate Checker for Chill Events
 *
 * Provides a reusable utility for checking if an event already exists,
 * based on title and start date. Designed for use by all data sources
 * and the import executor to prevent duplicate event imports.
 *
 * @package ChillEvents\Events
 * @author Chris Huber
 * @link https://chubes.net
 * @since 1.0.0
 */

namespace ChillEvents\Events;

if (!defined('ABSPATH')) {
    exit;
}

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
        error_log('[ChillEvents][DEBUG] event_exists check: title=' . $title . ' | start_date=' . $start_date . ' | formatted=' . $start_date_formatted);
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
        error_log('[ChillEvents][DEBUG] event_exists SQL: ' . $query);
        $result = $wpdb->get_var($query);
        error_log('[ChillEvents][DEBUG] event_exists result: ' . var_export($result, true));
        return !empty($result);
    }
}

/**
 * Usage:
 *   if (EventDuplicateChecker::event_exists($title, $start_date)) {
 *       // Duplicate found, skip import
 *   }
 */ 