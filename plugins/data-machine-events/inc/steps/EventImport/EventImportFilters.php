<?php
/**
 * Event Import System Registration
 * 
 * Registers event import step type, handlers, and universal venue parameter injection.
 * Venue parameters are injected via dm_engine_parameters filter making them available
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
 * Universal venue parameter injection via Data Machine engine
 * 
 * Extracts venue data from event import sources and injects as engine parameters.
 * This makes venue data universally available to ALL subsequent steps (AI, Publish, Update)
 * following the same pattern as WordPress Local handler's source_url injection.
 */
add_filter('datamachine_engine_parameters', function($parameters, $data, $flow_step_config, $step_type, $flow_step_id) {
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
    
    do_action('datamachine_log', 'debug', 'DataMachineEvents: Injecting venue parameters from event import', [
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
        $venue_fields = ['venue_address', 'venue_city', 'venue_state', 'venue_zip',
                        'venue_country', 'venue_phone', 'venue_website', 'venue_coordinates',
                        'venue_capacity'];

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
        do_action('datamachine_log', 'debug', 'DataMachineEvents: Venue parameters injected successfully', [
            'source_type' => $source_type,
            'injected_fields' => array_keys($injected_fields),
            'venue_name' => $injected_fields['venue'] ?? 'N/A',
            'total_parameters' => count($parameters)
        ]);
    }

    return $parameters;
}, 10, 5);

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
    if (!$screen || strpos($screen->id, 'dm-') === false) {
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

    // Localize script with AJAX configuration
    wp_localize_script('datamachine-events-venue-selector', 'dmEventsVenue', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('datamachine_events_venue_nonce'),
        'actions' => array(
            'getVenueData' => 'dm_events_get_venue_data',
            'checkDuplicate' => 'dm_events_check_duplicate_venue'
        )
    ));

    // Enqueue CSS
    wp_enqueue_style(
        'datamachine-events-venue-autocomplete',
        DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/css/venue-autocomplete.css',
        array(),
        filemtime(DATAMACHINE_EVENTS_PLUGIN_DIR . 'assets/css/venue-autocomplete.css')
    );
});

/**
 * AJAX handler: Get venue data by term ID
 *
 * Retrieves complete venue data including all metadata fields for populating
 * the venue form when an existing venue is selected from the dropdown.
 */
add_action('wp_ajax_dm_events_get_venue_data', function() {
    // Verify nonce
    check_ajax_referer('datamachine_events_venue_nonce', 'nonce');

    $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;

    if (!$term_id) {
        wp_send_json_error(array(
            'message' => __('Invalid venue ID', 'datamachine-events')
        ));
    }

    // Verify term exists
    if (!term_exists($term_id, 'venue')) {
        wp_send_json_error(array(
            'message' => __('Venue not found', 'datamachine-events')
        ));
    }

    // Get venue data using Venue_Taxonomy class
    $venue_data = \DataMachineEvents\Core\Venue_Taxonomy::get_venue_data($term_id);

    if (empty($venue_data)) {
        wp_send_json_error(array(
            'message' => __('Failed to load venue data', 'datamachine-events')
        ));
    }

    wp_send_json_success($venue_data);
});

/**
 * AJAX handler: Check for duplicate venue
 *
 * Checks if a venue with the given name and address already exists
 * before allowing creation of a new venue. Prevents duplicate venues
 * while allowing user confirmation for intentional duplicates.
 */
add_action('wp_ajax_dm_events_check_duplicate_venue', function() {
    // Verify nonce
    check_ajax_referer('datamachine_events_venue_nonce', 'nonce');

    $venue_name = isset($_POST['venue_name']) ? sanitize_text_field($_POST['venue_name']) : '';
    $venue_address = isset($_POST['venue_address']) ? sanitize_text_field($_POST['venue_address']) : '';

    if (empty($venue_name)) {
        wp_send_json_success(array(
            'is_duplicate' => false,
            'message' => ''
        ));
        return;
    }

    // Check for exact name match
    $existing = get_term_by('name', $venue_name, 'venue');

    if (!$existing) {
        wp_send_json_success(array(
            'is_duplicate' => false,
            'message' => ''
        ));
        return;
    }

    // Check if address also matches (if provided)
    if (!empty($venue_address)) {
        $stored_address = get_term_meta($existing->term_id, '_venue_address', true);

        // Normalize for comparison
        $stored_address_normalized = trim(strtolower($stored_address));
        $venue_address_normalized = trim(strtolower($venue_address));

        if ($stored_address_normalized === $venue_address_normalized) {
            wp_send_json_success(array(
                'is_duplicate' => true,
                'existing_term_id' => $existing->term_id,
                'existing_venue_name' => $existing->name,
                'message' => sprintf(
                    __('A venue named "%s" with this address already exists.', 'datamachine-events'),
                    $existing->name
                )
            ));
            return;
        }
    }

    // Name matches but address doesn't - still warn but less severe
    wp_send_json_success(array(
        'is_duplicate' => true,
        'existing_term_id' => $existing->term_id,
        'existing_venue_name' => $existing->name,
        'message' => sprintf(
            __('A venue named "%s" already exists (different address). Create anyway?', 'datamachine-events'),
            $existing->name
        )
    ));
});