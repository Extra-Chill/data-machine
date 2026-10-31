<?php
/**
 * Google Analytics input and response-shape guards.
 *
 * Regressions for #147 (multi-dimension totals rows) and #148 (aggregate-only
 * keys silently ignored by fixed actions). Kept separate from
 * GoogleAnalyticsAbilitiesTest, which is at the file-size audit threshold.
 *
 * @package DataMachine\Tests\Unit\Abilities\Analytics
 */

namespace DataMachine\Tests\Unit\Abilities\Analytics;

use DataMachineBusiness\Abilities\Analytics\GoogleAnalyticsAbilities;
use WP_UnitTestCase;

class GoogleAnalyticsInputGuardsTest extends WP_UnitTestCase {

	/**
	 * Regression for #148: fixed actions ignore aggregate-only keys, so a
	 * filtered landing_page_acquisition call returned site-wide data that read
	 * as filtered. They are rejected before any API call, naming the action to
	 * use; empty values (model-filled defaults) and consumer context keys still
	 * pass through.
	 */
	public function test_fixed_actions_reject_aggregate_only_keys(): void {
		$result = GoogleAnalyticsAbilities::fetchStats(
			array(
				'action'  => 'landing_page_acquisition',
				'filters' => array(
					array(
						'field_name' => 'sessionSource',
						'match_type' => 'CONTAINS',
						'value'      => 'chatgpt',
					),
				),
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'filters only applies to action aggregate_report', $result['error'] );
		$this->assertStringContainsString( '"landing_page_acquisition"', $result['error'] );

		$multiple = GoogleAnalyticsAbilities::fetchStats(
			array(
				'action'     => 'page_stats',
				'dimensions' => array( 'landingPage' ),
				'metrics'    => array( 'sessions' ),
			)
		);
		$this->assertStringContainsString( 'dimensions, metrics only apply to action aggregate_report', $multiple['error'] );

		// An unknown action fails at action validation, before auth or HTTP, so
		// this proves empty aggregate keys and consumer context keys pass the
		// guard without touching the network.
		$empty = GoogleAnalyticsAbilities::fetchStats(
			array(
				'action'           => 'not_a_real_action',
				'filters'          => array(),
				'consumer_context' => 'preserved',
			)
		);
		$this->assertStringStartsWith( 'Invalid action.', $empty['error'], 'Empty aggregate keys and consumer context keys must not trip the guard.' );
	}

	/**
	 * Regression for #147: GA4 echoes one RESERVED_TOTAL per requested
	 * dimension on the totals row. Captured live: dimensions
	 * ["hostName","landingPage"] returned two RESERVED_TOTAL entries, which the
	 * single-entry check rejected, so every multi-dimension report failed.
	 */
	public function test_aggregate_normalizes_multi_dimension_reserved_total_row(): void {
		$report = $this->two_dimension_report( array( 'RESERVED_TOTAL', 'RESERVED_TOTAL' ) );

		$normalized = GoogleAnalyticsAbilities::normalizeAggregateReport(
			$report,
			array( 'start_date' => '2026-09-01', 'end_date' => '2026-09-30' ),
			array( 'hostName', 'landingPage' ),
			array( 'sessions' )
		);

		$this->assertNotWPError( $normalized );
		$this->assertSame( '147738', $normalized['totals']['sessions'] );
		$this->assertSame( 'events.extrachill.com', $normalized['rows'][0]['dimensions']['hostName'] );
		$this->assertSame( '/events/feed-me', $normalized['rows'][0]['dimensions']['landingPage'] );
	}

	/**
	 * The reserved placeholder count must match the dimension count; a totals
	 * row with too few placeholders, or a real value among them, is still a
	 * malformation (#147).
	 */
	public function test_aggregate_rejects_multi_dimension_totals_with_wrong_placeholders(): void {
		foreach ( array( array( 'RESERVED_TOTAL' ), array( 'RESERVED_TOTAL', 'events.extrachill.com' ), array( 'RESERVED_TOTAL', 'RESERVED_TOTAL', 'RESERVED_TOTAL' ) ) as $values ) {
			$result = GoogleAnalyticsAbilities::normalizeAggregateReport(
				$this->two_dimension_report( $values ),
				array( 'start_date' => '2026-09-01', 'end_date' => '2026-09-30' ),
				array( 'hostName', 'landingPage' ),
				array( 'sessions' )
			);

			$this->assertWPError( $result, wp_json_encode( $values ) );
			$this->assertStringContainsString( '(totals_shape)', $result->get_error_message() );
		}
	}

	/**
	 * Build a two-dimension runReport payload with the given totals placeholders.
	 *
	 * @param string[] $totals_dimension_values Totals-row dimension values.
	 * @return array
	 */
	private function two_dimension_report( array $totals_dimension_values ): array {
		return array(
			'kind'             => GoogleAnalyticsAbilities::AGGREGATE_REPORT_RESPONSE_KIND,
			'dimensionHeaders' => array( array( 'name' => 'hostName' ), array( 'name' => 'landingPage' ) ),
			'metricHeaders'    => array( array( 'name' => 'sessions', 'type' => 'TYPE_INTEGER' ) ),
			'rows'             => array(
				array(
					'dimensionValues' => array( array( 'value' => 'events.extrachill.com' ), array( 'value' => '/events/feed-me' ) ),
					'metricValues'    => array( array( 'value' => '14' ) ),
				),
			),
			'totals'           => array(
				array(
					'dimensionValues' => array_map( static fn( string $value ): array => array( 'value' => $value ), $totals_dimension_values ),
					'metricValues'    => array( array( 'value' => '147738' ) ),
				),
			),
			'rowCount'         => 1,
			'metadata'         => array(
				'currencyCode' => 'USD',
				'timeZone'     => 'America/New_York',
			),
		);
	}
}
