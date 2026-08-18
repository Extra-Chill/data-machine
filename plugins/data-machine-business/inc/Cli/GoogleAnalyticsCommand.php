<?php
/**
 * Google Analytics (GA4) WP-CLI command.
 *
 * Delegates to the datamachine/google-analytics ability owned by this plugin.
 * Lives in Data Machine Business alongside the ability it wraps — Data Machine
 * core stays agnostic of named external analytics integrations.
 *
 * @package DataMachineBusiness\Cli
 */

namespace DataMachineBusiness\Cli;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoogleAnalyticsCommand extends BaseCommand {

	/**
	 * Available actions for this flat `__invoke` command.
	 *
	 * The action is a positional argument dispatched inside `__invoke`, so it
	 * is invisible to method reflection. This static accessor is the
	 * machine-readable source consumed by the AGENTS.md section renderer
	 * without instantiating the command. Keep in sync with the `## OPTIONS`
	 * `<action>` list.
	 *
	 * @return array<string,string> Action name => short description.
	 */
	public static function actions(): array {
		return array(
			'page_stats'               => 'Top pages by sessions, views, and engagement',
			'traffic_sources'          => 'Sessions grouped by source and medium',
			'date_stats'               => 'Daily session and engagement trends',
			'realtime'                 => 'Real-time active users',
			'top_events'               => 'Top events by count',
			'user_demographics'        => 'Sessions by country and device',
			'landing_pages'            => 'Top landing pages by entrances and engagement',
			'landing_page_acquisition' => 'Landing pages by session source and medium',
			'page_acquisition'         => 'Touched pages by session source and medium',
			'page_audience'            => 'Touched pages by country and device',
			'engagement'               => 'Aggregate engagement metrics per page',
			'new_vs_returning'         => 'New vs returning user split',
			'network_density'          => 'Cross-site journey density via referrer host',
			'path_sequence'            => 'Ordered cross-host path sequences (funnel report)',
			'aggregate_report'         => 'Bounded aggregate report from JSON query options',
		);
	}

	/**
	 * Query Google Analytics (GA4) data.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: page_stats, traffic_sources, date_stats, realtime, top_events, user_demographics, landing_pages, landing_page_acquisition, page_acquisition, page_audience, engagement, new_vs_returning, network_density, path_sequence, aggregate_report.
	 *
	 * [--start-date=<date>]
	 * : Start date in YYYY-MM-DD format (default: 28 days ago). Not used for realtime.
	 *
	 * [--end-date=<date>]
	 * : End date in YYYY-MM-DD format (default: yesterday). Not used for realtime.
	 *
	 * [--limit=<number>]
	 * : Row limit (default: 25). aggregate_report max: 100; legacy actions max: 10000.
	 *
	 * [--property-id=<id>]
	 * : GA4 property ID. Defaults to the configured property; aggregate_report requires a numeric ID.
	 *
	 * [--page-filter=<string>]
	 * : Filter results to pages with paths containing this string.
	 *
	 * [--hostname=<string>]
	 * : Filter to pages on this hostname (for multisite GA4 properties).
	 *
	 * [--sort-by=<field>]
	 * : Sort results by this metric or dimension field name.
	 *
	 * [--order=<direction>]
	 * : Sort direction: asc or desc (default: desc).
	 *
	 * [--compare]
	 * : Compare against the previous period of equal length. Adds delta columns.
	 *
	 * [--date-range=<json>]
	 * : aggregate_report primary range JSON: {"start_date":"YYYY-MM-DD","end_date":"YYYY-MM-DD"}.
	 *
	 * [--comparison-date-range=<json>]
	 * : Optional aggregate_report comparison range JSON.
	 *
	 * [--dimensions=<json>]
	 * : aggregate_report dimensions JSON array (up to 3 approved names).
	 *
	 * [--metrics=<json>]
	 * : aggregate_report metrics JSON array (1-8 approved names).
	 *
	 * [--filters=<json>]
	 * : aggregate_report filters JSON array (up to 4 AND string filters).
	 *
	 * [--order-by=<json>]
	 * : aggregate_report ordering JSON array (up to 2 selected fields).
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
	 *     # Landing pages with highest engagement
	 *     wp datamachine analytics ga landing_pages --sort-by=engagementRate --order=desc
	 *
	 *     # Session-entry pages broken down by acquisition source
	 *     wp datamachine analytics ga landing_page_acquisition --hostname=example.com
	 *
	 *     # Any touched page broken down by acquisition source
	 *     wp datamachine analytics ga page_acquisition --page-filter=/blog/
	 *
	 *     # Any touched page broken down by country and device
	 *     wp datamachine analytics ga page_audience --page-filter=/blog/
	 *
	 *     # Engagement metrics for specific section
	 *     wp datamachine analytics ga engagement --page-filter=/blog/
	 *
	 *     # New vs returning users
	 *     wp datamachine analytics ga new_vs_returning
	 *
	 *     # Ordered cross-host journeys (true network density)
	 *     wp datamachine analytics ga path_sequence
	 *
	 *     # Bounded aggregate report (use --format=json for nested output)
	 *     wp datamachine analytics ga aggregate_report --date-range='{"start_date":"2026-01-01","end_date":"2026-01-31"}' --dimensions='["country"]' --metrics='["sessions"]' --format=json
	 *
	 *     # Filter by hostname for multisite
	 *     wp datamachine analytics ga page_stats --hostname=events.example.com
	 *
	 *     # Compare last 28 days vs previous 28 days
	 *     wp datamachine analytics ga page_stats --compare
	 *
	 *     # Sort by bounce rate ascending (worst engagement)
	 *     wp datamachine analytics ga page_stats --sort-by=bounceRate --order=asc --limit=10
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$input = array(
			'action' => $args[0] ?? '',
		);

		$this->map_optional(
			$input,
			$assoc_args,
			array(
				'property-id' => 'property_id',
				'start-date'  => 'start_date',
				'end-date'    => 'end_date',
				'limit'       => 'limit',
				'page-filter' => 'page_filter',
				'hostname'    => 'hostname',
				'sort-by'     => 'sort_by',
				'order'       => 'order',
			)
		);
		foreach ( array( 'date-range' => 'date_range', 'comparison-date-range' => 'comparison_date_range', 'dimensions' => 'dimensions', 'metrics' => 'metrics', 'filters' => 'filters', 'order-by' => 'order_by' ) as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $key ] = json_decode( $assoc_args[ $flag ], true );
			}
		}

		// --compare is a boolean flag (no value).
		if ( isset( $assoc_args['compare'] ) ) {
			$input['compare'] = true;
		}

		$this->execute_ability( 'datamachine/google-analytics', $input, $assoc_args );
	}

	/**
	 * Map CLI flags to ability input keys.
	 *
	 * @param array $input      Ability input (modified by reference).
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

		$coverage_warning = self::coverageWarning( $result );
		if ( null !== $coverage_warning ) {
			WP_CLI::warning( $coverage_warning );
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
			// Show top-level data for actions that don't return a results array.
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

		// Show summary with date range if available.
		$count = $result['results_count'] ?? count( $results );
		if ( ! empty( $result['date_range'] ) ) {
			$range    = $result['date_range'];
			$start    = $range['start_date'] ?? '?';
			$end      = $range['end_date'] ?? '?';
			$days_ago = $range['days_ago'] ?? null;

			WP_CLI::log( sprintf( '%d results (%s to %s)', $count, $start, $end ) );

			if ( null !== $days_ago && $days_ago > 30 ) {
				WP_CLI::warning(
					sprintf(
						'Data is %d days stale (latest: %s). Check API key and site verification.',
						$days_ago,
						$end
					)
				);
			}
		} else {
			WP_CLI::log( sprintf( '%d results', $count ) );
		}
	}

	/**
	 * Build the human warning for material unknown landing-page coverage.
	 *
	 * @param array $result Ability result.
	 * @return string|null
	 */
	public static function coverageWarning( array $result ): ?string {
		$coverage = $result['unknown_dimension_coverage']['current_period'] ?? null;
		if ( ! is_array( $coverage ) || 'material' !== ( $coverage['materiality'] ?? '' ) ) {
			return null;
		}

		$partial = 'partial' === ( $coverage['status'] ?? '' );
		$share   = $partial ? ( $coverage['observed_share_lower_bound'] ?? null ) : ( $coverage['share'] ?? null );
		$count   = $partial ? ( $coverage['observed_unknown_sessions'] ?? 0 ) : ( $coverage['unknown_sessions'] ?? 0 );
		$label   = $partial ? 'at least ' : '';

		return sprintf(
			'GA4 landing-page attribution gap: %s%d sessions (%s%.1f%%) have landingPage `(not set)`. Preserve these rows, but exclude or segment them for page-level acquisition conclusions.',
			$label,
			(int) $count,
			$label,
			(float) $share * 100
		);
	}

	/**
	 * Flatten a result row for table display.
	 *
	 * Converts nested arrays (like GA4 dimension/metric values) into flat key-value pairs.
	 * Scalar arrays (like GSC's 'keys') become comma-separated strings. Arrays whose
	 * elements are themselves arrays/objects (like path_sequence's 'next_hosts') are
	 * rendered readably rather than blindly imploded, which would otherwise trigger
	 * "Array to string conversion" warnings.
	 *
	 * @param array $row Result row.
	 * @return array Flat row.
	 */
	private function flatten_row( array $row ): array {
		$flat = array();
		foreach ( $row as $key => $value ) {
			if ( is_array( $value ) ) {
				$flat[ $key ] = $this->stringify_cell( $value );
			} else {
				$flat[ $key ] = $value;
			}
		}
		return $flat;
	}

	/**
	 * Render an array cell value as a readable string for table/CSV output.
	 *
	 * Scalar arrays are comma-joined (preserving the GSC 'keys' behavior). Arrays
	 * containing nested arrays/objects are rendered as compact, readable summaries:
	 * arrays of {next_host, users} pairs (path_sequence's 'next_hosts') become
	 * "host:users; host:users", and any other non-scalar structure falls back to a
	 * JSON encoding so the cell never triggers an "Array to string conversion" warning.
	 *
	 * @param array $value Array cell value.
	 * @return string Readable string representation.
	 */
	private function stringify_cell( array $value ): string {
		$has_nested = false;
		foreach ( $value as $element ) {
			if ( is_array( $element ) || is_object( $element ) ) {
				$has_nested = true;
				break;
			}
		}

		// Scalar arrays (e.g. GSC's 'keys'): join as before.
		if ( ! $has_nested ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		// Targeted rendering for path_sequence's next_host/users pairs.
		$pairs = array();
		foreach ( $value as $element ) {
			$element = (array) $element;
			if ( isset( $element['next_host'] ) && isset( $element['users'] ) ) {
				$pairs[] = $element['next_host'] . ':' . $element['users'];
			} else {
				// Unknown nested shape: fall back to JSON for the whole cell.
				return (string) wp_json_encode( $value );
			}
		}

		return implode( '; ', $pairs );
	}

	/**
	 * Flatten a nested result into a single-level array for display.
	 *
	 * Used for scores/metrics where the result has nested objects.
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
