<?php
/**
 * Prekindle Widget Import
 *
 * Imports events from the Prekindle organizer widget page derived from an org_id.
 * Combines JSON-LD event data with start times extracted from the HTML listing.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Prekindle
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Prekindle;

use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

class Prekindle extends EventImportHandler {

    use HandlerRegistrationTrait;

    public function __construct() {
        parent::__construct('prekindle');

        self::registerHandler(
            'prekindle',
            'event_import',
            self::class,
            __('Prekindle', 'datamachine-events'),
            __('Import events from a Prekindle org widget page (JSON-LD + listing times)', 'datamachine-events'),
            false,
            null,
            PrekindleSettings::class,
            null
        );
    }

    protected function executeFetch(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id): array {
        $this->log('info', 'Starting Prekindle event import', [
            'pipeline_id' => $pipeline_id,
            'job_id' => $job_id,
            'flow_step_id' => $flow_step_id,
        ]);

        $org_id = trim($config['org_id'] ?? '');
        if (empty($org_id)) {
            $this->log('error', 'Prekindle handler requires org_id configuration');
            return $this->emptyResponse() ?? [];
        }

        $widget_url = 'https://www.prekindle.com/organizer-grid-widget-main/id/' . urlencode($org_id) . '/?fp=false&thumbs=false&style=null';

        $result = $this->httpGet($widget_url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'text/html',
            ],
        ]);

        if (!$result['success']) {
            $this->log('error', 'Prekindle request failed: ' . ($result['error'] ?? 'Unknown error'));
            return $this->emptyResponse() ?? [];
        }

        if (($result['status_code'] ?? 0) !== 200) {
            $this->log('error', 'Prekindle returned non-200 status', ['status' => $result['status_code'] ?? null]);
            return $this->emptyResponse() ?? [];
        }

        $html = $result['data'] ?? '';
        if (empty($html)) {
            return $this->emptyResponse() ?? [];
        }

        $events = $this->extractJsonLdEvents($html);
        if (empty($events)) {
            $this->log('info', 'No events found in Prekindle JSON-LD');
            return $this->emptyResponse() ?? [];
        }

        $times_by_event_url = $this->extractEventTimesByUrl($html);

        foreach ($events as $raw_event) {
            $standardized_event = $this->mapPrekindleEvent($raw_event, $times_by_event_url, $config);

            if (empty($standardized_event['title'])) {
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
                        'import_source' => 'prekindle',
                    ], JSON_PRETTY_PRINT),
                ],
                [
                    'source_type' => 'prekindle',
                    'pipeline_id' => $pipeline_id,
                    'flow_id' => $flow_id,
                    'original_title' => $standardized_event['title'],
                    'event_identifier' => $event_identifier,
                    'import_timestamp' => time(),
                ],
                'event_import'
            );

            return $this->successResponse([$dataPacket]);
        }

        $this->log('info', 'No eligible Prekindle events found');
        return $this->emptyResponse() ?? [];
    }

    private function extractJsonLdEvents(string $html): array {
        if (!preg_match('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return [];
        }

        $data = json_decode($matches[1], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return $data['@graph'];
        }

        if (is_array($data) && isset($data[0])) {
            return $data;
        }

        return [];
    }

    private function extractEventTimesByUrl(string $html): array {
        $map = [];

        if (!preg_match_all('#<div[^>]+name=["\']pk-eachevent["\'][^>]*>#i', $html, $starts, PREG_OFFSET_CAPTURE)) {
            return $map;
        }

        $event_block_starts = $starts[0];
        $count = count($event_block_starts);

        for ($i = 0; $i < $count; $i++) {
            $start_offset = $event_block_starts[$i][1];
            $end_offset = ($i + 1 < $count) ? $event_block_starts[$i + 1][1] : strlen($html);

            $block_html = substr($html, $start_offset, $end_offset - $start_offset);

            if (!preg_match('#href=["\'](https?://[^"\']+/event/[^"\']+)["\']#i', $block_html, $url_match)) {
                continue;
            }

            $url = $url_match[1];

            if (!preg_match('#<div[^>]*class=["\']pk-times["\'][^>]*>\s*<div[^>]*>(.*?)</div>#is', $block_html, $time_match)) {
                continue;
            }

            $time_text = trim(wp_strip_all_tags($time_match[1]));
            if (empty($time_text)) {
                continue;
            }

            $map[$this->normalizeUrlKey($url)] = $time_text;
        }

        return $map;
    }

    private function mapPrekindleEvent(array $raw_event, array $times_by_event_url, array $config): array {
        $event_url = $this->sanitizeUrl((string)($raw_event['url'] ?? ''));

        $start_date = $this->sanitizeText((string)($raw_event['startDate'] ?? ''));
        $end_date = $this->sanitizeText((string)($raw_event['endDate'] ?? ''));

        $start_time = '';
        $time_text = '';
        if (!empty($event_url)) {
            $time_text = $times_by_event_url[$this->normalizeUrlKey($event_url)] ?? '';
            $start_time = $this->parseStartTime($time_text);
        }

        $location = $raw_event['location'] ?? [];
        $address = is_array($location) ? ($location['address'] ?? []) : [];

        $offers = $raw_event['offers'] ?? [];

        $performer_names = [];
        $performers = $raw_event['performer'] ?? [];
        if (is_array($performers)) {
            foreach ($performers as $performer) {
                if (is_array($performer) && !empty($performer['name'])) {
                    $performer_names[] = $this->sanitizeText((string)$performer['name']);
                } elseif (is_string($performer) && !empty($performer)) {
                    $performer_names[] = $this->sanitizeText($performer);
                }
            }
        }

        $organizer = $raw_event['organizer']['name'] ?? ($raw_event['organizer'] ?? '');
        if (is_array($organizer)) {
            $organizer = $organizer['name'] ?? '';
        }

        $standardized_event = [
            'title' => $this->sanitizeText((string)($raw_event['name'] ?? '')),
            'description' => $this->sanitizeText((string)($raw_event['description'] ?? '')),
            'startDate' => $start_date,
            'endDate' => $end_date,
            'startTime' => $start_time,
            'endTime' => '',
            'venue' => $this->sanitizeText((string)($location['name'] ?? '')),
            'venueAddress' => $this->sanitizeText((string)($address['streetAddress'] ?? '')),
            'venueCity' => $this->sanitizeText((string)($address['addressLocality'] ?? '')),
            'venueState' => $this->sanitizeText((string)($address['addressRegion'] ?? '')),
            'venueZip' => $this->sanitizeText((string)($address['postalCode'] ?? '')),
            'venueCountry' => $this->sanitizeText((string)($address['addressCountry'] ?? '')),
            'venuePhone' => '',
            'venueWebsite' => $this->sanitizeUrl((string)($raw_event['organizer']['url'] ?? '')),
            'ticketUrl' => $this->sanitizeUrl((string)($offers['url'] ?? $event_url)),
            'image' => $this->sanitizeUrl((string)($raw_event['image'] ?? '')),
            'price' => $this->sanitizeText((string)($offers['price'] ?? ($offers['lowPrice'] ?? ''))),
            'priceCurrency' => $this->sanitizeText((string)($offers['priceCurrency'] ?? '')),
            'offerAvailability' => $this->sanitizeText((string)($offers['availability'] ?? '')),
            'validFrom' => $this->sanitizeText((string)($offers['validFrom'] ?? '')),
            'performer' => trim(implode(', ', array_filter($performer_names))),
            'organizer' => $this->sanitizeText((string)$organizer),
            'organizerUrl' => $this->sanitizeUrl((string)($raw_event['organizer']['url'] ?? '')),
            'eventType' => $this->sanitizeText((string)($raw_event['@type'] ?? '')),
            'eventStatus' => $this->sanitizeText((string)($raw_event['eventStatus'] ?? '')),
            'source_url' => $event_url,
        ];

        if (!empty($config['venue_name'])) {
            $standardized_event['venue'] = $this->sanitizeText((string)$config['venue_name']);
        }
        if (!empty($config['venue_address'])) {
            $standardized_event['venueAddress'] = $this->sanitizeText((string)$config['venue_address']);
        }
        if (!empty($config['venue_city'])) {
            $standardized_event['venueCity'] = $this->sanitizeText((string)$config['venue_city']);
        }
        if (!empty($config['venue_state'])) {
            $standardized_event['venueState'] = $this->sanitizeText((string)$config['venue_state']);
        }
        if (!empty($config['venue_zip'])) {
            $standardized_event['venueZip'] = $this->sanitizeText((string)$config['venue_zip']);
        }
        if (!empty($config['venue_country'])) {
            $standardized_event['venueCountry'] = $this->sanitizeText((string)$config['venue_country']);
        }
        if (!empty($config['venue_phone'])) {
            $standardized_event['venuePhone'] = $this->sanitizeText((string)$config['venue_phone']);
        }
        if (!empty($config['venue_website'])) {
            $standardized_event['venueWebsite'] = $this->sanitizeUrl((string)$config['venue_website']);
        }

        return $standardized_event;
    }

    private function parseStartTime(string $time_text): string {
        if (empty($time_text)) {
            return '';
        }

        if (preg_match('/Start\s+(\d{1,2}:\d{2}\s*(?:am|pm))/i', $time_text, $m)) {
            $timestamp = strtotime($m[1]);
            return $timestamp ? date('H:i', $timestamp) : '';
        }

        if (preg_match('/(\d{1,2}:\d{2}\s*(?:am|pm))/i', $time_text, $m)) {
            $timestamp = strtotime($m[1]);
            return $timestamp ? date('H:i', $timestamp) : '';
        }

        return '';
    }

    private function normalizeUrlKey(string $url): string {
        $url = trim($url);
        $url = preg_replace('#^http://#i', 'https://', $url);
        return rtrim(strtolower($url), '/');
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
            'image_url' => $image_url,
        ]);
    }
}
