<?php
/**
 * Universal Web Scraper - Handler Registration
 *
 * Registers the Universal Web Scraper handler and settings with Data Machine.
 * Provides Schema.org compliant event extraction from any website.
 *
 * @package DmEvents\Steps\EventImport\Handlers\WebScraper
 * @since 1.0.0
 */

namespace DmEvents\Steps\EventImport\Handlers\WebScraper;

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
add_filter('dm_handlers', function($handlers) {
    $handlers['universal_web_scraper'] = [
        'type' => 'event_import',
        'class' => 'DmEvents\\Steps\\EventImport\\Handlers\\WebScraper\\UniversalWebScraper',
        'label' => __('Universal Web Scraper', 'dm-events'),
        'description' => __('Extract events from any website using Schema.org compliance with AI fallbacks', 'dm-events')
    ];
    
    return $handlers;
});

/**
 * Register Universal Web Scraper settings
 * 
 * Simple settings with just URL field - no dropdown complexity.
 */
add_filter('dm_handler_settings', function($all_settings) {
    $all_settings['universal_web_scraper'] = new UniversalWebScraperSettings();
    return $all_settings;
});

