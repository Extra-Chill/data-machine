<?php
/**
 * WP-CLI command for venue data quality audit
 *
 * Thin wrapper that delegates to the audit-venues ability.
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\GeocodingAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuditVenuesCommand {

	/**
	 * Audit venue data quality and geocoding coverage.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json, or summary. Default: table.
	 *
	 * [--limit=<number>]
	 * : Max venues to list per category (default: 25).
	 *
	 * ## EXAMPLES
	 *
	 *     # Quick summary
	 *     wp datamachine-events audit-venues
	 *
	 *     # Detailed table with venue lists
	 *     wp datamachine-events audit-venues --format=table
	 *
	 *     # JSON output for automation
	 *     wp datamachine-events audit-venues --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$limit  = (int) ( $assoc_args['limit'] ?? 25 );

		$ability_format = ( 'json' === $format || 'table' === $format ) ? 'detailed' : 'summary';

		$abilities = new GeocodingAbilities();
		$result    = $abilities->executeAuditVenues(
			array(
				'format' => $ability_format,
				'limit'  => $limit,
			)
		);

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'summary' === $format ) {
			\WP_CLI::log( $result['message'] );
			return;
		}

		// Table output.
		\WP_CLI::log( '=== Venue Data Quality Audit ===' );
		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Total venues:     %d', $result['total_venues'] ) );
		\WP_CLI::log( sprintf( 'Geocoded:         %d (%.1f%%)', $result['geocoded']['count'], $result['coverage_percent'] ) );
		\WP_CLI::log( sprintf( 'Missing coords:   %d', $result['missing_coordinates']['count'] ) );
		\WP_CLI::log( sprintf( 'Missing address:  %d', $result['missing_address']['count'] ) );
		\WP_CLI::log( sprintf( 'Addr + no coords: %d', $result['has_address_no_coords']['count'] ) );
		\WP_CLI::log( sprintf( 'Missing timezone: %d', $result['missing_timezone']['count'] ) );
		\WP_CLI::log( '' );

		// Show detailed tables for each category.
		$categories = array(
			'has_address_no_coords' => 'Venues With Address But No Coordinates (geocodable)',
			'missing_address'       => 'Venues With No Address (need manual data)',
			'missing_timezone'      => 'Venues Missing Timezone',
		);

		foreach ( $categories as $key => $label ) {
			$cat_data = $result[ $key ] ?? array();
			$venues   = $cat_data['venues'] ?? array();
			$count    = $cat_data['count'] ?? 0;

			if ( 0 === $count ) {
				continue;
			}

			\WP_CLI::log( "--- {$label} ({$count}) ---" );

			$table_data = array();
			foreach ( $venues as $venue ) {
				$table_data[] = array(
					'ID'     => $venue['term_id'],
					'Name'   => mb_substr( $venue['name'], 0, 35 ),
					'Events' => $venue['event_count'],
					'City'   => $venue['city'] ?? '',
				);
			}

			\WP_CLI\Utils\format_items( 'table', $table_data, array( 'ID', 'Name', 'Events', 'City' ) );
			\WP_CLI::log( '' );
		}

		// Coverage bar.
		$pct = $result['coverage_percent'];
		$bar_width  = 40;
		$filled     = (int) round( $pct / 100 * $bar_width );
		$empty      = $bar_width - $filled;
		$bar        = str_repeat( '#', $filled ) . str_repeat( '-', $empty );

		\WP_CLI::log( sprintf( 'Coverage: [%s] %.1f%%', $bar, $pct ) );

		if ( $pct >= 95 ) {
			\WP_CLI::success( $result['message'] );
		} else {
			\WP_CLI::warning( $result['message'] );
		}
	}
}
