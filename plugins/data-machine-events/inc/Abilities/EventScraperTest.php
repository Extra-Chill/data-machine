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
 * Fix shape: walk all packets and surface the exact `event_count` in both
 * `event_data` and `extraction_info`. The full `event_data.items[]` list remains
 * available for existing qualification consumers.
 *
 * Issue #511 adds config-aware source diagnostics only. Stable structured-event
 * counts collapse repeated packet identifiers, while raw sections and flyer
 * candidates have separate counters. None represent production eligibility.
 *
 * Issue #3869: generic handler execution is delegated to core's
 * `datamachine/test-handler` ability in lossless `raw` output mode. This
 * ability keeps only the events-domain interpretation of the returned packet
 * envelopes. Raw mode is bounded (at most 100 packets / 5 MiB), so the
 * ability requests the maximum bounds by default and surfaces a truncation
 * diagnostic whenever packets were capped or omitted.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachine\Abilities\PermissionHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventScraperTest {

	/**
	 * Core ability that performs the generic handler dry-run.
	 */
	public const TEST_HANDLER_ABILITY = 'datamachine/test-handler';

	/**
	 * Handler slug executed through the test-handler ability.
	 */
	public const HANDLER_SLUG = 'universal_web_scraper';

	/**
	 * Raw-mode packet bound enforced by datamachine/test-handler.
	 */
	public const MAX_PACKET_LIMIT = 100;

	/**
	 * Raw-mode response byte bounds enforced by datamachine/test-handler.
	 */
	public const MIN_BYTE_LIMIT = 4096;
	public const MAX_BYTE_LIMIT = 5242880;

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
							'type'                 => 'object',
							'required'             => array( 'target_url' ),
							'additionalProperties' => false,
							'properties'           => array(
								'target_url'     => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => 'Target URL to test. Overrides handler_config.source_url.',
								),
								'handler_config' => array(
									'type'                 => 'object',
									'description'          => 'Optional persisted universal web scraper config to apply during source extraction.',
									'additionalProperties' => false,
									'properties'           => array(
										'source_url'       => array(
											'type'   => 'string',
											'format' => 'uri',
										),
										'search'           => array( 'type' => 'string' ),
										'exclude_keywords' => array( 'type' => 'string' ),
										'venue'            => array( 'type' => array( 'integer', 'string' ) ),
										'venue_name'       => array( 'type' => 'string' ),
										'venue_address'    => array( 'type' => 'string' ),
										'venue_city'       => array( 'type' => 'string' ),
										'venue_state'      => array( 'type' => 'string' ),
										'venue_zip'        => array( 'type' => 'string' ),
										'venue_country'    => array( 'type' => 'string' ),
										'venue_phone'      => array( 'type' => 'string' ),
										'venue_website'    => array( 'type' => 'string' ),
										'venue_capacity'   => array( 'type' => array( 'integer', 'string' ) ),
										'max_items'        => array( 'type' => array( 'integer', 'string' ) ),
									),
								),
								'output_mode'    => array(
									'type'        => 'string',
									'enum'        => array( 'compact', 'raw' ),
									'default'     => 'compact',
									'description' => 'Compact (default) returns the event diagnostics. Raw additionally returns raw_events, the test-handler packet envelopes, and the packet omission count from the same single handler execution.',
								),
								'limit'          => array(
									'type'        => 'integer',
									'minimum'     => 0,
									'maximum'     => self::MAX_PACKET_LIMIT,
									'default'     => self::MAX_PACKET_LIMIT,
									'description' => 'Maximum packets materialized and returned, forwarded to datamachine/test-handler raw mode (1-100). Defaults to 100 so diagnostics do not undercount; 0 also means the default.',
								),
								'byte_limit'     => array(
									'type'        => 'integer',
									'minimum'     => self::MIN_BYTE_LIMIT,
									'maximum'     => self::MAX_BYTE_LIMIT,
									'default'     => self::MAX_BYTE_LIMIT,
									'description' => 'Maximum serialized bytes for the delegated raw test-handler response (4096-5242880, default 5242880). Whole packets are omitted rather than partially truncated.',
								),
							),
						),
						'output_schema'       => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'success', 'status', 'target_url', 'event_data', 'extraction_info', 'coverage_issues', 'warnings', 'logs' ),
							'properties'           => array(
								'success'              => array( 'type' => 'boolean' ),
								'status'               => array(
									'type' => 'string',
									'enum' => array( 'ok', 'warning', 'error' ),
								),
								'target_url'           => array(
									'type'   => 'string',
									'format' => 'uri',
								),
								'event_data'           => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'properties'           => array(
										'title'        => array( 'type' => 'string' ),
										'startDate'    => array( 'type' => 'string' ),
										'startTime'    => array( 'type' => 'string' ),
										'endDate'      => array( 'type' => 'string' ),
										'endTime'      => array( 'type' => 'string' ),
										'timezone'     => array( 'type' => 'string' ),
										'ticketUrl'    => array( 'type' => 'string' ),
										'venue'        => array( 'type' => 'string' ),
										'venueAddress' => array( 'type' => 'string' ),
										'venueCity'    => array( 'type' => 'string' ),
										'venueState'   => array( 'type' => 'string' ),
										'venueZip'     => array( 'type' => 'string' ),
										'event_count'  => array(
											'type'    => 'integer',
											'minimum' => 0,
										),
										'items'        => array(
											'type'  => 'array',
											'items' => array(
												'type' => 'object',
												'additionalProperties' => false,
												'properties' => array(
													'title'     => array( 'type' => 'string' ),
													'startDate' => array( 'type' => 'string' ),
													'startTime' => array( 'type' => 'string' ),
													'ticketUrl' => array( 'type' => 'string' ),
												),
											),
										),
										'raw_html'     => array( 'type' => 'string' ),
										'image_url'    => array( 'type' => 'string' ),
										'page_url'     => array( 'type' => 'string' ),
									),
								),
								'extraction_info'      => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'required'             => array(
										'packet_title',
										'source_type',
										'extraction_method',
										'payload_type',
										'event_count',
										'extracted_packet_count',
										'unique_source_event_count',
										'duplicate_packet_count',
										'production_max_items',
										'candidate_packet_count',
										'raw_section_count',
										'flyer_candidate_count',
										'context_supplied',
										'enrichment',
									),
									'properties'           => array(
										'packet_title'     => array( 'type' => 'string' ),
										'source_type'      => array( 'type' => 'string' ),
										'extraction_method' => array( 'type' => 'string' ),
										'payload_type'     => array(
											'type' => 'string',
											'enum' => array( 'event', 'raw_html', 'vision_flyer' ),
										),
										'event_count'      => array(
											'type'        => 'integer',
											'minimum'     => 0,
											'description' => 'Unique structured events extracted from the source.',
										),
										'extracted_packet_count' => array(
											'type'        => 'integer',
											'minimum'     => 0,
											'description' => 'All packets returned by the delegated test-handler execution before stable-identifier deduplication.',
										),
										'unique_source_event_count' => array(
											'type'        => 'integer',
											'minimum'     => 0,
											'description' => 'Unique structured events after stable-identifier deduplication.',
										),
										'duplicate_packet_count' => array(
											'type'        => 'integer',
											'minimum'     => 0,
											'description' => 'Packets removed because their stable identifier repeated.',
										),
										'production_max_items' => array(
											'type'        => array( 'integer', 'null' ),
											'minimum'     => 0,
											'description' => 'Configured production cap, reported but not applied to diagnostic extraction.',
										),
										'candidate_packet_count' => array(
											'type'        => 'integer',
											'minimum'     => 0,
											'description' => 'Non-structured raw-section and flyer candidate packets.',
										),
										'raw_section_count' => array(
											'type'        => 'integer',
											'minimum'     => 0,
											'description' => 'Raw HTML section candidates requiring downstream interpretation.',
										),
										'flyer_candidate_count' => array(
											'type'        => 'integer',
											'minimum'     => 0,
											'description' => 'Vision flyer candidates requiring downstream interpretation.',
										),
										'context_supplied' => array( 'type' => 'boolean' ),
										'omitted_packet_count' => array(
											'type'        => 'integer',
											'minimum'     => 0,
											'description' => 'Packets materialized by the handler but omitted from the delegated raw response. Present only when output was truncated.',
										),
										'truncation_reasons' => array(
											'type'        => 'array',
											'items'       => array( 'type' => 'string' ),
											'description' => 'test-handler truncation reasons (packet_limit, byte_limit, ...). Present only when output was truncated.',
										),
										'requires_ai_step' => array( 'type' => 'boolean' ),
										'image_file_stored' => array( 'type' => 'boolean' ),
										'enrichment'       => array(
											'type'       => 'object',
											'additionalProperties' => false,
											'required'   => array( 'followed', 'filled' ),
											'properties' => array(
												'followed' => array(
													'type' => 'integer',
													'minimum' => 0,
													'description' => 'Detail/ticket pages followed by the enrichment stage.',
												),
												'filled'   => array(
													'type' => 'object',
													'additionalProperties' => array( 'type' => 'integer' ),
													'description' => 'Fields filled by enrichment, keyed by field name with fill counts.',
												),
											),
										),
									),
								),
								'coverage_issues'      => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'properties'           => array(
										'missing_time'  => array( 'type' => 'boolean' ),
										'missing_venue' => array( 'type' => 'boolean' ),
										'incomplete_address' => array( 'type' => 'boolean' ),
										'time_data_warning' => array( 'type' => 'boolean' ),
										'raw_html_fallback' => array( 'type' => 'boolean' ),
										'vision_flyer'  => array( 'type' => 'boolean' ),
									),
								),
								'warnings'             => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'logs'                 => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'level'   => array( 'type' => 'string' ),
											'message' => array( 'type' => 'string' ),
											'context' => array( 'type' => 'object' ),
										),
									),
								),
								'output_mode'          => array(
									'type' => 'string',
									'enum' => array( 'compact', 'raw' ),
								),
								'raw_events'           => array(
									'type'        => 'array',
									'description' => 'Raw mode only: complete decoded event object of each unique structured packet, after stable-identifier deduplication.',
									'items'       => array(
										'type' => 'object',
										'additionalProperties' => true,
									),
								),
								'packets'              => array(
									'type'        => 'array',
									'description' => 'Raw mode only: raw packet envelopes returned by datamachine/test-handler.',
									'items'       => array(
										'type' => 'object',
										'additionalProperties' => true,
									),
								),
								'packet_count'         => array(
									'type'        => 'integer',
									'minimum'     => 0,
									'description' => 'Raw mode only: packets materialized by the handler.',
								),
								'omitted_packet_count' => array(
									'type'        => 'integer',
									'minimum'     => 0,
									'description' => 'Raw mode only: test-handler omitted packet count.',
								),
								'limits'               => array(
									'type'                 => 'object',
									'additionalProperties' => true,
									'description'          => 'Raw mode only: packet and byte bounds applied by test-handler.',
								),
								'truncation'           => array(
									'type'                 => 'object',
									'additionalProperties' => true,
									'description'          => 'Raw mode only: test-handler truncation report.',
								),
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

		$handler_config = array_key_exists( 'handler_config', $input ) && is_array( $input['handler_config'] )
			? $input['handler_config']
			: null;

		$options = array();
		foreach ( array( 'output_mode', 'limit', 'byte_limit' ) as $option ) {
			if ( array_key_exists( $option, $input ) ) {
				$options[ $option ] = $input[ $option ];
			}
		}

		return $this->test( $target_url, $handler_config, $options );
	}

	/**
	 * Run the universal web scraper against a URL and interpret its packets.
	 *
	 * The handler executes exactly once, through `datamachine/test-handler`
	 * in raw output mode. Compact and raw results are projections of that
	 * single execution.
	 *
	 * @param string     $target_url     Target URL.
	 * @param array|null $handler_config Optional persisted handler config.
	 * @param array      $options        Optional output_mode (compact|raw), limit, byte_limit.
	 * @return array|\WP_Error
	 */
	public function test( string $target_url, ?array $handler_config = null, array $options = array() ): array|\WP_Error {
		$output_mode = 'raw' === ( $options['output_mode'] ?? 'compact' ) ? 'raw' : 'compact';
		$limits      = self::normalizeLimits( $options );

		$logs         = array();
		$log_listener = static function ( string $level, string $message, array $context = array() ) use ( &$logs ): void {
			$logs[] = array(
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			);
		};
		add_action( 'datamachine_log', $log_listener, 10, 3 );

		$config               = array_merge(
			$handler_config ?? array(),
			array(
				'source_url' => $target_url,
			)
		);
		$production_max_items = null;
		if ( array_key_exists( 'max_items', $config ) ) {
			$production_max_items = max( 0, (int) $config['max_items'] );
			unset( $config['max_items'] );
		}

		try {
			$response = $this->runTestHandler( $config, $limits );
		} finally {
			remove_action( 'datamachine_log', $log_listener, 10 );
		}

		if ( is_wp_error( $response ) ) {
			if ( 'handler_execution_failed' === $response->get_error_code() ) {
				$failure_messages = $this->failureMessages( $logs );
				$message          = empty( $failure_messages ) ? $response->get_error_message() : implode( '; ', $failure_messages );
				return new \WP_Error( 'scraper_failed', $message, array( 'status' => 502 ) );
			}
			return $response;
		}

		$packet_count = (int) ( $response['packet_count'] ?? 0 );
		$raw_packets  = is_array( $response['packets'] ?? null ) ? array_values( $response['packets'] ) : array();
		$truncation   = is_array( $response['truncation'] ?? null ) ? $response['truncation'] : array();
		$omitted      = (int) ( $truncation['omitted_packet_count'] ?? max( 0, $packet_count - count( $raw_packets ) ) );

		if ( 0 === $packet_count ) {
			return new \WP_Error( 'scraper_failed', 'Scraper returned no results. ' . implode( '; ', $this->failureMessages( $logs ) ), array( 'status' => 500 ) );
		}

		// Walk every packet returned by the handler. One packet per extracted
		// event — multi-event calendars (Bandzoogle, JSON-LD lists, Tribe REST,
		// etc.) produce N packets and we must surface the full count to the
		// qualify path (#265).
		$packet_entries = array();
		foreach ( $raw_packets as $packet_entry ) {
			if ( is_array( $packet_entry ) && ! empty( $packet_entry ) ) {
				$packet_entries[] = $packet_entry;
			}
		}

		if ( empty( $packet_entries ) ) {
			return new \WP_Error(
				'scraper_output_truncated',
				sprintf(
					'Scraper materialized %d packets but all were omitted from the bounded test-handler response (reasons: %s). Raise byte_limit.',
					$packet_count,
					implode( ', ', (array) ( $truncation['reasons'] ?? array() ) )
				),
				array(
					'status'     => 500,
					'truncation' => $truncation,
				)
			);
		}

		$context = array(
			'output_mode' => $output_mode,
			'response'    => $response,
			'raw_packets' => $raw_packets,
			'omitted'     => $omitted,
			'truncation'  => $truncation,
		);

		$inventory       = $this->analyzePacketEntries( $packet_entries, null !== $handler_config, $production_max_items );
		$packet_entries  = $inventory['packet_entries'];
		$extraction_info = $inventory['extraction_info'];

		$packet_entry = $packet_entries[0] ?? array();
		$packet_data  = $packet_entry['data'] ?? array();
		$packet_meta  = $packet_entry['metadata'] ?? array();

		$body = $packet_data['body'] ?? '';
		if ( '' === $body && isset( $packet_entry['body'] ) ) {
			$body = (string) $packet_entry['body'];
		}

		$payload = json_decode( (string) $body, true );
		$event   = is_array( $payload ) ? ( $payload['event'] ?? null ) : null;

		$all_events = $this->summarizeEventsFromPackets( $packet_entries );

		$extraction_info['packet_title']      = $packet_data['title'] ?? '';
		$extraction_info['source_type']       = $packet_meta['source_type'] ?? '';
		$extraction_info['extraction_method'] = $packet_meta['extraction_method'] ?? '';
		$extraction_info['enrichment']        = $this->enrichmentSummary( $logs );

		if ( is_array( $payload ) && isset( $payload['raw_html'] ) && is_string( $payload['raw_html'] ) ) {
			return $this->finalizeResult( array(
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
			), $packet_entries, $context );
		}

		// Vision flyer extraction - image stored for AI step processing.
		if ( is_array( $payload ) && ( $payload['source_type'] ?? '' ) === 'vision_flyer' ) {
			return $this->finalizeResult( array(
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
			), $packet_entries, $context );
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

		if ( isset( $payload['venue_metadata'] ) && is_array( $payload['venue_metadata'] ) ) {
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

		// Preserve the full list until every qualification consumer reads event_count.
		$event_data['items']       = $all_events;
		$event_data['event_count'] = $extraction_info['event_count'];

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
					return 'warning' === $entry['level'];
				}
			)
		);
		foreach ( $log_warnings as $warning ) {
			$warnings[] = $warning['message'];
		}

		return $this->finalizeResult( array(
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
		), $packet_entries, $context );
	}

	/**
	 * Clamp caller-supplied limits to the datamachine/test-handler raw bounds.
	 *
	 * Defaults request the maximum bounds so a diagnostic scrape does not
	 * undercount events relative to walking every packet.
	 *
	 * @param array $options Caller options (limit, byte_limit).
	 * @return array{limit:int,byte_limit:int}
	 */
	public static function normalizeLimits( array $options ): array {
		$limit      = (int) ( $options['limit'] ?? 0 );
		$byte_limit = (int) ( $options['byte_limit'] ?? self::MAX_BYTE_LIMIT );

		return array(
			'limit'      => $limit > 0 ? min( self::MAX_PACKET_LIMIT, $limit ) : self::MAX_PACKET_LIMIT,
			'byte_limit' => max( self::MIN_BYTE_LIMIT, min( self::MAX_BYTE_LIMIT, $byte_limit ) ),
		);
	}

	/**
	 * Execute the scraper once through core's generic test-handler ability.
	 *
	 * The caller of test-event-scraper has already passed this ability's own
	 * manage_options gate (or is trusted server code: CLI, chat tool, tests).
	 * The nested call runs through the Abilities API so test-handler input and
	 * output validation still apply, inside PermissionHelper::run_as_system()
	 * so its Data Machine permission check does not re-authorize a delegation
	 * the outer gate already approved. Agent-token contexts keep their own
	 * capability ceiling because PermissionHelper evaluates agents first.
	 *
	 * @param array $config Handler config (source_url plus optional overrides).
	 * @param array $limits Normalized limit/byte_limit.
	 * @return array|\WP_Error Raw test-handler response.
	 */
	private function runTestHandler( array $config, array $limits ): array|\WP_Error {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( self::TEST_HANDLER_ABILITY ) : null;
		if ( ! $ability ) {
			return new \WP_Error( 'test_handler_unavailable', 'The datamachine/test-handler ability is unavailable; Data Machine core with raw test-handler output is required.', array( 'status' => 500 ) );
		}

		$input = array(
			'handler_slug' => self::HANDLER_SLUG,
			'config'       => $config,
			'output_mode'  => 'raw',
			'limit'        => $limits['limit'],
			'byte_limit'   => $limits['byte_limit'],
		);

		$execute = static fn() => $ability->execute( $input );
		$result  = class_exists( PermissionHelper::class )
			? PermissionHelper::run_as_system( $execute )
			: $execute();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) || 'raw' !== ( $result['output_mode'] ?? '' ) ) {
			return new \WP_Error( 'test_handler_invalid_response', 'datamachine/test-handler did not return a raw packet response.', array( 'status' => 500 ) );
		}

		return $result;
	}

	/**
	 * Collect warning/error log messages for failure reporting.
	 *
	 * @param array $logs Captured datamachine_log entries.
	 * @return string[]
	 */
	private function failureMessages( array $logs ): array {
		$messages = array();
		foreach ( $logs as $entry ) {
			if ( in_array( $entry['level'], array( 'warning', 'error' ), true ) ) {
				$messages[] = (string) ( $entry['context']['error'] ?? $entry['message'] );
			}
		}

		return array_values( array_unique( $messages ) );
	}

	/**
	 * Apply truncation diagnostics and the raw projection to a result.
	 *
	 * Compact results are unchanged unless test-handler capped or omitted
	 * packets, in which case the omission is surfaced as a warning plus
	 * extraction_info counters so event_count is never silently low.
	 *
	 * @param array $result         Compact result.
	 * @param array $packet_entries Unique (deduplicated) packet entries.
	 * @param array $context        Delegation context from test().
	 * @return array
	 */
	private function finalizeResult( array $result, array $packet_entries, array $context ): array {
		$truncation = $context['truncation'];
		$reasons    = array_values( array_filter( (array) ( $truncation['reasons'] ?? array() ), 'is_string' ) );
		$omitted    = (int) $context['omitted'];
		$limits     = is_array( $context['response']['limits'] ?? null ) ? $context['response']['limits'] : array();

		$packet_capped = in_array( 'packet_limit', $reasons, true );
		if ( $omitted > 0 || $packet_capped ) {
			$result['extraction_info']['omitted_packet_count'] = $omitted;
			$result['extraction_info']['truncation_reasons']   = $reasons;

			if ( $packet_capped ) {
				$result['warnings'][] = sprintf(
					'PACKET LIMIT: Handler output was capped at %d packets; the source may contain more events than event_count reports.',
					(int) ( $limits['packet_count'] ?? self::MAX_PACKET_LIMIT )
				);
			}
			if ( $omitted > 0 ) {
				$result['warnings'][] = sprintf(
					'BYTE LIMIT: %d of %d packets were omitted from the bounded test-handler response (reasons: %s); counts reflect returned packets only.',
					$omitted,
					(int) ( $context['response']['packet_count'] ?? 0 ),
					implode( ', ', $reasons )
				);
			}
			if ( 'ok' === ( $result['status'] ?? '' ) ) {
				$result['status'] = 'warning';
			}
		}

		if ( 'raw' !== $context['output_mode'] ) {
			return $result;
		}

		$result['output_mode']          = 'raw';
		$result['raw_events']           = $this->rawEventsFromPackets( $packet_entries );
		$result['packets']              = $context['raw_packets'];
		$result['packet_count']         = (int) ( $context['response']['packet_count'] ?? 0 );
		$result['omitted_packet_count'] = $omitted;
		$result['limits']               = $limits;
		$result['truncation']           = $truncation;

		return $result;
	}

	/**
	 * Decode the complete event object of each structured packet.
	 *
	 * @param array $packet_entries Unique packet entries.
	 * @return array<int, array>
	 */
	private function rawEventsFromPackets( array $packet_entries ): array {
		$events = array();
		foreach ( $packet_entries as $entry ) {
			$decoded = json_decode( (string) ( $entry['data']['body'] ?? '' ), true );
			if ( is_array( $decoded ) && is_array( $decoded['event'] ?? null ) ) {
				$events[] = $decoded['event'];
			}
		}

		return $events;
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

	/**
	 * Collapse repeated packets into stable unique source identities.
	 *
	 * This is an extraction diagnostic, not a production eligibility result.
	 *
	 * @param array $packet_entries Packet entries from DataPacket::addTo().
	 * @return array Unique packet entries.
	 */
	private function uniquePacketEntries( array $packet_entries ): array {
		$unique = array();
		$seen   = array();

		foreach ( $packet_entries as $entry ) {
			$identifier = (string) ( $entry['metadata']['item_identifier'] ?? '' );
			if ( '' !== $identifier ) {
				if ( isset( $seen[ $identifier ] ) ) {
					continue;
				}
				$seen[ $identifier ] = true;
			}
			$unique[] = $entry;
		}

		return $unique;
	}

	/**
	 * Build source-inventory counts without applying production selection state.
	 *
	 * @param array    $packet_entries       Extracted packet entries.
	 * @param bool     $context_supplied     Whether handler configuration was supplied.
	 * @param int|null $production_max_items Persisted production cap, if configured.
	 * @return array{packet_entries:array,extraction_info:array}
	 */
	private function analyzePacketEntries( array $packet_entries, bool $context_supplied, ?int $production_max_items ): array {
		$extracted_packet_count = count( $packet_entries );
		$packet_entries         = $this->uniquePacketEntries( $packet_entries );
		$structured_event_count = 0;
		$raw_section_count      = 0;
		$flyer_candidate_count  = 0;
		foreach ( $packet_entries as $entry ) {
			$body    = (string) ( $entry['data']['body'] ?? '' );
			$payload = json_decode( $body, true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			if ( is_array( $payload['event'] ?? null ) ) {
				++$structured_event_count;
			} elseif ( isset( $payload['raw_html'] ) && is_string( $payload['raw_html'] ) ) {
				++$raw_section_count;
			} elseif ( 'vision_flyer' === ( $payload['source_type'] ?? '' ) ) {
				++$flyer_candidate_count;
			}
		}
		$candidate_packet_count = $raw_section_count + $flyer_candidate_count;

		return array(
			'packet_entries'  => $packet_entries,
			'extraction_info' => array(
				'packet_title'              => '',
				'source_type'               => '',
				'extraction_method'         => '',
				'event_count'               => $structured_event_count,
				'extracted_packet_count'    => $extracted_packet_count,
				'unique_source_event_count' => $structured_event_count,
				'duplicate_packet_count'    => $extracted_packet_count - count( $packet_entries ),
				'production_max_items'      => $production_max_items,
				'candidate_packet_count'    => $candidate_packet_count,
				'raw_section_count'         => $raw_section_count,
				'flyer_candidate_count'     => $flyer_candidate_count,
				'context_supplied'          => $context_supplied,
			),
		);
	}

	/**
	 * Aggregate enrichment-stage summaries from the collected run logs.
	 *
	 * The Detail Page Enricher emits one info log per run
	 * ("Detail Page Enricher: Run summary") with followed/filled/skipped
	 * counts; multi-page runs log once per page, so counts are summed.
	 *
	 * @param array $logs Collected datamachine_log entries.
	 * @return array{followed: int, filled: array<string,int>}
	 */
	private function enrichmentSummary( array $logs ): array {
		$followed = 0;
		$filled   = array();

		foreach ( $logs as $entry ) {
			if ( 'Detail Page Enricher: Run summary' !== ( $entry['message'] ?? '' ) ) {
				continue;
			}

			$context   = is_array( $entry['context'] ?? null ) ? $entry['context'] : array();
			$followed += (int) ( $context['followed'] ?? 0 );

			foreach ( ( $context['filled'] ?? array() ) as $field => $count ) {
				$filled[ $field ] = ( $filled[ $field ] ?? 0 ) + (int) $count;
			}
		}

		return array(
			'followed' => $followed,
			'filled'   => $filled,
		);
	}
}
