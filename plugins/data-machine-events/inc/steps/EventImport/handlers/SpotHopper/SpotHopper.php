<?php
/**
 * SpotHopper API Integration
 *
 * Imports events from SpotHopper's public JSON API. No authentication required.
 * Single-item processing pattern with EventIdentifierGenerator for deduplication.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\SpotHopper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\SpotHopper;

use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

class SpotHopper extends EventImportHandler {

    use HandlerRegistrationTrait;

    const API_BASE = 'https://www.spothopperapp.com/api/spots/';

    public function __construct() {
        parent::__construct('spothopper');

        self::registerHandler(
            'spothopper',
            'event_import',
            self::class,
            __('SpotHopper Events', 'datamachine-events'),
            __('Import events from SpotHopper venue platform (no API key required)', 'datamachine-events'),
            false,
            null,
            SpotHopperSettings::class,
            null
        );
    }

    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $this->log('info', 'Starting SpotHopper event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'flow_step_id' => $flow_step_id
        ]);

        $spot_id = $config['spot_id'] ?? '';
        if (empty($spot_id)) {
            $this->log('error', 'SpotHopper handler requires spot_id configuration');
            return $this->emptyResponse() ?? [];
        }

        $api_response = $this->fetch_events($spot_id);
        if (empty($api_response)) {
            $this->log('info', 'No events returned from SpotHopper API');
            return $this->emptyResponse() ?? [];
        }

        $raw_events = $api_response['events'] ?? [];
        $linked = $api_response['linked'] ?? [];

        if (empty($raw_events)) {
            $this->log('info', 'No events found in SpotHopper response');
            return $this->emptyResponse() ?? [];
        }

        $this->log('info', 'Processing SpotHopper events', [
            'raw_events_available' => count($raw_events),
            'pipeline_id' => $pipeline_id
        ]);

        foreach ($raw_events as $raw_event) {
            $standardized_event = $this->map_spothopper_event($raw_event, $linked, $config);

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

            $this->log('info', 'Found eligible SpotHopper event', [
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
                        'import_source' => 'spothopper'
                    ], JSON_PRETTY_PRINT)
                ],
                [
                    'source_type' => 'spothopper',
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

        $this->log('info', 'No eligible SpotHopper events found');
        return $this->emptyResponse() ?? [];
    }

    private function fetch_events(string $spot_id): array {
        $url = self::API_BASE . urlencode($spot_id) . '/events';

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Data Machine Events WordPress Plugin'
            ]
        ]);

        if (is_wp_error($response)) {
            $this->log('error', 'SpotHopper API request failed: ' . $response->get_error_message());
            return [];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $this->log('error', 'SpotHopper API returned non-200 status', ['status' => $status_code]);
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', 'SpotHopper API returned invalid JSON');
            return [];
        }

        return $data;
    }

    private function map_spothopper_event(array $event, array $linked, array $config): array {
        $title = $event['name'] ?? '';
        $description = $event['text'] ?? '';

        $start_date = '';
        $start_time = '';
        $end_time = '';

        if (!empty($event['event_date'])) {
            $date_obj = new \DateTime($event['event_date']);
            $start_date = $date_obj->format('Y-m-d');
        }

        if (!empty($event['start_time'])) {
            $start_time = $event['start_time'];

            if (!empty($event['duration_minutes']) && is_numeric($event['duration_minutes'])) {
                $start_datetime = new \DateTime($start_date . ' ' . $start_time);
                $start_datetime->modify('+' . (int)$event['duration_minutes'] . ' minutes');
                $end_time = $start_datetime->format('H:i');
            }
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

        $spots = $linked['spots'] ?? [];
        if (!empty($spots[0])) {
            $spot = $spots[0];
            $venue_name = $spot['name'] ?? '';
            $venue_address = $spot['address'] ?? '';
            $venue_city = $spot['city'] ?? '';
            $venue_state = $spot['state'] ?? '';
            $venue_zip = $spot['zip'] ?? '';
            $venue_country = $spot['country'] ?? 'US';
            $venue_phone = $spot['phone_number'] ?? '';
            $venue_website = $spot['website_url'] ?? '';

            if (!empty($spot['latitude']) && !empty($spot['longitude'])) {
                $venue_coordinates = $spot['latitude'] . ',' . $spot['longitude'];
            }
        }

        if (!empty($config['venue_name_override'])) {
            $venue_name = $config['venue_name_override'];
        }

        $image_url = $this->resolve_image_url($event, $linked);

        return [
            'title' => $this->sanitizeText($title),
            'description' => $this->cleanHtml($description),
            'startDate' => $start_date,
            'endDate' => '',
            'startTime' => $start_time,
            'endTime' => $end_time,
            'venue' => $this->sanitizeText($venue_name),
            'venueAddress' => $this->sanitizeText($venue_address),
            'venueCity' => $this->sanitizeText($venue_city),
            'venueState' => $this->sanitizeText($venue_state),
            'venueZip' => $this->sanitizeText($venue_zip),
            'venueCountry' => $this->sanitizeText($venue_country),
            'venuePhone' => $this->sanitizeText($venue_phone),
            'venueWebsite' => $this->sanitizeUrl($venue_website),
            'venueCoordinates' => $this->sanitizeText($venue_coordinates),
            'image' => $image_url,
            'price' => '',
            'ticketUrl' => ''
        ];
    }

    private function resolve_image_url(array $event, array $linked): string {
        $image_ids = $event['links']['images'] ?? [];
        if (empty($image_ids)) {
            return '';
        }

        $linked_images = $linked['images'] ?? [];
        if (empty($linked_images)) {
            return '';
        }

        $target_id = $image_ids[0];

        foreach ($linked_images as $image) {
            if (($image['id'] ?? null) == $target_id) {
                return $image['urls']['full'] ?? ($image['urls']['large'] ?? ($image['url'] ?? ''));
            }
        }

        return '';
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
