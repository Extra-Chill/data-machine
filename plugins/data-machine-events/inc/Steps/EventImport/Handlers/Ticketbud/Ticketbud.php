<?php
/**
 * Ticketbud Event Import Handler
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketbud
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketbud;

use DataMachine\Core\DataPacket;
use DataMachine\Core\HttpClient;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Utilities\EventIdentifierGenerator;

if (!defined('ABSPATH')) {
    exit;
}

class Ticketbud extends EventImportHandler {

    use HandlerRegistrationTrait;

    private const EVENTS_URL = 'https://api.ticketbud.com/events.json';

    public function __construct() {
        parent::__construct('ticketbud');

        self::registerHandler(
            'ticketbud',
            'event_import',
            self::class,
            __('Ticketbud Events', 'datamachine-events'),
            __('Import events from Ticketbud API with venue data', 'datamachine-events'),
            true,
            TicketbudAuth::class,
            TicketbudSettings::class,
            null
        );
    }

    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $auth = $this->getAuthProvider('ticketbud');
        if (!$auth) {
            $this->log('error', 'Ticketbud authentication provider not found');
            return [];
        }

        $account = $auth->get_account();
        $access_token = $account['access_token'] ?? '';
        if ($access_token === '') {
            $this->log('error', 'Ticketbud access token not configured');
            return [];
        }

        $raw_events = $this->fetch_events($access_token);
        if (empty($raw_events)) {
            $this->log('info', 'No events found from Ticketbud API');
            return [];
        }

        foreach ($raw_events as $raw_event) {
            $standardized_event = $this->map_ticketbud_event($raw_event);

            if (empty($standardized_event['title'])) {
                continue;
            }

            if ($this->shouldSkipEventTitle($standardized_event['title'])) {
                continue;
            }

            $include_over = !empty($config['include_over']);
            if (!$include_over && !empty($raw_event['over'])) {
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

            if ($this->isItemProcessed($event_identifier, $flow_step_id)) {
                continue;
            }

            if ($this->isPastEvent($standardized_event['startDate'] ?? '')) {
                continue;
            }

            $this->markItemProcessed($event_identifier, $flow_step_id, $job_id);

            $venue_metadata = $this->extractVenueMetadata($standardized_event);
            EventEngineData::storeVenueContext($job_id, $standardized_event, $venue_metadata);
            $this->stripVenueMetadataFromEvent($standardized_event);

            return [new DataPacket(
                [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'ticketbud',
                    ], JSON_PRETTY_PRINT),
                ],
                [
                    'source_type' => 'ticketbud',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_id,
                    'original_title' => $standardized_event['title'] ?? '',
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time(),
                ],
                'event_import'
            )];
        }

        return [];
    }

    private function fetch_events(string $access_token): array {
        $url = add_query_arg(['access_token' => $access_token], self::EVENTS_URL);

        $result = HttpClient::get($url, [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'context' => 'Ticketbud Events',
        ]);

        if (!$result['success']) {
            $this->log('error', 'Ticketbud request failed', [
                'error' => $result['error'] ?? 'Unknown error',
            ]);
            return [];
        }

        $data = json_decode($result['data'] ?? '', true);
        if (!is_array($data)) {
            $this->log('error', 'Ticketbud returned invalid JSON');
            return [];
        }

        $events = $data['events'] ?? [];
        return is_array($events) ? $events : [];
    }

    private function map_ticketbud_event(array $raw_event): array {
        $title = $this->sanitizeText((string) ($raw_event['title'] ?? ''));

        $start = $this->parse_iso_datetime((string) ($raw_event['event_start'] ?? ''));
        $end = $this->parse_iso_datetime((string) ($raw_event['event_end'] ?? ''));

        $location = is_array($raw_event['event_location'] ?? null) ? $raw_event['event_location'] : [];

        $venue_coordinates = '';
        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;
        if ($latitude !== null && $longitude !== null && is_numeric($latitude) && is_numeric($longitude)) {
            $venue_coordinates = trim((string) $latitude) . ',' . trim((string) $longitude);
        }

        return [
            'title' => $title,
            'description' => $this->cleanHtml((string) ($raw_event['description'] ?? '')),
            'startDate' => $start['date'],
            'endDate' => $end['date'],
            'startTime' => $start['time'],
            'endTime' => $end['time'],
            'venue' => $this->sanitizeText((string) ($location['name'] ?? $location['location'] ?? '')),
            'ticketUrl' => $this->sanitizeUrl((string) ($raw_event['url'] ?? '')),
            'imageUrl' => $this->sanitizeUrl((string) ($raw_event['image'] ?? '')),
            'venueAddress' => $this->sanitizeText((string) ($location['address'] ?? '')),
            'venueCity' => $this->sanitizeText((string) ($location['city'] ?? '')),
            'venueState' => $this->sanitizeText((string) ($location['state'] ?? '')),
            'venueZip' => $this->sanitizeText((string) ($location['zip'] ?? '')),
            'venueCountry' => $this->sanitizeText((string) ($location['country'] ?? '')),
            'venuePhone' => $this->sanitizeText((string) ($location['phone'] ?? '')),
            'venueWebsite' => $this->sanitizeUrl((string) ($location['website'] ?? '')),
            'venueCoordinates' => $this->sanitizeText($venue_coordinates),
        ];
    }

    /**
     * @return array{date: string, time: string}
     */
    private function parse_iso_datetime(string $datetime): array {
        $datetime = trim($datetime);
        if ($datetime === '') {
            return ['date' => '', 'time' => ''];
        }

        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            return ['date' => '', 'time' => ''];
        }

        $date = date('Y-m-d', $timestamp);
        $time = date('H:i', $timestamp);

        if (!str_contains($datetime, 'T')) {
            $time = '';
        }

        return ['date' => $date, 'time' => $time];
    }
}
