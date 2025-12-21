<?php
/**
 * WordPress Events API Handler Settings
 *
 * Configuration fields for importing events from external WordPress sites
 * running Tribe Events Calendar or similar event plugins.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WordPressEventsAPI
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WordPressEventsAPI;

if (!defined('ABSPATH')) {
    exit;
}

class WordPressEventsAPISettings {

    public static function get_fields(array $current_config = []): array {
        return [
            'endpoint_url' => [
                'type' => 'text',
                'label' => __('API Endpoint URL', 'datamachine-events'),
                'description' => __('Full REST API endpoint URL. For Tribe Events: https://example.com/wp-json/tribe/events/v1/events', 'datamachine-events'),
                'placeholder' => __('https://example.com/wp-json/tribe/events/v1/events', 'datamachine-events'),
                'required' => true,
            ],
            'venue_name_override' => [
                'type' => 'text',
                'label' => __('Venue Name Override', 'datamachine-events'),
                'description' => __('Consolidate all events under one venue name. Useful when a venue has multiple stages (e.g., "Main Stage", "Deck Stage") but you want one map pin.', 'datamachine-events'),
                'placeholder' => __('Charleston Pour House', 'datamachine-events'),
            ],
            'search' => [
                'type' => 'text',
                'label' => __('Include Keywords', 'datamachine-events'),
                'description' => __('Only import events containing any of these keywords (comma-separated).', 'datamachine-events'),
            ],
            'exclude_keywords' => [
                'type' => 'text',
                'label' => __('Exclude Keywords', 'datamachine-events'),
                'description' => __('Skip events containing any of these keywords (comma-separated).', 'datamachine-events'),
            ],
        ];
    }

    public static function sanitize(array $raw_settings): array {
        $sanitized = [
            'endpoint_url' => esc_url_raw(trim($raw_settings['endpoint_url'] ?? '')),
            'venue_name_override' => sanitize_text_field($raw_settings['venue_name_override'] ?? ''),
            'search' => sanitize_text_field($raw_settings['search'] ?? ''),
            'exclude_keywords' => sanitize_text_field($raw_settings['exclude_keywords'] ?? ''),
        ];

        if (!empty($sanitized['endpoint_url']) && !filter_var($sanitized['endpoint_url'], FILTER_VALIDATE_URL)) {
            $sanitized['endpoint_url'] = '';
        }

        return $sanitized;
    }

    public static function requires_authentication(array $current_config = []): bool {
        return false;
    }

    public static function get_defaults(): array {
        return [
            'endpoint_url' => '',
            'venue_name_override' => '',
            'search' => '',
            'exclude_keywords' => '',
        ];
    }
}
