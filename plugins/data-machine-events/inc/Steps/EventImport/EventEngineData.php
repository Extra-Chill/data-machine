<?php
/**
 * Event Engine Data Helper
 *
 * Persists venue metadata via datamachine_merge_engine_data so EngineData snapshots remain
 * the single source of truth for downstream handlers.
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
        $job_id = (int) $job_id;

        if ($job_id <= 0 || !function_exists('datamachine_merge_engine_data')) {
            return;
        }

        $flattened = [
            'venue' => $event_data['venue'] ?? '',
            'venueAddress' => $venue_metadata['venueAddress'] ?? '',
            'venueCity' => $venue_metadata['venueCity'] ?? '',
            'venueState' => $venue_metadata['venueState'] ?? '',
            'venueZip' => $venue_metadata['venueZip'] ?? '',
            'venueCountry' => $venue_metadata['venueCountry'] ?? '',
            'venuePhone' => $venue_metadata['venuePhone'] ?? '',
            'venueWebsite' => $venue_metadata['venueWebsite'] ?? '',
            'venueCoordinates' => $venue_metadata['venueCoordinates'] ?? '',
            'venueCapacity' => $venue_metadata['venueCapacity'] ?? ''
        ];

        $metadata = [
            'name' => $flattened['venue'] ?? '',
            'address' => $flattened['venueAddress'] ?? '',
            'city' => $flattened['venueCity'] ?? '',
            'state' => $flattened['venueState'] ?? '',
            'zip' => $flattened['venueZip'] ?? '',
            'country' => $flattened['venueCountry'] ?? '',
            'phone' => $flattened['venuePhone'] ?? '',
            'website' => $flattened['venueWebsite'] ?? '',
            'coordinates' => $flattened['venueCoordinates'] ?? '',
            'capacity' => $flattened['venueCapacity'] ?? ''
        ];

        $payload = array_filter($flattened, static function($value) {
            return $value !== '' && $value !== null;
        });

        $metadata_clean = array_filter($metadata, static function($value) {
            return $value !== '' && $value !== null;
        });

        if (!empty($metadata_clean)) {
            $payload['venue_context'] = $metadata_clean;
        }

        if (empty($payload)) {
            return;
        }

        datamachine_merge_engine_data($job_id, $payload);
    }
}
