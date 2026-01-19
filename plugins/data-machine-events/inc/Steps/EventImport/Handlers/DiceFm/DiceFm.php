<?php
/**
 * Dice.fm Event Import Handler
 *
 * Integrates with Dice.fm API for event imports using Data Machine's
 * single-item processing model with deduplication tracking.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\DiceFm
 * @since 1.0.0
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\DiceFm;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachine\Core\DataPacket;
use DataMachine\Core\Steps\HandlerRegistrationTrait;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dice.fm API event import handler with single-item processing
 *
 * Implements Data Machine handler interface for importing events from
 * Dice.fm API with standardized processing and venue data extraction.
 */
class DiceFm extends EventImportHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'dice_fm' );

		self::registerHandler(
			'dice_fm',
			'event_import',
			self::class,
			__( 'Dice.fm Events', 'datamachine-events' ),
			__( 'Import events from Dice.fm API with venue data', 'datamachine-events' ),
			true,
			DiceFmAuth::class,
			DiceFmSettings::class,
			null
		);
	}

	/**
	 * Execute Dice FM event import with flat parameter structure
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$context->log( 'info', 'DiceFm: Starting event import' );

		// Get API configuration from Data Machine auth system
		$auth = $this->getAuthProvider( 'dice_fm' );
		if ( ! $auth ) {
			$context->log( 'error', 'DiceFm: Authentication provider not found' );
			return array();
		}

		$api_config = $auth->get_account();
		if ( empty( $api_config['api_key'] ) ) {
			$context->log( 'error', 'DiceFm: API key not configured' );
			return array();
		}

		// Get required city parameter
		$city = isset( $config['city'] ) ? trim( $config['city'] ) : '';
		if ( empty( $city ) ) {
			$context->log( 'error', 'DiceFm: No city specified for search', $config );
			return array();
		}

		// Build configuration
		$partner_id = ! empty( $api_config['partner_id'] ) ? trim( $api_config['partner_id'] ) : '';

		// Fetch events from API
		$raw_events = $this->fetch_dice_fm_events( $api_config['api_key'], $city, $partner_id, $context );
		if ( empty( $raw_events ) ) {
			$context->log( 'info', 'DiceFm: No events found from API' );
			return array();
		}

		// Process events one at a time (Data Machine single-item model)
		$context->log(
			'info',
			'DiceFm: Processing events for eligible item',
			array(
				'raw_events_available' => count( $raw_events ),
			)
		);

		foreach ( $raw_events as $raw_event ) {
			// Standardize the event
			$standardized_event = $this->convert_dice_fm_event( $raw_event );

			// Skip if no title
			if ( empty( $standardized_event['title'] ) ) {
				continue;
			}

			if ( $this->shouldSkipEventTitle( $standardized_event['title'] ) ) {
				continue;
			}

			// Apply keyword filtering
			$search_text = $standardized_event['title'] . ' ' . ( $standardized_event['description'] ?? '' );

			if ( ! $this->applyKeywordSearch( $search_text, $config['search'] ?? '' ) ) {
				continue;
			}

			if ( $this->applyExcludeKeywords( $search_text, $config['exclude_keywords'] ?? '' ) ) {
				continue;
			}

			// Create unique identifier for processed items tracking
			$event_identifier = \DataMachineEvents\Utilities\EventIdentifierGenerator::generate(
				$standardized_event['title'],
				$standardized_event['startDate'] ?? '',
				$standardized_event['venue'] ?? ''
			);

			// Check if already processed FIRST
			if ( $this->checkItemProcessed( $context, $event_identifier ) ) {
				continue;
			}

			// Found eligible event - mark as processed
			$this->markItemAsProcessed( $context, $event_identifier );

			$context->log(
				'info',
				'DiceFm: Found eligible event',
				array(
					'title' => $standardized_event['title'],
					'date'  => $standardized_event['startDate'],
					'venue' => $standardized_event['venue'],
				)
			);

			$venue_metadata = $this->extractVenueMetadata( $standardized_event );
			$this->storeEventContext( $context, $standardized_event );
			$this->stripVenueMetadataFromEvent( $standardized_event );

			// Create DataPacket
			$dataPacket = new DataPacket(
				array(
					'title' => $standardized_event['title'],
					'body'  => wp_json_encode(
						array(
							'event'          => $standardized_event,
							'venue_metadata' => $venue_metadata,
							'import_source'  => 'dice_fm',
						),
						JSON_PRETTY_PRINT
					),
				),
				array(
					'source_type'      => 'dice_fm',
					'pipeline_id'      => $context->getPipelineId(),
					'flow_id'          => $context->getFlowId(),
					'original_title'   => $standardized_event['title'] ?? '',
					'event_identifier' => $event_identifier,
					'import_timestamp' => time(),
				),
				'event_import'
			);

			return array( $dataPacket );
		}

		// No eligible events found
		$context->log(
			'info',
			'DiceFm: No eligible events found',
			array(
				'raw_events_checked' => count( $raw_events ),
			)
		);

		return array();
	}

	/**
	 * Fetch events from Dice.fm API
	 */
	private function fetch_dice_fm_events( $api_key, $city, $partner_id, ExecutionContext $context ): array {
		$base_url = 'https://partners-endpoint.dice.fm/api/v2/events';

		// Build query parameters
		$params = array(
			'page[size]'       => 100,
			'types'            => 'linkout,event',
			'filter[cities][]' => $city,
		);

		$url = add_query_arg( $params, $base_url );

		// Prepare headers
		$headers = array(
			'Accept'    => 'application/json',
			'x-api-key' => $api_key,
		);

		if ( ! empty( $partner_id ) ) {
			$headers['X-Partner-Id'] = trim( $partner_id );
		}

		// Make API request
		$result = $this->httpGet(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);

		if ( ! $result['success'] ) {
			$context->log( 'error', 'DiceFm: API request failed', array( 'error' => $result['error'] ?? 'Unknown error' ) );
			return array();
		}

		$response_code = $result['status_code'];
		$body          = $result['data'];

		if ( 200 !== $response_code ) {
			$context->log( 'error', 'DiceFm: API returned status ' . $response_code );
			return array();
		}

		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$context->log( 'error', 'DiceFm: Invalid JSON response from API' );
			return array();
		}

		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			$context->log( 'error', 'DiceFm: No events data in response' );
			return array();
		}

		return $data['data'];
	}

	/**
	 * Convert Dice.fm event format to Event Details schema
	 *
	 * @param array $event Raw Dice.fm event data
	 * @return array Standardized event data
	 */
	private function convert_dice_fm_event( $event ) {
		$venue_data = $this->extract_venue_data( $event );
		$timezone   = $event['timezone'] ?? '';

		$start_parsed = $this->parseDateTimeUtc( $event['date'] ?? '', $timezone );
		$end_parsed   = $this->parseDateTimeUtc( $event['date_end'] ?? '', $timezone );

		return array(
			'title'            => sanitize_text_field( $event['name'] ?? '' ),
			'startDate'        => $start_parsed['date'],
			'endDate'          => $end_parsed['date'],
			'startTime'        => $start_parsed['time'],
			'endTime'          => $end_parsed['time'],
			'venue'            => sanitize_text_field( $venue_data['venue_name'] ),
			'artist'           => '',
			'price'            => '',
			'ticketUrl'        => esc_url_raw( $event['url'] ?? '' ),
			'description'      => wp_kses_post( $event['description'] ?? '' ),
			'venueAddress'     => sanitize_text_field( $venue_data['venue_address'] ),
			'venueCity'        => sanitize_text_field( $venue_data['venue_city'] ),
			'venueState'       => sanitize_text_field( $venue_data['venue_state'] ),
			'venueZip'         => sanitize_text_field( $venue_data['venue_zip'] ),
			'venueCountry'     => sanitize_text_field( $venue_data['venue_country'] ),
			'venueCoordinates' => sanitize_text_field( $venue_data['venue_coordinates'] ),
			'venueTimezone'    => sanitize_text_field( $timezone ),
		);
	}

	/**
	 * Extract venue data from Dice.fm event
	 *
	 * @param array $event Raw event data
	 * @return array Venue data with all location fields
	 */
	private function extract_venue_data( $event ) {
		$venue_data = array(
			'venue_name'        => '',
			'venue_address'     => '',
			'venue_city'        => '',
			'venue_state'       => '',
			'venue_zip'         => '',
			'venue_country'     => '',
			'venue_coordinates' => '',
		);

		if ( ! empty( $event['venue'] ) ) {
			$venue_data['venue_name'] = $event['venue'];
		} elseif ( ! empty( $event['venues'] ) && is_array( $event['venues'] ) && ! empty( $event['venues'][0]['name'] ) ) {
			$venue_data['venue_name'] = $event['venues'][0]['name'];
		}

		$location = $event['location'] ?? array();
		if ( ! empty( $location ) ) {
			$venue_data['venue_address'] = $location['street'] ?? '';
			$venue_data['venue_city']    = $location['city'] ?? '';
			$venue_data['venue_state']   = $location['state'] ?? '';
			$venue_data['venue_zip']     = $location['zip'] ?? '';
			$venue_data['venue_country'] = $location['country'] ?? '';

			if ( ! empty( $location['lat'] ) && ! empty( $location['lng'] ) ) {
				$venue_data['venue_coordinates'] = $location['lat'] . ',' . $location['lng'];
			}
		}

		return $venue_data;
	}
}
