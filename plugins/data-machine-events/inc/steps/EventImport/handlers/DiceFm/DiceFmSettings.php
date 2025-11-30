<?php
/**
 * Dice.fm Event Import Handler Settings
 * 
 * Defines settings fields and sanitization for Dice.fm event import handler.
 * Part of the modular handler architecture for Data Machine integration.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\DiceFm
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\DiceFm;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * DiceFmSettings class
 * 
 * Configuration fields for Dice.fm API event import.
 */
class DiceFmSettings {
    
    /**
     * Constructor
     * Pure filter-based architecture - no dependencies.
     */
    public function __construct() {
        // No constructor dependencies - all services accessed via filters
    }
    
    /**
     * Get settings fields for Dice.fm event import handler
     *
     * @param array $current_config Current configuration values for this handler
     * @return array Associative array defining the settings fields
     */
    public static function get_fields(array $current_config = []): array {
        return [
            'city' => [
                'type' => 'text',
                'label' => __('City', 'datamachine-events'),
                'description' => __('City name to search for events (required). This is the primary filter for Dice.fm API.', 'datamachine-events'),
                'placeholder' => __('Charleston', 'datamachine-events'),
                'required' => true,
            ]
        ];
    }
    
    /**
     * Sanitize Dice.fm handler settings.
     *
     * @param array $raw_settings Raw settings input.
     * @return array Sanitized settings.
     */
    public static function sanitize(array $raw_settings): array {
        return [
            'city' => sanitize_text_field($raw_settings['city'] ?? '')
        ];
    }
    
    /**
     * Determine if authentication is required.
     *
     * @param array $current_config Current configuration values.
     * @return bool True if authentication is required.
     */
    public static function requires_authentication(array $current_config = []): bool {
        return true; // Dice.fm requires API key authentication
    }
    
    /**
     * Get default values for all settings.
     *
     * @return array Default values.
     */
    public static function get_defaults(): array {
        return [
            'city' => ''
        ];
    }
}