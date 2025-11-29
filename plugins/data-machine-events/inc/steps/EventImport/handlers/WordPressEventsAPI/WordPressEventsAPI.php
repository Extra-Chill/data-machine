<?php
/**
 * WordPress Events API Handler
 *
 * Imports events from external WordPress sites running Tribe Events Calendar
 * or similar event plugins via their REST API endpoints.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WordPressEventsAPI
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WordPressEventsAPI;

use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

class WordPressEventsAPI extends EventImportHandler {

    use HandlerRegistrationTrait;

    public function __construct() {
        parent::__construct('wordpress_events_api');

        self::registerHandler(
            'wordpress_events_api',
            'event_import',
            self::class,
            __('WordPress Events API', 'datamachine-events'),
            __('Import events from external WordPress sites running Tribe Events or similar plugins', 'datamachine-events'),
            false,
            null,
            WordPressEventsAPISettings::class,
            null
        );
    }

    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $this->log('info', 'Starting WordPress Events API import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'flow_step_id' => $flow_step_id
        ]);

        $endpoint_url = trim($config['endpoint_url'] ?? '');
        if (empty($endpoint_url)) {
            $this->log('error', 'WordPress Events API handler requires endpoint_url configuration');
            return $this->emptyResponse() ?? [];
        }

        if (!filter_var($endpoint_url, FILTER_VALIDATE_URL)) {
            $this->log('error', 'Invalid endpoint URL format', ['endpoint_url' => $endpoint_url]);
            return $this->emptyResponse() ?? [];
        }

        $api_response = $this->fetch_events($endpoint_url, $config);
        if (empty($api_response)) {
            $this->log('info', 'No response from WordPress Events API');
            return $this->emptyResponse() ?? [];
        }

        $api_format = $this->detect_api_format($api_response);

        $raw_events = $this->extract_events_from_response($api_response, $api_format);
        if (empty($raw_events)) {
            $this->log('info', 'No events found in WordPress Events API response');
            return $this->emptyResponse() ?? [];
        }

        $this->log('info', 'Processing WordPress Events API events', [
            'raw_events_available' => count($raw_events),
            'api_format' => $api_format,
            'pipeline_id' => $pipeline_id
        ]);

        foreach ($raw_events as $raw_event) {
            $standardized_event = $this->map_event($raw_event, $api_format, $config);

            if (empty($standardized_event['title'])) {
                continue;
            }

            $search_text = $standardized_event['title'] . ' ' . ($standardized_event['description'] ?? '');

            if (!$this->applyKeywordSearch($search_text, $config['search'] ?? '')) {
                $this->log('debug', 'Skipping event (include keywords)', [
                    'title' => $standardized_event['title']
                ]);
                continue;
            }

            if ($this->applyExcludeKeywords($search_text, $config['exclude_keywords'] ?? '')) {
                $this->log('debug', 'Skipping event (exclude keywords)', [
                    'title' => $standardized_event['title']
                ]);
                continue;
            }

            $event_identifier = EventIdentifierGenerator::generate(
                $standardized_event['title'],
                $standardized_event['startDate'] ?? '',
                $standardized_event['venue'] ?? ''
            );

            if ($this->isItemProcessed($event_identifier, $flow_step_id)) {
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

            $this->log('info', 'Found eligible WordPress Events API event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate'],
                'venue' => $standardized_event['venue']
            ]);

            $venue_metadata = $this->extractVenueMetadata($standardized_event);

            EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);

            if (!empty($standardized_event['image'])) {
                $this->storeImageInEngine($job_id, $standardized_event['image']);
            }

            $this->stripVenueMetadataFromEvent($standardized_event);
            unset($standardized_event['image']);

            $dataPacket = new DataPacket(
                [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'wordpress_events_api'
                    ], JSON_PRETTY_PRINT)
                ],
                [
                    'source_type' => 'wordpress_events_api',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_id,
                    'original_title' => $standardized_event['title'],
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time()
                ],
                'event_import'
            );

            return $this->successResponse([$dataPacket]);
        }

        $this->log('info', 'No eligible WordPress Events API events found');
        return $this->emptyResponse() ?? [];
    }

    private function fetch_events(string $endpoint_url, array $config): array {
        $query_params = [
            'per_page' => 100,
        ];

        $url = add_query_arg($query_params, $endpoint_url);

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Data Machine Events WordPress Plugin'
            ]
        ]);

        if (is_wp_error($response)) {
            $this->log('error', 'WordPress Events API request failed: ' . $response->get_error_message());
            return [];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $this->log('error', 'WordPress Events API returned non-200 status', ['status' => $status_code]);
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', 'WordPress Events API returned invalid JSON');
            return [];
        }

        return $data;
    }

    private function detect_api_format(array $response): string {
        if (isset($response['events']) && isset($response['rest_url'])) {
            return 'tribe_v1';
        }

        if (is_array($response) && !empty($response) && isset($response[0]['id'])) {
            if (isset($response[0]['start_date']) || isset($response[0]['meta']['_EventStartDate'])) {
                return 'tribe_wp';
            }
            return 'generic_wp';
        }

        return 'generic_wp';
    }

    private function extract_events_from_response(array $response, string $api_format): array {
        if ($api_format === 'tribe_v1') {
            return $response['events'] ?? [];
        }

        if (is_array($response) && !empty($response) && isset($response[0])) {
            return $response;
        }

        return [];
    }

    private function map_event(array $event, string $api_format, array $config): array {
        switch ($api_format) {
            case 'tribe_v1':
                return $this->map_tribe_v1_event($event, $config);
            case 'tribe_wp':
                return $this->map_tribe_wp_event($event, $config);
            default:
                return $this->map_generic_event($event, $config);
        }
    }

    private function map_tribe_v1_event(array $event, array $config): array {
        $title = $event['title'] ?? '';
        $description = $event['description'] ?? '';

        $start_date = '';
        $start_time = '';
        $end_date = '';
        $end_time = '';

        if (!empty($event['start_date'])) {
            $datetime = new \DateTime($event['start_date']);
            $start_date = $datetime->format('Y-m-d');
            $start_time = $datetime->format('H:i');
        }

        if (!empty($event['end_date'])) {
            $datetime = new \DateTime($event['end_date']);
            $end_date = $datetime->format('Y-m-d');
            $end_time = $datetime->format('H:i');
        }

        if (!empty($event['start_date_details'])) {
            $details = $event['start_date_details'];
            if (!empty($details['hour']) && !empty($details['minutes'])) {
                $start_time = sprintf('%02d:%02d', $details['hour'], $details['minutes']);
            }
        }

        if (!empty($event['end_date_details'])) {
            $details = $event['end_date_details'];
            if (!empty($details['hour']) && !empty($details['minutes'])) {
                $end_time = sprintf('%02d:%02d', $details['hour'], $details['minutes']);
            }
        }

        $all_day = $event['all_day'] ?? false;
        if ($all_day) {
            $start_time = '';
            $end_time = '';
        }

        $venue_data = $this->extract_tribe_venue($event);

        if (!empty($config['venue_name_override'])) {
            $venue_data['venue'] = $config['venue_name_override'];
        }

        $price = $event['cost'] ?? '';
        $ticket_url = $event['website'] ?? '';

        $image_url = '';
        if (!empty($event['image']['url'])) {
            $image_url = $event['image']['url'];
        }

        return [
            'title' => $this->sanitizeText($title),
            'description' => $this->cleanHtml($description),
            'startDate' => $start_date,
            'endDate' => $end_date,
            'startTime' => $start_time,
            'endTime' => $end_time,
            'venue' => $this->sanitizeText($venue_data['venue']),
            'venueAddress' => $this->sanitizeText($venue_data['venueAddress']),
            'venueCity' => $this->sanitizeText($venue_data['venueCity']),
            'venueState' => $this->sanitizeText($venue_data['venueState']),
            'venueZip' => $this->sanitizeText($venue_data['venueZip']),
            'venueCountry' => $this->sanitizeText($venue_data['venueCountry']),
            'venuePhone' => $this->sanitizeText($venue_data['venuePhone']),
            'venueWebsite' => $this->sanitizeUrl($venue_data['venueWebsite']),
            'venueCoordinates' => $this->sanitizeText($venue_data['venueCoordinates']),
            'price' => $this->sanitizeText($price),
            'ticketUrl' => $this->sanitizeUrl($ticket_url),
            'image' => $image_url,
        ];
    }

    private function extract_tribe_venue(array $event): array {
        $venue = $event['venue'] ?? [];

        $venue_name = '';
        $venue_address = '';
        $venue_city = '';
        $venue_state = '';
        $venue_zip = '';
        $venue_country = '';
        $venue_phone = '';
        $venue_website = '';
        $venue_coordinates = '';

        if (!empty($venue)) {
            $venue_name = $venue['venue'] ?? '';
            $venue_address = $venue['address'] ?? '';
            $venue_city = $venue['city'] ?? '';
            $venue_state = $venue['state'] ?? $venue['province'] ?? '';
            $venue_zip = $venue['zip'] ?? $venue['postal_code'] ?? '';
            $venue_country = $venue['country'] ?? '';
            $venue_phone = $venue['phone'] ?? '';
            $venue_website = $venue['website'] ?? $venue['url'] ?? '';

            if (!empty($venue['geo_lat']) && !empty($venue['geo_lng'])) {
                $venue_coordinates = $venue['geo_lat'] . ',' . $venue['geo_lng'];
            }
        }

        return [
            'venue' => $venue_name,
            'venueAddress' => $venue_address,
            'venueCity' => $venue_city,
            'venueState' => $venue_state,
            'venueZip' => $venue_zip,
            'venueCountry' => $venue_country,
            'venuePhone' => $venue_phone,
            'venueWebsite' => $venue_website,
            'venueCoordinates' => $venue_coordinates,
        ];
    }

    private function map_tribe_wp_event(array $event, array $config): array {
        $title = '';
        if (isset($event['title']['rendered'])) {
            $title = $event['title']['rendered'];
        } elseif (isset($event['title']) && is_string($event['title'])) {
            $title = $event['title'];
        }

        $description = '';
        if (isset($event['content']['rendered'])) {
            $description = $event['content']['rendered'];
        } elseif (isset($event['description'])) {
            $description = $event['description'];
        }

        $start_date = '';
        $start_time = '';
        $end_date = '';
        $end_time = '';

        $meta = $event['meta'] ?? [];
        if (!empty($meta['_EventStartDate'])) {
            $datetime = new \DateTime($meta['_EventStartDate']);
            $start_date = $datetime->format('Y-m-d');
            $start_time = $datetime->format('H:i');
        }
        if (!empty($meta['_EventEndDate'])) {
            $datetime = new \DateTime($meta['_EventEndDate']);
            $end_date = $datetime->format('Y-m-d');
            $end_time = $datetime->format('H:i');
        }

        $venue_name = '';
        $venue_address = '';
        $venue_city = '';
        $venue_state = '';
        $venue_zip = '';
        $venue_country = '';
        $venue_coordinates = '';

        if (!empty($meta['_EventVenueID'])) {
            $venue_name = $meta['_VenueName'] ?? '';
            $venue_address = $meta['_VenueAddress'] ?? '';
            $venue_city = $meta['_VenueCity'] ?? '';
            $venue_state = $meta['_VenueState'] ?? $meta['_VenueProvince'] ?? '';
            $venue_zip = $meta['_VenueZip'] ?? '';
            $venue_country = $meta['_VenueCountry'] ?? '';

            if (!empty($meta['_VenueLat']) && !empty($meta['_VenueLng'])) {
                $venue_coordinates = $meta['_VenueLat'] . ',' . $meta['_VenueLng'];
            }
        }

        if (!empty($config['venue_name_override'])) {
            $venue_name = $config['venue_name_override'];
        }

        $price = $meta['_EventCost'] ?? '';
        $ticket_url = $meta['_EventURL'] ?? $event['link'] ?? '';

        $image_url = '';
        if (isset($event['_embedded']['wp:featuredmedia'][0]['source_url'])) {
            $image_url = $event['_embedded']['wp:featuredmedia'][0]['source_url'];
        }

        return [
            'title' => $this->sanitizeText($title),
            'description' => $this->cleanHtml($description),
            'startDate' => $start_date,
            'endDate' => $end_date,
            'startTime' => $start_time,
            'endTime' => $end_time,
            'venue' => $this->sanitizeText($venue_name),
            'venueAddress' => $this->sanitizeText($venue_address),
            'venueCity' => $this->sanitizeText($venue_city),
            'venueState' => $this->sanitizeText($venue_state),
            'venueZip' => $this->sanitizeText($venue_zip),
            'venueCountry' => $this->sanitizeText($venue_country),
            'venuePhone' => '',
            'venueWebsite' => '',
            'venueCoordinates' => $this->sanitizeText($venue_coordinates),
            'price' => $this->sanitizeText($price),
            'ticketUrl' => $this->sanitizeUrl($ticket_url),
            'image' => $image_url,
        ];
    }

    private function map_generic_event(array $event, array $config): array {
        $title = '';
        if (isset($event['title']['rendered'])) {
            $title = $event['title']['rendered'];
        } elseif (isset($event['title']) && is_string($event['title'])) {
            $title = $event['title'];
        } elseif (isset($event['name'])) {
            $title = $event['name'];
        }

        $description = '';
        if (isset($event['content']['rendered'])) {
            $description = $event['content']['rendered'];
        } elseif (isset($event['description'])) {
            $description = $event['description'];
        } elseif (isset($event['body'])) {
            $description = $event['body'];
        }

        $start_date = '';
        $start_time = '';
        $end_date = '';
        $end_time = '';

        $date_fields = ['start_date', 'event_date', 'date', 'startDate'];
        foreach ($date_fields as $field) {
            if (!empty($event[$field])) {
                $datetime = new \DateTime($event[$field]);
                $start_date = $datetime->format('Y-m-d');
                $start_time = $datetime->format('H:i');
                break;
            }
        }

        $end_date_fields = ['end_date', 'endDate'];
        foreach ($end_date_fields as $field) {
            if (!empty($event[$field])) {
                $datetime = new \DateTime($event[$field]);
                $end_date = $datetime->format('Y-m-d');
                $end_time = $datetime->format('H:i');
                break;
            }
        }

        $venue_name = $event['venue'] ?? $event['venue_name'] ?? $event['location'] ?? '';
        if (!empty($config['venue_name_override'])) {
            $venue_name = $config['venue_name_override'];
        }

        $image_url = '';
        if (isset($event['_embedded']['wp:featuredmedia'][0]['source_url'])) {
            $image_url = $event['_embedded']['wp:featuredmedia'][0]['source_url'];
        } elseif (isset($event['image']['url'])) {
            $image_url = $event['image']['url'];
        } elseif (isset($event['image']) && is_string($event['image'])) {
            $image_url = $event['image'];
        }

        return [
            'title' => $this->sanitizeText($title),
            'description' => $this->cleanHtml($description),
            'startDate' => $start_date,
            'endDate' => $end_date,
            'startTime' => $start_time,
            'endTime' => $end_time,
            'venue' => $this->sanitizeText($venue_name),
            'venueAddress' => $this->sanitizeText($event['address'] ?? ''),
            'venueCity' => $this->sanitizeText($event['city'] ?? ''),
            'venueState' => $this->sanitizeText($event['state'] ?? ''),
            'venueZip' => $this->sanitizeText($event['zip'] ?? $event['postal_code'] ?? ''),
            'venueCountry' => $this->sanitizeText($event['country'] ?? ''),
            'venuePhone' => '',
            'venueWebsite' => '',
            'venueCoordinates' => '',
            'price' => $this->sanitizeText($event['cost'] ?? $event['price'] ?? ''),
            'ticketUrl' => $this->sanitizeUrl($event['website'] ?? $event['url'] ?? $event['link'] ?? ''),
            'image' => $image_url,
        ];
    }

    private function storeImageInEngine(?string $job_id, string $image_url): void {
        if (empty($job_id) || empty($image_url)) {
            return;
        }

        $job_id = (int) $job_id;
        if ($job_id <= 0 || !function_exists('datamachine_merge_engine_data')) {
            return;
        }

        datamachine_merge_engine_data($job_id, [
            'image_url' => $image_url
        ]);
    }
}
