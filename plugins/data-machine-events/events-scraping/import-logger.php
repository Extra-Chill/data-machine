<?php
/**
 * import-logger.php
 * 
 * Handles logging of import events to the WordPress options table.
 */

/**
 * Log import events to an option for admin display.
 *
 * @param string $source     The source of the import (e.g., 'cron ticketmaster', 'manual scraping').
 * @param int    $num_events The number of events imported.
 */
function log_import_event($source, $num_events) {
    $logs = get_option('event_import_logs', []);

    // Add new log entry.
    $logs[] = [
        'source'     => sanitize_text_field($source),
        'num_events' => intval($num_events),
        'timestamp'  => current_time('mysql'),
    ];

    // Keep only the last 5 logs.
    if (count($logs) > 5) {
        $logs = array_slice($logs, -5);
    }

    update_option('event_import_logs', $logs);
}


