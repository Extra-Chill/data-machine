<?php
/**
 * Data Machine Events Venue Handler
 *
 * Centralized venue taxonomy handling for Data Machine Events.
 *
 * @package DmEvents
 */

namespace DmEvents\Steps\Publish\Handlers\DmEvents;

use DmEvents\Core\Venue_Taxonomy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized venue taxonomy management for Data Machine Events
 * 
 * Handles venue term creation, metadata population, event assignment,
 * and data validation with error handling and logging.
 */
class DmEventsVenue {
    
    /**
     * Find existing venue or create new venue term with metadata
     *
     * @param string $venue_name Venue name for term creation/lookup
     * @param array $venue_data Optional venue metadata
     * @return array Venue operation result with term_id, was_created, success status, and error details
     */
    public static function find_or_create_venue($venue_name, $venue_data = []) {
        if (empty($venue_name)) {
            return [
                'success' => false,
                'error' => 'Venue name is required',
                'term_id' => null,
                'was_created' => false
            ];
        }
        
        $venue_name = sanitize_text_field($venue_name);
        
        $existing_term = get_term_by('name', $venue_name, 'venue');
        
        if ($existing_term) {
            do_action('dm_log', 'debug', 'Found existing venue term', [
                'venue_name' => $venue_name,
                'term_id' => $existing_term->term_id
            ]);
            
            return [
                'success' => true,
                'term_id' => $existing_term->term_id,
                'term_name' => $venue_name,
                'was_created' => false,
                'error' => null
            ];
        }
        
        $term_args = [
            'description' => $venue_data['venueDescription'] ?? $venue_data['description'] ?? ''
        ];
        
        $term_result = wp_insert_term($venue_name, 'venue', $term_args);
        
        if (is_wp_error($term_result)) {
            $error_msg = 'Venue term creation failed: ' . $term_result->get_error_message();
            do_action('dm_log', 'error', $error_msg, [
                'venue_name' => $venue_name,
                'venue_data' => $venue_data,
                'wp_error' => $term_result->get_error_message()
            ]);
            
            return [
                'success' => false,
                'error' => $error_msg,
                'term_id' => null,
                'was_created' => false
            ];
        }
        
        $term_id = $term_result['term_id'];
        
        $venue_meta = self::extract_venue_data($venue_data);
        if (!empty($venue_meta)) {
            $meta_success = Venue_Taxonomy::update_venue_meta($term_id, $venue_meta);
            if (!$meta_success) {
                do_action('dm_log', 'warning', 'Venue meta update failed', [
                    'venue_name' => $venue_name,
                    'term_id' => $term_id,
                    'venue_meta' => $venue_meta
                ]);
            }
        }
        
        do_action('dm_log', 'debug', 'Created new venue term with metadata', [
            'venue_name' => $venue_name,
            'term_id' => $term_id,
            'meta_fields_populated' => count($venue_meta)
        ]);
        
        return [
            'success' => true,
            'term_id' => $term_id,
            'term_name' => $venue_name,
            'was_created' => true,
            'error' => null
        ];
    }
    
    /**
     * Complete venue assignment workflow for event posts
     *
     * @param int $post_id Event post ID for venue assignment
     * @param string $venue_name Venue name
     * @param array $venue_data Optional venue metadata
     * @return array Complete assignment result with venue and relationship details
     */
    public static function assign_venue_to_event($post_id, $venue_name, $venue_data = []) {
        if (!$post_id || empty($venue_name)) {
            return [
                'success' => false,
                'error' => 'Post ID and venue name are required',
                'venue_result' => null
            ];
        }
        
        $venue_result = self::find_or_create_venue($venue_name, $venue_data);
        
        if (!$venue_result['success']) {
            return [
                'success' => false,
                'error' => 'Venue creation failed: ' . $venue_result['error'],
                'venue_result' => $venue_result
            ];
        }
        
        $assignment_result = wp_set_post_terms($post_id, [$venue_result['term_id']], 'venue');
        
        if (is_wp_error($assignment_result)) {
            $error_msg = 'Venue assignment failed: ' . $assignment_result->get_error_message();
            do_action('dm_log', 'error', $error_msg, [
                'post_id' => $post_id,
                'venue_name' => $venue_name,
                'term_id' => $venue_result['term_id'],
                'wp_error' => $assignment_result->get_error_message()
            ]);
            
            return [
                'success' => false,
                'error' => $error_msg,
                'venue_result' => $venue_result
            ];
        }
        
        do_action('dm_log', 'debug', 'Venue successfully assigned to event', [
            'post_id' => $post_id,
            'venue_name' => $venue_name,
            'term_id' => $venue_result['term_id'],
            'was_venue_created' => $venue_result['was_created']
        ]);
        
        return [
            'success' => true,
            'error' => null,
            'venue_result' => $venue_result
        ];
    }
    
    /**
     * Extract venue data from any source format
     *
     * Maps venue-prefixed keys to Venue_Taxonomy field structure with sanitization.
     *
     * @param array $source_data Source data containing venue information
     * @param string $type Source type for debugging
     * @return array Mapped venue metadata array with sanitized values
     */
    public static function extract_venue_data($source_data, $type = 'ai_parameters') {
        if (!is_array($source_data) || empty($source_data)) {
            return [];
        }
        
        $venue_meta = [];
        
        $field_mappings = [
            'venue_address' => 'address',
            'venue_city' => 'city',
            'venue_state' => 'state',
            'venue_zip' => 'zip',
            'venue_country' => 'country',
            'venue_phone' => 'phone',
            'venue_website' => 'website',
            'venue_capacity' => 'capacity',
            'venue_coordinates' => 'coordinates',
            'address' => 'address',
            'city' => 'city',
            'state' => 'state',
            'zip' => 'zip',
            'country' => 'country',
            'phone' => 'phone',
            'website' => 'website',
            'capacity' => 'capacity',
            'coordinates' => 'coordinates'
        ];
        
        foreach ($field_mappings as $source_key => $meta_key) {
            if (!empty($source_data[$source_key])) {
                if (!isset($venue_meta[$meta_key])) {
                    $venue_meta[$meta_key] = sanitize_text_field($source_data[$source_key]);
                }
            }
        }
        
        return $venue_meta;
    }
    
    /**
     * Validate venue data for completeness and accuracy
     *
     * @param array $venue_data Venue data array to validate
     * @return array Validation result with success status and error details
     */
    public static function validate_venue_data($venue_data) {
        if (!is_array($venue_data)) {
            return [
                'valid' => false,
                'errors' => ['Venue data must be an array'],
                'sanitized_data' => []
            ];
        }
        
        $errors = [];
        $sanitized_data = [];
        
        $optional_fields = [
            'address' => 'sanitize_text_field',
            'city' => 'sanitize_text_field',
            'state' => 'sanitize_text_field',
            'zip' => 'sanitize_text_field',
            'country' => 'sanitize_text_field',
            'phone' => 'sanitize_text_field',
            'website' => 'esc_url_raw',
            'capacity' => 'absint',
            'coordinates' => 'sanitize_text_field'
        ];
        
        foreach ($optional_fields as $field => $sanitizer) {
            if (isset($venue_data[$field]) && !empty($venue_data[$field])) {
                $sanitized_value = call_user_func($sanitizer, $venue_data[$field]);
                
                if ($field === 'website' && !empty($sanitized_value) && !filter_var($sanitized_value, FILTER_VALIDATE_URL)) {
                    $errors[] = "Invalid website URL: {$venue_data[$field]}";
                } elseif ($field === 'capacity' && $sanitized_value <= 0 && !empty($venue_data[$field])) {
                    $errors[] = "Capacity must be a positive number: {$venue_data[$field]}";
                } else {
                    $sanitized_data[$field] = $sanitized_value;
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized_data' => $sanitized_data
        ];
    }
    
    /**
     * Get venue assignment statistics for monitoring
     *
     * @return array Venue operation statistics
     */
    public static function get_venue_stats() {
        global $wpdb;
        
        $venues_with_meta = $wpdb->get_var("
            SELECT COUNT(DISTINCT tm.term_id) 
            FROM {$wpdb->termmeta} tm
            INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
            WHERE tt.taxonomy = 'venue'
            AND tm.meta_key LIKE '_venue_%'
        ");
        
        $total_venues = wp_count_terms(['taxonomy' => 'venue']);
        
        $events_with_venues = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE p.post_type = 'dm_events' 
            AND p.post_status = 'publish'
            AND tt.taxonomy = 'venue'
        ");
        
        return [
            'total_venues' => (int) $total_venues,
            'venues_with_metadata' => (int) $venues_with_meta,
            'events_with_venues' => (int) $events_with_venues,
            'metadata_coverage' => $total_venues > 0 ? round(($venues_with_meta / $total_venues) * 100, 2) : 0
        ];
    }
}