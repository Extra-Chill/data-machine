<?php
/**
 * Venue Taxonomy Registration and Management
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Comprehensive venue taxonomy with 9 meta fields and admin UI
 */
class Venue_Taxonomy {
    
    private static $meta_fields = [
        'address' => '_venue_address',
        'city' => '_venue_city',
        'state' => '_venue_state',
        'zip' => '_venue_zip',
        'country' => '_venue_country',
        'phone' => '_venue_phone',
        'website' => '_venue_website',
        'capacity' => '_venue_capacity',
        'coordinates' => '_venue_coordinates'
    ];
    
    public static function register() {
        self::register_venue_taxonomy();
        
        self::register_all_public_taxonomies();
        
        self::init_admin_hooks();
    }
    
    private static function register_venue_taxonomy() {
        if (taxonomy_exists('venue')) {
            register_taxonomy_for_object_type('venue', 'datamachine_events');
        } else {
            register_taxonomy('venue', array('post', 'datamachine_events'), array(
                'hierarchical' => false,
                'labels' => array(
                    'name' => _x('Venues', 'taxonomy general name', 'datamachine-events'),
                    'singular_name' => _x('Venue', 'taxonomy singular name', 'datamachine-events'),
                    'search_items' => __('Search Venues', 'datamachine-events'),
                    'all_items' => __('All Venues', 'datamachine-events'),
                    'edit_item' => __('Edit Venue', 'datamachine-events'),
                    'update_item' => __('Update Venue', 'datamachine-events'),
                    'add_new_item' => __('Add New Venue', 'datamachine-events'),
                    'new_item_name' => __('New Venue Name', 'datamachine-events'),
                    'menu_name' => __('Venues', 'datamachine-events'),
                ),
                'show_ui' => true,
                'show_admin_column' => true,
                'query_var' => true,
                'rewrite' => array('slug' => 'venue'),
                'show_in_rest' => true,
            ));
        }
        
        register_taxonomy_for_object_type('venue', 'datamachine_events');
    }
    
    private static function register_all_public_taxonomies() {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        
        if (!$taxonomies || is_wp_error($taxonomies)) {
            return;
        }
        
        foreach ($taxonomies as $taxonomy_slug) {
            if ($taxonomy_slug === 'venue') {
                continue;
            }
            
            register_taxonomy_for_object_type($taxonomy_slug, 'datamachine_events');
        }
    }
    
    /**
     * Find or create a venue with given name and metadata
     *
     * @param string $venue_name Venue name
     * @param array $venue_data Venue metadata (address, city, state, etc.)
     * @return array Array with keys: term_id, was_created
     */
    public static function find_or_create_venue($venue_name, $venue_data = []) {
        // Allow normalization of venue name (e.g. aliases, corrections)
        $venue_name = apply_filters('datamachine_events_normalize_venue_name', $venue_name);

        // Check if venue already exists by name
        $existing = get_term_by('name', $venue_name, 'venue');

        // Smart Lookup: If exact match fails, try variations with/without "The"
        if (!$existing) {
            $alt_name = '';
            if (stripos($venue_name, 'The ') === 0) {
                // Remove "The " prefix
                $alt_name = substr($venue_name, 4);
            } else {
                // Add "The " prefix
                $alt_name = 'The ' . $venue_name;
            }
            
            if (!empty($alt_name)) {
                $existing = get_term_by('name', $alt_name, 'venue');
            }
        }

        if ($existing) {
            $term_id = $existing->term_id;

            // Smart Merge: Fill in any missing metadata fields
            if (!empty($venue_data)) {
                self::smart_merge_venue_meta($term_id, $venue_data);
            }

            return [
                'term_id' => $term_id,
                'was_created' => false
            ];
        }

        // Create new venue
        $result = wp_insert_term($venue_name, 'venue');

        if (is_wp_error($result)) {
            error_log('DM Events: Failed to create venue "' . $venue_name . '": ' . $result->get_error_message());
            return [
                'term_id' => null,
                'was_created' => false
            ];
        }

        $term_id = $result['term_id'];

        // Update all metadata for new venue
        self::update_venue_meta($term_id, $venue_data);

        return [
            'term_id' => $term_id,
            'was_created' => true
        ];
    }

    /**
     * Smartly merge new venue data into existing venue
     * Only updates fields that are currently empty in the database
     *
     * @param int $term_id Venue term ID
     * @param array $venue_data New venue data
     */
    private static function smart_merge_venue_meta($term_id, $venue_data) {
        foreach (self::$meta_fields as $data_key => $meta_key) {
            // Skip if we don't have new data for this field
            if (empty($venue_data[$data_key])) {
                continue;
            }

            // Check existing value
            $existing_value = get_term_meta($term_id, $meta_key, true);

            // Only update if existing value is empty
            if (empty($existing_value)) {
                update_term_meta($term_id, $meta_key, sanitize_text_field($venue_data[$data_key]));
            }
        }
    }

    /**
     * Update venue term meta with venue data
     *
     * Supports selective updates - only updates fields present in $venue_data array.
     * This allows updating only changed fields without overwriting unchanged ones.
     *
     * @param int $term_id Venue term ID
     * @param array $venue_data Venue data array (can contain subset of fields)
     * @return bool Success status
     */
    public static function update_venue_meta($term_id, $venue_data) {
        if (!$term_id || !is_array($venue_data)) {
            return false;
        }

        // Only update fields present in $venue_data array
        foreach (self::$meta_fields as $data_key => $meta_key) {
            if (array_key_exists($data_key, $venue_data)) {
                // Update even if empty (allows clearing fields)
                update_term_meta($term_id, $meta_key, sanitize_text_field($venue_data[$data_key]));
            }
        }

        return true;
    }

    /**
     * Check if venue has any metadata populated
     *
     * @param int $term_id Venue term ID
     * @return bool True if venue has at least one metadata field populated
     */
    private static function has_venue_metadata($term_id) {
        if (!$term_id) {
            return false;
        }

        foreach (self::$meta_fields as $data_key => $meta_key) {
            $value = get_term_meta($term_id, $meta_key, true);
            if (!empty($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieves complete venue data with all 9 meta fields populated
     */
    public static function get_venue_data($term_id) {
        $term = get_term($term_id, 'venue');
        if (!$term || is_wp_error($term)) {
            return [];
        }

        $venue_data = [
            'name' => $term->name,
            'term_id' => $term_id,
            'slug' => $term->slug,
            'description' => $term->description,
        ];

        foreach (self::$meta_fields as $data_key => $meta_key) {
            $venue_data[$data_key] = get_term_meta($term_id, $meta_key, true);
        }

        return $venue_data;
    }
    
    /**
     * Generate formatted address string from venue meta fields
     *
     * @param int $term_id Venue term ID
     * @return string Formatted address string
     */
    public static function get_formatted_address($term_id) {
        $venue_data = self::get_venue_data($term_id);
        
        $address_parts = [];
        
        if (!empty($venue_data['address'])) {
            $address_parts[] = $venue_data['address'];
        }
        
        $city_state = [];
        if (!empty($venue_data['city'])) {
            $city_state[] = $venue_data['city'];
        }
        if (!empty($venue_data['state'])) {
            $city_state[] = $venue_data['state'];
        }
        
        if (!empty($city_state)) {
            $address_parts[] = implode(', ', $city_state);
        }
        
        if (!empty($venue_data['zip'])) {
            $address_parts[] = $venue_data['zip'];
        }
        
        return implode(', ', $address_parts);
    }
    
    public static function get_all_venues() {
        $venues = get_terms([
            'taxonomy' => 'venue',
            'hide_empty' => false,
        ]);

        if (is_wp_error($venues)) {
            return [];
        }

        $venue_data = [];
        foreach ($venues as $venue) {
            $venue_data[] = self::get_venue_data($venue->term_id);
        }

        return $venue_data;
    }

    public static function get_venues_by_event_count($min_events = 1) {
        $venues = get_terms([
            'taxonomy' => 'venue',
            'hide_empty' => true,
            'number' => 0,
        ]);
        
        if (is_wp_error($venues)) {
            return [];
        }
        
        $venue_data = [];
        foreach ($venues as $venue) {
            if ($venue->count >= $min_events) {
                $venue_data[] = self::get_venue_data($venue->term_id);
            }
        }
        
        return $venue_data;
    }
    
    private static function extract_city_from_location($location_name) {
        if (empty($location_name)) {
            return '';
        }
        
        $parts = explode(',', $location_name);
        return trim($parts[0]);
    }
    
    private static function extract_state_from_location($location_name) {
        if (empty($location_name)) {
            return '';
        }
        
        $parts = explode(',', $location_name);
        if (count($parts) > 1) {
            return trim($parts[1]);
        }
        
        return '';
    }
    
    private static function init_admin_hooks() {
        add_action('venue_add_form_fields', [__CLASS__, 'add_venue_form_fields']);
        
        add_action('venue_edit_form_fields', [__CLASS__, 'edit_venue_form_fields']);
        
        add_action('created_venue', [__CLASS__, 'save_venue_meta']);
        
        add_action('edited_venue', [__CLASS__, 'save_venue_meta']);
    }
    
    public static function add_venue_form_fields($taxonomy) {
        $fields = [
            'address'   => 'Address',
            'city'      => 'City',
            'state'     => 'State',
            'zip'       => 'Postal Code',
            'country'   => 'Country',
            'phone'     => 'Phone',
            'website'   => 'Website',
            'capacity'  => 'Capacity',
            'coordinates' => 'Coordinates'
        ];

        foreach ($fields as $key => $label) {
            $meta_key = "_venue_$key";
            echo '<div class="form-field">';
            echo "<label for='$meta_key'>$label</label>";
            echo "<input type='text' name='$meta_key' id='$meta_key' value='' class='regular-text' />";
            echo '</div>';
        }
    }

    public static function edit_venue_form_fields($term) {
        $fields = [
            'address'   => 'Address',
            'city'      => 'City',
            'state'     => 'State',
            'zip'       => 'Postal Code',
            'country'   => 'Country',
            'phone'     => 'Phone',
            'website'   => 'Website',
            'capacity'  => 'Capacity',
            'coordinates' => 'Coordinates'
        ];

        foreach ($fields as $key => $label) {
            $meta_key = "_venue_$key";
            $value = get_term_meta($term->term_id, $meta_key, true);
            echo '<tr class="form-field">';
            echo "<th scope='row'><label for='$meta_key'>$label</label></th>";
            echo "<td><input type='text' name='$meta_key' id='$meta_key' value='" . esc_attr($value) . "' class='regular-text' /></td>";
            echo '</tr>';
        }
    }

    public static function save_venue_meta($term_id) {
        $fields = ['address', 'city', 'state', 'zip', 'country', 'phone', 'website', 'capacity', 'coordinates'];
        
        foreach ($fields as $key) {
            $meta_key = "_venue_$key";
            if (isset($_POST[$meta_key])) {
                update_term_meta($term_id, $meta_key, sanitize_text_field($_POST[$meta_key]));
            }
        }
    }
    
}