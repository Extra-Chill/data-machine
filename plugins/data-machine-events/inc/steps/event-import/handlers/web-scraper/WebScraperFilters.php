<?php
/**
 * Web Scraper Handler Registration
 * 
 * Registers the web scraper event import handler with Data Machine.
 * This handler manages all venue-specific scrapers through filter discovery.
 *
 * @package DmEvents\Steps\EventImport\Handlers\WebScraper
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register web scraper event import handler with Data Machine
 * 
 * Adds the web_scraper handler to Data Machine's handler registry.
 * This handler will be available in the Event Import step configuration.
 */
add_filter('dm_handlers', function($handlers) {
    $handlers['web_scraper'] = [
        'type' => 'event_import',
        'class' => 'DmEvents\\Steps\\EventImport\\Handlers\\WebScraper\\WebScraper',
        'label' => __('Web Scraper', 'dm-events'),
        'description' => __('Import events from venue websites using web scrapers', 'dm-events')
    ];
    
    return $handlers;
});

/**
 * Register web scraper settings provider with Data Machine
 * 
 * Adds the web scraper settings provider to Data Machine's settings system.
 * This enables the configuration UI for scraper source selection.
 */
add_filter('dm_handler_settings', function($all_settings) {
    $all_settings['web_scraper'] = new DmEvents\Steps\EventImport\Handlers\WebScraper\WebScraperSettings();
    return $all_settings;
});