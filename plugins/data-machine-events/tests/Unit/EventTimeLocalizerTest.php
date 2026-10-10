<?php
/**
 * Event time localization tests (#905).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\EventTimeLocalizer;
use DataMachineEvents\Core\Venue_Taxonomy;

class EventTimeLocalizerTest extends WP_UnitTestCase {

	public function test_utc_time_is_converted_to_venue_local(): void {
		// Harvelle's Long Beach publishes 2026-10-12T03:00:00Z (Oct 11, 8 PM PDT).
		$event = EventTimeLocalizer::localize(
			array(
				'startDate'   => '2026-10-12',
				'startTime'   => '03:00',
				'startOffset' => '+00:00',
			),
			'America/Los_Angeles'
		);

		$this->assertSame( '2026-10-11', $event['startDate'] );
		$this->assertSame( '20:00', $event['startTime'] );
		$this->assertArrayNotHasKey( 'startOffset', $event );
	}

	public function test_matching_offset_is_unchanged(): void {
		$event = EventTimeLocalizer::localize(
			array(
				'startDate'   => '2026-10-24',
				'startTime'   => '19:00',
				'startOffset' => '-04:00',
			),
			'America/New_York'
		);

		$this->assertSame( '2026-10-24', $event['startDate'] );
		$this->assertSame( '19:00', $event['startTime'] );
	}

	public function test_end_time_is_converted(): void {
		$event = EventTimeLocalizer::localize(
			array(
				'startDate' => '2026-10-11',
				'startTime' => '20:00',
				'endDate'   => '2026-10-12',
				'endTime'   => '06:00',
				'endOffset' => '+00:00',
			),
			'America/Los_Angeles'
		);

		$this->assertSame( '2026-10-11', $event['endDate'] );
		$this->assertSame( '23:00', $event['endTime'] );
		$this->assertArrayNotHasKey( 'endOffset', $event );
	}

	public function test_floating_time_and_unknown_venue_zone_keep_source_wall_clock(): void {
		$floating = EventTimeLocalizer::localize(
			array(
				'startDate'   => '2026-10-12',
				'startTime'   => '03:00',
				'startOffset' => '',
			),
			'America/Los_Angeles'
		);
		$unknown  = EventTimeLocalizer::localize(
			array(
				'startDate'   => '2026-10-12',
				'startTime'   => '03:00',
				'startOffset' => '+00:00',
			),
			''
		);

		$this->assertSame( '03:00', $floating['startTime'] );
		$this->assertSame( '2026-10-12', $unknown['startDate'] );
		$this->assertSame( '03:00', $unknown['startTime'] );
		$this->assertArrayNotHasKey( 'startOffset', $unknown );
	}

	public function test_date_only_event_is_not_converted(): void {
		$event = EventTimeLocalizer::localize(
			array(
				'startDate'   => '2026-10-12',
				'startTime'   => '',
				'startOffset' => '+00:00',
			),
			'America/Los_Angeles'
		);

		$this->assertSame( '2026-10-12', $event['startDate'] );
		$this->assertSame( '', $event['startTime'] );
	}

	public function test_venue_timezone_prefers_configured_venue_term(): void {
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		$term = wp_insert_term( 'Localizer Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term );
		update_term_meta( $term['term_id'], '_venue_timezone', 'America/Denver' );

		$zone = EventTimeLocalizer::venueTimezone( array( 'venueState' => 'CA' ), array( 'venue' => $term['term_id'] ) );
		wp_delete_term( $term['term_id'], 'venue' );

		$this->assertSame( 'America/Denver', $zone );
	}

	public function test_venue_timezone_from_location_then_hint(): void {
		$this->assertSame( 'America/Los_Angeles', EventTimeLocalizer::venueTimezone( array( 'venueState' => 'CA', 'venueCountry' => 'US' ), array() ) );
		$this->assertSame( 'Europe/Berlin', EventTimeLocalizer::venueTimezone( array( 'venueTimezone' => 'Europe/Berlin' ), array() ) );
		$this->assertSame( '', EventTimeLocalizer::venueTimezone( array( 'venueTimezone' => '-07:00' ), array() ) );
	}
}
