<?php
/**
 * Venue Term Meta Operations
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents\Events\Venues;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles venue term meta operations for storing and retrieving venue data
 * 
 * @since 1.0.0
 */
class Venue_Term_Meta {
    
    /**
     * Meta field mappings for venue data
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
        'coordinates' => '_venue_coordinates',
        'description' => '_venue_description'
    ];
    
    /**
     * Update venue term meta with data from standardized event
     * 
     * @param int $term_id Venue term ID
     * @param \ChillEvents\Events\StandardizedEvent $standardized_event Event data
     * @return bool True on success, false on failure
     */
    public static function update_venue_meta($term_id, $standardized_event) {
        if (!$term_id || !$standardized_event) {
            return false;
        }
        
        // Extract venue data from standardized event
        $venue_data = [
            'address' => $standardized_event->get('address'),
            'city' => self::extract_city_from_location($standardized_event->get('location_name')),
            'state' => self::extract_state_from_location($standardized_event->get('location_name')),
            'phone' => $standardized_event->get('venue_phone'),
            'website' => $standardized_event->get('venue_website'),
        ];
        
        // Store each field as term meta
        foreach (self::$meta_fields as $data_key => $meta_key) {
            if (isset($venue_data[$data_key]) && !empty($venue_data[$data_key])) {
                update_term_meta($term_id, $meta_key, sanitize_text_field($venue_data[$data_key]));
            }
        }
        
        return true;
    }
    
    /**
     * Get complete venue data by term ID
     * 
     * @param int $term_id Venue term ID
     * @return array Venue data array
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
        ];
        
        // Get all term meta fields
        foreach (self::$meta_fields as $data_key => $meta_key) {
            $venue_data[$data_key] = get_term_meta($term_id, $meta_key, true);
        }
        
        return $venue_data;
    }
    
    /**
     * Get venue address as formatted string
     * 
     * @param int $term_id Venue term ID
     * @return string Formatted address
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
     * Extract city from location string
     * 
     * @param string $location_name Location string (e.g., "Charleston, SC")
     * @return string City name
     */
    private static function extract_city_from_location($location_name) {
        if (empty($location_name)) {
            return '';
        }
        
        // Split by comma and take first part
        $parts = explode(',', $location_name);
        return trim($parts[0]);
    }
    
    /**
     * Extract state from location string
     * 
     * @param string $location_name Location string (e.g., "Charleston, SC")
     * @return string State name
     */
    private static function extract_state_from_location($location_name) {
        if (empty($location_name)) {
            return '';
        }
        
        // Split by comma and take second part
        $parts = explode(',', $location_name);
        if (count($parts) > 1) {
            return trim($parts[1]);
        }
        
        return '';
    }
    
    /**
     * Get all venue terms with their data
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
     * Get venues by event count
     * 
     * @param int $min_events Minimum number of events
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
}

// Admin UI: Add custom fields to the Add Venue form
add_action('venue_add_form_fields', function($taxonomy) {
    $fields = [
        'address'   => 'Address',
        'city'      => 'City',
        'state'     => 'State',
        'zip'       => 'Postal Code',
        'country'   => 'Country',
        'phone'     => 'Phone',
        'website'   => 'Website',
        'capacity'  => 'Capacity',
        'coordinates' => 'Coordinates',
        'description' => 'Description'
    ];
    foreach ($fields as $key => $label) {
        $meta_key = "_venue_$key";
        echo '<div class="form-field">';
        echo "<label for='$meta_key'>$label</label>";
        echo "<input type='text' name='$meta_key' id='$meta_key' value='' class='regular-text' />";
        echo '</div>';
    }
});

// Admin UI: Add custom fields to the Edit Venue form
add_action('venue_edit_form_fields', function($term) {
    $fields = [
        'address'   => 'Address',
        'city'      => 'City',
        'state'     => 'State',
        'zip'       => 'Postal Code',
        'country'   => 'Country',
        'phone'     => 'Phone',
        'website'   => 'Website',
        'capacity'  => 'Capacity',
        'coordinates' => 'Coordinates',
        'description' => 'Description'
    ];
    foreach ($fields as $key => $label) {
        $meta_key = "_venue_$key";
        $value = get_term_meta($term->term_id, $meta_key, true);
        echo '<tr class="form-field">';
        echo "<th scope='row'><label for='$meta_key'>$label</label></th>";
        echo "<td><input type='text' name='$meta_key' id='$meta_key' value='" . esc_attr($value) . "' class='regular-text' /></td>";
        echo '</tr>';
    }
});

// Save custom fields when a venue is created
add_action('created_venue', function($term_id) {
    $fields = ['address','city','state','zip','country','phone','website','capacity','coordinates','description'];
    foreach ($fields as $key) {
        $meta_key = "_venue_$key";
        if (isset($_POST[$meta_key])) {
            update_term_meta($term_id, $meta_key, sanitize_text_field($_POST[$meta_key]));
        }
    }
});

// Save custom fields when a venue is updated
add_action('edited_venue', function($term_id) {
    $fields = ['address','city','state','zip','country','phone','website','capacity','coordinates','description'];
    foreach ($fields as $key) {
        $meta_key = "_venue_$key";
        if (isset($_POST[$meta_key])) {
            update_term_meta($term_id, $meta_key, sanitize_text_field($_POST[$meta_key]));
        }
    }
}); 