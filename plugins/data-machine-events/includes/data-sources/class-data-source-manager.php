<?php
/**
 * DataSourceManager for Chill Events
 *
 * Auto-discovers all data sources (core + child theme) for Import Module UI.
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

class DataSourceManager {
    /**
     * Discover all available data sources (core + child theme)
     *
     * @return array Array of data source info arrays (from get_info())
     */
    public static function get_available_data_sources() {
        $sources = [];
        $classes = self::discover_data_source_classes();
        foreach ($classes as $class) {
            if (class_exists($class)) {
                $instance = new $class();
                if (method_exists($instance, 'get_info')) {
                    $info = $instance->get_info();
                    $info['class'] = $class;
                    $sources[] = $info;
                }
            }
        }
        
        // Add custom scrapers from child theme
        if (class_exists('ChillEvents\\ScraperDiscovery')) {
            $scraper_sources = ScraperDiscovery::get_custom_scraper_data_sources();
            $sources = array_merge($sources, $scraper_sources);
        }
        
        return $sources;
    }

    /**
     * Discover all class names that extend BaseDataSource in plugin and child theme
     *
     * @return array Array of fully qualified class names
     */
    protected static function discover_data_source_classes() {
        $classes = [];
        // 1. Core plugin data sources
        $core_dir = CHILL_EVENTS_PLUGIN_DIR . 'includes/data-sources/';
        $classes = array_merge($classes, self::find_data_source_classes_in_dir($core_dir, 'ChillEvents'));
        // 2. Child theme data sources (if any)
        $theme_dir = get_stylesheet_directory() . '/chill-events-data-sources/';
        if (is_dir($theme_dir)) {
            $classes = array_merge($classes, self::find_data_source_classes_in_dir($theme_dir));
        }
        return $classes;
    }

    /**
     * Find all classes in a directory that extend BaseDataSource
     *
     * @param string $dir Directory to scan
     * @param string|null $namespace Optional namespace prefix
     * @return array Array of class names
     */
    protected static function find_data_source_classes_in_dir($dir, $namespace = null) {
        $classes = [];
        if (!is_dir($dir)) return $classes;
        foreach (glob($dir . '*.php') as $file) {
            require_once $file;
            $class_name = self::get_class_name_from_file($file, $namespace);
            if ($class_name && class_exists($class_name) && is_subclass_of($class_name, '\ChillEvents\BaseDataSource')) {
                $classes[] = $class_name;
            }
        }
        return $classes;
    }

    /**
     * Extract the class name from a PHP file (assumes PSR-4 or 1-class-per-file)
     *
     * @param string $file
     * @param string|null $namespace
     * @return string|null Fully qualified class name or null
     */
    protected static function get_class_name_from_file($file, $namespace = null) {
        $contents = file_get_contents($file);
        if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
            $class = $matches[1];
            if ($namespace) {
                return '\\' . $namespace . '\\' . $class;
            }
            return $class;
        }
        return null;
    }

    /**
     * Get an instance of a data source by class name
     *
     * @param string $class_name Fully qualified class name
     * @return object|null Instance of the data source or null if not found
     */
    public static function get_data_source_instance($class_name) {
        if (class_exists($class_name)) {
            return new $class_name();
        }
        return null;
    }
} 