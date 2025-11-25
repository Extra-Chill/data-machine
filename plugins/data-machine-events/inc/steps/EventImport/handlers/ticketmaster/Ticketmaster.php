<?php
/**
 * Ticketmaster Discovery API integration with single-item processing
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster;

use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachine\Core\DataPacket;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-item processing with Discovery API v2 integration
 */
class Ticketmaster extends EventImportHandler {
    
    const API_BASE = 'https://app.ticketmaster.com/discovery/v2/';

    const DEFAULT_PARAMS = [
        'size' => 50,
        'sort' => 'date,asc',
        'page' => 0
    ];

    public function __construct() {
        parent::__construct('ticketmaster');
    }
    
    /**
     * Execute fetch logic
     */
    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $this->log('info', 'Starting event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'flow_step_id' => $flow_step_id
        ]);
        
        $auth = $this->getAuthProvider('ticketmaster');
        if (!$auth) {
            $this->log('error', 'Ticketmaster authentication provider not found');
            return $this->emptyResponse() ?? [];
        }

        $api_config = $auth->get_account();
        if (empty($api_config['api_key'])) {
            $this->log('error', 'API key not configured');
            return $this->emptyResponse() ?? [];
        }
        
        try {
            $search_params = $this->build_search_params($config, $api_config['api_key']);
        } catch (\Exception $e) {
            $this->log('error', $e->getMessage());
            return $this->emptyResponse() ?? [];
        }
        
        $raw_events = $this->fetch_events($search_params);
        if (empty($raw_events)) {
            $this->log('info', 'No events found from Ticketmaster API');
            return $this->emptyResponse() ?? [];
        }
        
        $this->log('info', 'Processing events for eligible item', [
            'raw_events_available' => count($raw_events),
            'pipeline_id' => $pipeline_id
        ]);
        
        // Pass affiliate ID from auth config to mapper
        $affiliate_id = $api_config['affiliate_id'] ?? '';

        foreach ($raw_events as $raw_event) {
            // Only process actively scheduled events
            $event_status = $raw_event['dates']['status']['code'] ?? '';
            if ($event_status !== 'onsale') {
                continue;
            }
            
            $standardized_event = $this->map_ticketmaster_event($raw_event, $affiliate_id);
            
            if (empty($standardized_event['title'])) {
                continue;
            }
            
            $event_identifier = \DataMachineEvents\Utilities\EventIdentifierGenerator::generate(
                $standardized_event['title'],
                $standardized_event['startDate'] ?? '',
                $standardized_event['venue'] ?? ''
            );
            
            if ($this->isItemProcessed($event_identifier, $flow_step_id)) {
                continue;
            }
            
            // Found eligible event
            $this->markItemProcessed($event_identifier, $flow_step_id, $job_id);
            
            $this->log('info', 'Found eligible event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate'],
                'venue' => $standardized_event['venue']
            ]);
            
            // Extract venue metadata
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
            
            EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);

            // Remove venue metadata from event data
            unset($standardized_event['venueAddress'], $standardized_event['venueCity'], 
                  $standardized_event['venueState'], $standardized_event['venueZip'],
                  $standardized_event['venueCountry'], $standardized_event['venuePhone'],
                  $standardized_event['venueWebsite'], $standardized_event['venueCoordinates']);
            
            // Create DataPacket
            $dataPacket = new DataPacket(
                [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'ticketmaster'
                    ], JSON_PRETTY_PRINT)
                ],
                [
                    'source_type' => 'ticketmaster',
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
        
        $this->log('info', 'No eligible events found');
        return $this->emptyResponse() ?? [];
    }
    
    /**
     * Build search parameters for API request
     */
    private function build_search_params(array $handler_config, string $api_key): array {
        $params = array_merge(self::DEFAULT_PARAMS, [
            'apikey' => $api_key
        ]);
        
        if (empty($handler_config['classification_type'])) {
            throw new \Exception('Ticketmaster handler requires classification_type setting. Job failed.');
        }
        
        $classifications = self::get_classifications($api_key);
        $classification_slug = $handler_config['classification_type'];
        
        if (!isset($classifications[$classification_slug])) {
            throw new \Exception('Invalid Ticketmaster classification_type: ' . $classification_slug);
        }
        
        $params['segmentName'] = $classifications[$classification_slug];
        
        $this->log('info', 'Added segment filter', [
            'slug' => $classification_slug,
            'segment_name' => $classifications[$classification_slug]
        ]);
        
        $location = $handler_config['location'] ?? '32.7765,-79.9311'; // Charleston, SC
        $coordinates = $this->get_coordinates($location);
        if ($coordinates) {
            $params['geoPoint'] = $coordinates['lat'] . ',' . $coordinates['lng'];
            $radius = !empty($handler_config['radius']) ? $handler_config['radius'] : '50';
            $params['radius'] = $radius;
            $params['unit'] = 'miles';
        }
        
        $start_date = !empty($handler_config['start_date']) 
            ? $handler_config['start_date'] 
            : date('Y-m-d\TH:i:s\Z', strtotime('+1 hour'));
        $params['startDateTime'] = $start_date;
        
        $page = !empty($handler_config['page']) ? intval($handler_config['page']) : 0;
        $params['page'] = $page;
        
        if (!empty($handler_config['genre'])) {
            $params['genreId'] = $handler_config['genre'];
        }
        
        if (!empty($handler_config['venue_id'])) {
            $params['venueId'] = $handler_config['venue_id'];
        }
        
        return $params;
    }
    
    /**
     * Get event type classifications with 24-hour caching
     */
    public static function get_classifications($api_key = '') {
        $cache_key = 'datamachine_events_ticketmaster_classifications';
        $cached_classifications = get_transient($cache_key);
        
        if ($cached_classifications !== false) {
            return $cached_classifications;
        }
        
        if (empty($api_key)) {
            // We can't easily access the auth provider statically here without dependency injection or a service locator.
            // However, since we are moving away from global filters, we should rely on the caller passing the key.
            // If no key is passed, we can try to instantiate the auth class directly as a fallback, 
            // or just return fallback classifications.
            // Given the architecture, instantiating the auth class is safe since it's a simple provider.
            $auth = new TicketmasterAuth();
            $api_config = $auth->get_account();
            $api_key = $api_config['api_key'] ?? '';
        }
        
        if (empty($api_key)) {
            return self::get_fallback_classifications();
        }
        
        $api_url = 'https://app.ticketmaster.com/discovery/v2/classifications.json';
        $response = wp_remote_get($api_url . '?apikey=' . urlencode($api_key), [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::get_fallback_classifications();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['_embedded']['classifications'])) {
            return self::get_fallback_classifications();
        }
        
        $classifications = self::parse_classifications_response($data);
        set_transient($cache_key, $classifications, 24 * HOUR_IN_SECONDS);
        
        return $classifications;
    }
    
    private static function parse_classifications_response($api_data) {
        $classifications = [];
        $seen_segments = [];
        
        foreach ($api_data['_embedded']['classifications'] as $classification) {
            if (isset($classification['segment'])) {
                $segment = $classification['segment'];
                $segment_name = $segment['name'] ?? '';
                
                if (!empty($segment_name) && !isset($seen_segments[$segment_name])) {
                    $slug = sanitize_key(strtolower($segment_name));
                    $slug = str_replace('_', '-', $slug);
                    
                    $classifications[$slug] = $segment_name;
                    $seen_segments[$segment_name] = true;
                }
            }
        }
        
        return $classifications;
    }
    
    private static function get_fallback_classifications() {
        return [
            'music' => __('Music', 'datamachine-events'),
            'sports' => __('Sports', 'datamachine-events'),
            'arts-theatre' => __('Arts & Theatre', 'datamachine-events'),
            'film' => __('Film', 'datamachine-events'),
            'family' => __('Family', 'datamachine-events')
        ];
    }
    
    public static function get_classifications_for_dropdown($current_config = []) {
        $auth = new TicketmasterAuth();
        $api_config = $auth->get_account();
        $api_key = $api_config['api_key'] ?? '';
        return self::get_classifications($api_key);
    }
    
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
            $this->log('error', 'API request failed: ' . $response->get_error_message());
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data['_embedded']['events'])) {
            return [];
        }
        
        return $data['_embedded']['events'];
    }
    
    private function map_ticketmaster_event(array $tm_event, string $affiliate_id = ''): array {
        $title = $tm_event['name'] ?? '';
        $description = $tm_event['info'] ?? $tm_event['pleaseNote'] ?? '';
        
        $start_date = '';
        $start_time = '';
        if (!empty($tm_event['dates']['start']['localDate'])) {
            $start_date = $tm_event['dates']['start']['localDate'];
            $start_time = $tm_event['dates']['start']['localTime'] ?? '';
        }
        
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
            
            if (!empty($venue['address'])) {
                if (!empty($venue['address']['line1'])) {
                    $venue_address = $venue['address']['line1'];
                }
                if (!empty($venue['address']['line2'])) {
                    $venue_address .= (!empty($venue_address) ? ', ' : '') . $venue['address']['line2'];
                }
                if (!empty($venue['address']['line3'])) {
                    $venue_address .= (!empty($venue_address) ? ', ' : '') . $venue['address']['line3'];
                }
            }
            
            $venue_city = $venue['city']['name'] ?? '';
            $venue_state = $venue['state']['stateCode'] ?? '';
            $venue_zip = $venue['postalCode'] ?? '';
            $venue_country = $venue['country']['countryCode'] ?? '';
            $venue_phone = $venue['boxOfficeInfo']['phoneNumberDetail'] ?? '';
            $venue_website = $venue['url'] ?? '';
            
            if (!empty($venue['location']['latitude']) && !empty($venue['location']['longitude'])) {
                $venue_coordinates = $venue['location']['latitude'] . ',' . $venue['location']['longitude'];
            }
        }
        
        $artist = $tm_event['_embedded']['attractions'][0]['name'] ?? '';
        
        $price = '';
        if (!empty($tm_event['priceRanges'][0])) {
            $price_range = $tm_event['priceRanges'][0];
            $min = $price_range['min'] ?? 0;
            $max = $price_range['max'] ?? 0;
            
            if ($min == $max) {
                $price = '$' . number_format($min, 2);
            } else {
                $price = '$' . number_format($min, 2) . ' - $' . number_format($max, 2);
            }
        }
        
        $ticket_url = $tm_event['url'] ?? '';

        // Construct clean URL from ID if possible
        if (!empty($tm_event['id'])) {
            $clean_url = 'https://www.ticketmaster.com/event/' . $tm_event['id'];
        } else {
            $clean_url = $ticket_url;
        }

        // Apply affiliate link if configured
        if (!empty($affiliate_id)) {
            // Use standard Impact Radius format for Ticketmaster
            // https://ticketmaster.evyy.net/c/{affiliate_id}/264167/4272?u={encoded_url}
            $ticket_url = sprintf(
                'https://ticketmaster.evyy.net/c/%s/264167/4272?u=%s&utm_medium=affiliate',
                $affiliate_id,
                urlencode($clean_url)
            );
        } elseif (strpos($ticket_url, 'ticketmaster.evyy.net') !== false) {
             // If URL is already an affiliate link but broken (contains httpswww), try to fix it using clean URL
             if (strpos($ticket_url, 'httpswww') !== false && !empty($tm_event['id'])) {
                 // Extract affiliate ID if possible, or just use the clean URL if we can't save the affiliate part
                 if (preg_match('/\/c\/(\d+)\//', $ticket_url, $matches)) {
                     $extracted_affiliate_id = $matches[1];
                     $ticket_url = sprintf(
                        'https://ticketmaster.evyy.net/c/%s/264167/4272?u=%s&utm_medium=affiliate',
                        $extracted_affiliate_id,
                        urlencode($clean_url)
                    );
                 } else {
                     // Can't parse affiliate ID, fallback to clean URL to avoid 404
                     $ticket_url = $clean_url;
                 }
             }
        }
        
        return [
            'title' => $this->sanitize_text($title),
            'startDate' => $start_date,
            'endDate' => '',
            'startTime' => $start_time,
            'endTime' => '',
            'venue' => $this->sanitize_text($venue_name),
            'artist' => $this->sanitize_text($artist),
            'price' => $this->sanitize_text($price),
            'ticketUrl' => $this->sanitize_url($ticket_url),
            'description' => $this->clean_html($description),
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
    
    private function get_coordinates(string $location) {
        $location = trim($location);
        $coords = explode(',', $location);
        
        if (count($coords) !== 2) {
            return false;
        }
        
        $lat = trim($coords[0]);
        $lng = trim($coords[1]);
        
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }
        
        $lat = floatval($lat);
        $lng = floatval($lng);
        
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return false;
        }
        
        return [
            'lat' => $lat,
            'lng' => $lng
        ];
    }
    
    private function sanitize_text(string $text): string {
        return sanitize_text_field(trim($text));
    }
    
    private function sanitize_url(string $url): string {
        return esc_url_raw(trim($url));
    }
    
    private function clean_html(string $html): string {
        if (empty($html)) {
            return '';
        }
        return html_entity_decode(strip_tags($html, '<a><br><p>'));
    }
}
