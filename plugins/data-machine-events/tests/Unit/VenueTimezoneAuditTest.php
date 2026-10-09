<?php
/**
 * Venue timezone audit classifier tests (#898).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\DateTimeParser;
use DataMachineEvents\Core\VenueTimezoneAudit;
use DataMachineEvents\Core\VenueTimezoneResolver;

class VenueTimezoneAuditTest extends WP_UnitTestCase {

	public function test_matching_zone_is_ok(): void {
		$audit = VenueTimezoneAudit::classify( 'America/New_York', '32.8697,-79.9727', 'US', 'SC' );

		$this->assertSame( VenueTimezoneAudit::OK, $audit['status'] );
		$this->assertFalse( $audit['repairable'] );
	}

	public function test_equivalent_zone_is_ok(): void {
		$audit = VenueTimezoneAudit::classify( 'America/Detroit', '', 'US', 'GA' );

		$this->assertSame( VenueTimezoneAudit::OK, $audit['status'] );
	}

	public function test_wrong_zone_against_single_zone_state_is_repairable_mismatch(): void {
		$audit = VenueTimezoneAudit::classify( 'America/Chicago', '40.4437,-80.0003', 'US', 'PA' );

		$this->assertSame( VenueTimezoneAudit::MISMATCH, $audit['status'] );
		$this->assertSame( 'America/New_York', $audit['expected'] );
		$this->assertTrue( $audit['repairable'] );
	}

	public function test_wrong_zone_against_single_zone_country_is_repairable_mismatch(): void {
		$audit = VenueTimezoneAudit::classify( 'Europe/London', '', 'Netherlands', '' );

		$this->assertSame( VenueTimezoneAudit::MISMATCH, $audit['status'] );
		$this->assertSame( 'Europe/Amsterdam', $audit['expected'] );
		$this->assertTrue( $audit['repairable'] );
	}

	public function test_disagreement_in_split_state_is_review_only(): void {
		$audit = VenueTimezoneAudit::classify( 'America/Chicago', '27.9506,-82.4572', 'US', 'FL' );

		$this->assertSame( VenueTimezoneAudit::REVIEW, $audit['status'] );
		$this->assertFalse( $audit['repairable'] );
	}

	public function test_offset_value_is_invalid_and_repairable_with_exact_region(): void {
		$audit = VenueTimezoneAudit::classify( '-08:00', '32.8697,-79.9727', 'US', 'SC' );

		$this->assertSame( VenueTimezoneAudit::INVALID, $audit['status'] );
		$this->assertSame( 'America/New_York', $audit['expected'] );
		$this->assertTrue( $audit['repairable'] );
	}

	public function test_invalid_value_without_exact_region_is_reported_not_repaired(): void {
		$audit = VenueTimezoneAudit::classify( 'US/Eastern', '38.2554,-85.7487', 'Unites States', 'Ky. 40202' );

		$this->assertSame( VenueTimezoneAudit::INVALID, $audit['status'] );
		$this->assertFalse( $audit['repairable'] );
	}

	public function test_coordinates_outside_region_do_not_drive_expectation(): void {
		// The Buell Theatre, Denver CO, geocoded to Paris.
		$audit = VenueTimezoneAudit::classify( 'Europe/Paris', '48.8295585,2.3239740', 'US', 'CO' );

		$this->assertSame( VenueTimezoneAudit::MISMATCH, $audit['status'] );
		$this->assertSame( 'America/Denver', $audit['expected'] );
		$this->assertTrue( $audit['repairable'] );
	}

	public function test_correct_zone_with_bad_coordinates_is_flagged_for_review(): void {
		$audit = VenueTimezoneAudit::classify( 'America/Denver', '48.8295585,2.3239740', 'US', 'CO' );

		$this->assertSame( VenueTimezoneAudit::COORDINATES_CONFLICT, $audit['status'] );
		$this->assertFalse( $audit['repairable'] );
	}

	public function test_missing_and_unresolvable(): void {
		$this->assertSame( VenueTimezoneAudit::MISSING, VenueTimezoneAudit::classify( '', '', 'US', 'SC' )['status'] );
		$this->assertSame( VenueTimezoneAudit::UNRESOLVABLE, VenueTimezoneAudit::classify( '', '', '', '' )['status'] );
	}

	public function test_resolve_from_region_ignores_split_states(): void {
		$this->assertSame( 'America/New_York', VenueTimezoneResolver::resolveFromRegion( 'US', 'SC' )['timezone'] );
		$this->assertNull( VenueTimezoneResolver::resolveFromRegion( 'US', 'FL' ) );
		$this->assertNull( VenueTimezoneResolver::resolveFromRegion( '', '' ) );
	}

	public function test_resolve_offline_never_calls_network(): void {
		$calls = 0;
		$spy   = static function () use ( &$calls ) {
			++$calls;
			return new \WP_Error( 'blocked', 'blocked' );
		};
		add_filter( 'pre_http_request', $spy );
		$result = VenueTimezoneResolver::resolveOffline( '32.8697,-79.9727', 'US', 'SC' );
		remove_filter( 'pre_http_request', $spy );

		$this->assertSame( 'America/New_York', $result['timezone'] );
		$this->assertSame( 0, $calls );
	}

	public function test_is_valid_timezone_rejects_offsets_and_unloadable_aliases(): void {
		$this->assertFalse( DateTimeParser::isValidTimezone( '-08:00' ) );
		$this->assertFalse( DateTimeParser::isValidTimezone( 'Z' ) );
		$this->assertTrue( DateTimeParser::isValidTimezone( 'America/New_York' ) );

		foreach ( array( 'America/Indianapolis', 'US/Eastern' ) as $alias ) {
			$loadable = true;
			try {
				new \DateTimeZone( $alias );
			} catch ( \Exception $e ) {
				$loadable = false;
			}
			if ( ! $loadable ) {
				$this->assertFalse( DateTimeParser::isValidTimezone( $alias ), "{$alias} cannot be loaded and must not validate." );
			}
		}
	}
}
