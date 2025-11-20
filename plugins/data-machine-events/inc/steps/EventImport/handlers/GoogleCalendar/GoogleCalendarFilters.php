<?php
/**
 * Google Calendar Handler Registration
 *
 * Registers the Google Calendar event import handler with Data Machine.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar;

use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Calendar handler registration and configuration.
 */
class GoogleCalendarFilters {
    use HandlerRegistrationTrait;

    /**
     * Register Google Calendar handler with all required filters.
     */
    public static function register(): void {
        self::registerHandler(
            'google_calendar',
            'event_import',
            GoogleCalendar::class,
            __('Google Calendar', 'datamachine-events'),
            __('Import events from public Google Calendar .ics feeds', 'datamachine-events'),
            true,
            GoogleCalendarAuth::class,
            GoogleCalendarSettings::class,
            null
        );
    }
}

/**
 * Register Google Calendar handler filters.
 */
function datamachine_events_register_google_calendar_filters() {
    GoogleCalendarFilters::register();
}

datamachine_events_register_google_calendar_filters();
