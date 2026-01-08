<?php
/**
 * DoStuff Media API Event Import Handler
 *
 * Imports events from DoStuff Media JSON feeds (Waterloo Records, etc.).
 * Single-item processing with EventIdentifierGenerator for deduplication.
 * No authentication required - works with public JSON feeds.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\DoStuffMediaApi
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\DoStuffMediaApi;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

class DoStuffMediaApi extends EventImportHandler {

    use HandlerRegistrationTrait;

    public function __construct() {
        parent::__construct('dostuff_media_api');

        self::registerHandler(
            'dostuff_media_api',
            'event_import',
            self::class,
            __('DoStuff Media API', 'datamachine-events'),
            __('Import events from DoStuff Media JSON feeds (Waterloo Records, etc.)', 'datamachine-events'),
            false,
            null,
            DoStuffMediaApiSettings::class,
            null
        );
    }

    protected function executeFetch(array $config, ExecutionContext $context): array {
        $context->log('info', 'DoStuffMediaApi: Starting event import');

        $feed_url = trim($config['feed_url'] ?? '');

        if (empty($feed_url)) {
            $context->log('error', 'DoStuffMediaApi: Feed URL not configured');
            return [];
        }

        if (!filter_var($feed_url, FILTER_VALIDATE_URL)) {
            $context->log('error', 'DoStuffMediaApi: Invalid feed URL format', ['url' => $feed_url]);
            return [];
        }

        $raw_events = $this->fetch_events($feed_url, $context);
        if (empty($raw_events)) {
            $context->log('info', 'DoStuffMediaApi: No events found');
            return [];
        }

        $context->log('info', 'DoStuffMediaApi: Processing events', [
            'events_available' => count($raw_events)
        ]);

        foreach ($raw_events as $raw_event) {
            $standardized_event = $this->map_dostuff_event($raw_event);

            if (empty($standardized_event['title'])) {
                continue;
            }

            if ($this->shouldSkipEventTitle($standardized_event['title'])) {
                continue;
            }

            if (!empty($raw_event['past'])) {
                continue;
            }

            $search_text = $standardized_event['title'] . ' ' . ($standardized_event['description'] ?? '');

            if (!$this->applyKeywordSearch($search_text, $config['search'] ?? '')) {
                continue;
            }

            if ($this->applyExcludeKeywords($search_text, $config['exclude_keywords'] ?? '')) {
                continue;
            }

            $event_identifier = EventIdentifierGenerator::generate(
                $standardized_event['title'],
                $standardized_event['startDate'] ?? '',
                $standardized_event['venue'] ?? ''
            );

            if ($this->checkItemProcessed($context, $event_identifier)) {
                continue;
            }

            if ($this->isPastEvent($standardized_event['startDate'] ?? '')) {
                continue;
            }

            $this->markItemAsProcessed($context, $event_identifier);

            $context->log('info', 'DoStuffMediaApi: Found eligible event', [
                'title' => $standardized_event['title'],
                'date' => $standardized_event['startDate'],
                'venue' => $standardized_event['venue']
            ]);

            $venue_metadata = $this->extractVenueMetadata($standardized_event);
            $job_id = $context->getJobId();

            EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);

            $this->stripVenueMetadataFromEvent($standardized_event);

            $dataPacket = new DataPacket(
                [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'dostuff_media_api'
                    ], JSON_PRETTY_PRINT)
                ],
                [
                    'source_type' => 'dostuff_media_api',
                    'pipeline_id' => $context->getPipelineId(),
                    'flow_id' => $context->getFlowId(),
                    'original_title' => $standardized_event['title'],
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time()
                ],
                'event_import'
            );

            return [$dataPacket];
        }

        $context->log('info', 'DoStuffMediaApi: No eligible events found');
        return [];
    }

    /**
     * Fetch events from DoStuff Media JSON feed
     */
    private function fetch_events(string $feed_url, ExecutionContext $context): array {
        $result = $this->httpGet($feed_url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'browser_mode' => true,
        ]);

        if (!$result['success']) {
            $context->log('error', 'DoStuffMediaApi: Request failed', [
                'url' => $feed_url,
                'error' => $result['error'] ?? 'Unknown error',
            ]);
            return [];
        }

        $status_code = $result['status_code'];
        if ($status_code !== 200) {
            $context->log('error', 'DoStuffMediaApi: HTTP error', [
                'url' => $feed_url,
                'status_code' => $status_code,
            ]);
            return [];
        }

        $body = $result['data'];
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $context->log('error', 'DoStuffMediaApi: Invalid JSON response', [
                'url' => $feed_url,
                'json_error' => json_last_error_msg()
            ]);
            return [];
        }

        $events = [];
        if (!empty($data['event_groups']) && is_array($data['event_groups'])) {
            foreach ($data['event_groups'] as $group) {
                if (!empty($group['events']) && is_array($group['events'])) {
                    foreach ($group['events'] as $event) {
                        $events[] = $event;
                    }
                }
            }
        }

        $context->log('info', 'DoStuffMediaApi: Fetched events', [
            'total_events' => count($events),
            'feed_url' => $feed_url
        ]);

        return $events;
    }

    /**
     * Map DoStuff Media event to standardized event format
     */
    private function map_dostuff_event(array $event): array {
        $standardized_event = [
            'title' => sanitize_text_field($event['title'] ?? ''),
            'description' => $this->clean_description($event['description'] ?? ''),
            'startDate' => '',
            'endDate' => '',
            'startTime' => '',
            'endTime' => '',
            'venue' => '',
            'venueAddress' => '',
            'venueCity' => '',
            'venueState' => '',
            'venueZip' => '',
            'venueCountry' => 'US',
            'venueCoordinates' => '',
            'ticketUrl' => esc_url_raw($event['buy_url'] ?? ''),
            'image' => '',
            'price' => '',
            'artists' => [],
            'category' => sanitize_text_field($event['category'] ?? ''),
            'source_url' => ''
        ];

        // Parse start datetime
        if (!empty($event['begin_time'])) {
            $start_datetime = strtotime($event['begin_time']);
            if ($start_datetime) {
                $standardized_event['startDate'] = date('Y-m-d', $start_datetime);
                $standardized_event['startTime'] = date('H:i', $start_datetime);
            }
        }

        // Parse end datetime
        if (!empty($event['end_time'])) {
            $end_datetime = strtotime($event['end_time']);
            if ($end_datetime) {
                $standardized_event['endDate'] = date('Y-m-d', $end_datetime);
                $standardized_event['endTime'] = date('H:i', $end_datetime);
            }
        }

        // Extract venue data
        if (!empty($event['venue']) && is_array($event['venue'])) {
            $venue = $event['venue'];
            $standardized_event['venue'] = sanitize_text_field($venue['title'] ?? '');
            $standardized_event['venueAddress'] = sanitize_text_field($venue['address'] ?? '');
            $standardized_event['venueCity'] = sanitize_text_field($venue['city'] ?? '');
            $standardized_event['venueState'] = sanitize_text_field($venue['state'] ?? '');
            $standardized_event['venueZip'] = sanitize_text_field($venue['zip'] ?? '');

            if (!empty($venue['latitude']) && !empty($venue['longitude'])) {
                $standardized_event['venueCoordinates'] = $venue['latitude'] . ',' . $venue['longitude'];
            }
        }

        // Extract best image
        if (!empty($event['imagery']['aws']['cover_image_h_630_w_1200'])) {
            $standardized_event['image'] = esc_url_raw($event['imagery']['aws']['cover_image_h_630_w_1200']);
        } elseif (!empty($event['imagery']['aws']['cover_image_w_1200_h_450'])) {
            $standardized_event['image'] = esc_url_raw($event['imagery']['aws']['cover_image_w_1200_h_450']);
        } elseif (!empty($event['imagery']['aws']['poster_w_800'])) {
            $standardized_event['image'] = esc_url_raw($event['imagery']['aws']['poster_w_800']);
        }

        // Handle pricing
        if (!empty($event['is_free'])) {
            $standardized_event['price'] = 'Free';
        }

        // Extract artists array
        if (!empty($event['artists']) && is_array($event['artists'])) {
            $standardized_event['artists'] = array_map(function($artist) {
                return [
                    'title' => sanitize_text_field($artist['title'] ?? ''),
                    'description' => sanitize_textarea_field($artist['description'] ?? ''),
                    'hometown' => sanitize_text_field($artist['hometown'] ?? ''),
                    'spotify_id' => sanitize_text_field($artist['spotify_id'] ?? ''),
                    'youtube_id' => sanitize_text_field($artist['youtube_id'] ?? '')
                ];
            }, $event['artists']);
        }

        // Build source URL from permalink if available
        if (!empty($event['permalink'])) {
            $standardized_event['source_url'] = 'https://do512.com' . $event['permalink'];
        }

        return $standardized_event;
    }

    /**
     * Clean HTML description from DoStuff Media API
     */
    private function clean_description(string $description): string {
        $description = wp_kses_post($description);
        $description = preg_replace('/<!--.*?-->/s', '', $description);
        $description = preg_replace('/\s+/', ' ', $description);
        return trim($description);
    }
}
