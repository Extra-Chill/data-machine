<?php
/**
 * Mediavine CLI output contract tests.
 *
 * @package DataMachineBusiness\Tests\Unit\Cli
 */

namespace DataMachineBusiness\Tests\Unit\Cli;

use DataMachineBusiness\Cli\MediavineCommand;
use WP_UnitTestCase;

class MediavineCommandTest extends WP_UnitTestCase {

	public function test_json_output_preserves_complete_typed_ability_envelope(): void {
		$result = array(
			'success' => true,
			'action' => 'devices',
			'site_id' => 'relay-id',
			'date_range' => array( 'start_date' => '2026-07-10', 'end_date' => '2026-07-16' ),
			'results_count' => 1,
			'results' => array( array( 'label' => 'Mobile', 'pageviews' => 1200, 'revenue' => 15.0, 'period' => '2026-07' ) ),
			'diagnostics' => array(
				'status' => 'warning',
				'warning_count' => 1,
				'warnings' => array( array( 'code' => 'monetizable_pageviews_exceed_pageviews' ) ),
			),
			'provenance' => array( 'source' => array( 'operation' => 'DevicesMetricsSummaryQuery' ) ),
		);

		$this->assertSame( $result, json_decode( MediavineCommand::jsonOutput( $result ), true ) );
		$this->assertIsInt( json_decode( MediavineCommand::jsonOutput( $result ), true )['results'][0]['pageviews'] );
	}

	public function test_integrity_warning_messages_surface_row_and_observed_values(): void {
		$result = array(
			'diagnostics' => array(
				'warnings' => array(
					array(
						'row' => array( 'index' => 3, 'dimension' => 'label', 'value' => 'other' ),
						'observed' => array(
							'subset_field' => 'monetizablePageviews',
							'subset_value' => 18211,
							'total_field' => 'pageviews',
							'total_value' => 6,
						),
					),
				),
			),
		);

		$messages = MediavineCommand::integrityWarningMessages( $result );

		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'row 3 (label=other)', $messages[0] );
		$this->assertStringContainsString( 'monetizablePageviews=18211 exceeds pageviews=6', $messages[0] );
		$this->assertStringContainsString( 'Upstream bucket semantics are unknown', $messages[0] );
		$this->assertSame( array(), MediavineCommand::integrityWarningMessages( array() ) );
	}

	public function test_table_and_csv_rows_use_stable_action_specific_columns(): void {
		$result = array(
			'action' => 'ad_units',
			'results' => array(
				array( 'adunit' => 'Content', 'grain' => 'parent', 'deviceType' => null, 'revenue' => 25.0, 'period' => '2026-07' ),
			),
		);

		$rows = MediavineCommand::tabularRows( $result );
		$this->assertSame( MediavineCommand::tableFields( 'ad_units' ), array_keys( $rows[0] ) );
		$this->assertSame( 'parent', $rows[0]['grain'] );
		$this->assertNull( $rows[0]['deviceType'] );
		$this->assertSame( 25.0, $rows[0]['revenue'] );
	}

	public function test_cli_exposes_only_bounded_dimensional_actions(): void {
		$this->assertSame( array( 'devices', 'countries', 'sources', 'ad_units' ), array_keys( MediavineCommand::actions() ) );
	}
}
