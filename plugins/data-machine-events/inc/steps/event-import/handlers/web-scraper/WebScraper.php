<?php
/**
 * Web Scraper Event Import Handler
 * 
 * Unified handler for all web scrapers using filter-based discovery.
 * Allows selection of different venue scrapers through handler settings.
 *
 * @package ChillEvents\Steps\EventImport\Handlers\WebScraper
 * @since 1.0.0
 */

namespace ChillEvents\Steps\EventImport\Handlers\WebScraper;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WebScraper class
 * 
 * Main handler class that discovers and executes venue-specific scrapers.
 */
class WebScraper {
    
    /**
     * Get event data from selected web scraper
     * 
     * @param int $pipeline_id Pipeline ID
     * @param array $handler_config Handler configuration
     * @param string|null $job_id Job ID for tracking
     * @return array Standardized data packet with processed_items
     */
    public function get_event_data(int $pipeline_id, array $handler_config, ?string $job_id = null): array {
        $this->log_info('Web Scraper Handler: Starting event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'config' => $handler_config
        ]);
        
        // Get selected scraper from configuration
        $selected_scraper = isset($handler_config['scraper_source']) ? $handler_config['scraper_source'] : '';
        if (empty($selected_scraper)) {
            $this->log_error('No scraper source selected in configuration');
            return ['processed_items' => []];
        }
        
        // Discover all available scrapers
        $available_scrapers = apply_filters('dm_web_scrapers', []);
        
        if (!isset($available_scrapers[$selected_scraper])) {
            $this->log_error('Selected scraper not found: ' . $selected_scraper, [
                'available' => array_keys($available_scrapers)
            ]);
            return ['processed_items' => []];
        }
        
        // Get scraper class
        $scraper_class = $available_scrapers[$selected_scraper];
        
        if (!class_exists($scraper_class)) {
            $this->log_error('Scraper class does not exist: ' . $scraper_class);
            return ['processed_items' => []];
        }
        
        try {
            // Instantiate and run scraper
            $scraper = new $scraper_class();
            
            // Validate scraper implements required interface
            if (!method_exists($scraper, 'get_events')) {
                $this->log_error('Scraper class missing get_events method: ' . $scraper_class);
                return ['processed_items' => []];
            }
            
            // Get events from scraper
            $events = $scraper->get_events($handler_config);
            
            if (!is_array($events)) {
                $this->log_error('Scraper returned invalid data type: ' . gettype($events));
                return ['processed_items' => []];
            }
            
            $this->log_info('Web Scraper Handler: Events retrieved', [
                'scraper' => $selected_scraper,
                'count' => count($events)
            ]);
            
            // Format for Data Machine
            $processed_items = [];
            foreach ($events as $event) {
                if (is_array($event)) {
                    $processed_items[] = [
                        'data' => $event,
                        'metadata' => [
                            'source' => $selected_scraper,
                            'imported_at' => current_time('mysql'),
                            'scraper_class' => $scraper_class
                        ]
                    ];
                }
            }
            
            return ['processed_items' => $processed_items];
            
        } catch (\Exception $e) {
            $this->log_error('Scraper execution failed: ' . $e->getMessage(), [
                'scraper' => $selected_scraper,
                'class' => $scraper_class,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return ['processed_items' => []];
        }
    }
    
    /**
     * Get all discovered web scrapers
     * 
     * @return array Available scrapers with their metadata
     */
    public function get_available_scrapers(): array {
        $scrapers = apply_filters('dm_web_scrapers', []);
        $scraper_info = [];
        
        foreach ($scrapers as $key => $class) {
            if (class_exists($class)) {
                try {
                    $instance = new $class();
                    if (method_exists($instance, 'get_info')) {
                        $info = $instance->get_info();
                        $scraper_info[$key] = [
                            'name' => $info['name'] ?? $key,
                            'description' => $info['description'] ?? '',
                            'class' => $class
                        ];
                    } else {
                        $scraper_info[$key] = [
                            'name' => $key,
                            'description' => 'Legacy scraper',
                            'class' => $class
                        ];
                    }
                } catch (\Exception $e) {
                    $this->log_error('Failed to get scraper info: ' . $key, [
                        'class' => $class,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        return $scraper_info;
    }
    
    /**
     * Log informational message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    private function log_info(string $message, array $context = []): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $context_str = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
            error_log($message . $context_str);
        }
    }
    
    /**
     * Log error message
     * 
     * @param string $message Error message
     * @param array $context Additional context data
     */
    private function log_error(string $message, array $context = []): void {
        $context_str = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
        error_log('Chill Events Web Scraper Error: ' . $message . $context_str);
    }
}