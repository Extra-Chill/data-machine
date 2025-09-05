<?php
/**
 * Dice.fm Handler Registration
 * 
 * Registers the Dice.fm event import handler with Data Machine.
 *
 * @package DmEvents\Steps\EventImport\Handlers\DiceFm
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Dice.fm event import handler with Data Machine
 * 
 * Adds the dice_fm_events handler to Data Machine's handler registry.
 * This handler will be available in the Event Import step configuration.
 */
add_filter('dm_handlers', function($handlers) {
    $handlers['dice_fm_events'] = [
        'type' => 'event_import',
        'class' => 'DmEvents\\Steps\\EventImport\\Handlers\\DiceFm\\DiceFm',
        'label' => __('Dice.fm Events', 'dm-events'),
        'description' => __('Import events from Dice.fm API', 'dm-events')
    ];
    
    return $handlers;
});

/**
 * Register Dice.fm authentication provider with Data Machine
 * 
 * Adds the Dice.fm auth provider to Data Machine's auth system.
 * This enables the authentication modal for API key configuration.
 */
add_filter('dm_auth_providers', function($providers) {
    $providers['dice_fm_events'] = new DmEvents\Steps\EventImport\Handlers\DiceFm\DiceFmAuth();
    return $providers;
});

/**
 * Register Dice.fm settings provider with Data Machine
 * 
 * Adds the Dice.fm settings provider to Data Machine's settings system.
 * This enables the configuration UI for handler parameters.
 */
add_filter('dm_handler_settings', function($all_settings) {
    $all_settings['dice_fm_events'] = new DmEvents\Steps\EventImport\Handlers\DiceFm\DiceFmSettings();
    return $all_settings;
});