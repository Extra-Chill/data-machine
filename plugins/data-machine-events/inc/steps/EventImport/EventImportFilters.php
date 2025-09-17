<?php
/**
 * Event Import System Registration
 * 
 * Registers event import step type, handlers, and universal venue parameter injection.
 * Venue parameters are injected via dm_engine_parameters filter making them available
 * to ALL subsequent steps (AI, Publish, Update) following Data Machine's unified architecture.
 *
 * @package DmEvents\Steps\EventImport
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
add_filter('dm_steps', function($steps) {
    $steps['event_import'] = [
        'label' => __('Event Import', 'dm-events'),
        'description' => __('Import events from venues and ticketing platforms', 'dm-events'),
        'class' => 'DmEvents\\Steps\\EventImport\\EventImportStep',
        'position' => 25 // Position between fetch (20) and AI (30)
    ];
    
    return $steps;
});

/**
 * Register Event Import handlers with Data Machine
 * 
 * Registers all available event import handlers using Data Machine's unified handler registry.
 * This enables native discovery and execution via the dm_handlers filter.
 */
add_filter('dm_handlers', function($handlers) {
    // Ticketmaster Discovery API handler
    $handlers['ticketmaster_events'] = [
        'type' => 'event_import',
        'class' => 'DmEvents\\Steps\\EventImport\\Handlers\\Ticketmaster\\Ticketmaster',
        'label' => __('Ticketmaster Events', 'dm-events'),
        'description' => __('Import events from Ticketmaster Discovery API with venue data', 'dm-events')
    ];
    
    // Dice FM API handler
    $handlers['dice_fm_events'] = [
        'type' => 'event_import',
        'class' => 'DmEvents\\Steps\\EventImport\\Handlers\\DiceFm\\DiceFm',
        'label' => __('Dice FM Events', 'dm-events'),
        'description' => __('Import events from Dice FM API for electronic music venues', 'dm-events')
    ];

    // Google Calendar .ics handler
    $handlers['google_calendar'] = [
        'type' => 'event_import',
        'class' => 'DmEvents\\Steps\\EventImport\\Handlers\\GoogleCalendar\\GoogleCalendar',
        'label' => __('Google Calendar', 'dm-events'),
        'description' => __('Import events from public Google Calendar .ics feeds', 'dm-events')
    ];
    
    return $handlers;
});

/**
 * Universal venue parameter injection via Data Machine engine
 * 
 * Extracts venue data from event import sources and injects as engine parameters.
 * This makes venue data universally available to ALL subsequent steps (AI, Publish, Update)
 * following the same pattern as WordPress Local handler's source_url injection.
 */
add_filter('dm_engine_parameters', function($parameters, $data, $flow_step_config, $step_type, $flow_step_id) {
    // Only process if we have data from previous steps
    if (empty($data) || !is_array($data)) {
        return $parameters;
    }
    
    // Get the most recent data entry (event imports add to front of array)
    $latest_entry = $data[0] ?? [];
    $metadata = $latest_entry['metadata'] ?? [];
    $source_type = $metadata['source_type'] ?? '';
    
    // Only inject parameters for event import sources
    if (!in_array($source_type, ['ticketmaster', 'dice_fm', 'web_scraper', 'google_calendar'])) {
        return $parameters;
    }
    
    do_action('dm_log', 'debug', 'DmEvents: Injecting venue parameters from event import', [
        'source_type' => $source_type,
        'step_type' => $step_type,
        'flow_step_id' => $flow_step_id
    ]);
    
    // Parse event data from content body
    $content_body = $latest_entry['content']['body'] ?? '';
    if (empty($content_body)) {
        return $parameters;
    }
    
    $event_data = json_decode($content_body, true);
    if (!$event_data || !is_array($event_data)) {
        return $parameters;
    }
    
    $injected_fields = [];
    
    // Inject venue name from event data
    if (!empty($event_data['event']['venue'])) {
        $parameters['venue'] = sanitize_text_field($event_data['event']['venue']);
        $injected_fields['venue'] = $event_data['event']['venue'];
    }
    
    // Inject venue metadata if available
    if (!empty($event_data['venue_metadata']) && is_array($event_data['venue_metadata'])) {
        $venue_metadata = $event_data['venue_metadata'];
        $venue_fields = ['venueAddress', 'venueCity', 'venueState', 'venueZip', 
                        'venueCountry', 'venuePhone', 'venueWebsite', 'venueCoordinates'];
        
        foreach ($venue_fields as $field) {
            if (!empty($venue_metadata[$field])) {
                $parameters[$field] = sanitize_text_field($venue_metadata[$field]);
                $injected_fields[$field] = $venue_metadata[$field];
            }
        }
    }
    
    // Inject event image if available
    if (!empty($event_data['event']['image'])) {
        $parameters['eventImage'] = esc_url_raw($event_data['event']['image']);
        $injected_fields['eventImage'] = $event_data['event']['image'];
    }
    
    if (!empty($injected_fields)) {
        do_action('dm_log', 'debug', 'DmEvents: Venue parameters injected successfully', [
            'source_type' => $source_type,
            'injected_fields' => array_keys($injected_fields),
            'venue_name' => $injected_fields['venue'] ?? 'N/A',
            'total_parameters' => count($parameters)
        ]);
    }
    
    return $parameters;
}, 10, 5);