<?php
/**
 * Ticketmaster API Data Source for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;

use ChillEvents\Events\EventDuplicateChecker;
use ChillEvents\Events\StandardizedEvent;

if (!defined('ABSPATH')) {
    exit;
}

class TicketmasterApi extends BaseDataSource {
    /**
     * Get info about this data source
     *
     * @return array
     */
    public function get_info() {
        return array(
            'name' => 'Ticketmaster API',
            'slug' => 'ticketmaster',
            'type' => 'api',
            'description' => 'Official Live Nation/Ticketmaster integration with global venue network access',
            'coverage' => 'Global - 40+ countries, 200+ major venues',
            'official' => true,
            'requires_config' => true,
            'requires_api_key' => true,
            'api_key_description' => 'Get your Consumer Key from the Ticketmaster Developer Portal',
            'settings_fields' => array(
                'location_type' => array(
                    'type' => 'select',
                    'label' => 'Search Type',
                    'options' => array(
                        'coordinates' => 'Coordinates (Lat/Lng)',
                        'city' => 'City Name',
                        'venue_id' => 'Specific Venue ID'
                    ),
                    'default' => 'city',
                    'description' => 'How to specify the location for event searches.'
                ),
                'latitude' => array(
                    'type' => 'number',
                    'label' => 'Latitude',
                    'step' => '0.000001',
                    'description' => 'Latitude coordinate (e.g., 32.7765 for Charleston, SC). Required if using coordinates.',
                    'conditional' => array('location_type' => 'coordinates')
                ),
                'longitude' => array(
                    'type' => 'number',
                    'label' => 'Longitude',
                    'step' => '0.000001',
                    'description' => 'Longitude coordinate (e.g., -79.9311 for Charleston, SC). Required if using coordinates.',
                    'conditional' => array('location_type' => 'coordinates')
                ),
                'city' => array(
                    'type' => 'text',
                    'label' => 'City Name',
                    'description' => 'City name for event search (e.g., "Charleston, SC"). Required if using city search.',
                    'conditional' => array('location_type' => 'city')
                ),
                'venue_id' => array(
                    'type' => 'text',
                    'label' => 'Venue ID',
                    'description' => 'Specific Ticketmaster venue ID. Required if searching by venue.',
                    'conditional' => array('location_type' => 'venue_id')
                ),
                'radius' => array(
                    'type' => 'number',
                    'label' => 'Search Radius (miles)',
                    'default' => 50,
                    'min' => 1,
                    'max' => 500,
                    'description' => 'Search radius in miles from the specified location.'
                ),
                'classification' => array(
                    'type' => 'select',
                    'label' => 'Event Classification',
                    'options' => array(
                        'music' => 'Music',
                        'sports' => 'Sports',
                        'arts' => 'Arts & Theatre',
                        'family' => 'Family',
                        'film' => 'Film',
                        'miscellaneous' => 'Miscellaneous'
                    ),
                    'default' => 'music',
                    'description' => 'Type of events to import.'
                ),
                'market_id' => array(
                    'type' => 'text',
                    'label' => 'Market ID (Optional)',
                    'description' => 'Ticketmaster market ID for more targeted results (optional).'
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
     * Get a single page of events from this data source
     *
     * @param array $settings Data source settings
     * @param int $page Page number (zero-based)
     * @param int $size Number of events per page
     * @return array Array of standardized event data (may include duplicates)
     */
    public function get_events($settings = array(), $page = 0, $size = 200) {
        $api_config = \ChillEvents\ApiConfig::get('ticketmaster');
        $api_key = isset($api_config['api_key']) ? $api_config['api_key'] : null;
        
        if (!$api_key) {
            $this->log_error('Ticketmaster API key not configured. Please check API Settings.');
            return array();
        }
        
        $date_range = isset($settings['date_range']) ? intval($settings['date_range']) : 90;
        $startDateTime = gmdate('Y-m-d\TH:i:s\Z');
        $endDateTime = gmdate('Y-m-d\TH:i:s\Z', strtotime('+' . $date_range . ' days'));
        
        $events = $this->fetch_ticketmaster_events($api_key, $settings, $page, $size, $startDateTime, $endDateTime);
        
        $standardized_events = array();
        foreach ($events as $event) {
            $standardized = $this->convert_ticketmaster_event($event);
            $event_time = strtotime($standardized['start_date']);
            if ($event_time < strtotime($startDateTime) || $event_time > strtotime($endDateTime)) {
                continue;
            }
            $standardized_events[] = new StandardizedEvent($standardized);
        }
        
        return $standardized_events;
    }
    
    /**
     * Fetch events from Ticketmaster API (paginated)
     *
     * @param string $api_key API key
     * @param array $settings Module settings
     * @param int $page Page number (zero-based)
     * @param int $size Number of events per page
     * @param string $startDateTime ISO8601 start datetime
     * @param string $endDateTime ISO8601 end datetime
     * @return array Raw event data from API
     */
    private function fetch_ticketmaster_events($api_key, $settings, $page = 0, $size = 200, $startDateTime = '', $endDateTime = '') {
        $base_url = 'https://app.ticketmaster.com/discovery/v2/events.json';
        
        // Build query parameters
        $params = array(
            'apikey' => $api_key,
            'classificationName' => isset($settings['classification']) ? $settings['classification'] : 'music',
            'size' => $size,
            'page' => $page,
            'sort' => 'date,asc',
            'startDateTime' => $startDateTime,
            'endDateTime' => $endDateTime
        );
        
        // Location parameters
        $location_type = isset($settings['location_type']) ? $settings['location_type'] : 'coordinates';
        
        switch ($location_type) {
            case 'coordinates':
                if (isset($settings['latitude']) && isset($settings['longitude'])) {
                    $params['latlong'] = $settings['latitude'] . ',' . $settings['longitude'];
                    $params['radius'] = isset($settings['radius']) ? $settings['radius'] : 50;
                    $params['unit'] = 'miles';
                }
                break;
            case 'city':
                if (isset($settings['city'])) {
                    $params['city'] = $settings['city'];
                }
                break;
            case 'venue_id':
                if (isset($settings['venue_id'])) {
                    $params['venueId'] = $settings['venue_id'];
                }
                break;
        }
        
        // Market ID if specified
        if (!empty($settings['market_id'])) {
            $params['marketId'] = $settings['market_id'];
        }
        
        $url = $base_url . '?' . http_build_query($params);
        
        // Make API request
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        $data = json_decode($body, true);
        
        if (!$data) {
            return array();
        }
        
        if (!isset($data['_embedded']['events'])) {
            return array();
        }
        
        return $data['_embedded']['events'];
    }
    
    /**
     * Convert Ticketmaster event format to standardized format
     *
     * @param array $event Raw Ticketmaster event data
     * @return array Standardized event data
     */
    private function convert_ticketmaster_event($event) {
        $standardized = array();
        // Always include the Ticketmaster event ID
        $standardized['id'] = isset($event['id']) ? $event['id'] : '';
        
        // Basic event info
        $standardized['title'] = isset($event['name']) ? $event['name'] : '';
        $standardized['description'] = isset($event['info']) ? $event['info'] : '';
        
        // Date/time
        if (isset($event['dates']['start']['dateTime'])) {
            $standardized['start_date'] = $event['dates']['start']['dateTime'];
        } elseif (isset($event['dates']['start']['localDate'])) {
            $standardized['start_date'] = $event['dates']['start']['localDate'] . ' 00:00:00';
        }
        
        // Venue information
        if (isset($event['_embedded']['venues'][0])) {
            $venue = $event['_embedded']['venues'][0];
            $standardized['venue_name'] = isset($venue['name']) ? $venue['name'] : '';
            
            // Address
            if (isset($venue['address']['line1'])) {
                $standardized['address'] = $venue['address']['line1'];
            }
            
            // Location
            if (isset($venue['city']['name'])) {
                $location = $venue['city']['name'];
                if (isset($venue['state']['stateCode'])) {
                    $location .= ', ' . $venue['state']['stateCode'];
                }
                $standardized['location_name'] = $location;
            }
            
            // Venue phone
            if (isset($venue['boxOfficeInfo']['phoneNumberDetail'])) {
                $standardized['venue_phone'] = $venue['boxOfficeInfo']['phoneNumberDetail'];
            }
            
            // Venue website
            if (isset($venue['url'])) {
                $standardized['venue_website'] = $venue['url'];
            }
        }
        
        // Artist/performer information
        if (isset($event['_embedded']['attractions'])) {
            $artists = array();
            foreach ($event['_embedded']['attractions'] as $attraction) {
                if (isset($attraction['name'])) {
                    $artists[] = $attraction['name'];
                }
            }
            if (!empty($artists)) {
                $standardized['artist_name'] = implode(', ', $artists);
            }
        }
        
        // Pricing
        if (isset($event['priceRanges'][0])) {
            $min_price = $event['priceRanges'][0]['min'];
            $max_price = $event['priceRanges'][0]['max'];
            $currency = isset($event['priceRanges'][0]['currency']) ? $event['priceRanges'][0]['currency'] : 'USD';
            
            if ($min_price == $max_price) {
                $standardized['price'] = '$' . number_format($min_price, 2);
            } else {
                $standardized['price'] = '$' . number_format($min_price, 2) . ' - $' . number_format($max_price, 2);
            }
        }
        
        // Ticket URL
        if (isset($event['url'])) {
            $standardized['ticket_url'] = $event['url'];
        }
        
        // Image
        if (isset($event['images'][0]['url'])) {
            $standardized['image_url'] = $event['images'][0]['url'];
        }
        
        // Genre/classification
        if (isset($event['classifications'][0]['genre']['name'])) {
            $standardized['genre'] = $event['classifications'][0]['genre']['name'];
        }
        
        if (isset($event['classifications'][0]['segment']['name'])) {
            $standardized['event_type'] = $event['classifications'][0]['segment']['name'];
        }
        
        return $standardized;
    }

    /**
     * Standardize event data for Ticketmaster events
     *
     * @param array $raw_event Raw event data
     * @return array Sanitized event data
     */
    public function standardize_event_data($raw_event) {
        return parent::standardize_event_data($raw_event);
    }
} 