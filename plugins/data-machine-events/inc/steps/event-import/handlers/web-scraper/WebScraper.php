<?php
/**
 * Web Scraper Event Import Handler
 * 
 * Unified handler for all web scrapers using filter-based discovery.
 * Allows selection of different venue scrapers through handler settings.
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
 * WebScraper class
 * 
 * Main handler class that discovers and executes venue-specific scrapers.
 */
class WebScraper {
    
    /**
     * Execute web scraper event import with unified parameter structure
     * 
     * Follows Data Machine's unified parameter system via dm_engine_parameters filter.
     * 
     * @param array $parameters Unified parameter structure from Data Machine
     *   - execution: ['job_id' => string, 'flow_step_id' => string]
     *   - config: ['flow_step' => array] Step configuration
     *   - data: array Cumulative data packet from previous steps
     *   - metadata: array Dynamic metadata from dm_engine_additional_parameters
     * @return array Updated data packet array with event entry added
     */
    public function execute(array $parameters): array {
        // Extract from unified parameter structure
        $job_id = $parameters['execution']['job_id'];
        $flow_step_id = $parameters['execution']['flow_step_id'];
        $data = $parameters['data'] ?? [];
        $flow_step_config = $parameters['config']['flow_step'] ?? [];
        
        // Extract handler configuration
        $handler_config = $flow_step_config['handler']['settings'] ?? [];
        $pipeline_id = $flow_step_config['pipeline_id'] ?? null;
        
        // Use legacy method for actual processing
        $result = $this->get_fetch_data($pipeline_id, array_merge($handler_config, ['flow_step_id' => $flow_step_id]), $job_id);
        
        // Convert legacy format to data packet array
        if (!empty($result['processed_items'])) {
            $processed_item = $result['processed_items'][0];
            $event_data = $processed_item['data'];
            
            // Extract venue metadata separately for nested structure
            $venue_metadata = $event_data['venue_metadata'] ?? [];
            
            // Create data packet entry
            $event_entry = [
                'type' => 'event_import',
                'handler' => 'web_scraper',
                'content' => [
                    'title' => $event_data['event']['title'] ?? '',
                    'body' => wp_json_encode($event_data, JSON_PRETTY_PRINT)
                ],
                'metadata' => array_merge($processed_item['metadata'] ?? [], [
                    'source_type' => 'web_scraper',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_step_config['flow_id'] ?? null,
                    'import_timestamp' => time()
                ]),
                'attachments' => [],
                'timestamp' => time()
            ];
            
            // Add to front of data packet array
            array_unshift($data, $event_entry);
        }
        
        return $data;
    }
    
    /**
     * Get event data from selected web scraper (legacy interface)
     * 
     * @param int $pipeline_id Pipeline ID
     * @param array $handler_config Handler configuration
     * @param string|null $job_id Job ID for tracking
     * @return array Standardized data packet with processed_items
     */
    public function get_fetch_data(int $pipeline_id, array $handler_config, ?string $job_id = null): array {
        $this->log_info('Web Scraper Handler: Starting event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'config' => $handler_config
        ]);
        
        // Extract flow_step_id from handler config for processed items tracking
        $flow_step_id = $handler_config['flow_step_id'] ?? null;
        
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
            
            $this->log_info('Web Scraper Handler: Processing events for eligible item', [
                'scraper' => $selected_scraper,
                'events_available' => count($events)
            ]);
            
            // Process events one at a time (Data Machine single-item model)
            foreach ($events as $event) {
                if (!is_array($event) || empty($event['title'])) {
                    continue;
                }
                
                // Create unique identifier for this event
                $event_identifier = md5(($event['title'] ?? '') . ($event['startDate'] ?? '') . ($event['venue'] ?? '') . $selected_scraper);
                
                // Check if already processed FIRST
                $is_processed = apply_filters('dm_is_item_processed', false, $flow_step_id, 'web_scraper', $event_identifier);
                if ($is_processed) {
                    $this->log_debug('Skipping already processed event', [
                        'title' => $event['title'],
                        'scraper' => $selected_scraper,
                        'event_identifier' => $event_identifier
                    ]);
                    continue;
                }
                
                // Found eligible event - mark as processed and return immediately
                if ($flow_step_id && $job_id) {
                    do_action('dm_mark_item_processed', $flow_step_id, 'web_scraper', $event_identifier, $job_id);
                }
                
                $this->log_info('Web Scraper Handler: Found eligible event', [
                    'title' => $event['title'],
                    'scraper' => $selected_scraper,
                    'venue' => $event['venue'] ?? 'N/A'
                ]);
                
                // Extract venue metadata for nested structure (flexible for different scrapers)
                $venue_metadata = [
                    'venueAddress' => $event['venueAddress'] ?? $event['address'] ?? '',
                    'venueCity' => $event['venueCity'] ?? $event['city'] ?? '',
                    'venueState' => $event['venueState'] ?? $event['state'] ?? '',
                    'venueZip' => $event['venueZip'] ?? $event['zip'] ?? '',
                    'venueCountry' => $event['venueCountry'] ?? $event['country'] ?? '',
                    'venuePhone' => $event['venuePhone'] ?? $event['phone'] ?? '',
                    'venueWebsite' => $event['venueWebsite'] ?? $event['website'] ?? '',
                    'venueCoordinates' => $event['venueCoordinates'] ?? $event['coordinates'] ?? ''
                ];
                
                // Remove venue metadata from event data (move to separate structure)
                $venue_fields = ['venueAddress', 'venueCity', 'venueState', 'venueZip', 'venueCountry', 
                               'venuePhone', 'venueWebsite', 'venueCoordinates', 'address', 'city', 
                               'state', 'zip', 'country', 'phone', 'website', 'coordinates'];
                foreach ($venue_fields as $field) {
                    unset($event[$field]);
                }
                
                // Return nested structure for consistent data flow
                return [
                    'processed_items' => [[
                        'data' => [
                            'event' => $event,
                            'venue_metadata' => $venue_metadata,
                            'import_source' => $selected_scraper
                        ],
                        'metadata' => [
                            'source' => $selected_scraper,
                            'imported_at' => current_time('mysql'),
                            'scraper_class' => $scraper_class,
                            'event_identifier' => $event_identifier,
                            'source_type' => 'web_scraper'
                        ]
                    ]],
                    'metadata' => [
                        'source_type' => 'web_scraper',
                        'selected_scraper' => $selected_scraper,
                        'import_timestamp' => time()
                    ]
                ];
            }
            
            // No eligible events found
            $this->log_info('Web Scraper Handler: No eligible events found', [
                'scraper' => $selected_scraper,
                'events_checked' => count($events)
            ]);
            
            return ['processed_items' => []];
            
        } catch (\Exception $e) {
            $this->log_error('Scraper execution failed: ' . $e->getMessage(), [
                'scraper' => $selected_scraper,
                'class' => $scraper_class,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return $data; // Return unchanged data packet array on error
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
        error_log('Data Machine Events Web Scraper Error: ' . $message . $context_str);
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Debug message
     * @param array $context Additional context data
     */
    private function log_debug(string $message, array $context = []): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $context_str = !empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
            error_log('Data Machine Events Web Scraper Debug: ' . $message . $context_str);
        }
    }
}