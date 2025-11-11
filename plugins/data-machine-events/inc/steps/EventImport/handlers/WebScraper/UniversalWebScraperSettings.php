<?php
/**
 * Universal Web Scraper Settings
 * 
 * Simple settings class with just URL field - no dropdown complexity.
 * Clean and direct configuration for AI-powered web scraping.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * UniversalWebScraperSettings class
 * 
 * Simple configuration for universal AI-powered web scraping.
 */
class UniversalWebScraperSettings {
    
    /**
     * Get settings fields for Universal Web Scraper handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        // Get all venues for dropdown
        $all_venues = \DataMachineEvents\Core\Venue_Taxonomy::get_all_venues();

        // Sort venues alphabetically by name
        usort($all_venues, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // Build options array with "Create New" at top
        $venue_options = ['' => '-- Create New Venue --'];
        foreach ($all_venues as $venue) {
            $venue_options[$venue['term_id']] = $venue['name'];
        }

        return [
            'source_url' => [
                'type' => 'url',
                'label' => __('Website URL', 'datamachine-events'),
                'description' => __('URL of the webpage containing events. The AI will analyze the page and extract event information automatically.', 'datamachine-events'),
                'placeholder' => 'https://venue.com/events',
                'required' => true,
            ],
            'venue' => [
                'type' => 'select',
                'label' => __('Venue', 'datamachine-events'),
                'description' => __('Select an existing venue to edit its details, or choose "Create New Venue" to add a new venue.', 'datamachine-events'),
                'options' => $venue_options,
                'required' => false,
                'attributes' => [
                    'class' => 'venue-selector',
                    'data-venue-selector' => 'true',
                ],
            ],
            'venue_name' => [
                'type' => 'text',
                'label' => __('Venue Name', 'datamachine-events'),
                'description' => __('Required when creating a new venue. Leave blank to let AI extract from page content.', 'datamachine-events'),
                'placeholder' => 'The Royal American',
                'required' => false,
            ],
            'venue_address' => [
                'type' => 'text',
                'label' => __('Venue Address', 'datamachine-events'),
                'description' => __('Leave blank to let AI extract venue address from page content. Start typing to see address suggestions.', 'datamachine-events'),
                'placeholder' => '970 Morrison Drive',
                'required' => false,
                'attributes' => [
                    'class' => 'venue-address-autocomplete',
                    'data-city-field' => 'venue_city',
                    'data-state-field' => 'venue_state',
                    'data-zip-field' => 'venue_zip',
                    'data-country-field' => 'venue_country',
                    'data-coords-field' => 'venue_coordinates',
                ],
            ],
            'venue_city' => [
                'type' => 'text',
                'label' => __('Venue City', 'datamachine-events'),
                'description' => __('Leave blank to let AI extract venue city from page content.', 'datamachine-events'),
                'placeholder' => 'Charleston',
                'required' => false,
            ],
            'venue_state' => [
                'type' => 'text',
                'label' => __('Venue State', 'datamachine-events'),
                'description' => __('Leave blank to let AI extract venue state from page content.', 'datamachine-events'),
                'placeholder' => 'SC',
                'required' => false,
            ],
            'venue_zip' => [
                'type' => 'text',
                'label' => __('Venue Zip Code', 'datamachine-events'),
                'description' => __('Leave blank to let AI extract venue zip code from page content.', 'datamachine-events'),
                'placeholder' => '29403',
                'required' => false,
            ],
            'venue_country' => [
                'type' => 'text',
                'label' => __('Venue Country', 'datamachine-events'),
                'description' => __('Leave blank to let AI extract venue country from page content.', 'datamachine-events'),
                'placeholder' => 'US',
                'required' => false,
            ],
            'venue_phone' => [
                'type' => 'text',
                'label' => __('Venue Phone', 'datamachine-events'),
                'description' => __('Leave blank to let AI extract venue phone from page content.', 'datamachine-events'),
                'placeholder' => '(843) 817-6925',
                'required' => false,
            ],
            'venue_website' => [
                'type' => 'url',
                'label' => __('Venue Website', 'datamachine-events'),
                'description' => __('Leave blank to let AI extract venue website from page content.', 'datamachine-events'),
                'placeholder' => 'https://www.theroyalamerican.com',
                'required' => false,
            ],
            'venue_coordinates' => [
                'type' => 'text',
                'label' => __('Venue Coordinates', 'datamachine-events'),
                'description' => __('Leave blank to let AI extract venue coordinates from page content. Format: latitude,longitude', 'datamachine-events'),
                'placeholder' => '32.7900,-79.9400',
                'required' => false,
            ],
            'venue_capacity' => [
                'type' => 'number',
                'label' => __('Venue Capacity', 'datamachine-events'),
                'description' => __('Leave blank to let AI extract venue capacity from page content.', 'datamachine-events'),
                'placeholder' => '500',
                'required' => false,
            ]
        ];
    }
    
    /**
     * Sanitize Universal Web Scraper handler settings
     *
     * This method sanitizes the raw input, then passes it to save_settings()
     * which handles venue creation/update before returning the final settings
     * that Data Machine will persist to the database.
     *
     * @param array $raw_settings Raw settings input
     * @return array Sanitized settings (with venue creation/update applied)
     */
    public static function sanitize(array $raw_settings): array {
        $sanitized = [
            'source_url' => esc_url_raw($raw_settings['source_url'] ?? ''),
            'venue' => sanitize_text_field($raw_settings['venue'] ?? ''),
            'venue_name' => sanitize_text_field($raw_settings['venue_name'] ?? ''),
            'venue_address' => sanitize_text_field($raw_settings['venue_address'] ?? ''),
            'venue_city' => sanitize_text_field($raw_settings['venue_city'] ?? ''),
            'venue_state' => sanitize_text_field($raw_settings['venue_state'] ?? ''),
            'venue_zip' => sanitize_text_field($raw_settings['venue_zip'] ?? ''),
            'venue_country' => sanitize_text_field($raw_settings['venue_country'] ?? ''),
            'venue_phone' => sanitize_text_field($raw_settings['venue_phone'] ?? ''),
            'venue_website' => esc_url_raw($raw_settings['venue_website'] ?? ''),
            'venue_coordinates' => sanitize_text_field($raw_settings['venue_coordinates'] ?? ''),
            'venue_capacity' => !empty($raw_settings['venue_capacity']) ? absint($raw_settings['venue_capacity']) : ''
        ];

        // Create/update venue taxonomy and clean up settings before Data Machine stores them
        return self::save_settings($sanitized);
    }

    /**
     * Save settings with venue creation/update logic
     *
     * This method creates or updates venue taxonomy immediately when settings are saved,
     * then stores only the venue term_id in the configuration.
     *
     * @param array $settings Sanitized settings array
     * @return array Modified settings with venue term_id
     */
    public static function save_settings(array $settings): array {
        $venue_term_id = $settings['venue'] ?? '';

        // Collect venue metadata from form
        $submitted_data = [
            'address' => $settings['venue_address'] ?? '',
            'city' => $settings['venue_city'] ?? '',
            'state' => $settings['venue_state'] ?? '',
            'zip' => $settings['venue_zip'] ?? '',
            'country' => $settings['venue_country'] ?? '',
            'phone' => $settings['venue_phone'] ?? '',
            'website' => $settings['venue_website'] ?? '',
            'coordinates' => $settings['venue_coordinates'] ?? '',
            'capacity' => $settings['venue_capacity'] ?? ''
        ];

        if (empty($venue_term_id)) {
            // Creating new venue
            $venue_name = $settings['venue_name'] ?? '';

            if (!empty($venue_name)) {
                $result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue($venue_name, $submitted_data);
                $venue_term_id = $result['term_id'];
            }
        } else {
            // Updating existing venue - only changed fields
            $original_data = \DataMachineEvents\Core\Venue_Taxonomy::get_venue_data($venue_term_id);
            $changed_fields = [];

            foreach ($submitted_data as $key => $value) {
                $original_value = $original_data[$key] ?? '';
                // Normalize for comparison (trim whitespace)
                if (trim($original_value) !== trim($value)) {
                    $changed_fields[$key] = $value;
                }
            }

            if (!empty($changed_fields)) {
                \DataMachineEvents\Core\Venue_Taxonomy::update_venue_meta($venue_term_id, $changed_fields);
            }
        }

        // Store only term_id in settings
        $settings['venue'] = $venue_term_id;

        // Remove metadata fields from settings (no longer needed)
        unset(
            $settings['venue_name'],
            $settings['venue_address'],
            $settings['venue_city'],
            $settings['venue_state'],
            $settings['venue_zip'],
            $settings['venue_country'],
            $settings['venue_phone'],
            $settings['venue_website'],
            $settings['venue_coordinates'],
            $settings['venue_capacity']
        );

        return $settings;
    }
    
    /**
     * Universal Web Scraper doesn't require authentication
     *
     * @param array $current_config Current configuration values
     * @return bool Always false - no authentication required
     */
    public static function requires_authentication(array $current_config = []): bool {
        return false;
    }
    
    /**
     * Get default values for all settings
     *
     * @return array Default values
     */
    public static function get_defaults(): array {
        return [
            'source_url' => '',
            'venue' => '',
            'venue_name' => '',
            'venue_address' => '',
            'venue_city' => '',
            'venue_state' => '',
            'venue_zip' => '',
            'venue_country' => '',
            'venue_phone' => '',
            'venue_website' => '',
            'venue_coordinates' => '',
            'venue_capacity' => ''
        ];
    }
}