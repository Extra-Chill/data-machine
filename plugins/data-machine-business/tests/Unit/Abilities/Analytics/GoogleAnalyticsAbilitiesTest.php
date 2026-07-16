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

	public function test_ai_tool_schema_enumerates_bounded_actions(): void {
		$reflection = new \ReflectionClass( GoogleAnalytics::class );
		$tool       = $reflection->newInstanceWithoutConstructor();
		$definition = $tool->getToolDefinition();
		$parameters = $definition['parameters']['properties'];

		$this->assertContains( 'landing_page_acquisition', $parameters['action']['enum'] );
		$this->assertContains( 'page_acquisition', $parameters['action']['enum'] );
		$this->assertContains( 'page_audience', $parameters['action']['enum'] );
		$this->assertSame( array( 'asc', 'desc' ), $parameters['order']['enum'] );
		$this->assertSame( 1, $parameters['limit']['minimum'] );
		$this->assertSame( GoogleAnalyticsAbilities::MAX_LIMIT, $parameters['limit']['maximum'] );
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

	private function format_comparison_rows( array $data, int $limit ): array {
		$method = new \ReflectionMethod( GoogleAnalyticsAbilities::class, 'formatComparisonRows' );
		$method->setAccessible( true );

		return $method->invoke( null, $data, $limit );
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
