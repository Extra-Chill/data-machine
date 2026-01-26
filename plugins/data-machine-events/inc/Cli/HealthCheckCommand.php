<?php
/**
 * WP-CLI command for event health checks
 *
 * Wraps EventHealthAbilities for CLI consumption.
 *
 * @package DataMachineEvents\Cli
 * @since 0.9.15
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\EventHealthAbilities;

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
	);
	private const DEFAULT_LIMIT    = 25;
	private const DEFAULT_DAYS     = 90;

	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$scope      = $assoc_args['scope'] ?? 'upcoming';
		$days_ahead = (int) ( $assoc_args['days_ahead'] ?? self::DEFAULT_DAYS );
		$limit      = (int) ( $assoc_args['limit'] ?? self::DEFAULT_LIMIT );
		$category   = $assoc_args['category'] ?? '';
		$format     = $assoc_args['format'] ?? 'table';

		if ( ! in_array( $scope, self::VALID_SCOPES, true ) ) {
			\WP_CLI::error( 'Invalid scope. Must be one of: ' . implode( ', ', self::VALID_SCOPES ) );
		}

		if ( ! empty( $category ) && ! in_array( $category, self::VALID_CATEGORIES, true ) ) {
			\WP_CLI::error( 'Invalid category. Must be one of: ' . implode( ', ', self::VALID_CATEGORIES ) );
		}

		$abilities = new EventHealthAbilities();
		$result    = $abilities->executeHealthCheck(
			array(
				'scope'      => $scope,
				'days_ahead' => $days_ahead,
				'limit'      => $limit,
			)
		);

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( 'json' === $format ) {
			$this->outputJson( $result, $category );
			return;
		}

		$this->outputTable( $result, $category );
	}

	private function outputJson( array $data, string $category ): void {
		if ( ! empty( $category ) ) {
			$filtered = array(
				'total_scanned' => $data['total_scanned'],
				'scope'         => $data['scope'],
				$category       => $data[ $category ] ?? array(
					'count'  => 0,
					'events' => array(),
				),
			);
			\WP_CLI::log( wp_json_encode( $filtered, JSON_PRETTY_PRINT ) );
			return;
		}

		\WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	private function outputTable( array $data, string $category ): void {
		\WP_CLI::log( 'Scope: ' . $data['scope'] );
		\WP_CLI::log( 'Total Scanned: ' . $data['total_scanned'] );
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
			\WP_CLI::log( "=== {$label} ({$count}) ===" );

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
		);

		return $labels[ $category ] ?? $category;
	}
}
