<?php
/**
 * Universal Web Scraper - AI Tool Registration
 * 
 * Registers the extract_event_from_html AI tool for Universal Web Scraper handler.
 * Integrates with Data Machine's bidirectional tool detection system.
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
 * Direct handler registration for AI-powered universal web scraping.
 * No dropdown complexity - just URL input and AI processing.
 */
add_filter('dm_handlers', function($handlers) {
    $handlers['universal_web_scraper'] = [
        'type' => 'event_import',
        'class' => 'DmEvents\\Steps\\EventImport\\Handlers\\WebScraper\\UniversalWebScraper',
        'label' => __('Universal Web Scraper', 'dm-events'),
        'description' => __('Use AI to extract events from any website - just provide a URL', 'dm-events')
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

