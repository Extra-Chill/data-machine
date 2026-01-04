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
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-item processing with Discovery API v2 integration
 */
class Ticketmaster extends EventImportHandler {

    use HandlerRegistrationTrait;
    
    const API_BASE = 'https://app.ticketmaster.com/discovery/v2/';

    const DEFAULT_PARAMS = [
        'size' => 50,
        'sort' => 'date,asc',
        'page' => 0
    ];

    const MAX_PAGE = 19;

    public function __construct() {
        parent::__construct('ticketmaster');

        self::registerHandler(
            'ticketmaster',
            'event_import',
            self::class,
            __('Ticketmaster Events', 'datamachine-events'),
            __('Import events from Ticketmaster Discovery API with venue data', 'datamachine-events'),
            true,
            TicketmasterAuth::class,
            TicketmasterSettings::class,
            null
        );
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
            return [];
        }

        $api_config = $auth->get_account();
        if (empty($api_config['api_key'])) {
            $this->log('error', 'API key not configured');
            return [];
        }
        
        try {
            $search_params = $this->build_search_params($config, $api_config['api_key']);
        } catch (\Exception $e) {
            $this->log('error', $e->getMessage());
            return [];
        }
        
        $current_page = 0;
        
        do {
            $search_params['page'] = $current_page;
            $result = $this->fetch_events($search_params);
            $raw_events = $result['events'];
            $page_info = $result['page'];
            
            if (empty($raw_events)) {
                if ($current_page === 0) {
                    $this->log('info', 'No events found from Ticketmaster API');
                }
                break;
            }
            
            $this->log('info', 'Processing events for eligible item', [
                'page' => $current_page,
                'events_on_page' => count($raw_events),
                'total_pages' => $page_info['totalPages'],
                'pipeline_id' => $pipeline_id
            ]);
            
            foreach ($raw_events as $raw_event) {
                $event_status = $raw_event['dates']['status']['code'] ?? '';
                if ($event_status !== 'onsale') {
                    continue;
                }
                
                $standardized_event = $this->map_ticketmaster_event($raw_event);
                
                if (empty($standardized_event['title'])) {
                    continue;
                }

                if ($this->shouldSkipEventTitle($standardized_event['title'])) {
                    continue;
                }

                $search_text = $standardized_event['title'] . ' ' . ($standardized_event['description'] ?? '');

                if (!$this->applyKeywordSearch($search_text, $config['search'] ?? '')) {
                    continue;
                }

                if ($this->applyExcludeKeywords($search_text, $config['exclude_keywords'] ?? '')) {
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
                
                $this->markItemProcessed($event_identifier, $flow_step_id, $job_id);
                
                $this->log('info', 'Found eligible event', [
                    'title' => $standardized_event['title'],
                    'date' => $standardized_event['startDate'],
                    'venue' => $standardized_event['venue'],
                    'page' => $current_page
                ]);
                
                $venue_metadata = $this->extractVenueMetadata($standardized_event);
                
                EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);

                $resolved_job_id = (int) $job_id;
                if ($resolved_job_id > 0 && function_exists('datamachine_merge_engine_data') && !empty($standardized_event['price'])) {
                    datamachine_merge_engine_data($resolved_job_id, [
                        'price' => $standardized_event['price'],
                    ]);
                }

                $this->stripVenueMetadataFromEvent($standardized_event);
                
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
                
                return [$dataPacket];
            }
            
            $has_more_pages = $page_info['number'] < ($page_info['totalPages'] - 1)
                              && $current_page < self::MAX_PAGE;
            
            if ($has_more_pages) {
                $this->log('info', 'No eligible events on page, fetching next', [
                    'current_page' => $current_page,
                    'total_pages' => $page_info['totalPages']
                ]);
            }
            
            $current_page++;
            
        } while ($has_more_pages);
        
        $this->log('info', 'No eligible events found', [
            'pages_searched' => $current_page
        ]);
        return [];
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
        $coordinates = $this->parseCoordinates($location);
        if ($coordinates) {
            $params['geoPoint'] = $coordinates['lat'] . ',' . $coordinates['lng'];
            $radius = !empty($handler_config['radius']) ? $handler_config['radius'] : '50';
            $params['radius'] = $radius;
            $params['unit'] = 'miles';
        }
        
        $page = !empty($handler_config['page']) ? intval($handler_config['page']) : 0;
        $params['page'] = $page;
        
        $params['startDateTime'] = gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 hour'));
        
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
        
        $api_url = 'https://app.ticketmaster.com/discovery/v2/classifications.json?apikey=' . urlencode($api_key);
        $result = \DataMachine\Core\HttpClient::get($api_url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'context' => 'Ticketmaster Classifications',
        ]);
        
        if (!$result['success'] || $result['status_code'] !== 200) {
            return self::get_fallback_classifications();
        }
        
        $body = $result['data'];
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
        
        $result = $this->httpGet($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
        
        if (!$result['success']) {
            $this->log('error', 'API request failed: ' . ($result['error'] ?? 'Unknown error'));
            return [
                'events' => [],
                'page' => ['number' => 0, 'totalPages' => 1]
            ];
        }
        
        $body = $result['data'];
        $data = json_decode($body, true);
        
        return [
            'events' => $data['_embedded']['events'] ?? [],
            'page' => $data['page'] ?? ['number' => 0, 'totalPages' => 1]
        ];
    }
    
    private function map_ticketmaster_event(array $tm_event): array {
        $title = $tm_event['name'] ?? '';
        $description = $tm_event['info'] ?? $tm_event['pleaseNote'] ?? '';
        
        $start_parsed = $this->parseDateTimeLocal(
            $tm_event['dates']['start']['localDate'] ?? '',
            $tm_event['dates']['start']['localTime'] ?? '',
            $tm_event['_embedded']['venues'][0]['timezone'] ?? ''
        );
        
        $venue_name = '';
        $venue_address = '';
        $venue_city = '';
        $venue_state = '';
        $venue_zip = '';
        $venue_country = '';
        $venue_phone = '';
        $venue_website = '';
        $venue_coordinates = '';
        $venue_timezone = '';
        
        if (!empty($tm_event['_embedded']['venues'][0])) {
            $venue = $tm_event['_embedded']['venues'][0];
            $venue_name = $venue['name'] ?? '';
            $venue_timezone = $venue['timezone'] ?? '';
            
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
            $venue_website = '';
            
            if (!empty($venue['location']['latitude']) && !empty($venue['location']['longitude'])) {
                $venue_coordinates = $venue['location']['latitude'] . ',' . $venue['location']['longitude'];
            }
        }
        
        $artist = $tm_event['_embedded']['attractions'][0]['name'] ?? '';

        $organizer = '';
        if (!empty($tm_event['promoter']['name'])) {
            $organizer = $tm_event['promoter']['name'];
        } elseif (!empty($tm_event['promoters'][0]['name'])) {
            $organizer = $tm_event['promoters'][0]['name'];
        }
        
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
        
        return [
            'title' => $this->sanitizeText($title),
            'startDate' => $start_parsed['date'],
            'endDate' => '',
            'startTime' => $start_parsed['time'],
            'endTime' => '',
            'venue' => $this->sanitizeText($venue_name),
            'artist' => $this->sanitizeText($artist),
            'organizer' => $this->sanitizeText($organizer),
            'price' => $this->sanitizeText($price),
            'ticketUrl' => $this->sanitizeUrl($ticket_url),
            'description' => $this->cleanHtml($description),
            'venueAddress' => $this->sanitizeText($venue_address),
            'venueCity' => $this->sanitizeText($venue_city),
            'venueState' => $this->sanitizeText($venue_state),
            'venueZip' => $this->sanitizeText($venue_zip),
            'venueCountry' => $this->sanitizeText($venue_country),
            'venuePhone' => $this->sanitizeText($venue_phone),
            'venueWebsite' => $this->sanitizeUrl($venue_website),
            'venueCoordinates' => $this->sanitizeText($venue_coordinates),
            'venueTimezone' => $this->sanitizeText($venue_timezone),
        ];
    }
}
