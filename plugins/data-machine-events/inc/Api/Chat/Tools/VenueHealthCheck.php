<?php
/**
 * Venue Health Check Tool
 *
 * Scans venues for data quality issues: missing address, coordinates, or timezone.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Core\Venue_Taxonomy;

class VenueHealthCheck {
    use ToolRegistrationTrait;

    private const DEFAULT_LIMIT = 25;

    public function __construct() {
        $this->registerTool('chat', 'venue_health_check', [$this, 'getToolDefinition']);
    }

    public function getToolDefinition(): array {
        return [
            'class' => self::class,
            'method' => 'handle_tool_call',
            'description' => 'Check venues for data quality issues: missing address, missing coordinates, or missing timezone. Returns counts and lists of problematic venues.',
            'parameters' => [
                'limit' => [
                    'type' => 'integer',
                    'required' => false,
                    'description' => 'Max venues to return per issue category (default: 25)'
                ]
            ]
        ];
    }

    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        $limit = (int) ($parameters['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $venues = get_terms([
            'taxonomy' => 'venue',
            'hide_empty' => false,
        ]);

        if (is_wp_error($venues)) {
            return [
                'success' => false,
                'error' => 'Failed to query venues: ' . $venues->get_error_message(),
                'tool_name' => 'venue_health_check'
            ];
        }

        if (empty($venues)) {
            return [
                'success' => true,
                'data' => [
                    'total_venues' => 0,
                    'message' => 'No venues found in the system.'
                ],
                'tool_name' => 'venue_health_check'
            ];
        }

        $missing_address = [];
        $missing_coordinates = [];
        $missing_timezone = [];

        foreach ($venues as $venue) {
            $address = get_term_meta($venue->term_id, '_venue_address', true);
            $city = get_term_meta($venue->term_id, '_venue_city', true);
            $coordinates = get_term_meta($venue->term_id, '_venue_coordinates', true);
            $timezone = get_term_meta($venue->term_id, '_venue_timezone', true);

            $venue_info = [
                'term_id' => $venue->term_id,
                'name' => $venue->name,
                'event_count' => $venue->count
            ];

            if (empty($address) && empty($city)) {
                $missing_address[] = $venue_info;
            }

            if (empty($coordinates)) {
                $missing_coordinates[] = $venue_info;
            }

            if (!empty($coordinates) && empty($timezone)) {
                $missing_timezone[] = $venue_info;
            }
        }

        $total = count($venues);
        $issues_count = count($missing_address) + count($missing_coordinates) + count($missing_timezone);
        
        // Build message
        $message_parts = [];
        if (!empty($missing_address)) {
            $message_parts[] = count($missing_address) . ' missing address';
        }
        if (!empty($missing_coordinates)) {
            $message_parts[] = count($missing_coordinates) . ' missing coordinates';
        }
        if (!empty($missing_timezone)) {
            $message_parts[] = count($missing_timezone) . ' missing timezone';
        }

        if (empty($message_parts)) {
            $message = "All {$total} venues have complete data.";
        } else {
            $message = "Found issues: " . implode(', ', $message_parts) . ". Use update_venue tool to fix.";
        }

        return [
            'success' => true,
            'data' => [
                'total_venues' => $total,
                'missing_address' => [
                    'count' => count($missing_address),
                    'venues' => array_slice($missing_address, 0, $limit)
                ],
                'missing_coordinates' => [
                    'count' => count($missing_coordinates),
                    'venues' => array_slice($missing_coordinates, 0, $limit)
                ],
                'missing_timezone' => [
                    'count' => count($missing_timezone),
                    'venues' => array_slice($missing_timezone, 0, $limit)
                ],
                'message' => $message
            ],
            'tool_name' => 'venue_health_check'
        ];
    }
}
