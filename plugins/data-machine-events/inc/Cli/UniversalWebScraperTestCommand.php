<?php

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\EventScraperTest;

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

		$result = $this->callAbility( $target_url );
		$this->outputResult( $result );
	}

	private function callAbility( string $target_url ): array {
		$tester = new EventScraperTest();
		return $tester->test( $target_url );
	}

	private function outputResult( array $result ): void {
		\WP_CLI::log( 'Target URL: ' . $result['target_url'] );

		if ( ! $result['success'] ) {
			\WP_CLI::error( 'Failed to test scraper.' );

			if ( ! empty( $result['warnings'] ) ) {
				foreach ( $result['warnings'] as $warning ) {
					\WP_CLI::warning( $warning );
				}
			}

			if ( ! empty( $result['logs'] ) ) {
				\WP_CLI::log( 'Logs (tail):' );
				foreach ( array_slice( $result['logs'], -20 ) as $entry ) {
					$message = strtoupper( $entry['level'] ) . ': ' . $entry['message'];
					if ( ! empty( $entry['context'] ) ) {
						$message .= ' ' . wp_json_encode( $entry['context'] );
					}
					\WP_CLI::log( $message );
				}
			}

			return;
		}

		$extraction_info = $result['extraction_info'] ?? [];

		if ( isset( $extraction_info['packet_title'] ) && $extraction_info['packet_title'] !== '' ) {
			\WP_CLI::log( 'Packet title: ' . $extraction_info['packet_title'] );
		}
		if ( isset( $extraction_info['source_type'] ) && $extraction_info['source_type'] !== '' ) {
			\WP_CLI::log( 'Source type: ' . $extraction_info['source_type'] );
		}
		if ( isset( $extraction_info['extraction_method'] ) && $extraction_info['extraction_method'] !== '' ) {
			\WP_CLI::log( 'Extraction method: ' . $extraction_info['extraction_method'] );
		}

		$payload_type = $extraction_info['payload_type'] ?? '';

		if ( $payload_type === 'raw_html' ) {
			\WP_CLI::log( 'Payload type: raw_html (HTML fallback)' );
			\WP_CLI::warning( 'VENUE COVERAGE: No structured venue fields. Set venue override for reliable address/geocoding.' );
			\WP_CLI::log( 'Venue: (unknown; extracted by AI)' );
			\WP_CLI::log( 'Venue address: (missing)' );
			\WP_CLI::log( '--- Raw HTML Content ---' );
			if ( isset( $result['event_data'] ) && isset( $result['event_data']['raw_html'] ) ) {
				\WP_CLI::log( $result['event_data']['raw_html'] );
			}
		} elseif ( $payload_type !== 'event' ) {
			\WP_CLI::warning( 'Payload did not contain an event object.' );
			\WP_CLI::log( 'Payload type: ' . $payload_type );
		} else {
			$event_data = $result['event_data'] ?? [];
			\WP_CLI::log( 'Payload type: event' );
			\WP_CLI::log( 'Title: ' . ( $event_data['title'] ?? '' ) );
			\WP_CLI::log( 'Start Date: ' . ( $event_data['startDate'] ?? '' ) );
			\WP_CLI::log( 'Start Time: ' . ( $event_data['startTime'] ?? '' ) );
			\WP_CLI::log( 'End Date: ' . ( $event_data['endDate'] ?? '' ) );
			\WP_CLI::log( 'End Time: ' . ( $event_data['endTime'] ?? '' ) );
			\WP_CLI::log( 'Timezone: ' . ( $event_data['venueTimezone'] ?? '' ) );
			\WP_CLI::log( 'Venue: ' . ( $event_data['venue'] ?? '' ) );
			\WP_CLI::log( 'Venue address: ' . ( $event_data['venueAddress'] ?? '' ) );

			$coverage_issues = $result['coverage_issues'] ?? [];

			if ( ! empty( $coverage_issues ) ) {
				if ( $coverage_issues['time_data_warning'] ?? false ) {
					\WP_CLI::warning( 'TIME DATA: Missing start/end time - check ICS feed timezone handling or source data' );
				}
				if ( $coverage_issues['missing_venue'] ?? false ) {
					\WP_CLI::warning( 'VENUE COVERAGE: Missing venue name; set venue override.' );
				}
				if ( $coverage_issues['incomplete_address'] ?? false ) {
					\WP_CLI::warning( 'VENUE COVERAGE: Missing venue address fields (venueAddress/venueCity/venueState). Geocoding may fail; set venue override.' );
				}
			}

			$warnings = $result['warnings'] ?? [];

			if ( $result['status'] === 'warning' && ! empty( $coverage_issues ) ) {
				$warning_parts = [];
				if ( $coverage_issues['time_data_warning'] ?? false ) {
					$warning_parts[] = 'time data missing';
				}
				if ( ( $coverage_issues['missing_venue'] ?? false ) || ( $coverage_issues['incomplete_address'] ?? false ) ) {
					$warning_parts[] = 'venue/address incomplete';
				}
				\WP_CLI::warning( 'STATUS: WARNING (' . implode( ', ', $warning_parts ) . ')' );
			} elseif ( $result['status'] === 'ok' ) {
				\WP_CLI::log( 'STATUS: OK (venue/address/time coverage present)' );
			}

			if ( ! empty( $warnings ) ) {
				\WP_CLI::log( 'Warnings:' );
				foreach ( $warnings as $warning ) {
					\WP_CLI::log( '- ' . $warning );
				}
			}
		}

		if ( ! empty( $result['logs'] ) ) {
			\WP_CLI::log( 'Logs (tail):' );
			foreach ( array_slice( $result['logs'], -20 ) as $entry ) {
				$message = strtoupper( $entry['level'] ) . ': ' . $entry['message'];
				if ( ! empty( $entry['context'] ) ) {
					$message .= ' ' . wp_json_encode( $entry['context'] );
				}
				\WP_CLI::log( $message );
			}
		}
	}
}
