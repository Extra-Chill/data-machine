<?php
/**
 * Google Search Console WP-CLI command.
 *
 * @package DataMachineBusiness\Cli
 */

namespace DataMachineBusiness\Cli;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleSearchConsoleCommand extends BaseCommand {

	/**
	 * Available actions for this flat `__invoke` command.
	 *
	 * The action is a positional argument dispatched inside `__invoke`, so it
	 * is invisible to method reflection. This static accessor is the
	 * machine-readable source consumed by the AGENTS.md section renderer (and
	 * available to any introspector) without instantiating the command.
	 *
	 * Keep in sync with the `## OPTIONS` `<action>` list and the ability's
	 * valid-actions check.
	 *
	 * @return array<string,string> Action name => short description.
	 */
	public static function actions(): array {
		return array(
			'query_stats'        => 'Top search queries by clicks and impressions',
			'page_stats'         => 'Top pages by clicks and impressions',
			'query_page_stats'   => 'Combined query/page breakdown per URL',
			'date_stats'         => 'Performance trends by date',
			'inspect_url'        => 'URL Inspection — index status, coverage, mobile usability',
			'list_sitemaps'      => 'List submitted sitemaps',
			'get_sitemap'        => 'Get details for a single sitemap',
			'submit_sitemap'     => 'Submit a sitemap for crawling',
		);
	}

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
	 * [--inspect-url=<url>]
	 * : URL for inspect_url action (named --inspect-url to avoid WP-CLI's global --url).
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
	 *     wp datamachine analytics gsc query_stats
	 *     wp datamachine analytics gsc page_stats --url-filter=/blog/ --limit=50
	 *     wp datamachine analytics gsc inspect_url --inspect-url=https://example.com/about/
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$input = array(
			'action' => $args[0] ?? '',
		);

		$this->map_optional( $input, $assoc_args, array(
			'start-date'   => 'start_date',
			'end-date'     => 'end_date',
			'limit'        => 'limit',
			'url-filter'   => 'url_filter',
			'query-filter' => 'query_filter',
			'inspect-url'  => 'url',
			'sitemap-url'  => 'sitemap_url',
		) );

		$this->execute_ability( 'datamachine/google-search-console', $input, $assoc_args );
	}

	private function map_optional( array &$input, array $assoc_args, array $mapping ): void {
		foreach ( $mapping as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $key ] = $assoc_args[ $flag ];
			}
		}
	}

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
			$display = array_filter(
				$result,
				function ( $value, $key ) {
					return ! in_array( $key, array( 'success', 'tool_name' ), true ) && ! is_array( $value );
				},
				ARRAY_FILTER_USE_BOTH
			);

			if ( ! empty( $display ) ) {
				$this->format_items( array( $display ), array_keys( $display ), $assoc_args );
				return;
			}

			WP_CLI::success( 'Command completed with no tabular results. Use --format=json for full output.' );
			return;
		}

		$flat_results = array_map( array( $this, 'flatten_row' ), $results );
		$fields       = array_keys( $flat_results[0] );
		$this->format_items( $flat_results, $fields, $assoc_args );
		WP_CLI::log( sprintf( '%d results', $result['results_count'] ?? count( $results ) ) );
	}

	private function flatten_row( array $row ): array {
		$flat = array();
		foreach ( $row as $key => $value ) {
			$flat[ $key ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : $value;
		}
		return $flat;
	}
}
