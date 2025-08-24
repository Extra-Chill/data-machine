<?php
/**
 * Dice.fm Event Import Handler
 * 
 * Imports events from Dice.fm API.
 * Standalone implementation for Data Machine integration.
 *
 * @package ChillEvents\Steps\EventImport\Handlers\DiceFm
 * @since 1.0.0
 */

namespace ChillEvents\Steps\EventImport\Handlers\DiceFm;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * DiceFm class
 * 
 * Event import handler for Dice.fm API.
 */
class DiceFm {
    
    /**
     * Get event data from Dice.fm API
     * 
     * @param int $pipeline_id Pipeline ID
     * @param array $handler_config Handler configuration
     * @param string|null $job_id Job ID for tracking
     * @return array Standardized data packet with processed_items
     */
    public function get_event_data(int $pipeline_id, array $handler_config, ?string $job_id = null): array {
        $this->log_info('Dice.fm Handler: Starting event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id
        ]);
        
        // Get API configuration from Data Machine auth system
        $api_config = apply_filters('dm_oauth', [], 'get_config', 'dice_fm_events');
        if (empty($api_config['api_key'])) {
            $this->log_error('Dice.fm API key not configured');
            return ['processed_items' => []];
        }
        
        // Get required city parameter
        $city = isset($handler_config['city']) ? trim($handler_config['city']) : '';
        if (empty($city)) {
            $this->log_error('No city specified for Dice.fm search', $handler_config);
            return ['processed_items' => []];
        }
        
        // Build configuration
        $date_range = isset($handler_config['date_range']) ? intval($handler_config['date_range']) : 90;
        $page_size = isset($handler_config['page_size']) ? intval($handler_config['page_size']) : 100;
        $event_types = isset($handler_config['event_types']) ? $handler_config['event_types'] : 'linkout,event';
        $partner_id = !empty($api_config['partner_id']) ? trim($api_config['partner_id']) : '';
        
        // Fetch events from API
        $raw_events = $this->fetch_dice_fm_events($api_config['api_key'], $city, $page_size, $event_types, $partner_id);
        if (empty($raw_events)) {
            $this->log_info('No events found from Dice.fm API');
            return ['processed_items' => []];
        }
        
        // Process and standardize events
        $standardized_events = [];
        foreach ($raw_events as $raw_event) {
            $standardized_event = $this->convert_dice_fm_event($raw_event);
            
            // Filter by date range
            $event_time = strtotime($standardized_event['startDate'] . ' ' . $standardized_event['startTime']);
            $now = time();
            $future = strtotime("+{$date_range} days");
            
            if ($event_time < $now || $event_time > $future) {
                continue;
            }
            
            if (!empty($standardized_event['title'])) {
                $standardized_events[] = $standardized_event;
            }
        }
        
        $this->log_info('Dice.fm Handler: Event import completed', [
            'total_fetched' => count($raw_events),
            'total_processed' => count($standardized_events),
            'pipeline_id' => $pipeline_id
        ]);
        
        return $this->create_data_packet($standardized_events, 'dice_fm');
    }
    
    /**
     * Fetch events from Dice.fm API
     *
     * @param string $api_key API key
     * @param string $city City name
     * @param int $page_size Number of events per page
     * @param string $event_types Event types to fetch
     * @param string $partner_id Partner ID (optional)
     * @return array Raw event data from API
     */
    private function fetch_dice_fm_events($api_key, $city, $page_size = 100, $event_types = 'linkout,event', $partner_id = '') {
        $base_url = 'https://partners-endpoint.dice.fm/api/v2/events';
        
        // Build query parameters
        $params = array(
            'page[size]' => $page_size,
            'types' => $event_types,
            'filter[cities][]' => $city,
        );
        
        $url = add_query_arg($params, $base_url);
        
        // Prepare headers
        $headers = array(
            'Accept' => 'application/json',
            'x-api-key' => $api_key,
        );
        
        if (!empty($partner_id)) {
            $headers['X-Partner-Id'] = trim($partner_id);
        }
        
        // Make API request
        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            $this->log_error('Dice.fm API request failed: ' . $response->get_error_message());
            return array();
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $this->log_error("Dice.fm API returned status {$response_code}: {$body}");
            return array();
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error('Invalid JSON response from Dice.fm API');
            return array();
        }
        
        if (!isset($data['data']) || !is_array($data['data'])) {
            $this->log_error('No events data in Dice.fm response');
            return array();
        }
        
        return $data['data'];
    }
    
    /**
     * Convert Dice.fm event format to Event Details schema
     *
     * @param array $event Raw Dice.fm event data
     * @return array Standardized event data
     */
    private function convert_dice_fm_event($event) {
        // Extract venue data
        $venue_data = $this->extract_venue_data($event);
        
        // Parse dates
        $start_date = $this->parse_dice_fm_date($event['date'] ?? '');
        $end_date = $this->parse_dice_fm_date($event['date_end'] ?? '');
        
        return [
            'title' => sanitize_text_field($event['name'] ?? ''),
            'startDate' => $this->extract_date($start_date),
            'endDate' => $this->extract_date($end_date),
            'startTime' => $this->extract_time($start_date),
            'endTime' => $this->extract_time($end_date),
            'venue' => sanitize_text_field($venue_data['venue_name']),
            'address' => sanitize_text_field($venue_data['venue_address']),
            'artist' => '', // Dice.fm doesn't separate artists
            'price' => '', // Price info not in API response
            'ticketUrl' => esc_url_raw($event['url'] ?? ''),
            'description' => wp_kses_post($event['description'] ?? ''),
        ];
    }
    
    /**
     * Extract venue data from Dice.fm event
     *
     * @param array $event Raw event data
     * @return array Venue data
     */
    private function extract_venue_data($event) {
        $venue_data = array(
            'venue_name' => 'N/A',
            'venue_address' => 'N/A',
        );
        
        if (!empty($event['venues']) && is_array($event['venues'])) {
            $venue = $event['venues'][0]; // Use the first venue
            
            // Venue name
            if (isset($venue['name'])) {
                $venue_data['venue_name'] = $venue['name'];
            }
            
            // Address - prefer location object, fall back to top-level address
            if (isset($event['location']['street']) && !empty($event['location']['street'])) {
                $venue_data['venue_address'] = $event['location']['street'];
            } elseif (isset($event['address']) && !empty($event['address'])) {
                $venue_data['venue_address'] = $event['address'];
            }
            
            // City - try venue city first, then location
            if (isset($venue['city'])) {
                if (is_array($venue['city']) && isset($venue['city']['name'])) {
                    $city = $venue['city']['name'];
                } else {
                    $city = $venue['city'];
                }
                $venue_data['venue_address'] .= ', ' . $city;
            } elseif (isset($event['location']['city'])) {
                $venue_data['venue_address'] .= ', ' . $event['location']['city'];
            }
        }
        
        return $venue_data;
    }
    
    /**
     * Parse Dice.fm date format to standardized format
     *
     * @param string $date_string Dice.fm date string
     * @return string Standardized date string
     */
    private function parse_dice_fm_date($date_string) {
        if (empty($date_string)) {
            return '';
        }
        
        try {
            $date = new \DateTime($date_string);
            $date->setTimezone(new \DateTimeZone('America/Chicago'));
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->log_error('Failed to parse Dice.fm date: ' . $date_string);
            return $date_string; // Return original if parsing fails
        }
    }
    
    /**
     * Extract date from datetime string
     * 
     * @param string $datetime Datetime string
     * @return string Date in Y-m-d format
     */
    private function extract_date(string $datetime): string {
        if (empty($datetime)) {
            return '';
        }
        
        try {
            $date = new \DateTime($datetime);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            $this->log_error('Date extraction failed: ' . $e->getMessage(), [
                'datetime' => $datetime
            ]);
            return '';
        }
    }
    
    /**
     * Extract time from datetime string
     * 
     * @param string $datetime Datetime string
     * @return string Time in H:i format
     */
    private function extract_time(string $datetime): string {
        if (empty($datetime)) {
            return '';
        }
        
        try {
            $date = new \DateTime($datetime);
            return $date->format('H:i');
        } catch (\Exception $e) {
            $this->log_error('Time extraction failed: ' . $e->getMessage(), [
                'datetime' => $datetime
            ]);
            return '';
        }
    }
    
    /**
     * Create standardized data packet for Data Machine
     * 
     * @param array $events Array of standardized event data
     * @param string $source_type Source identifier
     * @return array Data packet with processed_items
     */
    private function create_data_packet(array $events, string $source_type): array {
        $processed_items = [];
        
        foreach ($events as $event) {
            $processed_items[] = [
                'data' => $event,
                'metadata' => [
                    'source_type' => $source_type,
                    'original_title' => $event['title'] ?? '',
                    'import_timestamp' => time()
                ]
            ];
        }
        
        return [
            'processed_items' => $processed_items,
            'metadata' => [
                'total_events' => count($events),
                'source_type' => $source_type,
                'import_timestamp' => time()
            ]
        ];
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
}