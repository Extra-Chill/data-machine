<?php
/**
 * Venue Taxonomy Registration and Management
 * 
 * Handles venue taxonomy registration with 9 meta fields and admin UI integration.
 *
 * @package DmEvents\Core
 */

namespace DmEvents\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Venue Taxonomy registration and meta field management
 */
class Venue_Taxonomy {
    
    /**
     * Maps venue data keys to term meta field names
     *
     * @var array
     */
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
    
    /**
     * Register venue taxonomy and initialize admin hooks
     */
    public static function register() {
        self::register_venue_taxonomy();
        
        self::register_all_public_taxonomies();
        
        self::init_admin_hooks();
    }
    
    /**
     * Register venue taxonomy with proper labels and settings
     */
    private static function register_venue_taxonomy() {
        if (taxonomy_exists('venue')) {
            register_taxonomy_for_object_type('venue', 'dm_events');
        } else {
            register_taxonomy('venue', array('post', 'dm_events'), array(
                'hierarchical' => false,
                'labels' => array(
                    'name' => _x('Venues', 'taxonomy general name', 'dm-events'),
                    'singular_name' => _x('Venue', 'taxonomy singular name', 'dm-events'),
                    'search_items' => __('Search Venues', 'dm-events'),
                    'all_items' => __('All Venues', 'dm-events'),
                    'edit_item' => __('Edit Venue', 'dm-events'),
                    'update_item' => __('Update Venue', 'dm-events'),
                    'add_new_item' => __('Add New Venue', 'dm-events'),
                    'new_item_name' => __('New Venue Name', 'dm-events'),
                    'menu_name' => __('Venues', 'dm-events'),
                ),
                'show_ui' => true,
                'show_admin_column' => true,
                'query_var' => true,
                'rewrite' => array('slug' => 'venue'),
                'show_in_rest' => true,
            ));
        }
        
        register_taxonomy_for_object_type('venue', 'dm_events');
    }
    
    /**
     * Register all public taxonomies for dm_events post type
     */
    private static function register_all_public_taxonomies() {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        
        if (!$taxonomies || is_wp_error($taxonomies)) {
            return;
        }
        
        foreach ($taxonomies as $taxonomy_slug) {
            if ($taxonomy_slug === 'venue') {
                continue;
            }
            
            register_taxonomy_for_object_type($taxonomy_slug, 'dm_events');
        }
    }
    
    /**
     * Update venue term meta with venue data
     *
     * @param int $term_id Venue term ID
     * @param array $venue_data Venue data array
     * @return bool Success status
     */
    public static function update_venue_meta($term_id, $venue_data) {
        if (!$term_id || !is_array($venue_data)) {
            return false;
        }
        
        foreach (self::$meta_fields as $data_key => $meta_key) {
            if (isset($venue_data[$data_key]) && !empty($venue_data[$data_key])) {
                update_term_meta($term_id, $meta_key, sanitize_text_field($venue_data[$data_key]));
            }
        }
        
        return true;
    }
    
    /**
     * Get complete venue data including term details and meta fields
     *
     * @param int $term_id Venue term ID
     * @return array Complete venue data
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
    
    /**
     * Get all venue terms with complete meta data
     *
     * @return array Array of venue data
     */
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
    
    /**
     * Get venues filtered by minimum event count
     *
     * @param int $min_events Minimum event count (default: 1)
     * @return array Array of venue data
     */
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
    
    /**
     * Extract city name from location string
     *
     * @param string $location_name Location string
     * @return string City name
     */
    private static function extract_city_from_location($location_name) {
        if (empty($location_name)) {
            return '';
        }
        
        $parts = explode(',', $location_name);
        return trim($parts[0]);
    }
    
    /**
     * Extract state code from location string
     *
     * @param string $location_name Location string
     * @return string State code
     */
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
    
    /**
     * Initialize admin hooks for venue meta field management
     */
    private static function init_admin_hooks() {
        add_action('venue_add_form_fields', [__CLASS__, 'add_venue_form_fields']);
        
        add_action('venue_edit_form_fields', [__CLASS__, 'edit_venue_form_fields']);
        
        add_action('created_venue', [__CLASS__, 'save_venue_meta']);
        
        add_action('edited_venue', [__CLASS__, 'save_venue_meta']);
    }
    
    /**
     * Add venue meta fields to "Add New Venue" form
     *
     * @param string $taxonomy Taxonomy slug
     */
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
    
    /**
     * Add venue meta fields to edit venue form
     *
     * @param WP_Term $term Term object
     */
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
    
    /**
     * Save venue meta fields when creating or updating venue terms
     *
     * @param int $term_id Term ID of the venue being saved
     */
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