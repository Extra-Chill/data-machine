<?php
/**
 * Event Health Check Tool
 *
 * Scans events for data quality issues: missing time, suspicious midnight start,
 * late night start (midnight-4am), suspicious 11:59pm end time, missing venue,
 * or missing description.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Core\Event_Post_Type;

class EventHealthCheck {
    use ToolRegistrationTrait;

    private const DEFAULT_LIMIT = 25;
    private const DEFAULT_DAYS_AHEAD = 90;

    public function __construct() {
        $this->registerTool('chat', 'event_health_check', [$this, 'getToolDefinition']);
    }

    public function getToolDefinition(): array {
        return [
            'class' => self::class,
            'method' => 'handle_tool_call',
            'description' => 'Check events for data quality issues: missing start time, suspicious midnight start, late night start (midnight-4am), suspicious 11:59pm end time, missing venue, missing description, or missing venue timezone. Returns counts and lists of problematic events.',
            'parameters' => [
                'scope' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Which events to check: "upcoming" (default), "all", or "past"',
                    'enum' => ['upcoming', 'all', 'past']
                ],
                'days_ahead' => [
                    'type' => 'integer',
                    'required' => false,
                    'description' => 'Days to look ahead for upcoming scope (default: 90)'
                ],
                'limit' => [
                    'type' => 'integer',
                    'required' => false,
                    'description' => 'Max events to return per issue category (default: 25)'
                ]
            ]
        ];
    }

    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        $scope = $parameters['scope'] ?? 'upcoming';
        $days_ahead = (int) ($parameters['days_ahead'] ?? self::DEFAULT_DAYS_AHEAD);
        $limit = (int) ($parameters['limit'] ?? self::DEFAULT_LIMIT);

        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($days_ahead <= 0) {
            $days_ahead = self::DEFAULT_DAYS_AHEAD;
        }

        $events = $this->queryEvents($scope, $days_ahead);

        if (is_wp_error($events)) {
            return [
                'success' => false,
                'error' => 'Failed to query events: ' . $events->get_error_message(),
                'tool_name' => 'event_health_check'
            ];
        }

        if (empty($events)) {
            return [
                'success' => true,
                'data' => [
                    'total_scanned' => 0,
                    'scope' => $scope,
                    'message' => 'No events found matching the specified scope.'
                ],
                'tool_name' => 'event_health_check'
            ];
        }

        $missing_time = [];
        $midnight_time = [];
        $late_night_time = [];
        $suspicious_end_time = [];
        $missing_venue = [];
        $missing_description = [];
        $broken_timezone = [];
        $no_venue_count = 0;

        foreach ($events as $event) {
            $block_attrs = $this->extractBlockAttributes($event->ID);
            $venue_terms = wp_get_post_terms($event->ID, 'venue', ['fields' => 'names']);
            $venue_name = (!is_wp_error($venue_terms) && !empty($venue_terms)) ? $venue_terms[0] : '';

            $event_info = [
                'id' => $event->ID,
                'title' => $event->post_title,
                'date' => $block_attrs['startDate'] ?? '',
                'venue' => $venue_name
            ];

            $start_time = $block_attrs['startTime'] ?? '';
            $end_time = $block_attrs['endTime'] ?? '';

            if (empty($start_time)) {
                $missing_time[] = $event_info;
            } elseif ($start_time === '00:00' || $start_time === '00:00:00') {
                $midnight_time[] = $event_info;
            } elseif ($this->isLateNightTime($start_time)) {
                $late_night_time[] = $event_info;
            }

            if ($this->isSuspiciousEndTime($end_time)) {
                $suspicious_end_time[] = $event_info;
            }

            if (empty($venue_name)) {
                $missing_venue[] = $event_info;
            }

            $description = $this->extractDescriptionFromInnerBlocks($event->ID);
            if (empty(trim($description))) {
                $missing_description[] = $event_info;
            }
        }

        $ability = wp_get_ability('datamachine-events/find-broken-timezone-events');
        if ($ability) {
            $result = $ability->execute([
                'scope' => $scope,
                'limit' => $limit
            ]);

            $broken_timezone = $result['broken_events'] ?? [];
            $no_venue_count = $result['no_venue_count'] ?? 0;
        } else {
            $broken_timezone = [];
        }

        $total = count($events);

        $sort_by_date = fn($a, $b) => strcmp($a['date'], $b['date']);
        if ($scope === 'past') {
            $sort_by_date = fn($a, $b) => strcmp($b['date'], $a['date']);
        }

        usort($missing_time, $sort_by_date);
        usort($midnight_time, $sort_by_date);
        usort($late_night_time, $sort_by_date);
        usort($suspicious_end_time, $sort_by_date);
        usort($missing_venue, $sort_by_date);
        usort($missing_description, $sort_by_date);

        $message_parts = [];
        if (!empty($missing_time)) {
            $message_parts[] = count($missing_time) . ' missing time';
        }
        if (!empty($midnight_time)) {
            $message_parts[] = count($midnight_time) . ' suspicious midnight';
        }
        if (!empty($late_night_time)) {
            $message_parts[] = count($late_night_time) . ' late night (midnight-4am)';
        }
        if (!empty($suspicious_end_time)) {
            $message_parts[] = count($suspicious_end_time) . ' suspicious 11:59pm end';
        }
        if (!empty($missing_venue)) {
            $message_parts[] = count($missing_venue) . ' missing venue';
        }
        if (!empty($missing_description)) {
            $message_parts[] = count($missing_description) . ' missing description';
        }
        if (count($broken_timezone) > 0) {
            $message_parts[] = count($broken_timezone) . ' missing timezone';
        }
        if ($no_venue_count > 0) {
            $message_parts[] = $no_venue_count . ' no venue';
        }

        if (empty($message_parts)) {
            $message = "All {$total} events have complete data.";
        } else {
            $message = "Found issues: " . implode(', ', $message_parts) . ". Use update_event tool to fix.";
        }

        return [
            'success' => true,
            'data' => [
                'total_scanned' => $total,
                'scope' => $scope,
                'missing_time' => [
                    'count' => count($missing_time),
                    'events' => array_slice($missing_time, 0, $limit)
                ],
                'midnight_time' => [
                    'count' => count($midnight_time),
                    'events' => array_slice($midnight_time, 0, $limit)
                ],
                'late_night_time' => [
                    'count' => count($late_night_time),
                    'events' => array_slice($late_night_time, 0, $limit)
                ],
                'suspicious_end_time' => [
                    'count' => count($suspicious_end_time),
                    'events' => array_slice($suspicious_end_time, 0, $limit)
                ],
                'missing_venue' => [
                    'count' => count($missing_venue),
                    'events' => array_slice($missing_venue, 0, $limit)
                ],
                'missing_description' => [
                    'count' => count($missing_description),
                    'events' => array_slice($missing_description, 0, $limit)
                ],
                'broken_timezone' => [
                    'count' => count($broken_timezone),
                    'events' => array_slice($broken_timezone, 0, $limit)
                ],
                'message' => $message
            ],
            'tool_name' => 'event_health_check'
        ];
    }

    /**
     * Query events based on scope.
     *
     * @param string $scope 'upcoming', 'past', or 'all'
     * @param int $days_ahead Days to look ahead for upcoming scope
     * @return array|\WP_Error Array of WP_Post objects or WP_Error
     */
    private function queryEvents(string $scope, int $days_ahead): array|\WP_Error {
        $args = [
            'post_type' => Event_Post_Type::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'meta_value',
            'meta_key' => Event_Post_Type::EVENT_DATE_META_KEY,
            'order' => 'ASC'
        ];

        $now = current_time('Y-m-d H:i:s');

        if ($scope === 'upcoming') {
            $end_date = gmdate('Y-m-d H:i:s', strtotime("+{$days_ahead} days"));
            $args['meta_query'] = [
                [
                    'key' => Event_Post_Type::EVENT_DATE_META_KEY,
                    'value' => [$now, $end_date],
                    'compare' => 'BETWEEN',
                    'type' => 'DATETIME'
                ]
            ];
        } elseif ($scope === 'past') {
            $args['meta_query'] = [
                [
                    'key' => Event_Post_Type::EVENT_DATE_META_KEY,
                    'value' => $now,
                    'compare' => '<',
                    'type' => 'DATETIME'
                ]
            ];
            $args['order'] = 'DESC';
        }

        $query = new \WP_Query($args);

        return $query->posts;
    }

    /**
     * Extract Event Details block attributes from post content.
     *
     * @param int $post_id Event post ID
     * @return array Block attributes or empty array
     */
    private function extractBlockAttributes(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $blocks = parse_blocks($post->post_content);

        foreach ($blocks as $block) {
            if ($block['blockName'] === 'datamachine-events/event-details') {
                return $block['attrs'] ?? [];
            }
        }

        return [];
    }

    /**
     * Extract description content from InnerBlocks (paragraph blocks).
     *
     * Descriptions are stored as core/paragraph InnerBlocks inside the
     * event-details block, not as a block attribute.
     *
     * @param int $post_id Event post ID
     * @return string Combined plain text from paragraph blocks
     */
    private function extractDescriptionFromInnerBlocks(int $post_id): string {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $blocks = parse_blocks($post->post_content);

        foreach ($blocks as $block) {
            if ($block['blockName'] !== 'datamachine-events/event-details') {
                continue;
            }

            if (empty($block['innerBlocks'])) {
                return '';
            }

            $text_parts = [];
            foreach ($block['innerBlocks'] as $inner) {
                if ($inner['blockName'] === 'core/paragraph' && !empty($inner['innerHTML'])) {
                    $text_parts[] = wp_strip_all_tags($inner['innerHTML']);
                }
            }

            return implode(' ', $text_parts);
        }

        return '';
    }

    /**
     * Check if time falls in late-night window (00:01-03:59).
     *
     * @param string $time Time string in HH:MM or HH:MM:SS format
     * @return bool True if time is between 00:01 and 03:59
     */
    private function isLateNightTime(string $time): bool {
        if (empty($time)) {
            return false;
        }

        $hour = (int) substr($time, 0, 2);
        $minute = (int) substr($time, 3, 2);

        if ($hour === 0 && $minute > 0) {
            return true;
        }

        return $hour >= 1 && $hour <= 3;
    }

    /**
     * Check if end time is suspicious 11:59pm (likely default/placeholder).
     *
     * @param string $time Time string in HH:MM or HH:MM:SS format
     * @return bool True if time is 23:59
     */
    private function isSuspiciousEndTime(string $time): bool {
        if (empty($time)) {
            return false;
        }

        return $time === '23:59' || $time === '23:59:00';
    }
}
