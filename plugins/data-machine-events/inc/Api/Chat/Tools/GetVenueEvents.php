<?php
/**
 * Get Venue Events Tool
 *
 * Returns events attached to a specific venue for investigation and management.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

class GetVenueEvents {
    use ToolRegistrationTrait;

    public function __construct() {
        $this->registerTool('chat', 'get_venue_events', [$this, 'getToolDefinition']);
    }

    public function getToolDefinition(): array {
        return [
            'class' => self::class,
            'method' => 'handle_tool_call',
            'description' => 'Get events attached to a specific venue. Useful for investigating venue terms before merging or cleanup.',
            'parameters' => [
                'venue' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Venue identifier (term ID, name, or slug)'
                ],
                'limit' => [
                    'type' => 'integer',
                    'required' => false,
                    'description' => 'Maximum events to return (default: 25, max: 100)'
                ],
                'status' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Post status filter: any, publish, future, draft (default: any)'
                ],
                'published_before' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Only return events published before this date (YYYY-MM-DD format)'
                ],
                'published_after' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Only return events published after this date (YYYY-MM-DD format)'
                ]
            ]
        ];
    }

    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        $venue_identifier = $parameters['venue'] ?? null;

        if (empty($venue_identifier)) {
            return [
                'success' => false,
                'error' => 'venue parameter is required',
                'tool_name' => 'get_venue_events'
            ];
        }

        $term = $this->resolveVenue($venue_identifier);
        if (!$term) {
            return [
                'success' => false,
                'error' => "Venue '{$venue_identifier}' not found",
                'tool_name' => 'get_venue_events'
            ];
        }

        $limit = isset($parameters['limit']) ? min(max(1, (int) $parameters['limit']), 100) : 25;
        $status = $parameters['status'] ?? 'any';

        $valid_statuses = ['any', 'publish', 'future', 'draft', 'pending', 'private'];
        if (!in_array($status, $valid_statuses, true)) {
            $status = 'any';
        }

        $date_query = [];
        if (!empty($parameters['published_before'])) {
            $date_query[] = [
                'before' => $parameters['published_before'],
                'inclusive' => false
            ];
        }
        if (!empty($parameters['published_after'])) {
            $date_query[] = [
                'after' => $parameters['published_after'],
                'inclusive' => true
            ];
        }

        $query_args = [
            'post_type' => Event_Post_Type::POST_TYPE,
            'post_status' => $status,
            'posts_per_page' => $limit,
            'orderby' => 'meta_value',
            'meta_key' => '_datamachine_event_datetime',
            'order' => 'DESC',
            'tax_query' => [
                [
                    'taxonomy' => 'venue',
                    'field' => 'term_id',
                    'terms' => $term->term_id
                ]
            ]
        ];

        if (!empty($date_query)) {
            $query_args['date_query'] = $date_query;
        }

        $query = new \WP_Query($query_args);
        $events = [];

        foreach ($query->posts as $post) {
            $start_date = get_post_meta($post->ID, '_datamachine_event_datetime', true);
            $end_date = get_post_meta($post->ID, '_datamachine_event_end_datetime', true);

            $events[] = [
                'post_id' => $post->ID,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'published' => $post->post_date,
                'start_date' => $start_date ?: null,
                'end_date' => $end_date ?: null,
                'permalink' => get_permalink($post->ID)
            ];
        }

        $venue_data = Venue_Taxonomy::get_venue_data($term->term_id);
        $total_count = $term->count;

        return [
            'success' => true,
            'data' => [
                'venue' => [
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'total_events' => $total_count,
                    'venue_data' => $venue_data
                ],
                'events' => $events,
                'returned_count' => count($events),
                'message' => sprintf(
                    "Found %d events for venue '%s' (showing %d)",
                    $total_count,
                    $term->name,
                    count($events)
                )
            ],
            'tool_name' => 'get_venue_events'
        ];
    }

    private function resolveVenue(string $identifier): ?\WP_Term {
        if (is_numeric($identifier)) {
            $term = get_term((int) $identifier, 'venue');
            if ($term && !is_wp_error($term)) {
                return $term;
            }
        }

        $term = get_term_by('name', $identifier, 'venue');
        if ($term) {
            return $term;
        }

        $term = get_term_by('slug', $identifier, 'venue');
        if ($term) {
            return $term;
        }

        return null;
    }
}
