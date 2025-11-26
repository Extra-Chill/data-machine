<?php
/**
 * SpotHopper Event Import Handler Settings
 *
 * Defines settings fields and sanitization for SpotHopper event import handler.
 * SpotHopper uses a public API requiring only a spot_id parameter.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\SpotHopper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\SpotHopper;

if (!defined('ABSPATH')) {
    exit;
}

class SpotHopperSettings {

    public function __construct() {
    }

    /**
     * Get settings fields for SpotHopper event import handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        return [
            'spot_id' => [
                'type' => 'text',
                'label' => __('Spot ID', 'datamachine-events'),
                'description' => __('The SpotHopper spot ID for the venue. Find this in the venue\'s SpotHopper URL or admin panel.', 'datamachine-events'),
                'placeholder' => __('101982', 'datamachine-events'),
                'required' => true
            ],
            'venue_name_override' => [
                'type' => 'text',
                'label' => __('Venue Name Override', 'datamachine-events'),
                'description' => __('Optional: Use a custom venue name instead of the SpotHopper spot name. Useful for sub-venues like "The Rickhouse @ Cannon Distillery".', 'datamachine-events'),
                'placeholder' => __('The Rickhouse', 'datamachine-events'),
                'required' => false
            ],
            'search' => [
                'type' => 'text',
                'label' => __('Include Keywords', 'datamachine-events'),
                'description' => __('Only import events containing any of these keywords (comma-separated). Leave empty to import all.', 'datamachine-events'),
                'placeholder' => __('concert, live music, band', 'datamachine-events'),
                'required' => false
            ],
            'exclude_keywords' => [
                'type' => 'text',
                'label' => __('Exclude Keywords', 'datamachine-events'),
                'description' => __('Skip events containing any of these keywords (comma-separated).', 'datamachine-events'),
                'placeholder' => __('cornhole, trivia, karaoke', 'datamachine-events'),
                'required' => false
            ]
        ];
    }

    /**
     * Sanitize SpotHopper handler settings
     *
     * @param array $raw_settings Raw settings input
     * @return array Sanitized settings
     */
    public static function sanitize(array $raw_settings): array {
        return [
            'spot_id' => sanitize_text_field($raw_settings['spot_id'] ?? ''),
            'venue_name_override' => sanitize_text_field($raw_settings['venue_name_override'] ?? ''),
            'search' => sanitize_text_field($raw_settings['search'] ?? ''),
            'exclude_keywords' => sanitize_text_field($raw_settings['exclude_keywords'] ?? '')
        ];
    }

    /**
     * Determine if authentication is required
     *
     * @param array $current_config Current configuration values
     * @return bool True if authentication is required
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
            'spot_id' => '',
            'venue_name_override' => '',
            'search' => '',
            'exclude_keywords' => ''
        ];
    }
}
