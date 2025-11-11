<?php
/**
 * Ticketmaster Handler Registration
 * 
 * Registers the Ticketmaster event import handler with Data Machine.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handler registration is centralized in EventImportFilters.php to avoid duplicates

/**
 * Register Ticketmaster authentication provider with Data Machine
 * 
 * Adds the Ticketmaster auth provider to Data Machine's auth system.
 * This enables the authentication modal for API key configuration.
 */
add_filter('datamachine_auth_providers', function($providers) {
    $providers['ticketmaster_events'] = new DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterAuth();
    return $providers;
});

/**
 * Register Ticketmaster settings provider with Data Machine
 * 
 * Adds the Ticketmaster settings provider to Data Machine's settings system.
 * This enables the configuration UI for handler parameters.
 */
add_filter('datamachine_handler_settings', function($all_settings) {
    $all_settings['ticketmaster_events'] = new DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterSettings();
    return $all_settings;
});