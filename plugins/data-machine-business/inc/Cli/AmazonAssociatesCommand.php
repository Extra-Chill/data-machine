<?php
/**
 * Amazon Associates affiliate revenue reporting CLI.
 *
 * @package DataMachineBusiness\Cli
 */

namespace DataMachineBusiness\Cli;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class AmazonAssociatesCommand extends BaseCommand {

	/**
	 * Import Amazon Associates Central reports and query stored affiliate revenue.
	 *
	 * Associates has no public earnings API: download Reports → Download (CSV or
	 * TSV) from Associates Central, import it, then read summaries from the
	 * stored rows. Re-importing a report replaces its date window.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : import, summary, earnings, orders, or coverage.
	 *
	 * [--file=<path>]
	 * : Import only: path to a downloaded earnings or orders report.
	 *
	 * [--start-date=<date>]
	 * : Window start (YYYY-MM-DD). Reads default to 28 days ago; import defaults to the report title window.
	 *
	 * [--end-date=<date>]
	 * : Window end (YYYY-MM-DD), inclusive. Reads default to yesterday.
	 *
	 * [--tracking-id=<tag>]
	 * : Restrict reads to one tracking ID.
	 *
	 * [--report-type=<type>]
	 * : Import: override header detection (earnings|orders). Coverage: restrict to one type.
	 *
	 * [--marketplace=<host>]
	 * : Marketplace host (default www.amazon.com for imports).
	 *
	 * [--limit=<limit>]
	 * : earnings/orders: maximum rows (default 100, max 1000).
	 *
	 * [--dry-run]
	 * : Import only: parse and report without writing.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine analytics amazon import --file=Fee-Earnings-2026-10.csv
	 *     wp datamachine analytics amazon import --file=Fee-Orders-2026-10.tsv --dry-run
	 *     wp datamachine analytics amazon summary --start-date=2026-10-01 --end-date=2026-10-31 --format=json
	 *     wp datamachine analytics amazon earnings --tracking-id=extrachill-20 --limit=25
	 *     wp datamachine analytics amazon coverage
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			WP_CLI::error( 'Format must be table or json.' );
			return;
		}
		$ability = wp_get_ability( 'datamachine/amazon-associates-reports' );
		if ( ! $ability ) {
			WP_CLI::error( 'Ability datamachine/amazon-associates-reports is not registered. Ensure Data Machine Business is active.' );
			return;
		}
		$result = $ability->execute( self::buildInput( $args, $assoc_args ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::line( self::jsonOutput( $result ) );
			return;
		}
		foreach ( $result['warnings'] ?? array() as $warning ) {
			WP_CLI::warning( $warning );
		}

		$action = (string) $result['action'];
		if ( 'import' === $action ) {
			$import = $result['import'];
			WP_CLI::success( sprintf(
				'%s%s %s report %s to %s: %d rows parsed, %d replaced, %d inserted, %d skipped. Tracking IDs: %s.',
				$import['dry_run'] ? '[dry run] ' : '',
				$import['marketplace'],
				$import['report_type'],
				$import['window']['start_date'],
				$import['window']['end_date'],
				$import['rows_parsed'],
				$import['rows_replaced'],
				$import['rows_inserted'],
				$import['rows_skipped'],
				$import['tracking_ids'] ? implode( ', ', $import['tracking_ids'] ) : 'none'
			) );
			return;
		}
		if ( 'summary' === $action ) {
			foreach ( $result['totals'] as $total ) {
				WP_CLI::log( sprintf( 'Total (%s): ad fees %s, revenue %s, items shipped %d, returns %d, ordered items %d', $total['currency'], $total['ad_fees'], $total['revenue'], $total['items_shipped'], $total['returns'], $total['ordered_items'] ) );
			}
		}
		$rows = self::tabularRows( $result );
		if ( empty( $rows ) ) {
			WP_CLI::success( 'No Amazon Associates rows for this window. Use --format=json for coverage provenance.' );
			return;
		}
		$this->format_items( $rows, self::tableFields( $action ), $assoc_args );
	}

	/** Map CLI args/flags to ability input. */
	public static function buildInput( array $args, array $assoc_args ): array {
		$input = array( 'action' => (string) ( $args[0] ?? '' ) );
		$flags = array(
			'file'        => 'file',
			'start-date'  => 'start_date',
			'end-date'    => 'end_date',
			'tracking-id' => 'tracking_id',
			'report-type' => 'report_type',
			'marketplace' => 'marketplace',
		);
		foreach ( $flags as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) && '' !== (string) $assoc_args[ $flag ] ) {
				$input[ $key ] = (string) $assoc_args[ $flag ];
			}
		}
		if ( isset( $assoc_args['limit'] ) ) {
			$input['limit'] = (int) $assoc_args['limit'];
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$input['dry_run'] = true;
		}
		return $input;
	}

	public static function jsonOutput( array $result ): string {
		$encoded = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION );
		return false === $encoded ? '{}' : $encoded;
	}

	/** Stable table columns per action. */
	public static function tableFields( string $action ): array {
		return match ( $action ) {
			'summary' => array( 'tracking_id', 'currency', 'ad_fees', 'revenue', 'items_shipped', 'returns', 'ordered_items', 'earnings_rows', 'order_rows' ),
			'earnings' => array( 'date', 'tracking_id', 'asin', 'name', 'price', 'items', 'returns', 'revenue', 'ad_fees', 'currency', 'device_type' ),
			'orders' => array( 'date', 'tracking_id', 'asin', 'name', 'price', 'items', 'link_type', 'indirect', 'currency', 'device_type' ),
			'coverage' => array( 'report_type', 'marketplace', 'start_date', 'end_date', 'imports', 'last_imported_at' ),
			default => array(),
		};
	}

	public static function tabularRows( array $result ): array {
		$fields = self::tableFields( (string) ( $result['action'] ?? '' ) );
		$rows   = array();
		foreach ( $result['results'] ?? array() as $result_row ) {
			$row = array();
			foreach ( $fields as $field ) {
				$value         = $result_row[ $field ] ?? null;
				$row[ $field ] = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value;
			}
			$rows[] = $row;
		}
		return $rows;
	}
}
