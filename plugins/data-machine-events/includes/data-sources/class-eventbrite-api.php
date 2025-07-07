<?php
/**
 * Eventbrite API Data Source for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;

if (!defined('ABSPATH')) {
    exit;
}

class EventbriteApi extends BaseDataSource {
    /**
     * Get info about this data source
     *
     * @return array
     */
    public function get_info() {
        return array(
            'name' => 'Eventbrite API',
            'slug' => 'eventbrite',
            'type' => 'api',
            'description' => 'Community and local event integration with Eventbrite platform',
            'coverage' => 'Global - Community events, local venues, independent organizers',
            'official' => true,
            'requires_config' => true,
            'requires_api_key' => true,
            'api_key_description' => 'Get your Private Token from your Eventbrite Account Settings',
            'settings_fields' => array(
                'organizer_url' => array(
                    'type' => 'text',
                    'label' => 'Organizer URL or ID',
                    'description' => 'Paste the full Eventbrite organizer URL (e.g., "https://www.eventbrite.com/o/lofi-brewing-1234567890") or just the numeric ID. <strong>Note:</strong> Due to Eventbrite API restrictions, only organizer-based imports are supported. Location or keyword search is not available.',
                ),
                'event_status' => array(
                    'type' => 'select',
                    'label' => 'Event Status',
                    'options' => array(
                        'live' => 'Live Events Only',
                        'all' => 'All Events'
                    ),
                    'default' => 'live',
                    'description' => 'Include only live/published events or all events.'
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
        // Get API key from secure storage
        $api_config = \ChillEvents\ApiConfig::get('eventbrite');
        $api_key = isset($api_config['api_key']) ? $api_config['api_key'] : null;
        if (!$api_key) {
            $this->log_error('Eventbrite API key not configured. Please check API Settings.');
            return array();
        }
        try {
            $organizer_id = $this->extract_organizer_id(isset($settings['organizer_url']) ? $settings['organizer_url'] : '');
            if (!$organizer_id) {
                $this->log_error('Invalid or missing Eventbrite organizer URL or ID.', $settings);
                return array();
            }
            $events = $this->fetch_by_organizer($api_key, array('organizer_id' => $organizer_id, 'event_status' => $settings['event_status'], 'date_range' => $settings['date_range']));
            $standardized_events = array();
            foreach ($events as $event) {
                $standardized_events[] = $this->standardize_event_data($this->convert_eventbrite_event($event));
            }
            return $standardized_events;
        } catch (Exception $e) {
            $this->log_error('Eventbrite API error: ' . $e->getMessage(), $settings);
            return array();
        }
    }
    
    /**
     * Fetch events from Eventbrite API
     *
     * @param string $api_key API key
     * @param array $settings Module settings
     * @return array Raw event data from API
     */
    private function fetch_eventbrite_events($api_key, $settings) {
        // Only organizer-based import is supported
        return $this->fetch_by_organizer($api_key, $settings);
    }
    
    /**
     * Fetch events by organizer
     */
    private function fetch_by_organizer($api_key, $settings) {
        if (empty($settings['organizer_id'])) {
            return array();
        }
        $organizer_id = $settings['organizer_id'];
        $url = "https://www.eventbriteapi.com/v3/organizers/{$organizer_id}/events/";
        return $this->make_eventbrite_request($url, $api_key, $settings);
    }
    
    /**
     * Make API request to Eventbrite
     */
    private function make_eventbrite_request($url, $api_key, $settings, $additional_params = array()) {
        // Base parameters
        $params = array(
            'expand' => 'venue,organizer,format,category',
            'order_by' => 'start_asc',
            'status' => isset($settings['event_status']) && $settings['event_status'] === 'all' ? 'all' : 'live'
        );
        
        // Date range
        $date_range = isset($settings['date_range']) ? intval($settings['date_range']) : 90;
        $params['start_date.range_start'] = gmdate('Y-m-d\TH:i:s\Z');
        $params['start_date.range_end'] = gmdate('Y-m-d\TH:i:s\Z', strtotime('+' . $date_range . ' days'));
        
        // Merge additional parameters
        $params = array_merge($params, $additional_params);
        
        $full_url = $url . '?' . http_build_query($params);
        
        // Debug logging
        error_log('[ChillEvents][Eventbrite] Request URL: ' . $full_url);
        
        // Make API request
        $response = wp_remote_get($full_url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('[ChillEvents][Eventbrite] WP_Error: ' . $response->get_error_message());
            throw new Exception('API request failed: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        error_log('[ChillEvents][Eventbrite] Response Body: ' . $body);
        
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['events'])) {
            return array();
        }
        
        return $data['events'];
    }
    
    /**
     * Convert Eventbrite event format to standardized format
     *
     * @param array $event Raw Eventbrite event data
     * @return array Standardized event data
     */
    private function convert_eventbrite_event($event) {
        $standardized = array();
        
        // Basic event info
        $standardized['title'] = isset($event['name']['text']) ? $event['name']['text'] : '';
        $standardized['description'] = isset($event['description']['text']) ? $event['description']['text'] : '';
        
        // Date/time
        if (isset($event['start']['utc'])) {
            $standardized['start_date'] = $event['start']['utc'];
        }
        
        if (isset($event['end']['utc'])) {
            $standardized['end_date'] = $event['end']['utc'];
        }
        
        // Venue information
        if (isset($event['venue'])) {
            $venue = $event['venue'];
            $standardized['venue_name'] = isset($venue['name']) ? $venue['name'] : '';
            
            // Build complete address
            if (isset($venue['address'])) {
                $address_parts = array();
                if (!empty($venue['address']['address_1'])) {
                    $address_parts[] = $venue['address']['address_1'];
                }
                if (!empty($venue['address']['address_2'])) {
                    $address_parts[] = $venue['address']['address_2'];
                }
                if (!empty($venue['address']['city'])) {
                    $address_parts[] = $venue['address']['city'];
                }
                if (!empty($venue['address']['region'])) {
                    $address_parts[] = $venue['address']['region'];
                }
                if (!empty($venue['address']['postal_code'])) {
                    $address_parts[] = $venue['address']['postal_code'];
                }
                
                if (!empty($address_parts)) {
                    $standardized['address'] = implode(', ', $address_parts);
                }
                
                // Location (city, region for display)
                $location_parts = array();
                if (!empty($venue['address']['city'])) {
                    $location_parts[] = $venue['address']['city'];
                }
                if (!empty($venue['address']['region'])) {
                    $location_parts[] = $venue['address']['region'];
                }
                if (!empty($location_parts)) {
                    $standardized['location_name'] = implode(', ', $location_parts);
                }
            }
            
            // Venue phone (Eventbrite doesn't provide this in venue data)
            // $standardized['venue_phone'] = '';
            
            // Venue website
            if (isset($venue['website'])) {
                $standardized['venue_website'] = $venue['website'];
            }
        }
        
        // Organizer as artist
        if (isset($event['organizer']['name'])) {
            $standardized['artist_name'] = $event['organizer']['name'];
        }
        
        // Pricing
        if (isset($event['ticket_classes']) && !empty($event['ticket_classes'])) {
            $prices = array();
            foreach ($event['ticket_classes'] as $ticket_class) {
                if (isset($ticket_class['cost'])) {
                    $price = floatval($ticket_class['cost']['major_value']);
                    if ($price > 0) {
                        $prices[] = $price;
                    }
                }
            }
            
            if (!empty($prices)) {
                $min_price = min($prices);
                $max_price = max($prices);
                
                if ($min_price == $max_price) {
                    $standardized['price'] = '$' . number_format($min_price, 2);
                } else {
                    $standardized['price'] = '$' . number_format($min_price, 2) . ' - $' . number_format($max_price, 2);
                }
            } else {
                $standardized['price'] = 'Free';
            }
        }
        
        // Ticket URL
        if (isset($event['url'])) {
            $standardized['ticket_url'] = $event['url'];
        }
        
        // Image
        if (isset($event['logo']['url'])) {
            $standardized['image_url'] = $event['logo']['url'];
        }
        
        // Category
        if (isset($event['category']['name'])) {
            $standardized['event_category'] = $event['category']['name'];
        }
        
        // Format
        if (isset($event['format']['name'])) {
            $standardized['event_type'] = $event['format']['name'];
        }
        
        return $standardized;
    }

    /**
     * Extract organizer ID from URL or return if already numeric
     */
    private function extract_organizer_id($url_or_id) {
        // If it's just a number, return as is
        if (preg_match('/^\\d+$/', $url_or_id)) {
            return $url_or_id;
        }
        // Try to extract from URL
        if (preg_match('/eventbrite\\.com\/o\/[^\/]+-(\\d+)/', $url_or_id, $matches)) {
            return $matches[1];
        }
        return null;
    }
} 