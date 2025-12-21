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

use const DataMachineEvents\Api\API_NAMESPACE;

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

// Handler registration is now decentralized to individual handler filters
// using the HandlerRegistrationTrait for standardization.

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
    if (!$screen || strpos($screen->id, 'datamachine-pipelines') === false) {
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
        'restUrl' => rest_url(API_NAMESPACE),
        'nonce' => wp_create_nonce('wp_rest')
    ));

    // Enqueue CSS
    wp_enqueue_style(
        'datamachine-events-venue-autocomplete',
        DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/css/venue-autocomplete.css',
        array(),
        filemtime(DATAMACHINE_EVENTS_PLUGIN_DIR . 'assets/css/venue-autocomplete.css')
    );

    // Enqueue pipeline hooks JavaScript (extends core React via @wordpress/hooks)
    wp_enqueue_script(
        'datamachine-events-pipeline-hooks',
        DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/js/pipeline-hooks.js',
        array('datamachine-pipelines-react', 'wp-hooks', 'wp-api-fetch'),
        filemtime(DATAMACHINE_EVENTS_PLUGIN_DIR . 'assets/js/pipeline-hooks.js'),
        true
    );

    // Enqueue pipeline React components (custom field types like address-autocomplete)
    wp_enqueue_script(
        'datamachine-events-pipeline-components',
        DATAMACHINE_EVENTS_PLUGIN_URL . 'assets/js/pipeline-components.js',
        array('datamachine-pipelines-react', 'wp-element', 'wp-components', 'wp-hooks', 'wp-i18n'),
        filemtime(DATAMACHINE_EVENTS_PLUGIN_DIR . 'assets/js/pipeline-components.js'),
        true
    );
});