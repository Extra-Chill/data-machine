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
}
