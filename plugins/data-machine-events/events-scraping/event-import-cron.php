<?php

/**
 * Add a custom 'daily' cron schedule.
 */
add_filter('cron_schedules', 'extra_chill_add_daily_cron_schedule');
function extra_chill_add_daily_cron_schedule($schedules) {
    if (!isset($schedules['daily'])) {
        $schedules['daily'] = array(
            'interval' => 86400, // 1 day in seconds
            'display'  => __('Once Daily', 'extrachill'),
        );
    }
    return $schedules;
}

/**
 * Schedule the daily event import if not already scheduled.
 */
add_action('after_switch_theme', 'extra_chill_register_daily_event_import');
function extra_chill_register_daily_event_import() {
    // Unschedule the old weekly event if it exists
    if (wp_next_scheduled('import_weekly_events_hook')) {
        wp_clear_scheduled_hook('import_weekly_events_hook');
        error_log('Cleared old weekly event schedule.');
    }

    // Schedule the new daily event
    if (!wp_next_scheduled('import_daily_events_hook')) {
        wp_schedule_event(time(), 'daily', 'import_daily_events_hook');
        error_log('Scheduled import_daily_events_hook at ' . date('Y-m-d H:i:s', time()));
    } else {
        $next_run = wp_next_scheduled('import_daily_events_hook');
        error_log('import_daily_events_hook is already scheduled to run at ' . date('Y-m-d H:i:s', $next_run));
    }
}

/**
 * Hook the automated event import function to the new daily scheduled event.
 */
add_action('import_daily_events_hook', 'extra_chill_automated_event_import');

function extra_chill_automated_event_import() {
    error_log('[CRON START] automated_event_import triggered at ' . date('Y-m-d H:i:s'));

    // Post locally scraped events
    error_log('[CRON STEP] Starting local scraping import...');
    // Let the function use its default maxEvents value (10)
    $localEvents = post_aggregated_events_to_calendar();
    if (is_wp_error($localEvents)) {
        error_log('[CRON ERROR] Error posting local events: ' . $localEvents->get_error_message());
        // Optionally log a 0 count or just skip logging for errors
        log_import_event('cron scraping error', 0); 
    } else {
        $addedCount = is_array($localEvents) ? count($localEvents) : 0; // Ensure count is applied to an array
        error_log('[CRON SUCCESS] Local scraping finished. Added ' . $addedCount . ' events.');
        log_import_event('cron scraping', $addedCount);
    }

    // Post Ticketmaster events
    error_log('[CRON STEP] Starting Ticketmaster import...');
    // Let the function use its default maxEvents value (10)
    $ticketmasterEvents = post_ticketmaster_events_to_calendar(null, 'cron ticketmaster'); // Pass null for maxEvents to use default
    if (is_wp_error($ticketmasterEvents)) {
        error_log('[CRON ERROR] Error posting Ticketmaster events: ' . $ticketmasterEvents->get_error_message());
        // Optionally log a 0 count or just skip logging for errors
        log_import_event('cron ticketmaster error', 0);
    } else {
        $totalPosted = is_array($ticketmasterEvents) ? count($ticketmasterEvents) : 0; // Ensure count is applied to an array
        error_log('[CRON SUCCESS] Ticketmaster finished. Added ' . $totalPosted . ' events.');
        log_import_event('cron ticketmaster', $totalPosted);
    }

    // **Post DICE.FM events (for Austin)**
    error_log('[CRON STEP] Starting DICE.FM import...');
    // Let the function use its default maxEvents value (10)
    $diceEvents = post_dice_fm_events_to_calendar();
    if (is_wp_error($diceEvents)) {
        error_log('[CRON ERROR] Error posting DICE.FM events: ' . $diceEvents->get_error_message());
        // Optionally log a 0 count or just skip logging for errors
        log_import_event('cron dice error', 0);
    } else {
        // Ensure $diceEvents is an array before counting
        $diceCount = is_array($diceEvents) ? count($diceEvents) : 0;
        error_log('[CRON SUCCESS] DICE.FM finished. Added ' . $diceCount . ' events.');
        log_import_event('cron dice', $diceCount);
    }
    error_log('[CRON END] automated_event_import finished at ' . date('Y-m-d H:i:s'));
}




