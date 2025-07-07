<?php
/**
 * Base Data Source class for Chill Events
 *
 * @package ChillEvents
 * @since 1.0.0
 */

namespace ChillEvents;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract BaseDataSource class
 *
 * All data sources (APIs, scrapers, manual, etc.) must extend this class.
 *
 * @since 1.0.0
 */
abstract class BaseDataSource {
    /**
     * Get info about this data source
     *
     * @return array
     *   - name: string
     *   - type: string (api, scraper, manual, etc.)
     *   - description: string
     *   - settings_fields: array (fields required for configuration)
     */
    abstract public function get_info();

    /**
     * Get events from this data source
     *
     * @param array $settings Data source settings (from Import Module)
     * @return array Array of standardized event data
     */
    abstract public function get_events($settings = array());

    /**
     * Standardized event data format
     *
     * @param array $raw_event Raw event data
     * @return array Sanitized event data
     */
    protected function sanitize_event_data($raw_event) {
        $sanitized = array();

        // Preserve event ID if present
        if (isset($raw_event['id'])) {
            $sanitized['id'] = sanitize_text_field($raw_event['id']);
        }

        // Required fields
        $sanitized['title'] = isset($raw_event['title']) ? sanitize_text_field($raw_event['title']) : '';
        $sanitized['start_date'] = isset($raw_event['start_date']) ? sanitize_text_field($raw_event['start_date']) : '';

        // Optional fields
        $optional_fields = array(
            'end_date', 'venue_name', 'location_name', 'artist_name', 
            'price', 'ticket_url', 'description', 'image_url'
        );

        foreach ($optional_fields as $field) {
            if (isset($raw_event[$field])) {
                if ($field === 'description') {
                    // Only process description if it has content
                    if (!empty(trim($raw_event[$field]))) {
                        $sanitized[$field] = wp_kses_post($raw_event[$field]);
                    } else {
                        $sanitized[$field] = '';
                    }
                } elseif ($field === 'ticket_url' || $field === 'image_url') {
                    $sanitized[$field] = esc_url_raw($raw_event[$field]);
                } else {
                    $sanitized[$field] = sanitize_text_field($raw_event[$field]);
                }
            }
        }

        return $sanitized;
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     * @param array $context Additional context data
     * @since 1.0.0
     */
    protected function log_error($message, $context = array()) {
        $log = '[ChillEvents][DataSource][' . static::class . '] ' . $message;
        if (!empty($context)) {
            $log .= ' | Context: ' . wp_json_encode($context);
        }
        error_log($log);
    }

    /**
     * Standardize event data (public interface)
     *
     * @param array $raw_event Raw event data
     * @return array Sanitized event data
     */
    public function standardize_event_data($raw_event) {
        return $this->sanitize_event_data($raw_event);
    }
} 