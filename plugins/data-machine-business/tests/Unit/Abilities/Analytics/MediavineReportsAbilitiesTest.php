<?php
/**
 * Current Mediavine reporting contract tests.
 *
 * @package DataMachine\Tests\Unit\Abilities\Analytics
 */

namespace DataMachine\Tests\Unit\Abilities\Analytics;

use DataMachineBusiness\Abilities\Analytics\MediavineReportsAbilities;
use WP_UnitTestCase;

class MediavineReportsAbilitiesTest extends WP_UnitTestCase {

	public function test_pages_request_uses_current_graphql_contract(): void {
		$body = MediavineReportsAbilities::buildPagesRequestBody( 'global-id', '2026-06-01', '2026-06-30' );

		$this->assertSame( 'PagesSummaryQuery', $body['operationName'] );
		$this->assertStringContainsString( 'GetPagesSummaryInput!', $body['query'] );
		$this->assertStringContainsString( 'pagesSummary(data:$data)', $body['query'] );
		$this->assertSame( 'global-id', $body['variables']['data']['siteId'] );
		$this->assertSame( '2026-06-01T00:00:00.000Z', $body['variables']['data']['startDate'] );
		$this->assertSame( '2026-06-30T23:59:59.999Z', $body['variables']['data']['endDate'] );
		$this->assertSame( 100000, $body['variables']['data']['perPage'] );
	}

	public function test_pages_response_maps_current_fields_to_public_row_contract(): void {
		$response = array(
			'data' => array(
				'pagesSummary' => array(
					'meta'  => array( 'totalCount' => 1 ),
					'pages' => array(
						array(
							'path'                   => '/music/example/',
							'pageviews'              => 1250.0,
							'pageRevenue'            => 18.75,
							'rpm'                    => 15.0,
							'cpm'                    => 2.5,
							'viewability'            => 0.72,
							'fillrate'               => 0.94,
							'impressionsPerPageView' => 4.2,
						),
					),
				),
			),
		);

		$rows = MediavineReportsAbilities::parsePagesResponse( $response, '2026-06' );

		$this->assertFalse( is_wp_error( $rows ) );
		$this->assertSame( '/music/example/', $rows[0]['slug'] );
		$this->assertSame( 1250, $rows[0]['views'] );
		$this->assertSame( 18.75, $rows[0]['revenue'] );
		$this->assertSame( 0.94, $rows[0]['fillRate'] );
		$this->assertSame( 4.2, $rows[0]['impressionsPerPageview'] );
		$this->assertSame( '2026-06', $rows[0]['period'] );
	}

	public function test_empty_pages_response_is_distinct_failure(): void {
		$result = MediavineReportsAbilities::parsePagesResponse(
			array( 'data' => array( 'pagesSummary' => array( 'pages' => array() ) ) ),
			'2026-06'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'mediavine_pages_empty', $result->get_error_code() );
	}

	public function test_summary_query_uses_current_input_type(): void {
		$source = file_get_contents( dirname( __DIR__, 4 ) . '/inc/Abilities/Analytics/MediavineReportsAbilities.php' );

		$this->assertStringContainsString( 'GetMetricsSummaryInput!', $source );
		$this->assertStringNotContainsString( '$data: MetricsSummaryInput!', $source );
	}

	/**
	 * Provenance must preserve the requested site id and a decoded numeric
	 * internal id (relay-encoded "InternalSite:<n>" -> "<n>").
	 */
	public function test_decode_internal_site_id_from_relay_and_numeric(): void {
		$this->assertSame( '11476', MediavineReportsAbilities::decodeInternalSiteId( '11476' ) );
		$this->assertSame( '11476', MediavineReportsAbilities::decodeInternalSiteId( base64_encode( 'InternalSite:11476' ) ) );
		$this->assertNull( MediavineReportsAbilities::decodeInternalSiteId( '' ) );
		$this->assertNull( MediavineReportsAbilities::decodeInternalSiteId( 'legacy-slug' ) );
	}

	/**
	 * Provenance carries the requested + canonical report period so downstream
	 * consumers know exactly what window was asked for and what the upstream
	 * reported (the canonical boundary comes from the upstream ReportMeta).
	 */
	public function test_provenance_carries_requested_and_canonical_period_boundaries(): void {
		$relay  = base64_encode( 'InternalSite:11476' );
		$meta   = array( 'reportStart' => '2026/06/01', 'reportEnd' => '2026/06/30', 'totalCount' => 208 );
		$prov   = MediavineReportsAbilities::buildProvenance( 'pages', 'PagesSummaryQuery', '11476', $relay, '2026-06-01', '2026-06-30', $meta );

		$this->assertSame( 'datamachine/mediavine-reports', $prov['source']['ability'] );
		$this->assertSame( 'pages', $prov['source']['action'] );
		$this->assertSame( 'PagesSummaryQuery', $prov['source']['operation'] );

		$this->assertSame( '11476', $prov['site']['requested_id'] );
		$this->assertSame( $relay, $prov['site']['relay_id'] );
		$this->assertSame( '11476', $prov['site']['internal_id'] );

		$this->assertSame( '2026-06-01', $prov['period']['requested']['start'] );
		$this->assertSame( '2026-06-30', $prov['period']['requested']['end'] );
		$this->assertSame( '2026/06/01', $prov['period']['canonical']['start'] );
		$this->assertSame( '2026/06/30', $prov['period']['canonical']['end'] );
		$this->assertSame( 208, $prov['period']['row_count'] );
	}

	public function test_provenance_canonical_period_is_null_when_upstream_omits_meta(): void {
		$relay = base64_encode( 'InternalSite:11476' );
		$prov  = MediavineReportsAbilities::buildProvenance( 'backfill', 'PagesSummaryQuery', '11476', $relay, '2026-01-01', '2026-03-31', array() );

		$this->assertNull( $prov['period']['canonical']['start'] );
		$this->assertNull( $prov['period']['canonical']['end'] );
		$this->assertNull( $prov['period']['row_count'] );
	}

	/**
	 * The upstream PageReport type exposes path only — no hostname/domain.
	 * Provenance must encode that as structured "unavailable", never synthesize.
	 */
	public function test_provenance_reports_host_attribution_unavailable(): void {
		$relay = base64_encode( 'InternalSite:11476' );
		$prov  = MediavineReportsAbilities::buildProvenance( 'pages', 'PagesSummaryQuery', '11476', $relay, '2026-06-01', '2026-06-30', array() );

		$this->assertFalse( $prov['host_attribution']['available'] );
		$this->assertNotEmpty( $prov['host_attribution']['reason'] );
		// The reason must trace the limitation to the actual upstream type, not
		// an Extra Chill assumption.
		$this->assertStringContainsString( 'PageReport', $prov['host_attribution']['reason'] );
		$this->assertStringNotContainsString( 'extrachill', strtolower( $prov['host_attribution']['reason'] ) );
	}

	/**
	 * parsePagesPayload returns rows PLUS the upstream meta (canonical period),
	 * while keeping the established row contract additive-only (no synthetic
	 * host field is injected onto a row).
	 */
	public function test_parse_pages_payload_returns_rows_and_canonical_meta(): void {
		$response = array(
			'data' => array(
				'pagesSummary' => array(
					'meta'  => array( 'totalCount' => 1, 'reportStart' => '2026/06/01', 'reportEnd' => '2026/06/30' ),
					'pages' => array(
						array(
							'path'                   => '/music/example/',
							'pageviews'              => 1250.0,
							'pageRevenue'            => 18.75,
							'rpm'                    => 15.0,
							'cpm'                    => 2.5,
							'viewability'            => 0.72,
							'fillrate'               => 0.94,
							'impressionsPerPageView' => 4.2,
						),
					),
				),
			),
		);

		$parsed = MediavineReportsAbilities::parsePagesPayload( $response, '2026-06' );

		$this->assertIsArray( $parsed );
		$this->assertSame( '2026/06/01', $parsed['meta']['reportStart'] );
		$this->assertSame( '2026/06/30', $parsed['meta']['reportEnd'] );
		$this->assertSame( 1, $parsed['meta']['totalCount'] );

		// Row contract unchanged.
		$this->assertSame( '/music/example/', $parsed['rows'][0]['slug'] );
		$this->assertSame( 1250, $parsed['rows'][0]['views'] );
		$this->assertSame( 0.94, $parsed['rows'][0]['fillRate'] );
		$this->assertSame( '2026-06', $parsed['rows'][0]['period'] );
		// No synthetic host attribution is ever written onto a row.
		$this->assertArrayNotHasKey( 'hostname', $parsed['rows'][0] );
		$this->assertArrayNotHasKey( 'canonicalUrl', $parsed['rows'][0] );
		$this->assertArrayNotHasKey( 'domain', $parsed['rows'][0] );
	}

	public function test_pages_result_includes_provenance_and_preserves_site_id(): void {
		$relay  = base64_encode( 'InternalSite:11476' );
		$parsed = array(
			'rows' => array( array( 'slug' => '/x/', 'period' => '2026-06' ) ),
			'meta' => array( 'reportStart' => '2026/06/01', 'reportEnd' => '2026/06/30', 'totalCount' => 1 ),
		);

		$result = MediavineReportsAbilities::buildPagesResult( '11476', $relay, '2026-06-01', '2026-06-30', '2026-06', $parsed );

		$this->assertSame( 'pages', $result['action'] );
		$this->assertSame( $relay, $result['site_id'] );
		$this->assertSame( 1, $result['results_count'] );
		$this->assertArrayHasKey( 'provenance', $result );
		$this->assertSame( '11476', $result['provenance']['site']['requested_id'] );
		$this->assertSame( $relay, $result['provenance']['site']['relay_id'] );
		$this->assertSame( '11476', $result['provenance']['site']['internal_id'] );
		$this->assertSame( '2026/06/01', $result['provenance']['period']['canonical']['start'] );
		$this->assertFalse( $result['provenance']['host_attribution']['available'] );
	}

	public function test_summary_result_includes_provenance_and_canonical_meta_query(): void {
		$relay  = base64_encode( 'InternalSite:11476' );
		$srow   = array( 'period' => '2026-06', 'earnings' => 1.0 );
		$meta   = array( 'reportStart' => '2026/06/01', 'reportEnd' => '2026/06/30', 'totalCount' => null );
		$result = MediavineReportsAbilities::buildSummaryResult( '11476', $relay, '2026-06-01', '2026-06-30', $srow, $meta );

		$this->assertSame( 'summary', $result['action'] );
		$this->assertArrayHasKey( 'provenance', $result );
		$this->assertSame( 'MetricsSummaryQuery', $result['provenance']['source']['operation'] );
		$this->assertSame( '2026/06/01', $result['provenance']['period']['canonical']['start'] );
		$this->assertStringContainsString( 'site-level aggregate', $result['provenance']['host_attribution']['reason'] );
		$this->assertStringNotContainsString( 'PageReport', $result['provenance']['host_attribution']['reason'] );

		// The summary GraphQL query must request the canonical meta block too.
		$source = file_get_contents( dirname( __DIR__, 4 ) . '/inc/Abilities/Analytics/MediavineReportsAbilities.php' );
		$this->assertStringContainsString( 'metricsSummary(data:$data){ meta{ totalCount reportStart reportEnd }', $source );
	}

	public function test_backfill_result_and_each_period_summary_carry_provenance(): void {
		$relay = base64_encode( 'InternalSite:11476' );
		$meta  = array( 'reportStart' => '2026/05/01', 'reportEnd' => '2026/05/31', 'totalCount' => 12 );

		$period_one = MediavineReportsAbilities::buildBackfillPeriodSummary( '11476', $relay, '2026-05', '2026-05-01', '2026-05-31', 12, $meta );
		$period_two = MediavineReportsAbilities::buildBackfillPeriodSummary( '11476', $relay, '2026-06', '2026-06-01', '2026-06-30', 7, $meta );

		$this->assertArrayHasKey( 'provenance', $period_one );
		$this->assertArrayHasKey( 'provenance', $period_two );
		$this->assertSame( '2026-05-01', $period_one['provenance']['period']['requested']['start'] );
		$this->assertSame( 'backfill', $period_one['provenance']['source']['action'] );
		$this->assertFalse( $period_two['provenance']['host_attribution']['available'] );

		$result = MediavineReportsAbilities::buildBackfillResult( '11476', $relay, array( $period_one, $period_two ), array_fill( 0, 19, array( 'slug' => '/x/' ) ) );

		$this->assertSame( 'backfill', $result['action'] );
		$this->assertSame( 19, $result['results_count'] );
		$this->assertArrayHasKey( 'provenance', $result );
		// Top-level provenance spans the full requested window across periods.
		$this->assertSame( '2026-05-01', $result['provenance']['period']['requested']['start'] );
		$this->assertSame( '2026-06-30', $result['provenance']['period']['requested']['end'] );
	}

	public function test_backfill_period_summary_records_error_without_losing_provenance(): void {
		$relay   = base64_encode( 'InternalSite:11476' );
		$summary = MediavineReportsAbilities::buildBackfillPeriodSummary( '11476', $relay, '2026-05', '2026-05-01', '2026-05-31', 0, array(), 'upstream failure' );

		$this->assertSame( 0, $summary['rows'] );
		$this->assertSame( 'upstream failure', $summary['error'] );
		// Provenance still records what was requested even on failure.
		$this->assertArrayHasKey( 'provenance', $summary );
		$this->assertSame( '11476', $summary['provenance']['site']['internal_id'] );
	}

	/**
	 * No credentials/tokens ever leak into a result or provenance block.
	 */
	public function test_no_credentials_leak_in_outputs(): void {
		$relay  = base64_encode( 'InternalSite:11476' );
		$parsed = array(
			'rows' => array( array( 'slug' => '/x/', 'period' => '2026-06' ) ),
			'meta' => array( 'reportStart' => '2026/06/01', 'reportEnd' => '2026/06/30', 'totalCount' => 1 ),
		);

		$blob  = wp_json_encode( MediavineReportsAbilities::buildPagesResult( '11476', $relay, '2026-06-01', '2026-06-30', '2026-06', $parsed ) );
		$blob .= wp_json_encode( MediavineReportsAbilities::buildSummaryResult( '11476', $relay, '2026-06-01', '2026-06-30', array( 'period' => '2026-06' ), $parsed['meta'] ) );
		$blob .= wp_json_encode( MediavineReportsAbilities::buildBackfillResult( '11476', $relay, array(), array() ) );
		$blob .= wp_json_encode( MediavineReportsAbilities::buildProvenance( 'pages', 'PagesSummaryQuery', '11476', $relay, '2026-06-01', '2026-06-30', $parsed['meta'] ) );

		$this->assertStringNotContainsString( 'password', $blob );
		$this->assertStringNotContainsString( 'Bearer ', $blob );
		$this->assertStringNotContainsString( 'accessToken', $blob );
		$this->assertStringNotContainsString( 'refreshToken', $blob );
	}

	public function test_registered_ability_schema_fully_describes_result_and_period_items(): void {
		// Use the static schema directly so this contract test runs in any
		// environment. It does not depend on the data-machine dependency being
		// active to register the ability (the test bootstrap loads this plugin
		// alone); outputSchema() is exactly what the ability registers.
		$schema = MediavineReportsAbilities::outputSchema();

		$this->assertArrayHasKey( 'site_id', $schema['properties'] );
		$this->assertArrayHasKey( 'date_range', $schema['properties'] );
		$this->assertArrayHasKey( 'properties', $schema['properties']['results']['items'] );
		$this->assertArrayHasKey( 'slug', $schema['properties']['results']['items']['properties'] );
		$this->assertArrayHasKey( 'earnings', $schema['properties']['results']['items']['properties'] );
		$this->assertArrayHasKey( 'properties', $schema['properties']['periods']['items'] );
		$this->assertArrayHasKey( 'provenance', $schema['properties']['periods']['items']['properties'] );
		$this->assertSame( array( 'string', 'null' ), $schema['properties']['provenance']['properties']['site']['properties']['internal_id']['type'] );
		$this->assertSame( array( 'integer', 'null' ), $schema['properties']['provenance']['properties']['period']['properties']['row_count']['type'] );
	}

	public function test_registered_ability_schema_validates_all_action_outputs_with_omitted_metadata(): void {
		// Dependency-free: validate the actual builder outputs (the same
		// structures execute() returns) against the registered schema, with
		// upstream metadata omitted so provenance canonical dates/row_count are
		// null. This is the merge-safety gate.
		$schema = MediavineReportsAbilities::outputSchema();
		$relay  = base64_encode( 'InternalSite:11476' );
		$row    = array(
			'slug'                   => '/music/example/',
			'views'                  => 1250,
			'revenue'                => 18.75,
			'rpm'                    => 15.0,
			'cpm'                    => 2.5,
			'viewability'            => 0.72,
			'fillRate'               => 0.94,
			'impressionsPerPageview' => 4.2,
			'period'                 => '2026-06',
		);
		$summary_row = array(
			'period'          => '2026-06',
			'earnings'        => 18.75,
			'pageviews'       => 1250,
			'sessions'        => 900,
			'cpm'             => 2.5,
			'sessionRpm'      => 20.83,
			'pageRpm'         => 15.0,
			'paidImpressions' => 7500,
		);

		$pages  = MediavineReportsAbilities::buildPagesResult( '11476', $relay, '2026-06-01', '2026-06-30', '2026-06', array( 'rows' => array( $row ) ) );
		$summary = MediavineReportsAbilities::buildSummaryResult( '11476', $relay, '2026-06-01', '2026-06-30', $summary_row );
		$period  = MediavineReportsAbilities::buildBackfillPeriodSummary( '11476', $relay, '2026-06', '2026-06-01', '2026-06-30', 1 );
		$backfill = MediavineReportsAbilities::buildBackfillResult( '11476', $relay, array( $period ), array( $row ) );

		foreach ( array( 'pages' => $pages, 'summary' => $summary, 'backfill' => $backfill ) as $action => $output ) {
			$validated = rest_validate_value_from_schema( $output, $schema, 'output' );
			$this->assertTrue( $validated, $action . ' output failed registered schema validation: ' . ( is_wp_error( $validated ) ? $validated->get_error_message() : '' ) );
			$this->assertNull( $output['provenance']['period']['canonical']['start'] );
			$this->assertNull( $output['provenance']['period']['canonical']['end'] );
			$this->assertNull( $output['provenance']['period']['row_count'] );
		}
	}

	public function test_registered_ability_schema_validates_explicit_null_metadata_for_all_actions(): void {
		// Dependency-free: explicit null upstream metadata must still validate,
		// proving the schema declares the nullable form the upstream can return.
		$schema = MediavineReportsAbilities::outputSchema();
		$relay  = base64_encode( 'InternalSite:11476' );
		$meta   = MediavineReportsAbilities::normalizeReportMeta(
			array(
				'totalCount'  => null,
				'reportStart' => null,
				'reportEnd'   => null,
			)
		);
		$row    = array( 'slug' => '/music/example/', 'period' => '2026-06' );

		$pages    = MediavineReportsAbilities::buildPagesResult( '11476', $relay, '2026-06-01', '2026-06-30', '2026-06', array( 'rows' => array( $row ), 'meta' => $meta ) );
		$summary  = MediavineReportsAbilities::buildSummaryResult( '11476', $relay, '2026-06-01', '2026-06-30', array( 'period' => '2026-06' ), $meta );
		$period   = MediavineReportsAbilities::buildBackfillPeriodSummary( '11476', $relay, '2026-06', '2026-06-01', '2026-06-30', 1, $meta );
		$backfill = MediavineReportsAbilities::buildBackfillResult( '11476', $relay, array( $period ), array( $row ) );

		foreach ( array( 'pages' => $pages, 'summary' => $summary, 'backfill' => $backfill ) as $action => $output ) {
			$validated = rest_validate_value_from_schema( $output, $schema, 'output' );
			$this->assertTrue( $validated, $action . ' output failed registered schema validation: ' . ( is_wp_error( $validated ) ? $validated->get_error_message() : '' ) );
		}

		foreach ( array( $pages['provenance'], $summary['provenance'], $backfill['periods'][0]['provenance'] ) as $provenance ) {
			$this->assertNull( $provenance['period']['canonical']['start'] );
			$this->assertNull( $provenance['period']['canonical']['end'] );
			$this->assertNull( $provenance['period']['row_count'] );
		}
	}

	/**
	 * Dependency-free schema gate for backfill mixing a successful period
	 * (canonical metadata present) and a failed period (transport error,
	 * omitted metadata). Both period summaries must validate against the
	 * registered periods-items schema and carry provenance; the failed period
	 * carries its error plus null canonical provenance.
	 */
	public function test_backfill_validates_with_successful_and_failed_periods_omitted_metadata(): void {
		$schema         = MediavineReportsAbilities::outputSchema();
		$periods_schema = $schema['properties']['periods']['items'];
		$relay          = base64_encode( 'InternalSite:11476' );

		$meta_ok = MediavineReportsAbilities::normalizeReportMeta(
			array(
				'totalCount'  => 12,
				'reportStart' => '2026/05/01',
				'reportEnd'   => '2026/05/31',
			)
		);

		$success_period = MediavineReportsAbilities::buildBackfillPeriodSummary( '11476', $relay, '2026-05', '2026-05-01', '2026-05-31', 2, $meta_ok );
		$failed_period  = MediavineReportsAbilities::buildBackfillPeriodSummary( '11476', $relay, '2026-06', '2026-06-01', '2026-06-30', 0, array(), 'upstream failure' );
		$backfill       = MediavineReportsAbilities::buildBackfillResult( '11476', $relay, array( $success_period, $failed_period ), array() );

		// Whole backfill response validates.
		$validated = rest_validate_value_from_schema( $backfill, $schema, 'output' );
		$this->assertTrue( $validated, 'backfill output failed schema validation: ' . ( is_wp_error( $validated ) ? $validated->get_error_message() : '' ) );

		// Each period summary (incl. the failed one) validates against the items schema.
		$ok = rest_validate_value_from_schema( $success_period, $periods_schema, 'period[ok]' );
		$this->assertTrue( $ok, 'successful period failed schema validation: ' . ( is_wp_error( $ok ) ? $ok->get_error_message() : '' ) );
		$err = rest_validate_value_from_schema( $failed_period, $periods_schema, 'period[fail]' );
		$this->assertTrue( $err, 'failed period failed schema validation: ' . ( is_wp_error( $err ) ? $err->get_error_message() : '' ) );

		// Successful period: canonical provenance preserved, action stays backfill.
		$this->assertSame( 'backfill', $success_period['provenance']['source']['action'] );
		$this->assertSame( '2026/05/01', $success_period['provenance']['period']['canonical']['start'] );
		$this->assertSame( 12, $success_period['provenance']['period']['row_count'] );
		$this->assertArrayNotHasKey( 'error', $success_period );

		// Failed period: error carried, canonical provenance null (omitted metadata).
		$this->assertSame( 'backfill', $failed_period['provenance']['source']['action'] );
		$this->assertNull( $failed_period['provenance']['period']['canonical']['start'] );
		$this->assertNull( $failed_period['provenance']['period']['row_count'] );
		$this->assertSame( 'upstream failure', $failed_period['error'] );

		// Top-level provenance spans the full requested window with null canonical metadata.
		$this->assertSame( '2026-05-01', $backfill['provenance']['period']['requested']['start'] );
		$this->assertSame( '2026-06-30', $backfill['provenance']['period']['requested']['end'] );
	}

	/**
	 * Layer purity: the generic ability source contains no Extra Chill
	 * domain/route special cases.
	 */
	public function test_ability_source_has_no_extra_chill_special_cases(): void {
		$source = file_get_contents( dirname( __DIR__, 4 ) . '/inc/Abilities/Analytics/MediavineReportsAbilities.php' );

		$this->assertDoesNotMatchRegularExpression( '/extrachill\.com|community\.extra|events\.extra|wire\.extra|artist\.extra/i', $source );
	}
}
