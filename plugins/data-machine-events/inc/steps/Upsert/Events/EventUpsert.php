<?php
/**
 * Event Upsert Handler
 *
 * Intelligently creates or updates event posts based on event identity.
 * Searches for existing events by (title, venue, startDate) and updates if found,
 * creates if new, or skips if data unchanged.
 *
 * Replaces Publisher with smarter create/update logic and change detection.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 * @since   0.2.0
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachine\Core\EngineData;
use DataMachineEvents\Steps\Upsert\Events\Venue;
use DataMachineEvents\Steps\Upsert\Events\Schema;
use DataMachine\Core\Steps\Update\Handlers\UpdateHandler;
use DataMachine\Core\WordPress\TaxonomyHandler;
use DataMachine\Core\WordPress\WordPressSettingsResolver;

if (!defined('ABSPATH')) {
    exit;
}

class EventUpsert extends UpdateHandler {
    protected $taxonomy_handler;

    public function __construct() {
        $this->taxonomy_handler = new TaxonomyHandler();
        // Register custom handler for venue taxonomy
        TaxonomyHandler::addCustomHandler('venue', [$this, 'assignVenueTaxonomy']);
    }

    /**
     * Execute event upsert (create or update)
     *
     * @param array $parameters Event data from AI tool call
     * @param array $handler_config Handler configuration
     * @return array Tool call result with action: created|updated|no_change
     */
    protected function executeUpdate(array $parameters, array $handler_config): array {
        if (empty($parameters['title'])) {
            return $this->errorResponse('title parameter is required for event upsert', [
                'provided_parameters' => array_keys($parameters)
            ]);
        }

        $job_id = (int) ($parameters['job_id'] ?? 0);
        $engine = $parameters['engine'] ?? null;
        if (!$engine instanceof EngineData) {
            $engine_snapshot = $job_id ? $this->getEngineData($job_id) : [];
            $engine = new EngineData($engine_snapshot, $job_id);
        }

        $engine_parameters = $this->extract_event_engine_parameters($engine);

        // Extract event identity fields
        $title = sanitize_text_field($parameters['title']);
        $venue = $parameters['venue'] ?? '';
        $startDate = $parameters['startDate'] ?? '';

        $this->log('debug', 'Event Upsert: Processing event', [
            'title' => $title,
            'venue' => $venue,
            'startDate' => $startDate
        ]);

        // Search for existing event
        $existing_post_id = $this->findExistingEvent($title, $venue, $startDate);

        if ($existing_post_id) {
            // Event exists - check if data changed
            $existing_data = $this->extractEventData($existing_post_id);

            if ($this->hasDataChanged($existing_data, $parameters)) {
                // UPDATE existing event
                $this->updateEventPost($existing_post_id, $parameters, $handler_config, $engine, $engine_parameters);

                $this->log('info', 'Event Upsert: Updated existing event', [
                    'post_id' => $existing_post_id,
                    'title' => $title
                ]);

                return $this->successResponse([
                    'post_id' => $existing_post_id,
                    'post_url' => get_permalink($existing_post_id),
                    'action' => 'updated'
                ]);
            } else {
                // SKIP - no changes detected
                $this->log('debug', 'Event Upsert: Skipped event (no changes)', [
                    'post_id' => $existing_post_id,
                    'title' => $title
                ]);

                return $this->successResponse([
                    'post_id' => $existing_post_id,
                    'post_url' => get_permalink($existing_post_id),
                    'action' => 'no_change'
                ]);
            }
        } else {
            // CREATE new event
            $post_id = $this->createEventPost($parameters, $handler_config, $engine, $engine_parameters);

            if (is_wp_error($post_id) || !$post_id) {
                return $this->errorResponse('Event post creation failed', [
                    'title' => $title
                ]);
            }

            $this->log('info', 'Event Upsert: Created new event', [
                'post_id' => $post_id,
                'title' => $title
            ]);

            return $this->successResponse([
                'post_id' => $post_id,
                'post_url' => get_permalink($post_id),
                'action' => 'created'
            ]);
        }
    }

    /**
     * Find existing event by title, venue, and start date
     *
     * @param string $title Event title
     * @param string $venue Venue name
     * @param string $startDate Start date (YYYY-MM-DD)
     * @return int|null Post ID if found, null otherwise
     */
    private function findExistingEvent(string $title, string $venue, string $startDate): ?int {
        global $wpdb;

        // Query by exact title match
        $args = [
            'post_type' => 'datamachine_events',
            'post_title' => $title,
            'posts_per_page' => 1,
            'post_status' => ['publish', 'draft', 'pending'],
            'fields' => 'ids'
        ];

        // Add date filter if provided
        if (!empty($startDate)) {
            $args['meta_query'] = [
                [
                    'key' => '_datamachine_event_datetime',
                    'value' => $startDate,
                    'compare' => 'LIKE'
                ]
            ];
        }

        $posts = get_posts($args);

        if (!empty($posts)) {
            // If we have a venue, verify it matches
            if (!empty($venue)) {
                $post_id = $posts[0];
                $venue_terms = wp_get_post_terms($post_id, 'venue', ['fields' => 'names']);

                if (!empty($venue_terms) && in_array($venue, $venue_terms, true)) {
                    return $post_id;
                } elseif (empty($venue_terms)) {
                    // Post has no venue assigned, but title and date match
                    return $post_id;
                }
            } else {
                // No venue specified, return first match
                return $posts[0];
            }
        }

        return null;
    }

    /**
     * Extract event data from existing post
     *
     * @param int $post_id Post ID
     * @return array Event attributes from event-details block
     */
    private function extractEventData(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $blocks = parse_blocks($post->post_content);

        foreach ($blocks as $block) {
            if ($block['blockName'] === 'datamachine-events/event-details') {
                return $block['attrs'] ?? [];
            }
        }

        return [];
    }

    /**
     * Compare existing and incoming event data
     *
     * @param array $existing Existing event attributes
     * @param array $incoming Incoming event parameters
     * @return bool True if data changed, false if identical
     */
    private function hasDataChanged(array $existing, array $incoming): bool {
        // Fields to compare
        $compare_fields = [
            'startDate', 'endDate', 'startTime', 'endTime',
            'venue', 'address', 'price', 'ticketUrl',
            'performer', 'performerType', 'organizer', 'organizerType',
            'organizerUrl', 'eventStatus', 'previousStartDate',
            'priceCurrency', 'offerAvailability'
        ];

        foreach ($compare_fields as $field) {
            $existing_value = trim((string)($existing[$field] ?? ''));
            $incoming_value = trim((string)($incoming[$field] ?? ''));

            if ($existing_value !== $incoming_value) {
                $this->log('debug', "Event Upsert: Field changed: {$field}", [
                    'existing' => $existing_value,
                    'incoming' => $incoming_value
                ]);
                return true;
            }
        }

        // Check description (may be in inner blocks)
        $existing_description = trim((string)($existing['description'] ?? ''));
        $incoming_description = trim((string)($incoming['description'] ?? ''));

        if ($existing_description !== $incoming_description) {
            $this->log('debug', 'Event Upsert: Description changed');
            return true;
        }

        return false; // No changes detected
    }

    /**
     * Create new event post
     *
     * @param array $parameters Event parameters
     * @param array $handler_config Handler configuration
     * @param EngineData $engine Engine snapshot helper
     * @param array $engine_parameters Extracted engine parameters
     * @return int|WP_Error Post ID on success
     */
    private function createEventPost(array $parameters, array $handler_config, EngineData $engine, array $engine_parameters): int|\WP_Error {
        $job_id = (int) ($parameters['job_id'] ?? 0);
        $post_status = WordPressSettingsResolver::getPostStatus($handler_config);
        $post_author = WordPressSettingsResolver::getPostAuthor($handler_config);

        $routing = Schema::engine_or_tool($parameters, $handler_config, $engine_parameters);

        $event_data = [
            'title' => sanitize_text_field($parameters['title']),
            'description' => $parameters['description'] ?? ''
        ];

        foreach ($routing['engine'] as $field => $value) {
            $event_data[$field] = $value;
        }

        $ai_schema_fields = ['startDate', 'endDate', 'startTime', 'endTime', 'performer',
                           'performerType', 'organizer', 'organizerType', 'organizerUrl',
                           'eventStatus', 'previousStartDate', 'price',
                           'priceCurrency', 'ticketUrl', 'offerAvailability'];

        foreach ($ai_schema_fields as $field) {
            if (!isset($event_data[$field]) && !empty($parameters[$field])) {
                $event_data[$field] = sanitize_text_field($parameters[$field]);
            }
        }

        if (!empty($handler_config['venue'])) {
            $event_data['venue'] = $handler_config['venue'];
        }

        $post_data = [
            'post_type' => 'datamachine_events',
            'post_title' => $event_data['title'],
            'post_status' => $post_status,
            'post_author' => $post_author,
            'post_content' => $this->generate_event_block_content($event_data, $parameters)
        ];

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id) || !$post_id) {
            return $post_id;
        }

        // Process featured image
        $this->processEventFeaturedImage($post_id, $handler_config, $engine);

        // Process venue
        $this->processVenue($post_id, $parameters);

        // Process taxonomies
        $handler_config_for_tax = $handler_config;
        $handler_config_for_tax['taxonomy_venue_selection'] = 'skip';
        $engine_data_array = $engine instanceof EngineData ? $engine->all() : [];
        $this->taxonomy_handler->processTaxonomies($post_id, $parameters, $handler_config_for_tax, $engine_data_array);

        // Store event_id in engine data
        if ($job_id) {
            datamachine_merge_engine_data($job_id, [
                'event_id' => $post_id,
                'event_url' => get_permalink($post_id)
            ]);
        }

        return $post_id;
    }

    /**
     * Update existing event post
     *
     * @param int $post_id Existing post ID
     * @param array $parameters Event parameters
     * @param array $handler_config Handler configuration
     * @param EngineData $engine Engine snapshot helper
     * @param array $engine_parameters Extracted engine parameters
     */
    private function updateEventPost(int $post_id, array $parameters, array $handler_config, EngineData $engine, array $engine_parameters): void {
        $routing = Schema::engine_or_tool($parameters, $handler_config, $engine_parameters);

        $event_data = [
            'title' => sanitize_text_field($parameters['title']),
            'description' => $parameters['description'] ?? ''
        ];

        foreach ($routing['engine'] as $field => $value) {
            $event_data[$field] = $value;
        }

        $ai_schema_fields = ['startDate', 'endDate', 'startTime', 'endTime', 'performer',
                           'performerType', 'organizer', 'organizerType', 'organizerUrl',
                           'eventStatus', 'previousStartDate', 'price',
                           'priceCurrency', 'ticketUrl', 'offerAvailability'];

        foreach ($ai_schema_fields as $field) {
            if (!isset($event_data[$field]) && !empty($parameters[$field])) {
                $event_data[$field] = sanitize_text_field($parameters[$field]);
            }
        }

        if (!empty($handler_config['venue'])) {
            $event_data['venue'] = $handler_config['venue'];
        }

        // Update post
        wp_update_post([
            'ID' => $post_id,
            'post_title' => $event_data['title'],
            'post_content' => $this->generate_event_block_content($event_data, $parameters)
        ]);

        // Update featured image
        $this->processEventFeaturedImage($post_id, $handler_config, $engine);

        // Update venue
        $this->processVenue($post_id, $parameters);

        // Update taxonomies
        $handler_config_for_tax = $handler_config;
        $handler_config_for_tax['taxonomy_venue_selection'] = 'skip';
        $engine_data_array = $engine instanceof EngineData ? $engine->all() : [];
        $this->taxonomy_handler->processTaxonomies($post_id, $parameters, $handler_config_for_tax, $engine_data_array);
    }

    /**
     * Process venue assignment
     *
     * @param int $post_id Post ID
     * @param array $parameters Event parameters
     */
    private function processVenue(int $post_id, array $parameters): void {
        $venue_name = $parameters['venue'] ?? '';

        if (!empty($venue_name)) {
            $venue_metadata = [
                'address' => $this->getParameterValue($parameters, 'venueAddress'),
                'city' => $this->getParameterValue($parameters, 'venueCity'),
                'state' => $this->getParameterValue($parameters, 'venueState'),
                'zip' => $this->getParameterValue($parameters, 'venueZip'),
                'country' => $this->getParameterValue($parameters, 'venueCountry'),
                'phone' => $this->getParameterValue($parameters, 'venuePhone'),
                'website' => $this->getParameterValue($parameters, 'venueWebsite'),
                'coordinates' => $this->getParameterValue($parameters, 'venueCoordinates'),
                'capacity' => $this->getParameterValue($parameters, 'venueCapacity')
            ];

            $venue_result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue($venue_name, $venue_metadata);

            if ($venue_result['term_id']) {
                Venue::assign_venue_to_event($post_id, [
                    'venue' => $venue_result['term_id']
                ]);
            }
        }
    }

    /**
     * Process featured image with EngineData context and handler fallbacks.
     */
    private function processEventFeaturedImage(int $post_id, array $handler_config, EngineData $engine): void {
        if (empty($handler_config['include_images'])) {
            return;
        }

        $image_path = $engine->getImagePath();

        if (!empty($image_path)) {
            $engine->attachImageToPost($post_id, $handler_config);
        } elseif (!empty($handler_config['eventImage'])) {
            $fallback_engine = new EngineData(['image_file_path' => $handler_config['eventImage']], null);
            $fallback_engine->attachImageToPost($post_id, $handler_config);
        }
    }

    /**
     * Generate Event Details block content
     *
     * @param array $event_data Event data
     * @param array $parameters Full parameters (includes engine data)
     * @return string Block content
     */
    private function generate_event_block_content(array $event_data, array $parameters = []): string {
        $block_attributes = [
            'startDate' => $event_data['startDate'] ?? '',
            'startTime' => $event_data['startTime'] ?? '',
            'endDate' => $event_data['endDate'] ?? '',
            'endTime' => $event_data['endTime'] ?? '',
            'venue' => $parameters['venue'] ?? $event_data['venue'] ?? '',
            'address' => $parameters['venueAddress'] ?? $event_data['venueAddress'] ?? '',
            'price' => $event_data['price'] ?? '',
            'ticketUrl' => $event_data['ticketUrl'] ?? '',

            'performer' => $event_data['performer'] ?? '',
            'performerType' => $event_data['performerType'] ?? 'PerformingGroup',
            'organizer' => $event_data['organizer'] ?? '',
            'organizerType' => $event_data['organizerType'] ?? 'Organization',
            'organizerUrl' => $event_data['organizerUrl'] ?? '',
            'eventStatus' => $event_data['eventStatus'] ?? 'EventScheduled',
            'previousStartDate' => $event_data['previousStartDate'] ?? '',
            'priceCurrency' => $event_data['priceCurrency'] ?? 'USD',
            'offerAvailability' => $event_data['offerAvailability'] ?? 'InStock',

            'venuePhone' => $parameters['venuePhone'] ?? '',
            'venueWebsite' => $parameters['venueWebsite'] ?? '',
            'venueCity' => $parameters['venueCity'] ?? '',
            'venueState' => $parameters['venueState'] ?? '',
            'venueZip' => $parameters['venueZip'] ?? '',
            'venueCountry' => $parameters['venueCountry'] ?? '',
            'venueCoordinates' => $parameters['venueCoordinates'] ?? '',

            'showVenue' => true,
            'showPrice' => true,
            'showTicketLink' => true
        ];

        $block_attributes = array_filter($block_attributes, function($value) {
            return $value !== '' && $value !== null;
        });

        $block_attributes['showVenue'] = true;
        $block_attributes['showPrice'] = true;
        $block_attributes['showTicketLink'] = true;

        $block_json = wp_json_encode($block_attributes);
        $description = !empty($event_data['description']) ? wp_kses_post($event_data['description']) : '';

        $inner_blocks = '';
        if ($description) {
            $inner_blocks = '<!-- wp:paragraph -->
<p>' . $description . '</p>
<!-- /wp:paragraph -->';
        }

        return '<!-- wp:datamachine-events/event-details ' . $block_json . ' -->' .
               ($inner_blocks ? "\n" . $inner_blocks . "\n" : '') .
               '<!-- /wp:datamachine-events/event-details -->';
    }

    /**
     * Extract event-specific parameters from engine data
     *
     * @param EngineData $engine Engine snapshot helper
     * @return array Event-specific parameters
     */
    private function extract_event_engine_parameters(EngineData $engine): array {
        $fields = [
            'venue', 'venueAddress', 'venueCity', 'venueState', 'venueZip',
            'venueCountry', 'venuePhone', 'venueWebsite', 'venueCoordinates',
            'venueCapacity', 'eventImage'
        ];

        $resolved = [];
        foreach ($fields as $field) {
            $value = $engine->get($field);
            if ($value !== null && $value !== '') {
                $resolved[$field] = $value;
            }
        }

        $legacy_context = $engine->get('venue_context');
        if (is_array($legacy_context)) {
            $mapping = [
                'name' => 'venue',
                'address' => 'venueAddress',
                'city' => 'venueCity',
                'state' => 'venueState',
                'zip' => 'venueZip',
                'country' => 'venueCountry',
                'phone' => 'venuePhone',
                'website' => 'venueWebsite',
                'coordinates' => 'venueCoordinates',
                'capacity' => 'venueCapacity'
            ];

            foreach ($mapping as $source_key => $target_key) {
                $value = $legacy_context[$source_key] ?? null;
                if ($value !== null && $value !== '' && empty($resolved[$target_key])) {
                    $resolved[$target_key] = $value;
                }
            }
        }

        return $resolved;
    }

    /**
     * Custom taxonomy handler for venue
     *
     * @param int $post_id Post ID
     * @param array $parameters Event parameters
     * @param array $handler_config Handler configuration
     * @param mixed $engine_context Engine context (EngineData|array|null)
     * @return array|null Assignment result
     */
    public function assignVenueTaxonomy(int $post_id, array $parameters, array $handler_config, $engine_context = null): ?array {
        $engine = $this->resolveEngineContext($engine_context, $parameters);
        $engine_parameters = $this->extract_event_engine_parameters($engine);
        $venue_name = $parameters['venue'] ?? ($engine_parameters['venue'] ?? '');

        if (empty($venue_name)) {
            return null;
        }

        $venue_metadata = [
            'address' => $this->getParameterValue($parameters, 'venueAddress') ?: ($engine_parameters['venueAddress'] ?? ''),
            'city' => $this->getParameterValue($parameters, 'venueCity') ?: ($engine_parameters['venueCity'] ?? ''),
            'state' => $this->getParameterValue($parameters, 'venueState') ?: ($engine_parameters['venueState'] ?? ''),
            'zip' => $this->getParameterValue($parameters, 'venueZip') ?: ($engine_parameters['venueZip'] ?? ''),
            'country' => $this->getParameterValue($parameters, 'venueCountry') ?: ($engine_parameters['venueCountry'] ?? ''),
            'phone' => $this->getParameterValue($parameters, 'venuePhone') ?: ($engine_parameters['venuePhone'] ?? ''),
            'website' => $this->getParameterValue($parameters, 'venueWebsite') ?: ($engine_parameters['venueWebsite'] ?? ''),
            'coordinates' => $this->getParameterValue($parameters, 'venueCoordinates') ?: ($engine_parameters['venueCoordinates'] ?? ''),
            'capacity' => $this->getParameterValue($parameters, 'venueCapacity') ?: ($engine_parameters['venueCapacity'] ?? '')
        ];

        $venue_result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue($venue_name, $venue_metadata);

        if (!empty($venue_result['term_id'])) {
            $assignment_result = Venue::assign_venue_to_event($post_id, ['venue' => $venue_result['term_id']]);

            if (!empty($assignment_result)) {
                return [
                    'success' => true,
                    'taxonomy' => 'venue',
                    'term_id' => $venue_result['term_id'],
                    'term_name' => $venue_name,
                    'source' => 'event_venue_handler'
                ];
            }

            return ['success' => false, 'error' => 'Failed to assign venue term'];
        }

        return ['success' => false, 'error' => 'Failed to create or find venue'];
    }

    /**
     * Get parameter value (camelCase only)
     *
     * @param array $parameters Parameters array
     * @param string $camelKey CamelCase parameter key
     * @return string Parameter value or empty string
     */
    private function getParameterValue(array $parameters, string $camelKey): string {
        if (!empty($parameters[$camelKey])) {
            return (string) $parameters[$camelKey];
        }
        return '';
    }

    /**
     * Log wrapper
     */
    protected function log(string $level, string $message, array $context = []): void {
        $this->dmLog($level, $message, $context);
    }

    /**
     * Success response wrapper
     */
    protected function successResponse(array $data): array {
        return [
            'success' => true,
            'data' => $data,
            'tool_name' => 'datamachine_events'
        ];
    }

    /**
     * Normalize arbitrary engine context input into an EngineData instance.
     *
     * @param mixed $engine_context Engine context (EngineData|array|null)
     * @param array $parameters Parameters array
     * @return EngineData EngineData instance
     */
    private function resolveEngineContext($engine_context = null, array $parameters = []): EngineData {
        if ($engine_context instanceof EngineData) {
            return $engine_context;
        }

        $job_id = (int) ($parameters['job_id'] ?? null);

        if ($engine_context === null) {
            $engine_context = $parameters['engine'] ?? ($parameters['engine_data'] ?? []);
        }

        if ($engine_context instanceof EngineData) {
            return $engine_context;
        }

        if (!is_array($engine_context)) {
            $engine_context = is_string($engine_context) ? ['image_url' => $engine_context] : [];
        }

        return new EngineData($engine_context, $job_id);
    }

    /**
     * Logging wrapper for Data Machine logging system.
     *
     * @param string $level Log level (debug, info, warning, error)
     * @param string $message Log message
     * @param array $context Additional context data
     */
    private function dmLog(string $level, string $message, array $context = []): void {
        do_action('datamachine_log', $level, $message, $context);
    }
}
