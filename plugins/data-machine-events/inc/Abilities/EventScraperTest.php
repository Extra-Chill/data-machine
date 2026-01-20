<?php
/**
 * Event Scraper Test Ability
 *
 * Tests universal web scraper compatibility with a target URL.
 * Provides structured JSON output via WordPress Abilities API and Chat Tools.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventScraperTest {

	public function __construct() {
		add_action(
			'wp_abilities_api_init',
			function () {
				wp_register_ability(
					'datamachine/test-event-scraper',
					array(
						'label'               => __( 'Test Event Scraper', 'datamachine-events' ),
						'description'         => __( 'Test universal web scraper compatibility with a target URL', 'datamachine-events' ),
						'category'            => 'datamachine',
						'input_schema'        => array(
							'type'       => 'object',
							'required'   => array( 'target_url' ),
							'properties' => array(
								'target_url' => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => 'Target URL to test scraper against',
								),
							),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'success'         => array( 'type' => 'boolean' ),
								'status'          => array(
									'type' => 'string',
									'enum' => array( 'ok', 'warning', 'error' ),
								),
								'target_url'      => array( 'type' => 'string' ),
								'event_data'      => array( 'type' => 'object' ),
								'extraction_info' => array( 'type' => 'object' ),
								'coverage_issues' => array( 'type' => 'object' ),
								'warnings'        => array( 'type' => 'array' ),
								'logs'            => array( 'type' => 'array' ),
							),
						),
						'execute_callback'    => array( $this, 'executeAbility' ),
						'permission_callback' => function () {
							return current_user_can( 'manage_options' );
						},
						'meta'                => array( 'show_in_rest' => true ),
					)
				);
			}
		);
	}

	public function executeAbility( array $input ): array {
		$target_url = $input['target_url'] ?? '';

		if ( empty( $target_url ) ) {
			return $this->buildErrorResponse( 'Missing required target_url parameter.' );
		}

		return $this->test( $target_url );
	}

	public function test( string $target_url ): array {
		$logs = array();
		add_action(
			'datamachine_log',
			static function ( string $level, string $message, array $context = array() ) use ( &$logs ): void {
				$logs[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			},
			10,
			3
		);

		$config = array(
			'source_url'   => $target_url,
			'flow_step_id' => 'test_' . wp_generate_uuid4(),
			'flow_id'      => 'direct',
			'search'       => '',
		);

		$handler = new UniversalWebScraper();
		$results = $handler->get_fetch_data( 'direct', $config, null );

		if ( empty( $results ) ) {
			$warnings = array_values(
				array_filter(
					$logs,
					static function ( array $entry ): bool {
						return ( $entry['level'] ?? '' ) === 'warning';
					}
				)
			);

			return array(
				'success'         => false,
				'status'          => 'error',
				'target_url'      => $target_url,
				'event_data'      => null,
				'extraction_info' => null,
				'coverage_issues' => null,
				'warnings'        => array_map( fn( $w ) => $w['message'], $warnings ),
				'logs'            => array_slice( $logs, -20 ),
			);
		}

		$packet       = $results[0];
		$packet_array = $packet->addTo( array() );
		$packet_entry = $packet_array[0] ?? array();
		$packet_data  = $packet_entry['data'] ?? array();
		$packet_meta  = $packet_entry['metadata'] ?? array();

		$body = $packet_data['body'] ?? '';
		if ( '' === $body && isset( $packet_entry['body'] ) ) {
			$body = (string) $packet_entry['body'];
		}

		$payload = json_decode( (string) $body, true );
		$event   = is_array( $payload ) ? ( $payload['event'] ?? null ) : null;

		$extraction_info = array(
			'packet_title'      => $packet_data['title'] ?? '',
			'source_type'       => $packet_meta['source_type'] ?? '',
			'extraction_method' => $packet_meta['extraction_method'] ?? '',
		);

		if ( is_array( $payload ) && isset( $payload['raw_html'] ) && is_string( $payload['raw_html'] ) ) {
			return array(
				'success'         => true,
				'status'          => 'warning',
				'target_url'      => $target_url,
				'event_data'      => null,
				'extraction_info' => array_merge(
					$extraction_info,
					array(
						'payload_type' => 'raw_html',
					)
				),
				'coverage_issues' => array(
					'missing_time'       => false,
					'missing_venue'      => true,
					'incomplete_address' => true,
					'time_data_warning'  => false,
					'raw_html_fallback'  => true,
				),
				'warnings'        => array( 'No structured venue fields. Set venue override for reliable address/geocoding.' ),
				'logs'            => array_slice( $logs, -20 ),
			);
		}

		if ( ! is_array( $event ) ) {
			return array(
				'success'         => false,
				'status'          => 'error',
				'target_url'      => $target_url,
				'event_data'      => null,
				'extraction_info' => array_merge(
					$extraction_info,
					array(
						'payload_type' => 'unknown',
					)
				),
				'coverage_issues' => null,
				'warnings'        => array( 'Payload did not contain an event object.' ),
				'logs'            => array_slice( $logs, -20 ),
			);
		}

		$event_data = array(
			'title'         => (string) ( $event['title'] ?? '' ),
			'startDate'     => (string) ( $event['startDate'] ?? '' ),
			'startTime'     => (string) ( $event['startTime'] ?? '' ),
			'endDate'       => (string) ( $event['endDate'] ?? '' ),
			'endTime'       => (string) ( $event['endTime'] ?? '' ),
			'venueTimezone' => (string) ( $event['venueTimezone'] ?? '' ),
		);

		$venue_name  = (string) ( $event['venue'] ?? '' );
		$venue_addr  = (string) ( $event['venueAddress'] ?? '' );
		$venue_city  = (string) ( $event['venueCity'] ?? '' );
		$venue_state = (string) ( $event['venueState'] ?? '' );
		$venue_zip   = (string) ( $event['venueZip'] ?? '' );

		if ( is_array( $payload ) && isset( $payload['venue_metadata'] ) && is_array( $payload['venue_metadata'] ) ) {
			$venue_meta  = $payload['venue_metadata'];
			$venue_addr  = '' !== $venue_addr ? $venue_addr : (string) ( $venue_meta['venueAddress'] ?? '' );
			$venue_city  = '' !== $venue_city ? $venue_city : (string) ( $venue_meta['venueCity'] ?? '' );
			$venue_state = '' !== $venue_state ? $venue_state : (string) ( $venue_meta['venueState'] ?? '' );
			$venue_zip   = '' !== $venue_zip ? $venue_zip : (string) ( $venue_meta['venueZip'] ?? '' );
		}

		$city_state_zip = trim( $venue_city . ', ' . $venue_state . ' ' . $venue_zip );
		$city_state_zip = ',' === $city_state_zip ? '' : $city_state_zip;
		$venue_full     = trim( implode( ', ', array_filter( array( $venue_addr, $city_state_zip ) ) ) );

		$event_data['venue']        = $venue_name;
		$event_data['venueAddress'] = $venue_full;
		$event_data['venueCity']    = $venue_city;
		$event_data['venueState']   = $venue_state;
		$event_data['venueZip']     = $venue_zip;

		$extraction_info['payload_type'] = 'event';

		$time_data_warning = false;
		$coverage_warning  = false;

		if ( empty( trim( $event['startTime'] ?? '' ) ) && ! empty( trim( $event['startDate'] ?? '' ) ) ) {
			$time_data_warning = true;
			$coverage_warning  = true;
		}

		$missing_venue      = empty( trim( $venue_name ) );
		$incomplete_address = empty( trim( $venue_addr ) ) || empty( trim( $venue_city ) ) || empty( trim( $venue_state ) );

		if ( $missing_venue || $incomplete_address ) {
			$coverage_warning = true;
		}

		$warnings = array();
		if ( $time_data_warning ) {
			$warnings[] = 'TIME DATA: Missing start/end time - check ICS feed timezone handling or source data';
		}
		if ( $missing_venue ) {
			$warnings[] = 'VENUE COVERAGE: Missing venue name; set venue override.';
		}
		if ( $incomplete_address ) {
			$warnings[] = 'VENUE COVERAGE: Missing venue address fields (venueAddress/venueCity/venueState). Geocoding may fail; set venue override.';
		}

		$log_warnings = array_values(
			array_filter(
				$logs,
				static function ( array $entry ): bool {
					return ( $entry['level'] ?? '' ) === 'warning';
				}
			)
		);
		foreach ( $log_warnings as $warning ) {
			$warnings[] = $warning['message'];
		}

		return array(
			'success'         => true,
			'status'          => $coverage_warning ? 'warning' : 'ok',
			'target_url'      => $target_url,
			'event_data'      => $event_data,
			'extraction_info' => $extraction_info,
			'coverage_issues' => array(
				'missing_time'       => $time_data_warning,
				'missing_venue'      => $missing_venue,
				'incomplete_address' => $incomplete_address,
				'time_data_warning'  => $time_data_warning,
			),
			'warnings'        => $warnings,
			'logs'            => array_slice( $logs, -20 ),
		);
	}

	private function buildErrorResponse( string $message ): array {
		return array(
			'success'         => false,
			'status'          => 'error',
			'target_url'      => '',
			'event_data'      => null,
			'extraction_info' => null,
			'coverage_issues' => null,
			'warnings'        => array( $message ),
			'logs'            => array(),
		);
	}
}
