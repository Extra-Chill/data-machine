<?php
/**
 * Mediavine dimensional reports WP-CLI command.
 *
 * @package DataMachineBusiness\Cli
 */

namespace DataMachineBusiness\Cli;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class MediavineCommand extends BaseCommand {

	/**
	 * Available source-native dimensional actions.
	 *
	 * @return array<string,string> Action name => short description.
	 */
	public static function actions(): array {
		return array(
			'devices'   => 'Revenue and delivery metrics by device bucket',
			'countries' => 'Country-level revenue and delivery metrics',
			'sources'   => 'Revenue by Mediavine normalized acquisition source',
			'ad_units'  => 'Parent and child ad-unit revenue by explicit grain',
		);
	}

	/**
	 * Query Mediavine dimensional revenue reports.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Action to perform: devices, countries, sources, or ad_units.
	 *
	 * [--site-id=<id>]
	 * : Mediavine GraphQL global InternalSite ID or numeric internal site id.
	 *
	 * [--start-date=<date>]
	 * : Start date in YYYY-MM-DD format (default: 28 days ago).
	 *
	 * [--end-date=<date>]
	 * : End date in YYYY-MM-DD format (default: yesterday).
	 *
	 * [--period=<label>]
	 * : Optional period label included in each result row.
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
	 *     wp datamachine analytics mediavine devices
	 *     wp datamachine analytics mediavine countries --start-date=2026-07-01 --end-date=2026-07-07
	 *     wp datamachine analytics mediavine sources --format=json
	 *     wp datamachine analytics mediavine ad_units --format=csv
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$input = array( 'action' => $args[0] ?? '' );
		foreach ( array( 'site-id' => 'site_id', 'start-date' => 'start_date', 'end-date' => 'end_date', 'period' => 'period' ) as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $key ] = $assoc_args[ $flag ];
			}
		}

		$ability = wp_get_ability( 'datamachine/mediavine-reports' );
		if ( ! $ability ) {
			WP_CLI::error( 'Ability datamachine/mediavine-reports is not registered. Ensure Data Machine Business is active and WordPress 6.9+.' );
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
			WP_CLI::line( self::jsonOutput( $result ) );
			return;
		}

		foreach ( self::integrityWarningMessages( $result ) as $warning ) {
			WP_CLI::warning( $warning );
		}

		$rows = self::tabularRows( $result );
		if ( empty( $rows ) ) {
			WP_CLI::success( 'Mediavine report returned no rows. Use --format=json for the complete response envelope.' );
			return;
		}

		$this->format_items( $rows, self::tableFields( (string) $result['action'] ), $assoc_args );
		WP_CLI::log( sprintf( '%d results', $result['results_count'] ?? count( $rows ) ) );
	}

	/**
	 * Encode the complete typed ability envelope for JSON output.
	 *
	 * @param array $result Ability result.
	 * @return string
	 */
	public static function jsonOutput( array $result ): string {
		$encoded = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION );
		return false === $encoded ? '{}' : $encoded;
	}

	/**
	 * Render concise human warnings from machine-readable diagnostics.
	 *
	 * @param array $result Ability result.
	 * @return array<int,string>
	 */
	public static function integrityWarningMessages( array $result ): array {
		$messages = array();
		foreach ( $result['diagnostics']['warnings'] ?? array() as $warning ) {
			if ( ! is_array( $warning ) || ! is_array( $warning['row'] ?? null ) || ! is_array( $warning['observed'] ?? null ) ) {
				continue;
			}

			$row      = $warning['row'];
			$observed = $warning['observed'];
			$value    = null === ( $row['value'] ?? null ) ? 'null' : (string) $row['value'];

			$messages[] = sprintf(
				'Mediavine integrity warning at row %d (%s=%s): %s=%d exceeds %s=%d. Upstream bucket semantics are unknown; raw values were preserved.',
				(int) ( $row['index'] ?? 0 ),
				(string) ( $row['dimension'] ?? 'dimension' ),
				$value,
				(string) ( $observed['subset_field'] ?? 'subset' ),
				(int) ( $observed['subset_value'] ?? 0 ),
				(string) ( $observed['total_field'] ?? 'total' ),
				(int) ( $observed['total_value'] ?? 0 )
			);
		}

		return $messages;
	}

	/**
	 * Return stable table/CSV field order for an action.
	 *
	 * @param string $action Dimensional action.
	 * @return array<int,string>
	 */
	public static function tableFields( string $action ): array {
		$fields = array(
			'devices'   => array( 'label', 'pageviewRpm', 'pageviews', 'revenue', 'sessionRpm', 'sessions', 'monetizablePageviews', 'monetizableSessions', 'monetizablePageviewRpm', 'monetizableSessionRpm', 'period' ),
			'countries' => array( 'country', 'pageviews', 'pageviewsPercentage', 'sessions', 'sessionsPercentage', 'netRevenue', 'pageRevenue', 'impressions', 'paidImpressions', 'cpm', 'fillrate', 'viewability', 'pageviewsRpm', 'sessionsRpm', 'monetizablePageviews', 'monetizablePageviewsPercentage', 'monetizablePageviewsRpm', 'monetizableSessions', 'monetizableSessionsPercentage', 'monetizableSessionsRpm', 'period' ),
			'sources'   => array( 'source', 'revenue', 'netRevenue', 'pageviews', 'sessions', 'impressions', 'pageviewsRpm', 'sessionsRpm', 'monetizablePageviews', 'monetizablePageviewsRpm', 'monetizableSessions', 'monetizableSessionsRpm', 'impressionsPerPageview', 'impressionsPerSession', 'impressionsPerMonetizablePageview', 'impressionsPerMonetizableSession', 'period' ),
			'ad_units'  => array( 'grain', 'adunit', 'deviceType', 'revenue', 'paidImpressions', 'viewability', 'fillrate', 'sessionRpm', 'pageviewRpm', 'monetizableSessionRpm', 'monetizablePageviewRpm', 'cpm', 'period' ),
		);

		return $fields[ $action ] ?? array();
	}

	/**
	 * Normalize result rows to stable table/CSV columns.
	 *
	 * @param array $result Ability result.
	 * @return array<int,array<string,mixed>>
	 */
	public static function tabularRows( array $result ): array {
		$fields = self::tableFields( (string) ( $result['action'] ?? '' ) );
		$rows   = array();

		foreach ( $result['results'] ?? array() as $result_row ) {
			if ( ! is_array( $result_row ) ) {
				continue;
			}

			$row = array();
			foreach ( $fields as $field ) {
				$row[ $field ] = $result_row[ $field ] ?? null;
			}
			$rows[] = $row;
		}

		return $rows;
	}
}
