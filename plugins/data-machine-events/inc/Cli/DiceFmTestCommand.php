<?php
/**
 * WP-CLI command for testing Dice FM API handler
 *
 * @package DataMachineEvents\Cli
 * @since 0.11.4
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\DiceFmTest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DiceFmTestCommand {

	/**
	 * Test Dice FM API handler with raw response data.
	 *
	 * ## OPTIONS
	 *
	 * --city=<city>
	 * : City name to search for events
	 *
	 * [--limit=<count>]
	 * : Max events to show (default: 5)
	 *
	 * [--format=<format>]
	 * : Output format (table or json, default: table)
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events test-dice-fm --city="Charleston"
	 *     wp data-machine-events test-dice-fm --city="New York" --limit=10
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$city = $assoc_args['city'] ?? '';
		if ( empty( $city ) ) {
			\WP_CLI::error( 'Missing required --city parameter.' );
		}

		$limit  = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : 5;
		$format = $assoc_args['format'] ?? 'table';

		$ability = new DiceFmTest();
		$result  = $ability->test( $city, $limit );

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$this->outputResult( $result );
	}

	private function outputResult( array $result ): void {
		\WP_CLI::log( '' );
		\WP_CLI::log( '=== API Configuration ===' );

		$api_config = $result['api_config'] ?? array();
		\WP_CLI::log( 'API Key: ' . ( $api_config['api_key'] ?? 'unknown' ) );
		\WP_CLI::log( 'Partner ID: ' . ( $api_config['partner_id'] ?? '(not set)' ) );
		\WP_CLI::log( 'City: ' . ( $api_config['city'] ?? '' ) );

		if ( ! $result['success'] ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( '=== Coverage Issues ===' );
			foreach ( $result['coverage_issues'] as $issue ) {
				\WP_CLI::warning( $issue );
			}
			$this->outputLogs( $result['logs'] ?? array() );
			\WP_CLI::error( 'Test failed.' );
			return;
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( '=== API Response ===' );
		$api_response = $result['api_response'] ?? array();
		\WP_CLI::log( 'Status: ' . ( $api_response['status_code'] ?? 0 ) . ' ' . ( $api_response['success'] ? 'OK' : 'Failed' ) );
		\WP_CLI::log( 'Events found: ' . ( $api_response['events_found'] ?? 0 ) );

		$events       = $result['events'] ?? array();
		$total_events = count( $events );

		foreach ( $events as $index => $event ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( sprintf( '=== Event %d of %d ===', $index + 1, $total_events ) );

			$raw = $event['raw'] ?? array();
			\WP_CLI::log( '[RAW] Name: ' . ( $raw['name'] ?? '' ) );
			\WP_CLI::log( '[RAW] Date: ' . ( $raw['date'] ?? '' ) );
			\WP_CLI::log( '[RAW] Date End: ' . ( $raw['date_end'] ?? '' ) );
			\WP_CLI::log( '[RAW] Venue: ' . ( $raw['venue'] ?? '' ) );
			\WP_CLI::log( '[RAW] Timezone: ' . ( $raw['timezone'] ?? '' ) );

			if ( isset( $raw['location'] ) && is_array( $raw['location'] ) ) {
				\WP_CLI::log( '[RAW] Location: ' . wp_json_encode( $raw['location'] ) );
			} else {
				\WP_CLI::log( '[RAW] Location: (none)' );
			}

			\WP_CLI::log( '---' );

			$mapped = $event['mapped'] ?? array();
			\WP_CLI::log( '[MAPPED] Title: ' . ( $mapped['title'] ?? '' ) );
			\WP_CLI::log( '[MAPPED] Start Date: ' . ( $mapped['startDate'] ?? '' ) );
			\WP_CLI::log( '[MAPPED] Start Time: ' . ( $mapped['startTime'] ?? '' ) );
			\WP_CLI::log( '[MAPPED] End Date: ' . ( $mapped['endDate'] ?? '' ) );
			\WP_CLI::log( '[MAPPED] End Time: ' . ( $mapped['endTime'] ?? '' ) );
			\WP_CLI::log( '[MAPPED] Venue: ' . ( $mapped['venue'] ?? '' ) );
			\WP_CLI::log( '[MAPPED] Venue Address: ' . ( $mapped['venueAddress'] ?? '(none)' ) );
			\WP_CLI::log( '[MAPPED] Venue City: ' . ( $mapped['venueCity'] ?? '(none)' ) );
			\WP_CLI::log( '[MAPPED] Venue State: ' . ( $mapped['venueState'] ?? '(none)' ) );
			\WP_CLI::log( '[MAPPED] Venue Timezone: ' . ( $mapped['venueTimezone'] ?? '(none)' ) );
			\WP_CLI::log( '[MAPPED] Ticket URL: ' . ( $mapped['ticketUrl'] ?? '' ) );

			$issues = $event['issues'] ?? array();
			if ( ! empty( $issues ) ) {
				\WP_CLI::log( '[ISSUES] ' . implode( ', ', $issues ) );
			}
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( '=== Coverage Issues ===' );
		$coverage_issues = $result['coverage_issues'] ?? array();
		if ( empty( $coverage_issues ) ) {
			\WP_CLI::log( 'None' );
		} else {
			foreach ( $coverage_issues as $issue ) {
				\WP_CLI::warning( $issue );
			}
		}

		$this->outputLogs( $result['logs'] ?? array() );

		if ( 'ok' === $result['status'] ) {
			\WP_CLI::success( 'Test completed successfully.' );
		} else {
			\WP_CLI::warning( 'Test completed with warnings.' );
		}
	}

	private function outputLogs( array $logs ): void {
		if ( empty( $logs ) ) {
			return;
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( '=== Logs (tail 20) ===' );

		foreach ( array_slice( $logs, -20 ) as $entry ) {
			$message = strtoupper( $entry['level'] ) . ': ' . $entry['message'];
			if ( ! empty( $entry['context'] ) ) {
				$message .= ' ' . wp_json_encode( $entry['context'] );
			}
			\WP_CLI::log( $message );
		}
	}
}
