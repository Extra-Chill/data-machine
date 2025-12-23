<?php
/**
 * Ticketbud Event Import Handler Settings
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\Ticketbud
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\Ticketbud;

if (!defined('ABSPATH')) {
    exit;
}

class TicketbudSettings {

    public function __construct() {
    }

    public static function get_fields(array $current_config = []): array {
        return [
            'include_over' => [
                'type' => 'checkbox',
                'label' => __('Include Past/Over Events', 'datamachine-events'),
                'description' => __('Include events marked as over by Ticketbud. Past events may still be skipped by the global past-event rule.', 'datamachine-events'),
                'value' => $current_config['include_over'] ?? false,
                'default' => false,
            ],
            'search' => [
                'type' => 'text',
                'label' => __('Include Keywords', 'datamachine-events'),
                'description' => __('Only import events containing any of these keywords (comma-separated). Leave empty to import all.', 'datamachine-events'),
                'placeholder' => __('concert, live music, band', 'datamachine-events'),
                'required' => false,
            ],
            'exclude_keywords' => [
                'type' => 'text',
                'label' => __('Exclude Keywords', 'datamachine-events'),
                'description' => __('Skip events containing any of these keywords (comma-separated).', 'datamachine-events'),
                'placeholder' => __('trivia, karaoke, brunch, bingo', 'datamachine-events'),
                'required' => false,
            ],
        ];
    }

    public static function sanitize(array $raw_settings): array {
        return [
            'include_over' => !empty($raw_settings['include_over']),
            'search' => sanitize_text_field($raw_settings['search'] ?? ''),
            'exclude_keywords' => sanitize_text_field($raw_settings['exclude_keywords'] ?? ''),
        ];
    }

    public static function requires_authentication(array $current_config = []): bool {
        return true;
    }

    public static function get_defaults(): array {
        return [
            'include_over' => false,
            'search' => '',
            'exclude_keywords' => '',
        ];
    }
}
