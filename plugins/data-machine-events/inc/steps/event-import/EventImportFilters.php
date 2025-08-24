<?php
/**
 * Event Import Step Registration
 * 
 * Registers the custom Event Import step type with Data Machine.
 * Follows the same pattern as Data Machine's core steps.
 *
 * @package ChillEvents\Steps\EventImport
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
        'label' => __('Event Import', 'chill-events'),
        'description' => __('Import events from venues and ticketing platforms', 'chill-events'),
        'class' => 'ChillEvents\\Steps\\EventImport\\EventImportStep',
        'position' => 25 // Position between fetch (20) and AI (30)
    ];
    
    return $steps;
});