<?php
/**
 * Amazon Associates report parser and normalizer.
 *
 * Associates Central exposes no public earnings API, so reports arrive as
 * operator-downloaded CSV/TSV exports. Parsing (text -> associative records)
 * and normalization (associative record -> storage row) are deliberately
 * separate: a future live fetcher can hand associative records straight to
 * normalizeRecords() without going through the text parser.
 *
 * @package DataMachineBusiness\Abilities\Analytics\AmazonAssociates
 */

namespace DataMachineBusiness\Abilities\Analytics\AmazonAssociates;

defined( 'ABSPATH' ) || exit;

final class ReportParser {

	const REPORT_TYPES = array( 'earnings', 'orders' );

	/** Header aliases (normalized header => canonical field) per report type. */
	const COLUMNS = array(
		'earnings' => array(
			'category'          => 'category',
			'name'              => 'name',
			'product name'      => 'name',
			'asin'              => 'asin',
			'seller'            => 'seller',
			'tracking id'       => 'tracking_id',
			'tag'               => 'tracking_id',
			'date shipped'      => 'date',
			'shipped date'      => 'date',
			'price'             => 'price',
			'items shipped'     => 'items',
			'returns'           => 'returns',
			'revenue'           => 'revenue',
			'ad fees'           => 'ad_fees',
			'device type group' => 'device_type',
			'device type'       => 'device_type',
		),
		'orders'   => array(
			'category'          => 'category',
			'name'              => 'name',
			'product name'      => 'name',
			'asin'              => 'asin',
			'seller'            => 'seller',
			'date'              => 'date',
			'order date'        => 'date',
			'qty'               => 'items',
			'quantity'          => 'items',
			'price'             => 'price',
			'link type'         => 'link_type',
			'tag'               => 'tracking_id',
			'tracking id'       => 'tracking_id',
			'indirect sales'    => 'indirect',
			'device type group' => 'device_type',
			'device type'       => 'device_type',
		),
	);

	/** Canonical fields that must be present, with the header label used in errors. */
	const REQUIRED = array(
		'earnings' => array(
			'asin'        => 'ASIN',
			'tracking_id' => 'Tracking ID',
			'date'        => 'Date Shipped',
			'items'       => 'Items Shipped',
			'revenue'     => 'Revenue($)',
			'ad_fees'     => 'Ad Fees($)',
		),
		'orders'   => array(
			'asin'  => 'ASIN',
			'date'  => 'Date',
			'items' => 'Qty',
		),
	);

	/** Headers that only appear in one report type; used for type detection. */
	const MARKERS = array(
		'earnings' => array( 'date shipped', 'shipped date', 'items shipped', 'ad fees', 'revenue', 'returns' ),
		'orders'   => array( 'qty', 'quantity', 'link type', 'indirect sales', 'order date' ),
	);

	const CURRENCY_SYMBOLS = array(
		'$'    => 'USD',
		'usd'  => 'USD',
		'£'    => 'GBP',
		'gbp'  => 'GBP',
		'€'    => 'EUR',
		'eur'  => 'EUR',
		'¥'    => 'JPY',
		'jpy'  => 'JPY',
		'cad'  => 'CAD',
		'cdn$' => 'CAD',
		'aud'  => 'AUD',
		'inr'  => 'INR',
		'₹'    => 'INR',
	);

	const MARKETPLACE_CURRENCIES = array(
		'www.amazon.com'    => 'USD',
		'www.amazon.ca'     => 'CAD',
		'www.amazon.co.uk'  => 'GBP',
		'www.amazon.de'     => 'EUR',
		'www.amazon.fr'     => 'EUR',
		'www.amazon.it'     => 'EUR',
		'www.amazon.es'     => 'EUR',
		'www.amazon.nl'     => 'EUR',
		'www.amazon.co.jp'  => 'JPY',
		'www.amazon.com.au' => 'AUD',
		'www.amazon.in'     => 'INR',
	);

	/**
	 * Parse an Associates Central CSV/TSV export.
	 *
	 * @param string $content Raw report text.
	 * @param array  $options Optional report_type and marketplace.
	 * @return array|\WP_Error Parsed report: report_type, title, title_window, currency, delimiter, rows, skipped.
	 */
	public static function parse( string $content, array $options = array() ) {
		$content = self::stripBom( $content );
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );
		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'amazon_associates_empty_report', 'The Amazon Associates report is empty.' );
		}
		if ( '<' === substr( ltrim( $content ), 0, 1 ) ) {
			return new \WP_Error( 'amazon_associates_unsupported_format', 'XML Associates reports are not supported. Download the report as CSV or TSV from Associates Central.' );
		}

		$delimiter = self::detectDelimiter( $content );
		$records   = self::readRecords( $content, $delimiter );

		$title        = null;
		$header_index = null;
		foreach ( $records as $index => $record ) {
			$normalized = array_map( array( self::class, 'normalizeHeader' ), $record );
			if ( in_array( 'asin', $normalized, true ) ) {
				$header_index = $index;
				break;
			}
			$line = trim( implode( ' ', $record ) );
			if ( null === $title && '' !== $line ) {
				$title = $line;
			}
		}
		if ( null === $header_index ) {
			return new \WP_Error( 'amazon_associates_header_not_found', 'Could not find the Associates report header row (expected a row containing an "ASIN" column).' );
		}

		$headers     = $records[ $header_index ];
		$normalized  = array_map( array( self::class, 'normalizeHeader' ), $headers );
		$report_type = self::detectReportType( $normalized, (string) ( $options['report_type'] ?? '' ) );
		if ( is_wp_error( $report_type ) ) {
			return $report_type;
		}

		$map     = array();
		$aliases = self::COLUMNS[ $report_type ];
		foreach ( $normalized as $position => $header ) {
			if ( isset( $aliases[ $header ] ) && ! in_array( $aliases[ $header ], $map, true ) ) {
				$map[ $position ] = $aliases[ $header ];
			}
		}
		$missing = array_diff_key( self::REQUIRED[ $report_type ], array_flip( $map ) );
		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'amazon_associates_missing_columns',
				sprintf( 'Amazon Associates %s report is missing required column(s): %s.', $report_type, implode( ', ', $missing ) )
			);
		}

		$marketplace = self::normalizeMarketplace( (string) ( $options['marketplace'] ?? '' ) );
		$currency    = self::detectCurrency( $headers ) ?? ( self::MARKETPLACE_CURRENCIES[ $marketplace ] ?? 'USD' );

		$assoc = array();
		foreach ( array_slice( $records, (int) $header_index + 1 ) as $record ) {
			$row = array();
			foreach ( $map as $position => $field ) {
				$row[ $field ] = trim( (string) ( $record[ $position ] ?? '' ) );
			}
			$assoc[] = $row;
		}

		$normalized_rows = self::normalizeRecords( $report_type, $assoc, $currency, $marketplace );

		return array(
			'report_type'  => $report_type,
			'title'        => $title,
			'title_window' => self::titleWindow( (string) $title ),
			'currency'     => $currency,
			'marketplace'  => $marketplace,
			'delimiter'    => "\t" === $delimiter ? 'tab' : 'comma',
			'columns'      => array_values( $map ),
			'rows'         => $normalized_rows['rows'],
			'skipped'      => $normalized_rows['skipped'],
		);
	}

	/**
	 * Normalize source-agnostic associative records (canonical field keys) into storage rows.
	 *
	 * @param string $report_type earnings|orders.
	 * @param array  $records     Records keyed by canonical field names.
	 * @param string $currency    ISO currency code.
	 * @param string $marketplace Marketplace host.
	 * @return array{rows: array<int,array>, skipped: array<int,array>}
	 */
	public static function normalizeRecords( string $report_type, array $records, string $currency, string $marketplace ): array {
		$rows    = array();
		$skipped = array();
		foreach ( array_values( $records ) as $index => $record ) {
			$values = array_filter( array_map( 'strval', $record ), static fn( $value ) => '' !== trim( $value ) );
			if ( empty( $values ) ) {
				continue;
			}
			$asin = strtoupper( trim( (string) ( $record['asin'] ?? '' ) ) );
			$date = self::normalizeDate( (string) ( $record['date'] ?? '' ) );
			if ( '' === $asin || null === $date ) {
				// Footer/total lines carry neither an ASIN nor a parseable date.
				$skipped[] = array(
					'line'   => $index + 1,
					'reason' => '' === $asin ? 'missing_asin' : 'invalid_date',
				);
				continue;
			}
			$rows[] = array(
				'report_type' => $report_type,
				'date'        => $date,
				'tracking_id' => strtolower( trim( (string) ( $record['tracking_id'] ?? '' ) ) ),
				'asin'        => $asin,
				'name'        => self::text( $record['name'] ?? '', 1000 ),
				'category'    => self::text( $record['category'] ?? '', 191 ),
				'seller'      => self::text( $record['seller'] ?? '', 191 ),
				'price'       => self::money( (string) ( $record['price'] ?? '' ) ),
				'items'       => self::integer( (string) ( $record['items'] ?? '' ) ),
				'returns'     => self::integer( (string) ( $record['returns'] ?? '' ) ),
				'revenue'     => self::money( (string) ( $record['revenue'] ?? '' ) ),
				'ad_fees'     => self::money( (string) ( $record['ad_fees'] ?? '' ) ),
				'device_type' => self::text( $record['device_type'] ?? '', 64 ),
				'link_type'   => self::text( $record['link_type'] ?? '', 64 ),
				'indirect'    => self::flag( (string) ( $record['indirect'] ?? '' ) ),
				'currency'    => $currency,
				'marketplace' => $marketplace,
			);
		}
		return array(
			'rows'    => $rows,
			'skipped' => $skipped,
		);
	}

	/** Lowercase, drop currency suffixes like "($)" and collapse whitespace. */
	public static function normalizeHeader( $header ): string {
		$header = self::stripBom( (string) $header );
		$header = preg_replace( '/\([^)]*\)/u', '', $header );
		$header = strtolower( trim( (string) preg_replace( '/\s+/u', ' ', (string) $header ) ) );
		return trim( $header, " \t\"'" );
	}

	public static function normalizeMarketplace( string $marketplace ): string {
		$marketplace = strtolower( trim( $marketplace ) );
		$marketplace = (string) preg_replace( '#^https?://#', '', $marketplace );
		$marketplace = rtrim( $marketplace, '/' );
		if ( '' === $marketplace ) {
			return 'www.amazon.com';
		}
		if ( str_starts_with( $marketplace, 'amazon.' ) ) {
			$marketplace = 'www.' . $marketplace;
		}
		return substr( $marketplace, 0, 64 );
	}

	/**
	 * Convert a money cell to a two-decimal string. Handles currency symbols,
	 * thousands separators, European decimal commas and accounting negatives.
	 */
	public static function money( string $value ): string {
		return self::formatCents( self::toCents( $value ) );
	}

	public static function toCents( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		$negative = str_starts_with( $value, '-' ) || ( str_starts_with( $value, '(' ) && str_ends_with( $value, ')' ) );
		$value    = (string) preg_replace( '/[^0-9.,]/', '', $value );
		$last_dot = strrpos( $value, '.' );
		$last_com = strrpos( $value, ',' );
		if ( false !== $last_com && ( false === $last_dot || $last_com > $last_dot ) && 2 === strlen( $value ) - $last_com - 1 ) {
			// Decimal comma (e.g. 1.234,56 or 12,34).
			$value = str_replace( array( '.', ',' ), array( '', '.' ), $value );
		} else {
			$value = str_replace( ',', '', $value );
		}
		if ( ! preg_match( '/^(\d*)(?:\.(\d*))?$/', $value, $match ) ) {
			return 0;
		}
		$whole    = '' === $match[1] ? '0' : $match[1];
		$fraction = str_pad( substr( $match[2] ?? '', 0, 3 ), 3, '0' );
		$cents    = (int) $whole * 100 + intdiv( (int) $fraction + 5, 10 );
		return $negative ? -$cents : $cents;
	}

	public static function formatCents( int $cents ): string {
		$sign = $cents < 0 ? '-' : '';
		$abs  = abs( $cents );
		return $sign . intdiv( $abs, 100 ) . '.' . str_pad( (string) ( $abs % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/** Parse report dates into Y-m-d; null when unparseable. */
	public static function normalizeDate( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
		}
		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})#', $value, $m ) ) {
			// Associates Central (US) uses month/day/year.
			return checkdate( (int) $m[1], (int) $m[2], (int) $m[3] ) ? sprintf( '%04d-%02d-%02d', $m[3], $m[1], $m[2] ) : null;
		}
		$timestamp = strtotime( $value . ' UTC' );
		if ( false === $timestamp ) {
			$timestamp = strtotime( $value );
		}
		return false === $timestamp ? null : gmdate( 'Y-m-d', $timestamp );
	}

	/** "Fee-Earnings reports from 2026-10-01 to 2026-10-31" -> window. */
	public static function titleWindow( string $title ): ?array {
		if ( ! preg_match( '/from\s+(.+?)\s+to\s+(.+?)\s*$/i', $title, $m ) ) {
			return null;
		}
		$start = self::normalizeDate( $m[1] );
		$end   = self::normalizeDate( $m[2] );
		if ( null === $start || null === $end || $start > $end ) {
			return null;
		}
		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	private static function detectReportType( array $normalized_headers, string $requested ) {
		if ( '' !== $requested ) {
			if ( ! in_array( $requested, self::REPORT_TYPES, true ) ) {
				return new \WP_Error( 'amazon_associates_invalid_report_type', 'report_type must be earnings or orders.' );
			}
			return $requested;
		}
		foreach ( self::REPORT_TYPES as $type ) {
			if ( array_intersect( self::MARKERS[ $type ], $normalized_headers ) ) {
				return $type;
			}
		}
		return new \WP_Error(
			'amazon_associates_unknown_report_type',
			'Could not detect the Associates report type. Earnings reports need Date Shipped, Items Shipped, Revenue($) and Ad Fees($); orders reports need Date and Qty.'
		);
	}

	private static function detectCurrency( array $headers ): ?string {
		foreach ( $headers as $header ) {
			if ( preg_match( '/\(([^)]+)\)/u', (string) $header, $m ) ) {
				$symbol = strtolower( trim( $m[1] ) );
				if ( isset( self::CURRENCY_SYMBOLS[ $symbol ] ) ) {
					return self::CURRENCY_SYMBOLS[ $symbol ];
				}
			}
		}
		return null;
	}

	private static function detectDelimiter( string $content ): string {
		$lines = array_slice( array_filter( explode( "\n", $content ), static fn( $line ) => '' !== trim( $line ) ), 0, 5 );
		$tabs  = 0;
		$comma = 0;
		foreach ( $lines as $line ) {
			$tabs  = max( $tabs, substr_count( $line, "\t" ) );
			$comma = max( $comma, substr_count( $line, ',' ) );
		}
		return $tabs >= 2 && $tabs >= $comma / 2 ? "\t" : ',';
	}

	/** Read delimited records, honoring quoted fields that span lines. */
	private static function readRecords( string $content, string $delimiter ): array {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- In-memory php://temp stream, not the filesystem.
		$stream = fopen( 'php://temp', 'r+' );
		if ( false === $stream ) {
			return array();
		}
		fwrite( $stream, $content );
		rewind( $stream );
		$records = array();
		while ( true ) {
			$record = fgetcsv( $stream, 0, $delimiter, '"', '' );
			if ( false === $record ) {
				break;
			}
			if ( array( null ) === $record ) {
				continue;
			}
			$records[] = array_map( static fn( $cell ) => (string) $cell, $record );
		}
		fclose( $stream );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		return $records;
	}

	private static function stripBom( string $value ): string {
		return str_starts_with( $value, "\xEF\xBB\xBF" ) ? substr( $value, 3 ) : $value;
	}

	private static function text( $value, int $max ): string {
		$value = trim( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	private static function integer( string $value ): int {
		$value = (string) preg_replace( '/[^0-9\-]/', '', $value );
		return '' === $value || '-' === $value ? 0 : (int) $value;
	}

	private static function flag( string $value ): ?bool {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return null;
		}
		// Associates exports "Direct"/"Indirect" (sometimes DIRECT_SALES/INDIRECT_SALES).
		return str_contains( $value, 'indirect' ) || in_array( $value, array( 'yes', 'y', 'true', '1' ), true );
	}
}
