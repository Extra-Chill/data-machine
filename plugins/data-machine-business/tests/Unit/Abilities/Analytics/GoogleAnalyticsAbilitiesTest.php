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
				'hostname'    => 'extrachill.com',
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
	 * network_density must constrain pageReferrer to the in-network EC hosts
	 * server-side so the GA4 row cap can't truncate the in-network referrer
	 * long tail. With no other filter, the single dimensionFilter is the OR
	 * group of pageReferrer CONTAINS expressions for the default host set.
	 */
	public function test_network_density_filters_referrer_to_in_network_hosts(): void {
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'start_date' => '2026-01-01',
				'end_date'   => '2026-01-31',
			),
			'network_density'
		);

		$this->assertArrayHasKey(
			'dimensionFilter',
			$body,
			'network_density must emit an in-network pageReferrer dimensionFilter'
		);
		$this->assertArrayHasKey(
			'orGroup',
			$body['dimensionFilter'],
			'Default multi-host set must produce an orGroup of pageReferrer expressions'
		);

		$expressions = $body['dimensionFilter']['orGroup']['expressions'];
		$this->assertCount( 2, $expressions, 'Default EC host set has two hosts' );

		foreach ( $expressions as $expr ) {
			$filter = $expr['filter'];
			$this->assertSame( 'pageReferrer', $filter['fieldName'] );
			$this->assertSame( 'CONTAINS', $filter['stringFilter']['matchType'] );
		}

		$values = array_map(
			static fn( $e ) => $e['filter']['stringFilter']['value'],
			$expressions
		);
		$this->assertContains( 'extrachill.com', $values );
		$this->assertContains( 'extrachill.link', $values );
	}

	/**
	 * The in-network host set is config/filter-driven, not an inline literal.
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
		$body = GoogleAnalyticsAbilities::buildReportRequestBody(
			array(
				'hostname'   => 'extrachill.com',
				'start_date' => '2026-01-01',
				'end_date'   => '2026-01-31',
			),
			'network_density'
		);

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

	public static function all_filterable_actions(): array {
		return array(
			'page_stats'        => array( 'page_stats' ),
			'traffic_sources'   => array( 'traffic_sources' ),
			'date_stats'        => array( 'date_stats' ),
			'top_events'        => array( 'top_events' ),
			'user_demographics' => array( 'user_demographics' ),
			'landing_pages'     => array( 'landing_pages' ),
			'engagement'        => array( 'engagement' ),
			'new_vs_returning'  => array( 'new_vs_returning' ),
		);
	}

	public static function page_path_grouped_actions(): array {
		return array(
			'page_stats' => array( 'page_stats' ),
			'engagement' => array( 'engagement' ),
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
}
