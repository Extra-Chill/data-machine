<?php
/**
 * Eventbrite Event Import Handler
 * 
 * Parses public Eventbrite organizer pages to extract events from embedded
 * Schema.org JSON-LD structured data. Works with any public organizer URL.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Eventbrite
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Eventbrite;

use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Eventbrite handler with single-item processing via JSON-LD extraction
 */
class Eventbrite extends EventImportHandler {

    use HandlerRegistrationTrait;

    public function __construct() {
        parent::__construct('eventbrite');

        self::registerHandler(
            'eventbrite',
            'event_import',
            self::class,
            __('Eventbrite Events', 'datamachine-events'),
            __('Import events from any public Eventbrite organizer page via JSON-LD extraction', 'datamachine-events'),
            false,
            null,
            EventbriteSettings::class,
            null
        );
    }
    
    /**
     * Execute fetch logic - parse Eventbrite organizer page for events
     */
    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $this->log('info', 'Starting Eventbrite event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'flow_step_id' => $flow_step_id
        ]);
        
        $organizer_url = trim($config['organizer_url'] ?? '');
        if (empty($organizer_url)) {
            $this->log('error', 'No organizer URL configured');
            return $this->emptyResponse();
        }
        
        if (!filter_var($organizer_url, FILTER_VALIDATE_URL)) {
            $this->log('error', 'Invalid organizer URL', ['url' => $organizer_url]);
            return $this->emptyResponse();
        }
        
        $raw_events = $this->fetch_events_from_page($organizer_url);
        if (empty($raw_events)) {
            $this->log('info', 'No events found on Eventbrite organizer page');
            return $this->emptyResponse();
        }
        
        $this->log('info', 'Processing events for eligible item', [
            'raw_events_available' => count($raw_events),
            'pipeline_id' => $pipeline_id
        ]);
        
        foreach ($raw_events as $raw_event) {
            $standardized_event = $this->map_eventbrite_event($raw_event);
            
            if (empty($standardized_event['title'])) {
                continue;
            }
            
            $event_identifier = \DataMachineEvents\Utilities\EventIdentifierGenerator::generate(
                $standardized_event['title'],
                $standardized_event['startDate'] ?? '',
                $standardized_event['venue'] ?? ''
            );
            
            if ($this->isItemProcessed($event_identifier, $flow_step_id)) {
                $this->log('debug', 'Skipping already processed event', [
                    'title' => $standardized_event['title'],
                    'event_identifier' => $event_identifier
                ]);
                continue;
            }
            
            if ($this->isPastEvent($standardized_event['startDate'] ?? '')) {
                $this->log('debug', 'Skipping past event', [
                    'title' => $standardized_event['title'],
                    'date' => $standardized_event['startDate']
                ]);
                continue;
            }
            
            $this->markItemProcessed($event_identifier, $flow_step_id, $job_id);
            
            $this->log('info', 'Found eligible event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate'],
                'venue' => $standardized_event['venue']
            ]);
            
            $venue_metadata = $this->extractVenueMetadata($standardized_event);
            
            EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);
            
            $this->stripVenueMetadataFromEvent($standardized_event);
            
            $dataPacket = new DataPacket(
                [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'eventbrite'
                    ], JSON_PRETTY_PRINT)
                ],
                [
                    'source_type' => 'eventbrite',
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
        return $this->emptyResponse();
    }
    
    /**
     * Fetch and parse events from Eventbrite organizer page
     */
    private function fetch_events_from_page(string $url): array {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml',
                'User-Agent' => 'Mozilla/5.0 (compatible; DataMachine/1.0)'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->log('error', 'Failed to fetch Eventbrite page: ' . $response->get_error_message());
            return [];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $this->log('error', 'Eventbrite page returned status ' . $status_code);
            return [];
        }
        
        $html = wp_remote_retrieve_body($response);
        
        return $this->extract_json_ld_events($html);
    }
    
    /**
     * Extract events from JSON-LD script tags in HTML
     */
    private function extract_json_ld_events(string $html): array {
        $events = [];
        
        if (!preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches)) {
            $this->log('debug', 'No JSON-LD scripts found in page');
            return [];
        }
        
        foreach ($matches[1] as $json_content) {
            $json_content = trim($json_content);
            if (empty($json_content)) {
                continue;
            }
            
            $data = json_decode($json_content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }
            
            if (isset($data['@type']) && $data['@type'] === 'ItemList' && isset($data['itemListElement'])) {
                foreach ($data['itemListElement'] as $item) {
                    if (isset($item['@type']) && $item['@type'] === 'ListItem' && isset($item['item'])) {
                        $nested = $item['item'];
                        if (isset($nested['@type']) && $nested['@type'] === 'Event') {
                            $events[] = $nested;
                        }
                    } elseif (isset($item['@type']) && $item['@type'] === 'Event') {
                        $events[] = $item;
                    }
                }
            }
            
            if (isset($data['@type']) && $data['@type'] === 'Event') {
                $events[] = $data;
            }
        }
        
        $this->log('debug', 'Extracted events from JSON-LD', ['count' => count($events)]);
        
        return $events;
    }
    
    /**
     * Map Eventbrite Schema.org Event to standardized format
     */
    private function map_eventbrite_event(array $eb_event): array {
        $title = $eb_event['name'] ?? '';
        $description = $eb_event['description'] ?? '';
        
        $start_date = '';
        $start_time = '';
        if (!empty($eb_event['startDate'])) {
            $parsed = $this->parse_iso_datetime($eb_event['startDate']);
            $start_date = $parsed['date'];
            $start_time = $parsed['time'];
        }
        
        $end_date = '';
        $end_time = '';
        if (!empty($eb_event['endDate'])) {
            $parsed = $this->parse_iso_datetime($eb_event['endDate']);
            $end_date = $parsed['date'];
            $end_time = $parsed['time'];
        }
        
        $venue_name = '';
        $venue_address = '';
        $venue_city = '';
        $venue_state = '';
        $venue_zip = '';
        $venue_country = '';
        $venue_coordinates = '';
        
        $location = $eb_event['location'] ?? [];
        if (!empty($location)) {
            $venue_name = $location['name'] ?? '';
            
            $address = $location['address'] ?? [];
            if (!empty($address)) {
                $venue_address = $address['streetAddress'] ?? '';
                $venue_city = $address['addressLocality'] ?? '';
                $venue_state = $address['addressRegion'] ?? '';
                $venue_zip = $address['postalCode'] ?? '';
                $venue_country = $address['addressCountry'] ?? '';
            }
            
            $geo = $location['geo'] ?? [];
            if (!empty($geo['latitude']) && !empty($geo['longitude'])) {
                $venue_coordinates = $geo['latitude'] . ',' . $geo['longitude'];
            }
        }
        
        $price = '';
        $ticket_url = $eb_event['url'] ?? '';
        $offers = $eb_event['offers'] ?? [];
        
        if (!empty($offers)) {
            $offer = is_array($offers) && isset($offers[0]) ? $offers[0] : $offers;
            
            $low_price = $offer['lowPrice'] ?? $offer['price'] ?? '';
            $high_price = $offer['highPrice'] ?? '';
            $currency = $offer['priceCurrency'] ?? 'USD';
            
            if (!empty($low_price)) {
                if (!empty($high_price) && $high_price != $low_price) {
                    $price = '$' . number_format((float)$low_price, 2) . ' - $' . number_format((float)$high_price, 2);
                } else {
                    $price = '$' . number_format((float)$low_price, 2);
                }
            }
            
            if (empty($ticket_url) && !empty($offer['url'])) {
                $ticket_url = $offer['url'];
            }
        }
        
        $artist = '';
        $performers = $eb_event['performer'] ?? [];
        if (!empty($performers)) {
            $performer = is_array($performers) && isset($performers[0]) ? $performers[0] : $performers;
            $artist = $performer['name'] ?? '';
        }
        
        $image_url = '';
        if (!empty($eb_event['image'])) {
            $image_url = is_string($eb_event['image']) ? $eb_event['image'] : ($eb_event['image']['url'] ?? '');
        }
        
        return [
            'title' => $this->sanitizeText($title),
            'startDate' => $start_date,
            'endDate' => $end_date,
            'startTime' => $start_time,
            'endTime' => $end_time,
            'venue' => $this->sanitizeText($venue_name),
            'artist' => $this->sanitizeText($artist),
            'price' => $this->sanitizeText($price),
            'ticketUrl' => $this->sanitizeUrl($ticket_url),
            'description' => $this->cleanHtml($description),
            'imageUrl' => $this->sanitizeUrl($image_url),
            'venueAddress' => $this->sanitizeText($venue_address),
            'venueCity' => $this->sanitizeText($venue_city),
            'venueState' => $this->sanitizeText($venue_state),
            'venueZip' => $this->sanitizeText($venue_zip),
            'venueCountry' => $this->sanitizeText($venue_country),
            'venuePhone' => '',
            'venueWebsite' => '',
            'venueCoordinates' => $this->sanitizeText($venue_coordinates),
        ];
    }
    
    /**
     * Parse ISO 8601 datetime string into date and time components
     */
    private function parse_iso_datetime(string $datetime): array {
        $result = ['date' => '', 'time' => ''];
        
        if (empty($datetime)) {
            return $result;
        }
        
        try {
            $dt = new \DateTime($datetime);
            $result['date'] = $dt->format('Y-m-d');
            $result['time'] = $dt->format('H:i');
        } catch (\Exception $e) {
            $this->log('error', 'Failed to parse datetime: ' . $datetime);
        }
        
        return $result;
    }
}
