<?php
/**
 * Read-only Impact (impact.com) affiliate reporting CLI.
 *
 * @package DataMachineBusiness\Cli
 */
namespace DataMachineBusiness\Cli;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class ImpactCommand extends BaseCommand {
	/**
	 * Fetch Impact affiliate commissions by UTC day and campaign, optionally storing them in the revenue ledger.
	 *
	 * Reads in chunks of at most 45 days (the Impact API limit). Credentials come from the
	 * datamachine_impact_config option (account_sid, auth_token).
	 *
	 * ## OPTIONS
	 *
	 * [--start=<date>]
	 * : Window start (YYYY-MM-DD, UTC). Default: 30 days ago.
	 *
	 * [--end=<date>]
	 * : Window end (YYYY-MM-DD, UTC, inclusive). Default: today. At most 400 days per run.
	 *
	 * [--store]
	 * : Ingest the window into the revenue ledger (source=impact, kind=affiliate_commission), replacing it.
	 *
	 * [--dry-run]
	 * : With --store: report what the ledger would replace and insert without writing.
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
	 *     wp datamachine analytics impact fetch --start=2026-10-01 --end=2026-10-31
	 *     wp datamachine analytics impact fetch --start=2025-11-25 --end=2026-11-24 --store --dry-run
	 *
	 * @subcommand fetch
	 */
	public function fetch( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			WP_CLI::error( 'Format must be table, json, or csv.' );
			return;
		}
		$ability = wp_get_ability( 'datamachine/impact-reports' );
		if ( ! $ability ) {
			WP_CLI::error( 'Ability datamachine/impact-reports is not registered. Ensure Data Machine Business is active.' );
			return;
		}
		$input  = array(
			'start_date' => (string) ( $assoc_args['start'] ?? gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ),
			'end_date'   => (string) ( $assoc_args['end'] ?? gmdate( 'Y-m-d' ) ),
			'store'      => ! empty( $assoc_args['store'] ),
			'dry_run'    => ! empty( $assoc_args['dry-run'] ),
		);
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION ) );
			return;
		}
		$rows = array_map(
			static fn( array $row ): array => array(
				'day'      => $row['period_start'],
				'campaign' => $row['object_ref'],
				'actions'  => $row['metrics']['actions'] ?? 0,
				'amount'   => $row['amount'],
				'currency' => $row['currency'],
			),
			$result['results']
		);
		if ( ! empty( $rows ) ) {
			$this->format_items( $rows, array( 'day', 'campaign', 'actions', 'amount', 'currency' ), $assoc_args );
		}
		foreach ( $result['totals'] as $total ) {
			WP_CLI::log( sprintf( 'Total %s: %s (%d actions, %d outside window, %d invalid).', $total['currency'], $total['amount'], $result['counts']['aggregated'], $result['counts']['outside_window'], $result['counts']['invalid'] ) );
		}
		if ( isset( $result['ledger'] ) ) {
			$ledger = $result['ledger'];
			WP_CLI::success( sprintf( '%sStored impact %s..%s: replaced %d, inserted %d ledger rows.', $ledger['dry_run'] ? '[dry run] ' : '', $ledger['window']['start_date'], $ledger['window']['end_date'], $ledger['replaced'], $ledger['inserted'] ) );
			return;
		}
		WP_CLI::success( sprintf( 'Read %d Impact actions into %d day/campaign rows.', $result['counts']['source_records'], $result['results_count'] ) );
	}
}
