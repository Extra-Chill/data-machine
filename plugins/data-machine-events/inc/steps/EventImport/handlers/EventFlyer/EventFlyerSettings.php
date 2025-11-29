<?php
/**
 * Event Flyer Handler Settings
 *
 * Configuration fields for extracting event data from flyer images.
 * All fields follow the "fill OR AI extracts" pattern - if a field is
 * populated, that value is used; if blank, AI extracts from the image.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\EventFlyer
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\EventFlyer;

if (!defined('ABSPATH')) {
    exit;
}

class EventFlyerSettings {

    public static function get_fields(array $current_config = []): array {
        return [
            'title' => [
                'type' => 'text',
                'label' => __('Event Title', 'datamachine-events'),
                'description' => __('Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
            'venue' => [
                'type' => 'text',
                'label' => __('Venue', 'datamachine-events'),
                'description' => __('Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
            'venueAddress' => [
                'type' => 'text',
                'label' => __('Venue Address', 'datamachine-events'),
                'description' => __('Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
            'city' => [
                'type' => 'text',
                'label' => __('City', 'datamachine-events'),
                'description' => __('Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
            'state' => [
                'type' => 'text',
                'label' => __('State', 'datamachine-events'),
                'description' => __('Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
            'startDate' => [
                'type' => 'text',
                'label' => __('Start Date', 'datamachine-events'),
                'description' => __('YYYY-MM-DD format. Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
            'startTime' => [
                'type' => 'text',
                'label' => __('Start Time', 'datamachine-events'),
                'description' => __('HH:MM format. Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
            'endTime' => [
                'type' => 'text',
                'label' => __('End Time', 'datamachine-events'),
                'description' => __('HH:MM format. Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
            'price' => [
                'type' => 'text',
                'label' => __('Price', 'datamachine-events'),
                'description' => __('Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
            'ticketUrl' => [
                'type' => 'text',
                'label' => __('Ticket URL', 'datamachine-events'),
                'description' => __('Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
            'performer' => [
                'type' => 'text',
                'label' => __('Performer(s)', 'datamachine-events'),
                'description' => __('Supporting acts or additional performers. Leave blank for AI to extract from flyer.', 'datamachine-events'),
                'placeholder' => __('AI extracts from flyer', 'datamachine-events'),
            ],
        ];
    }

    public static function sanitize(array $raw_settings): array {
        return [
            'title' => sanitize_text_field($raw_settings['title'] ?? ''),
            'venue' => sanitize_text_field($raw_settings['venue'] ?? ''),
            'venueAddress' => sanitize_text_field($raw_settings['venueAddress'] ?? ''),
            'city' => sanitize_text_field($raw_settings['city'] ?? ''),
            'state' => sanitize_text_field($raw_settings['state'] ?? ''),
            'startDate' => sanitize_text_field($raw_settings['startDate'] ?? ''),
            'startTime' => sanitize_text_field($raw_settings['startTime'] ?? ''),
            'endTime' => sanitize_text_field($raw_settings['endTime'] ?? ''),
            'price' => sanitize_text_field($raw_settings['price'] ?? ''),
            'ticketUrl' => esc_url_raw($raw_settings['ticketUrl'] ?? ''),
            'performer' => sanitize_text_field($raw_settings['performer'] ?? ''),
        ];
    }

    public static function requires_authentication(array $current_config = []): bool {
        return false;
    }

    public static function get_defaults(): array {
        return [
            'title' => '',
            'venue' => '',
            'venueAddress' => '',
            'city' => '',
            'state' => '',
            'startDate' => '',
            'startTime' => '',
            'endTime' => '',
            'price' => '',
            'ticketUrl' => '',
            'performer' => '',
        ];
    }

    public static function get_ai_extraction_fields(): array {
        return [
            'title' => 'Event title or headliner name (usually the largest text on the flyer)',
            'venue' => 'Venue name where the event takes place',
            'venueAddress' => 'Street address of the venue',
            'city' => 'City name',
            'state' => 'State or province abbreviation (e.g., SC, NY, CA)',
            'startDate' => 'Event date in YYYY-MM-DD format',
            'startTime' => 'Event start time in HH:MM 24-hour format',
            'endTime' => 'Event end time in HH:MM 24-hour format (if visible)',
            'price' => 'Ticket price (e.g., "$20" or "$15 adv / $20 dos")',
            'ticketUrl' => 'Ticket purchase URL if visible on the flyer',
            'performer' => 'Supporting acts, opening bands, or additional performers',
            'description' => 'Any additional event details visible on the flyer (age restrictions, special notes, etc.)',
        ];
    }
}
