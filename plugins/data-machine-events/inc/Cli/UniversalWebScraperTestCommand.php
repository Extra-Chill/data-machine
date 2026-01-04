<?php

namespace DataMachineEvents\Cli;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;

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

		$do_upsert = (bool) ( $assoc_args['upsert'] ?? false );
		$pipeline_id = (int) ( $assoc_args['pipeline_id'] ?? 1 );
		$flow_id = (int) ( $assoc_args['flow_id'] ?? 1 );
		$flow_step_id = (string) ( $assoc_args['flow_step_id'] ?? '' );
		$flow_step_id = $flow_step_id ? $flow_step_id : 'flow_step_' . wp_generate_uuid4();
		$venue_name = (string) ( $assoc_args['venue_name'] ?? '' );

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

		$db_jobs = new Jobs();
		$job_id  = $db_jobs->create_job( [
			'pipeline_id' => $pipeline_id,
			'flow_id' => $flow_id,
		] );

		if ( ! is_int( $job_id ) || $job_id <= 0 ) {
			\WP_CLI::error( 'Failed to create a job record.' );
		}

		$config = [
			'source_url' => $target_url,
			'flow_step_id' => $flow_step_id,
			'flow_id' => $flow_id,
			'search' => '',
		];

		if ( $venue_name ) {
			$config['venue_name'] = $venue_name;
		}

		$handler = new UniversalWebScraper();
		$results = $handler->get_fetch_data( $pipeline_id, $config, (string) $job_id );

		\WP_CLI::log( "Target URL: {$target_url}" );
		\WP_CLI::log( "Job ID: {$job_id}" );
		\WP_CLI::log( "Flow Step ID: {$flow_step_id}" );
		\WP_CLI::log( 'Packets: ' . count( $results ) );
		\WP_CLI::log( 'Note: HTML fallback packets are acceptable (AI can parse).' );

		if ( empty( $results ) ) {
			\WP_CLI::warning( 'No events returned.' );
			if ( ! empty( $logs ) ) {
				\WP_CLI::log( 'Logs (tail):' );
				foreach ( array_slice( $logs, -20 ) as $entry ) {
					\WP_CLI::log( strtoupper( $entry['level'] ) . ': ' . $entry['message'] );
				}
			}
			return;
		}

		$max = (int) ( $assoc_args['max'] ?? 3 );
		$max = $max > 0 ? $max : 3;

		$coverage_warning = false;

		foreach ( array_slice( $results, 0, $max ) as $index => $packet ) {
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

			\WP_CLI::log( "---- Packet #" . ( $index + 1 ) . " ----" );
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
				\WP_CLI::log( substr( $payload['raw_html'], 0, 800 ) );
				continue;
			}

			if ( ! is_array( $event ) ) {
				\WP_CLI::warning( 'Payload did not contain an event object.' );
				\WP_CLI::log( 'Packet body (head): ' . substr( (string) $body, 0, 800 ) );
				continue;
			}

			\WP_CLI::log( 'Payload type: event' );
			\WP_CLI::log( 'Title: ' . (string) ( $event['title'] ?? '' ) );
			\WP_CLI::log( 'Start: ' . (string) ( $event['startDate'] ?? '' ) );

			$venue_name_out = (string) ( $event['venue'] ?? '' );
			$venue_addr = (string) ( $event['venueAddress'] ?? '' );
			$venue_city = (string) ( $event['venueCity'] ?? '' );
			$venue_state = (string) ( $event['venueState'] ?? '' );

			if ( is_array( $payload ) && isset( $payload['venue_metadata'] ) && is_array( $payload['venue_metadata'] ) ) {
				$venue_meta = $payload['venue_metadata'];
				$venue_addr = $venue_addr !== '' ? $venue_addr : (string) ( $venue_meta['venueAddress'] ?? '' );
				$venue_city = $venue_city !== '' ? $venue_city : (string) ( $venue_meta['venueCity'] ?? '' );
				$venue_state = $venue_state !== '' ? $venue_state : (string) ( $venue_meta['venueState'] ?? '' );
			}
			$venue_full = trim( implode( ', ', array_filter( [ $venue_addr, trim( $venue_city . ' ' . $venue_state ) ] ) ) );

			\WP_CLI::log( 'Venue: ' . $venue_name_out );
			\WP_CLI::log( 'Venue address: ' . $venue_full );

			if ( empty( trim( $venue_name_out ) ) ) {
				\WP_CLI::warning( 'VENUE COVERAGE: Missing venue name; set venue override.' );
				$coverage_warning = true;
			}

			if ( empty( trim( $venue_addr ) ) || empty( trim( $venue_city ) ) || empty( trim( $venue_state ) ) ) {
				\WP_CLI::warning( 'VENUE COVERAGE: Missing venue address fields (venueAddress/venueCity/venueState). Geocoding may fail; set venue override.' );
				$coverage_warning = true;
			}

			if ( $do_upsert ) {
				$upsert = new EventUpsert();
				$response = $upsert->handle_tool_call( [
					'title' => (string) ( $event['title'] ?? '' ),
					'venue' => $venue_name_out,
					'startDate' => (string) ( $event['startDate'] ?? '' ),
					'venueAddress' => $venue_addr,
					'venueCity' => $venue_city,
					'venueState' => $venue_state,
					'job_id' => $job_id,
				], [] );

				$action = is_array( $response ) ? ( $response['data']['action'] ?? 'unknown' ) : 'unknown';
				$post_id = is_array( $response ) ? ( $response['data']['post_id'] ?? null ) : null;

				\WP_CLI::log( 'Upsert: ' . (string) $action . ' (post_id=' . (string) $post_id . ')' );
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
