<?php
/**
 * AI-driven event creation with Event Details block generation and venue handling.
 *
 * @package DataMachineEvents\Steps\Publish\Events
 */

namespace DataMachineEvents\Steps\Publish\Events;

use DataMachineEvents\Steps\Publish\Events\Venue;
use DataMachineEvents\Steps\Publish\Events\Schema;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;

if (!defined('ABSPATH')) {
    exit;
}

class Publisher extends PublishHandler {

    public function __construct() {
        parent::__construct('datamachine_events');
    }

    /**
     * Execute event publishing.
     *
     * @param array $parameters Event data from AI tool call
     * @param array $handler_config Handler configuration
     * @return array Tool call result
     */
    protected function executePublish(array $parameters, array $handler_config): array {
        return $this->handle_tool_call_legacy($parameters, ['handler_config' => $handler_config]);
    }

    /**
     * Legacy method maintained for backward compatibility.
     * Routes to new structure via executePublish().
     *
     * @param array $parameters Event data from AI tool call
     * @param array $tool_def Tool definition configuration
     * @return array Tool call result with success status and created post data
     */
    private function handle_tool_call_legacy(array $parameters, array $tool_def = []): array {
        if (empty($parameters['title'])) {
            return $this->errorResponse('DM Events tool call missing required title parameter', [
                'provided_parameters' => array_keys($parameters),
                'required_parameters' => ['title']
            ]);
        }
        
        $job_id = $parameters['job_id'] ?? null;
        $engine_data = $this->getEngineData($job_id);

        $engine_parameters = $this->extract_event_engine_parameters($engine_data);
        $parameters = array_merge($engine_parameters, $parameters);

        if (!empty($engine_parameters)) {
            $this->log('debug', 'DM Events Tool: Loaded engine venue context', [
                'fields' => array_keys($engine_parameters)
            ]);
        }

        $handler_config = $tool_def['handler_config'] ?? [];
        
        $routing = Schema::engine_or_tool($parameters, $handler_config, $engine_parameters);

        $this->log('debug', 'DM Events Tool: Smart parameter routing', [
            'engine_params' => array_keys($routing['engine']),
            'tool_params' => $routing['tool'],
            'total_ai_params' => count($parameters)
        ]);

        $post_status = $handler_config['post_status'] ?? 'draft';
        $post_author = $handler_config['post_author'] ?? get_current_user_id() ?: 1;

        $this->log('debug', 'DM Events Tool: Processing event creation', [
            'title' => $parameters['title'],
            'has_venue' => !empty($parameters['venue']),
            'post_status' => $post_status,
            'post_author' => $post_author
        ]);
        
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
            $error_msg = 'Event post creation failed: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error');
            return $this->errorResponse($error_msg, [
                'event_data' => $event_data,
                'post_data' => $post_data
            ]);
        }
        
        $featured_image_result = null;
        if (!empty($handler_config['include_images'])) {
            $image_url = $parameters['eventImage'] ?? $handler_config['eventImage'] ?? null;
            if ($image_url) {
                $featured_image_result = $this->set_featured_image($post_id, $image_url);
            }
        }
        
        $venue_result = null;
        $venue_name = $parameters['venue'] ?? '';

        if (!empty($venue_name)) {
            $venue_metadata = [
                'address' => $this->getParameterValue($parameters, 'venueAddress', 'venue_address'),
                'city' => $this->getParameterValue($parameters, 'venueCity', 'venue_city'),
                'state' => $this->getParameterValue($parameters, 'venueState', 'venue_state'),
                'zip' => $this->getParameterValue($parameters, 'venueZip', 'venue_zip'),
                'country' => $this->getParameterValue($parameters, 'venueCountry', 'venue_country'),
                'phone' => $this->getParameterValue($parameters, 'venuePhone', 'venue_phone'),
                'website' => $this->getParameterValue($parameters, 'venueWebsite', 'venue_website'),
                'coordinates' => $this->getParameterValue($parameters, 'venueCoordinates', 'venue_coordinates'),
                'capacity' => $this->getParameterValue($parameters, 'venueCapacity', 'venue_capacity')
            ];

            // Create or find venue
            $venue_result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue($venue_name, $venue_metadata);

            if ($venue_result['term_id']) {
                // Assign existing venue term_id to event
                $assignment_result = Venue::assign_venue_to_event($post_id, [
                    'venue' => $venue_result['term_id']
                ]);
            }
        }
        
        $taxonomy_results = $this->process_event_taxonomies($post_id, $parameters, $handler_config);

        $this->log('debug', 'DM Events Tool: Event created successfully', [
            'post_id' => $post_id,
            'post_url' => get_permalink($post_id),
            'venue_created' => $venue_result !== null,
            'total_taxonomy_assignments' => count($taxonomy_results)
        ]);

        return $this->successResponse([
            'post_id' => $post_id,
            'post_url' => get_permalink($post_id),
            'venue_result' => $venue_result,
            'taxonomy_results' => $taxonomy_results
        ]);
    }
    
    /**
     * Create event post with Event Details block
     *
     * @param array $event_data Event data
     * @param array $settings Publisher settings
     * @return int|false Post ID on success, false on failure
     */
    public static function create_event($event_data, $settings = []) {
        if (!self::validate_event_data($event_data)) {
            return false;
        }
        
        $post_data = [
            'post_type' => 'datamachine_events',
            'post_title' => sanitize_text_field($event_data['title']),
            'post_status' => 'publish',
            'post_content' => self::generate_event_content($event_data)
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id) || !$post_id) {
            return false;
        }
        
        if (!empty($event_data['venue'])) {
            // Extract venue metadata
            $venue_metadata = [
                'address' => $event_data['venue_address'] ?? '',
                'city' => $event_data['venue_city'] ?? '',
                'state' => $event_data['venue_state'] ?? '',
                'zip' => $event_data['venue_zip'] ?? '',
                'country' => $event_data['venue_country'] ?? '',
                'phone' => $event_data['venue_phone'] ?? '',
                'website' => $event_data['venue_website'] ?? '',
                'coordinates' => $event_data['venue_coordinates'] ?? '',
                'capacity' => $event_data['venue_capacity'] ?? ''
            ];

            // Create or find venue
            $venue_result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue(
                $event_data['venue'],
                $venue_metadata
            );

            if ($venue_result['term_id']) {
                // Assign existing venue term_id to event
                Venue::assign_venue_to_event($post_id, [
                    'venue' => $venue_result['term_id']
                ]);
            }
        }
        
        return $post_id;
    }
    
    /**
     * Generate Event Details block content for AI tool calls
     *
     * Venue data automatically available in $parameters array from engine.
     *
     * @param array $event_data Event data
    * @param array $parameters Engine data context (includes venue data)
     * @return string Block content
     */
    private function generate_event_block_content($event_data, $parameters = []) {
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
            
            // Venue data automatically injected by engine from event import
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
        
        // Generate Event Details block with InnerBlocks for description
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
     * Extract event-specific parameters from engine data.
     *
     * @param array $engine_data Full engine data array
     * @return array Event-specific parameters
     */
    private function extract_event_engine_parameters(array $engine_data): array {
        $fields = [
            'venue',
            'venueAddress',
            'venueCity',
            'venueState',
            'venueZip',
            'venueCountry',
            'venuePhone',
            'venueWebsite',
            'venueCoordinates',
            'venueCapacity',
            'eventImage'
        ];

        $resolved = [];
        foreach ($fields as $field) {
            if (!empty($engine_data[$field])) {
                $resolved[$field] = $engine_data[$field];
            }
        }

        return $resolved;
    }

    /**
     * Process event taxonomies using unified approach.
     *
     * Combines direct assignments and AI-decided taxonomies, excluding venue taxonomy.
     *
     * @param int $post_id Event post ID
     * @param array $parameters AI parameters
     * @param array $handler_config Handler configuration
     * @return array Taxonomy assignment results
     */
    private function process_event_taxonomies(int $post_id, array $parameters, array $handler_config): array {
        $results = [];

        $taxonomies = get_object_taxonomies('datamachine_events', 'objects');

        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->name === 'venue' || !$taxonomy->public) {
                continue;
            }

            $field_key = "taxonomy_{$taxonomy->name}_selection";
            $selection = $handler_config[$field_key] ?? 'skip';

            if ($selection === 'skip') {
                continue;
            } elseif (is_numeric($selection) && $selection > 0) {
                $result = $this->assign_pre_selected_taxonomy($post_id, $taxonomy->name, (int) $selection);
                if ($result) {
                    $results[$taxonomy->name] = $result;
                }
            } elseif ($selection === 'ai_decides' && !empty($parameters[$taxonomy->name])) {
                $result = $this->assign_ai_decided_taxonomy($post_id, $taxonomy->name, $parameters[$taxonomy->name]);
                if ($result) {
                    $results[$taxonomy->name] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * Assign pre-selected taxonomy term.
     *
     * @param int $post_id Post ID
     * @param string $taxonomy_name Taxonomy name
     * @param int $term_id Term ID
     * @return array|null Assignment result
     */
    private function assign_pre_selected_taxonomy(int $post_id, string $taxonomy_name, int $term_id): ?array {
        $term = get_term($term_id, $taxonomy_name);

        if ($term && !is_wp_error($term)) {
            $result = wp_set_post_terms($post_id, [$term_id], $taxonomy_name);
            if (!is_wp_error($result)) {
                $this->log('debug', 'DM Events: Direct taxonomy assignment successful', [
                    'taxonomy' => $taxonomy_name,
                    'term_id' => $term_id,
                    'term_name' => $term->name,
                    'post_id' => $post_id
                ]);

                return [
                    'success' => true,
                    'source' => 'direct_assignment',
                    'term_id' => $term_id,
                    'term_name' => $term->name,
                    'taxonomy' => $taxonomy_name
                ];
            } else {
                return [
                    'success' => false,
                    'source' => 'direct_assignment',
                    'error' => $result->get_error_message(),
                    'taxonomy' => $taxonomy_name
                ];
            }
        } else {
            $this->log('warning', 'DM Events: Invalid term ID for direct assignment', [
                'taxonomy' => $taxonomy_name,
                'term_id' => $term_id
            ]);
        }

        return null;
    }

    /**
     * Assign AI-decided taxonomy terms.
     *
     * @param int $post_id Post ID
     * @param string $taxonomy_name Taxonomy name
     * @param string|array $term_value Term value(s)
     * @return array Assignment result
     */
    private function assign_ai_decided_taxonomy(int $post_id, string $taxonomy_name, $term_value): array {
        if (is_array($term_value)) {
            $terms = array_map('sanitize_text_field', $term_value);
        } else {
            $terms = [sanitize_text_field($term_value)];
        }

        $result = wp_set_post_terms($post_id, $terms, $taxonomy_name);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error' => $result->get_error_message()
            ];
        }

        return [
            'success' => true,
            'terms' => $terms,
            'taxonomy' => $taxonomy_name
        ];
    }

    /**
     * Helper to read camelCase or snake_case parameter variants.
     */
    private function getParameterValue(array $parameters, string $camelKey, string $snakeKey = ''): string {
        if (!empty($parameters[$camelKey])) {
            return $parameters[$camelKey];
        }

        if ($snakeKey && !empty($parameters[$snakeKey])) {
            return $parameters[$snakeKey];
        }

        return '';
    }
    
    /**
     * Generate Event Details block content (legacy method)
     *
     * @param array $event_data Event data
     * @return string Block content
     */
    private static function generate_event_content($event_data) {
        $block_attributes = [
            'startDate' => $event_data['startDate'] ?? '',
            'startTime' => $event_data['startTime'] ?? '',
            'endDate' => $event_data['endDate'] ?? '',
            'endTime' => $event_data['endTime'] ?? '',
            'venue' => $event_data['venue'] ?? '',
            'address' => $event_data['address'] ?? '',
            'price' => $event_data['price'] ?? '',
            'ticketUrl' => $event_data['ticketUrl'] ?? ''
        ];
        
        $block_json = wp_json_encode($block_attributes);
        
        // Generate Event Details block with InnerBlocks
        $inner_blocks = '';
        if (!empty($event_data['title'])) {
            $inner_blocks .= '<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">' . esc_html($event_data['title']) . '</h3>
<!-- /wp:heading -->

';
        }
        
        $inner_blocks .= '<!-- wp:paragraph -->
<p>Event details managed in block attributes.</p>
<!-- /wp:paragraph -->';
        
        return '<!-- wp:datamachine-events/event-details ' . $block_json . ' -->' . 
               "\n" . $inner_blocks . "\n" .
               '<!-- /wp:datamachine-events/event-details -->';
    }
    
    
    /**
     * Validate event data structure
     *
     * @param array $event_data Event data
     * @return bool True if valid, false if invalid
     */
    private static function validate_event_data($event_data) {
        if (!is_array($event_data)) {
            return false;
        }
        
        $required_fields = ['title'];
        
        foreach ($required_fields as $field) {
            if (empty($event_data[$field])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get event creation statistics
     *
     * @return array Statistics
     */
    public static function get_creation_stats() {
        return [
            'total_events' => wp_count_posts('datamachine_events')->publish,
            'total_venues' => wp_count_terms(['taxonomy' => 'venue']),
            'events_with_venues' => self::count_events_with_venues()
        ];
    }
    
    /**
     * Count published events with venue assignments
     *
     * @return int Count
     */
    private static function count_events_with_venues() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE p.post_type = 'datamachine_events' 
            AND p.post_status = 'publish'
            AND tt.taxonomy = 'venue'
        ");
        
        return (int) $count;
    }
    
    /**
     * Set featured image for post from image URL.
     *
     * Downloads image from URL and attaches as featured image to post.
     *
     * @param int    $post_id   Post ID
     * @param string $image_url Image URL
     * @return array|null Result with success status and attachment details
     */
    private function set_featured_image(int $post_id, string $image_url): ?array {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        try {
            $temp_file = download_url($image_url);
            if (is_wp_error($temp_file)) {
                $this->log('warning', 'DM Events Featured Image: Failed to download image', [
                    'image_url' => $image_url,
                    'error' => $temp_file->get_error_message()
                ]);
                return ['success' => false, 'error' => 'Failed to download image'];
            }

            $file_array = [
                'name' => basename($image_url),
                'tmp_name' => $temp_file
            ];

            $attachment_id = media_handle_sideload($file_array, $post_id);

            if (is_wp_error($attachment_id)) {
                @unlink($temp_file);
                $this->log('warning', 'DM Events Featured Image: Failed to create attachment', [
                    'image_url' => $image_url,
                    'error' => $attachment_id->get_error_message()
                ]);
                return ['success' => false, 'error' => 'Failed to create media attachment'];
            }

            $result = set_post_thumbnail($post_id, $attachment_id);

            if (!$result) {
                $this->log('warning', 'DM Events Featured Image: Failed to set featured image', [
                    'post_id' => $post_id,
                    'attachment_id' => $attachment_id
                ]);
                return ['success' => false, 'error' => 'Failed to set featured image'];
            }

            $this->log('debug', 'DM Events Featured Image: Successfully set featured image', [
                'post_id' => $post_id,
                'attachment_id' => $attachment_id,
                'image_url' => $image_url
            ]);

            return [
                'success' => true,
                'attachment_id' => $attachment_id,
                'image_url' => $image_url
            ];

        } catch (\Exception $e) {
            $this->log('error', 'DM Events Featured Image: Exception occurred', [
                'image_url' => $image_url,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
        }
    }
}