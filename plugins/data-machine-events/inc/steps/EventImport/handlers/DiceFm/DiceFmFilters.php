<?php
/**
 * Dice.fm Handler Registration
 * 
 * Registers the Dice.fm event import handler with Data Machine.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\DiceFm
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handler registration is centralized in EventImportFilters.php to avoid duplicates

/**
 * Register Dice.fm authentication provider with Data Machine
 * 
 * Adds the Dice.fm auth provider to Data Machine's auth system.
 * This enables the authentication modal for API key configuration.
 */
add_filter('datamachine_auth_providers', function($providers) {
    $providers['dice_fm_events'] = new DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFmAuth();
    return $providers;
});

/**
 * Register Dice.fm settings provider with Data Machine
 * 
 * Adds the Dice.fm settings provider to Data Machine's settings system.
 * This enables the configuration UI for handler parameters.
 */
add_filter('datamachine_handler_settings', function($all_settings) {
    $all_settings['dice_fm_events'] = new DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFmSettings();
    return $all_settings;
});