<?php
/**
 * Import-based Amazon Associates affiliate revenue reports.
 *
 * Amazon Associates has no public earnings/reporting API (the Creators API and
 * PA-API 5 cover the product catalog only). Operators download reports from
 * Associates Central and import them; reads run against the stored rows. No
 * credentials and no network calls are involved.
 *
 * @package DataMachineBusiness\Abilities\Analytics
 */

namespace DataMachineBusiness\Abilities\Analytics;

use DataMachine\Abilities\PermissionHelper;
use DataMachineBusiness\Abilities\Analytics\AmazonAssociates\ImportsRepository;
use DataMachineBusiness\Abilities\Analytics\AmazonAssociates\ReportParser;
use DataMachineBusiness\Abilities\Analytics\AmazonAssociates\ReportRowsRepository;

defined( 'ABSPATH' ) || exit;

class AmazonAssociatesReportsAbilities {

	const ABILITY       = 'datamachine/amazon-associates-reports';
	const SOURCE        = 'amazon_associates';
	const ACTIONS       = array( 'import', 'summary', 'earnings', 'orders', 'coverage' );
	const DEFAULT_LIMIT = 100;
	const MAX_LIMIT     = 1000;
	const MAX_FILE_SIZE = 20971520;

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}
		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( static function () {
			wp_register_ability( self::ABILITY, array(
				'label'               => 'Amazon Associates Reports',
				'description'         => 'Import Amazon Associates Central earnings/orders report downloads (CSV or TSV) and read affiliate revenue from the stored rows. Actions: import, summary, earnings, orders, coverage. Associates has no public earnings API, so data is only as current as the latest import; every result carries coverage provenance.',
				'category'            => 'datamachine-analytics',
				'input_schema'        => self::inputSchema(),
				'output_schema'       => self::outputSchema(),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => false ),
			) );
		} );
		self::$registered = true;
	}

	public static function inputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'action' ),
			'properties' => array(
				'action'      => array(
					'type' => 'string',
					'enum' => self::ACTIONS,
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => 'Window start (YYYY-MM-DD). For import, overrides the report title window.',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => 'Window end (YYYY-MM-DD), inclusive.',
				),
				'tracking_id' => array(
					'type'        => 'string',
					'description' => 'Restrict reads to one Associates tracking ID (tag).',
				),
				'file'        => array(
					'type'        => 'string',
					'description' => 'Import only: server path to a downloaded Associates report.',
				),
				'content'     => array(
					'type'        => 'string',
					'description' => 'Import only: raw report text.',
				),
				'report_type' => array(
					'type'        => 'string',
					'enum'        => ReportParser::REPORT_TYPES,
					'description' => 'Import: override header detection. Coverage: restrict to one report type.',
				),
				'marketplace' => array(
					'type'        => 'string',
					'description' => 'Marketplace host, e.g. www.amazon.com (default for imports).',
				),
				'dry_run'     => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Import only: parse and report what would change without writing.',
				),
				'limit'       => array(
					'type'        => 'integer',
					'default'     => self::DEFAULT_LIMIT,
					'description' => 'Row-level actions: maximum rows returned; clamped to 1–1000.',
				),
			),
		);
	}

	public static function outputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'success', 'source', 'action', 'results', 'results_count', 'row_count', 'provenance' ),
			'properties' => array(
				'success'        => array( 'type' => 'boolean' ),
				'source'         => array( 'type' => 'string' ),
				'action'         => array(
					'type' => 'string',
					'enum' => self::ACTIONS,
				),
				'date_range'     => array( 'type' => array( 'object', 'null' ) ),
				'row_count'      => array( 'type' => 'integer' ),
				'results'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'results_count'  => array( 'type' => 'integer' ),
				'totals'         => array( 'type' => 'array' ),
				'by_tracking_id' => array( 'type' => 'array' ),
				'import'         => array( 'type' => 'object' ),
				'coverage'       => array( 'type' => 'object' ),
				'warnings'       => array( 'type' => 'array' ),
				'fetched_at'     => array( 'type' => 'string' ),
				'imported_at'    => array( 'type' => array( 'string', 'null' ) ),
				'provenance'     => array( 'type' => 'object' ),
			),
		);
	}

	/** @return array|\WP_Error */
	public static function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$action = (string) ( $input['action'] ?? '' );
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error( 'amazon_associates_invalid_action', 'Invalid Amazon Associates report action. Use import, summary, earnings, orders, or coverage.' );
		}
		foreach ( array( 'start_date', 'end_date' ) as $key ) {
			if ( ! empty( $input[ $key ] ) && ! self::isDate( (string) $input[ $key ] ) ) {
				return new \WP_Error( 'amazon_associates_invalid_date', sprintf( '%s must be a valid YYYY-MM-DD date.', $key ) );
			}
		}
		if ( ! empty( $input['start_date'] ) && ! empty( $input['end_date'] ) && $input['start_date'] > $input['end_date'] ) {
			return new \WP_Error( 'amazon_associates_invalid_range', 'start_date must not be after end_date.' );
		}

		ReportRowsRepository::ensure_schema();

		return match ( $action ) {
			'import' => self::import( $input ),
			'summary' => self::summary( $input ),
			'coverage' => self::coverageAction( $input ),
			default => self::rows( $action, $input ),
		};
	}

	/** @return array|\WP_Error */
	private static function import( array $input ) {
		$content = $input['content'] ?? null;
		$label   = 'content';
		if ( ! empty( $input['file'] ) ) {
			$file = (string) $input['file'];
			if ( ! is_file( $file ) || ! is_readable( $file ) ) {
				return new \WP_Error( 'amazon_associates_file_unreadable', sprintf( 'Report file is not readable: %s', $file ) );
			}
			if ( filesize( $file ) > self::MAX_FILE_SIZE ) {
				return new \WP_Error( 'amazon_associates_file_too_large', 'Report file exceeds the 20 MB import limit.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local, operator-supplied report file.
			$content = file_get_contents( $file );
			$label   = basename( $file );
		}
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new \WP_Error( 'amazon_associates_no_report', 'Provide a report via file (server path) or content (raw text).' );
		}

		$parsed = ReportParser::parse( $content, array(
			'report_type' => $input['report_type'] ?? '',
			'marketplace' => $input['marketplace'] ?? '',
		) );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$dates = array_column( $parsed['rows'], 'date' );
		$start = $input['start_date'] ?? ( $parsed['title_window']['start'] ?? ( $dates ? min( $dates ) : null ) );
		$end   = $input['end_date'] ?? ( $parsed['title_window']['end'] ?? ( $dates ? max( $dates ) : null ) );
		if ( empty( $start ) || empty( $end ) ) {
			return new \WP_Error( 'amazon_associates_no_window', 'The report has no rows and no "from … to …" title line; pass start_date and end_date to record the covered window.' );
		}
		// Rows outside the declared window would escape the next replace; widen instead.
		if ( $dates ) {
			$start = min( $start, min( $dates ) );
			$end   = max( $end, max( $dates ) );
		}

		$type         = $parsed['report_type'];
		$marketplace  = $parsed['marketplace'];
		$tracking_ids = array_values( array_unique( array_filter( array_column( $parsed['rows'], 'tracking_id' ) ) ) );
		sort( $tracking_ids );
		$repo     = new ReportRowsRepository();
		$ledger   = new ImportsRepository();
		$dry_run  = ! empty( $input['dry_run'] );
		$existing = $repo->count_matching( array(
			'report_type' => $type,
			'marketplace' => $marketplace,
			'start'       => $start,
			'end'         => $end,
		) );

		$batch       = $dry_run ? null : wp_generate_uuid4();
		$imported_at = $dry_run ? null : gmdate( 'Y-m-d H:i:s' );
		$inserted    = 0;
		if ( ! $dry_run ) {
			$repo->delete_window( $type, $marketplace, $start, $end );
			$inserted = $repo->insert_rows( $parsed['rows'], $batch, $imported_at );
			if ( false === $inserted ) {
				return new \WP_Error( 'amazon_associates_import_failed', 'Database error while storing Amazon Associates rows; the window may be partially imported. Re-run the import.' );
			}
			$ledger->delete_contained( $type, $marketplace, $start, $end );
			$ledger->record( array(
				'import_batch' => $batch,
				'report_type'  => $type,
				'marketplace'  => $marketplace,
				'window_start' => $start,
				'window_end'   => $end,
				'row_count'    => $inserted,
				'tracking_ids' => $tracking_ids,
				'source_label' => $label,
				'imported_at'  => $imported_at,
			) );
		}

		$import = array(
			'dry_run'       => $dry_run,
			'report_type'   => $type,
			'title'         => $parsed['title'],
			'marketplace'   => $marketplace,
			'currency'      => $parsed['currency'],
			'delimiter'     => $parsed['delimiter'],
			'window'        => array(
				'start_date' => $start,
				'end_date'   => $end,
			),
			'rows_parsed'   => count( $parsed['rows'] ),
			'rows_skipped'  => count( $parsed['skipped'] ),
			'skipped'       => array_slice( $parsed['skipped'], 0, 20 ),
			'rows_replaced' => $existing,
			'rows_inserted' => $dry_run ? 0 : $inserted,
			'tracking_ids'  => $tracking_ids,
			'import_batch'  => $batch,
			'source_label'  => $label,
		);

		$result                = self::envelope( 'import', $start, $end, count( $parsed['rows'] ), array( $import ) );
		$result['import']      = $import;
		$result['coverage']    = self::coverage( $start, $end, $marketplace, $type );
		$result['imported_at'] = $imported_at;
		return $result;
	}

	private static function summary( array $input ): array {
		list( $start, $end ) = self::window( $input );
		$marketplace         = self::marketplaceFilter( $input );
		$filters             = array(
			'start'       => $start,
			'end'         => $end,
			'tracking_id' => self::trackingFilter( $input ),
			'marketplace' => $marketplace,
		);
		$groups              = array();
		$totals              = array();
		$row_count           = 0;
		foreach ( ( new ReportRowsRepository() )->summary_rows( $filters ) as $agg ) {
			$key = $agg['tracking_id'] . '|' . $agg['currency'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = self::emptyTotals( array(
					'tracking_id' => (string) $agg['tracking_id'],
					'currency'    => (string) $agg['currency'],
				) );
			}
			$currency = (string) $agg['currency'];
			if ( ! isset( $totals[ $currency ] ) ) {
				$totals[ $currency ] = self::emptyTotals( array( 'currency' => $currency ) );
			}
			foreach ( array( &$groups[ $key ], &$totals[ $currency ] ) as &$bucket ) {
				if ( 'earnings' === $agg['report_type'] ) {
					$bucket['ad_fees_cents'] += ReportParser::toCents( (string) $agg['ad_fees'] );
					$bucket['revenue_cents'] += ReportParser::toCents( (string) $agg['revenue'] );
					$bucket['items_shipped'] += (int) $agg['items'];
					$bucket['returns']       += (int) $agg['returns'];
					++$bucket['earnings_rows'];
				} else {
					$bucket['ordered_items'] += (int) $agg['items'];
					++$bucket['order_rows'];
				}
			}
			unset( $bucket );
			++$row_count;
		}
		ksort( $totals );
		ksort( $groups );
		$by_tracking = array_map( array( self::class, 'finalizeTotals' ), array_values( $groups ) );
		$totals      = array_map( array( self::class, 'finalizeTotals' ), array_values( $totals ) );

		$result                   = self::envelope( 'summary', $start, $end, $row_count, $by_tracking );
		$result['totals']         = $totals;
		$result['by_tracking_id'] = $by_tracking;
		$result['coverage']       = self::coverage( $start, $end, $marketplace );
		$result['imported_at']    = $result['coverage']['last_imported_at'];
		$result['warnings']       = $result['coverage']['warnings'];
		if ( count( $totals ) > 1 ) {
			$result['warnings'][] = 'Totals span multiple currencies; they are reported per currency and never converted.';
		}
		return $result;
	}

	private static function rows( string $action, array $input ): array {
		list( $start, $end ) = self::window( $input );
		$marketplace         = self::marketplaceFilter( $input );
		$limit               = max( 1, min( self::MAX_LIMIT, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$filters             = array(
			'report_type' => $action,
			'start'       => $start,
			'end'         => $end,
			'tracking_id' => self::trackingFilter( $input ),
			'marketplace' => $marketplace,
		);
		$repo                = new ReportRowsRepository();
		$total               = $repo->count_matching( $filters );
		$rows                = array_map( array( self::class, 'presentRow' ), $repo->find_rows( $filters, $limit ) );

		$result                            = self::envelope( $action, $start, $end, $total, $rows );
		$result['coverage']                = self::coverage( $start, $end, $marketplace, $action );
		$result['imported_at']             = $result['coverage']['last_imported_at'];
		$result['warnings']                = $result['coverage']['warnings'];
		$result['provenance']['limit']     = $limit;
		$result['provenance']['truncated'] = $total > count( $rows );
		return $result;
	}

	private static function coverageAction( array $input ): array {
		$start       = $input['start_date'] ?? null;
		$end         = $input['end_date'] ?? null;
		$marketplace = self::marketplaceFilter( $input );
		$type        = in_array( $input['report_type'] ?? '', ReportParser::REPORT_TYPES, true ) ? $input['report_type'] : null;
		$coverage    = self::coverage( $start, $end, $marketplace, $type );
		$tracking    = ( new ReportRowsRepository() )->tracking_coverage( array(
			'report_type' => $type,
			'marketplace' => $marketplace,
			'start'       => $start,
			'end'         => $end,
			'tracking_id' => self::trackingFilter( $input ),
		) );
		$by_tracking = array_map( static fn( $row ) => array(
			'report_type' => $row['report_type'],
			'marketplace' => $row['marketplace'],
			'tracking_id' => $row['tracking_id'],
			'first_date'  => $row['first_date'],
			'last_date'   => $row['last_date'],
			'rows'        => (int) $row['row_count'],
		), $tracking );

		$results = array();
		foreach ( $coverage['windows'] as $window ) {
			foreach ( $window['intervals'] as $interval ) {
				$results[] = array(
					'report_type'      => $window['report_type'],
					'marketplace'      => $window['marketplace'],
					'start_date'       => $interval['start'],
					'end_date'         => $interval['end'],
					'imports'          => $window['imports'],
					'last_imported_at' => $window['last_imported_at'],
				);
			}
		}
		$result                   = self::envelope( 'coverage', $start, $end, array_sum( array_column( $by_tracking, 'rows' ) ), $results );
		$result['by_tracking_id'] = $by_tracking;
		$result['coverage']       = $coverage;
		$result['imported_at']    = $coverage['last_imported_at'];
		$result['warnings']       = $start && $end ? $coverage['warnings'] : array();
		return $result;
	}

	/**
	 * Coverage of imported windows per report type and marketplace.
	 *
	 * @param string|null $start       Requested window start, or null for all imports.
	 * @param string|null $end         Requested window end.
	 * @param string|null $marketplace Marketplace filter.
	 * @param string|null $type        Report type filter.
	 * @return array
	 */
	public static function coverage( ?string $start, ?string $end, ?string $marketplace = null, ?string $type = null ): array {
		$imports = ( new ImportsRepository() )->find( array(
			'report_type' => $type,
			'marketplace' => $marketplace,
			'start'       => $start,
			'end'         => $end,
		) );
		$grouped = array();
		foreach ( $imports as $import ) {
			$grouped[ $import['report_type'] . '|' . $import['marketplace'] ][] = $import;
		}
		$windows     = array();
		$last_import = null;
		foreach ( $grouped as $entries ) {
			$intervals = self::mergeIntervals( array_map( static fn( $entry ) => array( $entry['window_start'], $entry['window_end'] ), $entries ) );
			$last      = (string) self::extreme( array_column( $entries, 'imported_at' ), true );
			$window    = array(
				'report_type'      => $entries[0]['report_type'],
				'marketplace'      => $entries[0]['marketplace'],
				'intervals'        => $intervals,
				'imports'          => count( $entries ),
				'latest_date'      => self::extreme( array_column( $intervals, 'end' ), true ),
				'last_imported_at' => $last,
			);
			if ( $start && $end ) {
				$window = array_merge( $window, self::windowCoverage( $intervals, $start, $end ) );
			}
			$windows[]   = $window;
			$last_import = self::extreme( array( $last_import, $last ), true );
		}

		$types    = $type ? array( $type ) : ReportParser::REPORT_TYPES;
		$by_type  = array();
		$warnings = array();
		foreach ( $types as $report_type ) {
			$entries = array_values( array_filter( $windows, static fn( $w ) => $w['report_type'] === $report_type ) );
			$summary = array(
				'imported'         => ! empty( $entries ),
				'complete'         => null,
				'covered_through'  => null,
				'latest_date'      => self::extreme( array_column( $entries, 'latest_date' ), true ),
				'last_imported_at' => self::extreme( array_column( $entries, 'last_imported_at' ), true ),
			);
			if ( $start && $end ) {
				$summary['complete']        = ! empty( $entries ) && ! in_array( false, array_column( $entries, 'complete' ), true );
				$through                    = array_column( $entries, 'covered_through' );
				$summary['covered_through'] = ( $entries && ! in_array( null, $through, true ) ) ? self::extreme( $through, false ) : null;
				if ( ! $summary['complete'] ) {
					$warnings[] = empty( $entries )
						? sprintf( 'No Amazon Associates %s report has been imported for %s to %s; totals for this report type are not real zeros.', $report_type, $start, $end )
						: sprintf(
							'Amazon Associates %s imports do not fully cover %s to %s; data is current through %s. Import a newer Associates Central report.',
							$report_type,
							$start,
							$end,
							$summary['covered_through'] ?? 'no continuous date from the window start'
						);
				}
			}
			$by_type[ $report_type ] = $summary;
		}

		return array(
			'requested'        => $start && $end ? array(
				'start_date' => $start,
				'end_date'   => $end,
			) : null,
			// Earnings rows carry the commission; orders are informational.
			'complete'         => $start && $end ? (bool) ( $by_type['earnings']['complete'] ?? $by_type[ $types[0] ]['complete'] ) : null,
			'report_types'     => $by_type,
			'windows'          => $windows,
			'last_imported_at' => $last_import,
			'warnings'         => $warnings,
		);
	}

	/**
	 * Merge inclusive [start, end] date intervals; adjacent days join.
	 *
	 * @param array<int,array{0:string,1:string}> $intervals Inclusive Y-m-d pairs.
	 * @return array<int,array<string,string>>
	 */
	public static function mergeIntervals( array $intervals ): array {
		usort( $intervals, static fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
		$merged = array();
		foreach ( $intervals as list( $start, $end ) ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $start <= self::nextDay( $merged[ $last ]['end'] ) ) {
				$merged[ $last ]['end'] = max( $merged[ $last ]['end'], $end );
				continue;
			}
			$merged[] = array(
				'start' => $start,
				'end'   => $end,
			);
		}
		return $merged;
	}

	/** Whether merged intervals cover [start, end], the contiguous coverage end, and gaps. */
	public static function windowCoverage( array $intervals, string $start, string $end ): array {
		$gaps   = array();
		$cursor = $start;
		foreach ( $intervals as $interval ) {
			if ( $interval['end'] < $cursor ) {
				continue;
			}
			if ( $interval['start'] > $end ) {
				break;
			}
			if ( $interval['start'] > $cursor ) {
				$gaps[] = array(
					'start' => $cursor,
					'end'   => self::previousDay( $interval['start'] ),
				);
			}
			$cursor = self::nextDay( $interval['end'] );
			if ( $cursor > $end ) {
				break;
			}
		}
		if ( $cursor <= $end ) {
			$gaps[] = array(
				'start' => $cursor,
				'end'   => $end,
			);
		}
		$through = $end;
		if ( ! empty( $gaps ) ) {
			$through = $gaps[0]['start'] === $start ? null : self::previousDay( $gaps[0]['start'] );
		}
		return array(
			'complete'        => empty( $gaps ),
			'covered_through' => $through,
			'gaps'            => $gaps,
		);
	}

	private static function envelope( string $action, ?string $start, ?string $end, int $row_count, array $results ): array {
		return array(
			'success'       => true,
			'source'        => self::SOURCE,
			'action'        => $action,
			'date_range'    => $start && $end ? array(
				'start_date' => $start,
				'end_date'   => $end,
			) : null,
			'row_count'     => $row_count,
			'results'       => $results,
			'results_count' => count( $results ),
			'warnings'      => array(),
			'fetched_at'    => gmdate( 'c' ),
			'imported_at'   => null,
			'provenance'    => array(
				'source'       => array(
					'ability' => self::ABILITY,
					'source'  => self::SOURCE,
					'action'  => $action,
					'method'  => 'import',
					'origin'  => 'Associates Central report downloads (no public earnings API)',
					'tables'  => array( ( new ReportRowsRepository() )->get_table_name(), ( new ImportsRepository() )->get_table_name() ),
				),
				'amount_units' => 'decimal_string',
			),
		);
	}

	private static function presentRow( array $row ): array {
		return array(
			'report_type'  => $row['report_type'],
			'date'         => $row['report_date'],
			'tracking_id'  => $row['tracking_id'],
			'asin'         => $row['asin'],
			'name'         => $row['name'],
			'category'     => $row['category'],
			'seller'       => $row['seller'],
			'price'        => ReportParser::money( (string) $row['price'] ),
			'items'        => (int) $row['items'],
			'returns'      => (int) $row['returns'],
			'revenue'      => ReportParser::money( (string) $row['revenue'] ),
			'ad_fees'      => ReportParser::money( (string) $row['ad_fees'] ),
			'device_type'  => $row['device_type'],
			'link_type'    => $row['link_type'],
			'indirect'     => null === $row['indirect'] ? null : (bool) (int) $row['indirect'],
			'currency'     => $row['currency'],
			'marketplace'  => $row['marketplace'],
			'import_batch' => $row['import_batch'],
			'imported_at'  => $row['imported_at'],
		);
	}

	private static function emptyTotals( array $identity ): array {
		return array_merge( $identity, array(
			'ad_fees_cents' => 0,
			'revenue_cents' => 0,
			'items_shipped' => 0,
			'returns'       => 0,
			'ordered_items' => 0,
			'earnings_rows' => 0,
			'order_rows'    => 0,
		) );
	}

	private static function finalizeTotals( array $bucket ): array {
		$bucket['ad_fees'] = ReportParser::formatCents( $bucket['ad_fees_cents'] );
		$bucket['revenue'] = ReportParser::formatCents( $bucket['revenue_cents'] );
		unset( $bucket['ad_fees_cents'], $bucket['revenue_cents'] );
		$identity = array_intersect_key( $bucket, array_flip( array( 'tracking_id', 'currency' ) ) );
		return array_merge( $identity, array(
			'ad_fees'       => $bucket['ad_fees'],
			'revenue'       => $bucket['revenue'],
			'items_shipped' => $bucket['items_shipped'],
			'returns'       => $bucket['returns'],
			'ordered_items' => $bucket['ordered_items'],
			'earnings_rows' => $bucket['earnings_rows'],
			'order_rows'    => $bucket['order_rows'],
		) );
	}

	/** Default read window: 28 days ago through yesterday (UTC), matching Mediavine. */
	private static function window( array $input ): array {
		$end   = ! empty( $input['end_date'] ) ? (string) $input['end_date'] : gmdate( 'Y-m-d', (int) strtotime( '-1 day' ) );
		$start = ! empty( $input['start_date'] ) ? (string) $input['start_date'] : gmdate( 'Y-m-d', (int) strtotime( $end . ' -27 days' ) );
		return array( $start, $end );
	}

	private static function marketplaceFilter( array $input ): ?string {
		return empty( $input['marketplace'] ) ? null : ReportParser::normalizeMarketplace( (string) $input['marketplace'] );
	}

	private static function trackingFilter( array $input ): ?string {
		return empty( $input['tracking_id'] ) ? null : strtolower( trim( (string) $input['tracking_id'] ) );
	}

	/** Lexicographic max/min of Y-m-d / datetime strings, ignoring nulls; null when empty. */
	private static function extreme( array $values, bool $max ): ?string {
		$out = null;
		foreach ( $values as $value ) {
			if ( null === $value ) {
				continue;
			}
			$value = (string) $value;
			if ( null === $out || ( $max ? $value > $out : $value < $out ) ) {
				$out = $value;
			}
		}
		return $out;
	}

	private static function isDate( string $value ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private static function nextDay( string $date ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $date . ' +1 day UTC' ) );
	}

	private static function previousDay( string $date ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $date . ' -1 day UTC' ) );
	}
}
