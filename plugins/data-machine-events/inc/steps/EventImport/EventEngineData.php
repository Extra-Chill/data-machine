<?php
/**
 * Event Engine Data Helper
 *
 * @package DataMachineEvents\Steps\EventImport
 */

namespace DataMachineEvents\Steps\EventImport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper class for managing event-specific engine data
 */
class EventEngineData {

    /**
     * Store venue context in engine data
     *
     * @param string $job_id Job ID
     * @param array $event_data Standardized event data
     * @param array $venue_metadata Venue metadata
     */
    public static function storeVenueContext(string $job_id, array $event_data, array $venue_metadata): void {
        if (empty($job_id)) {
            return;
        }

        $context_data = [
            'venue_context' => [
                'name' => $event_data['venue'] ?? '',
                'address' => $venue_metadata['venueAddress'] ?? '',
                'city' => $venue_metadata['venueCity'] ?? '',
                'state' => $venue_metadata['venueState'] ?? '',
                'zip' => $venue_metadata['venueZip'] ?? '',
                'coordinates' => $venue_metadata['venueCoordinates'] ?? ''
            ]
        ];

        apply_filters('datamachine_engine_data', null, $job_id, $context_data);
    }
}
