<?php
/**
 * ScraperDiscovery for Chill Events
 *
 * Discovers all custom PHP scrapers in the child theme's scrapers directory.
 *
 * @package ChillEvents
 * @author Chris Huber
 * @link https://chubes.net
 * @since 1.0.0
 */

namespace ChillEvents;

if (!defined('ABSPATH')) {
    exit;
}

class ScraperDiscovery {
    /**
     * Discover all custom scraper data sources in the child theme
     *
     * @return array Array of data source info arrays (from get_info())
     */
    public static function get_custom_scraper_data_sources() {
        $sources = [];
        $scraper_dir = get_stylesheet_directory() . '/chill-events/data-sources/scrapers/';
        if (!is_dir($scraper_dir)) {
            return $sources;
        }
        foreach (glob($scraper_dir . '*.php') as $file) {
            require_once $file;
        }
        // Find all declared classes that extend BaseDataSource
        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, '\ChillEvents\\BaseDataSource')) {
                $instance = new $class();
                if (method_exists($instance, 'get_info')) {
                    $info = $instance->get_info();
                    $info['class'] = $class;
                    $sources[] = $info;
                }
            }
        }
        return $sources;
    }
} 