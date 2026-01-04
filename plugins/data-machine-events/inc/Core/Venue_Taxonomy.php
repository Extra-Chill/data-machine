<?php
/**
 * Venue Taxonomy Registration and Management
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

use DataMachine\Core\HttpClient;
use DataMachineEvents\Core\GeoNamesService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Comprehensive venue taxonomy with 9 meta fields and admin UI
 */
class Venue_Taxonomy {

    private const NOMINATIM_API = 'https://nominatim.openstreetmap.org/search';
    private const NOMINATIM_USER_AGENT = 'DataMachineEvents/1.0 (https://extrachill.com)';
    
    public static $meta_fields = [
        'address' => '_venue_address',
        'city' => '_venue_city',
        'state' => '_venue_state',
        'zip' => '_venue_zip',
        'country' => '_venue_country',
        'phone' => '_venue_phone',
        'website' => '_venue_website',
        'capacity' => '_venue_capacity',
        'coordinates' => '_venue_coordinates',
        'timezone' => '_venue_timezone'
    ];
    
    private static $field_labels = [
        'address' => 'Address',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'Postal Code',
        'country' => 'Country',
        'phone' => 'Phone',
        'website' => 'Website',
        'capacity' => 'Capacity',
        'coordinates' => 'Coordinates',
        'timezone' => 'Timezone'
    ];
    
    public static function register() {
        self::register_venue_taxonomy();
        
        self::init_admin_hooks();
    }
    
    private static function register_venue_taxonomy() {
        if (taxonomy_exists('venue')) {
            register_taxonomy_for_object_type('venue', Event_Post_Type::POST_TYPE);
        } else {
            register_taxonomy('venue', array('post', Event_Post_Type::POST_TYPE), array(
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
        
        register_taxonomy_for_object_type('venue', Event_Post_Type::POST_TYPE);
    }
    
    /**
     * Find or create a venue with given name and metadata
     *
     * @param string $venue_name Venue name
     * @param array $venue_data Venue metadata (address, city, state, etc.)
     * @return array Array with keys: term_id, was_created
     */
    public static function find_or_create_venue($venue_name, $venue_data = []) {
        // Address-based matching (source of truth)
        $address = $venue_data['address'] ?? '';
        $city = $venue_data['city'] ?? '';
        
        $address_match = self::find_venue_by_address($address, $city);
        if ($address_match) {
            if (!empty($venue_data)) {
                self::smart_merge_venue_meta($address_match, $venue_data);
            }
            
            return [
                'term_id' => $address_match,
                'was_created' => false
            ];
        }

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
        $address_fields = ['address', 'city', 'state', 'zip', 'country'];
        $address_updated = false;

        foreach (self::$meta_fields as $data_key => $meta_key) {
            if (empty($venue_data[$data_key])) {
                continue;
            }

            $existing_value = get_term_meta($term_id, $meta_key, true);

            if (empty($existing_value)) {
                update_term_meta($term_id, $meta_key, sanitize_text_field($venue_data[$data_key]));
                
                if (in_array($data_key, $address_fields, true)) {
                    $address_updated = true;
                }
            }
        }

        if ($address_updated) {
            self::maybe_geocode_venue($term_id);
        }
    }

    /**
     * Update venue term meta with venue data
     *
     * Supports selective updates - only updates fields present in $venue_data array.
     * This allows updating only changed fields without overwriting unchanged ones.
     * Automatically geocodes address to coordinates if address fields are updated.
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
                update_term_meta($term_id, $meta_key, sanitize_text_field($venue_data[$data_key]));
            }
        }

        // Geocode if address fields were updated and coordinates are empty
        $address_fields = ['address', 'city', 'state', 'zip', 'country'];
        $address_updated = !empty(array_intersect($address_fields, array_keys($venue_data)));
        
        if ($address_updated) {
            self::maybe_geocode_venue($term_id);
        }

        return true;
    }

    /**
     * Geocode venue address if coordinates are missing
     *
     * @param int $term_id Venue term ID
     * @return bool True if geocoding was performed, false otherwise
     */
    public static function maybe_geocode_venue($term_id) {
        if (!$term_id) {
            return false;
        }

        $existing_coords = get_term_meta($term_id, '_venue_coordinates', true);
        if (!empty($existing_coords)) {
            self::maybe_derive_timezone($term_id, $existing_coords);
            return false;
        }

        $venue_data = self::get_venue_data($term_id);
        $coordinates = self::geocode_address($venue_data);

        if ($coordinates) {
            update_term_meta($term_id, '_venue_coordinates', $coordinates);
            self::maybe_derive_timezone($term_id, $coordinates);
            return true;
        }

        return false;
    }

    /**
     * Derive timezone from coordinates if timezone is missing
     *
     * Uses GeoNames API to lookup IANA timezone from lat/lng coordinates.
     * Only runs if GeoNames username is configured in settings.
     *
     * @param int $term_id Venue term ID
     * @param string $coordinates Coordinates as "lat,lng"
     * @return bool True if timezone was derived and saved, false otherwise
     */
    public static function maybe_derive_timezone($term_id, $coordinates = '') {
        if (!$term_id) {
            return false;
        }

        $existing_timezone = get_term_meta($term_id, '_venue_timezone', true);
        if (!empty($existing_timezone)) {
            return false;
        }

        if (empty($coordinates)) {
            $coordinates = get_term_meta($term_id, '_venue_coordinates', true);
        }

        if (empty($coordinates)) {
            return false;
        }

        if (!GeoNamesService::isConfigured()) {
            return false;
        }

        $timezone = GeoNamesService::getTimezoneFromCoordinates($coordinates);

        if ($timezone) {
            update_term_meta($term_id, '_venue_timezone', $timezone);
            return true;
        }

        return false;
    }

    /**
     * Backfill timezones for venues with coordinates but no timezone.
     *
     * Processes venues in batches for REST API pagination support.
     * Geocodes venues without coordinates, then derives timezone from coordinates.
     *
     * @param int $batch_size Number of venues to process (0 = all)
     * @param int $offset Starting position for batch processing
     * @return array{processed: int, geocoded: int, timezones_derived: int, errors: int, has_more: bool, next_offset: int}
     */
    public static function backfill_venue_timezones(int $batch_size = 0, int $offset = 0): array {
        $result = [
            'processed' => 0,
            'geocoded' => 0,
            'timezones_derived' => 0,
            'errors' => 0,
            'has_more' => false,
            'next_offset' => 0,
        ];

        if (!GeoNamesService::isConfigured()) {
            return $result;
        }

        $args = [
            'taxonomy' => 'venue',
            'hide_empty' => false,
            'offset' => $offset,
        ];

        if ($batch_size > 0) {
            $args['number'] = $batch_size;
        }

        $venues = get_terms($args);

        if (is_wp_error($venues) || empty($venues)) {
            return $result;
        }

        foreach ($venues as $venue) {
            $result['processed']++;

            $coordinates = get_term_meta($venue->term_id, '_venue_coordinates', true);

            if (empty($coordinates)) {
                if (self::maybe_geocode_venue($venue->term_id)) {
                    $result['geocoded']++;
                    $coordinates = get_term_meta($venue->term_id, '_venue_coordinates', true);
                }
            }

            if (empty($coordinates)) {
                continue;
            }

            $timezone = get_term_meta($venue->term_id, '_venue_timezone', true);
            if (!empty($timezone)) {
                continue;
            }

            if (self::maybe_derive_timezone($venue->term_id, $coordinates)) {
                $result['timezones_derived']++;
            } else {
                $result['errors']++;
            }
        }

        if ($batch_size > 0) {
            $total_count = wp_count_terms(['taxonomy' => 'venue', 'hide_empty' => false]);
            $next_offset = $offset + $batch_size;
            $result['has_more'] = $next_offset < $total_count;
            $result['next_offset'] = $next_offset;
        }

        return $result;
    }

    /**
     * Get count of venues that need timezone backfill.
     *
     * @return array{total: int, with_coordinates: int, with_timezone: int, needs_timezone: int}
     */
    public static function get_backfill_stats(): array {
        $stats = [
            'total' => 0,
            'with_coordinates' => 0,
            'with_timezone' => 0,
            'needs_timezone' => 0,
        ];

        $venues = get_terms([
            'taxonomy' => 'venue',
            'hide_empty' => false,
        ]);

        if (is_wp_error($venues) || empty($venues)) {
            return $stats;
        }

        $stats['total'] = count($venues);

        foreach ($venues as $venue) {
            $coordinates = get_term_meta($venue->term_id, '_venue_coordinates', true);
            $timezone = get_term_meta($venue->term_id, '_venue_timezone', true);

            if (!empty($coordinates)) {
                $stats['with_coordinates']++;
            }

            if (!empty($timezone)) {
                $stats['with_timezone']++;
            }

            if (!empty($coordinates) && empty($timezone)) {
                $stats['needs_timezone']++;
            }
        }

        return $stats;
    }

    /**
     * Geocode an address using Nominatim API
     *
     * @param array $venue_data Venue data with address fields
     * @return string|null Coordinates as "lat,lng" or null on failure
     */
    public static function geocode_address($venue_data) {
        $query_parts = [];

        if (!empty($venue_data['address'])) {
            $query_parts[] = $venue_data['address'];
        }
        if (!empty($venue_data['city'])) {
            $query_parts[] = $venue_data['city'];
        }
        if (!empty($venue_data['state'])) {
            $query_parts[] = $venue_data['state'];
        }
        if (!empty($venue_data['zip'])) {
            $query_parts[] = $venue_data['zip'];
        }
        if (!empty($venue_data['country'])) {
            $query_parts[] = $venue_data['country'];
        }

        if (empty($query_parts)) {
            return null;
        }

        $query = implode(', ', $query_parts);

        $url = add_query_arg([
            'format' => 'json',
            'limit' => 1,
            'q' => $query
        ], self::NOMINATIM_API);

        $result = HttpClient::get($url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => self::NOMINATIM_USER_AGENT,
            ],
            'context' => 'Venue Geocoding',
        ]);

        if (!$result['success']) {
            error_log('DM Events Geocoding Error: ' . ($result['error'] ?? 'Unknown error'));
            return null;
        }

        $body = $result['data'];
        $data = json_decode($body, true);

        if (empty($data) || !is_array($data) || empty($data[0])) {
            return null;
        }

        $result = $data[0];

        if (isset($result['lat']) && isset($result['lon'])) {
            return $result['lat'] . ',' . $result['lon'];
        }

        return null;
    }

    /**
     * Normalize address string for consistent comparison
     *
     * @param string $address Raw address string
     * @return string Normalized address for matching
     */
    private static function normalize_address_for_matching(string $address): string {
        $address = strtolower(trim($address));
        
        $replacements = [
            '/\bstreet\b/' => 'st',
            '/\bavenue\b/' => 'ave',
            '/\bboulevard\b/' => 'blvd',
            '/\bdrive\b/' => 'dr',
            '/\broad\b/' => 'rd',
            '/\blane\b/' => 'ln',
            '/\bcourt\b/' => 'ct',
            '/\bsuite\b/' => 'ste',
            '/\bapartment\b/' => 'apt',
            '/\bhighway\b/' => 'hwy',
            '/\bparkway\b/' => 'pkwy',
            '/\bplace\b/' => 'pl',
            '/\bcircle\b/' => 'cir',
            '/[.,#]/' => '',
        ];
        
        foreach ($replacements as $pattern => $replacement) {
            $address = preg_replace($pattern, $replacement, $address);
        }
        
        return preg_replace('/\s+/', ' ', trim($address));
    }

    /**
     * Find existing venue by address and city
     *
     * @param string $address Street address
     * @param string $city City name
     * @return int|null Term ID if found, null otherwise
     */
    public static function find_venue_by_address(string $address, string $city): ?int {
        if (empty($address) || empty($city)) {
            return null;
        }
        
        $normalized_address = self::normalize_address_for_matching($address);
        $normalized_city = strtolower(trim($city));
        
        $venues = get_terms([
            'taxonomy' => 'venue',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key' => '_venue_city',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        if (is_wp_error($venues) || empty($venues)) {
            return null;
        }
        
        foreach ($venues as $venue) {
            $venue_address = get_term_meta($venue->term_id, '_venue_address', true);
            $venue_city = get_term_meta($venue->term_id, '_venue_city', true);
            
            if (empty($venue_address) || empty($venue_city)) {
                continue;
            }
            
            $venue_normalized_address = self::normalize_address_for_matching($venue_address);
            $venue_normalized_city = strtolower(trim($venue_city));
            
            if ($venue_normalized_address === $normalized_address && 
                $venue_normalized_city === $normalized_city) {
                return $venue->term_id;
            }
        }
        
        return null;
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
        foreach (self::$meta_fields as $key => $meta_key) {
            $label = self::$field_labels[$key] ?? ucfirst($key);
            echo '<div class="form-field">';
            echo "<label for='$meta_key'>$label</label>";
            echo "<input type='text' name='$meta_key' id='$meta_key' value='' class='regular-text' />";
            echo '</div>';
        }
    }

    public static function edit_venue_form_fields($term) {
        foreach (self::$meta_fields as $key => $meta_key) {
            $label = self::$field_labels[$key] ?? ucfirst($key);
            $value = get_term_meta($term->term_id, $meta_key, true);
            echo '<tr class="form-field">';
            echo "<th scope='row'><label for='$meta_key'>$label</label></th>";
            echo "<td><input type='text' name='$meta_key' id='$meta_key' value='" . esc_attr($value) . "' class='regular-text' /></td>";
            echo '</tr>';
        }
    }

    public static function save_venue_meta($term_id) {
        foreach (self::$meta_fields as $key => $meta_key) {
            if (isset($_POST[$meta_key])) {
                update_term_meta($term_id, $meta_key, sanitize_text_field($_POST[$meta_key]));
            }
        }
    }
    
}