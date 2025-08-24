<?php
/**
 * Chill Events Publisher Handler
 * 
 * Creates WordPress event posts with Event Details blocks from imported event data.
 * Handles AI tool calls and direct publishing.
 *
 * @package ChillEvents\Steps\Publish\Handlers\ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents\Steps\Publish\Handlers\ChillEvents;

use ChillEvents\Events\Event_Data_Manager;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ChillEventsPublisher class
 * 
 * Publish handler that creates event posts with Event Details blocks.
 */
class ChillEventsPublisher {
    
    /**
     * Handle AI tool call for event creation
     * 
     * Called by Data Machine publish step with AI tool parameters.
     * Creates event post with Event Details block and handles venue taxonomy.
     * 
     * @param array $parameters AI tool parameters with event data
     * @param array $tool_def Tool definition from Data Machine
     * @return array Result with success status, message, and data
     */
    public function handle_tool_call($parameters, $tool_def = []) {
        $this->log_info('Chill Events Publisher: Processing AI tool call', [
            'parameters_keys' => array_keys($parameters),
            'tool_def' => $tool_def
        ]);
        
        try {
            // Validate required parameters
            if (empty($parameters['title'])) {
                return [
                    'success' => false,
                    'message' => 'Event title is required'
                ];
            }
            
            // Create event post
            $post_id = $this->create_event_post($parameters);
            
            if (is_wp_error($post_id)) {
                return [
                    'success' => false,
                    'message' => 'Failed to create event post: ' . $post_id->get_error_message()
                ];
            }
            
            // Handle venue taxonomy
            $venue_term_id = null;
            if (!empty($parameters['venue'])) {
                $venue_term_id = $this->handle_venue_taxonomy($post_id, $parameters);
            }
            
            // Trigger Event Data Manager sync
            if (class_exists('ChillEvents\Events\Event_Data_Manager')) {
                Event_Data_Manager::sync_event_meta($post_id);
            }
            
            $this->log_info('Chill Events Publisher: Event created successfully', [
                'post_id' => $post_id,
                'post_url' => get_permalink($post_id),
                'venue_term_id' => $venue_term_id
            ]);
            
            return [
                'success' => true,
                'message' => "Event created successfully",
                'data' => [
                    'post_id' => $post_id,
                    'post_url' => get_permalink($post_id),
                    'edit_url' => get_edit_post_link($post_id),
                    'venue_term_id' => $venue_term_id
                ]
            ];
            
        } catch (\Exception $e) {
            $this->log_error('Chill Events Publisher: Exception during event creation', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Error creating event: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Create event post with Event Details block
     * 
     * @param array $event_data Event data from AI tool
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    private function create_event_post(array $event_data) {
        // Prepare post data
        $post_data = [
            'post_title' => wp_unslash(sanitize_text_field($event_data['title'])),
            'post_content' => $this->generate_event_block_content($event_data),
            'post_status' => 'publish',
            'post_type' => 'chill_events',
            'post_author' => get_current_user_id() ?: 1
        ];
        
        // Add description to post content if provided
        if (!empty($event_data['description'])) {
            $description = wp_kses_post($event_data['description']);
            $post_data['post_content'] = "<!-- wp:paragraph -->\n<p>" . $description . "</p>\n<!-- /wp:paragraph -->\n\n" . $post_data['post_content'];
        }
        
        // Create the post
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            $this->log_error('Failed to create event post', [
                'error' => $post_id->get_error_message(),
                'post_data' => $post_data
            ]);
            return $post_id;
        }
        
        $this->log_debug('Event post created', [
            'post_id' => $post_id,
            'title' => $event_data['title']
        ]);
        
        return $post_id;
    }
    
    /**
     * Generate Event Details block content
     * 
     * @param array $event_data Event data
     * @return string Gutenberg block markup
     */
    private function generate_event_block_content(array $event_data): string {
        // Prepare block attributes
        $attributes = [
            'startDate' => sanitize_text_field($event_data['startDate'] ?? ''),
            'endDate' => sanitize_text_field($event_data['endDate'] ?? ''),
            'startTime' => sanitize_text_field($event_data['startTime'] ?? ''),
            'endTime' => sanitize_text_field($event_data['endTime'] ?? ''),
            'venue' => sanitize_text_field($event_data['venue'] ?? ''),
            'address' => sanitize_text_field($event_data['address'] ?? ''),
            'artist' => sanitize_text_field($event_data['artist'] ?? ''),
            'price' => sanitize_text_field($event_data['price'] ?? ''),
            'ticketUrl' => esc_url_raw($event_data['ticketUrl'] ?? ''),
            'showVenue' => !empty($event_data['venue']),
            'showArtist' => !empty($event_data['artist']),
            'showPrice' => !empty($event_data['price']),
            'showTicketLink' => !empty($event_data['ticketUrl'])
        ];
        
        // Remove empty attributes
        $attributes = array_filter($attributes, function($value) {
            return $value !== '' && $value !== null;
        });
        
        // Generate block markup
        $json_attributes = wp_json_encode($attributes, JSON_UNESCAPED_SLASHES);
        
        return "<!-- wp:chill-events/event-details $json_attributes /-->";
    }
    
    /**
     * Handle venue taxonomy assignment
     * 
     * @param int $post_id Post ID
     * @param array $event_data Event data
     * @return int|null Venue term ID or null if not handled
     */
    private function handle_venue_taxonomy(int $post_id, array $event_data): ?int {
        if (empty($event_data['venue'])) {
            return null;
        }
        
        $venue_name = sanitize_text_field($event_data['venue']);
        
        // Check if venue term exists
        $existing_term = get_term_by('name', $venue_name, 'venue');
        
        if ($existing_term) {
            $venue_term_id = $existing_term->term_id;
        } else {
            // Create new venue term
            $term_result = wp_insert_term($venue_name, 'venue');
            
            if (is_wp_error($term_result)) {
                $this->log_error('Failed to create venue term', [
                    'venue_name' => $venue_name,
                    'error' => $term_result->get_error_message()
                ]);
                return null;
            }
            
            $venue_term_id = $term_result['term_id'];
            
            // Add venue meta data if available
            if (!empty($event_data['address'])) {
                update_term_meta($venue_term_id, 'address', sanitize_text_field($event_data['address']));
            }
            
            $this->log_debug('Created new venue term', [
                'term_id' => $venue_term_id,
                'venue_name' => $venue_name
            ]);
        }
        
        // Assign venue to post
        $term_result = wp_set_post_terms($post_id, [$venue_term_id], 'venue');
        
        if (is_wp_error($term_result)) {
            $this->log_error('Failed to assign venue term to post', [
                'post_id' => $post_id,
                'venue_term_id' => $venue_term_id,
                'error' => $term_result->get_error_message()
            ]);
            return null;
        }
        
        return $venue_term_id;
    }
    
    /**
     * Log error message
     * 
     * @param string $message Error message
     * @param array $context Additional context
     */
    private function log_error(string $message, array $context = []): void {
        if (function_exists('do_action')) {
            do_action('dm_log', 'error', $message, $context);
        }
    }
    
    /**
     * Log info message
     * 
     * @param string $message Info message
     * @param array $context Additional context
     */
    private function log_info(string $message, array $context = []): void {
        if (function_exists('do_action')) {
            do_action('dm_log', 'info', $message, $context);
        }
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Debug message
     * @param array $context Additional context
     */
    private function log_debug(string $message, array $context = []): void {
        if (function_exists('do_action')) {
            do_action('dm_log', 'debug', $message, $context);
        }
    }
}