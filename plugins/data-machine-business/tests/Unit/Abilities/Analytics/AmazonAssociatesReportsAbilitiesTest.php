<?php
/**
 * Amazon Associates import-based revenue reporting contract tests.
 *
 * @package DataMachine\Tests\Unit\Abilities\Analytics
 */

namespace DataMachine\Tests\Unit\Abilities\Analytics;

use DataMachineBusiness\Abilities\Analytics\AmazonAssociates\ReportParser;
use DataMachineBusiness\Abilities\Analytics\AmazonAssociatesReportsAbilities as Amazon;
use DataMachineBusiness\Cli\AmazonAssociatesCommand;
use DataMachineBusiness\Cli\CommandRegistry;
use WP_UnitTestCase;

class AmazonAssociatesReportsAbilitiesTest extends WP_UnitTestCase {

	private function fixturePath( string $name ): string {
		return dirname( __DIR__, 3 ) . '/fixtures/amazon-associates/' . $name;
	}

	private function fixture( string $name ): string {
		$content = file_get_contents( $this->fixturePath( $name ) );
		$this->assertIsString( $content );
		return $content;
	}

	private function parse( string $content, array $options = array() ): array {
		$parsed = ReportParser::parse( $content, $options );
		$this->assertFalse( is_wp_error( $parsed ), is_wp_error( $parsed ) ? $parsed->get_error_message() : '' );
		return $parsed;
	}

	private function importFixture( string $name, array $extra = array() ): array {
		$result = Amazon::execute( array_merge( array(
			'action' => 'import',
			'file'   => $this->fixturePath( $name ),
		), $extra ) );
		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	private function byTracking( array $rows ): array {
		return array_column( $rows, null, 'tracking_id' );
	}

	public function test_parses_earnings_csv_with_title_line(): void {
		$parsed = $this->parse( $this->fixture( 'earnings-2026-10.csv' ) );

		$this->assertSame( 'earnings', $parsed['report_type'] );
		$this->assertSame( 'comma', $parsed['delimiter'] );
		$this->assertSame( 'Fee-Earnings reports from 2026-10-01 to 2026-10-31', $parsed['title'] );
		$this->assertSame( array( 'start' => '2026-10-01', 'end' => '2026-10-31' ), $parsed['title_window'] );
		$this->assertSame( 'USD', $parsed['currency'] );
		$this->assertCount( 6, $parsed['rows'] );
		$this->assertSame( array(), $parsed['skipped'] );

		$first = $parsed['rows'][0];
		$this->assertSame( '2026-10-03', $first['date'] );
		$this->assertSame( 'extrachill-20', $first['tracking_id'] );
		$this->assertSame( 'B0BF1DWQPH', $first['asin'] );
		$this->assertSame( 'Fender Player Stratocaster, Maple Fingerboard', $first['name'] );
		$this->assertSame( '849.99', $first['price'] );
		$this->assertSame( '25.50', $first['ad_fees'] );
		$this->assertSame( 1, $first['items'] );
		$this->assertSame( 'DESKTOP', $first['device_type'] );

		$quoted = $parsed['rows'][5];
		$this->assertSame( 'Yamaha HS8 8" Powered Studio Monitor, Pair', $quoted['name'] );
		$this->assertSame( '1099.98', $quoted['revenue'], 'Thousands separators are stripped into a decimal string.' );

		$returned = $parsed['rows'][3];
		$this->assertSame( 3, $returned['items'] );
		$this->assertSame( 1, $returned['returns'] );
	}

	public function test_tsv_with_bom_matches_csv_for_earnings_and_orders(): void {
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $this->fixture( 'earnings-2026-10.tsv' ) );

		foreach ( array( 'earnings', 'orders' ) as $type ) {
			$csv = $this->parse( $this->fixture( "{$type}-2026-10.csv" ) );
			$tsv = $this->parse( $this->fixture( "{$type}-2026-10.tsv" ) );

			$this->assertSame( 'tab', $tsv['delimiter'] );
			$this->assertSame( $type, $tsv['report_type'] );
			$this->assertSame( $csv['title_window'], $tsv['title_window'] );
			$this->assertSame( $csv['rows'], $tsv['rows'], "{$type} TSV must normalize identically to CSV." );
		}
	}

	public function test_bom_is_stripped_with_and_without_title_line(): void {
		$csv     = $this->fixture( 'earnings-2026-10.csv' );
		$with    = $this->parse( "\xEF\xBB\xBF" . $csv );
		$lines   = explode( "\r\n", $csv );
		$headers = $this->parse( "\xEF\xBB\xBF" . implode( "\r\n", array_slice( $lines, 1 ) ) );

		$this->assertSame( $this->parse( $csv )['rows'], $with['rows'] );
		$this->assertNull( $headers['title'] );
		$this->assertNull( $headers['title_window'] );
		$this->assertSame( 'category', $headers['columns'][0], 'BOM must not corrupt the first header.' );
		$this->assertCount( 6, $headers['rows'] );
	}

	public function test_parses_orders_report(): void {
		$parsed = $this->parse( $this->fixture( 'orders-2026-10.csv' ) );

		$this->assertSame( 'orders', $parsed['report_type'] );
		$this->assertCount( 5, $parsed['rows'] );
		$row = $parsed['rows'][2];
		$this->assertSame( '2026-10-10', $row['date'] );
		$this->assertSame( 'chubesnet-20', $row['tracking_id'] );
		$this->assertSame( 1, $row['items'] );
		$this->assertSame( 'Text', $row['link_type'] );
		$this->assertTrue( $row['indirect'] );
		$this->assertFalse( $parsed['rows'][0]['indirect'] );
		$this->assertSame( '0.00', $row['ad_fees'] );
	}

	public function test_missing_required_columns_error_names_them(): void {
		$error = ReportParser::parse( $this->fixture( 'earnings-missing-columns.csv' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'amazon_associates_missing_columns', $error->get_error_code() );
		$this->assertStringContainsString( 'Tracking ID', $error->get_error_message() );
		$this->assertStringContainsString( 'Ad Fees($)', $error->get_error_message() );

		$import = Amazon::execute( array( 'action' => 'import', 'file' => $this->fixturePath( 'earnings-missing-columns.csv' ) ) );
		$this->assertWPError( $import );
	}

	public function test_headers_are_case_insensitive_unknown_columns_ignored_and_footers_skipped(): void {
		$content = "ASIN\tTRACKING ID\tdate shipped\tITEMS SHIPPED\tRevenue(£)\tAd Fees(£)\tMystery Column\n"
			. "b0bf1dwqph\tExtraChill-21\t2026-10-03\t2\t10,50\t0,53\tignored\n"
			. "Total\t\t\t2\t10,50\t0,53\t\n";
		$parsed  = $this->parse( $content );

		$this->assertSame( 'earnings', $parsed['report_type'] );
		$this->assertSame( 'GBP', $parsed['currency'] );
		$this->assertCount( 1, $parsed['rows'] );
		$this->assertSame( 'B0BF1DWQPH', $parsed['rows'][0]['asin'] );
		$this->assertSame( 'extrachill-21', $parsed['rows'][0]['tracking_id'] );
		$this->assertSame( '10.50', $parsed['rows'][0]['revenue'] );
		$this->assertSame( '0.53', $parsed['rows'][0]['ad_fees'] );
		$this->assertCount( 1, $parsed['skipped'] );
		$this->assertNotContains( 'Mystery Column', $parsed['columns'] );
	}

	public function test_rejects_unrecognizable_and_xml_reports(): void {
		$this->assertSame( 'amazon_associates_header_not_found', ReportParser::parse( "foo,bar\n1,2\n" )->get_error_code() );
		$this->assertSame( 'amazon_associates_unsupported_format', ReportParser::parse( '<?xml version="1.0"?><Data/>' )->get_error_code() );
		$this->assertSame( 'amazon_associates_unknown_report_type', ReportParser::parse( "ASIN,Name\nB0,x\n" )->get_error_code() );
	}

	public function test_reimport_replaces_window_without_duplicates(): void {
		$first = $this->importFixture( 'earnings-2026-10.csv' );
		$this->assertSame( 'amazon_associates', $first['source'] );
		$this->assertSame( 6, $first['import']['rows_inserted'] );
		$this->assertSame( 0, $first['import']['rows_replaced'] );
		$this->assertSame( array( 'chubesnet-20', 'extrachill-20' ), $first['import']['tracking_ids'] );
		$this->assertSame( array( 'start_date' => '2026-10-01', 'end_date' => '2026-10-31' ), $first['import']['window'] );

		$second = $this->importFixture( 'earnings-2026-10.tsv' );
		$this->assertSame( 6, $second['import']['rows_replaced'] );
		$this->assertSame( 6, $second['import']['rows_inserted'] );

		$rows = Amazon::execute( array( 'action' => 'earnings', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31' ) );
		$this->assertSame( 6, $rows['row_count'] );
		$this->assertCount( 1, Amazon::execute( array( 'action' => 'coverage' ) )['coverage']['windows'][0]['intervals'] );
		$this->assertSame( 1, Amazon::execute( array( 'action' => 'coverage' ) )['coverage']['windows'][0]['imports'] );
	}

	public function test_dry_run_reports_without_writing(): void {
		$dry = $this->importFixture( 'earnings-2026-10.csv', array( 'dry_run' => true ) );

		$this->assertTrue( $dry['import']['dry_run'] );
		$this->assertSame( 6, $dry['import']['rows_parsed'] );
		$this->assertSame( 0, $dry['import']['rows_inserted'] );
		$this->assertSame( 0, Amazon::execute( array( 'action' => 'earnings', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31' ) )['row_count'] );
		$this->assertSame( array(), Amazon::execute( array( 'action' => 'coverage' ) )['coverage']['windows'] );
	}

	public function test_summary_math_by_tracking_id_with_full_coverage(): void {
		$this->importFixture( 'earnings-2026-10.csv' );
		$this->importFixture( 'orders-2026-10.tsv' );

		$summary = Amazon::execute( array( 'action' => 'summary', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31' ) );

		$this->assertTrue( $summary['success'] );
		$this->assertSame( 'summary', $summary['action'] );
		$this->assertSame( array( 'start_date' => '2026-10-01', 'end_date' => '2026-10-31' ), $summary['date_range'] );
		$this->assertSame( 11, $summary['row_count'] );
		$this->assertCount( 1, $summary['totals'] );
		$total = $summary['totals'][0];
		$this->assertSame( 'USD', $total['currency'] );
		$this->assertSame( '71.67', $total['ad_fees'] );
		$this->assertSame( '2379.94', $total['revenue'] );
		$this->assertSame( 9, $total['items_shipped'] );
		$this->assertSame( 1, $total['returns'] );
		$this->assertSame( 8, $total['ordered_items'] );

		$by = $this->byTracking( $summary['by_tracking_id'] );
		$this->assertSame( '67.85', $by['extrachill-20']['ad_fees'] );
		$this->assertSame( '2261.95', $by['extrachill-20']['revenue'] );
		$this->assertSame( 7, $by['extrachill-20']['items_shipped'] );
		$this->assertSame( 6, $by['extrachill-20']['ordered_items'] );
		$this->assertSame( '3.82', $by['chubesnet-20']['ad_fees'] );
		$this->assertSame( '117.99', $by['chubesnet-20']['revenue'] );
		$this->assertSame( 2, $by['chubesnet-20']['ordered_items'] );

		$this->assertTrue( $summary['coverage']['complete'] );
		$this->assertSame( '2026-10-31', $summary['coverage']['report_types']['earnings']['covered_through'] );
		$this->assertTrue( $summary['coverage']['report_types']['orders']['complete'] );
		$this->assertSame( array(), $summary['warnings'] );
		$this->assertNotEmpty( $summary['imported_at'] );
		$this->assertNotEmpty( $summary['fetched_at'] );
		$this->assertSame( 'datamachine/amazon-associates-reports', $summary['provenance']['source']['ability'] );

		$filtered = Amazon::execute( array( 'action' => 'summary', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15', 'tracking_id' => 'ExtraChill-20' ) );
		$this->assertSame( '34.86', $filtered['totals'][0]['ad_fees'] );
		$this->assertCount( 1, $filtered['by_tracking_id'] );
	}

	public function test_summary_flags_uncovered_windows(): void {
		$empty = Amazon::execute( array( 'action' => 'summary', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31' ) );
		$this->assertFalse( $empty['coverage']['complete'] );
		$this->assertNull( $empty['coverage']['report_types']['earnings']['covered_through'] );
		$this->assertStringContainsString( 'not real zeros', $empty['warnings'][0] );

		$this->importFixture( 'earnings-2026-10.csv' );
		$this->importFixture( 'orders-2026-10.csv' );
		$partial = Amazon::execute( array( 'action' => 'summary', 'start_date' => '2026-10-15', 'end_date' => '2026-11-15' ) );

		$this->assertFalse( $partial['coverage']['complete'] );
		$earnings = $partial['coverage']['windows'][0];
		$this->assertSame( '2026-10-31', $partial['coverage']['report_types']['earnings']['covered_through'] );
		$this->assertSame( array( array( 'start' => '2026-11-01', 'end' => '2026-11-15' ) ), $earnings['gaps'] );
		$this->assertStringContainsString( 'current through 2026-10-31', $partial['warnings'][0] );
	}

	public function test_row_reads_are_bounded_and_filtered(): void {
		$this->importFixture( 'earnings-2026-10.csv' );

		$rows = Amazon::execute( array( 'action' => 'earnings', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'limit' => 2 ) );
		$this->assertSame( 6, $rows['row_count'] );
		$this->assertSame( 2, $rows['results_count'] );
		$this->assertTrue( $rows['provenance']['truncated'] );
		$this->assertSame( '2026-10-30', $rows['results'][0]['date'], 'Newest first.' );
		$this->assertSame( '32.99', $rows['results'][0]['ad_fees'] );

		$tag = Amazon::execute( array( 'action' => 'earnings', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'tracking_id' => 'chubesnet-20' ) );
		$this->assertSame( 2, $tag['row_count'] );
		$this->assertSame( 0, Amazon::execute( array( 'action' => 'orders', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31' ) )['row_count'] );
	}

	public function test_coverage_action_reports_windows_and_tracking_ids(): void {
		$this->importFixture( 'earnings-2026-10.csv' );
		Amazon::execute( array(
			'action'     => 'import',
			'content'    => str_replace( '2026-10-', '2026-11-', $this->fixture( 'earnings-2026-10.csv' ) ),
			'start_date' => '2026-11-01',
			'end_date'   => '2026-11-30',
		) );

		$coverage = Amazon::execute( array( 'action' => 'coverage', 'report_type' => 'earnings' ) );
		$this->assertSame( 'coverage', $coverage['action'] );
		$this->assertCount( 1, $coverage['results'], 'Adjacent windows merge into one interval.' );
		$this->assertSame( '2026-10-01', $coverage['results'][0]['start_date'] );
		$this->assertSame( '2026-11-30', $coverage['results'][0]['end_date'] );
		$this->assertSame( 2, $coverage['results'][0]['imports'] );
		$tracking = $this->byTracking( $coverage['by_tracking_id'] );
		$this->assertSame( '2026-10-03', $tracking['extrachill-20']['first_date'] );
		$this->assertSame( 4, $tracking['chubesnet-20']['rows'], 'October and November rows.' );
	}

	public function test_interval_math(): void {
		$merged = Amazon::mergeIntervals( array( array( '2026-10-16', '2026-10-31' ), array( '2026-10-01', '2026-10-15' ), array( '2026-11-05', '2026-11-10' ) ) );
		$this->assertSame( array( array( 'start' => '2026-10-01', 'end' => '2026-10-31' ), array( 'start' => '2026-11-05', 'end' => '2026-11-10' ) ), $merged );

		$window = Amazon::windowCoverage( $merged, '2026-10-20', '2026-11-30' );
		$this->assertFalse( $window['complete'] );
		$this->assertSame( '2026-10-31', $window['covered_through'] );
		$this->assertSame(
			array( array( 'start' => '2026-11-01', 'end' => '2026-11-04' ), array( 'start' => '2026-11-11', 'end' => '2026-11-30' ) ),
			$window['gaps']
		);
		$this->assertTrue( Amazon::windowCoverage( $merged, '2026-10-05', '2026-10-25' )['complete'] );
		$this->assertNull( Amazon::windowCoverage( $merged, '2026-09-25', '2026-10-25' )['covered_through'] );
	}

	public function test_input_validation(): void {
		$this->assertSame( 'amazon_associates_invalid_action', Amazon::execute( array( 'action' => 'nope' ) )->get_error_code() );
		$this->assertSame( 'amazon_associates_invalid_date', Amazon::execute( array( 'action' => 'summary', 'start_date' => '2026-13-01' ) )->get_error_code() );
		$this->assertSame( 'amazon_associates_no_report', Amazon::execute( array( 'action' => 'import' ) )->get_error_code() );
	}

	public function test_cli_contract(): void {
		$this->assertSame( AmazonAssociatesCommand::class, CommandRegistry::map()['datamachine analytics amazon'] );
		$this->assertSame(
			array(
				'action'      => 'summary',
				'start_date'  => '2026-10-01',
				'end_date'    => '2026-10-31',
				'tracking_id' => 'extrachill-20',
				'limit'       => 5,
				'dry_run'     => true,
			),
			AmazonAssociatesCommand::buildInput( array( 'summary' ), array( 'start-date' => '2026-10-01', 'end-date' => '2026-10-31', 'tracking-id' => 'extrachill-20', 'limit' => '5', 'dry-run' => true, 'format' => 'json' ) )
		);
		$this->assertContains( 'ad_fees', AmazonAssociatesCommand::tableFields( 'summary' ) );
		$rows = AmazonAssociatesCommand::tabularRows( array( 'action' => 'orders', 'results' => array( array( 'asin' => 'B0', 'indirect' => true ) ) ) );
		$this->assertSame( 'yes', $rows[0]['indirect'] );
	}
}
