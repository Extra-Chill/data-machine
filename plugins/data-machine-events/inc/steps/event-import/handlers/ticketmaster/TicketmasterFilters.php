<?php
/**
 * Ticketmaster Handler Registration
 * 
 * Registers the Ticketmaster event import handler with Data Machine.
 *
 * @package DmEvents\Steps\EventImport\Handlers\Ticketmaster
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Ticketmaster event import handler with Data Machine
 * 
 * Adds the ticketmaster_events handler to Data Machine's handler registry.
 * This handler will be available in the Event Import step configuration.
 */
add_filter('dm_handlers', function($handlers) {
    $handlers['ticketmaster_events'] = [
        'type' => 'event_import',
        'class' => 'DmEvents\\Steps\\EventImport\\Handlers\\Ticketmaster\\Ticketmaster',
        'label' => __('Ticketmaster Events', 'dm-events'),
        'description' => __('Import events from Ticketmaster Discovery API', 'dm-events')
    ];
    
    return $handlers;
});

/**
 * Register Ticketmaster authentication provider with Data Machine
 * 
 * Adds the Ticketmaster auth provider to Data Machine's auth system.
 * This enables the authentication modal for API key configuration.
 */
add_filter('dm_auth_providers', function($providers) {
    $providers['ticketmaster_events'] = new DmEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterAuth();
    return $providers;
});

/**
 * Register Ticketmaster settings provider with Data Machine
 * 
 * Adds the Ticketmaster settings provider to Data Machine's settings system.
 * This enables the configuration UI for handler parameters.
 */
add_filter('dm_handler_settings', function($all_settings) {
    $all_settings['ticketmaster_events'] = new DmEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterSettings();
    return $all_settings;
});