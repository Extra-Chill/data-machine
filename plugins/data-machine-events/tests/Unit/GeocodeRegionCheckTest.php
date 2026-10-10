<?php
/**
 * Geocode region check tests (#899).
 *
 * A geocode result must lie in the venue's country (and, for the US, its
 * state). The Buell Theatre (Denver, CO) was geocoded to Paris.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Venue_Taxonomy;

class GeocodeRegionCheckTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $requested_urls = array();

	/** @var callable[] */
	private array $http_filters = array();

	public function tearDown(): void {
		foreach ( $this->http_filters as $filter ) {
			remove_filter( 'pre_http_request', $filter, 10 );
		}
		parent::tearDown();
	}

	public function test_expected_region_from_venue_data(): void {
		$this->assertSame( array( 'country' => 'US', 'state' => 'CO' ), Venue_Taxonomy::geocode_expected_region( array( 'state' => 'CO', 'country' => 'US' ) ) );
		$this->assertSame( array( 'country' => 'US', 'state' => 'SC' ), Venue_Taxonomy::geocode_expected_region( array( 'state' => 'South Carolina' ) ) );
		$this->assertSame( array( 'country' => 'NL', 'state' => '' ), Venue_Taxonomy::geocode_expected_region( array( 'country' => 'Netherlands', 'state' => 'Noord-Holland' ) ) );
		$this->assertSame( array( 'country' => '', 'state' => '' ), Venue_Taxonomy::geocode_expected_region( array( 'city' => 'Somewhere' ) ) );
	}

	public function test_result_outside_country_is_rejected(): void {
		$paris = array( 'country_code' => 'fr', 'region_code' => 'FR-IDF' );

		$this->assertFalse( Venue_Taxonomy::geocode_result_matches_region( $paris, 'US', 'CO' ) );
	}

	public function test_result_in_wrong_us_state_is_rejected(): void {
		$reno = array( 'country_code' => 'us', 'region_code' => 'US-NV' );

		$this->assertFalse( Venue_Taxonomy::geocode_result_matches_region( $reno, 'US', 'TX' ) );
	}

	public function test_matching_or_unknown_region_is_accepted(): void {
		$denver = array( 'country_code' => 'us', 'region_code' => 'US-CO' );

		$this->assertTrue( Venue_Taxonomy::geocode_result_matches_region( $denver, 'US', 'CO' ) );
		$this->assertTrue( Venue_Taxonomy::geocode_result_matches_region( $denver, '', '' ) );
		$this->assertTrue( Venue_Taxonomy::geocode_result_matches_region( array(), 'US', 'CO' ) );
	}

	public function test_query_rejects_out_of_region_result_and_sends_country_filter(): void {
		$this->respond_with( '48.8295585', '2.3239740', 'fr', 'FR-IDF' );

		$this->assertNull( Venue_Taxonomy::query_nominatim( 'Buell Theatre Paris Test ' . uniqid(), 'US', 'CO' ) );
		$this->assertStringContainsString( 'countrycodes=us', $this->requested_urls[0] );
		$this->assertStringContainsString( 'addressdetails=1', $this->requested_urls[0] );
	}

	public function test_query_accepts_in_region_result(): void {
		$this->respond_with( '39.7626227', '-104.9748913', 'us', 'US-CO' );

		$this->assertSame( '39.7626227,-104.9748913', Venue_Taxonomy::query_nominatim( 'Buell Theatre Denver Test ' . uniqid(), 'US', 'CO' ) );
	}

	public function test_query_without_region_sends_no_country_filter(): void {
		$this->respond_with( '39.7626227', '-104.9748913', 'us', 'US-CO' );

		$this->assertNotNull( Venue_Taxonomy::query_nominatim( 'Unscoped Test ' . uniqid() ) );
		$this->assertStringNotContainsString( 'countrycodes', $this->requested_urls[0] );
	}

	public function test_query_is_url_encoded_so_ampersand_does_not_truncate_it(): void {
		$this->respond_with( '39.7626227', '-104.9748913', 'us', 'US-CO' );
		$suffix = uniqid();

		Venue_Taxonomy::query_nominatim( "14th & Curtis Streets #2, Denver, CO {$suffix}", 'US', 'CO' );

		$query = array();
		wp_parse_str( (string) wp_parse_url( $this->requested_urls[0], PHP_URL_QUERY ), $query );
		$this->assertSame( "14th & Curtis Streets #2, Denver, CO {$suffix}", $query['q'] );
	}

	private function respond_with( string $lat, string $lon, string $country_code, string $region ): void {
		$filter = function ( $preempt, $args, $url ) use ( $lat, $lon, $country_code, $region ) {
			if ( ! str_contains( (string) $url, 'nominatim.openstreetmap.org' ) ) {
				return $preempt;
			}
			$this->requested_urls[] = (string) $url;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						array(
							'lat'          => $lat,
							'lon'          => $lon,
							'display_name' => 'Test result',
							'address'      => array(
								'country_code'   => $country_code,
								'ISO3166-2-lvl4' => $region,
							),
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		$this->http_filters[] = $filter;
		add_filter( 'pre_http_request', $filter, 10, 3 );
	}
}
