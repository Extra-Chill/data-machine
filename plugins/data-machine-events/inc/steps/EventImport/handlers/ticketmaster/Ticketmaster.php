<?php
/**
 * Ticketmaster Discovery API integration with single-item processing
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-item processing with Discovery API v2 integration
 */
class Ticketmaster {
    
    const API_BASE = 'https://app.ticketmaster.com/discovery/v2/';

    const DEFAULT_PARAMS = [
        'size' => 50,
        'sort' => 'date,asc',
        'page' => 0
    ];
    
    /**
     * Data Machine flat parameter execution for single-item processing
     * @param array $parameters Flat parameter structure from Data Machine
     * @return array Unchanged data packet or data packet with new event
     */
    public function execute(array $parameters): array {
        $job_id = $parameters['job_id'];
        $flow_step_id = $parameters['flow_step_id'];
        $data = $parameters['data'] ?? [];
        $flow_step_config = $parameters['flow_step_config'] ?? [];
        
        $handler_config = $flow_step_config['handler_config']['ticketmaster_events'] ?? [];
        $pipeline_id = $flow_step_config['pipeline_id'] ?? null;
        
        $this->log_info('Ticketmaster Handler: Starting event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'flow_step_id' => $flow_step_id
        ]);
        
        $api_config = apply_filters('datamachine_retrieve_oauth_account', [], 'ticketmaster_events');
        if (empty($api_config['api_key'])) {
            $this->log_error('Ticketmaster API key not configured');
            return $data;
        }
        
        $search_params = $this->build_search_params($handler_config, $api_config['api_key']);
        
        $raw_events = $this->fetch_events($search_params);
        if (empty($raw_events)) {
            $this->log_info('No events found from Ticketmaster API');
            return $data;
        }
        
        // Process events one at a time (Data Machine single-item model)
        $this->log_info('Ticketmaster Handler: Processing events for eligible item', [
            'raw_events_available' => count($raw_events),
            'pipeline_id' => $pipeline_id
        ]);
        
        foreach ($raw_events as $raw_event) {
            // Only process actively scheduled events (skip cancelled, postponed, rescheduled)
            $event_status = $raw_event['dates']['status']['code'] ?? '';
            if ($event_status !== 'onsale') {
                $this->log_debug('Skipping event with non-active status', [
                    'event_name' => $raw_event['name'] ?? 'Unknown',
                    'status' => $event_status
                ]);
                continue;
            }
            
            // Standardize the event
            $standardized_event = $this->map_ticketmaster_event($raw_event);
            
            // Skip if no title
            if (empty($standardized_event['title'])) {
                continue;
            }
            
            // Create unique identifier for processed items tracking
            $event_identifier = md5($standardized_event['title'] . ($standardized_event['startDate'] ?? '') . ($standardized_event['venue'] ?? ''));
            
            // Check if already processed FIRST
            $is_processed = apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'ticketmaster', $event_identifier);
            if ($is_processed) {
                $this->log_debug('Skipping already processed event', [
                    'title' => $standardized_event['title'],
                    'event_identifier' => $event_identifier
                ]);
                continue;
            }
            
            // API handles future events filtering via startDateTime parameter
            
            // Found eligible event - mark as processed and add to data packet array
            if ($flow_step_id && $job_id) {
                do_action('datamachine_mark_item_processed', $flow_step_id, 'ticketmaster', $event_identifier, $job_id);
            }
            
            $this->log_info('Ticketmaster Handler: Found eligible event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate'],
                'venue' => $standardized_event['venue'],
                'pipeline_id' => $pipeline_id
            ]);
            
            // Extract venue metadata separately for nested structure
            $venue_metadata = [
                'venueAddress' => $standardized_event['venueAddress'],
                'venueCity' => $standardized_event['venueCity'],
                'venueState' => $standardized_event['venueState'],
                'venueZip' => $standardized_event['venueZip'],
                'venueCountry' => $standardized_event['venueCountry'],
                'venuePhone' => $standardized_event['venuePhone'],
                'venueWebsite' => $standardized_event['venueWebsite'],
                'venueCoordinates' => $standardized_event['venueCoordinates']
            ];
            
            // Remove venue metadata from event data (move to separate structure)
            unset($standardized_event['venueAddress'], $standardized_event['venueCity'], 
                  $standardized_event['venueState'], $standardized_event['venueZip'],
                  $standardized_event['venueCountry'], $standardized_event['venuePhone'],
                  $standardized_event['venueWebsite'], $standardized_event['venueCoordinates']);
            
            // Create data packet entry following Data Machine standard
            $event_entry = [
                'type' => 'event_import',
                'handler' => 'ticketmaster',
                'content' => [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'ticketmaster'
                    ], JSON_PRETTY_PRINT)
                ],
                'metadata' => [
                    'source_type' => 'ticketmaster',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_step_config['flow_id'] ?? null,
                    'original_title' => $standardized_event['title'] ?? '',
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time()
                ],
                'attachments' => [],
                'timestamp' => time()
            ];
            
            // Add to front of data packet array (newest first)
            array_unshift($data, $event_entry);
            return $data;
        }
        
        // No eligible events found
        $this->log_info('Ticketmaster Handler: No eligible events found', [
            'raw_events_checked' => count($raw_events),
            'pipeline_id' => $pipeline_id
        ]);
        
        return $data; // Return unchanged data packet array
    }
    
    /**
     * Build search parameters for API request
     * 
     * @param array $handler_config Handler settings
     * @param string $api_key API key
     * @return array API parameters
     */
    private function build_search_params(array $handler_config, string $api_key): array {
        $params = array_merge(self::DEFAULT_PARAMS, [
            'apikey' => $api_key
        ]);
        
        // Event type classification is REQUIRED - fail job if not provided
        if (empty($handler_config['classification_type'])) {
            $this->log_error('Ticketmaster: classification_type is required but not provided');
            throw new \Exception('Ticketmaster handler requires classification_type setting. Job failed.');
        }
        
        // Get classifications to map slug to actual segment name
        $classifications = self::get_classifications($api_key);
        $classification_slug = $handler_config['classification_type'];
        
        if (!isset($classifications[$classification_slug])) {
            $this->log_error('Ticketmaster: Invalid classification_type provided', [
                'classification_type' => $classification_slug,
                'available_types' => array_keys($classifications)
            ]);
            throw new \Exception('Invalid Ticketmaster classification_type: ' . $classification_slug);
        }
        
        // Use segmentName parameter for proper event type filtering
        $params['segmentName'] = $classifications[$classification_slug];
        
        $this->log_info('Ticketmaster: Added segment filter', [
            'slug' => $classification_slug,
            'segment_name' => $classifications[$classification_slug]
        ]);
        
        // Set location coordinates (default to Charleston, SC if not specified)
        $location = $handler_config['location'] ?? '32.7765,-79.9311'; // Charleston, SC
        $coordinates = $this->get_coordinates($location);
        if ($coordinates) {
            $params['geoPoint'] = $coordinates['lat'] . ',' . $coordinates['lng'];
            
            // Set radius (default 50 miles if not specified)
            $radius = !empty($handler_config['radius']) ? $handler_config['radius'] : '50';
            $params['radius'] = $radius;
            $params['unit'] = 'miles';
        }
        
        // Add date range (future events only)
        $start_date = !empty($handler_config['start_date']) 
            ? $handler_config['start_date'] 
            : date('Y-m-d\TH:i:s\Z', strtotime('+1 hour')); // Start 1 hour from now to avoid edge cases
        $params['startDateTime'] = $start_date;
        
        // Add pagination support
        $page = !empty($handler_config['page']) ? intval($handler_config['page']) : 0;
        $params['page'] = $page;
        
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
     * Get event type classifications with 24-hour caching
     * 
     * @param string $api_key API key
     * @return array Classifications array
     */
    public static function get_classifications($api_key = '') {
        // Check cache first
        $cache_key = 'datamachine_events_ticketmaster_classifications';
        $cached_classifications = get_transient($cache_key);
        
        if ($cached_classifications !== false) {
            do_action('datamachine_log', 'debug', 'Ticketmaster: Using cached classifications', [
                'classification_count' => count($cached_classifications)
            ]);
            return $cached_classifications;
        }
        
        // Get API key if not provided
        if (empty($api_key)) {
            $api_config = apply_filters('datamachine_retrieve_oauth_account', [], 'ticketmaster_events');
            $api_key = $api_config['api_key'] ?? '';
        }
        
        // Fallback if no API key
        if (empty($api_key)) {
            do_action('datamachine_log', 'warning', 'Ticketmaster: No API key available for classifications');
            return self::get_fallback_classifications();
        }
        
        // Fetch classifications from API
        $api_url = 'https://app.ticketmaster.com/discovery/v2/classifications.json';
        $response = wp_remote_get($api_url . '?apikey=' . urlencode($api_key), [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
        
        // Handle API errors
        if (is_wp_error($response)) {
            do_action('datamachine_log', 'warning', 'Ticketmaster: Classifications API error', [
                'error' => $response->get_error_message()
            ]);
            return self::get_fallback_classifications();
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            do_action('datamachine_log', 'warning', 'Ticketmaster: Classifications API returned non-200', [
                'status_code' => $status_code
            ]);
            return self::get_fallback_classifications();
        }
        
        // Parse API response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['_embedded']['classifications'])) {
            do_action('datamachine_log', 'warning', 'Ticketmaster: Invalid classifications API response');
            return self::get_fallback_classifications();
        }
        
        // Extract segments from classifications
        $classifications = self::parse_classifications_response($data);
        
        // Cache for 24 hours
        set_transient($cache_key, $classifications, 24 * HOUR_IN_SECONDS);
        
        do_action('datamachine_log', 'debug', 'Ticketmaster: Classifications fetched and cached', [
            'classification_count' => count($classifications)
        ]);
        
        return $classifications;
    }
    
    /**
     * Parse classifications from API response
     * 
     * @param array $api_data API response data
     * @return array Parsed classifications
     */
    private static function parse_classifications_response($api_data) {
        $classifications = [];
        $seen_segments = [];
        
        foreach ($api_data['_embedded']['classifications'] as $classification) {
            if (isset($classification['segment'])) {
                $segment = $classification['segment'];
                $segment_name = $segment['name'] ?? '';
                $segment_id = $segment['id'] ?? '';
                
                if (!empty($segment_name) && !isset($seen_segments[$segment_name])) {
                    // Convert segment name to slug (lowercase, replace spaces/special chars)
                    $slug = sanitize_key(strtolower($segment_name));
                    $slug = str_replace('_', '-', $slug); // Use hyphens instead of underscores
                    
                    $classifications[$slug] = $segment_name;
                    $seen_segments[$segment_name] = true;
                }
            }
        }
        
        return $classifications;
    }
    
    /**
     * Get fallback classifications
     * 
     * @return array Basic classifications
     */
    private static function get_fallback_classifications() {
        return [
            'music' => __('Music', 'datamachine-events'),
            'sports' => __('Sports', 'datamachine-events'),
            'arts-theatre' => __('Arts & Theatre', 'datamachine-events'),
            'film' => __('Film', 'datamachine-events'),
            'family' => __('Family', 'datamachine-events')
        ];
    }
    
    /**
     * Get classifications for settings dropdown
     * 
     * @param array $current_config Current configuration
     * @return array Classifications array
     */
    public static function get_classifications_for_dropdown($current_config = []) {
        // Get API key from auth system
        $api_config = apply_filters('datamachine_retrieve_oauth_account', [], 'ticketmaster_events');
        $api_key = $api_config['api_key'] ?? '';
        
        return self::get_classifications($api_key);
    }
    
    /**
     * Fetch events from API
     * 
     * @param array $params API parameters
     * @return array Raw event data
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
     * Map Ticketmaster event to standardized event schema
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
        
        // Extract comprehensive venue data for DataMachineEventsPublisher integration
        // These fields are automatically injected as AI parameters via inject_venue_parameters
        $venue_name = '';
        $venue_address = '';
        $venue_city = '';
        $venue_state = '';
        $venue_zip = '';
        $venue_country = '';
        $venue_phone = '';
        $venue_website = '';
        $venue_coordinates = '';
        
        if (!empty($tm_event['_embedded']['venues'][0])) {
            $venue = $tm_event['_embedded']['venues'][0];
            $venue_name = $venue['name'] ?? '';
            
            // Extract detailed address components for venue taxonomy meta
            if (!empty($venue['address'])) {
                $address_parts = [];
                if (!empty($venue['address']['line1'])) {
                    $address_parts[] = $venue['address']['line1'];
                    $venue_address = $venue['address']['line1']; // Store street address separately
                }
                if (!empty($venue['address']['line2'])) {
                    $address_parts[] = $venue['address']['line2'];
                    if (empty($venue_address)) {
                        $venue_address = $venue['address']['line2'];
                    } else {
                        $venue_address .= ', ' . $venue['address']['line2'];
                    }
                }
                if (!empty($venue['address']['line3'])) {
                    $address_parts[] = $venue['address']['line3'];
                    if (empty($venue_address)) {
                        $venue_address = $venue['address']['line3'];
                    } else {
                        $venue_address .= ', ' . $venue['address']['line3'];
                    }
                }
            }
            
            // Extract city
            if (!empty($venue['city']['name'])) {
                $venue_city = $venue['city']['name'];
            }
            
            // Extract state
            if (!empty($venue['state']['stateCode'])) {
                $venue_state = $venue['state']['stateCode'];
            }
            
            // Extract postal code
            if (!empty($venue['postalCode'])) {
                $venue_zip = $venue['postalCode'];
            }
            
            // Extract country
            if (!empty($venue['country']['countryCode'])) {
                $venue_country = $venue['country']['countryCode'];
            }
            
            // Extract phone number from box office info
            if (!empty($venue['boxOfficeInfo']['phoneNumberDetail'])) {
                $venue_phone = $venue['boxOfficeInfo']['phoneNumberDetail'];
            }
            
            // Extract website URL
            if (!empty($venue['url'])) {
                $venue_website = $venue['url'];
            }
            
            // Extract coordinates
            if (!empty($venue['location']['latitude']) && !empty($venue['location']['longitude'])) {
                $venue_coordinates = $venue['location']['latitude'] . ',' . $venue['location']['longitude'];
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
            'endDate' => '', // Only set if provided by API
            'startTime' => $start_time,
            'endTime' => '', // Only set if provided by API
            'venue' => $this->sanitize_text($venue_name),
            'artist' => $this->sanitize_text($artist),
            'price' => $this->sanitize_text($price),
            'ticketUrl' => $this->sanitize_url($ticket_url),
            'description' => $this->clean_html($description),
            // Venue meta fields - injected as AI parameters for venue taxonomy creation
            'venueAddress' => $this->sanitize_text($venue_address),
            'venueCity' => $this->sanitize_text($venue_city),
            'venueState' => $this->sanitize_text($venue_state),
            'venueZip' => $this->sanitize_text($venue_zip),
            'venueCountry' => $this->sanitize_text($venue_country),
            'venuePhone' => $this->sanitize_text($venue_phone),
            'venueWebsite' => $this->sanitize_url($venue_website),
            'venueCoordinates' => $this->sanitize_text($venue_coordinates),
        ];
    }
    
    /**
     * Parse coordinates from location string
     * 
     * @param string $location Location string
     * @return array|false Coordinates array
     */
    private function get_coordinates(string $location) {
        // Clean and validate coordinate format
        $location = trim($location);
        $coords = explode(',', $location);
        
        if (count($coords) !== 2) {
            $this->log_error('Invalid coordinate format', [
                'location' => $location,
                'expected_format' => 'latitude,longitude'
            ]);
            return false;
        }
        
        $lat = trim($coords[0]);
        $lng = trim($coords[1]);
        
        // Validate latitude and longitude are numeric
        if (!is_numeric($lat) || !is_numeric($lng)) {
            $this->log_error('Non-numeric coordinates', [
                'latitude' => $lat,
                'longitude' => $lng
            ]);
            return false;
        }
        
        // Validate coordinate ranges
        $lat = floatval($lat);
        $lng = floatval($lng);
        
        if ($lat < -90 || $lat > 90) {
            $this->log_error('Invalid latitude range', [
                'latitude' => $lat,
                'valid_range' => '-90 to 90'
            ]);
            return false;
        }
        
        if ($lng < -180 || $lng > 180) {
            $this->log_error('Invalid longitude range', [
                'longitude' => $lng,
                'valid_range' => '-180 to 180'
            ]);
            return false;
        }
        
        return [
            'lat' => $lat,
            'lng' => $lng
        ];
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
            do_action('datamachine_log', 'error', $message, $context);
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
            do_action('datamachine_log', 'info', $message, $context);
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
            do_action('datamachine_log', 'debug', $message, $context);
        }
    }
}