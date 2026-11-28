<?php
/**
 * Revenue ledger CLI: ingest, query and coverage.
 *
 * Thin wrapper over datamachine/revenue-ingest and datamachine/revenue-query.
 *
 * @package DataMachineBusiness\Cli
 */

namespace DataMachineBusiness\Cli;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

class RevenueCommand extends BaseCommand {

	/**
	 * Ingest one window of normalized rows into the revenue ledger.
	 *
	 * Reads a JSON payload matching the datamachine/revenue-ingest input
	 * (source, account, start_date, end_date, rows, ...). Flags override the
	 * payload. Re-ingesting a window replaces its rows; it never duplicates.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : JSON payload file. Use - to read from STDIN.
	 *
	 * [--source=<source>]
	 * : Source slug (e.g. mediavine, stripe).
	 *
	 * [--account=<account>]
	 * : Source-side account or site id.
	 *
	 * [--start-date=<date>]
	 * : Window start (YYYY-MM-DD).
	 *
	 * [--end-date=<date>]
	 * : Window end (YYYY-MM-DD).
	 *
	 * [--dry-run]
	 * : Report what would be replaced and inserted without writing.
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
	 *     wp datamachine analytics revenue ingest --file=window.json --dry-run
	 *     cat window.json | wp datamachine analytics revenue ingest --file=- --format=json
	 */
	public function ingest( array $args, array $assoc_args ): void {
		$payload = array();
		if ( isset( $assoc_args['file'] ) ) {
			$path = (string) $assoc_args['file'];
			$raw  = '-' === $path ? stream_get_contents( STDIN ) : ( is_readable( $path ) ? file_get_contents( $path ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local CLI payload.
			if ( false === $raw ) {
				WP_CLI::error( 'Unable to read ingest payload.' );
				return;
			}
			$payload = json_decode( (string) $raw, true );
			if ( ! is_array( $payload ) ) {
				WP_CLI::error( 'Ingest payload must be a JSON object.' );
				return;
			}
		}
		$payload = self::ingestInput( $payload, $assoc_args );

		$result = $this->execute( 'datamachine/revenue-ingest', $payload );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( self::jsonOutput( $result ) );
			return;
		}
		if ( ! empty( $result['totals'] ) ) {
			$this->format_items( $result['totals'], array( 'source', 'kind', 'currency', 'amount', 'rows' ), $assoc_args );
		}
		WP_CLI::success( sprintf( '%s%s %s..%s: replaced %d, inserted %d.', $result['dry_run'] ? '[dry run] ' : '', $result['source'], $result['window']['start_date'], $result['window']['end_date'], $result['replaced'], $result['inserted'] ) );
	}

	/**
	 * Query revenue totals by source, kind and currency, with coverage.
	 *
	 * ## OPTIONS
	 *
	 * [--start-date=<date>]
	 * : Window start (YYYY-MM-DD).
	 *
	 * [--end-date=<date>]
	 * : Window end (YYYY-MM-DD).
	 *
	 * [--source=<source>]
	 * : Filter by source.
	 *
	 * [--account=<account>]
	 * : Filter by account.
	 *
	 * [--kind=<kind>]
	 * : Filter by kind.
	 *
	 * [--currency=<currency>]
	 * : Filter by currency.
	 *
	 * [--rows]
	 * : Include matching rows (JSON output).
	 *
	 * [--limit=<limit>]
	 * : Maximum rows with --rows (default 100, max 1000).
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
	 *     wp datamachine analytics revenue query --start-date=2026-06-01 --end-date=2026-06-30 --format=json
	 */
	public function query( array $args, array $assoc_args ): void {
		$result = $this->execute( 'datamachine/revenue-query', self::queryInput( $assoc_args ) );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( self::jsonOutput( $result ) );
			return;
		}
		if ( empty( $result['totals'] ) ) {
			WP_CLI::success( 'No ledger rows in this window. Use --format=json for coverage.' );
			return;
		}
		$this->format_items( $result['totals'], array( 'source', 'kind', 'currency', 'amount', 'rows' ), $assoc_args );
		if ( $result['counts']['partial_overlap_rows'] > 0 ) {
			WP_CLI::warning( sprintf( '%d row(s) only partially overlap the window and are excluded from totals.', $result['counts']['partial_overlap_rows'] ) );
		}
	}

	/**
	 * Show which windows each source/account has stored data for, plus gaps.
	 *
	 * ## OPTIONS
	 *
	 * [--start-date=<date>]
	 * : Window start (YYYY-MM-DD). Gaps are reported inside the window.
	 *
	 * [--end-date=<date>]
	 * : Window end (YYYY-MM-DD).
	 *
	 * [--source=<source>]
	 * : Filter by source.
	 *
	 * [--account=<account>]
	 * : Filter by account.
	 *
	 * [--kind=<kind>]
	 * : Filter by kind.
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
	 *     wp datamachine analytics revenue coverage --source=mediavine --start-date=2026-01-01 --end-date=2026-06-30
	 */
	public function coverage( array $args, array $assoc_args ): void {
		$result = $this->execute( 'datamachine/revenue-query', self::queryInput( $assoc_args ) );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( self::jsonOutput( array(
				'window'   => $result['window'],
				'coverage' => $result['coverage'],
			) ) );
			return;
		}
		if ( empty( $result['coverage'] ) ) {
			WP_CLI::success( 'No stored periods in this window.' );
			return;
		}
		$this->format_items( self::coverageTable( $result['coverage'] ), array( 'source', 'account', 'kinds', 'first_date', 'last_date', 'covered', 'gaps' ), $assoc_args );
	}

	/** Merge CLI flags into an ingest payload. */
	public static function ingestInput( array $payload, array $assoc_args ): array {
		foreach ( array(
			'source'     => 'source',
			'account'    => 'account',
			'start-date' => 'start_date',
			'end-date'   => 'end_date',
		) as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$payload[ $key ] = (string) $assoc_args[ $flag ];
			}
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$payload['dry_run'] = true;
		}
		return $payload;
	}

	/** Map CLI flags to revenue-query input. */
	public static function queryInput( array $assoc_args ): array {
		$input = array();
		foreach ( array(
			'start-date' => 'start_date',
			'end-date'   => 'end_date',
			'source'     => 'source',
			'account'    => 'account',
			'kind'       => 'kind',
			'currency'   => 'currency',
		) as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $key ] = (string) $assoc_args[ $flag ];
			}
		}
		if ( ! empty( $assoc_args['rows'] ) ) {
			$input['include_rows'] = true;
		}
		if ( isset( $assoc_args['limit'] ) ) {
			$input['limit'] = (int) $assoc_args['limit'];
		}
		return $input;
	}

	/** Flatten coverage entries for table output. */
	public static function coverageTable( array $coverage ): array {
		$ranges = static fn( array $ranges_list ): string => implode( ', ', array_map( static fn( array $r ): string => $r['start_date'] === $r['end_date'] ? $r['start_date'] : $r['start_date'] . '..' . $r['end_date'], $ranges_list ) );
		return array_map(
			static fn( array $entry ): array => array(
				'source'     => $entry['source'],
				'account'    => $entry['account'],
				'kinds'      => implode( ',', $entry['kinds'] ),
				'first_date' => $entry['first_date'],
				'last_date'  => $entry['last_date'],
				'covered'    => $ranges( $entry['covered'] ),
				'gaps'       => '' === $ranges( $entry['gaps'] ) ? '-' : $ranges( $entry['gaps'] ),
			),
			$coverage
		);
	}

	public static function jsonOutput( array $result ): string {
		return (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES );
	}

	/** Execute an ability or exit with its error. */
	private function execute( string $name, array $input ): array {
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			WP_CLI::error( "Ability {$name} is not registered. Ensure Data Machine Business is active." );
			return array();
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return array();
		}
		return is_array( $result ) ? $result : array();
	}
}
