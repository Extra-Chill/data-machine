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

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Register Universal Web Scraper handler with Data Machine
 *
 * Schema.org compliant event extraction from any website.
 * Prioritizes structured data with intelligent HTML parsing fallbacks.
 */
add_filter('datamachine_handlers', function($handlers, $step_type = null) {
    // Only register when event_import handlers are requested
    if ($step_type === null || $step_type === 'event_import') {
        $handlers['universal_web_scraper'] = [
            'type' => 'event_import',
            'class' => 'DataMachineEvents\\Steps\\EventImport\\Handlers\\WebScraper\\UniversalWebScraper',
            'label' => __('Universal Web Scraper', 'datamachine-events'),
            'description' => __('Extract events from any website using Schema.org compliance with AI fallbacks', 'datamachine-events')
        ];
    }

    return $handlers;
}, 10, 2);

/**
 * Register Universal Web Scraper settings
 * 
 * Simple settings with just URL field - no dropdown complexity.
 */
add_filter('datamachine_handler_settings', function($all_settings) {
    $all_settings['universal_web_scraper'] = new UniversalWebScraperSettings();
    return $all_settings;
});

