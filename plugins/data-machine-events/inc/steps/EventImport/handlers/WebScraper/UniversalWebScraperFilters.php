<?php
/**
 * Universal Web Scraper - Handler Registration
 *
 * Registers the Universal Web Scraper handler and settings with Data Machine.
 * Provides Schema.org compliant event extraction from any website.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachine\Core\Steps\HandlerRegistrationTrait;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Universal Web Scraper handler registration and configuration.
 */
class UniversalWebScraperFilters {
    use HandlerRegistrationTrait;

    /**
     * Register Universal Web Scraper handler with all required filters.
     */
    public static function register(): void {
        self::registerHandler(
            'universal_web_scraper',
            'event_import',
            UniversalWebScraper::class,
            __('Universal Web Scraper', 'datamachine-events'),
            __('Extract events from any website using Schema.org compliance with AI fallbacks', 'datamachine-events'),
            false,
            null,
            UniversalWebScraperSettings::class,
            null
        );
    }
}

/**
 * Register Universal Web Scraper handler filters.
 */
function datamachine_events_register_web_scraper_filters() {
    UniversalWebScraperFilters::register();
}

datamachine_events_register_web_scraper_filters();
