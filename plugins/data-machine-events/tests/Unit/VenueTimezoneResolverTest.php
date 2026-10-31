<?php
/**
 * VenueTimezoneResolver Tests
 *
 * Covers the offline fallback chain that lets venues publish on sites
 * without GeoNames configured. See #766.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\VenueTimezoneResolver;

class VenueTimezoneResolverTest extends WP_UnitTestCase {

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public function resolution_cases(): array {
		return array(
			// Single-zone US states are exact regardless of coordinates.
			'SC by code'              => array( '32.6557789,-79.9402464', 'US', 'SC', 'America/New_York', VenueTimezoneResolver::SOURCE_US_STATE ),
			'SC by full name'         => array( '', 'US', 'South Carolina', 'America/New_York', VenueTimezoneResolver::SOURCE_US_STATE ),
			'SC infers US country'    => array( '', '', 'SC', 'America/New_York', VenueTimezoneResolver::SOURCE_US_STATE ),
			'US by full country name' => array( '', 'United States', 'GA', 'America/New_York', VenueTimezoneResolver::SOURCE_US_STATE ),
			'AZ has no DST split'     => array( '33.4484,-112.0740', 'US', 'AZ', 'America/Phoenix', VenueTimezoneResolver::SOURCE_US_STATE ),

			// Split states resolve by coordinate boundary.
			'Austin TX'               => array( '30.2672,-97.7431', 'US', 'TX', 'America/Chicago', VenueTimezoneResolver::SOURCE_US_STATE_REGION ),
			'El Paso TX'              => array( '31.7619,-106.4850', 'US', 'TX', 'America/Denver', VenueTimezoneResolver::SOURCE_US_STATE_REGION ),
			'Miami FL'                => array( '25.7617,-80.1918', 'US', 'FL', 'America/New_York', VenueTimezoneResolver::SOURCE_US_STATE_REGION ),
			'Pensacola FL'            => array( '30.4213,-87.2169', 'US', 'FL', 'America/Chicago', VenueTimezoneResolver::SOURCE_US_STATE_REGION ),
			'Nashville TN'            => array( '36.1627,-86.7816', 'US', 'TN', 'America/Chicago', VenueTimezoneResolver::SOURCE_US_STATE_REGION ),
			'Knoxville TN'            => array( '35.9606,-83.9207', 'US', 'TN', 'America/New_York', VenueTimezoneResolver::SOURCE_US_STATE_REGION ),
			'Louisville KY'           => array( '38.2527,-85.7585', 'US', 'KY', 'America/New_York', VenueTimezoneResolver::SOURCE_US_STATE_REGION ),
			'Gary IN'                 => array( '41.5934,-87.3464', 'US', 'IN', 'America/Chicago', VenueTimezoneResolver::SOURCE_US_STATE_REGION ),
			'Indianapolis IN'         => array( '39.7684,-86.1581', 'US', 'IN', 'America/Indiana/Indianapolis', VenueTimezoneResolver::SOURCE_US_STATE_REGION ),
			'Split state, no coords'  => array( '', 'US', 'TX', 'America/Chicago', VenueTimezoneResolver::SOURCE_US_STATE_REGION ),

			// US with coordinates but no usable state.
			'US coords only, Chicago' => array( '41.8781,-87.6298', 'US', '', 'America/Chicago', VenueTimezoneResolver::SOURCE_US_LONGITUDE ),
			'US coords only, Denver'  => array( '39.7392,-104.9903', 'US', '', 'America/Denver', VenueTimezoneResolver::SOURCE_US_LONGITUDE ),

			// Single-zone countries are exact.
			'London'                  => array( '51.5074,-0.1278', 'GB', '', 'Europe/London', VenueTimezoneResolver::SOURCE_COUNTRY ),
			'UK alias'                => array( '', 'United Kingdom', '', 'Europe/London', VenueTimezoneResolver::SOURCE_COUNTRY ),
			'Tokyo'                   => array( '35.6762,139.6503', 'JP', '', 'Asia/Tokyo', VenueTimezoneResolver::SOURCE_COUNTRY ),

			// Multi-zone non-US countries use nearest zone.
			'Toronto'                 => array( '43.6532,-79.3832', 'CA', 'ON', 'America/Toronto', VenueTimezoneResolver::SOURCE_NEAREST ),
			'Vancouver'               => array( '49.2827,-123.1207', 'CA', 'BC', 'America/Vancouver', VenueTimezoneResolver::SOURCE_NEAREST ),
			'Sydney'                  => array( '-33.8688,151.2093', 'AU', '', 'Australia/Sydney', VenueTimezoneResolver::SOURCE_NEAREST ),

			// No country at all falls back to global nearest.
			'Paris no country'        => array( '48.8566,2.3522', '', '', 'Europe/Paris', VenueTimezoneResolver::SOURCE_NEAREST_GLOBAL ),
		);
	}

	/**
	 * @dataProvider resolution_cases
	 */
	public function test_resolves_expected_zone( string $coords, string $country, string $state, string $expected_tz, string $expected_source ): void {
		$result = VenueTimezoneResolver::resolve( $coords, $country, $state );

		$this->assertNotNull( $result, 'Expected a resolution' );
		$this->assertSame( $expected_tz, $result['timezone'] );
		$this->assertSame( $expected_source, $result['source'] );
	}

	public function test_returns_null_with_no_evidence(): void {
		$this->assertNull( VenueTimezoneResolver::resolve( '', '', '' ) );
		$this->assertNull( VenueTimezoneResolver::resolve( 'garbage', '', '' ) );
	}

	public function test_garbage_coordinates_do_not_block_state_rule(): void {
		$result = VenueTimezoneResolver::resolve( 'garbage', 'US', 'SC' );
		$this->assertSame( 'America/New_York', $result['timezone'] );
	}

	public function test_exact_source_classification(): void {
		$this->assertTrue( VenueTimezoneResolver::isExactSource( VenueTimezoneResolver::SOURCE_GEONAMES ) );
		$this->assertTrue( VenueTimezoneResolver::isExactSource( VenueTimezoneResolver::SOURCE_COUNTRY ) );
		$this->assertTrue( VenueTimezoneResolver::isExactSource( VenueTimezoneResolver::SOURCE_US_STATE ) );
		$this->assertFalse( VenueTimezoneResolver::isExactSource( VenueTimezoneResolver::SOURCE_US_STATE_REGION ) );
		$this->assertFalse( VenueTimezoneResolver::isExactSource( VenueTimezoneResolver::SOURCE_US_LONGITUDE ) );
		$this->assertFalse( VenueTimezoneResolver::isExactSource( VenueTimezoneResolver::SOURCE_NEAREST ) );
		$this->assertFalse( VenueTimezoneResolver::isExactSource( VenueTimezoneResolver::SOURCE_NEAREST_GLOBAL ) );
	}

	public function test_every_resolved_zone_is_a_valid_identifier(): void {
		$valid = \DateTimeZone::listIdentifiers( \DateTimeZone::ALL_WITH_BC );
		foreach ( $this->resolution_cases() as $label => $case ) {
			$result = VenueTimezoneResolver::resolve( $case[0], $case[1], $case[2] );
			$this->assertContains( $result['timezone'], $valid, $label );
		}
	}

	public function test_resolve_for_venue_reads_term_meta(): void {
		$term = wp_insert_term( 'Resolver Test Venue ' . wp_generate_password( 6, false ), 'venue' );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];

		update_term_meta( $term_id, '_venue_country', 'US' );
		update_term_meta( $term_id, '_venue_state', 'SC' );

		$result = VenueTimezoneResolver::resolveForVenue( $term_id );
		$this->assertSame( 'America/New_York', $result['timezone'] );

		wp_delete_term( $term_id, 'venue' );
	}
}
