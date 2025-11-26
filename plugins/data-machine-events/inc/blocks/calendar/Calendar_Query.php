<?php
/**
 * Calendar Query Builder
 *
 * Single source of truth for calendar event queries. Used by both render.php (initial load)
 * and Calendar REST controller (REST API filtering) to ensure consistent behavior.
 *
 * @package DataMachineEvents\Blocks\Calendar
 */

namespace DataMachineEvents\Blocks\Calendar;

use WP_Query;
use DateTime;
use DataMachineEvents\Core\Event_Post_Type;
use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;

if (!defined('ABSPATH')) {
    exit;
}

class Calendar_Query {

    /**
     * Build WP_Query arguments for calendar events
     *
     * @param array $params Query parameters
     * @return array WP_Query arguments
     */
    public static function build_query_args(array $params): array {
        $defaults = [
            'paged' => 1,
            'posts_per_page' => get_option('posts_per_page', 10),
            'show_past' => false,
            'search_query' => '',
            'date_start' => '',
            'date_end' => '',
            'tax_filters' => [],
            'tax_query_override' => null,
        ];

        $params = wp_parse_args($params, $defaults);

        $query_args = [
            'post_type' => Event_Post_Type::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => $params['posts_per_page'],
            'paged' => $params['paged'],
            'meta_key' => EVENT_DATETIME_META_KEY,
            'orderby' => 'meta_value',
            'order' => $params['show_past'] ? 'DESC' : 'ASC',
        ];

        $meta_query = ['relation' => 'AND'];
        $current_datetime = current_time('mysql');
        $has_date_range = !empty($params['date_start']) || !empty($params['date_end']);

        if ($params['show_past'] && !$has_date_range) {
            $meta_query[] = [
                'key' => EVENT_DATETIME_META_KEY,
                'value' => $current_datetime,
                'compare' => '<',
                'type' => 'DATETIME',
            ];
        } elseif (!$params['show_past'] && !$has_date_range) {
            $meta_query[] = [
                'key' => EVENT_DATETIME_META_KEY,
                'value' => $current_datetime,
                'compare' => '>=',
                'type' => 'DATETIME',
            ];
        }

        if (!empty($params['date_start'])) {
            $meta_query[] = [
                'key' => EVENT_DATETIME_META_KEY,
                'value' => $params['date_start'] . ' 00:00:00',
                'compare' => '>=',
                'type' => 'DATETIME',
            ];
        }

        if (!empty($params['date_end'])) {
            $meta_query[] = [
                'key' => EVENT_DATETIME_META_KEY,
                'value' => $params['date_end'] . ' 23:59:59',
                'compare' => '<=',
                'type' => 'DATETIME',
            ];
        }

        $query_args['meta_query'] = $meta_query;

        if ($params['tax_query_override']) {
            $query_args['tax_query'] = $params['tax_query_override'];
        }

        if (!empty($params['tax_filters']) && is_array($params['tax_filters'])) {
            $tax_query = isset($query_args['tax_query']) ? $query_args['tax_query'] : [];
            $tax_query['relation'] = 'AND';

            foreach ($params['tax_filters'] as $taxonomy => $term_ids) {
                $term_ids = is_array($term_ids) ? $term_ids : [$term_ids];
                $tax_query[] = [
                    'taxonomy' => sanitize_key($taxonomy),
                    'field' => 'term_id',
                    'terms' => array_map('absint', $term_ids),
                    'operator' => 'IN',
                ];
            }

            $query_args['tax_query'] = $tax_query;
        }

        if (!empty($params['search_query'])) {
            $query_args['s'] = $params['search_query'];
        }

        return apply_filters('datamachine_events_calendar_query_args', $query_args, $params);
    }

    /**
     * Get past and future event counts
     *
     * @return array ['past' => int, 'future' => int]
     */
    public static function get_event_counts(): array {
        $current_datetime = current_time('mysql');

        $future_query = new WP_Query([
            'post_type' => Event_Post_Type::POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => EVENT_DATETIME_META_KEY,
                    'value' => $current_datetime,
                    'compare' => '>=',
                    'type' => 'DATETIME',
                ],
            ],
        ]);

        $past_query = new WP_Query([
            'post_type' => Event_Post_Type::POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => EVENT_DATETIME_META_KEY,
                    'value' => $current_datetime,
                    'compare' => '<',
                    'type' => 'DATETIME',
                ],
            ],
        ]);

        return [
            'past' => $past_query->found_posts,
            'future' => $future_query->found_posts,
        ];
    }

    /**
     * Parse event data from post content block attributes
     *
     * @param \WP_Post $post Post object
     * @return array|null Event data array or null if not found
     */
    public static function parse_event_data(\WP_Post $post): ?array {
        $blocks = parse_blocks($post->post_content);
        $event_data = null;

        foreach ($blocks as $block) {
            if ('datamachine-events/event-details' === $block['blockName']) {
                $event_data = $block['attrs'];
                break;
            }
        }

        if (empty($event_data) || empty($event_data['startDate'])) {
            $meta_datetime = get_post_meta($post->ID, EVENT_DATETIME_META_KEY, true);
            if ($meta_datetime) {
                $event_data = is_array($event_data) ? $event_data : [];
                $event_data['startDate'] = date('Y-m-d', strtotime($meta_datetime));
                if (empty($event_data['startTime'])) {
                    $event_data['startTime'] = date('H:i:s', strtotime($meta_datetime));
                }
            }
        }

        return !empty($event_data['startDate']) ? $event_data : null;
    }

    /**
     * Build paged events array from WP_Query
     *
     * @param WP_Query $query Events query
     * @return array Array of event items with post, datetime, and event_data
     */
    public static function build_paged_events(WP_Query $query): array {
        $paged_events = [];

        if (!$query->have_posts()) {
            return $paged_events;
        }

        while ($query->have_posts()) {
            $query->the_post();
            $event_post = get_post();
            $event_data = self::parse_event_data($event_post);

            if ($event_data) {
                $start_time = $event_data['startTime'] ?? '00:00:00';
                $event_datetime = new DateTime(
                    $event_data['startDate'] . ' ' . $start_time,
                    wp_timezone()
                );

                $paged_events[] = [
                    'post' => $event_post,
                    'datetime' => $event_datetime,
                    'event_data' => $event_data,
                ];
            }
        }

        wp_reset_postdata();

        return $paged_events;
    }

    /**
     * Group events by date
     *
     * @param array $paged_events Array of event items
     * @param bool $show_past Whether showing past events (affects sort order)
     * @return array Date-grouped events
     */
    public static function group_events_by_date(array $paged_events, bool $show_past = false): array {
        $date_groups = [];

        foreach ($paged_events as $event_item) {
            $event_data = $event_item['event_data'];
            $start_date = $event_data['startDate'] ?? '';

            if (empty($start_date)) {
                continue;
            }

            $start_time = $event_data['startTime'] ?? '00:00:00';
            $start_datetime_obj = new DateTime($start_date . ' ' . $start_time, wp_timezone());
            $date_key = $start_datetime_obj->format('Y-m-d');

            if (!isset($date_groups[$date_key])) {
                $date_groups[$date_key] = [
                    'date_obj' => $start_datetime_obj,
                    'events' => [],
                ];
            }

            $date_groups[$date_key]['events'][] = $event_item;
        }

        uksort($date_groups, function ($a, $b) use ($show_past) {
            return $show_past ? strcmp($b, $a) : strcmp($a, $b);
        });

        return $date_groups;
    }

    /**
     * Build display variables for an event
     *
     * @param array $event_data Event data from block attributes
     * @return array Display variables
     */
    public static function build_display_vars(array $event_data): array {
        $start_date = $event_data['startDate'] ?? '';
        $start_time = $event_data['startTime'] ?? '';

        $formatted_start_time = '';
        $iso_start_date = '';

        if ($start_date) {
            $start_datetime_obj = new DateTime($start_date . ' ' . $start_time, wp_timezone());
            $formatted_start_time = $start_datetime_obj->format('g:i A');
            $iso_start_date = $start_datetime_obj->format('c');
        }

        return [
            'formatted_start_time' => $formatted_start_time,
            'venue_name' => self::decode_unicode($event_data['venue'] ?? ''),
            'performer_name' => self::decode_unicode($event_data['performer'] ?? ''),
            'iso_start_date' => $iso_start_date,
            'show_performer' => $event_data['showPerformer'] ?? true,
            'show_price' => $event_data['showPrice'] ?? true,
            'show_ticket_link' => $event_data['showTicketLink'] ?? true,
        ];
    }

    /**
     * Decode unicode escape sequences in strings
     *
     * @param string $str Input string
     * @return string Decoded string
     */
    public static function decode_unicode(string $str): string {
        return html_entity_decode(
            preg_replace('/\\\\u([0-9a-fA-F]{4})/', '&#x$1;', $str),
            ENT_NOQUOTES,
            'UTF-8'
        );
    }

    /**
     * Detect time gaps between date groups for carousel mode
     *
     * @param array $date_groups Date-grouped events
     * @return array Map of date_key => gap_days for gaps >= 2 days
     */
    public static function detect_time_gaps(array $date_groups): array {
        $gaps = [];
        $previous_date = null;

        foreach ($date_groups as $date_key => $date_group) {
            if ($previous_date !== null) {
                $current_date = new DateTime($date_key, wp_timezone());
                $days_diff = $current_date->diff($previous_date)->days;

                if ($days_diff > 1) {
                    $gaps[$date_key] = $days_diff;
                }
            }
            $previous_date = new DateTime($date_key, wp_timezone());
        }

        return $gaps;
    }
}
