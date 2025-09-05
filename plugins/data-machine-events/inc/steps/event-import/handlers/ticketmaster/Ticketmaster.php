<?php
/**
 * Ticketmaster Event Import Handler
 * 
 * Imports events from Ticketmaster Discovery API.
 * Handles API authentication and response mapping.
 *
 * @package DmEvents\Steps\EventImport\Handlers\Ticketmaster
 * @since 1.0.0
 */

namespace DmEvents\Steps\EventImport\Handlers\Ticketmaster;


// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ticketmaster class
 * 
 * Event import handler for Ticketmaster Discovery API.
 */
class Ticketmaster {
    
    /**
     * Ticketmaster API base URL
     */
    const API_BASE = 'https://app.ticketmaster.com/discovery/v2/';
    
    /**
     * Default search parameters
     */
    const DEFAULT_PARAMS = [
        'size' => 50,
        'sort' => 'date,asc',
        'classificationName' => 'Music'
    ];
    
    /**
     * Get event data from Ticketmaster API
     * 
     * @param int $pipeline_id Pipeline ID
     * @param array $handler_config Handler configuration
     * @param string|null $job_id Job ID for tracking
     * @return array Standardized data packet with processed_items
     */
    public function get_fetch_data(int $pipeline_id, array $handler_config, ?string $job_id = null): array {
        $this->log_info('Ticketmaster Handler: Starting event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id
        ]);
        
        // Extract flow_step_id from handler config for processed items tracking
        $flow_step_id = $handler_config['flow_step_id'] ?? null;
        
        // Get API configuration from Data Machine auth system
        $api_config = apply_filters('dm_retrieve_oauth_keys', [], 'ticketmaster_events');
        if (empty($api_config['api_key'])) {
            $this->log_error('Ticketmaster API key not configured');
            return ['processed_items' => []];
        }
        
        // Build search parameters
        $search_params = $this->build_search_params($handler_config, $api_config['api_key']);
        
        // Fetch events from API
        $raw_events = $this->fetch_events($search_params);
        if (empty($raw_events)) {
            $this->log_info('No events found from Ticketmaster API');
            return ['processed_items' => []];
        }
        
        // Process events one at a time (Data Machine single-item model)
        $this->log_info('Ticketmaster Handler: Processing events for eligible item', [
            'raw_events_available' => count($raw_events),
            'pipeline_id' => $pipeline_id
        ]);
        
        foreach ($raw_events as $raw_event) {
            // Standardize the event
            $standardized_event = $this->map_ticketmaster_event($raw_event);
            
            // Skip if no title
            if (empty($standardized_event['title'])) {
                continue;
            }
            
            // Create unique identifier for processed items tracking
            $event_identifier = md5($standardized_event['title'] . ($standardized_event['startDate'] ?? '') . ($standardized_event['venue'] ?? ''));
            
            // Check if already processed FIRST
            $is_processed = apply_filters('dm_is_item_processed', false, $flow_step_id, 'ticketmaster', $event_identifier);
            if ($is_processed) {
                $this->log_debug('Skipping already processed event', [
                    'title' => $standardized_event['title'],
                    'event_identifier' => $event_identifier
                ]);
                continue;
            }
            
            // Apply individual event filters
            if (!$this->is_future_event($standardized_event)) {
                $this->log_debug('Skipping past event', [
                    'title' => $standardized_event['title'],
                    'date' => $standardized_event['startDate']
                ]);
                continue;
            }
            
            // Found eligible event - mark as processed and return immediately
            if ($flow_step_id && $job_id) {
                do_action('dm_mark_item_processed', $flow_step_id, 'ticketmaster', $event_identifier, $job_id);
            }
            
            $this->log_info('Ticketmaster Handler: Found eligible event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate'],
                'venue' => $standardized_event['venue'],
                'pipeline_id' => $pipeline_id
            ]);
            
            // Return first eligible event immediately (Data Machine pattern)
            return [
                'processed_items' => [[
                    'data' => $standardized_event,
                    'metadata' => [
                        'source_type' => 'ticketmaster',
                        'original_title' => $standardized_event['title'] ?? '',
                        'event_identifier' => $event_identifier,
                        'import_timestamp' => time()
                    ]
                ]],
                'metadata' => [
                    'source_type' => 'ticketmaster',
                    'import_timestamp' => time()
                ]
            ];
        }
        
        // No eligible events found
        $this->log_info('Ticketmaster Handler: No eligible events found', [
            'raw_events_checked' => count($raw_events),
            'pipeline_id' => $pipeline_id
        ]);
        
        return ['processed_items' => []];
    }
    
    /**
     * Build search parameters for Ticketmaster API
     * 
     * @param array $handler_config Handler configuration
     * @param string $api_key API key
     * @return array API parameters
     */
    private function build_search_params(array $handler_config, string $api_key): array {
        $params = array_merge(self::DEFAULT_PARAMS, [
            'apikey' => $api_key
        ]);
        
        // Parse location field (e.g., "Charleston, SC")
        if (!empty($handler_config['location'])) {
            $location_parts = explode(',', $handler_config['location']);
            
            if (count($location_parts) >= 1) {
                $city = trim($location_parts[0]);
                if (!empty($city)) {
                    $params['city'] = $city;
                }
            }
            
            if (count($location_parts) >= 2) {
                $state = trim($location_parts[1]);
                if (!empty($state)) {
                    $params['stateCode'] = strtoupper($state);
                }
            }
        }
        
        // Default to US for country code
        $params['countryCode'] = 'US';
        
        // Add date range
        $start_date = !empty($handler_config['start_date']) 
            ? $handler_config['start_date'] 
            : date('Y-m-d\TH:i:s\Z');
        $params['startDateTime'] = $start_date;
        
        // Add genre/classification filters
        if (!empty($handler_config['genre'])) {
            $params['genreId'] = $handler_config['genre'];
        }
        
        // Add venue filter
        if (!empty($handler_config['venue_id'])) {
            $params['venueId'] = $handler_config['venue_id'];
        }
        
        return $params;
    }
    
    /**
     * Fetch events from Ticketmaster API
     * 
     * @param array $params API parameters
     * @return array Raw event data from API
     */
    private function fetch_events(array $params): array {
        $url = self::API_BASE . 'events.json?' . http_build_query($params);
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Data Machine Events WordPress Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->log_error('Ticketmaster API request failed: ' . $response->get_error_message(), [
                'url' => $url
            ]);
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error('Ticketmaster API response parsing failed', [
                'json_error' => json_last_error_msg()
            ]);
            return [];
        }
        
        if (empty($data['_embedded']['events'])) {
            $this->log_debug('No events in Ticketmaster API response');
            return [];
        }
        
        return $data['_embedded']['events'];
    }
    
    /**
     * Map Ticketmaster event to Event Details schema
     * 
     * @param array $tm_event Ticketmaster event data
     * @return array Standardized event data
     */
    private function map_ticketmaster_event(array $tm_event): array {
        // Extract basic info
        $title = $tm_event['name'] ?? '';
        $description = $tm_event['info'] ?? $tm_event['pleaseNote'] ?? '';
        
        // Extract dates
        $start_date = '';
        $start_time = '';
        if (!empty($tm_event['dates']['start']['localDate'])) {
            $start_date = $tm_event['dates']['start']['localDate'];
            $start_time = $tm_event['dates']['start']['localTime'] ?? '';
        }
        
        // Extract venue info
        $venue_name = '';
        $address = '';
        if (!empty($tm_event['_embedded']['venues'][0])) {
            $venue = $tm_event['_embedded']['venues'][0];
            $venue_name = $venue['name'] ?? '';
            
            if (!empty($venue['address'])) {
                $address_parts = [];
                if (!empty($venue['address']['line1'])) {
                    $address_parts[] = $venue['address']['line1'];
                }
                if (!empty($venue['address']['line2'])) {
                    $address_parts[] = $venue['address']['line2'];
                }
                if (!empty($venue['city']['name'])) {
                    $address_parts[] = $venue['city']['name'];
                }
                if (!empty($venue['state']['stateCode'])) {
                    $address_parts[] = $venue['state']['stateCode'];
                }
                $address = implode(', ', $address_parts);
            }
        }
        
        // Extract artist/performer info
        $artist = '';
        if (!empty($tm_event['_embedded']['attractions'][0]['name'])) {
            $artist = $tm_event['_embedded']['attractions'][0]['name'];
        }
        
        // Extract pricing info
        $price = '';
        if (!empty($tm_event['priceRanges'][0])) {
            $price_range = $tm_event['priceRanges'][0];
            $min = $price_range['min'] ?? 0;
            $max = $price_range['max'] ?? 0;
            $currency = $price_range['currency'] ?? 'USD';
            
            if ($min == $max) {
                $price = '$' . number_format($min, 2);
            } else {
                $price = '$' . number_format($min, 2) . ' - $' . number_format($max, 2);
            }
        }
        
        // Extract ticket URL
        $ticket_url = $tm_event['url'] ?? '';
        
        return [
            'title' => $this->sanitize_text($title),
            'startDate' => $start_date,
            'endDate' => $start_date, // Ticketmaster usually doesn't provide separate end dates
            'startTime' => $start_time,
            'endTime' => '',
            'venue' => $this->sanitize_text($venue_name),
            'address' => $this->sanitize_text($address),
            'artist' => $this->sanitize_text($artist),
            'price' => $this->sanitize_text($price),
            'ticketUrl' => $this->sanitize_url($ticket_url),
            'description' => $this->clean_html($description),
        ];
    }
    
    /**
     * Check if event is in the future
     * 
     * @param array $event Event data
     * @return bool True if event is in future
     */
    private function is_future_event(array $event): bool {
        try {
            if (empty($event['startDate'])) {
                return false;
            }
            
            $event_date = new \DateTime($event['startDate'] . ' ' . ($event['startTime'] ?? '23:59'));
            $now = new \DateTime();
            
            return $event_date > $now;
        } catch (\Exception $e) {
            $this->log_error('Date validation error: ' . $e->getMessage(), [
                'event' => $event
            ]);
            // Include event if date parsing fails
            return true;
        }
    }
    
    
    
    /**
     * Sanitize text field
     * 
     * @param string $text Text to sanitize
     * @return string Sanitized text
     */
    private function sanitize_text(string $text): string {
        return sanitize_text_field(trim($text));
    }
    
    /**
     * Sanitize URL
     * 
     * @param string $url URL to sanitize
     * @return string Sanitized URL
     */
    private function sanitize_url(string $url): string {
        return esc_url_raw(trim($url));
    }
    
    /**
     * Clean HTML content
     * 
     * @param string $html HTML content
     * @return string Cleaned content
     */
    private function clean_html(string $html): string {
        if (empty($html)) {
            return '';
        }
        
        return html_entity_decode(strip_tags($html, '<a><br><p>'));
    }
    
    /**
     * Log error message
     * 
     * @param string $message Error message
     * @param array $context Additional context
     */
    private function log_error(string $message, array $context = []): void {
        if (function_exists('do_action')) {
            do_action('dm_log', 'error', $message, $context);
        }
    }
    
    /**
     * Log info message
     * 
     * @param string $message Info message
     * @param array $context Additional context
     */
    private function log_info(string $message, array $context = []): void {
        if (function_exists('do_action')) {
            do_action('dm_log', 'info', $message, $context);
        }
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Debug message
     * @param array $context Additional context
     */
    private function log_debug(string $message, array $context = []): void {
        if (function_exists('do_action')) {
            do_action('dm_log', 'debug', $message, $context);
        }
    }
}