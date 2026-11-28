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

	/**
	 * Build geocode queries for venue data through the private builder.
	 *
	 * @param array $venue_data Venue data.
	 * @return array<string, string>
	 */
	private function queries_for( array $venue_data ): array {
		$method = new \ReflectionMethod( Venue_Taxonomy::class, 'build_geocode_queries' );
		$method->setAccessible( true );
		return $method->invoke( null, $venue_data );
	}

	public function test_street_containing_unit_word_is_not_truncated(): void {
		$queries = $this->queries_for(
			array(
				'address' => '2548 North Halsted',
				'city'    => 'Chicago',
				'state'   => 'IL',
			)
		);
		$this->assertSame( '2548 North Halsted, Chicago, IL', $queries['cleaned_address'] );

		$queries = $this->queries_for(
			array(
				'address' => '3010 Minnehaha Ave STE 420',
				'city'    => 'Minneapolis',
				'state'   => 'MN',
			)
		);
		$this->assertSame( '3010 Minnehaha Ave, Minneapolis, MN', $queries['cleaned_address'] );

		$queries = $this->queries_for(
			array(
				'address' => '100 Main St #4',
				'city'    => 'Austin',
				'state'   => 'TX',
			)
		);
		$this->assertSame( '100 Main St, Austin, TX', $queries['cleaned_address'] );
	}

	public function test_spelled_ordinals_become_numeric_in_queries(): void {
		$queries = $this->queries_for(
			array(
				'address' => '1200 West Third Avenue',
				'city'    => 'Columbus',
				'state'   => 'OH',
			)
		);
		$this->assertSame( '1200 West 3rd Avenue, Columbus, OH', $queries['cleaned_address'] );

		$this->assertSame( '454 6th Ave', Venue_Taxonomy::numeric_street_ordinals( '454 Sixth Ave' ) );
		$this->assertSame( '2500 East 2nd Street', Venue_Taxonomy::numeric_street_ordinals( '2500 East Second Street' ) );
		$this->assertSame( '12 Third Man Records Way', Venue_Taxonomy::numeric_street_ordinals( '12 Third Man Records Way' ) );
	}

	public function test_periods_and_floor_suffixes_are_stripped(): void {
		$queries = $this->queries_for(
			array(
				'address' => '4802 N. Broadway Ave.',
				'city'    => 'Chicago',
				'state'   => 'IL',
			)
		);
		$this->assertSame( '4802 N Broadway Ave, Chicago, IL', $queries['cleaned_address'] );

		$queries = $this->queries_for(
			array(
				'address' => '1808 West End Ave 2nd Floor',
				'city'    => 'Nashville',
				'state'   => 'TN',
			)
		);
		$this->assertSame( '1808 West End Ave, Nashville, TN', $queries['cleaned_address'] );
	}

	public function test_street_type_fallback_keeps_house_number(): void {
		$queries = $this->queries_for(
			array(
				'name'    => 'Green Mill Jazz Club',
				'address' => '4802 N. Broadway Ave.',
				'city'    => 'Chicago',
				'state'   => 'IL',
			)
		);
		$this->assertSame( '4802 N Broadway, Chicago, IL', $queries['no_street_type'] );
		$this->assertSame(
			array( 'cleaned_address', 'no_street_type', 'name_lookup' ),
			array_values( array_intersect( array_keys( $queries ), array( 'cleaned_address', 'no_street_type', 'name_lookup' ) ) )
		);

		$this->assertSame( '2325 Elm', Venue_Taxonomy::strip_trailing_street_type( '2325 Elm St' ) );
		$this->assertSame( '300 E Ocean', Venue_Taxonomy::strip_trailing_street_type( '300 E Ocean BL' ) );
		$this->assertSame( '', Venue_Taxonomy::strip_trailing_street_type( 'Theater Alley' ) );
		$this->assertSame( '', Venue_Taxonomy::strip_trailing_street_type( '10 Theater Alley' ) );
		$this->assertSame( '', Venue_Taxonomy::strip_trailing_street_type( '12 Way' ) );
	}
}
