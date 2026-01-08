<?php

namespace DataMachineEvents\Cli;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UniversalWebScraperTestCommand {
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$target_url = (string) ( $assoc_args['target_url'] ?? '' );
		$target_url = trim( $target_url );

		if ( empty( $target_url ) ) {
			\WP_CLI::error( 'Missing required --target_url parameter.' );
		}

		$logs = [];
		add_action(
			'datamachine_log',
			static function ( string $level, string $message, array $context = [] ) use ( &$logs ): void {
				$logs[] = [
					'level' => $level,
					'message' => $message,
					'context' => $context,
				];
			},
			10,
			3
		);

		$config = [
			'source_url' => $target_url,
			'flow_step_id' => 'cli_test_' . wp_generate_uuid4(),
			'flow_id' => 'direct',
			'search' => '',
		];

		$handler = new UniversalWebScraper();
		$results = $handler->get_fetch_data( 'direct', $config, null );

		\WP_CLI::log( "Target URL: {$target_url}" );

		if ( empty( $results ) ) {
			\WP_CLI::warning( 'No events returned.' );
			if ( ! empty( $logs ) ) {
				\WP_CLI::log( 'Logs (tail):' );
				foreach ( array_slice( $logs, -20 ) as $entry ) {
					$message = strtoupper( $entry['level'] ) . ': ' . $entry['message'];
					if ( ! empty( $entry['context'] ) ) {
						$message .= ' ' . wp_json_encode( $entry['context'] );
					}
					\WP_CLI::log( $message );
				}
			}
			return;
		}

		$coverage_warning = false;

		$packet = $results[0];
		$packet_array = $packet->addTo( [] );
		$packet_entry = $packet_array[0] ?? [];
		$packet_data = $packet_entry['data'] ?? [];
		$packet_meta = $packet_entry['metadata'] ?? [];
		$body = $packet_data['body'] ?? '';
		if ( $body === '' && isset( $packet_entry['body'] ) ) {
			$body = (string) $packet_entry['body'];
		}
		$payload = json_decode( (string) $body, true );
		$event = is_array( $payload ) ? ( $payload['event'] ?? null ) : null;

		if ( isset( $packet_data['title'] ) ) {
			\WP_CLI::log( 'Packet title: ' . (string) $packet_data['title'] );
		}
		if ( isset( $packet_meta['source_type'] ) ) {
			\WP_CLI::log( 'Source type: ' . (string) $packet_meta['source_type'] );
		}
		if ( isset( $packet_meta['extraction_method'] ) ) {
			\WP_CLI::log( 'Extraction method: ' . (string) $packet_meta['extraction_method'] );
		}

		if ( is_array( $payload ) && isset( $payload['raw_html'] ) && is_string( $payload['raw_html'] ) ) {
			\WP_CLI::log( 'Payload type: raw_html (HTML fallback)' );
			\WP_CLI::warning( 'VENUE COVERAGE: No structured venue fields. Set venue override for reliable address/geocoding.' );
			$coverage_warning = true;
			\WP_CLI::log( 'Venue: (unknown; extracted by AI)' );
			\WP_CLI::log( 'Venue address: (missing)' );
			\WP_CLI::log( '--- Raw HTML Content ---' );
			\WP_CLI::log( $payload['raw_html'] );
		} elseif ( ! is_array( $event ) ) {
			\WP_CLI::warning( 'Payload did not contain an event object.' );
			\WP_CLI::log( 'Packet body (head): ' . substr( (string) $body, 0, 800 ) );
		} else {
			\WP_CLI::log( 'Payload type: event' );
			\WP_CLI::log( 'Title: ' . (string) ( $event['title'] ?? '' ) );
			\WP_CLI::log( 'Start: ' . (string) ( $event['startDate'] ?? '' ) );

			$venue_name = (string) ( $event['venue'] ?? '' );
			$venue_addr = (string) ( $event['venueAddress'] ?? '' );
			$venue_city = (string) ( $event['venueCity'] ?? '' );
			$venue_state = (string) ( $event['venueState'] ?? '' );
			$venue_zip = (string) ( $event['venueZip'] ?? '' );

			if ( is_array( $payload ) && isset( $payload['venue_metadata'] ) && is_array( $payload['venue_metadata'] ) ) {
				$venue_meta = $payload['venue_metadata'];
				$venue_addr = $venue_addr !== '' ? $venue_addr : (string) ( $venue_meta['venueAddress'] ?? '' );
				$venue_city = $venue_city !== '' ? $venue_city : (string) ( $venue_meta['venueCity'] ?? '' );
				$venue_state = $venue_state !== '' ? $venue_state : (string) ( $venue_meta['venueState'] ?? '' );
				$venue_zip = $venue_zip !== '' ? $venue_zip : (string) ( $venue_meta['venueZip'] ?? '' );
			}
			$city_state_zip = trim( $venue_city . ', ' . $venue_state . ' ' . $venue_zip );
			$city_state_zip = $city_state_zip === ',' ? '' : $city_state_zip;
			$venue_full = trim( implode( ', ', array_filter( [ $venue_addr, $city_state_zip ] ) ) );

			\WP_CLI::log( 'Venue: ' . $venue_name );
			\WP_CLI::log( 'Venue address: ' . $venue_full );

			if ( empty( trim( $venue_name ) ) ) {
				\WP_CLI::warning( 'VENUE COVERAGE: Missing venue name; set venue override.' );
				$coverage_warning = true;
			}

			if ( empty( trim( $venue_addr ) ) || empty( trim( $venue_city ) ) || empty( trim( $venue_state ) ) ) {
				\WP_CLI::warning( 'VENUE COVERAGE: Missing venue address fields (venueAddress/venueCity/venueState). Geocoding may fail; set venue override.' );
				$coverage_warning = true;
			}
		}

		if ( $coverage_warning ) {
			\WP_CLI::warning( 'STATUS: WARNING (venue/address coverage incomplete)' );
		} else {
			\WP_CLI::log( 'STATUS: OK (venue/address coverage present)' );
		}

		$warnings = array_values(
			array_filter(
				$logs,
				static function ( array $entry ): bool {
					return ( $entry['level'] ?? '' ) === 'warning';
				}
			)
		);

		if ( ! empty( $warnings ) ) {
			\WP_CLI::log( 'Warnings:' );
			foreach ( $warnings as $entry ) {
				\WP_CLI::log( '- ' . (string) $entry['message'] );
			}
		}
	}
}
