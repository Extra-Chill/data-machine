<?php
/**
 * WP-CLI command for querying venue events
 *
 * @package DataMachineEvents\Cli
 * @since 0.9.14
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\EventQueryAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GetVenueEventsCommand {

	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$venue = $assoc_args['venue'] ?? ( $args[0] ?? '' );
		$venue = trim( $venue );

		if ( empty( $venue ) ) {
			\WP_CLI::error( 'Missing required venue parameter. Use: wp datamachine-events get-venue-events <venue> or --venue=<venue>' );
		}

		$input = array(
			'venue'  => $venue,
			'limit'  => (int) ( $assoc_args['limit'] ?? 25 ),
			'status' => $assoc_args['status'] ?? 'any',
		);

		if ( ! empty( $assoc_args['published_before'] ) ) {
			$input['published_before'] = $assoc_args['published_before'];
		}
		if ( ! empty( $assoc_args['published_after'] ) ) {
			$input['published_after'] = $assoc_args['published_after'];
		}

		$abilities = new EventQueryAbilities();
		$result    = $abilities->executeGetVenueEvents( $input );

		$this->outputResult( $result );
	}

	private function outputResult( array $result ): void {
		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		$venue_info = $result['venue'] ?? array();
		\WP_CLI::log( 'Venue: ' . ( $venue_info['name'] ?? 'Unknown' ) );
		\WP_CLI::log( 'Term ID: ' . ( $venue_info['term_id'] ?? 'N/A' ) );
		\WP_CLI::log( 'Total Events: ' . ( $venue_info['total_events'] ?? 0 ) );
		\WP_CLI::log( 'Returned: ' . ( $result['returned_count'] ?? 0 ) );
		\WP_CLI::log( '' );

		$events = $result['events'] ?? array();

		if ( empty( $events ) ) {
			\WP_CLI::warning( 'No events found for this venue.' );
			return;
		}

		$table_data = array();
		foreach ( $events as $event ) {
			$table_data[] = array(
				'ID'         => $event['post_id'],
				'Title'      => mb_substr( $event['title'], 0, 50 ),
				'Status'     => $event['status'],
				'Start Date' => $event['start_date'] ?? 'N/A',
				'Published'  => substr( $event['published'], 0, 10 ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $table_data, array( 'ID', 'Title', 'Status', 'Start Date', 'Published' ) );
	}
}
