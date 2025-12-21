<?php
/**
 * GoDaddy Calendar JSON Handler
 *
 * Imports events from GoDaddy Website Builder calendar JSON endpoints.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\GoDaddyCalendar
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\GoDaddyCalendar;

use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

class GoDaddyCalendar extends EventImportHandler {

    use HandlerRegistrationTrait;

    public function __construct() {
        parent::__construct('godaddy_calendar');

        self::registerHandler(
            'godaddy_calendar',
            'event_import',
            self::class,
            __('GoDaddy Calendar', 'datamachine-events'),
            __('Import events from GoDaddy Website Builder calendar JSON endpoints', 'datamachine-events'),
            false,
            null,
            GoDaddyCalendarSettings::class,
            null
        );
    }

    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $events_url = trim($config['events_url'] ?? '');
        if (empty($events_url) || !filter_var($events_url, FILTER_VALIDATE_URL)) {
            $this->log('error', 'GoDaddy Calendar handler requires a valid events_url');
            return [];
        }

        $response = $this->httpGet($events_url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'browser_mode' => true,
        ]);

        if (!$response['success']) {
            $this->log('error', 'GoDaddy Calendar request failed', [
                'events_url' => $events_url,
                'error' => $response['error'] ?? 'Unknown error',
            ]);
            return [];
        }

        if (($response['status_code'] ?? 0) !== 200) {
            $this->log('error', 'GoDaddy Calendar returned non-200 status', [
                'events_url' => $events_url,
                'status_code' => $response['status_code'] ?? null,
            ]);
            return [];
        }

        $data = json_decode((string)($response['data'] ?? ''), true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', 'GoDaddy Calendar returned invalid JSON', [
                'events_url' => $events_url,
                'json_error' => json_last_error_msg(),
            ]);
            return [];
        }

        $events = $data['events'] ?? [];
        if (!is_array($events) || empty($events)) {
            return [];
        }

        foreach ($events as $raw_event) {
            if (!is_array($raw_event)) {
                continue;
            }

            $standardized_event = $this->map_event($raw_event, $config, $events_url);

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

            $dataPacket = new DataPacket(
                [
                    'title' => $standardized_event['title'],
                    'body' => wp_json_encode([
                        'event' => $standardized_event,
                        'venue_metadata' => $venue_metadata,
                        'import_source' => 'godaddy_calendar',
                        'source_url' => $events_url,
                    ], JSON_PRETTY_PRINT),
                ],
                [
                    'source_type' => 'godaddy_calendar',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_id,
                    'original_title' => $standardized_event['title'],
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time(),
                ],
                'event_import'
            );

            return [$dataPacket];
        }

        return [];
    }

    private function map_event(array $raw_event, array $config, string $events_url): array {
        $start = $this->parse_iso_datetime($raw_event['start'] ?? '');
        $end = $this->parse_iso_datetime($raw_event['end'] ?? '');

        $title = $this->sanitizeText((string)($raw_event['title'] ?? ''));
        $description = sanitize_textarea_field((string)($raw_event['desc'] ?? ''));

        $location = $this->sanitizeText((string)($raw_event['location'] ?? ''));

        $standardized_event = [
            'title' => $title,
            'description' => $description,
            'startDate' => $start['date'],
            'endDate' => $end['date'] ?: $start['date'],
            'startTime' => $start['time'],
            'endTime' => $end['time'],
            'venue' => $this->sanitizeText((string)($config['venue_name'] ?? '')),
            'venueAddress' => $this->sanitizeText((string)($config['venue_address'] ?? '')),
            'venueCity' => $this->sanitizeText((string)($config['venue_city'] ?? '')),
            'venueState' => $this->sanitizeText((string)($config['venue_state'] ?? '')),
            'venueZip' => $this->sanitizeText((string)($config['venue_zip'] ?? '')),
            'venueCountry' => $this->sanitizeText((string)($config['venue_country'] ?? '')),
            'venuePhone' => $this->sanitizeText((string)($config['venue_phone'] ?? '')),
            'venueWebsite' => $this->sanitizeUrl((string)($config['venue_website'] ?? '')),
            'ticketUrl' => '',
            'image' => '',
            'price' => '',
            'performer' => '',
            'organizer' => '',
            'source_url' => $events_url,
        ];

        if (empty($standardized_event['venue']) && !empty($location)) {
            $standardized_event['venue'] = $location;
        }

        $all_day = !empty($raw_event['allDay']);
        if ($all_day) {
            $standardized_event['startTime'] = '';
            $standardized_event['endTime'] = '';
        }

        return $standardized_event;
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
