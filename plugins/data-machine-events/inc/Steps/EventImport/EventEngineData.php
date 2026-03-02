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

if ( ! defined( 'ABSPATH' ) ) {
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
	public static function storeVenueContext( ?string $job_id, array $event_data, array $venue_metadata ): void {
		$job_id = (int) $job_id;

		if ( $job_id <= 0 || ! function_exists( 'datamachine_merge_engine_data' ) ) {
			return;
		}

		$flattened = array(
			'venue'            => $event_data['venue'] ?? '',
			'venueAddress'     => $venue_metadata['venueAddress'] ?? '',
			'venueCity'        => $venue_metadata['venueCity'] ?? '',
			'venueState'       => $venue_metadata['venueState'] ?? '',
			'venueZip'         => $venue_metadata['venueZip'] ?? '',
			'venueCountry'     => $venue_metadata['venueCountry'] ?? '',
			'venuePhone'       => $venue_metadata['venuePhone'] ?? '',
			'venueWebsite'     => $venue_metadata['venueWebsite'] ?? '',
			'venueCoordinates' => $venue_metadata['venueCoordinates'] ?? '',
			'venueCapacity'    => $venue_metadata['venueCapacity'] ?? '',
			'venueTimezone'    => $venue_metadata['venueTimezone'] ?? '',
		);

		$metadata = array(
			'name'        => $flattened['venue'] ?? '',
			'address'     => $flattened['venueAddress'] ?? '',
			'city'        => $flattened['venueCity'] ?? '',
			'state'       => $flattened['venueState'] ?? '',
			'zip'         => $flattened['venueZip'] ?? '',
			'country'     => $flattened['venueCountry'] ?? '',
			'phone'       => $flattened['venuePhone'] ?? '',
			'website'     => $flattened['venueWebsite'] ?? '',
			'coordinates' => $flattened['venueCoordinates'] ?? '',
			'capacity'    => $flattened['venueCapacity'] ?? '',
			'timezone'    => $flattened['venueTimezone'] ?? '',
		);

		$payload = array_filter(
			$flattened,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		$metadata_clean = array_filter(
			$metadata,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		if ( ! empty( $metadata_clean ) ) {
			$payload['venue_context'] = $metadata_clean;
		}

		if ( empty( $payload ) ) {
			return;
		}

		datamachine_merge_engine_data( $job_id, $payload );
	}

	/**
	 * Store core event fields in engine data.
	 *
	 * When these fields exist in engine data, they are:
	 * 1. Excluded from AI tool parameters (AI can't override them)
	 * 2. Read directly by EventUpsert::buildEventData()
	 *
	 * @param string|int $job_id Job ID
	 * @param array $event_data Standardized event data
	 * @since 0.8.32
	 */
	public static function storeEventCoreFields( $job_id, array $event_data ): void {
		$job_id = (int) $job_id;

		if ( $job_id <= 0 || ! function_exists( 'datamachine_merge_engine_data' ) ) {
			return;
		}

		$core_fields = array(
			'startDate' => $event_data['startDate'] ?? '',
			'startTime' => $event_data['startTime'] ?? '',
			'endDate'   => $event_data['endDate'] ?? '',
			'endTime'   => $event_data['endTime'] ?? '',
			'ticketUrl' => $event_data['ticketUrl'] ?? '',
			'price'     => $event_data['price'] ?? '',
		);

		$payload = array_filter(
			$core_fields,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		if ( ! empty( $payload ) ) {
			datamachine_merge_engine_data( $job_id, $payload );
		}
	}

	/**
	 * Build per-item engine data array for batch fan-out.
	 *
	 * Returns the combined venue context and core event fields as an array
	 * suitable for DataPacket metadata['_engine_data']. The
	 * PipelineBatchScheduler seeds this into each child job's engine data.
	 *
	 * @param array $event_data Standardized event data (with venue fields).
	 * @param array $venue_metadata Extracted venue metadata.
	 * @return array Engine data payload (may be empty if no data).
	 * @since 0.14.0
	 */
	public static function buildEngineData( array $event_data, array $venue_metadata ): array {
		$payload = array();

		// Venue fields (flattened).
		$venue_fields = array(
			'venue'            => $event_data['venue'] ?? '',
			'venueAddress'     => $venue_metadata['venueAddress'] ?? '',
			'venueCity'        => $venue_metadata['venueCity'] ?? '',
			'venueState'       => $venue_metadata['venueState'] ?? '',
			'venueZip'         => $venue_metadata['venueZip'] ?? '',
			'venueCountry'     => $venue_metadata['venueCountry'] ?? '',
			'venuePhone'       => $venue_metadata['venuePhone'] ?? '',
			'venueWebsite'     => $venue_metadata['venueWebsite'] ?? '',
			'venueCoordinates' => $venue_metadata['venueCoordinates'] ?? '',
			'venueCapacity'    => $venue_metadata['venueCapacity'] ?? '',
			'venueTimezone'    => $venue_metadata['venueTimezone'] ?? '',
		);

		$venue_fields_clean = array_filter(
			$venue_fields,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		if ( ! empty( $venue_fields_clean ) ) {
			$payload = array_merge( $payload, $venue_fields_clean );

			// Build venue_context sub-array.
			$context_map = array(
				'name'        => $venue_fields['venue'],
				'address'     => $venue_fields['venueAddress'],
				'city'        => $venue_fields['venueCity'],
				'state'       => $venue_fields['venueState'],
				'zip'         => $venue_fields['venueZip'],
				'country'     => $venue_fields['venueCountry'],
				'phone'       => $venue_fields['venuePhone'],
				'website'     => $venue_fields['venueWebsite'],
				'coordinates' => $venue_fields['venueCoordinates'],
				'capacity'    => $venue_fields['venueCapacity'],
				'timezone'    => $venue_fields['venueTimezone'],
			);

			$context_clean = array_filter(
				$context_map,
				static function ( $value ) {
					return '' !== $value && null !== $value;
				}
			);

			if ( ! empty( $context_clean ) ) {
				$payload['venue_context'] = $context_clean;
			}
		}

		// Core event fields.
		$core_fields = array(
			'startDate' => $event_data['startDate'] ?? '',
			'startTime' => $event_data['startTime'] ?? '',
			'endDate'   => $event_data['endDate'] ?? '',
			'endTime'   => $event_data['endTime'] ?? '',
			'ticketUrl' => $event_data['ticketUrl'] ?? '',
			'price'     => $event_data['price'] ?? '',
		);

		$core_clean = array_filter(
			$core_fields,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		if ( ! empty( $core_clean ) ) {
			$payload = array_merge( $payload, $core_clean );
		}

		return $payload;
	}

	/**
	 * Store item context in engine data for skip_item tool.
	 *
	 * This enables the skip_item tool to mark items as processed even when
	 * the AI decides to skip them. Without this, skipped items would be
	 * refetched on subsequent runs.
	 *
	 * @param int $job_id Job ID
	 * @param string $item_id Item identifier (event_identifier, uid, etc.)
	 * @param string $source_type Source type (universal_web_scraper, ics_calendar, etc.)
	 * @since 0.8.31
	 */
	public static function storeItemContext( int $job_id, string $item_id, string $source_type ): void {
		if ( $job_id <= 0 || ! function_exists( 'datamachine_merge_engine_data' ) ) {
			return;
		}

		datamachine_merge_engine_data(
			$job_id,
			array(
				'item_id'     => $item_id,
				'source_type' => $source_type,
			)
		);
	}
}
