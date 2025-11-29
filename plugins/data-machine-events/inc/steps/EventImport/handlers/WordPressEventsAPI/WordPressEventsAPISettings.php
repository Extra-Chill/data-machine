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
            'per_page' => [
                'type' => 'text',
                'label' => __('Results Per Page', 'datamachine-events'),
                'description' => __('Number of events to fetch per API request. Default: 50', 'datamachine-events'),
                'placeholder' => '50',
            ],
            'categories' => [
                'type' => 'text',
                'label' => __('Category Filter', 'datamachine-events'),
                'description' => __('Optional: Filter by category slugs (comma-separated). Leave empty for all categories.', 'datamachine-events'),
                'placeholder' => __('concerts,festivals', 'datamachine-events'),
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
            'per_page' => absint($raw_settings['per_page'] ?? 50),
            'categories' => sanitize_text_field($raw_settings['categories'] ?? ''),
            'search' => sanitize_text_field($raw_settings['search'] ?? ''),
            'exclude_keywords' => sanitize_text_field($raw_settings['exclude_keywords'] ?? ''),
        ];

        if (!empty($sanitized['endpoint_url']) && !filter_var($sanitized['endpoint_url'], FILTER_VALIDATE_URL)) {
            $sanitized['endpoint_url'] = '';
        }

        if ($sanitized['per_page'] < 1 || $sanitized['per_page'] > 100) {
            $sanitized['per_page'] = 50;
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
            'per_page' => 50,
            'categories' => '',
            'search' => '',
            'exclude_keywords' => '',
        ];
    }
}
