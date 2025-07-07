<?php
/**
 * DICE.FM API Data Source for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;

use ChillEvents\Events\StandardizedEvent;

if (!defined('ABSPATH')) {
    exit;
}

class DiceFmApi extends BaseDataSource {
    /**
     * Get info about this data source
     *
     * @return array
     */
    public function get_info() {
        return array(
            'name' => 'DICE.FM API',
            'slug' => 'dice_fm',
            'type' => 'api',
            'description' => 'Independent venue and event coverage from DICE.FM',
            'coverage' => 'Global - Independent venues and events',
            'official' => true,
            'requires_config' => true,
            'requires_api_key' => true,
            'api_key_description' => 'Get your API key from DICE.FM Partner Portal',
            'settings_fields' => array(
                'city' => array(
                    'type' => 'text',
                    'label' => 'City Name',
                    'description' => 'City name for event search (e.g., "Austin", "London").',
                    'required' => true,
                ),
                'partner_id' => array(
                    'type' => 'text',
                    'label' => 'Partner ID (Optional)',
                    'description' => 'Your DICE.FM partner ID if you have one.',
                    'required' => false,
                ),
                'event_types' => array(
                    'type' => 'select',
                    'label' => 'Event Types',
                    'options' => array(
                        'linkout,event' => 'All Events',
                        'event' => 'Events Only',
                        'linkout' => 'Linkout Events Only'
                    ),
                    'default' => 'linkout,event',
                    'description' => 'Types of events to import from DICE.FM.'
                ),
                'page_size' => array(
                    'type' => 'number',
                    'label' => 'Events Per Page',
                    'default' => 100,
                    'min' => 10,
                    'max' => 200,
                    'description' => 'Number of events to fetch per API request.'
                ),
                'date_range' => array(
                    'type' => 'select',
                    'label' => 'Date Range',
                    'options' => array(
                        '30' => 'Next 30 days',
                        '60' => 'Next 60 days',
                        '90' => 'Next 90 days',
                        '180' => 'Next 6 months',
                        '365' => 'Next year'
                    ),
                    'default' => '90',
                    'description' => 'How far ahead to search for events.'
                )
            )
        );
    }

    /**
     * Get events from this data source
     *
     * @param array $settings Data source settings
     * @return array Array of standardized event data
     */
    public function get_events($settings = array()) {
        $api_config = \ChillEvents\ApiConfig::get('dice_fm');
        $api_key = isset($api_config['api_key']) ? $api_config['api_key'] : null;
        
        if (!$api_key) {
            $this->log_error('DICE.FM API key not configured. Please check API Settings.');
            return array();
        }
        
        $city = isset($settings['city']) ? trim($settings['city']) : '';
        if (empty($city)) {
            $this->log_error('No city specified for DICE.FM search.', $settings);
            return array();
        }
        
        $date_range = isset($settings['date_range']) ? intval($settings['date_range']) : 90;
        $page_size = isset($settings['page_size']) ? intval($settings['page_size']) : 100;
        $event_types = isset($settings['event_types']) ? $settings['event_types'] : 'linkout,event';
        $partner_id = isset($settings['partner_id']) ? trim($settings['partner_id']) : '';
        
        $events = $this->fetch_dice_fm_events($api_key, $city, $page_size, $event_types, $partner_id);
        
        $standardized_events = array();
        foreach ($events as $event) {
            $standardized = $this->convert_dice_fm_event($event);
            
            // Filter by date range
            $event_time = strtotime($standardized['start_date']);
            $now = time();
            $future = strtotime("+{$date_range} days");
            
            if ($event_time < $now || $event_time > $future) {
                continue;
            }
            
            $standardized_events[] = new StandardizedEvent($standardized);
        }
        
        return $standardized_events;
    }
    
    /**
     * Fetch events from DICE.FM API
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
            $this->log_error('DICE.FM API request failed: ' . $response->get_error_message());
            return array();
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $this->log_error("DICE.FM API returned status {$response_code}: {$body}");
            return array();
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error('Invalid JSON response from DICE.FM API');
            return array();
        }
        
        if (!isset($data['data']) || !is_array($data['data'])) {
            $this->log_error('No events data in DICE.FM response');
            return array();
        }
        
        return $data['data'];
    }
    
    /**
     * Convert DICE.FM event format to standardized format
     *
     * @param array $event Raw DICE.FM event data
     * @return array Standardized event data
     */
    private function convert_dice_fm_event($event) {
        // Extract venue data
        $venue_data = $this->extract_venue_data($event);
        
        // Parse dates
        $start_date = $this->parse_dice_fm_date($event['date'] ?? '');
        $end_date = $this->parse_dice_fm_date($event['date_end'] ?? '');
        
        // Build standardized event
        $standardized = array(
            'id' => $event['id'] ?? '',
            'title' => $event['name'] ?? '',
            'start_date' => $start_date,
            'end_date' => $end_date,
            'description' => $event['description'] ?? '',
            'ticket_url' => $event['url'] ?? '',
            'venue_name' => $venue_data['venue_name'],
            'venue_address' => $venue_data['venue_address'],
            'venue_city' => $venue_data['venue_city'],
            'venue_state' => $venue_data['venue_state'],
            'venue_zip' => $venue_data['venue_zip'],
            'venue_country' => $venue_data['venue_country'],
        );
        
        return $this->sanitize_event_data($standardized);
    }
    
    /**
     * Extract venue data from DICE.FM event
     *
     * @param array $event Raw event data
     * @return array Venue data
     */
    private function extract_venue_data($event) {
        $venue_data = array(
            'venue_name' => 'N/A',
            'venue_address' => 'N/A',
            'venue_city' => 'N/A',
            'venue_state' => 'N/A',
            'venue_zip' => 'N/A',
            'venue_country' => 'N/A',
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
                    $venue_data['venue_city'] = $venue['city']['name'];
                } else {
                    $venue_data['venue_city'] = $venue['city'];
                }
            } elseif (isset($event['location']['city'])) {
                $venue_data['venue_city'] = $event['location']['city'];
            }
            
            // State, zip, country from location object
            if (isset($event['location']['state'])) {
                $venue_data['venue_state'] = $event['location']['state'];
            }
            if (isset($event['location']['zip'])) {
                $venue_data['venue_zip'] = $event['location']['zip'];
            }
            if (isset($event['location']['country'])) {
                $venue_data['venue_country'] = $event['location']['country'];
            }
        }
        
        return $venue_data;
    }
    
    /**
     * Parse DICE.FM date format to standardized format
     *
     * @param string $date_string DICE.FM date string
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
            $this->log_error('Failed to parse DICE.FM date: ' . $date_string);
            return $date_string; // Return original if parsing fails
        }
    }
} 