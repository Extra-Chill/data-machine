<?php
/**
 * SpotHopper Handler Registration
 *
 * Registers the SpotHopper event import handler with Data Machine.
 * SpotHopper uses a public API - no authentication required.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\SpotHopper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\SpotHopper;

use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

class SpotHopperFilters {
    use HandlerRegistrationTrait;

    /**
     * Register SpotHopper handler with all required filters
     */
    public static function register(): void {
        self::registerHandler(
            'spothopper',
            'event_import',
            SpotHopper::class,
            __('SpotHopper Events', 'datamachine-events'),
            __('Import events from SpotHopper venue platform (no API key required)', 'datamachine-events'),
            true,
            null,
            SpotHopperSettings::class,
            null
        );
    }
}

function datamachine_events_register_spothopper_filters() {
    SpotHopperFilters::register();
}

datamachine_events_register_spothopper_filters();
