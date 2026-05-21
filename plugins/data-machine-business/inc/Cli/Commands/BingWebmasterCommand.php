<?php
/**
 * Bing Webmaster WP-CLI command.
 *
 * @package DataMachineBusiness\Cli\Commands
 */

namespace DataMachineBusiness\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class BingWebmasterCommand extends BaseCommand {

	/**
	 * Query Bing Webmaster Tools analytics.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: query_stats, traffic_stats, page_stats, crawl_stats.
	 *
	 * [--limit=<number>]
	 * : Maximum number of results (default: 20).
	 *
	 * [--days=<number>]
	 * : Only show data from the last N days (client-side filter).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine analytics bing query_stats
	 *     wp datamachine analytics bing traffic_stats --format=json
	 *     wp datamachine analytics bing crawl_stats --limit=50
	 *     wp datamachine analytics bing traffic_stats --days=30
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$input = array(
			'action' => $args[0] ?? '',
		);

		foreach ( array( 'limit', 'days' ) as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$input[ $key ] = $assoc_args[ $key ];
			}
		}

		$this->execute_ability( 'datamachine/bing-webmaster', $input, $assoc_args );
	}

	/**
	 * Execute an ability and output the results.
	 *
	 * @param string $ability_slug Ability slug.
	 * @param array  $input        Ability input.
	 * @param array  $assoc_args   CLI associative arguments.
	 */
	private function execute_ability( string $ability_slug, array $input, array $assoc_args ): void {
		$ability = wp_get_ability( $ability_slug );

		if ( ! $ability ) {
			WP_CLI::error( "Ability '{$ability_slug}' not registered. Ensure Data Machine Business is active and WordPress 6.9+." );
			return;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'Unknown error.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$results = $result['results'] ?? array();
		if ( empty( $results ) ) {
			WP_CLI::success( 'Command completed with no tabular results. Use --format=json for full output.' );
			return;
		}

		$flat_results = array_map( array( $this, 'flatten_row' ), $results );
		$fields       = array_keys( $flat_results[0] );
		$this->format_items( $flat_results, $fields, $assoc_args );

		$count = $result['results_count'] ?? count( $results );
		if ( ! empty( $result['date_range'] ) ) {
			$range    = $result['date_range'];
			$start    = $range['start_date'] ?? '?';
			$end      = $range['end_date'] ?? '?';
			$days_ago = $range['days_ago'] ?? null;

			WP_CLI::log( sprintf( '%d results (%s to %s)', $count, $start, $end ) );

			if ( null !== $days_ago && $days_ago > 30 ) {
				WP_CLI::warning( sprintf( 'Data is %d days stale (latest: %s). Check API key and site verification.', $days_ago, $end ) );
			}
			return;
		}

		WP_CLI::log( sprintf( '%d results', $count ) );
	}

	/**
	 * Flatten a result row for table display.
	 *
	 * @param array $row Result row.
	 * @return array<string,mixed> Flat row.
	 */
	private function flatten_row( array $row ): array {
		$flat = array();
		foreach ( $row as $key => $value ) {
			$flat[ $key ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : $value;
		}
		return $flat;
	}
}
