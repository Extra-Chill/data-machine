<?php
/**
 * Event Scraper Test Ability
 *
 * Tests universal web scraper compatibility with a target URL.
 * Provides structured JSON output via WordPress Abilities API and Chat Tools.
 *
 * Qualify-path divergence (issue #265, fixed in #266):
 * -----------------------------------------------------
 * UniversalWebScraper::executeFetch() returns `{ items: [...] }` when an
 * extractor yields multiple events on a calendar page. FetchHandler::get_fetch_data()
 * normalizes that into one DataPacket per event — so for a Bandzoogle calendar with
 * 19 events, `$handler->get_fetch_data()` returns an array of 19 DataPackets.
 *
 * Prior to the #265 fix, this ability read only `$results[0]` — the first packet —
 * and returned its single event as `event_data`. A qualifying consumer then
 * saw a single-event payload and recorded `extractor_attempts[i].events: 1`
 * regardless of how many
 * events the extractor actually found. That undercount caused every Bandzoogle /
 * multi-event JSON-LD venue to be flagged `extraction_gap` on its first qualify run.
 *
 * Fix shape (Shape A from the issue): walk all packets, build an `event_data.events[]`
 * summary array (compatible with `QualifyFingerprinter::count_events()`'s existing
 * `items[]` check), and surface `event_count` in both `event_data` and
 * `extraction_info`. The first event's full record stays at `event_data` top-level
 * for backwards compatibility with the chat tool and the CLI command, which only
 * read a single event's fields.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventScraperTest {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$register_callback = function () {
				wp_register_ability(
					'data-machine-events/test-event-scraper',
					array(
						'label'               => __( 'Test Event Scraper', 'data-machine-events' ),
						'description'         => __( 'Test universal web scraper compatibility with a target URL', 'data-machine-events' ),
						'category'            => 'datamachine-events-testing',
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
			};

			add_action( 'wp_abilities_api_init', $register_callback );

			self::$registered = true;
		}
	}

	public function executeAbility( array $input ): array|\WP_Error {
		$target_url = $input['target_url'] ?? '';

		if ( empty( $target_url ) ) {
			return new \WP_Error( 'missing_target_url', 'Missing required target_url parameter.', array( 'status' => 400 ) );
		}

		return $this->test( $target_url );
	}

	public function test( string $target_url ): array|\WP_Error {
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
			$warnings         = array_values(
				array_filter(
					$logs,
					static function ( array $entry ): bool {
						return ( $entry['level'] ?? '' ) === 'warning';
					}
				)
			);
			$warning_messages = array_map( fn( $w ) => $w['message'], $warnings );

			return new \WP_Error( 'scraper_failed', 'Scraper returned no results. ' . implode( '; ', $warning_messages ), array( 'status' => 500 ) );
		}

		// Walk every packet returned by get_fetch_data(). One packet per extracted
		// event — multi-event calendars (Bandzoogle, JSON-LD lists, Tribe REST,
		// etc.) produce N packets and we must surface the full count to the
		// qualify path (#265).
		$packet_entries = array();
		foreach ( $results as $packet_obj ) {
			$packet_array = $packet_obj->addTo( array() );
			$packet_entry = $packet_array[0] ?? array();
			if ( ! empty( $packet_entry ) ) {
				$packet_entries[] = $packet_entry;
			}
		}

		$packet_entry = $packet_entries[0] ?? array();
		$packet_data  = $packet_entry['data'] ?? array();
		$packet_meta  = $packet_entry['metadata'] ?? array();

		$body = $packet_data['body'] ?? '';
		if ( '' === $body && isset( $packet_entry['body'] ) ) {
			$body = (string) $packet_entry['body'];
		}

		$payload = json_decode( (string) $body, true );
		$event   = is_array( $payload ) ? ( $payload['event'] ?? null ) : null;

		// Build a summary list of every event across all packets. Compatible
		// with QualifyFingerprinter::count_events() which already handles
		// $event_data['items'] / $event_data['events'] as the multi-event signal.
		$all_events = $this->summarizeEventsFromPackets( $packet_entries );

		$extraction_info = array(
			'packet_title'      => $packet_data['title'] ?? '',
			'source_type'       => $packet_meta['source_type'] ?? '',
			'extraction_method' => $packet_meta['extraction_method'] ?? '',
			'event_count'       => count( $packet_entries ),
		);

		if ( is_array( $payload ) && isset( $payload['raw_html'] ) && is_string( $payload['raw_html'] ) ) {
			return array(
				'success'         => true,
				'status'          => 'warning',
				'target_url'      => $target_url,
				'event_data'      => array( 'raw_html' => $payload['raw_html'] ),
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

		// Vision flyer extraction - image stored for AI step processing.
		if ( is_array( $payload ) && ( $payload['source_type'] ?? '' ) === 'vision_flyer' ) {
			return array(
				'success'         => true,
				'status'          => 'ok',
				'target_url'      => $target_url,
				'event_data'      => array(
					'image_url' => $payload['image_url'] ?? '',
					'page_url'  => $payload['page_url'] ?? '',
				),
				'extraction_info' => array_merge(
					$extraction_info,
					array(
						'payload_type'      => 'vision_flyer',
						'requires_ai_step'  => true,
						'image_file_stored' => true,
						'extraction_method' => $payload['extraction_method'] ?? 'vision',
					)
				),
				'coverage_issues' => array(
					'missing_time'       => false,
					'missing_venue'      => false,
					'incomplete_address' => false,
					'time_data_warning'  => false,
					'vision_flyer'       => true,
				),
				'warnings'        => array( 'Vision flyer detected. Image stored in engine data. Pipeline requires AI step for event extraction.' ),
				'logs'            => array_slice( $logs, -20 ),
			);
		}

		if ( ! is_array( $event ) ) {
			return new \WP_Error( 'no_event_data', 'Payload did not contain an event object.', array( 'status' => 500 ) );
		}

		$event_data = array(
			'title'     => (string) ( $event['title'] ?? '' ),
			'startDate' => (string) ( $event['startDate'] ?? '' ),
			'startTime' => (string) ( $event['startTime'] ?? '' ),
			'endDate'   => (string) ( $event['endDate'] ?? '' ),
			'endTime'   => (string) ( $event['endTime'] ?? '' ),
			'timezone'  => (string) ( $event['timezone'] ?? $event['venueTimezone'] ?? '' ),
			'ticketUrl' => (string) ( $event['ticketUrl'] ?? '' ),
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

		// Surface the full event list and total count so qualify-path consumers
		// downstream consumers see the true
		// extraction count instead of just the first event (#265).
		//
		// `items` is the field QualifyFingerprinter::count_events() already
		// inspects (`isset($event_data['items'])`) — populating it lets the fix
		// land without requiring a matching consumer change.
		$event_data['items']       = $all_events;
		$event_data['event_count'] = count( $all_events );

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

	private function buildErrorResponse( string $message ): \WP_Error {
		return new \WP_Error( 'test_error', $message, array( 'status' => 400 ) );
	}

	/**
	 * Build a lightweight summary list of every event represented in the
	 * DataPacket array returned by UniversalWebScraper::get_fetch_data().
	 *
	 * Each input packet entry is the array shape produced by DataPacket::addTo()
	 * (i.e. has `data.body` as a JSON-encoded `{ event: {...}, ... }` blob, as
	 * built by StructuredDataProcessor::process()).
	 *
	 * Packets whose body is non-event (raw_html / vision_flyer) are skipped —
	 * those payload types are inherently single-event and already counted via
	 * the existing `event_data.title` / `event_data.raw_html` heuristics in
	 * QualifyFingerprinter::count_events().
	 *
	 * @param array $packet_entries Packet entries from DataPacket::addTo().
	 * @return array<int, array{title:string,startDate:string,startTime:string,ticketUrl:string}>
	 */
	private function summarizeEventsFromPackets( array $packet_entries ): array {
		$summary = array();

		foreach ( $packet_entries as $entry ) {
			$data = $entry['data'] ?? array();
			$body = $data['body'] ?? '';
			if ( '' === $body ) {
				continue;
			}

			$decoded = json_decode( (string) $body, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$event = $decoded['event'] ?? null;
			if ( ! is_array( $event ) ) {
				continue;
			}

			$summary[] = array(
				'title'     => (string) ( $event['title'] ?? '' ),
				'startDate' => (string) ( $event['startDate'] ?? '' ),
				'startTime' => (string) ( $event['startTime'] ?? '' ),
				'ticketUrl' => (string) ( $event['ticketUrl'] ?? '' ),
			);
		}

		return $summary;
	}
}
