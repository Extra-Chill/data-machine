<?php
/**
 * Revenue ledger abilities: the single write path and the read path.
 *
 * - datamachine/revenue-ingest replaces every row of (source, account, kind)
 *   contained in a window with the supplied normalized rows, so re-ingesting a
 *   window never duplicates. Supports dry_run.
 * - datamachine/revenue-query returns per source/kind/currency totals,
 *   per-currency totals (never converted), optional rows, and coverage derived
 *   from the stored periods.
 *
 * @package DataMachineBusiness\Abilities\Revenue
 */

namespace DataMachineBusiness\Abilities\Revenue;

use DataMachine\Abilities\PermissionHelper;

defined( 'ABSPATH' ) || exit;

class RevenueLedgerAbilities {

	public const INGEST_ABILITY = 'datamachine/revenue-ingest';
	public const QUERY_ABILITY  = 'datamachine/revenue-query';

	public const GRAINS = array( 'day', 'week', 'month', 'quarter', 'year', 'range' );

	/** Upper bound on rows per ingest call; larger windows should be split. */
	public const MAX_ROWS = 50000;

	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/D';

	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}
		\DataMachine\Abilities\AbilityRegistration::on_abilities_api_init( static function () {
			wp_register_ability( self::INGEST_ABILITY, array(
				'label'               => 'Revenue Ingest',
				'description'         => 'The only write path into the generic revenue ledger. Replaces every row of (source, account, kind) contained in [start_date, end_date] with the supplied normalized rows, so re-ingesting a window never duplicates. Amounts are decimal strings; source-native numbers go in metrics. Supports dry_run.',
				'category'            => 'datamachine-analytics',
				'input_schema'        => self::ingestInputSchema(),
				'output_schema'       => self::ingestOutputSchema(),
				'execute_callback'    => array( self::class, 'ingest' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => false ),
			) );
			wp_register_ability( self::QUERY_ABILITY, array(
				'label'               => 'Revenue Query',
				'description'         => 'Read the generic revenue ledger: totals by source, kind and currency (never converted), per-currency totals, optional rows, and coverage (which windows each source/account has stored data for, plus gaps).',
				'category'            => 'datamachine-analytics',
				'input_schema'        => self::queryInputSchema(),
				'output_schema'       => self::queryOutputSchema(),
				'execute_callback'    => array( self::class, 'query' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => false ),
			) );
		} );
		self::$registered = true;
	}

	public static function ingestInputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'source', 'start_date', 'end_date', 'rows' ),
			'properties' => array(
				'source'       => array(
					'type'        => 'string',
					'description' => 'Source slug, e.g. mediavine, stripe, impact.',
				),
				'account'      => array(
					'type'        => 'string',
					'default'     => '',
					'description' => 'Source-side account or site id.',
				),
				'start_date'   => array(
					'type'        => 'string',
					'description' => 'Window start (YYYY-MM-DD, inclusive). Every row must lie inside the window.',
				),
				'end_date'     => array(
					'type'        => 'string',
					'description' => 'Window end (YYYY-MM-DD, inclusive).',
				),
				'grain'        => array(
					'type'        => 'string',
					'enum'        => self::GRAINS,
					'description' => 'Default row grain; derived from the window when omitted.',
				),
				'kinds'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Kinds whose stored rows in the window are replaced. Defaults to the kinds present in rows. Declare kinds explicitly to clear a kind that now has no rows.',
				),
				'rows'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'kind', 'amount', 'currency' ),
						'properties' => array(
							'kind'         => array( 'type' => 'string' ),
							'amount'       => array(
								'type'        => array( 'string', 'number', 'integer' ),
								'description' => 'Decimal string (preferred) with up to 6 fraction digits, in major units.',
							),
							'currency'     => array( 'type' => 'string' ),
							'period_start' => array( 'type' => 'string' ),
							'period_end'   => array( 'type' => 'string' ),
							'grain'        => array(
								'type' => 'string',
								'enum' => self::GRAINS,
							),
							'url'          => array( 'type' => array( 'string', 'null' ) ),
							'object_ref'   => array(
								'type'        => array( 'string', 'null' ),
								'description' => 'Opaque source reference such as a sub-ID, tracking ID or charge ID.',
							),
							'blog_id'      => array( 'type' => array( 'integer', 'null' ) ),
							'metrics'      => array(
								'type'        => array( 'object', 'null' ),
								'description' => 'Source-native numbers (views, rpm, clicks, conversions, ...). Stored as JSON and returned unchanged.',
							),
						),
					),
				),
				'import_batch' => array(
					'type'        => 'string',
					'description' => 'Batch label stamped on every written row; generated when omitted.',
				),
				'dry_run'      => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		);
	}

	public static function ingestOutputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'success', 'dry_run', 'source', 'account', 'window', 'kinds', 'replaced', 'inserted', 'totals', 'provenance' ),
			'properties' => array(
				'success'      => array( 'type' => 'boolean' ),
				'dry_run'      => array( 'type' => 'boolean' ),
				'source'       => array( 'type' => 'string' ),
				'account'      => array( 'type' => 'string' ),
				'window'       => self::windowSchema(),
				'kinds'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'import_batch' => array( 'type' => 'string' ),
				'replaced'     => array(
					'type'        => 'integer',
					'description' => 'Stored rows deleted (or that would be deleted on dry_run).',
				),
				'inserted'     => array(
					'type'        => 'integer',
					'description' => 'Rows written (or that would be written on dry_run).',
				),
				'totals'       => self::totalsSchema(),
				'provenance'   => array( 'type' => 'object' ),
			),
		);
	}

	public static function queryInputSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'start_date'   => array(
					'type'        => 'string',
					'description' => 'Window start (YYYY-MM-DD). Totals include rows whose period lies fully inside the window.',
				),
				'end_date'     => array(
					'type'        => 'string',
					'description' => 'Window end (YYYY-MM-DD).',
				),
				'source'       => array( 'type' => 'string' ),
				'account'      => array( 'type' => 'string' ),
				'kind'         => array( 'type' => 'string' ),
				'currency'     => array( 'type' => 'string' ),
				'include_rows' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'limit'        => array(
					'type'        => 'integer',
					'default'     => 100,
					'description' => 'Maximum rows returned when include_rows is true; clamped to 1–1000. Totals always cover every matching row.',
				),
			),
		);
	}

	public static function queryOutputSchema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'success', 'window', 'totals', 'currency_totals', 'coverage', 'counts', 'provenance' ),
			'properties' => array(
				'success'         => array( 'type' => 'boolean' ),
				'window'          => self::windowSchema(),
				'totals'          => self::totalsSchema(),
				'currency_totals' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'currency' => array( 'type' => 'string' ),
							'amount'   => array( 'type' => 'string' ),
							'rows'     => array( 'type' => 'integer' ),
						),
					),
				),
				'coverage'        => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'source'     => array( 'type' => 'string' ),
							'account'    => array( 'type' => 'string' ),
							'kinds'      => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'grains'     => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'first_date' => array( 'type' => 'string' ),
							'last_date'  => array( 'type' => 'string' ),
							'covered'    => array(
								'type'  => 'array',
								'items' => self::windowSchema(),
							),
							'gaps'       => array(
								'type'  => 'array',
								'items' => self::windowSchema(),
							),
							'complete'   => array( 'type' => 'boolean' ),
						),
					),
				),
				'rows'            => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'counts'          => array(
					'type'       => 'object',
					'properties' => array(
						'matched_rows'         => array( 'type' => 'integer' ),
						'partial_overlap_rows' => array( 'type' => 'integer' ),
						'returned_rows'        => array( 'type' => 'integer' ),
					),
				),
				'provenance'      => array( 'type' => 'object' ),
			),
		);
	}

	private static function windowSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'start_date' => array( 'type' => array( 'string', 'null' ) ),
				'end_date'   => array( 'type' => array( 'string', 'null' ) ),
			),
		);
	}

	private static function totalsSchema(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'source'   => array( 'type' => 'string' ),
					'kind'     => array( 'type' => 'string' ),
					'currency' => array( 'type' => 'string' ),
					'amount'   => array( 'type' => 'string' ),
					'rows'     => array( 'type' => 'integer' ),
				),
			),
		);
	}

	/**
	 * Ingest one window of normalized rows. The only ledger write path.
	 *
	 * @return array|\WP_Error
	 */
	public static function ingest( array $input ) {
		$source  = strtolower( trim( (string) ( $input['source'] ?? '' ) ) );
		$account = trim( (string) ( $input['account'] ?? '' ) );
		if ( ! preg_match( self::SLUG_PATTERN, $source ) ) {
			return new \WP_Error( 'revenue_invalid_source', 'source must be a lowercase slug (a-z, 0-9, _ . -), at most 64 characters.' );
		}
		if ( strlen( $account ) > 191 ) {
			return new \WP_Error( 'revenue_invalid_account', 'account must be at most 191 characters.' );
		}
		$start = self::date( $input['start_date'] ?? null );
		$end   = self::date( $input['end_date'] ?? null );
		if ( null === $start || null === $end || $start > $end ) {
			return new \WP_Error( 'revenue_invalid_window', 'start_date and end_date must be YYYY-MM-DD dates with start_date <= end_date.' );
		}
		$default_grain = isset( $input['grain'] ) && '' !== $input['grain'] ? (string) $input['grain'] : self::deriveGrain( $start, $end );
		if ( ! in_array( $default_grain, self::GRAINS, true ) ) {
			return new \WP_Error( 'revenue_invalid_grain', 'grain must be one of: ' . implode( ', ', self::GRAINS ) . '.' );
		}
		$rows = $input['rows'] ?? null;
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
			return new \WP_Error( 'revenue_invalid_rows', 'rows must be a list of normalized ledger rows.' );
		}
		if ( count( $rows ) > self::MAX_ROWS ) {
			return new \WP_Error( 'revenue_too_many_rows', 'At most ' . self::MAX_ROWS . ' rows per ingest; split the window.' );
		}
		$batch = isset( $input['import_batch'] ) && '' !== (string) $input['import_batch'] ? substr( sanitize_text_field( (string) $input['import_batch'] ), 0, 64 ) : $source . '-' . gmdate( 'YmdHis' ) . '-' . substr( md5( wp_generate_password( 12, false ) ), 0, 8 );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$normalized = array();
		$totals     = array();
		foreach ( $rows as $index => $row ) {
			$clean = self::normalizeRow( $row, $index, $start, $end, $default_grain );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$micros                       = $clean['micros'];
			$clean['row']['source']       = $source;
			$clean['row']['account']      = $account;
			$clean['row']['import_batch'] = $batch;
			$clean['row']['imported_at']  = $now;
			$normalized[]                 = $clean['row'];
			$key                          = $clean['row']['kind'] . '|' . $clean['row']['currency'];
			$totals[ $key ]               = array(
				'source'   => $source,
				'kind'     => $clean['row']['kind'],
				'currency' => $clean['row']['currency'],
				'micros'   => ( $totals[ $key ]['micros'] ?? 0 ) + $micros,
				'rows'     => ( $totals[ $key ]['rows'] ?? 0 ) + 1,
			);
		}

		$kinds = array_values( array_unique( array_column( $normalized, 'kind' ) ) );
		if ( isset( $input['kinds'] ) ) {
			if ( ! is_array( $input['kinds'] ) ) {
				return new \WP_Error( 'revenue_invalid_kinds', 'kinds must be a list of kind slugs.' );
			}
			foreach ( $input['kinds'] as $kind ) {
				$kind = strtolower( trim( (string) $kind ) );
				if ( ! preg_match( self::SLUG_PATTERN, $kind ) ) {
					return new \WP_Error( 'revenue_invalid_kind', 'kinds must contain lowercase kind slugs.' );
				}
				$kinds[] = $kind;
			}
			$kinds = array_values( array_unique( $kinds ) );
		}
		sort( $kinds );

		RevenueLedger::maybe_upgrade();
		$ledger     = new RevenueLedger();
		$straddling = $ledger->count_straddling( $source, $account, $kinds, $start, $end );
		if ( $straddling > 0 ) {
			return new \WP_Error( 'revenue_window_conflict', sprintf( '%d stored row(s) for this source/account/kind partially overlap %s..%s. Ingest the window that contains them (e.g. the whole stored period) so replacement cannot double count.', $straddling, $start, $end ) );
		}

		$dry_run = ! empty( $input['dry_run'] );
		if ( $dry_run ) {
			$replaced = $ledger->count_window( $source, $account, $kinds, $start, $end );
			$inserted = count( $normalized );
		} else {
			$written = $ledger->replace_window( $source, $account, $kinds, $start, $end, $normalized );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			$replaced = $written['replaced'];
			$inserted = $written['inserted'];
		}

		return array(
			'success'      => true,
			'dry_run'      => $dry_run,
			'source'       => $source,
			'account'      => $account,
			'window'       => array(
				'start_date' => $start,
				'end_date'   => $end,
			),
			'kinds'        => $kinds,
			'import_batch' => $batch,
			'replaced'     => $replaced,
			'inserted'     => $inserted,
			'totals'       => self::formatTotals( $totals ),
			'provenance'   => array(
				'ability'       => self::INGEST_ABILITY,
				'table'         => $ledger->get_table_name(),
				'replace_scope' => 'source+account+kind rows contained in window',
				'grain'         => $default_grain,
				'amount_units'  => 'major',
			),
		);
	}

	/**
	 * Query totals, rows and coverage.
	 *
	 * @return array|\WP_Error
	 */
	public static function query( array $input ) {
		$start = null;
		$end   = null;
		if ( ! empty( $input['start_date'] ) ) {
			$start = self::date( $input['start_date'] );
			if ( null === $start ) {
				return new \WP_Error( 'revenue_invalid_window', 'start_date must be YYYY-MM-DD.' );
			}
		}
		if ( ! empty( $input['end_date'] ) ) {
			$end = self::date( $input['end_date'] );
			if ( null === $end ) {
				return new \WP_Error( 'revenue_invalid_window', 'end_date must be YYYY-MM-DD.' );
			}
		}
		if ( null !== $start && null !== $end && $start > $end ) {
			return new \WP_Error( 'revenue_invalid_window', 'start_date must be on or before end_date.' );
		}
		$filters = array(
			'start_date' => $start,
			'end_date'   => $end,
		);
		foreach ( array( 'source', 'account', 'kind' ) as $field ) {
			if ( isset( $input[ $field ] ) && '' !== (string) $input[ $field ] ) {
				$filters[ $field ] = 'account' === $field ? trim( (string) $input[ $field ] ) : strtolower( trim( (string) $input[ $field ] ) );
			}
		}
		if ( isset( $input['currency'] ) && '' !== (string) $input['currency'] ) {
			$filters['currency'] = strtoupper( trim( (string) $input['currency'] ) );
		}
		$include_rows = ! empty( $input['include_rows'] );
		$limit        = max( 1, min( 1000, (int) ( $input['limit'] ?? 100 ) ) );

		RevenueLedger::maybe_upgrade();
		$ledger      = new RevenueLedger();
		$overlapping = $ledger->find( $filters );

		$totals          = array();
		$currency_totals = array();
		$contained       = array();
		$partial         = 0;
		foreach ( $overlapping as $row ) {
			if ( ( null !== $start && $row['period_start'] < $start ) || ( null !== $end && $row['period_end'] > $end ) ) {
				++$partial;
				continue;
			}
			$contained[]                         = $row;
			$micros                              = RevenueDecimal::to_micros( $row['amount'] ) ?? 0;
			$key                                 = $row['source'] . '|' . $row['kind'] . '|' . $row['currency'];
			$totals[ $key ]                      = array(
				'source'   => $row['source'],
				'kind'     => $row['kind'],
				'currency' => $row['currency'],
				'micros'   => ( $totals[ $key ]['micros'] ?? 0 ) + $micros,
				'rows'     => ( $totals[ $key ]['rows'] ?? 0 ) + 1,
			);
			$currency_totals[ $row['currency'] ] = array(
				'currency' => $row['currency'],
				'micros'   => ( $currency_totals[ $row['currency'] ]['micros'] ?? 0 ) + $micros,
				'rows'     => ( $currency_totals[ $row['currency'] ]['rows'] ?? 0 ) + 1,
			);
		}
		ksort( $currency_totals );

		$result = array(
			'success'         => true,
			'window'          => array(
				'start_date' => $start,
				'end_date'   => $end,
			),
			'totals'          => self::formatTotals( $totals ),
			'currency_totals' => array_values(
				array_map(
					static fn( array $t ): array => array(
						'currency' => $t['currency'],
						'amount'   => RevenueDecimal::from_micros( $t['micros'] ),
						'rows'     => $t['rows'],
					),
					$currency_totals
				)
			),
			'coverage'        => self::coverage( $overlapping, $start, $end ),
			'counts'          => array(
				'matched_rows'         => count( $contained ),
				'partial_overlap_rows' => $partial,
				'returned_rows'        => $include_rows ? min( $limit, count( $contained ) ) : 0,
			),
			'provenance'      => array(
				'ability'            => self::QUERY_ABILITY,
				'table'              => $ledger->get_table_name(),
				'filters'            => array_filter( $filters, static fn( $v ) => null !== $v ),
				'total_semantics'    => 'Totals include rows whose period lies fully inside the window; partially overlapping rows are counted in partial_overlap_rows and never prorated.',
				'currency_semantics' => 'Amounts are never converted; totals are per currency.',
				'amount_units'       => 'major',
			),
		);
		if ( $include_rows ) {
			$result['rows'] = array_slice( $contained, 0, $limit );
		}
		return $result;
	}

	/**
	 * Derive coverage from stored periods.
	 *
	 * Groups rows by source/account, merges their periods (clipped to the
	 * window) into contiguous day ranges, and reports the gaps inside the
	 * window (or between the first and last stored day when unbounded).
	 *
	 * @param array[] $rows Hydrated rows.
	 */
	public static function coverage( array $rows, ?string $start = null, ?string $end = null ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$key = $row['source'] . "\0" . $row['account'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'source'    => $row['source'],
					'account'   => $row['account'],
					'kinds'     => array(),
					'grains'    => array(),
					'intervals' => array(),
				);
			}
			$groups[ $key ]['kinds'][ $row['kind'] ]   = true;
			$groups[ $key ]['grains'][ $row['grain'] ] = true;
			$from                                      = null !== $start && $row['period_start'] < $start ? $start : $row['period_start'];
			$to                                        = null !== $end && $row['period_end'] > $end ? $end : $row['period_end'];
			$groups[ $key ]['intervals'][ $from . '|' . $to ] = array( $from, $to );
		}
		ksort( $groups );

		$coverage = array();
		foreach ( $groups as $group ) {
			$intervals = array_values( $group['intervals'] );
			usort( $intervals, static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );
			$merged = array();
			foreach ( $intervals as list( $from, $to ) ) {
				$last = count( $merged ) - 1;
				if ( $last >= 0 && $from <= self::addDays( $merged[ $last ][1], 1 ) ) {
					if ( $to > $merged[ $last ][1] ) {
						$merged[ $last ][1] = $to;
					}
					continue;
				}
				$merged[] = array( $from, $to );
			}
			$first       = $merged[0][0];
			$latest      = $merged[ count( $merged ) - 1 ][1];
			$bound_start = $start ?? $first;
			$bound_end   = $end ?? $latest;
			$gaps        = array();
			$cursor      = $bound_start;
			foreach ( $merged as list( $from, $to ) ) {
				if ( $from > $cursor ) {
					$gaps[] = array( $cursor, self::addDays( $from, -1 ) );
				}
				$next = self::addDays( $to, 1 );
				if ( $next > $cursor ) {
					$cursor = $next;
				}
			}
			if ( $cursor <= $bound_end ) {
				$gaps[] = array( $cursor, $bound_end );
			}
			$kinds  = array_keys( $group['kinds'] );
			$grains = array_keys( $group['grains'] );
			sort( $kinds );
			sort( $grains );
			$pairs      = static fn( array $date_pairs ): array => array_map(
				static fn( array $p ): array => array(
					'start_date' => $p[0],
					'end_date'   => $p[1],
				),
				$date_pairs
			);
			$coverage[] = array(
				'source'     => $group['source'],
				'account'    => $group['account'],
				'kinds'      => $kinds,
				'grains'     => $grains,
				'first_date' => $first,
				'last_date'  => $latest,
				'covered'    => $pairs( $merged ),
				'gaps'       => $pairs( $gaps ),
				'complete'   => empty( $gaps ),
			);
		}
		return $coverage;
	}

	/** Day/month grain when the window is exactly one day or one calendar month. */
	public static function deriveGrain( string $start, string $end ): string {
		if ( $start === $end ) {
			return 'day';
		}
		if ( str_ends_with( $start, '-01' ) && substr( $start, 0, 7 ) === substr( $end, 0, 7 ) && gmdate( 'Y-m-t', (int) strtotime( $start . ' 00:00:00 UTC' ) ) === $end ) {
			return 'month';
		}
		return 'range';
	}

	/**
	 * Validate and normalize one row.
	 *
	 * @param mixed $row Raw row.
	 * @return array{row:array,micros:int}|\WP_Error
	 */
	private static function normalizeRow( $row, int $index, string $start, string $end, string $default_grain ) {
		$label = 'rows[' . $index . ']';
		if ( ! is_array( $row ) ) {
			return new \WP_Error( 'revenue_invalid_row', $label . ' must be an object.' );
		}
		$kind = strtolower( trim( (string) ( $row['kind'] ?? '' ) ) );
		if ( ! preg_match( self::SLUG_PATTERN, $kind ) ) {
			return new \WP_Error( 'revenue_invalid_kind', $label . '.kind must be a lowercase slug (e.g. ad_revenue, commission, charge, payout, refund).' );
		}
		$micros = RevenueDecimal::to_micros( $row['amount'] ?? null );
		if ( null === $micros ) {
			return new \WP_Error( 'revenue_invalid_amount', $label . '.amount must be a decimal with at most 6 fraction digits.' );
		}
		$currency = strtoupper( trim( (string) ( $row['currency'] ?? '' ) ) );
		if ( ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) {
			return new \WP_Error( 'revenue_invalid_currency', $label . '.currency must be a 3-letter ISO 4217 code.' );
		}
		$period_start = isset( $row['period_start'] ) && '' !== $row['period_start'] ? self::date( $row['period_start'] ) : $start;
		$period_end   = isset( $row['period_end'] ) && '' !== $row['period_end'] ? self::date( $row['period_end'] ) : $end;
		if ( null === $period_start || null === $period_end || $period_start > $period_end ) {
			return new \WP_Error( 'revenue_invalid_period', $label . ' period_start/period_end must be YYYY-MM-DD with start <= end.' );
		}
		if ( $period_start < $start || $period_end > $end ) {
			return new \WP_Error( 'revenue_row_outside_window', $label . ' period ' . $period_start . '..' . $period_end . ' lies outside the ingest window ' . $start . '..' . $end . '.' );
		}
		$grain = isset( $row['grain'] ) && '' !== $row['grain'] ? (string) $row['grain'] : ( $period_start === $start && $period_end === $end ? $default_grain : self::deriveGrain( $period_start, $period_end ) );
		if ( ! in_array( $grain, self::GRAINS, true ) ) {
			return new \WP_Error( 'revenue_invalid_grain', $label . '.grain must be one of: ' . implode( ', ', self::GRAINS ) . '.' );
		}
		$metrics = $row['metrics'] ?? null;
		if ( null !== $metrics && ! is_array( $metrics ) ) {
			return new \WP_Error( 'revenue_invalid_metrics', $label . '.metrics must be an object of source-native values.' );
		}
		$metrics_json = null;
		if ( ! empty( $metrics ) ) {
			$metrics_json = wp_json_encode( $metrics, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $metrics_json ) {
				return new \WP_Error( 'revenue_invalid_metrics', $label . '.metrics must be JSON-serializable.' );
			}
		}
		$url        = isset( $row['url'] ) && '' !== (string) $row['url'] ? substr( (string) $row['url'], 0, 2048 ) : null;
		$object_ref = isset( $row['object_ref'] ) && '' !== (string) $row['object_ref'] ? substr( (string) $row['object_ref'], 0, 191 ) : null;
		$blog_id    = isset( $row['blog_id'] ) && '' !== (string) $row['blog_id'] ? absint( $row['blog_id'] ) : null;

		return array(
			'micros' => $micros,
			'row'    => array(
				'kind'         => $kind,
				'period_start' => $period_start,
				'period_end'   => $period_end,
				'grain'        => $grain,
				'amount'       => RevenueDecimal::to_storage( $micros ),
				'currency'     => $currency,
				'url'          => $url,
				'object_ref'   => $object_ref,
				'blog_id'      => $blog_id,
				'metrics'      => $metrics_json,
			),
		);
	}

	private static function formatTotals( array $totals ): array {
		ksort( $totals );
		return array_values(
			array_map(
				static fn( array $t ): array => array(
					'source'   => $t['source'],
					'kind'     => $t['kind'],
					'currency' => $t['currency'],
					'amount'   => RevenueDecimal::from_micros( $t['micros'] ),
					'rows'     => $t['rows'],
				),
				$totals
			)
		);
	}

	/** Strict YYYY-MM-DD validation. */
	private static function date( $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', trim( $value ), $m ) ) {
			return null;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? trim( $value ) : null;
	}

	private static function addDays( string $date, int $days ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $date . ' 00:00:00 UTC' ) + $days * DAY_IN_SECONDS );
	}
}
