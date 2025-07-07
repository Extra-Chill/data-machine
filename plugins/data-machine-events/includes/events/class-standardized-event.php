<?php
/**
 * Standardized Event class for Chill Events
 *
 * Defines the canonical event format for all data sources and import logic.
 *
 * @package ChillEvents\Events
 * @author Chris Huber
 * @link https://chubes.net
 * @since 1.0.0
 */

namespace ChillEvents\Events;

if (!defined('ABSPATH')) {
    exit;
}

class StandardizedEvent {
    // Required fields
    public $id = '';
    public $title = '';
    public $start_date = '';

    // Optional fields
    public $end_date = '';
    public $venue_name = '';
    public $address = '';
    public $location_name = '';
    public $venue_phone = '';
    public $venue_website = '';
    public $artist_name = '';
    public $price = '';
    public $ticket_url = '';
    public $description = '';
    public $image_url = '';
    // Add more fields as needed

    /**
     * Construct from array (raw or sanitized)
     */
    public function __construct($data = array()) {
        foreach ($this->get_fields() as $field) {
            if (isset($data[$field])) {
                $this->$field = $this->sanitize_field($field, $data[$field]);
            }
        }
    }

    /**
     * Get all canonical fields
     */
    public function get_fields() {
        return array(
            'id', 'title', 'start_date', 'end_date', 'venue_name', 'address',
            'location_name', 'venue_phone', 'venue_website', 'artist_name', 'price', 'ticket_url', 'description', 'image_url'
        );
    }

    /**
     * Sanitize a field value
     */
    public function sanitize_field($field, $value) {
        switch ($field) {
            case 'id':
            case 'title':
            case 'venue_name':
            case 'address':
            case 'location_name':
            case 'venue_phone':
            case 'artist_name':
            case 'price':
                return sanitize_text_field($value);
            case 'start_date':
            case 'end_date':
                return sanitize_text_field($value); // Could add datetime validation
            case 'ticket_url':
            case 'image_url':
            case 'venue_website':
                return esc_url_raw($value);
            case 'description':
                // Only process description if it has content
                if (!empty(trim($value))) {
                    return wp_kses_post($value);
                }
                return '';
            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Convert to array for storage or further processing
     */
    public function to_array() {
        $arr = array();
        foreach ($this->get_fields() as $field) {
            $arr[$field] = $this->$field;
        }
        return $arr;
    }

    /**
     * Get a field value
     */
    public function get($field) {
        return property_exists($this, $field) ? $this->$field : null;
    }

    /**
     * Set a field value (with sanitization)
     */
    public function set($field, $value) {
        if (in_array($field, $this->get_fields())) {
            $this->$field = $this->sanitize_field($field, $value);
        }
    }
} 