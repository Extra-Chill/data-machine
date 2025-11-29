<?php
/**
 * Dynamic venue parameter generation for AI tool definitions.
 *
 * Provides venue field parameters to AI tools when no static venue is configured.
 * Single source of truth for venue tool parameter schema, working alongside
 * Venue_Taxonomy (storage) and VenueService (operations).
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

if (!defined('ABSPATH')) {
    exit;
}

class VenueParameterProvider {

    private const TOOL_PARAMETERS = [
        'venue' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Venue name where the event takes place'
        ],
        'venueAddress' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Street address of the venue'
        ],
        'venueCity' => [
            'type' => 'string',
            'required' => false,
            'description' => 'City where the venue is located'
        ],
        'venueState' => [
            'type' => 'string',
            'required' => false,
            'description' => 'State/province where the venue is located'
        ],
        'venueZip' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Postal/zip code of the venue'
        ],
        'venueCountry' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Country where the venue is located'
        ],
        'venuePhone' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Phone number of the venue'
        ],
        'venueWebsite' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Website URL of the venue'
        ],
        'venueCoordinates' => [
            'type' => 'string',
            'required' => false,
            'description' => 'GPS coordinates (latitude,longitude format)'
        ],
        'venueCapacity' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Maximum venue capacity'
        ]
    ];

    private const PARAMETER_TO_META_MAP = [
        'venue' => 'name',
        'venueAddress' => 'address',
        'venueCity' => 'city',
        'venueState' => 'state',
        'venueZip' => 'zip',
        'venueCountry' => 'country',
        'venuePhone' => 'phone',
        'venueWebsite' => 'website',
        'venueCoordinates' => 'coordinates',
        'venueCapacity' => 'capacity'
    ];

    /**
     * Get AI tool parameters for venue fields when AI should decide.
     *
     * @param array $handler_config Handler configuration
     * @return array Tool parameter definitions (empty if static venue configured)
     */
    public static function getToolParameters(array $handler_config): array {
        if (self::hasStaticVenue($handler_config)) {
            return [];
        }
        return self::TOOL_PARAMETERS;
    }

    /**
     * Check if static venue data is configured.
     *
     * @param array $handler_config Handler configuration
     * @return bool True if static venue is available
     */
    public static function hasStaticVenue(array $handler_config): bool {
        if (!empty($handler_config['universal_web_scraper']['venue'])) {
            return true;
        }

        if (!empty($handler_config['venue']) && is_numeric($handler_config['venue'])) {
            return true;
        }

        return false;
    }

    /**
     * Get all venue parameter keys (for tool params).
     *
     * @return array List of venue parameter names
     */
    public static function getParameterKeys(): array {
        return array_keys(self::TOOL_PARAMETERS);
    }

    /**
     * Get venue meta field keys (for storage operations).
     * Maps tool parameter names to Venue_Taxonomy meta field keys.
     *
     * @return array Mapping of param name => meta field key
     */
    public static function getParameterToMetaKeyMap(): array {
        return self::PARAMETER_TO_META_MAP;
    }

    /**
     * Extract venue data from AI tool parameters.
     * Returns data keyed by Venue_Taxonomy meta field names.
     *
     * @param array $parameters AI tool call parameters
     * @return array Venue data keyed by meta field names (address, city, etc.)
     */
    public static function extractFromParameters(array $parameters): array {
        $venue_data = [];

        foreach (self::PARAMETER_TO_META_MAP as $param_key => $meta_key) {
            if (!empty($parameters[$param_key])) {
                $venue_data[$meta_key] = $parameters[$param_key];
            }
        }

        return $venue_data;
    }

    /**
     * Extract venue metadata from event data array.
     * Used by EventImportHandler subclasses.
     *
     * @param array $event Event data array with venueAddress, venueCity, etc.
     * @return array Venue metadata keyed by parameter names
     */
    public static function extractFromEventData(array $event): array {
        $metadata = [];

        foreach (self::getParameterKeys() as $key) {
            if ($key === 'venue') {
                continue;
            }
            $metadata[$key] = $event[$key] ?? '';
        }

        return $metadata;
    }

    /**
     * Strip venue metadata fields from event data array.
     *
     * @param array &$event Event data array (modified in place)
     */
    public static function stripFromEventData(array &$event): void {
        foreach (self::getParameterKeys() as $key) {
            if ($key === 'venue') {
                continue;
            }
            unset($event[$key]);
        }
    }
}
