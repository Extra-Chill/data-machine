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
			'provenance' => array( 'source' => array( 'operation' => 'DevicesMetricsSummaryQuery' ) ),
		);

		$this->assertSame( $result, json_decode( MediavineCommand::jsonOutput( $result ), true ) );
		$this->assertIsInt( json_decode( MediavineCommand::jsonOutput( $result ), true )['results'][0]['pageviews'] );
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
