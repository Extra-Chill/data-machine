<?php
/**
 * WP-CLI Analytics Command
 *
 * Provides CLI access to all analytics integrations:
 * Google Search Console, Bing Webmaster, Google Analytics (GA4), PageSpeed Insights.
 *
 * Each subcommand delegates to its respective ability via wp_get_ability().
 *
 * @package DataMachine\Cli\Commands
 * @since 0.31.0
 */

namespace DataMachine\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;

defined( 'ABSPATH' ) || exit;

class AnalyticsCommand extends BaseCommand {

	/**
	 * Query Google Search Console analytics.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: query_stats, page_stats, query_page_stats, date_stats, inspect_url, list_sitemaps, get_sitemap, submit_sitemap.
	 *
	 * [--start-date=<date>]
	 * : Start date in YYYY-MM-DD format (default: 28 days ago).
	 *
	 * [--end-date=<date>]
	 * : End date in YYYY-MM-DD format (default: 3 days ago).
	 *
	 * [--limit=<number>]
	 * : Row limit (default: 25, max: 25000).
	 *
	 * [--url-filter=<string>]
	 * : Filter results to URLs containing this string.
	 *
	 * [--query-filter=<string>]
	 * : Filter results to queries containing this string.
	 *
	 * [--url=<url>]
	 * : URL for inspect_url action.
	 *
	 * [--sitemap-url=<url>]
	 * : Sitemap URL for get_sitemap/submit_sitemap actions.
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
	 *     # Top search queries
	 *     wp datamachine analytics gsc query_stats
	 *
	 *     # Page stats with URL filter
	 *     wp datamachine analytics gsc page_stats --url-filter=/blog/ --limit=50
	 *
	 *     # Daily trends as JSON
	 *     wp datamachine analytics gsc date_stats --format=json
	 *
	 *     # Inspect a URL
	 *     wp datamachine analytics gsc inspect_url --url=https://chubes.net/about/
	 *
	 * @subcommand gsc
	 */
	public function gsc( array $args, array $assoc_args ): void {
		$input = array(
			'action' => $args[0] ?? '',
		);

		$this->map_optional( $input, $assoc_args, array(
			'start-date'   => 'start_date',
			'end-date'     => 'end_date',
			'limit'        => 'limit',
			'url-filter'   => 'url_filter',
			'query-filter' => 'query_filter',
			'url'          => 'url',
			'sitemap-url'  => 'sitemap_url',
		) );

		$this->execute_ability( 'datamachine/google-search-console', $input, $assoc_args );
	}

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
	 *     # Query performance stats
	 *     wp datamachine analytics bing query_stats
	 *
	 *     # Traffic stats as JSON
	 *     wp datamachine analytics bing traffic_stats --format=json
	 *
	 *     # Crawl stats with limit
	 *     wp datamachine analytics bing crawl_stats --limit=50
	 *
	 * @subcommand bing
	 */
	public function bing( array $args, array $assoc_args ): void {
		$input = array(
			'action' => $args[0] ?? '',
		);

		$this->map_optional( $input, $assoc_args, array(
			'limit' => 'limit',
		) );

		$this->execute_ability( 'datamachine/bing-webmaster', $input, $assoc_args );
	}

	/**
	 * Query Google Analytics (GA4) data.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: page_stats, traffic_sources, date_stats, realtime, top_events, user_demographics.
	 *
	 * [--start-date=<date>]
	 * : Start date in YYYY-MM-DD format (default: 28 days ago). Not used for realtime.
	 *
	 * [--end-date=<date>]
	 * : End date in YYYY-MM-DD format (default: yesterday). Not used for realtime.
	 *
	 * [--limit=<number>]
	 * : Row limit (default: 25, max: 10000).
	 *
	 * [--page-filter=<string>]
	 * : Filter results to pages with paths containing this string.
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
	 *     # Page performance stats
	 *     wp datamachine analytics ga page_stats
	 *
	 *     # Traffic sources
	 *     wp datamachine analytics ga traffic_sources --limit=50
	 *
	 *     # Real-time active users
	 *     wp datamachine analytics ga realtime
	 *
	 *     # Daily trends for blog pages
	 *     wp datamachine analytics ga date_stats --page-filter=/blog/ --format=json
	 *
	 *     # Top events
	 *     wp datamachine analytics ga top_events
	 *
	 * @subcommand ga
	 */
	public function ga( array $args, array $assoc_args ): void {
		$input = array(
			'action' => $args[0] ?? '',
		);

		$this->map_optional( $input, $assoc_args, array(
			'start-date'  => 'start_date',
			'end-date'    => 'end_date',
			'limit'       => 'limit',
			'page-filter' => 'page_filter',
		) );

		$this->execute_ability( 'datamachine/google-analytics', $input, $assoc_args );
	}

	/**
	 * Run PageSpeed Insights (Lighthouse) audits.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: analyze (full audit), performance (Core Web Vitals), opportunities (optimization suggestions).
	 *
	 * [--url=<url>]
	 * : URL to analyze. Defaults to the site home URL.
	 *
	 * [--strategy=<strategy>]
	 * : Device strategy: mobile or desktop (default: mobile).
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
	 *     # Full Lighthouse audit (mobile)
	 *     wp datamachine analytics pagespeed analyze
	 *
	 *     # Desktop performance check
	 *     wp datamachine analytics pagespeed performance --strategy=desktop
	 *
	 *     # Optimization opportunities for a specific page
	 *     wp datamachine analytics pagespeed opportunities --url=https://chubes.net/blog/
	 *
	 *     # Full audit as JSON
	 *     wp datamachine analytics pagespeed analyze --format=json
	 *
	 * @subcommand pagespeed
	 */
	public function pagespeed( array $args, array $assoc_args ): void {
		$input = array(
			'action' => $args[0] ?? '',
		);

		$this->map_optional( $input, $assoc_args, array(
			'url'      => 'url',
			'strategy' => 'strategy',
		) );

		$this->execute_ability( 'datamachine/pagespeed', $input, $assoc_args );
	}

	/**
	 * Map CLI flags to ability input keys.
	 *
	 * @param array $input     Ability input (modified by reference).
	 * @param array $assoc_args CLI associative arguments.
	 * @param array $mapping    Flag-to-key mapping (cli-flag => ability_key).
	 */
	private function map_optional( array &$input, array $assoc_args, array $mapping ): void {
		foreach ( $mapping as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $key ] = $assoc_args[ $flag ];
			}
		}
	}

	/**
	 * Execute an ability and output the results.
	 *
	 * @param string $ability_slug Ability slug.
	 * @param array  $input        Ability input.
	 * @param array  $assoc_args   CLI associative arguments (for format).
	 */
	private function execute_ability( string $ability_slug, array $input, array $assoc_args ): void {
		$ability = wp_get_ability( $ability_slug );

		if ( ! $ability ) {
			WP_CLI::error( "Ability '{$ability_slug}' not registered. Ensure the plugin is active and WordPress 6.9+." );
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

		// JSON output: dump the whole result.
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		// Table/CSV output: format the results array.
		$results = $result['results'] ?? array();

		if ( empty( $results ) ) {
			// Show top-level data for actions that don't return a results array (e.g., pagespeed analyze).
			$display = array_filter(
				$result,
				function ( $value, $key ) {
					return ! in_array( $key, array( 'success', 'tool_name' ), true ) && ! is_array( $value );
				},
				ARRAY_FILTER_USE_BOTH
			);

			if ( ! empty( $display ) ) {
				$items = array( $display );
				$this->format_items( $items, array_keys( $display ), $assoc_args );
				return;
			}

			// For nested data (scores, metrics), flatten one level.
			$flattened = $this->flatten_result( $result );
			if ( ! empty( $flattened ) ) {
				$items = array( $flattened );
				$this->format_items( $items, array_keys( $flattened ), $assoc_args );
				return;
			}

			WP_CLI::success( 'Command completed with no tabular results. Use --format=json for full output.' );
			return;
		}

		// Ensure results are flat for table display.
		$flat_results = array_map( array( $this, 'flatten_row' ), $results );

		if ( ! empty( $flat_results ) ) {
			$fields = array_keys( $flat_results[0] );
			$this->format_items( $flat_results, $fields, $assoc_args );
		}

		// Show summary.
		$count = $result['results_count'] ?? count( $results );
		if ( isset( $result['date_range'] ) ) {
			WP_CLI::log( sprintf(
				'%d results (%s to %s)',
				$count,
				$result['date_range']['start_date'] ?? '?',
				$result['date_range']['end_date'] ?? '?'
			) );
		} else {
			WP_CLI::log( sprintf( '%d results', $count ) );
		}
	}

	/**
	 * Flatten a result row for table display.
	 *
	 * Converts nested arrays (like GA4 dimension/metric values) into flat key-value pairs.
	 * Arrays within rows become comma-separated strings.
	 *
	 * @param array $row Result row.
	 * @return array Flat row.
	 */
	private function flatten_row( array $row ): array {
		$flat = array();
		foreach ( $row as $key => $value ) {
			if ( is_array( $value ) ) {
				// For arrays like GSC's 'keys', join them.
				$flat[ $key ] = implode( ', ', array_map( 'strval', $value ) );
			} else {
				$flat[ $key ] = $value;
			}
		}
		return $flat;
	}

	/**
	 * Flatten a nested result into a single-level array for display.
	 *
	 * Used for pagespeed scores/metrics where the result has nested objects.
	 *
	 * @param array $result Full result array.
	 * @return array Flattened key-value pairs.
	 */
	private function flatten_result( array $result ): array {
		$flat = array();

		foreach ( $result as $key => $value ) {
			if ( in_array( $key, array( 'success', 'tool_name', 'results', 'results_count' ), true ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				foreach ( $value as $sub_key => $sub_value ) {
					if ( is_array( $sub_value ) ) {
						// For metrics with value/numeric/score sub-keys, use displayValue.
						$flat[ $sub_key ] = $sub_value['value'] ?? wp_json_encode( $sub_value );
					} else {
						$flat[ $sub_key ] = $sub_value;
					}
				}
			} else {
				$flat[ $key ] = $value;
			}
		}

		return $flat;
	}
}
