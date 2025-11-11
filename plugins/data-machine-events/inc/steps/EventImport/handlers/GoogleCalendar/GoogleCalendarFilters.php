<?php
/**
 * Google Calendar Handler Registration
 *
 * Registers the Google Calendar event import handler with Data Machine.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handler registration is centralized in EventImportFilters.php to avoid duplicates

/**
 * Register Google Calendar authentication provider with Data Machine
 *
 * Adds the Google Calendar auth provider to Data Machine's auth system.
 * This enables the authentication modal for URL validation and testing.
 */
add_filter('datamachine_auth_providers', function($providers) {
    $providers['google_calendar'] = new DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar\GoogleCalendarAuth();
    return $providers;
});

/**
 * Register Google Calendar settings provider with Data Machine
 *
 * Adds the Google Calendar settings provider to Data Machine's settings system.
 * This enables the configuration UI for handler parameters.
 */
add_filter('datamachine_handler_settings', function($all_settings) {
    $all_settings['google_calendar'] = new DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar\GoogleCalendarSettings();
    return $all_settings;
});