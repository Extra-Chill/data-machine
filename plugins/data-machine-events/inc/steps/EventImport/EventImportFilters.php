<?php
/**
 * Event Import System Registration
 * 
 * Registers event import step type, handlers, and universal venue parameter injection.
 * Venue parameters are persisted via engine data making them available
 * to ALL subsequent steps (AI, Publish, Update) following Data Machine's unified architecture.
 *
 * @package DataMachineEvents\Steps\EventImport
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Event Import step type with Data Machine
 * 
 * Adds the event_import step type to Data Machine's step registry.
 * This step will appear in the pipeline builder as an available step.
 */
add_filter('datamachine_step_types', function($steps) {
    $steps['event_import'] = [
        'label' => __('Event Import', 'datamachine-events'),
        'description' => __('Import events from venues and ticketing platforms', 'datamachine-events'),
        'class' => 'DataMachineEvents\\Steps\\EventImport\\EventImportStep',
        'position' => 25 // Position between fetch (20) and AI (30)
    ];
    
    return $steps;
});

/**
 * Register Event Import handlers with Data Machine
 *
 * Registers all available event import handlers using Data Machine's unified handler registry.
 * This enables native discovery and execution via the datamachine_handlers filter.
 */
add_filter('datamachine_handlers', function($handlers, $step_type = null) {
    // Only register event_import handlers when requested
    if ($step_type === null || $step_type === 'event_import') {
        // Ticketmaster Discovery API handler
        $handlers['ticketmaster_events'] = [
            'type' => 'event_import',
            'class' => 'DataMachineEvents\\Steps\\EventImport\\Handlers\\Ticketmaster\\Ticketmaster',
            'label' => __('Ticketmaster Events', 'datamachine-events'),
            'description' => __('Import events from Ticketmaster Discovery API with venue data', 'datamachine-events')
        ];

        // Dice FM API handler
        $handlers['dice_fm_events'] = [
            'type' => 'event_import',
            'class' => 'DataMachineEvents\\Steps\\EventImport\\Handlers\\DiceFm\\DiceFm',
            'label' => __('Dice FM Events', 'datamachine-events'),
            'description' => __('Import events from Dice FM API for electronic music venues', 'datamachine-events')
        ];

        // Google Calendar .ics handler
        $handlers['google_calendar'] = [
            'type' => 'event_import',
            'class' => 'DataMachineEvents\\Steps\\EventImport\\Handlers\\GoogleCalendar\\GoogleCalendar',
            'label' => __('Google Calendar', 'datamachine-events'),
            'description' => __('Import events from public Google Calendar .ics feeds', 'datamachine-events')
        ];
    }

    return $handlers;
}, 10, 2);

/**
 * Enqueue venue autocomplete and selector assets on Data Machine admin pages
 *
 * Loads Nominatim-powered address autocomplete and venue selector dropdown
 * JavaScript/CSS only on Data Machine settings pages where venue fields are displayed.
 */
add_action('admin_enqueue_scripts', function() {
    // Only load on Data Machine settings pages
    if (!is_admin()) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'datamachine') === false) {
        return;
    }

    // Enqueue venue autocomplete JavaScript
    wp_enqueue_script(
        'datamachine-events-venue-autocomplete',
        DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/js/venue-autocomplete.js',
        array('jquery'),
        filemtime(DATAMACHINE_EVENTS_PLUGIN_DIR . 'assets/js/venue-autocomplete.js'),
        true
    );

    // Enqueue venue selector JavaScript
    wp_enqueue_script(
        'datamachine-events-venue-selector',
        DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/js/venue-selector.js',
        array('jquery', 'datamachine-events-venue-autocomplete'),
        filemtime(DATAMACHINE_EVENTS_PLUGIN_DIR . 'assets/js/venue-selector.js'),
        true
    );

    // Localize script with REST API configuration
    wp_localize_script('datamachine-events-venue-selector', 'dmEventsVenue', array(
        'restUrl' => rest_url('datamachine/v1'),
        'nonce' => wp_create_nonce('wp_rest')
    ));

    // Enqueue CSS
    wp_enqueue_style(
        'datamachine-events-venue-autocomplete',
        DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/css/venue-autocomplete.css',
        array(),
        filemtime(DATAMACHINE_EVENTS_PLUGIN_DIR . 'assets/css/venue-autocomplete.css')
    );
});