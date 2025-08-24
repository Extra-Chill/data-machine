<?php
/**
 * Base Data Source Abstract Class
 *
 * Abstract class for Data Machine event import handlers providing standardized interface
 * for event data collection from external sources (APIs, web scraping, etc.).
 *
 * @package ChillEvents\Events
 * @since 1.0.0
 */

namespace ChillEvents\Events;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract base class for Data Machine event import handlers
 * 
 * Provides standardized event data format, HTTP request handling, and error logging.
 * All import handlers must implement get_info() and get_events() methods.
 * 
 * @since 1.0.0
 */
abstract class BaseDataSource {
    
    /**
     * Get scraper information
     * 
     * Must be implemented by each scraper to provide metadata about the scraper.
     * 
     * @return array Associative array with keys: name, type, description, settings_fields
     */
    abstract public function get_info();
    
    /**
     * Get events from the data source
     * 
     * Must be implemented by each scraper to fetch and return event data.
     * 
     * @param array $settings Configuration settings for the scraper
     * @return array Array of standardized event data
     */
    abstract public function get_events($settings = array());
    
    /**
     * Standardize event data format
     * 
     * Ensures all scrapers return event data in a consistent format.
     * 
     * @param array $event_data Raw event data from scraper
     * @return array Standardized event data
     */
    protected function standardize_event_data($event_data) {
        $defaults = [
            'title' => '',
            'description' => '',
            'start_date' => '',
            'end_date' => '',
            'venue_name' => '',
            'address' => '',
            'location_name' => '',
            'ticket_url' => '',
            'price' => '',
            'categories' => [],
            'tags' => []
        ];
        
        $standardized = wp_parse_args($event_data, $defaults);
        
        // Ensure dates are in proper format
        if (!empty($standardized['start_date'])) {
            $standardized['start_date'] = $this->normalize_date($standardized['start_date']);
        }
        
        if (!empty($standardized['end_date'])) {
            $standardized['end_date'] = $this->normalize_date($standardized['end_date']);
        }
        
        // Sanitize text fields
        $standardized['title'] = sanitize_text_field($standardized['title']);
        $standardized['description'] = wp_kses_post($standardized['description']);
        $standardized['venue_name'] = sanitize_text_field($standardized['venue_name']);
        $standardized['address'] = sanitize_text_field($standardized['address']);
        $standardized['location_name'] = sanitize_text_field($standardized['location_name']);
        $standardized['ticket_url'] = esc_url_raw($standardized['ticket_url']);
        
        return $standardized;
    }
    
    /**
     * Normalize date format
     * 
     * Converts various date formats to MySQL datetime format.
     * 
     * @param string $date Date string in various formats
     * @return string Normalized date in Y-m-d H:i:s format
     */
    protected function normalize_date($date) {
        try {
            $datetime = new \DateTime($date);
            return $datetime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->log_error('Invalid date format: ' . $date);
            return '';
        }
    }
    
    /**
     * Log error messages
     * 
     * Provides consistent error logging across all scrapers.
     * 
     * @param string $message Error message to log
     */
    protected function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Chill Events Scraper Error: ' . $message);
        }
    }
    
    /**
     * Make HTTP request with WordPress HTTP API
     * 
     * Standardized HTTP request method with proper timeout and error handling.
     * 
     * @param string $url URL to fetch
     * @param array $args Optional request arguments
     * @return array|WP_Error Response data or error
     */
    protected function make_request($url, $args = []) {
        $defaults = [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; ChillEvents/1.0)'
            ],
            'timeout' => 30
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        return wp_remote_get($url, $args);
    }
    
    /**
     * Parse HTML content safely
     * 
     * Creates DOMDocument with error handling for HTML parsing.
     * 
     * @param string $html_content HTML content to parse
     * @return \DOMDocument|null DOM document or null on failure
     */
    protected function parse_html($html_content) {
        if (empty($html_content)) {
            return null;
        }
        
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html_content);
        libxml_clear_errors();
        
        return $dom;
    }
}