<?php
/**
 * Revenue ledger primitive contract tests.
 *
 * @package DataMachineBusiness\Tests\Unit\Abilities\Revenue
 */

namespace DataMachineBusiness\Tests\Unit\Abilities\Revenue;

use DataMachineBusiness\Abilities\Analytics\MediavineReportsAbilities;
use DataMachineBusiness\Abilities\Analytics\StripeReportsAbilities;
use DataMachineBusiness\Abilities\Revenue\RevenueDecimal;
use DataMachineBusiness\Abilities\Revenue\RevenueLedger;
use DataMachineBusiness\Abilities\Revenue\RevenueLedgerAbilities;
use WP_UnitTestCase;

class RevenueLedgerAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		RevenueLedger::create_table();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', RevenueLedger::table_name() ) );
	}

	private function ingest( array $input ): array {
		$result = RevenueLedgerAbilities::ingest( $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	private function storedCount(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', RevenueLedger::table_name() ) );
	}

	private function window( array $rows, array $extra = array() ): array {
		return array_merge(
			array(
				'source'     => 'adnet',
				'account'    => 'site-1',
				'start_date' => '2026-06-01',
				'end_date'   => '2026-06-30',
				'rows'       => $rows,
			),
			$extra
		);
	}

	public function test_reingesting_a_window_replaces_rows_instead_of_duplicating(): void {
		$rows = array(
			array( 'kind' => 'ad_revenue', 'amount' => '10.50', 'currency' => 'usd', 'url' => '/a/' ),
			array( 'kind' => 'ad_revenue', 'amount' => '4.25', 'currency' => 'USD', 'url' => '/b/' ),
			array( 'kind' => 'ad_revenue', 'amount' => '0.000001', 'currency' => 'USD', 'url' => '/c/' ),
		);

		$first = $this->ingest( $this->window( $rows ) );
		$this->assertSame( 0, $first['replaced'] );
		$this->assertSame( 3, $first['inserted'] );
		$this->assertSame( 'month', $first['provenance']['grain'] );
		$this->assertSame( 3, $this->storedCount() );

		$second = $this->ingest( $this->window( $rows ) );
		$this->assertSame( 3, $second['replaced'] );
		$this->assertSame( 3, $second['inserted'] );
		$this->assertSame( 3, $this->storedCount() );

		$query = RevenueLedgerAbilities::query( array( 'start_date' => '2026-06-01', 'end_date' => '2026-06-30' ) );
		$this->assertSame( '14.750001', $query['totals'][0]['amount'] );
		$this->assertSame( 3, $query['totals'][0]['rows'] );
	}

	public function test_dry_run_reports_without_writing(): void {
		$this->ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '1.00', 'currency' => 'USD' ) ) ) );

		$dry = $this->ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '2.00', 'currency' => 'USD' ), array( 'kind' => 'ad_revenue', 'amount' => '3.00', 'currency' => 'USD' ) ), array( 'dry_run' => true ) ) );

		$this->assertTrue( $dry['dry_run'] );
		$this->assertSame( 1, $dry['replaced'] );
		$this->assertSame( 2, $dry['inserted'] );
		$this->assertSame( '5.00', $dry['totals'][0]['amount'] );
		$this->assertSame( 1, $this->storedCount() );
	}

	public function test_replace_scope_is_source_account_kind_and_window(): void {
		$this->ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '1', 'currency' => 'USD' ) ) ) );
		$this->ingest( $this->window( array( array( 'kind' => 'commission', 'amount' => '2', 'currency' => 'USD' ) ) ) );
		$this->ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '3', 'currency' => 'USD' ) ), array( 'account' => 'site-2' ) ) );
		$this->ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '4', 'currency' => 'USD' ) ), array( 'start_date' => '2026-07-01', 'end_date' => '2026-07-31' ) ) );
		$this->assertSame( 4, $this->storedCount() );

		// Explicit kinds clear a kind that now has no rows, without touching others.
		$cleared = $this->ingest( $this->window( array(), array( 'kinds' => array( 'commission' ) ) ) );
		$this->assertSame( 1, $cleared['replaced'] );
		$this->assertSame( 3, $this->storedCount() );
	}

	public function test_partially_overlapping_stored_rows_block_replacement(): void {
		$this->ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '30', 'currency' => 'USD' ) ) ) );

		$conflict = RevenueLedgerAbilities::ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '1', 'currency' => 'USD' ) ), array( 'start_date' => '2026-06-10', 'end_date' => '2026-06-10' ) ) );

		$this->assertWPError( $conflict );
		$this->assertSame( 'revenue_window_conflict', $conflict->get_error_code() );
		$this->assertSame( 1, $this->storedCount() );
	}

	public function test_invalid_rows_are_rejected_before_any_write(): void {
		$outside = RevenueLedgerAbilities::ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '1', 'currency' => 'USD', 'period_start' => '2026-07-01', 'period_end' => '2026-07-01' ) ) ) );
		$this->assertSame( 'revenue_row_outside_window', $outside->get_error_code() );

		$precision = RevenueLedgerAbilities::ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '1.0000001', 'currency' => 'USD' ) ) ) );
		$this->assertSame( 'revenue_invalid_amount', $precision->get_error_code() );

		$currency = RevenueLedgerAbilities::ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '1', 'currency' => 'dollars' ) ) ) );
		$this->assertSame( 'revenue_invalid_currency', $currency->get_error_code() );

		$this->assertSame( 0, $this->storedCount() );
	}

	public function test_query_totals_are_per_source_kind_and_currency_without_conversion(): void {
		$this->ingest( $this->window( array(
			array( 'kind' => 'ad_revenue', 'amount' => '0.10', 'currency' => 'USD' ),
			array( 'kind' => 'ad_revenue', 'amount' => '0.20', 'currency' => 'USD' ),
		) ) );
		$this->ingest( array(
			'source'     => 'payments',
			'account'    => 'acct',
			'start_date' => '2026-06-01',
			'end_date'   => '2026-06-30',
			'rows'       => array(
				array( 'kind' => 'charge', 'amount' => '100.00', 'currency' => 'EUR', 'period_start' => '2026-06-02', 'period_end' => '2026-06-02' ),
				array( 'kind' => 'charge', 'amount' => '50.00', 'currency' => 'USD', 'period_start' => '2026-06-03', 'period_end' => '2026-06-03' ),
				array( 'kind' => 'refund', 'amount' => '-20.00', 'currency' => 'EUR', 'period_start' => '2026-06-04', 'period_end' => '2026-06-04' ),
				array( 'kind' => 'charge', 'amount' => 1500, 'currency' => 'JPY', 'period_start' => '2026-06-05', 'period_end' => '2026-06-05' ),
			),
		) );

		$query = RevenueLedgerAbilities::query( array( 'start_date' => '2026-06-01', 'end_date' => '2026-06-30' ) );

		$totals = array();
		foreach ( $query['totals'] as $total ) {
			$totals[ $total['source'] . '|' . $total['kind'] . '|' . $total['currency'] ] = $total['amount'];
		}
		$this->assertSame(
			array(
				'adnet|ad_revenue|USD' => '0.30',
				'payments|charge|EUR'  => '100.00',
				'payments|charge|JPY'  => '1500.00',
				'payments|charge|USD'  => '50.00',
				'payments|refund|EUR'  => '-20.00',
			),
			$totals
		);
		$this->assertSame(
			array(
				array( 'currency' => 'EUR', 'amount' => '80.00', 'rows' => 2 ),
				array( 'currency' => 'JPY', 'amount' => '1500.00', 'rows' => 1 ),
				array( 'currency' => 'USD', 'amount' => '50.30', 'rows' => 3 ),
			),
			$query['currency_totals']
		);

		$eur = RevenueLedgerAbilities::query( array( 'currency' => 'eur', 'source' => 'payments' ) );
		$this->assertCount( 1, $eur['currency_totals'] );
		$this->assertSame( '80.00', $eur['currency_totals'][0]['amount'] );
	}

	public function test_query_offset_pages_rows_without_changing_totals(): void {
		$rows = array();
		for ( $day = 1; $day <= 5; $day++ ) {
			$date   = sprintf( '2026-06-%02d', $day );
			$rows[] = array( 'kind' => 'ad_revenue', 'amount' => (string) $day, 'currency' => 'USD', 'period_start' => $date, 'period_end' => $date );
		}
		$this->ingest( $this->window( $rows ) );

		$base  = array( 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'include_rows' => true, 'limit' => 2 );
		$first = RevenueLedgerAbilities::query( $base );
		$this->assertSame( 0, $first['counts']['offset'] );
		$this->assertSame( 2, $first['counts']['returned_rows'] );

		$pages = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$page = RevenueLedgerAbilities::query( array_merge( $base, array( 'offset' => $offset ) ) );
			$this->assertSame( $offset, $page['counts']['offset'] );
			$this->assertSame( 5, $page['counts']['matched_rows'] );
			$this->assertSame( min( 2, 5 - $offset ), $page['counts']['returned_rows'] );
			$this->assertCount( $page['counts']['returned_rows'], $page['rows'] );
			$this->assertSame( $first['totals'], $page['totals'] );
			$this->assertSame( $first['currency_totals'], $page['currency_totals'] );
			$this->assertSame( $first['coverage'], $page['coverage'] );
			$this->assertSame( $first['provenance'], $page['provenance'] );
			foreach ( $page['rows'] as $row ) {
				$pages[] = $row['period_start'];
			}
		}
		$this->assertSame( array( '2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05' ), $pages );

		$beyond = RevenueLedgerAbilities::query( array_merge( $base, array( 'offset' => 10 ) ) );
		$this->assertSame( 0, $beyond['counts']['returned_rows'] );
		$this->assertSame( array(), $beyond['rows'] );
		$this->assertSame( '15.00', $beyond['currency_totals'][0]['amount'] );

		$negative = RevenueLedgerAbilities::query( array_merge( $base, array( 'offset' => -3 ) ) );
		$this->assertSame( 0, $negative['counts']['offset'] );
		$this->assertSame( '2026-06-01', $negative['rows'][0]['period_start'] );

		$no_rows = RevenueLedgerAbilities::query( array( 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'offset' => 2 ) );
		$this->assertSame( 0, $no_rows['counts']['returned_rows'] );
		$this->assertArrayNotHasKey( 'rows', $no_rows );
	}

	public function test_query_excludes_partially_overlapping_periods_from_totals(): void {
		$this->ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '30', 'currency' => 'USD' ) ) ) );

		$query = RevenueLedgerAbilities::query( array( 'start_date' => '2026-06-15', 'end_date' => '2026-07-15' ) );

		$this->assertSame( array(), $query['totals'] );
		$this->assertSame( 1, $query['counts']['partial_overlap_rows'] );
		$this->assertSame( array( array( 'start_date' => '2026-06-15', 'end_date' => '2026-06-30' ) ), $query['coverage'][0]['covered'] );
		$this->assertSame( array( array( 'start_date' => '2026-07-01', 'end_date' => '2026-07-15' ) ), $query['coverage'][0]['gaps'] );
	}

	public function test_coverage_reports_gaps_from_stored_periods(): void {
		$day = static fn( string $date, string $amount ) => array( 'kind' => 'charge', 'amount' => $amount, 'currency' => 'USD', 'period_start' => $date, 'period_end' => $date );
		$this->ingest( array(
			'source'     => 'payments',
			'account'    => 'acct',
			'start_date' => '2026-06-01',
			'end_date'   => '2026-06-10',
			'rows'       => array( $day( '2026-06-01', '1' ), $day( '2026-06-02', '1' ), $day( '2026-06-03', '1' ), $day( '2026-06-06', '1' ), $day( '2026-06-07', '1' ), $day( '2026-06-07', '2' ) ),
		) );
		$this->ingest( $this->window( array( array( 'kind' => 'ad_revenue', 'amount' => '9', 'currency' => 'USD' ) ) ) );

		$query = RevenueLedgerAbilities::query( array( 'start_date' => '2026-06-01', 'end_date' => '2026-06-12' ) );
		$this->assertCount( 2, $query['coverage'] );

		$payments = $query['coverage'][1];
		$this->assertSame( 'payments', $payments['source'] );
		$this->assertSame( array( 'charge' ), $payments['kinds'] );
		$this->assertSame( array( 'day' ), $payments['grains'] );
		$this->assertSame(
			array(
				array( 'start_date' => '2026-06-01', 'end_date' => '2026-06-03' ),
				array( 'start_date' => '2026-06-06', 'end_date' => '2026-06-07' ),
			),
			$payments['covered']
		);
		$this->assertSame(
			array(
				array( 'start_date' => '2026-06-04', 'end_date' => '2026-06-05' ),
				array( 'start_date' => '2026-06-08', 'end_date' => '2026-06-12' ),
			),
			$payments['gaps']
		);
		$this->assertFalse( $payments['complete'] );

		// The monthly row covers the whole query window (clipped), but is excluded from totals.
		$adnet = $query['coverage'][0];
		$this->assertSame( 'adnet', $adnet['source'] );
		$this->assertTrue( $adnet['complete'] );

		// Unbounded coverage reports gaps between first and last stored day.
		$unbounded = RevenueLedgerAbilities::query( array( 'source' => 'payments' ) );
		$this->assertSame( array( array( 'start_date' => '2026-06-04', 'end_date' => '2026-06-05' ) ), $unbounded['coverage'][0]['gaps'] );
	}

	public function test_metrics_and_attribution_round_trip(): void {
		$metrics = array(
			'views'       => 1250,
			'rpm'         => 15.0,
			'viewability' => 0.72,
			'nested'      => array( 'clicks' => 3, 'label' => 'ünïcode/path' ),
		);
		$this->ingest( $this->window( array(
			array(
				'kind'       => 'commission',
				'amount'     => '12.345678',
				'currency'   => 'GBP',
				'url'        => 'https://example.com/a/b/',
				'object_ref' => 'subid-42',
				'blog_id'    => 7,
				'metrics'    => $metrics,
			),
		), array( 'import_batch' => 'batch-1' ) ) );

		$query = RevenueLedgerAbilities::query( array( 'include_rows' => true ) );
		$row   = $query['rows'][0];

		$this->assertSame( $metrics, $row['metrics'] );
		$this->assertIsFloat( $row['metrics']['rpm'] );
		$this->assertSame( '12.345678', $row['amount'] );
		$this->assertSame( 'GBP', $row['currency'] );
		$this->assertSame( 'https://example.com/a/b/', $row['url'] );
		$this->assertSame( 'subid-42', $row['object_ref'] );
		$this->assertSame( 7, $row['blog_id'] );
		$this->assertSame( 'batch-1', $row['import_batch'] );
		$this->assertSame( '2026-06-01', $row['period_start'] );
		$this->assertSame( '2026-06-30', $row['period_end'] );
		$this->assertSame( 'month', $row['grain'] );
	}

	public function test_mediavine_pages_normalize_into_ledger_rows(): void {
		$parsed = MediavineReportsAbilities::parsePagesResponse(
			array(
				'data' => array(
					'pagesSummary' => array(
						'pages' => array(
							array( 'path' => '/one/', 'pageviews' => 1000, 'pageRevenue' => 12.5, 'rpm' => 12.5, 'cpm' => 2.0, 'viewability' => 0.7, 'fillrate' => 0.9, 'impressionsPerPageView' => 4.0 ),
							array( 'path' => '/two/', 'pageviews' => 10, 'pageRevenue' => 0.123456, 'rpm' => 12.3, 'cpm' => 1.0, 'viewability' => 0.5, 'fillrate' => 0.8, 'impressionsPerPageView' => 3.0 ),
						),
					),
				),
			),
			'2026-06'
		);
		$rows = MediavineReportsAbilities::ledgerRowsFromPages( $parsed );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'ad_revenue', $rows[0]['kind'] );
		$this->assertSame( '12.500000', $rows[0]['amount'] );
		$this->assertSame( 'USD', $rows[0]['currency'] );
		$this->assertSame( '/one/', $rows[0]['url'] );
		$this->assertSame( 1000, $rows[0]['metrics']['views'] );
		$this->assertArrayNotHasKey( 'revenue', $rows[0]['metrics'] );

		$input = array(
			'source'     => 'mediavine',
			'account'    => '1234',
			'start_date' => '2026-06-01',
			'end_date'   => '2026-06-30',
			'kinds'      => array( 'ad_revenue' ),
			'rows'       => $rows,
		);
		$this->ingest( $input );
		$again = $this->ingest( $input );
		$this->assertSame( 2, $again['replaced'] );
		$this->assertSame( 2, $this->storedCount() );

		$query = RevenueLedgerAbilities::query( array( 'source' => 'mediavine', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'include_rows' => true ) );
		$this->assertSame( '12.623456', $query['totals'][0]['amount'] );
		$this->assertSame( 12.5, $query['rows'][0]['metrics']['rpm'] );
		$this->assertTrue( $query['coverage'][0]['complete'] );
	}

	public function test_stripe_charges_and_payouts_normalize_into_ledger_rows(): void {
		$charges = array(
			array( 'id' => 'ch_1', 'status' => 'succeeded', 'paid' => true, 'amount' => 2500, 'amount_captured' => 2500, 'amount_refunded' => 500, 'refunded' => false, 'currency' => 'usd', 'created' => gmmktime( 12, 0, 0, 6, 2, 2026 ) ),
			array( 'id' => 'ch_2', 'status' => 'succeeded', 'paid' => true, 'amount' => 1200, 'amount_captured' => 1200, 'amount_refunded' => 0, 'currency' => 'jpy', 'created' => gmmktime( 0, 0, 1, 6, 3, 2026 ) ),
			array( 'id' => 'ch_3', 'status' => 'failed', 'paid' => false, 'amount' => 999, 'currency' => 'usd', 'created' => gmmktime( 0, 0, 1, 6, 3, 2026 ) ),
		);
		$rows = StripeReportsAbilities::ledgerRowsFromCharges( $charges );

		$this->assertCount( 3, $rows );
		$this->assertSame( array( 'charge', 'refund', 'charge' ), array_column( $rows, 'kind' ) );
		$this->assertSame( array( '25.00', '-5.00', '1200' ), array_column( $rows, 'amount' ) );
		$this->assertSame( array( 'USD', 'USD', 'JPY' ), array_column( $rows, 'currency' ) );
		$this->assertSame( '2026-06-02', $rows[0]['period_start'] );
		$this->assertSame( 'ch_1', $rows[1]['object_ref'] );

		$payouts = StripeReportsAbilities::ledgerRowsFromPayouts( array(
			array( 'id' => 'po_1', 'status' => 'paid', 'amount' => 10000, 'currency' => 'eur', 'arrival_date' => gmmktime( 0, 0, 0, 6, 5, 2026 ) ),
			array( 'id' => 'po_2', 'status' => 'failed', 'amount' => 500, 'currency' => 'eur', 'arrival_date' => gmmktime( 0, 0, 0, 6, 6, 2026 ) ),
		) );
		$this->assertCount( 1, $payouts );
		$this->assertSame( '100.00', $payouts[0]['amount'] );
		$this->assertSame( 'paid', $payouts[0]['metrics']['status'] );

		$input = array(
			'source'     => 'stripe',
			'account'    => 'test',
			'start_date' => '2026-06-01',
			'end_date'   => '2026-06-07',
			'kinds'      => array( 'charge', 'refund' ),
			'rows'       => $rows,
		);
		$this->ingest( $input );
		$this->ingest( $input );
		$this->assertSame( 3, $this->storedCount() );

		$query  = RevenueLedgerAbilities::query( array( 'source' => 'stripe' ) );
		$totals = array_column( $query['currency_totals'], 'amount', 'currency' );
		$this->assertSame( array( 'JPY' => '1200.00', 'USD' => '20.00' ), $totals );
	}

	public function test_decimal_strings_are_exact(): void {
		$this->assertSame( 300000, RevenueDecimal::to_micros( '0.3' ) );
		$this->assertSame( -1, RevenueDecimal::to_micros( '-0.000001' ) );
		$this->assertNull( RevenueDecimal::to_micros( '1e5' ) );
		$this->assertNull( RevenueDecimal::to_micros( '1,000.00' ) );
		$this->assertSame( '0.30', RevenueDecimal::from_micros( 300000 ) );
		$this->assertSame( '-0.000001', RevenueDecimal::from_micros( -1 ) );
		$this->assertSame( '12.340000', RevenueDecimal::to_storage( 12340000 ) );
		$this->assertSame( '-0.050000', RevenueDecimal::to_storage( -50000 ) );
		$this->assertSame( 'month', RevenueLedgerAbilities::deriveGrain( '2026-02-01', '2026-02-28' ) );
		$this->assertSame( 'day', RevenueLedgerAbilities::deriveGrain( '2026-02-01', '2026-02-01' ) );
		$this->assertSame( 'range', RevenueLedgerAbilities::deriveGrain( '2026-02-01', '2026-02-27' ) );
	}
}
