<?php
/**
 * Update Venue Tool
 *
 * Updates venue name and meta fields. Triggers auto-geocoding when address fields change.
 *
 * @package DataMachineEvents\Api\Chat\Tools
 */

namespace DataMachineEvents\Api\Chat\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use DataMachine\Engine\AI\Tools\ToolRegistrationTrait;
use DataMachineEvents\Core\Venue_Taxonomy;

class UpdateVenue {
    use ToolRegistrationTrait;

    public function __construct() {
        $this->registerTool('chat', 'update_venue', [$this, 'getToolDefinition']);
    }

    public function getToolDefinition(): array {
        return [
            'class' => self::class,
            'method' => 'handle_tool_call',
            'description' => 'Update a venue name and/or meta fields. Address changes trigger automatic geocoding.',
            'parameters' => [
                'venue' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Venue identifier (term ID, name, or slug)'
                ],
                'name' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'New venue name'
                ],
                'description' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Venue description'
                ],
                'address' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Street address'
                ],
                'city' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'City'
                ],
                'state' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'State/region'
                ],
                'zip' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Postal/ZIP code'
                ],
                'country' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Country'
                ],
                'phone' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Phone number'
                ],
                'website' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Website URL'
                ],
                'capacity' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Venue capacity'
                ],
                'coordinates' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'GPS coordinates as "lat,lng"'
                ],
                'timezone' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'IANA timezone identifier (e.g., America/New_York)'
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
                'tool_name' => 'update_venue'
            ];
        }

        // Resolve venue term
        $term = $this->resolveVenue($venue_identifier);
        if (!$term) {
            return [
                'success' => false,
                'error' => "Venue '{$venue_identifier}' not found",
                'tool_name' => 'update_venue'
            ];
        }

        $updated_fields = [];

        // Update term fields (name, description) if provided
        $term_updates = [];
        if (!empty($parameters['name'])) {
            $term_updates['name'] = sanitize_text_field($parameters['name']);
            $updated_fields[] = 'name';
        }
        if (isset($parameters['description']) && $parameters['description'] !== '') {
            $term_updates['description'] = wp_kses_post($parameters['description']);
            $updated_fields[] = 'description';
        }

        if (!empty($term_updates)) {
            $result = wp_update_term($term->term_id, 'venue', $term_updates);
            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'error' => 'Failed to update venue: ' . $result->get_error_message(),
                    'tool_name' => 'update_venue'
                ];
            }
        }

        // Build meta data array from parameters
        $meta_keys = ['address', 'city', 'state', 'zip', 'country', 'phone', 'website', 'capacity', 'coordinates', 'timezone'];
        $meta_data = [];

        foreach ($meta_keys as $key) {
            if (array_key_exists($key, $parameters) && $parameters[$key] !== null && $parameters[$key] !== '') {
                $meta_data[$key] = $parameters[$key];
                $updated_fields[] = $key;
            }
        }

        // Update meta if any provided
        if (!empty($meta_data)) {
            Venue_Taxonomy::update_venue_meta($term->term_id, $meta_data);
        }

        if (empty($updated_fields)) {
            return [
                'success' => false,
                'error' => 'No fields provided to update',
                'tool_name' => 'update_venue'
            ];
        }

        // Get updated venue data
        $updated_term = get_term($term->term_id, 'venue');
        $venue_data = Venue_Taxonomy::get_venue_data($term->term_id);

        return [
            'success' => true,
            'data' => [
                'term_id' => $term->term_id,
                'name' => $updated_term->name,
                'updated_fields' => $updated_fields,
                'venue_data' => $venue_data,
                'message' => "Updated venue '{$updated_term->name}': " . implode(', ', $updated_fields)
            ],
            'tool_name' => 'update_venue'
        ];
    }

    /**
     * Resolve venue by ID, name, or slug.
     */
    private function resolveVenue(string $identifier): ?\WP_Term {
        // Try as ID
        if (is_numeric($identifier)) {
            $term = get_term((int) $identifier, 'venue');
            if ($term && !is_wp_error($term)) {
                return $term;
            }
        }

        // Try by name
        $term = get_term_by('name', $identifier, 'venue');
        if ($term) {
            return $term;
        }

        // Try by slug
        $term = get_term_by('slug', $identifier, 'venue');
        if ($term) {
            return $term;
        }

        return null;
    }
}
