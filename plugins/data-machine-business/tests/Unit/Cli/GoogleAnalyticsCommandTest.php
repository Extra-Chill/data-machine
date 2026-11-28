<?php
/**
 * Google Analytics CLI input-building tests (#4074).
 *
 * @package DataMachineBusiness\Tests\Unit\Cli
 */

namespace DataMachineBusiness\Tests\Unit\Cli;

use DataMachineBusiness\Abilities\Analytics\GoogleAnalyticsAbilities;
use DataMachineBusiness\Cli\GoogleAnalyticsCommand;
use WP_UnitTestCase;

class GoogleAnalyticsCommandTest extends WP_UnitTestCase {

	private const BASE = array(
		'dimensions' => '["country"]',
		'metrics'    => '["sessions"]',
	);

	public function test_aggregate_translates_start_end_and_hostname_into_valid_input(): void {
		$input = GoogleAnalyticsCommand::buildInput(
			array( 'aggregate_report' ),
			self::BASE + array(
				'hostname'   => 'docs.extrachill.com',
				'start-date' => '2026-08-30',
				'end-date'   => '2026-11-27',
			)
		);

		$this->assertIsArray( $input );
		$this->assertSame( array( 'start_date' => '2026-08-30', 'end_date' => '2026-11-27' ), $input['date_range'] );
		$this->assertSame(
			array( array( 'field_name' => 'hostName', 'match_type' => 'EXACT', 'value' => 'docs.extrachill.com' ) ),
			$input['filters']
		);
		$this->assertArrayNotHasKey( 'start_date', $input );
		$this->assertArrayNotHasKey( 'hostname', $input );
		$this->assertValidAgainstAbilitySchema( $input );
	}

	public function test_aggregate_hostname_appends_to_existing_filters(): void {
		$input = GoogleAnalyticsCommand::buildInput(
			array( 'aggregate_report' ),
			self::BASE + array(
				'date-range' => '{"start_date":"2026-09-01","end_date":"2026-09-30"}',
				'filters'    => '[{"field_name":"sessionSource","match_type":"CONTAINS","value":"chatgpt"}]',
				'hostname'   => 'events.extrachill.com',
				'limit'      => '10',
			)
		);

		$this->assertIsArray( $input );
		$this->assertCount( 2, $input['filters'] );
		$this->assertSame( 'sessionSource', $input['filters'][0]['field_name'] );
		$this->assertSame( 'hostName', $input['filters'][1]['field_name'] );
		$this->assertSame( 10, $input['limit'] );
		$this->assertValidAgainstAbilitySchema( $input );
	}

	public function test_aggregate_rejects_both_date_forms(): void {
		$input = GoogleAnalyticsCommand::buildInput(
			array( 'aggregate_report' ),
			self::BASE + array(
				'date-range' => '{"start_date":"2026-09-01","end_date":"2026-09-30"}',
				'start-date' => '2026-09-01',
				'end-date'   => '2026-09-30',
			)
		);

		$this->assertIsString( $input );
		$this->assertStringContainsString( 'not both', $input );
	}

	public function test_aggregate_rejects_half_date_range(): void {
		$input = GoogleAnalyticsCommand::buildInput( array( 'aggregate_report' ), self::BASE + array( 'start-date' => '2026-09-01' ) );

		$this->assertIsString( $input );
		$this->assertStringContainsString( '--end-date', $input );
	}

	/**
	 * @dataProvider unsupported_flags
	 */
	public function test_aggregate_rejects_legacy_only_flags_with_equivalent( string $flag, $value, string $equivalent ): void {
		$input = GoogleAnalyticsCommand::buildInput(
			array( 'aggregate_report' ),
			self::BASE + array(
				'date-range' => '{"start_date":"2026-09-01","end_date":"2026-09-30"}',
				$flag        => $value,
			)
		);

		$this->assertIsString( $input );
		$this->assertStringContainsString( '--' . $flag, $input );
		$this->assertStringContainsString( $equivalent, $input );
	}

	public function unsupported_flags(): array {
		return array(
			'page-filter' => array( 'page-filter', '/blog/', '--filters' ),
			'sort-by'     => array( 'sort-by', 'sessions', '--order-by' ),
			'order'       => array( 'order', 'asc', '--order-by' ),
			'compare'     => array( 'compare', true, '--comparison-date-range' ),
		);
	}

	public function test_invalid_json_flag_is_an_error(): void {
		$input = GoogleAnalyticsCommand::buildInput( array( 'aggregate_report' ), array( 'metrics' => '["sessions"' ) );

		$this->assertIsString( $input );
		$this->assertStringContainsString( '--metrics', $input );
	}

	public function test_legacy_actions_pass_flags_through_unchanged(): void {
		$input = GoogleAnalyticsCommand::buildInput(
			array( 'page_stats' ),
			array(
				'start-date'  => '2026-09-01',
				'end-date'    => '2026-09-30',
				'hostname'    => 'docs.extrachill.com',
				'page-filter' => '/blog/',
				'sort-by'     => 'bounceRate',
				'order'       => 'asc',
				'limit'       => '10',
				'compare'     => true,
			)
		);

		$this->assertSame(
			array(
				'action'      => 'page_stats',
				'start_date'  => '2026-09-01',
				'end_date'    => '2026-09-30',
				'limit'       => '10',
				'page_filter' => '/blog/',
				'hostname'    => 'docs.extrachill.com',
				'sort_by'     => 'bounceRate',
				'order'       => 'asc',
				'compare'     => true,
			),
			$input
		);
	}

	private function assertValidAgainstAbilitySchema( array $input ): void {
		$result = rest_validate_value_from_schema( $input, GoogleAnalyticsAbilities::inputSchema(), 'input' );
		$this->assertNotWPError( $result );
	}
}
