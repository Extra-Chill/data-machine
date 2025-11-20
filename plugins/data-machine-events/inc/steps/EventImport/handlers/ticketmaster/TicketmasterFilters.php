<?php
/**
 * Ticketmaster Handler Registration
 * 
 * Registers the Ticketmaster event import handler with Data Machine.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster;

use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ticketmaster handler registration and configuration.
 */
class TicketmasterFilters {
    use HandlerRegistrationTrait;

    /**
     * Register Ticketmaster handler with all required filters.
     */
    public static function register(): void {
        self::registerHandler(
            'ticketmaster_events',
            'event_import',
            Ticketmaster::class,
            __('Ticketmaster Events', 'datamachine-events'),
            __('Import events from Ticketmaster Discovery API with venue data', 'datamachine-events'),
            true,
            TicketmasterAuth::class,
            TicketmasterSettings::class,
            null
        );
    }
}

/**
 * Register Ticketmaster handler filters.
 */
function datamachine_events_register_ticketmaster_filters() {
    TicketmasterFilters::register();
}

datamachine_events_register_ticketmaster_filters();
