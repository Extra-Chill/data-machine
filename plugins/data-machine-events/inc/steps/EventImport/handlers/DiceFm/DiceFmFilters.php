<?php
/**
 * Dice.fm Handler Registration
 * 
 * Registers the Dice.fm event import handler with Data Machine.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\DiceFm
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\DiceFm;

use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dice.fm handler registration and configuration.
 */
class DiceFmFilters {
    use HandlerRegistrationTrait;

    /**
     * Register Dice.fm handler with all required filters.
     */
    public static function register(): void {
        self::registerHandler(
            'dice_fm_events',
            'event_import',
            DiceFm::class,
            __('Dice FM Events', 'datamachine-events'),
            __('Import events from Dice FM API for electronic music venues', 'datamachine-events'),
            true,
            DiceFmAuth::class,
            DiceFmSettings::class,
            null
        );
    }
}

/**
 * Register Dice.fm handler filters.
 */
function datamachine_events_register_dice_fm_filters() {
    DiceFmFilters::register();
}

datamachine_events_register_dice_fm_filters();
