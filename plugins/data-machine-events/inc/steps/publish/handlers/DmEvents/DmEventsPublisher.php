<?php
/**
 * AI-driven event creation with Event Details block generation and venue handling.
 *
 * @package DmEvents\Steps\Publish\Handlers\DmEvents
 */

namespace DmEvents\Steps\Publish\Handlers\DmEvents;

use DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsVenue;
use DmEvents\Steps\Publish\Handlers\DmEvents\DmEventsSchema;

if (!defined('ABSPATH')) {
    exit;
}

class DmEventsPublisher {
    
    public function __construct() {
    }

    /**
     * @param array $parameters Event data from AI tool call
     * @param array $tool_def Tool definition configuration
     * @return array Tool call result with success status and created post data
     */
    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        if (empty($parameters['title'])) {
            $error_msg = 'DM Events tool call missing required title parameter';
            do_action('dm_log', 'error', $error_msg, [
                'provided_parameters' => array_keys($parameters),
                'required_parameters' => ['title']
            ]);
            
            return [
                'success' => false,
                'error' => $error_msg,
                'tool_name' => 'create_event'
            ];
        }
        
        $handler_config = $tool_def['handler_config'] ?? [];
        
        $routing = DmEventsSchema::engine_or_tool($parameters, $handler_config, $parameters);
        
        do_action('dm_log', 'debug', 'DM Events Tool: Smart parameter routing', [
            'engine_params' => array_keys($routing['engine']),
            'tool_params' => $routing['tool'],
            'total_ai_params' => count($parameters)
        ]);
        
        $post_status = $handler_config['post_status'] ?? 'draft';
        $post_author = $handler_config['post_author'] ?? get_current_user_id() ?: 1;
        
        do_action('dm_log', 'debug', 'DM Events Tool: Processing event creation', [
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
            'post_type' => 'dm_events',
            'post_title' => $event_data['title'],
            'post_status' => $post_status,
            'post_author' => $post_author,
            'post_content' => $this->generate_event_block_content($event_data, $parameters)
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id) || !$post_id) {
            $error_msg = 'Event post creation failed: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error');
            do_action('dm_log', 'error', $error_msg, [
                'event_data' => $event_data,
                'post_data' => $post_data
            ]);
            
            return [
                'success' => false,
                'error' => $error_msg,
                'tool_name' => 'create_event'
            ];
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
                'venueAddress' => $parameters['venueAddress'] ?? '',
                'venueCity' => $parameters['venueCity'] ?? '',
                'venueState' => $parameters['venueState'] ?? '',
                'venueZip' => $parameters['venueZip'] ?? '',
                'venueCountry' => $parameters['venueCountry'] ?? '',
                'venuePhone' => $parameters['venuePhone'] ?? '',
                'venueWebsite' => $parameters['venueWebsite'] ?? '',
                'venueCoordinates' => $parameters['venueCoordinates'] ?? ''
            ];
            
            $assignment_result = DmEventsVenue::assign_venue_to_event($post_id, $venue_name, $venue_metadata);
            $venue_result = $assignment_result['venue_result'];
        }
        
        $direct_taxonomy_results = $this->process_direct_taxonomy_assignments($post_id, $handler_config);
        
        $ai_taxonomy_results = $this->assign_taxonomies_from_parameters($post_id, $parameters, $handler_config);
        
        $taxonomy_results = array_merge($direct_taxonomy_results, $ai_taxonomy_results);
        
        do_action('dm_log', 'debug', 'DM Events Tool: Event created successfully', [
            'post_id' => $post_id,
            'post_url' => get_permalink($post_id),
            'venue_created' => $venue_result !== null,
            'direct_taxonomy_assignments' => count($direct_taxonomy_results),
            'ai_taxonomy_assignments' => count($ai_taxonomy_results),
            'total_taxonomy_assignments' => count($taxonomy_results)
        ]);
        
        return [
            'success' => true,
            'data' => [
                'post_id' => $post_id,
                'post_url' => get_permalink($post_id),
                'venue_result' => $venue_result,
                'taxonomy_results' => $taxonomy_results
            ],
            'tool_name' => 'create_event'
        ];
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
            'post_type' => 'dm_events',
            'post_title' => sanitize_text_field($event_data['title']),
            'post_status' => 'publish',
            'post_content' => self::generate_event_content($event_data)
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id) || !$post_id) {
            return false;
        }
        
        if (!empty($event_data['venue'])) {
            DmEventsVenue::assign_venue_to_event($post_id, $event_data['venue'], $event_data);
        }
        
        return $post_id;
    }
    
    /**
     * Generate Event Details block content for AI tool calls
     *
     * Venue data automatically available in $parameters array from engine.
     *
     * @param array $event_data Event data
     * @param array $parameters Engine parameters (includes venue data)
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
        
        return '<!-- wp:dm-events/event-details ' . $block_json . ' -->' . 
               ($inner_blocks ? "\n" . $inner_blocks . "\n" : '') .
               '<!-- /wp:dm-events/event-details -->';
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
        
        return '<!-- wp:dm-events/event-details ' . $block_json . ' -->' . 
               "\n" . $inner_blocks . "\n" .
               '<!-- /wp:dm-events/event-details -->';
    }
    
    
    /**
     * Process direct taxonomy assignments from handler configuration
     *
     * @param int $post_id Event post ID
     * @param array $handler_config Handler configuration
     * @return array Assignment results
     */
    private function process_direct_taxonomy_assignments($post_id, $handler_config) {
        $results = [];
        
        $taxonomies = get_object_taxonomies('dm_events', 'objects');
        
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->name === 'venue' || !$taxonomy->public) {
                continue;
            }
            
            $field_key = "taxonomy_{$taxonomy->name}_selection";
            $selection = $handler_config[$field_key] ?? 'skip';
            
            if (is_numeric($selection) && $selection > 0) {
                $term_id = (int) $selection;
                $term = get_term($term_id, $taxonomy->name);
                
                if ($term && !is_wp_error($term)) {
                    $result = wp_set_post_terms($post_id, [$term_id], $taxonomy->name);
                    if (!is_wp_error($result)) {
                        $results[$taxonomy->name] = [
                            'success' => true,
                            'source' => 'direct_assignment',
                            'term_id' => $term_id,
                            'term_name' => $term->name,
                            'taxonomy' => $taxonomy->name
                        ];
                        
                        do_action('dm_log', 'debug', 'DM Events: Direct taxonomy assignment successful', [
                            'taxonomy' => $taxonomy->name,
                            'term_id' => $term_id,
                            'term_name' => $term->name,
                            'post_id' => $post_id
                        ]);
                    } else {
                        $results[$taxonomy->name] = [
                            'success' => false,
                            'source' => 'direct_assignment',
                            'error' => $result->get_error_message(),
                            'taxonomy' => $taxonomy->name
                        ];
                    }
                } else {
                    do_action('dm_log', 'warning', 'DM Events: Invalid term ID for direct assignment', [
                        'taxonomy' => $taxonomy->name,
                        'term_id' => $term_id,
                        'field_key' => $field_key
                    ]);
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Assign taxonomies from AI parameters
     *
     * @param int $post_id Event post ID
     * @param array $ai_parameters AI parameters
     * @param array $handler_config Handler configuration
     * @return array Assignment results
     */
    private function assign_taxonomies_from_parameters($post_id, $ai_parameters, $handler_config) {
        $results = [];
        
        $taxonomies = get_object_taxonomies('dm_events', 'objects');
        
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->name === 'venue' || !$taxonomy->public) {
                continue;
            }
            
            $field_key = "taxonomy_{$taxonomy->name}_selection";
            $selection = $handler_config[$field_key] ?? 'skip';
            
            if ($selection === 'ai_decides' && !empty($ai_parameters[$taxonomy->name])) {
                $term_value = $ai_parameters[$taxonomy->name];
                $result = $this->assign_taxonomy_term($post_id, $taxonomy->name, $term_value);
                $results[$taxonomy->name] = $result;
            }
        }
        
        return $results;
    }
    
    /**
     * Assign single taxonomy term
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @param string|array $term_value Term value(s)
     * @return array Assignment result
     */
    private function assign_taxonomy_term($post_id, $taxonomy, $term_value) {
        if (is_array($term_value)) {
            $terms = array_map('sanitize_text_field', $term_value);
        } else {
            $terms = [sanitize_text_field($term_value)];
        }
        
        $result = wp_set_post_terms($post_id, $terms, $taxonomy);
        
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error' => $result->get_error_message()
            ];
        }
        
        return [
            'success' => true,
            'terms' => $terms,
            'taxonomy' => $taxonomy
        ];
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
            'total_events' => wp_count_posts('dm_events')->publish,
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
            WHERE p.post_type = 'dm_events' 
            AND p.post_status = 'publish'
            AND tt.taxonomy = 'venue'
        ");
        
        return (int) $count;
    }
    
    /**
     * Set featured image for post from image URL
     *
     * @param int    $post_id   Post ID
     * @param string $image_url Image URL
     * @return array|null Result
     */
    private function set_featured_image(int $post_id, string $image_url): ?array {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        try {
            // Download image to temp file
            $temp_file = download_url($image_url);
            if (is_wp_error($temp_file)) {
                do_action('dm_log', 'warning', 'DM Events Featured Image: Failed to download image', [
                    'image_url' => $image_url,
                    'error' => $temp_file->get_error_message()
                ]);
                return ['success' => false, 'error' => 'Failed to download image'];
            }

            // Get image info and validate
            $file_array = [
                'name' => basename($image_url),
                'tmp_name' => $temp_file
            ];

            // Upload to media library
            $attachment_id = media_handle_sideload($file_array, $post_id);
            
            if (is_wp_error($attachment_id)) {
                @unlink($temp_file);
                do_action('dm_log', 'warning', 'DM Events Featured Image: Failed to create attachment', [
                    'image_url' => $image_url,
                    'error' => $attachment_id->get_error_message()
                ]);
                return ['success' => false, 'error' => 'Failed to create media attachment'];
            }

            // Set as featured image
            $result = set_post_thumbnail($post_id, $attachment_id);
            
            if (!$result) {
                do_action('dm_log', 'warning', 'DM Events Featured Image: Failed to set featured image', [
                    'post_id' => $post_id,
                    'attachment_id' => $attachment_id
                ]);
                return ['success' => false, 'error' => 'Failed to set featured image'];
            }

            do_action('dm_log', 'debug', 'DM Events Featured Image: Successfully set featured image', [
                'post_id' => $post_id,
                'attachment_id' => $attachment_id,
                'image_url' => $image_url
            ]);

            return [
                'success' => true,
                'attachment_id' => $attachment_id,
                'image_url' => $image_url
            ];

        } catch (Exception $e) {
            do_action('dm_log', 'error', 'DM Events Featured Image: Exception occurred', [
                'image_url' => $image_url,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
        }
    }
}