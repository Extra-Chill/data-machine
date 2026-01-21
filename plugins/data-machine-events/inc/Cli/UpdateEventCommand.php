<?php
/**
 * WP-CLI command for updating events
 *
 * Wraps EventUpdateAbilities for CLI consumption.
 *
 * @package DataMachineEvents\Cli
 * @since 0.9.15
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\EventUpdateAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UpdateEventCommand {

	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$event_ids_raw = $args[0] ?? '';

		if ( empty( $event_ids_raw ) ) {
			\WP_CLI::error( 'Missing required event ID(s). Usage: wp datamachine-events update-event <event_ids> [--startTime=<time>]' );
		}

		$event_ids = $this->parseEventIds( $event_ids_raw );

		if ( empty( $event_ids ) ) {
			\WP_CLI::error( 'No valid event IDs provided.' );
		}

		$format = $assoc_args['format'] ?? 'table';
		unset( $assoc_args['format'] );

		$fields = $this->extractUpdateFields( $assoc_args );

		if ( empty( $fields ) ) {
			\WP_CLI::error( 'No fields to update. Provide at least one of: --startDate, --startTime, --endDate, --endTime, --occurrenceDates, --venue, --price, --ticketUrl, --performer, --performerType, --eventStatus, --eventType, --description' );
		}

		$abilities = new EventUpdateAbilities();
		$result    = $this->executeUpdate( $abilities, $event_ids, $fields );

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( 'json' === $format ) {
			$this->outputJson( $result );
			return;
		}

		$this->outputTable( $result );
	}

	private function parseEventIds( string $raw ): array {
		$ids = array_map( 'trim', explode( ',', $raw ) );
		$ids = array_filter( $ids, fn( $id ) => is_numeric( $id ) && (int) $id > 0 );
		return array_map( 'intval', $ids );
	}

	private function extractUpdateFields( array $assoc_args ): array {
		$allowed_fields = array(
			'startDate',
			'startTime',
			'endDate',
			'endTime',
			'occurrenceDates',
			'venue',
			'price',
			'priceCurrency',
			'ticketUrl',
			'offerAvailability',
			'validFrom',
			'performer',
			'performerType',
			'organizer',
			'organizerType',
			'organizerUrl',
			'eventStatus',
			'previousStartDate',
			'eventType',
			'description',
		);

		$fields = array();

		foreach ( $allowed_fields as $field ) {
			if ( isset( $assoc_args[ $field ] ) ) {
				$value = $assoc_args[ $field ];

				// Parse JSON for array fields
				if ( 'occurrenceDates' === $field && is_string( $value ) ) {
					$decoded = json_decode( $value, true );
					if ( is_array( $decoded ) ) {
						$value = $decoded;
					}
				}

				$fields[ $field ] = $value;
			}
		}

		return $fields;
	}

	private function executeUpdate( EventUpdateAbilities $abilities, array $event_ids, array $fields ): array {
		if ( count( $event_ids ) === 1 ) {
			$params          = $fields;
			$params['event'] = $event_ids[0];
			return $abilities->executeUpdateEvent( $params );
		}

		$events = array();
		foreach ( $event_ids as $id ) {
			$event_update          = $fields;
			$event_update['event'] = $id;
			$events[]              = $event_update;
		}

		return $abilities->executeUpdateEvent( array( 'events' => $events ) );
	}

	private function outputJson( array $data ): void {
		\WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function outputTable( array $data ): void {
		$summary = $data['summary'] ?? array();
		$results = $data['results'] ?? array();

		\WP_CLI::log( 'Summary: ' . ( $data['message'] ?? '' ) );
		\WP_CLI::log( 'Updated: ' . ( $summary['updated'] ?? 0 ) . ', Failed: ' . ( $summary['failed'] ?? 0 ) . ', Total: ' . ( $summary['total'] ?? 0 ) );
		\WP_CLI::log( '' );

		if ( empty( $results ) ) {
			return;
		}

		$table_data = array();
		foreach ( $results as $result ) {
			$updated_fields = $result['updated_fields'] ?? array();
			$warnings       = $result['warnings'] ?? array();

			$fields_str  = implode( ', ', $updated_fields );
			$warning_str = implode( '; ', $warnings );

			$table_data[] = array(
				'ID'      => $result['post_id'] ?? $result['event'] ?? 'N/A',
				'Title'   => mb_substr( $result['title'] ?? 'N/A', 0, 40 ),
				'Status'  => $result['status'],
				'Fields'  => ! empty( $fields_str ) ? $fields_str : '-',
				'Warning' => ! empty( $warning_str ) ? $warning_str : '-',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $table_data, array( 'ID', 'Title', 'Status', 'Fields', 'Warning' ) );
	}
}
