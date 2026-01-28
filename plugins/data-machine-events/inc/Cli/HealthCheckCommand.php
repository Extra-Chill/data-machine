<?php
/**
 * WP-CLI command for health checks
 *
 * Delegates to the unified datamachine/system-health-check ability.
 * Supports events, venues, handlers, system, and all check types.
 *
 * @package DataMachineEvents\Cli
 * @since 0.9.15
 */

namespace DataMachineEvents\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HealthCheckCommand {

	private const VALID_SCOPES     = array( 'upcoming', 'all', 'past' );
	private const VALID_CATEGORIES = array(
		'late_night_time',
		'midnight_time',
		'missing_time',
		'suspicious_end_time',
		'missing_venue',
		'missing_description',
		'broken_timezone',
		'invalid_encoding',
		'missing_meta_sync',
	);
	private const DEFAULT_LIMIT    = 25;
	private const DEFAULT_DAYS     = 90;

	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$type       = $assoc_args['type'] ?? 'events';
		$scope      = $assoc_args['scope'] ?? 'upcoming';
		$days_ahead = (int) ( $assoc_args['days_ahead'] ?? self::DEFAULT_DAYS );
		$limit      = (int) ( $assoc_args['limit'] ?? self::DEFAULT_LIMIT );
		$category   = $assoc_args['category'] ?? '';
		$format     = $assoc_args['format'] ?? 'table';
		$url        = $assoc_args['url'] ?? '';

		if ( ! in_array( $scope, self::VALID_SCOPES, true ) ) {
			\WP_CLI::error( 'Invalid scope. Must be one of: ' . implode( ', ', self::VALID_SCOPES ) );
		}

		if ( ! empty( $category ) && ! in_array( $category, self::VALID_CATEGORIES, true ) ) {
			\WP_CLI::error( 'Invalid category. Must be one of: ' . implode( ', ', self::VALID_CATEGORIES ) );
		}

		$ability = wp_get_ability( 'datamachine/system-health-check' );
		if ( ! $ability ) {
			\WP_CLI::error( 'System health check ability not available. Is data-machine active?' );
		}

		$result = $ability->execute(
			array(
				'types'   => array( $type ),
				'options' => array(
					'scope'      => $scope,
					'days_ahead' => $days_ahead,
					'limit'      => $limit,
					'category'   => $category,
					'url'        => $url,
				),
			)
		);

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( 'json' === $format ) {
			$this->outputJsonUnified( $result, $type );
			return;
		}

		$this->outputTableUnified( $result, $type, $category );
	}

	private function outputJsonUnified( array $data, string $type ): void {
		\WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function outputTableUnified( array $data, string $type, string $category ): void {
		\WP_CLI::log( 'Available check types: ' . implode( ', ', $data['available'] ?? array() ) );
		\WP_CLI::log( '' );

		$results = $data['results'] ?? array();

		foreach ( $results as $type_id => $type_data ) {
			$label  = $type_data['label'] ?? $type_id;
			$result = $type_data['result'] ?? array();

			\WP_CLI::log( "=== {$label} ===" );

			if ( isset( $result['error'] ) ) {
				\WP_CLI::warning( $result['error'] );
				\WP_CLI::log( '' );
				continue;
			}

			if ( 'system' === $type_id ) {
				$this->outputSystemDiagnostics( $result );
			} elseif ( 'events' === $type_id ) {
				$this->outputEventHealth( $result, $category );
			} elseif ( 'venues' === $type_id ) {
				$this->outputVenueHealth( $result );
			} elseif ( 'handlers' === $type_id ) {
				$this->outputHandlerTest( $result );
			} else {
				\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			}

			\WP_CLI::log( '' );
		}

		\WP_CLI::log( $data['summary'] ?? '' );
	}

	private function outputSystemDiagnostics( array $data ): void {
		\WP_CLI::log( 'Data Machine: ' . ( $data['version'] ?? 'unknown' ) );
		\WP_CLI::log( 'PHP: ' . ( $data['php_version'] ?? 'unknown' ) );
		\WP_CLI::log( 'WordPress: ' . ( $data['wp_version'] ?? 'unknown' ) );

		$rest_status = $data['rest_status'] ?? array();
		$namespace   = $rest_status['namespace_registered'] ?? false;
		\WP_CLI::log( 'REST API: ' . ( $namespace ? 'registered' : 'not registered' ) );

		$abilities = $data['abilities'] ?? array();
		\WP_CLI::log( 'Registered abilities: ' . count( $abilities ) );
	}

	private function outputEventHealth( array $data, string $category ): void {
		\WP_CLI::log( 'Scope: ' . ( $data['scope'] ?? 'unknown' ) );
		\WP_CLI::log( 'Total Scanned: ' . ( $data['total_scanned'] ?? 0 ) );
		\WP_CLI::log( '' );

		$categories_to_show = self::VALID_CATEGORIES;

		if ( ! empty( $category ) ) {
			$categories_to_show = array( $category );
		}

		foreach ( $categories_to_show as $cat ) {
			$cat_data = $data[ $cat ] ?? null;

			if ( null === $cat_data ) {
				continue;
			}

			$count  = $cat_data['count'] ?? 0;
			$events = $cat_data['events'] ?? array();

			$label = $this->getCategoryLabel( $cat );
			\WP_CLI::log( "--- {$label} ({$count}) ---" );

			if ( empty( $events ) ) {
				\WP_CLI::log( 'No issues found.' );
				\WP_CLI::log( '' );
				continue;
			}

			$table_data = array();
			foreach ( $events as $event ) {
				$table_data[] = array(
					'ID'    => $event['id'],
					'Title' => mb_substr( $event['title'], 0, 45 ),
					'Date'  => $event['date'] ?? 'N/A',
					'Venue' => mb_substr( $event['venue'] ?? 'N/A', 0, 25 ),
				);
			}

			\WP_CLI\Utils\format_items( 'table', $table_data, array( 'ID', 'Title', 'Date', 'Venue' ) );
			\WP_CLI::log( '' );
		}

		\WP_CLI::log( $data['message'] ?? '' );
	}

	private function outputVenueHealth( array $data ): void {
		\WP_CLI::log( 'Total Venues: ' . ( $data['total_venues'] ?? 0 ) );
		\WP_CLI::log( '' );

		$categories = array(
			'missing_address'     => 'Missing Address',
			'missing_coordinates' => 'Missing Coordinates',
			'missing_timezone'    => 'Missing Timezone',
			'missing_website'     => 'Missing Website',
			'suspicious_website'  => 'Suspicious Website (Ticket URL)',
		);

		foreach ( $categories as $cat => $label ) {
			$cat_data = $data[ $cat ] ?? null;

			if ( null === $cat_data ) {
				continue;
			}

			$count  = $cat_data['count'] ?? 0;
			$venues = $cat_data['venues'] ?? array();

			\WP_CLI::log( "--- {$label} ({$count}) ---" );

			if ( empty( $venues ) ) {
				\WP_CLI::log( 'No issues found.' );
				\WP_CLI::log( '' );
				continue;
			}

			$table_data = array();
			foreach ( $venues as $venue ) {
				$row = array(
					'ID'     => $venue['term_id'],
					'Name'   => mb_substr( $venue['name'], 0, 35 ),
					'Events' => $venue['event_count'] ?? 0,
				);

				if ( isset( $venue['suspicion_reason'] ) ) {
					$row['Reason'] = $venue['suspicion_reason'];
				}

				$table_data[] = $row;
			}

			$columns = array( 'ID', 'Name', 'Events' );
			if ( 'suspicious_website' === $cat ) {
				$columns[] = 'Reason';
			}

			\WP_CLI\Utils\format_items( 'table', $table_data, $columns );
			\WP_CLI::log( '' );
		}

		\WP_CLI::log( $data['message'] ?? '' );
	}

	private function outputHandlerTest( array $data ): void {
		$status = $data['status'] ?? 'unknown';
		\WP_CLI::log( 'Status: ' . $status );

		if ( isset( $data['target_url'] ) ) {
			\WP_CLI::log( 'URL: ' . $data['target_url'] );
		}

		if ( isset( $data['extraction_info'] ) ) {
			$info = $data['extraction_info'];
			\WP_CLI::log( 'Extraction Method: ' . ( $info['extraction_method'] ?? 'unknown' ) );
			\WP_CLI::log( 'Source Type: ' . ( $info['source_type'] ?? 'unknown' ) );
		}

		if ( ! empty( $data['warnings'] ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Warnings:' );
			foreach ( $data['warnings'] as $warning ) {
				\WP_CLI::warning( $warning );
			}
		}
	}

	private function getCategoryLabel( string $category ): string {
		$labels = array(
			'late_night_time'     => 'Late Night Time (midnight-4am)',
			'midnight_time'       => 'Suspicious Midnight Start',
			'missing_time'        => 'Missing Start Time',
			'suspicious_end_time' => 'Suspicious 11:59pm End Time',
			'missing_venue'       => 'Missing Venue',
			'missing_description' => 'Missing Description',
			'broken_timezone'     => 'Missing Venue Timezone',
			'invalid_encoding'    => 'Invalid Unicode Encoding',
			'missing_meta_sync'   => 'Missing Meta Sync (block ok, meta missing)',
		);

		return $labels[ $category ] ?? $category;
	}
}
