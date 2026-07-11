<?php
/**
 * PageSpeed WP-CLI command.
 *
 * @package DataMachineBusiness\Cli
 */

namespace DataMachineBusiness\Cli;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class PageSpeedCommand extends BaseCommand {

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
			'analyze'       => 'Full PageSpeed/Lighthouse audit',
			'performance'   => 'Core Web Vitals focus',
			'opportunities' => 'Optimization suggestions',
		);
	}

	/**
	 * Run PageSpeed Insights (Lighthouse) audits.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: analyze (full audit), performance (Core Web Vitals), opportunities (optimization suggestions).
	 *
	 * [--page-url=<url>]
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
	 *     wp datamachine analytics pagespeed analyze
	 *     wp datamachine analytics pagespeed performance --strategy=desktop
	 *     wp datamachine analytics pagespeed opportunities --page-url=https://example.com/
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$input = array(
			'action' => $args[0] ?? '',
		);

		if ( isset( $assoc_args['page-url'] ) ) {
			$input['url'] = $assoc_args['page-url'];
		}

		if ( isset( $assoc_args['strategy'] ) ) {
			$input['strategy'] = $assoc_args['strategy'];
		}

		$ability = wp_get_ability( 'datamachine/pagespeed' );
		if ( ! $ability ) {
			WP_CLI::error( 'PageSpeed ability not registered. Ensure Data Machine Business is active and WordPress 6.9+ is available.' );
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

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		$items = $result['results'] ?? array();
		if ( empty( $items ) ) {
			$items = array( $this->flatten_result( $result ) );
		}

		$items = array_filter( $items );
		if ( empty( $items ) ) {
			WP_CLI::success( 'No results.' );
			return;
		}

		$this->format_items( $items, array_keys( reset( $items ) ), $assoc_args );
	}

	private function flatten_result( array $result ): array {
		$flat = array();

		foreach ( $result as $key => $value ) {
			if ( in_array( $key, array( 'success', 'tool_name' ), true ) ) {
				continue;
			}

			if ( ! is_array( $value ) ) {
				$flat[ $key ] = $value;
				continue;
			}

			foreach ( $value as $nested_key => $nested_value ) {
				if ( is_array( $nested_value ) ) {
					$flat[ $key . '_' . $nested_key ] = wp_json_encode( $nested_value );
				} else {
					$flat[ $key . '_' . $nested_key ] = $nested_value;
				}
			}
		}

		return $flat;
	}
}
