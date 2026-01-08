<?php
/**
 * Event Flyer Handler
 *
 * Extracts event data from flyer/poster images using vision model capabilities.
 * Uses the Files handler patterns for image access and builds dynamic tool
 * parameters based on which fields are pre-filled vs AI-extracted.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\EventFlyer
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\EventFlyer;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Steps\EventImport\Handlers\VenueFieldsTrait;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

if (!defined('ABSPATH')) {
    exit;
}

class EventFlyer extends EventImportHandler {

    use HandlerRegistrationTrait;
    use VenueFieldsTrait;

    public function __construct() {
        parent::__construct('event_flyer');

        self::registerHandler(
            'event_flyer',
            'event_import',
            self::class,
            __('Event Flyer', 'datamachine-events'),
            __('Extract event data from flyer/poster images using AI vision', 'datamachine-events'),
            false,
            null,
            EventFlyerSettings::class,
            null
        );
    }

    protected function executeFetch(array $config, ExecutionContext $context): array {
        $context->log('info', 'EventFlyer: Starting import');

        $image_file = $this->getNextUnprocessedImage($context);

        if (!$image_file) {
            $context->log('info', 'EventFlyer: No unprocessed flyer images available');
            return [];
        }

        if (!file_exists($image_file['persistent_path'])) {
            $context->log('error', 'EventFlyer: Image file not found', ['path' => $image_file['persistent_path']]);
            return [];
        }

        $context->log('info', 'EventFlyer: Processing flyer image', [
            'file' => $image_file['original_name'],
            'path' => $image_file['persistent_path']
        ]);

        $file_identifier = $image_file['persistent_path'];
        $this->markItemAsProcessed($context, $file_identifier);

        $ai_fields = $this->buildAIExtractionFields($config);

        $event_data = $this->mergeConfigWithDefaults($config);

        if (!empty($event_data['title']) && $this->shouldSkipEventTitle($event_data['title'])) {
            return [];
        }

        $job_id = $context->getJobId();
        $this->storeImageInEngine($job_id, $image_file['persistent_path']);

        if (empty($event_data['title']) || empty($event_data['startDate'])) {
            $context->log('warning', 'EventFlyer: Requires AI processing for missing fields', [
                'ai_fields_needed' => array_keys($ai_fields)
            ]);
        }

        $event_identifier = EventIdentifierGenerator::generate(
            $event_data['title'] ?: 'pending-extraction',
            $event_data['startDate'] ?: '',
            $event_data['venue'] ?: ''
        );

        $venue_metadata = $this->extractVenueMetadata($event_data);

        EventEngineData::storeVenueContext($job_id, $event_data, $venue_metadata);

        $this->stripVenueMetadataFromEvent($event_data);

        $dataPacket = new DataPacket(
            [
                'title' => $event_data['title'] ?: $image_file['original_name'],
                'body' => wp_json_encode([
                    'event' => $event_data,
                    'venue_metadata' => $venue_metadata,
                    'import_source' => 'event_flyer',
                    'source_file' => $image_file['original_name'],
                    'ai_extraction_fields' => $ai_fields,
                ], JSON_PRETTY_PRINT)
            ],
            [
                'source_type' => 'event_flyer',
                'pipeline_id' => $context->getPipelineId(),
                'flow_id' => $context->getFlowId(),
                'original_title' => $event_data['title'] ?: $image_file['original_name'],
                'event_identifier' => $event_identifier,
                'import_timestamp' => time(),
                'image_file_path' => $image_file['persistent_path'],
            ],
            'event_import'
        );

        return [$dataPacket];
    }

    private function getNextUnprocessedImage(ExecutionContext $context): ?array {
        $storage = $this->getFileStorage();
        $storage_context = $context->getFileContext();

        $repo_files = $storage->get_all_files($storage_context);

        if (empty($repo_files)) {
            return null;
        }

        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        foreach ($repo_files as $file) {
            $extension = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));

            if (!in_array($extension, $image_extensions, true)) {
                continue;
            }

            $file_identifier = $file['path'];

            if ($this->checkItemProcessed($context, $file_identifier)) {
                continue;
            }

            return [
                'original_name' => $file['filename'],
                'persistent_path' => $file['path'],
                'size' => $file['size'],
                'mime_type' => $this->getMimeType($file['path']),
                'uploaded_at' => gmdate('Y-m-d H:i:s', $file['modified'])
            ];
        }

        return null;
    }

    private function getMimeType(string $file_path): string {
        $file_info = wp_check_filetype($file_path);
        return $file_info['type'] ?? 'application/octet-stream';
    }

    /**
     * Build AI extraction fields for parameters the AI should extract.
     *
     * AI extraction fields use camelCase (venueAddress) for tool parameter format.
     * Config uses snake_case (venue_address) for form fields.
     * This method maps between them when checking if a field is pre-filled.
     */
    private function buildAIExtractionFields(array $config): array {
        $all_fields = EventFlyerSettings::get_ai_extraction_fields();
        $ai_fields = [];

        $camel_to_snake_map = [
            'venue' => 'venue_name',
            'venueAddress' => 'venue_address',
            'venueCity' => 'venue_city',
            'venueState' => 'venue_state',
            'venueZip' => 'venue_zip',
            'venueCountry' => 'venue_country',
            'venuePhone' => 'venue_phone',
            'venueWebsite' => 'venue_website',
        ];

        foreach ($all_fields as $field => $description) {
            $config_key = $camel_to_snake_map[$field] ?? $field;

            if (empty($config[$config_key])) {
                $ai_fields[$field] = [
                    'type' => 'string',
                    'description' => $description,
                ];
            }
        }

        return $ai_fields;
    }

    /**
     * Merge config with defaults and convert venue fields to camelCase event data format.
     *
     * Handler config uses snake_case (venue_address), but event data uses camelCase (venueAddress).
     */
    private function mergeConfigWithDefaults(array $config): array {
        $defaults = EventFlyerSettings::get_defaults();

        $event_data = [];
        foreach ($defaults as $field => $default) {
            $event_data[$field] = !empty($config[$field]) ? $config[$field] : $default;
        }

        $venue_event_data = self::map_venue_config_to_event_data($config);
        $event_data = array_merge($event_data, $venue_event_data);

        return $event_data;
    }

    private function storeImageInEngine(?string $job_id, string $image_path): void {
        if (empty($job_id) || empty($image_path)) {
            return;
        }

        $job_id = (int) $job_id;
        if ($job_id <= 0 || !function_exists('datamachine_merge_engine_data')) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $image_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $image_path);

        datamachine_merge_engine_data($job_id, [
            'image_file_path' => $image_path,
            'image_url' => $image_url,
        ]);
    }

    public static function get_vision_prompt(): string {
        return "Extract event information from this promotional flyer, poster, or event graphic.

Look for and extract:
- Event title or headliner (usually the largest, most prominent text)
- Date and time information (parse into standard formats)
- Venue name and address
- Ticket prices (advance, door, VIP tiers if shown)
- Supporting acts, opening bands, or additional performers
- Ticket purchase URLs if visible
- Any age restrictions (21+, All Ages, etc.)

Format guidelines:
- Dates should be in YYYY-MM-DD format
- Times should be in HH:MM 24-hour format
- If information is not clearly visible, leave the field empty
- Do not guess or infer information that is not present on the flyer";
    }
}
