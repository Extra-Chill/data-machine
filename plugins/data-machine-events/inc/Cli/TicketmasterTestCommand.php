<?php
/**
 * WP-CLI command for testing Ticketmaster API handler
 *
 * @package DataMachineEvents\Cli
 * @since 0.11.4
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\TicketmasterTest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TicketmasterTestCommand {

	/**
	 * Test Ticketmaster API handler with raw response data.
	 *
	 * ## OPTIONS
	 *
	 * --classification_type=<type>
	 * : Event classification (music, sports, arts-theatre, etc.)
	 *
	 * [--location=<coords>]
	 * : Coordinates as "lat,lng" (defaults to Charleston: 32.7765,-79.9311)
	 *
	 * [--radius=<miles>]
	 * : Search radius in miles (default: 50)
	 *
	 * [--venue_id=<id>]
	 * : Specific Ticketmaster venue ID
	 *
	 * [--limit=<count>]
	 * : Max events to show (default: 5)
	 *
	 * [--format=<format>]
	 * : Output format (table or json, default: table)
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events test-ticketmaster --classification_type=music
	 *     wp data-machine-events test-ticketmaster --classification_type=music --location="32.7765,-79.9311" --radius=25
	 *     wp data-machine-events test-ticketmaster --classification_type=music --venue_id=KovZpZAJledA
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$classification_type = $assoc_args['classification_type'] ?? '';
		if ( empty( $classification_type ) ) {
			\WP_CLI::error( 'Missing required --classification_type parameter.' );
		}

		$location = $assoc_args['location'] ?? '';
		$radius   = isset( $assoc_args['radius'] ) ? intval( $assoc_args['radius'] ) : 50;
		$venue_id = $assoc_args['venue_id'] ?? '';
		$limit    = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : 5;
		$format   = $assoc_args['format'] ?? 'table';

		$ability = new TicketmasterTest();
		$result  = $ability->test( $classification_type, $location, $radius, $venue_id, $limit );

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
		\WP_CLI::log( 'Classification Type: ' . ( $api_config['classification_type'] ?? '' ) );
		\WP_CLI::log( 'Segment Name: ' . ( $api_config['segment_name'] ?? '' ) );
		\WP_CLI::log( 'Location: ' . ( $api_config['location'] ?? '' ) );
		\WP_CLI::log( 'Radius: ' . ( $api_config['radius'] ?? '' ) . ' miles' );

		if ( ! empty( $api_config['venue_id'] ) ) {
			\WP_CLI::log( 'Venue ID: ' . $api_config['venue_id'] );
		}

		if ( isset( $api_config['valid_classifications'] ) ) {
			\WP_CLI::log( 'Valid Classifications: ' . implode( ', ', $api_config['valid_classifications'] ) );
		}

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
			\WP_CLI::log( '[RAW] Status: ' . ( $raw['status'] ?? '' ) );

			if ( isset( $raw['priceRanges'] ) && is_array( $raw['priceRanges'] ) ) {
				\WP_CLI::log( '[RAW] Price Ranges: ' . wp_json_encode( $raw['priceRanges'] ) );
			} else {
				\WP_CLI::log( '[RAW] Price Ranges: (none)' );
			}

			\WP_CLI::log( '---' );

			$mapped = $event['mapped'] ?? array();
			\WP_CLI::log( '[MAPPED] Title: ' . ( $mapped['title'] ?? '' ) );
			\WP_CLI::log( '[MAPPED] Start Date: ' . ( $mapped['startDate'] ?? '' ) );
			\WP_CLI::log( '[MAPPED] Start Time: ' . ( $mapped['startTime'] ?? '' ) );
			\WP_CLI::log( '[MAPPED] Venue: ' . ( $mapped['venue'] ?? '' ) );
			\WP_CLI::log( '[MAPPED] Price: ' . ( $mapped['price'] ?? '(none)' ) );
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
