<?php
/**
 * Dice.fm Event Import Handler
 * 
 * Integrates with Dice.fm API for event imports using Data Machine's 
 * single-item processing model with deduplication tracking.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\DiceFm
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\DiceFm;

use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachine\Core\DataPacket;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dice.fm API event import handler with single-item processing
 * 
 * Implements Data Machine handler interface for importing events from
 * Dice.fm API with standardized processing and venue data extraction.
 */
class DiceFm extends EventImportHandler {

    public function __construct() {
        parent::__construct('dice_fm');
    }
    
    /**
    * Execute Dice FM event import with flat parameter structure
    * 
     * Stores venue context in engine data for downstream steps.
     * Fetches events, processes deduplication tracking, and returns data packet array.
     * 
     * @param int $pipeline_id Pipeline ID
     * @param array $config Handler configuration
     * @param string|null $flow_step_id Flow step ID
     * @param int $flow_id Flow ID
     * @param string|null $job_id Job ID
     * @return array Processed items array
     */
    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $this->log('info', 'Starting event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'flow_step_id' => $flow_step_id
        ]);
        
        // Get API configuration from Data Machine auth system
        $auth = $this->getAuthProvider('dice_fm_events');
        if (!$auth) {
            $this->log('error', 'Dice.fm authentication provider not found');
            return $this->emptyResponse();
        }

        $api_config = $auth->get_account();
        if (empty($api_config['api_key'])) {
            $this->log('error', 'Dice.fm API key not configured');
            return $this->emptyResponse();
        }
        
        // Get required city parameter
        $city = isset($config['city']) ? trim($config['city']) : '';
        if (empty($city)) {
            $this->log('error', 'No city specified for Dice.fm search', $config);
            return $this->emptyResponse();
        }
        
        // Build configuration
        $date_range = isset($config['date_range']) ? intval($config['date_range']) : 90;
        $page_size = isset($config['page_size']) ? intval($config['page_size']) : 100;
        $event_types = isset($config['event_types']) ? $config['event_types'] : 'linkout,event';
        $partner_id = !empty($api_config['partner_id']) ? trim($api_config['partner_id']) : '';
        
        // Fetch events from API
        $raw_events = $this->fetch_dice_fm_events($api_config['api_key'], $city, $page_size, $event_types, $partner_id);
        if (empty($raw_events)) {
            $this->log('info', 'No events found from Dice.fm API');
            return $this->emptyResponse();
        }
        
        // Process events one at a time (Data Machine single-item model)
        $this->log('info', 'Processing events for eligible item', [
            'raw_events_available' => count($raw_events),
            'date_range_days' => $date_range,
            'pipeline_id' => $pipeline_id
        ]);
        
        $now = time();
        $future = strtotime("+{$date_range} days");
        
        foreach ($raw_events as $raw_event) {
            // Standardize the event
            $standardized_event = $this->convert_dice_fm_event($raw_event);
            
            // Skip if no title
            if (empty($standardized_event['title'])) {
                continue;
            }
            
            // Create unique identifier for processed items tracking
            $event_identifier = \DataMachineEvents\Utilities\EventIdentifierGenerator::generate(
                $standardized_event['title'],
                $standardized_event['startDate'] ?? '',
                $standardized_event['venue'] ?? ''
            );
            
            // Check if already processed FIRST
            if ($this->isItemProcessed($event_identifier, $flow_step_id)) {
                $this->log('debug', 'Skipping already processed event', [
                    'title' => $standardized_event['title'],
                    'event_identifier' => $event_identifier
                ]);
                continue;
            }
            
            // Apply date range filter
            $event_time = strtotime($standardized_event['startDate'] . ' ' . $standardized_event['startTime']);
            if ($event_time < $now || $event_time > $future) {
                $this->log('debug', 'Skipping event outside date range', [
                    'title' => $standardized_event['title'],
                    'date' => $standardized_event['startDate'],
                    'time' => $standardized_event['startTime']
                ]);
                continue;
            }
            
            // Found eligible event - mark as processed
            $this->markItemProcessed($event_identifier, $flow_step_id, $job_id);
            
            $this->log('info', 'Found eligible event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate'],
                'venue' => $standardized_event['venue'],
                'pipeline_id' => $pipeline_id
            ]);
            
            // Extract limited venue metadata for nested structure (Dice FM has less data than Ticketmaster)
            $venue_metadata = [
                'venueAddress' => $standardized_event['address'] ?? '',
                'venueCity' => '',      // Not available in Dice FM API
                'venueState' => '',     // Not available in Dice FM API
                'venueZip' => '',       // Not available in Dice FM API
                'venueCountry' => '',   // Not available in Dice FM API
                'venuePhone' => '',     // Not available in Dice FM API
                'venueWebsite' => '',   // Not available in Dice FM API
                'venueCoordinates' => '' // Not available in Dice FM API
            ];
            
            EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);

            // Remove venue metadata from event data (move to separate structure)
            unset($standardized_event['address']);
            
            // Create DataPacket
            $dataPacket = new DataPacket(
                [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'dice_fm'
                    ], JSON_PRETTY_PRINT)
                ],
                [
                    'source_type' => 'dice_fm',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_id,
                    'original_title' => $standardized_event['title'] ?? '',
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time()
                ],
                'event_import'
            );
            
            return $this->successResponse([$dataPacket]);
        }
        
        // No eligible events found
        $this->log('info', 'No eligible events found', [
            'raw_events_checked' => count($raw_events),
            'date_range_days' => $date_range,
            'pipeline_id' => $pipeline_id
        ]);
        
        return $this->emptyResponse();
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
}