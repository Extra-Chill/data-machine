<?php
/**
 * Unit tests for GoogleAnalyticsAbilities request body construction.
 *
 * Covers the bug where --page-filter (page_filter input) was silently dropped
 * for any GA4 action whose dimensions did not include pagePath/landingPage —
 * meaning date_stats, traffic_sources, top_events, user_demographics, and
 * new_vs_returning all returned site-wide data even when the caller asked for
 * a specific page. Fix is to always emit a pagePath CONTAINS dimensionFilter
 * when page_filter is provided.
 *
 * @package DataMachine\Tests\Unit\Abilities\Analytics
 */

namespace DataMachine\Tests\Unit\Abilities\Analytics;

use DataMachineBusiness\Abilities\Analytics\GoogleAnalyticsAbilities;
use DataMachineBusiness\Cli\GoogleAnalyticsCommand;
use DataMachineBusiness\Engine\AI\Tools\Global\GoogleAnalytics;
use WP_UnitTestCase;

class GoogleAnalyticsAbilitiesTest extends WP_UnitTestCase {

	/**
	 * page_filter must produce a dimensionFilter for every action, including
	 * actions whose dimensions don't include pagePath/landingPage.
	 *
	 * @dataProvider all_filterable_actions
	 */
	public function test_page_filter_applied_to_all_actions( string $action ): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'page_filter' => '/the-history-of-extra-chill',
				'start_date'  => '2026-01-01',
				'end_date'    => '2026-01-31',
			),
			$action
		);

		$this->assertArrayHasKey(
			'dimensionFilter',
			$body,
			"Action '{$action}' must apply page_filter as a dimensionFilter"
		);

		$filter = $body['dimensionFilter']['filter'] ?? null;
		$this->assertNotNull(
			$filter,
			"Action '{$action}' must produce a single (not nested) filter when only page_filter is set"
		);
		$this->assertSame( 'CONTAINS', $filter['stringFilter']['matchType'] );
		$this->assertSame( '/the-history-of-extra-chill', $filter['stringFilter']['value'] );
	}

	/**
	 * Actions that group by pagePath (page_stats, engagement) filter on pagePath.
	 *
	 * @dataProvider page_path_grouped_actions
	 */
	public function test_page_filter_uses_pagepath_when_grouped_by_pagepath( string $action ): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'page_filter' => '/about/',
				'start_date'  => '2026-01-01',
				'end_date'    => '2026-01-31',
			),
			$action
		);

		$this->assertSame( 'pagePath', $body['dimensionFilter']['filter']['fieldName'] );
	}

	/**
	 * landing_pages action filters on landingPage so the filter matches the
	 * dimension actually being returned in the response.
	 */
	public function test_page_filter_uses_landingpage_for_landing_pages_action(): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'page_filter' => '/about/',
				'start_date'  => '2026-01-01',
				'end_date'    => '2026-01-31',
			),
			'landing_pages'
		);

		$this->assertSame( 'landingPage', $body['dimensionFilter']['filter']['fieldName'] );
	}

	/**
	 * Actions without pagePath/landingPage in their dimensions still filter on
	 * pagePath (the regression case). GA4 supports pagePath as a filter-only
	 * dimension, so the request scopes to hits matching that path.
	 *
	 * @dataProvider non_page_grouped_actions
	 */
	public function test_page_filter_uses_pagepath_for_non_page_grouped_actions( string $action ): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'page_filter' => '/about/',
				'start_date'  => '2026-01-01',
				'end_date'    => '2026-01-31',
			),
			$action
		);

		$this->assertArrayHasKey(
			'dimensionFilter',
			$body,
			"Regression: action '{$action}' previously dropped page_filter silently"
		);
		$this->assertSame( 'pagePath', $body['dimensionFilter']['filter']['fieldName'] );
	}

	/**
	 * Without page_filter, no dimensionFilter is emitted at all.
	 */
	public function test_no_filter_when_page_filter_absent(): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'start_date' => '2026-01-01',
				'end_date'   => '2026-01-31',
			),
			'date_stats'
		);

		$this->assertArrayNotHasKey( 'dimensionFilter', $body );
	}

	/**
	 * page_filter + hostname combine into an andGroup with both filters.
	 */
	public function test_page_filter_and_hostname_combine_into_and_group(): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'page_filter' => '/about/',
				'hostname'    => 'example.com',
				'start_date'  => '2026-01-01',
				'end_date'    => '2026-01-31',
			),
			'date_stats'
		);

		$this->assertArrayHasKey( 'andGroup', $body['dimensionFilter'] );
		$expressions = $body['dimensionFilter']['andGroup']['expressions'];
		$this->assertCount( 2, $expressions );

		$field_names = array_map(
			static fn( $e ) => $e['filter']['fieldName'],
			$expressions
		);
		$this->assertContains( 'pagePath', $field_names );
		$this->assertContains( 'hostName', $field_names );
	}

	/**
	 * Empty page_filter string should not produce a dimensionFilter (empty()
	 * check semantics).
	 */
	public function test_empty_page_filter_does_not_emit_filter(): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'page_filter' => '',
				'start_date'  => '2026-01-01',
				'end_date'    => '2026-01-31',
			),
			'date_stats'
		);

		$this->assertArrayNotHasKey( 'dimensionFilter', $body );
	}

	/**
	 * The recovery-analysis additions are fixed report presets. Callers cannot
	 * select arbitrary dimensions or metrics.
	 *
	 * @dataProvider bounded_page_report_configs
	 */
	public function test_bounded_page_report_config( string $action, array $dimensions, array $metrics ): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'start_date' => '2026-01-01',
				'end_date'   => '2026-01-31',
			),
			$action
		);

		$this->assertSame( $dimensions, wp_list_pluck( $body['dimensions'], 'name' ) );
		$this->assertSame( $metrics, wp_list_pluck( $body['metrics'], 'name' ) );
	}

	/**
	 * landing_page_acquisition retains session-entry filtering while the two
	 * pagePath reports retain touched-page filtering.
	 *
	 * @dataProvider bounded_page_filter_dimensions
	 */
	public function test_bounded_page_report_filter_semantics( string $action, string $field_name ): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'page_filter' => '/recovery/',
				'hostname'    => 'example.com',
				'start_date'  => '2026-01-01',
				'end_date'    => '2026-01-31',
			),
			$action
		);

		$filters = $body['dimensionFilter']['andGroup']['expressions'];
		$this->assertSame( $field_name, $filters[0]['filter']['fieldName'] );
		$this->assertSame( 'hostName', $filters[1]['filter']['fieldName'] );
	}

	public function test_report_limit_is_clamped_to_valid_range(): void {
		$too_small = GoogleAnalyticsAbilities::buildReportRequestBody( array( 'limit' => -5 ), 'page_acquisition' );
		$too_large = GoogleAnalyticsAbilities::buildReportRequestBody( array( 'limit' => 20000 ), 'page_acquisition' );

		$this->assertSame( 1, $too_small['limit'] );
		$this->assertSame( GoogleAnalyticsAbilities::MAX_LIMIT, $too_large['limit'] );
	}

	public function test_landing_acquisition_requests_total_session_aggregation(): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody( array( 'limit' => 25 ), 'landing_page_acquisition' );

		$this->assertSame( array( 'TOTAL' ), $body['metricAggregations'] );
		$this->assertArrayNotHasKey( 'metricAggregations', GoogleAnalyticsAbilities::buildReportRequestBody( array(), 'page_acquisition' ) );
	}

	public function test_ai_tool_schema_enumerates_bounded_actions(): void {
		$reflection = new \ReflectionClass( GoogleAnalytics::class );
		$tool       = $reflection->newInstanceWithoutConstructor();
		$definition = $tool->getToolDefinition();
		$legacy_parameters = $definition['parameters']['oneOf'][0]['properties'];
		$aggregate_parameters = $definition['parameters']['oneOf'][1]['properties'];

		$this->assertContains( 'landing_page_acquisition', $legacy_parameters['action']['enum'] );
		$this->assertContains( 'page_acquisition', $legacy_parameters['action']['enum'] );
		$this->assertContains( 'page_audience', $legacy_parameters['action']['enum'] );
		$this->assertNotContains( 'aggregate_report', $legacy_parameters['action']['enum'] );
		$this->assertSame( array( 'asc', 'desc' ), $legacy_parameters['order']['enum'] );
		$this->assertSame( 1, $legacy_parameters['limit']['minimum'] );
		$this->assertSame( GoogleAnalyticsAbilities::MAX_LIMIT, $legacy_parameters['limit']['maximum'] );
		$this->assertSame( array( 'aggregate_report' ), $aggregate_parameters['action']['enum'] );
		$this->assertSame( GoogleAnalyticsAbilities::AGGREGATE_MAX_ROWS, $aggregate_parameters['limit']['maximum'] );
	}

	public function test_report_rows_keep_dimensions_and_cast_numeric_metrics(): void {
		$data = array(
			'dimensionHeaders' => array(
				array( 'name' => 'pagePath' ),
				array( 'name' => 'country' ),
				array( 'name' => 'deviceCategory' ),
			),
			'metricHeaders'    => array(
				array( 'name' => 'screenPageViews' ),
				array( 'name' => 'engagementRate' ),
			),
			'rows'             => array(
				array(
					'dimensionValues' => array(
						array( 'value' => '/recovery/' ),
						array( 'value' => 'United States' ),
						array( 'value' => 'mobile' ),
					),
					'metricValues'    => array(
						array( 'value' => '42' ),
						array( 'value' => '0.625' ),
					),
				),
			),
		);

		$method = new \ReflectionMethod( GoogleAnalyticsAbilities::class, 'formatReportRows' );
		$method->setAccessible( true );
		$rows = $method->invoke( null, $data );

		$this->assertSame( '/recovery/', $rows[0]['pagePath'] );
		$this->assertSame( 42, $rows[0]['screenPageViews'] );
		$this->assertSame( 0.625, $rows[0]['engagementRate'] );
	}

	public function test_pagination_metadata_discloses_api_truncation(): void {
		$data = array(
			'rowCount' => 10,
			'rows'     => array( array(), array(), array() ),
		);

		$pagination = $this->pagination_metadata( $data, 3, 3, false );

		$this->assertSame( 10, $pagination['api_row_count'] );
		$this->assertSame( 3, $pagination['fetched_rows'] );
		$this->assertTrue( $pagination['truncated'] );
	}

	public function test_comparison_pagination_uses_current_period_rows_for_display_truncation(): void {
		$data = $this->comparison_response(
			array( 'pagePath', 'sessionSource', 'sessionMedium' ),
			array( 'sessions' ),
			array(
				array( array( '/one/', 'google', 'organic' ), 'date_range_0', array( '20' ) ),
				array( array( '/two/', 'direct', '(none)' ), 'date_range_0', array( '10' ) ),
				array( array( '/one/', 'google', 'organic' ), 'date_range_1', array( '18' ) ),
				array( array( '/two/', 'direct', '(none)' ), 'date_range_1', array( '8' ) ),
			)
		);
		$data['rowCount'] = 4;

		$pagination = $this->pagination_metadata( $data, 2, 2, true );

		$this->assertFalse( $pagination['truncated'] );
	}

	public function test_unknown_landing_coverage_reports_absent_cohort(): void {
		$data     = $this->landing_acquisition_response(
			array( array( '/known/', 'google', 'organic', 100, 60 ) ),
			100
		);
		$coverage = GoogleAnalyticsAbilities::buildUnknownDimensionCoverage( $data, false );
		$current  = $coverage['current_period'];

		$this->assertSame( 'complete', $current['status'] );
		$this->assertSame( 0, $current['unknown_sessions'] );
		$this->assertSame( 100, $current['observed_fetched_sessions'] );
		$this->assertSame( 0, $current['share'] );
		$this->assertSame( 'absent', $current['materiality'] );
		$this->assertNull( GoogleAnalyticsCommand::coverageWarning( array( 'unknown_dimension_coverage' => $coverage ) ) );
	}

	public function test_unknown_landing_coverage_reports_small_cohort(): void {
		$data     = $this->landing_acquisition_response(
			array(
				array( '(not set)', 'google', 'organic', 4, 1 ),
				array( '/known/', 'google', 'organic', 96, 58 ),
			),
			100
		);
		$current = GoogleAnalyticsAbilities::buildUnknownDimensionCoverage( $data, false )['current_period'];

		$this->assertSame( 4, $current['unknown_sessions'] );
		$this->assertSame( 0.04, $current['share'] );
		$this->assertSame( 0.25, $current['engagement_rate'] );
		$this->assertSame( 'small', $current['materiality'] );
	}

	public function test_unknown_landing_coverage_reports_material_cohort_and_cli_warning(): void {
		$data     = $this->landing_acquisition_response(
			array(
				array( '(not set)', 'google', 'organic', 12, 2 ),
				array( '(not set)', 'bing', 'organic', 8, 0 ),
				array( '/known/', 'google', 'organic', 80, 48 ),
			),
			100
		);
		$coverage = GoogleAnalyticsAbilities::buildUnknownDimensionCoverage( $data, false );
		$current  = $coverage['current_period'];

		$this->assertSame( 20, $current['unknown_sessions'] );
		$this->assertSame( 100, $current['total_sessions'] );
		$this->assertSame( 0.2, $current['share'] );
		$this->assertSame( 2, $current['engaged_sessions'] );
		$this->assertSame( 0.1, $current['engagement_rate'] );
		$this->assertSame( 'material', $current['materiality'] );
		$this->assertStringContainsString( '20 sessions (20.0%)', GoogleAnalyticsCommand::coverageWarning( array( 'unknown_dimension_coverage' => $coverage ) ) );
	}

	public function test_truncated_unknown_landing_coverage_is_an_explicit_lower_bound(): void {
		$data             = $this->landing_acquisition_response(
			array(
				array( '(not set)', 'google', 'organic', 20, 2 ),
				array( '/known/', 'google', 'organic', 30, 18 ),
			),
			100
		);
		$data['rowCount'] = 12;
		$coverage         = GoogleAnalyticsAbilities::buildUnknownDimensionCoverage( $data, false );
		$current          = $coverage['current_period'];

		$this->assertSame( 'partial', $current['status'] );
		$this->assertNull( $current['unknown_sessions'] );
		$this->assertSame( 20, $current['observed_unknown_sessions'] );
		$this->assertSame( 50, $current['observed_fetched_sessions'] );
		$this->assertNull( $current['share'] );
		$this->assertSame( 0.2, $current['observed_share_lower_bound'] );
		$this->assertNull( $current['engaged_sessions'] );
		$this->assertNull( $current['engagement_rate'] );
		$this->assertSame( 'material', $current['materiality'] );
		$this->assertStringContainsString( 'at least 20 sessions (at least 20.0%)', GoogleAnalyticsCommand::coverageWarning( array( 'unknown_dimension_coverage' => $coverage ) ) );
	}

	public function test_truncated_small_observation_has_unknown_materiality(): void {
		$data             = $this->landing_acquisition_response(
			array( array( '(not set)', 'google', 'organic', 2, 0 ) ),
			100
		);
		$data['rowCount'] = 50;
		$current          = GoogleAnalyticsAbilities::buildUnknownDimensionCoverage( $data, false )['current_period'];

		$this->assertSame( 'partial', $current['status'] );
		$this->assertSame( 'unknown', $current['materiality'] );
	}

	public function test_missing_api_row_count_never_claims_complete_coverage(): void {
		$data = $this->landing_acquisition_response(
			array( array( '(not set)', 'google', 'organic', 20, 2 ) ),
			100
		);
		unset( $data['rowCount'] );

		$current = GoogleAnalyticsAbilities::buildUnknownDimensionCoverage( $data, false )['current_period'];

		$this->assertSame( 'partial', $current['status'] );
		$this->assertNull( $current['unknown_sessions'] );
		$this->assertSame( 0.2, $current['observed_share_lower_bound'] );
	}

	public function test_comparison_reports_each_period_unknown_coverage(): void {
		$data     = $this->landing_acquisition_response(
			array(
				array( '(not set)', 'google', 'organic', 20, 2 ),
				array( '/known/', 'google', 'organic', 80, 48 ),
			),
			100,
			array(
				array( '(not set)', 'google', 'organic', 4, 1 ),
				array( '/known/', 'google', 'organic', 76, 45 ),
			),
			80
		);
		$coverage = GoogleAnalyticsAbilities::buildUnknownDimensionCoverage( $data, true );

		$this->assertSame( 0.2, $coverage['current_period']['share'] );
		$this->assertSame( 'material', $coverage['current_period']['materiality'] );
		$this->assertSame( 0.05, $coverage['comparison_period']['share'] );
		$this->assertSame( 'material', $coverage['comparison_period']['materiality'] );
	}

	public function test_unknown_coverage_does_not_filter_or_mutate_report_rows(): void {
		$data = $this->landing_acquisition_response(
			array(
				array( '(not set)', 'google', 'organic', 20, 2 ),
				array( '/known/', 'google', 'organic', 80, 48 ),
			),
			100
		);
		$before = $data;

		GoogleAnalyticsAbilities::buildUnknownDimensionCoverage( $data, false );
		$rows = $this->format_report_rows( $data );

		$this->assertSame( $before, $data );
		$this->assertCount( 2, $rows );
		$this->assertSame( '(not set)', $rows[0]['landingPage'] );
	}

	public function test_unknown_coverage_validates_against_ability_output_schema(): void {
		$data     = $this->landing_acquisition_response(
			array( array( '(not set)', 'google', 'organic', 20, 2 ) ),
			20
		);
		$response = array(
			'success'                    => true,
			'action'                     => 'landing_page_acquisition',
			'results_count'              => 1,
			'results'                    => $this->format_report_rows( $data ),
			'pagination'                 => array( 'truncated' => false ),
			'unknown_dimension_coverage' => GoogleAnalyticsAbilities::buildUnknownDimensionCoverage( $data, false ),
		);

		$validated = rest_validate_value_from_schema( $response, GoogleAnalyticsAbilities::outputSchema(), 'output' );
		$this->assertTrue( $validated, is_wp_error( $validated ) ? $validated->get_error_message() : '' );
	}

	/**
	 * path_sequence builds a hostName-EXACT funnel step (the unit-testable
	 * request shape used by the 2-step ordered-pair funnel).
	 */
	public function test_path_sequence_build_host_funnel_step(): void {
		$step = GoogleAnalyticsAbilities::buildHostFunnelStep( 'entry', 'a.example.com' );

		$this->assertSame( 'entry', $step['name'] );
		$filter = $step['filterExpression']['funnelFieldFilter'];
		$this->assertSame( 'hostName', $filter['fieldName'] );
		$this->assertSame( 'EXACT', $filter['stringFilter']['matchType'] );
		$this->assertSame( 'a.example.com', $filter['stringFilter']['value'] );
	}

	/**
	 * path_sequence reads step-1 (entry) and step-2 (next) activeUsers out of a
	 * 2-step funnelTable response by ordinal step prefix, ignoring the extra
	 * funnel metric columns (completionRate, abandonments, abandonmentRate).
	 */
	public function test_path_sequence_extract_funnel_step_users(): void {
		$response = array(
			'funnelTable' => array(
				'dimensionHeaders' => array( array( 'name' => 'funnelStepName' ) ),
				'metricHeaders'    => array(
					array( 'name' => 'activeUsers' ),
					array( 'name' => 'completionRate' ),
					array( 'name' => 'abandonments' ),
					array( 'name' => 'abandonmentRate' ),
				),
				'rows'             => array(
					array(
						'dimensionValues' => array( array( 'value' => '1. entry' ) ),
						'metricValues'    => array(
							array( 'value' => '5132' ),
							array( 'value' => '0.0304' ),
							array( 'value' => '4976' ),
							array( 'value' => '0.9696' ),
						),
					),
					array(
						'dimensionValues' => array( array( 'value' => '2. next' ) ),
						'metricValues'    => array(
							array( 'value' => '156' ),
							array( 'value' => '0.0' ),
							array( 'value' => '0' ),
							array( 'value' => '0.0' ),
						),
					),
				),
			),
		);

		$method = new \ReflectionMethod( GoogleAnalyticsAbilities::class, 'extractFunnelStepUsers' );
		$method->setAccessible( true );

		$users = $method->invoke( null, $response );

		$this->assertSame( 5132, $users['entry_users'] );
		$this->assertSame( 156, $users['next_users'] );
	}

	/**
	 * Every ordered direction is queried and aggregated independently, including
	 * the asymmetric case where the first direction is zero.
	 */
	public function test_path_sequence_queries_ordered_directions_independently(): void {
		$fixture = json_decode(
			file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/ga-path-sequence-directions.json' ),
			true
		);
		$method  = new \ReflectionMethod( GoogleAnalyticsAbilities::class, 'collectPathSequenceResults' );
		$method->setAccessible( true );

		foreach ( $fixture['cases'] as $case ) {
			$requests = array();
			$fetch    = static function ( string $entry_host, string $next_host ) use ( $case, &$requests ): array {
				$key        = $entry_host . ' -> ' . $next_host;
				$requests[] = $key;

				return $case['transitions'][ $key ];
			};

			$results = $method->invoke( null, $case['hosts'], $fetch );

			$this->assertSame( $case['expected_requests'], $requests, $case['name'] );
			$this->assertSame( $case['expected_results'], $results, $case['name'] );
		}
	}

	/**
	 * The generic layer ships with NO in-network host set baked in. With no
	 * consumer registering the datamachine_network_density_hosts filter, the
	 * default host list is empty and network_density emits no pageReferrer
	 * dimensionFilter — the EC-specific default was removed (layer purity).
	 */
	public function test_network_density_emits_no_referrer_filter_by_default(): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'start_date' => '2026-01-01',
				'end_date'   => '2026-01-31',
			),
			'network_density'
		);

		$this->assertArrayNotHasKey(
			'dimensionFilter',
			$body,
			'With no configured in-network hosts, network_density must not emit a referrer filter'
		);
	}

	/**
	 * When a consumer registers multiple in-network hosts via the filter,
	 * network_density constrains pageReferrer to that host set server-side
	 * (an orGroup of CONTAINS expressions) so the GA4 row cap can't truncate
	 * the in-network referrer long tail.
	 */
	public function test_network_density_filters_referrer_to_configured_hosts(): void {
		$callback = static fn() => array( 'example.com', 'example.link' );
		add_filter( 'datamachine_network_density_hosts', $callback );

		try {
			$body = GoogleAnalyticsAbilities::buildReportRequestBody(
				array(
					'start_date' => '2026-01-01',
					'end_date'   => '2026-01-31',
				),
				'network_density'
			);
		} finally {
			remove_filter( 'datamachine_network_density_hosts', $callback );
		}

		$this->assertArrayHasKey(
			'dimensionFilter',
			$body,
			'Configured in-network hosts must emit a pageReferrer dimensionFilter'
		);
		$this->assertArrayHasKey(
			'orGroup',
			$body['dimensionFilter'],
			'A multi-host set must produce an orGroup of pageReferrer expressions'
		);

		$expressions = $body['dimensionFilter']['orGroup']['expressions'];
		$this->assertCount( 2, $expressions, 'Configured host set has two hosts' );

		foreach ( $expressions as $expr ) {
			$filter = $expr['filter'];
			$this->assertSame( 'pageReferrer', $filter['fieldName'] );
			$this->assertSame( 'CONTAINS', $filter['stringFilter']['matchType'] );
		}

		$values = array_map(
			static fn( $e ) => $e['filter']['stringFilter']['value'],
			$expressions
		);
		$this->assertContains( 'example.com', $values );
		$this->assertContains( 'example.link', $values );
	}

	/**
	 * The in-network host set is config/filter-driven, not an inline literal. A
	 * single configured host collapses to one (non-grouped) filter expression.
	 */
	public function test_network_density_hosts_are_filterable(): void {
		$callback = static fn() => array( 'example.test' );
		add_filter( 'datamachine_network_density_hosts', $callback );

		try {
			$body = GoogleAnalyticsAbilities::buildReportRequestBody(
				array(
					'start_date' => '2026-01-01',
					'end_date'   => '2026-01-31',
				),
				'network_density'
			);
		} finally {
			remove_filter( 'datamachine_network_density_hosts', $callback );
		}

		// A single host collapses to one (non-grouped) filter expression.
		$filter = $body['dimensionFilter']['filter'];
		$this->assertSame( 'pageReferrer', $filter['fieldName'] );
		$this->assertSame( 'example.test', $filter['stringFilter']['value'] );
	}

	/**
	 * The network_density referrer filter ANDs with an explicit hostname filter
	 * so a per-site density request stays scoped to that host while still only
	 * counting in-network referrers.
	 */
	public function test_network_density_referrer_filter_ands_with_hostname(): void {
		$callback = static fn() => array( 'example.com', 'example.link' );
		add_filter( 'datamachine_network_density_hosts', $callback );

		try {
			$body = GoogleAnalyticsAbilities::buildReportRequestBody(
				array(
					'hostname'   => 'example.com',
					'start_date' => '2026-01-01',
					'end_date'   => '2026-01-31',
				),
				'network_density'
			);
		} finally {
			remove_filter( 'datamachine_network_density_hosts', $callback );
		}

		$this->assertArrayHasKey( 'andGroup', $body['dimensionFilter'] );
		$expressions = $body['dimensionFilter']['andGroup']['expressions'];
		$this->assertCount( 2, $expressions );

		$has_hostname = false;
		$has_referrer_group = false;
		foreach ( $expressions as $expr ) {
			if ( isset( $expr['filter'] ) && 'hostName' === $expr['filter']['fieldName'] ) {
				$has_hostname = true;
			}
			if ( isset( $expr['orGroup'] ) ) {
				$referrer_fields = array_map(
					static fn( $e ) => $e['filter']['fieldName'],
					$expr['orGroup']['expressions']
				);
				$has_referrer_group = array( 'pageReferrer', 'pageReferrer' ) === $referrer_fields;
			}
		}

		$this->assertTrue( $has_hostname, 'hostName EXACT filter must be present' );
		$this->assertTrue( $has_referrer_group, 'in-network pageReferrer orGroup must be present' );
	}

	/**
	 * network_density is the only report that gets the in-network referrer
	 * filter — other actions must not emit a pageReferrer dimensionFilter.
	 */
	public function test_other_actions_do_not_get_referrer_filter(): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'start_date' => '2026-01-01',
				'end_date'   => '2026-01-31',
			),
			'date_stats'
		);

		$this->assertArrayNotHasKey( 'dimensionFilter', $body );
	}

	/**
	 * Comparison rows are joined by landing page before the final limit is
	 * applied. Prior-only rows are not emitted and absent prior keys are new.
	 */
	public function test_landing_page_comparison_reconciles_rows_and_applies_final_limit(): void {
		$data = $this->comparison_response(
			array( 'landingPage' ),
			array( 'sessions' ),
			array(
				array( array( '/shared' ), 'date_range_0', array( '120' ) ),
				array( array( '/new' ), 'date_range_0', array( '30' ) ),
				array( array( '/shared' ), 'date_range_1', array( '100' ) ),
				array( array( '/removed' ), 'date_range_1', array( '40' ) ),
			)
		);

		$rows = $this->format_comparison_rows( $data, 2 );

		$this->assertCount( 2, $rows );
		$this->assertSame( '/shared', $rows[0]['landingPage'] );
		$this->assertSame( 120, $rows[0]['sessions'] );
		$this->assertSame( '+20%', $rows[0]["\xCE\x94 sessions"] );
		$this->assertSame( 'new', $rows[1]["\xCE\x94 sessions"] );
		$this->assertNotContains( '/removed', wp_list_pluck( $rows, 'landingPage' ) );

		$limited = $this->format_comparison_rows( $data, 1 );
		$this->assertCount( 1, $limited );
		$this->assertSame( '/shared', $limited[0]['landingPage'] );
	}

	/**
	 * A present zero-valued prior row is not a new key, even though a percentage
	 * delta cannot be calculated from zero.
	 */
	public function test_comparison_marks_only_absent_prior_keys_as_new(): void {
		$data = $this->comparison_response(
			array( 'landingPage' ),
			array( 'sessions' ),
			array(
				array( array( '(not set)' ), 'date_range_0', array( '10' ) ),
				array( array( '(not set)' ), 'date_range_1', array( '0' ) ),
			)
		);

		$rows = $this->format_comparison_rows( $data, 10 );

		$this->assertSame( '-', $rows[0]["\xCE\x94 sessions"] );
	}

	/**
	 * Multi-dimension actions use the complete tuple as their stable key, so two
	 * rows sharing a source but using different media remain independent.
	 */
	public function test_comparison_uses_complete_multi_dimension_key(): void {
		$data = $this->comparison_response(
			array( 'sessionSource', 'sessionMedium' ),
			array( 'sessions' ),
			array(
				array( array( 'search', 'organic' ), 'date_range_0', array( '90' ) ),
				array( array( 'search', 'referral' ), 'date_range_0', array( '25' ) ),
				array( array( 'search', 'organic' ), 'date_range_1', array( '60' ) ),
				array( array( 'search', 'referral' ), 'date_range_1', array( '20' ) ),
			)
		);

		$rows = $this->format_comparison_rows( $data, 10 );

		$this->assertCount( 2, $rows );
		$this->assertSame( '+50%', $rows[0]["\xCE\x94 sessions"] );
		$this->assertSame( '+25%', $rows[1]["\xCE\x94 sessions"] );
	}

	/**
	 * Compare requests fetch the supported key set; the requested limit is
	 * enforced after current/prior reconciliation instead of by GA per range.
	 */
	public function test_compare_request_defers_limit_until_after_reconciliation(): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'compare'    => true,
				'limit'      => 30,
				'start_date' => '2026-04-01',
				'end_date'   => '2026-07-13',
			),
			'landing_pages'
		);

		$this->assertSame( GoogleAnalyticsAbilities::MAX_LIMIT, $body['limit'] );
	}

	public function test_aggregate_request_is_bounded_and_uses_total_quota_batch_shape(): void {
		$body = GoogleAnalyticsAbilities::buildAggregateReportRequestBody( array(
			'action' => 'aggregate_report',
			'date_range' => array( 'start_date' => '2026-01-01', 'end_date' => '2026-01-31' ),
			'dimensions' => array( 'country' ), 'metrics' => array( 'sessions', 'activeUsers' ), 'limit' => 100,
			'filters' => array( array( 'field_name' => 'hostName', 'match_type' => 'EXACT', 'value' => 'example.com' ) ),
			'order_by' => array( array( 'type' => 'metric', 'name' => 'sessions', 'descending' => true ) ),
		) );
		$this->assertNotWPError( $body );
		$this->assertSame( array( 'TOTAL' ), $body['metricAggregations'] );
		$this->assertTrue( $body['returnPropertyQuota'] );
		$this->assertSame( 'example.com', $body['dimensionFilter']['andGroup']['expressions'][0]['filter']['stringFilter']['value'] );
		$this->assertSame( 'sessions', $body['orderBys'][0]['metric']['metricName'] );
	}

	/** @dataProvider invalid_aggregate_inputs */
	public function test_aggregate_request_rejects_invalid_bounds_and_fields( array $input ): void {
		$this->assertWPError( GoogleAnalyticsAbilities::buildAggregateReportRequestBody( $input ) );
	}

	public function test_aggregate_normalization_discloses_all_coverage_limits(): void {
		$report = GoogleAnalyticsAbilities::normalizeAggregateReport( array(
			'kind' => GoogleAnalyticsAbilities::AGGREGATE_REPORT_RESPONSE_KIND,
			'dimensionHeaders' => array( array( 'name' => 'country' ) ), 'metricHeaders' => array( array( 'name' => 'sessions' ) ),
			'rows' => array( array( 'dimensionValues' => array( array( 'value' => 'US' ) ), 'metricValues' => array( array( 'value' => '5' ) ) ) ),
			'totals' => array( array( 'metricValues' => array( array( 'value' => '9' ) ) ) ), 'rowCount' => 9,
			'metadata' => array( 'dataLossFromOtherRow' => true, 'subjectToThresholding' => true, 'samplingMetadatas' => array( array( 'samplesReadCount' => '10', 'samplingSpaceSize' => '100' ) ), 'timeZone' => 'UTC', 'currencyCode' => 'USD' ),
			'propertyQuota' => array( 'tokensPerDay' => array( 'remaining' => 42 ) ),
		), array( 'start_date' => '2026-01-01', 'end_date' => '2026-01-31' ), array( 'country' ), array( 'sessions' ) );
		$this->assertNotWPError( $report );
		$this->assertSame( '5', $report['rows'][0]['metrics']['sessions'] );
		$this->assertSame( '9', $report['totals']['sessions'] );
		$this->assertSame( 42, $report['quota_remaining']['tokensPerDay'] );
		$this->assertCount( 4, $report['coverage_limits'] );
	}

	public function test_aggregate_normalization_rejects_malformed_rows_and_preserves_old_action_builder(): void {
		$this->assertWPError( GoogleAnalyticsAbilities::normalizeAggregateReport( array( 'kind' => GoogleAnalyticsAbilities::AGGREGATE_REPORT_RESPONSE_KIND, 'dimensionHeaders' => array(), 'metricHeaders' => array(), 'rows' => array( array() ), 'totals' => array() ), array() ) );
		$this->assertSame( array( 'date' ), wp_list_pluck( GoogleAnalyticsAbilities::buildReportRequestBody( array(), 'date_stats' )['dimensions'], 'name' ) );
	}

	public function test_aggregate_batch_request_uses_one_http_envelope_for_primary_and_comparison(): void {
		$input = array( 'action' => 'aggregate_report', 'date_range' => array( 'start_date' => '2026-01-01', 'end_date' => '2026-01-31' ), 'comparison_date_range' => array( 'start_date' => '2025-01-01', 'end_date' => '2025-01-31' ), 'metrics' => array( 'sessions' ) );
		$batch = GoogleAnalyticsAbilities::buildAggregateBatchRequest( $input, '123456789', 'secret-token' );
		$this->assertNotWPError( $batch );
		$this->assertSame( 'https://analyticsdata.googleapis.com/v1beta/properties/123456789:batchRunReports', $batch['url'] );
		$this->assertSame( GoogleAnalyticsAbilities::AGGREGATE_MAX_RESPONSE_BYTES, $batch['options']['limit_response_size'] );
		$this->assertCount( 2, json_decode( $batch['options']['body'], true )['requests'] );
		$this->assertSame( $input['date_range'], $batch['ranges'][0] );
		$this->assertSame( $input['comparison_date_range'], $batch['ranges'][1] );
	}

	public function test_aggregate_schema_is_closed_and_does_not_expose_property_id_in_output(): void {
		$input_schema = GoogleAnalyticsAbilities::aggregateInputSchema();
		$this->assertFalse( $input_schema['additionalProperties'] );
		$this->assertFalse( $input_schema['properties']['filters']['items']['additionalProperties'] );
		$this->assertFalse( $input_schema['properties']['order_by']['items']['additionalProperties'] );
		$output_schema = GoogleAnalyticsAbilities::outputSchema();
		$this->assertArrayNotHasKey( 'property_id', $output_schema['properties'] );
		$response = array( 'success' => true, 'action' => 'aggregate_report', 'dimensions' => array(), 'metrics' => array( 'sessions' ), 'filters' => array(), 'reports' => array( array( 'label' => 'primary', 'date_range' => array( 'start_date' => '2026-01-01', 'end_date' => '2026-01-31' ), 'rows' => array(), 'totals' => array( 'sessions' => '0' ), 'returned_row_count' => 0, 'row_count' => 0, 'quota_remaining' => array(), 'coverage_limits' => array( 'No rows matched the requested report.' ), 'time_zone' => '', 'currency_code' => '' ) ) );
		$validated = rest_validate_value_from_schema( $response, $output_schema, 'output' );
		$this->assertTrue( $validated, is_wp_error( $validated ) ? $validated->get_error_message() : '' );
	}

	public function test_aggregate_exact_400_day_boundary_and_malformed_nested_values(): void {
		$valid = GoogleAnalyticsAbilities::buildAggregateReportRequestBody( array( 'action' => 'aggregate_report', 'date_range' => array( 'start_date' => '2025-01-01', 'end_date' => '2026-02-04' ), 'metrics' => array( 'sessions' ) ) );
		$this->assertNotWPError( $valid );
		$malformed = array( 'kind' => GoogleAnalyticsAbilities::AGGREGATE_REPORT_RESPONSE_KIND, 'dimensionHeaders' => array( array( 'name' => 'country' ) ), 'metricHeaders' => array( array( 'name' => 'sessions', 'type' => 'TYPE_INTEGER' ) ), 'rows' => array( array( 'dimensionValues' => array( array( 'value' => 'US' ) ), 'metricValues' => array( array( 'value' => 1 ) ) ) ), 'totals' => array() );
		$this->assertWPError( GoogleAnalyticsAbilities::normalizeAggregateReport( $malformed, array() ) );
		$malformed['rows'][0]['metricValues'][0]['value'] = '1';
		$malformed['rowCount'] = 1.5;
		$this->assertWPError( GoogleAnalyticsAbilities::normalizeAggregateReport( $malformed, array() ) );
	}

	public function test_aggregate_normalizes_bounded_empty_and_schema_restriction_metadata(): void {
		$report = array(
			'kind' => GoogleAnalyticsAbilities::AGGREGATE_REPORT_RESPONSE_KIND,
			'dimensionHeaders' => array(), 'metricHeaders' => array( array( 'name' => 'sessions', 'type' => 'TYPE_INTEGER' ) ),
			'rows' => array(), 'totals' => array( array( 'metricValues' => array( array( 'value' => '0' ) ) ) ),
			'metadata' => array( 'emptyReason' => 'NO_DATA', 'schemaRestrictionResponse' => array( 'activeMetricRestrictions' => array( array( 'restrictedMetricTypes' => array( 'COST_DATA', 'REVENUE_DATA' ), 'metricName' => 'sessions' ) ) ) ),
		);
		$normalized = GoogleAnalyticsAbilities::normalizeAggregateReport( $report, array(), array(), array( 'sessions' ) );
		$this->assertNotWPError( $normalized );
		$this->assertContains( 'Google Analytics reported an empty result for this report.', $normalized['coverage_limits'] );
		$this->assertContains( 'Google Analytics restricted one or more requested metrics.', $normalized['coverage_limits'] );
		$this->assertNotContains( 'NO_DATA', $normalized['coverage_limits'] );
		$report['metadata']['schemaRestrictionResponse']['activeMetricRestrictions'][0] = array( 'metricName' => 'sessions', 'restrictedMetricType' => 'COST_DATA' );
		$this->assertWPError( GoogleAnalyticsAbilities::normalizeAggregateReport( $report, array(), array(), array( 'sessions' ) ) );
	}

	public function test_aggregate_rejects_zero_dimension_header_or_value_mismatches(): void {
		$base = array( 'kind' => GoogleAnalyticsAbilities::AGGREGATE_REPORT_RESPONSE_KIND, 'dimensionHeaders' => array(), 'metricHeaders' => array( array( 'name' => 'sessions' ) ), 'rows' => array(), 'totals' => array() );
		$with_header = $base;
		$with_header['dimensionHeaders'][] = array( 'name' => 'country' );
		$this->assertWPError( GoogleAnalyticsAbilities::normalizeAggregateReport( $with_header, array(), array(), array( 'sessions' ) ) );
		$with_value = $base;
		$with_value['rows'][] = array( 'dimensionValues' => array( array( 'value' => 'US' ) ), 'metricValues' => array( array( 'value' => '1' ) ) );
		$this->assertWPError( GoogleAnalyticsAbilities::normalizeAggregateReport( $with_value, array(), array(), array( 'sessions' ) ) );
	}

	public function test_aggregate_accepts_omitted_repeated_fields_for_empty_and_total_only_reports(): void {
		$empty = GoogleAnalyticsAbilities::normalizeAggregateReport( array( 'kind' => GoogleAnalyticsAbilities::AGGREGATE_REPORT_RESPONSE_KIND ), array(), array( 'country' ), array( 'sessions' ) );
		$this->assertNotWPError( $empty );
		$this->assertSame( array( 'sessions' => '' ), $empty['totals'] );
		$this->assertContains( 'No rows matched the requested report.', $empty['coverage_limits'] );
		$total_only = GoogleAnalyticsAbilities::normalizeAggregateReport( array( 'kind' => GoogleAnalyticsAbilities::AGGREGATE_REPORT_RESPONSE_KIND, 'metricHeaders' => array( array( 'name' => 'sessions' ) ), 'totals' => array( array( 'metricValues' => array( array( 'value' => '12' ) ) ) ) ), array(), array( 'country' ), array( 'sessions' ) );
		$this->assertNotWPError( $total_only );
		$this->assertSame( '12', $total_only['totals']['sessions'] );
	}

	public function test_aggregate_output_schema_bounds_closed_rows_and_coverage(): void {
		$report = GoogleAnalyticsAbilities::outputSchema()['properties']['reports'];
		$row = $report['items']['properties']['rows'];
		$this->assertSame( 2, $report['maxItems'] );
		$this->assertSame( GoogleAnalyticsAbilities::AGGREGATE_MAX_ROWS, $row['maxItems'] );
		$this->assertFalse( $row['items']['additionalProperties'] );
		$this->assertSame( 10, $report['items']['properties']['coverage_limits']['maxItems'] );
	}

	public function test_cli_maps_explicit_property_id_and_fixed_action_builder_remains_compatible(): void {
		$command = ( new \ReflectionClass( GoogleAnalyticsCommand::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( GoogleAnalyticsCommand::class, 'map_optional' );
		$method->setAccessible( true );
		$input = array();
		$method->invokeArgs( $command, array( &$input, array( 'property-id' => '123456789' ), array( 'property-id' => 'property_id' ) ) );
		$this->assertSame( '123456789', $input['property_id'] );
		$this->assertSame( array( 'sessions', 'activeUsers', 'screenPageViews', 'bounceRate' ), wp_list_pluck( GoogleAnalyticsAbilities::buildReportRequestBody( array(), 'traffic_sources' )['metrics'], 'name' ) );
	}

	public function test_aggregate_errors_return_canonical_messages_without_provider_content(): void {
		$method = new \ReflectionMethod( GoogleAnalyticsAbilities::class, 'formatAggregateApiError' );
		$method->setAccessible( true );
		$error = $method->invoke( null, array( 'code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'Filter secret-token was rejected.' ), 403 );
		$this->assertSame( 'GA4 API error (403 PERMISSION_DENIED): Permission denied.', $error );
	}

	public function test_aggregate_property_resolution_uses_configured_or_explicit_id_without_output_field(): void {
		$this->assertSame( '123456789', GoogleAnalyticsAbilities::resolveAggregatePropertyId( array(), array( 'property_id' => '123456789' ) ) );
		$this->assertSame( '987654321', GoogleAnalyticsAbilities::resolveAggregatePropertyId( array( 'property_id' => '987654321' ), array( 'property_id' => '123456789' ) ) );
		$this->assertWPError( GoogleAnalyticsAbilities::resolveAggregatePropertyId( array( 'property_id' => 'not-numeric' ), array() ) );
	}

	public static function invalid_aggregate_inputs(): array {
		$base = array( 'action' => 'aggregate_report', 'date_range' => array( 'start_date' => '2026-01-01', 'end_date' => '2026-01-31' ), 'metrics' => array( 'sessions' ) );
		return array(
			'too many days' => array( array_merge( $base, array( 'date_range' => array( 'start_date' => '2025-01-01', 'end_date' => '2026-02-05' ) ) ) ),
			'arbitrary metric' => array( array_merge( $base, array( 'metrics' => array( 'evilMetric' ) ) ) ),
			'unselected ordering' => array( array_merge( $base, array( 'order_by' => array( array( 'type' => 'dimension', 'name' => 'country' ) ) ) ) ),
			'too many filters' => array( array_merge( $base, array( 'filters' => array_fill( 0, 5, array( 'field_name' => 'country', 'match_type' => 'EXACT', 'value' => 'US' ) ) ) ) ),
		);
	}

	private function format_comparison_rows( array $data, int $limit ): array {
		$method = new \ReflectionMethod( GoogleAnalyticsAbilities::class, 'formatComparisonRows' );
		$method->setAccessible( true );

		return $method->invoke( null, $data, $limit );
	}

	private function format_report_rows( array $data ): array {
		$method = new \ReflectionMethod( GoogleAnalyticsAbilities::class, 'formatReportRows' );
		$method->setAccessible( true );

		return $method->invoke( null, $data );
	}

	private function pagination_metadata( array $data, int $limit, int $returned_rows, bool $compare ): array {
		$method = new \ReflectionMethod( GoogleAnalyticsAbilities::class, 'buildPaginationMetadata' );
		$method->setAccessible( true );

		return $method->invoke( null, $data, $limit, $returned_rows, $compare );
	}

	private function comparison_response( array $dimensions, array $metrics, array $rows ): array {
		return array(
			'dimensionHeaders' => array_map(
				static fn( string $name ): array => array( 'name' => $name ),
				array_merge( $dimensions, array( 'dateRange' ) )
			),
			'metricHeaders'    => array_map(
				static fn( string $name ): array => array( 'name' => $name ),
				$metrics
			),
			'rows'             => array_map(
				static function ( array $row ): array {
					return array(
						'dimensionValues' => array_map(
							static fn( string $value ): array => array( 'value' => $value ),
							array_merge( $row[0], array( $row[1] ) )
						),
						'metricValues'    => array_map(
							static fn( string $value ): array => array( 'value' => $value ),
							$row[2]
						),
					);
				},
				$rows
			),
		);
	}

	private function landing_acquisition_response( array $current_rows, int $current_total, array $comparison_rows = array(), ?int $comparison_total = null ): array {
		$compare    = null !== $comparison_total;
		$dimensions = array( 'landingPage', 'sessionSource', 'sessionMedium' );
		if ( $compare ) {
			$dimensions[] = 'dateRange';
		}

		$build_row = static function ( array $row, string $date_range, bool $include_range ): array {
			$dimension_values = array_slice( $row, 0, 3 );
			if ( $include_range ) {
				$dimension_values[] = $date_range;
			}

			return array(
				'dimensionValues' => array_map( static fn( string $value ): array => array( 'value' => $value ), $dimension_values ),
				'metricValues'    => array(
					array( 'value' => (string) $row[3] ),
					array( 'value' => (string) $row[3] ),
					array( 'value' => (string) $row[4] ),
					array( 'value' => (string) ( $row[3] > 0 ? $row[4] / $row[3] : 0 ) ),
				),
			);
		};

		$rows = array_map( static fn( array $row ): array => $build_row( $row, 'date_range_0', $compare ), $current_rows );
		if ( $compare ) {
			$rows = array_merge( $rows, array_map( static fn( array $row ): array => $build_row( $row, 'date_range_1', true ), $comparison_rows ) );
		}

		$build_total = static function ( int $sessions, string $date_range, bool $include_range ): array {
			$dimension_values = array( 'RESERVED_TOTAL', 'RESERVED_TOTAL', 'RESERVED_TOTAL' );
			if ( $include_range ) {
				$dimension_values[] = $date_range;
			}

			return array(
				'dimensionValues' => array_map( static fn( string $value ): array => array( 'value' => $value ), $dimension_values ),
				'metricValues'    => array(
					array( 'value' => (string) $sessions ),
					array( 'value' => '0' ),
					array( 'value' => '0' ),
					array( 'value' => '0' ),
				),
			);
		};

		$totals = array( $build_total( $current_total, 'date_range_0', $compare ) );
		if ( $compare ) {
			$totals[] = $build_total( (int) $comparison_total, 'date_range_1', true );
		}

		return array(
			'dimensionHeaders' => array_map( static fn( string $name ): array => array( 'name' => $name ), $dimensions ),
			'metricHeaders'    => array_map(
				static fn( string $name ): array => array( 'name' => $name ),
				array( 'sessions', 'activeUsers', 'engagedSessions', 'engagementRate' )
			),
			'rows'             => $rows,
			'totals'           => $totals,
			'rowCount'         => count( $rows ),
		);
	}

	public static function all_filterable_actions(): array {
		return array(
			'page_stats'              => array( 'page_stats' ),
			'traffic_sources'         => array( 'traffic_sources' ),
			'date_stats'              => array( 'date_stats' ),
			'top_events'              => array( 'top_events' ),
			'user_demographics'       => array( 'user_demographics' ),
			'landing_pages'           => array( 'landing_pages' ),
			'landing_page_acquisition' => array( 'landing_page_acquisition' ),
			'page_acquisition'        => array( 'page_acquisition' ),
			'page_audience'           => array( 'page_audience' ),
			'engagement'              => array( 'engagement' ),
			'new_vs_returning'        => array( 'new_vs_returning' ),
		);
	}

	public static function page_path_grouped_actions(): array {
		return array(
			'page_stats'       => array( 'page_stats' ),
			'engagement'       => array( 'engagement' ),
			'page_acquisition' => array( 'page_acquisition' ),
			'page_audience'    => array( 'page_audience' ),
		);
	}

	public static function non_page_grouped_actions(): array {
		return array(
			'date_stats'        => array( 'date_stats' ),
			'traffic_sources'   => array( 'traffic_sources' ),
			'top_events'        => array( 'top_events' ),
			'user_demographics' => array( 'user_demographics' ),
			'new_vs_returning'  => array( 'new_vs_returning' ),
		);
	}

	public static function bounded_page_report_configs(): array {
		return array(
			'landing page acquisition' => array(
				'landing_page_acquisition',
				array( 'landingPage', 'sessionSource', 'sessionMedium' ),
				array( 'sessions', 'activeUsers', 'engagedSessions', 'engagementRate' ),
			),
			'page acquisition'         => array(
				'page_acquisition',
				array( 'pagePath', 'sessionSource', 'sessionMedium' ),
				array( 'screenPageViews', 'sessions', 'activeUsers', 'engagedSessions' ),
			),
			'page audience'            => array(
				'page_audience',
				array( 'pagePath', 'country', 'deviceCategory' ),
				array( 'screenPageViews', 'sessions', 'activeUsers' ),
			),
		);
	}

	public static function bounded_page_filter_dimensions(): array {
		return array(
			'landing page acquisition' => array( 'landing_page_acquisition', 'landingPage' ),
			'page acquisition'         => array( 'page_acquisition', 'pagePath' ),
			'page audience'            => array( 'page_audience', 'pagePath' ),
		);
	}
}
