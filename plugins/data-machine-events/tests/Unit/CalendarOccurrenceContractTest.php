<?php
/**
 * Canonical calendar occurrence artifact tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DataMachineEvents\Api\Controllers\Calendar;
use DataMachineEvents\Contracts\CalendarOccurrenceArtifact;

/**
 * Verifies deterministic producer serialization and portable pinning.
 */
final class CalendarOccurrenceContractTest extends TestCase {

	/**
	 * Producer regeneration must equal the fixture byte for byte.
	 */
	public function test_checked_in_artifact_matches_byte_stable_producer_output(): void {
		$first  = $this->generate_artifact_bytes();
		$second = $this->generate_artifact_bytes();
		$path   = dirname( __DIR__, 2 ) . '/contracts/calendar-occurrence-v1.json';
		if ( '1' === getenv( 'DME_UPDATE_CONTRACT_FIXTURES' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Explicit fixture regeneration mode.
			file_put_contents( $path, $first );
		}

		$this->assertSame( $first, $second, 'Repeated producer serialization must be byte-stable.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local checked-in artifact.
		$this->assertSame( (string) file_get_contents( $path ), $first );
	}

	/**
	 * Required canonical fields must remain on the actual producer boundary.
	 */
	public function test_artifact_contains_required_canonical_fields(): void {
		$artifact   = json_decode( $this->generate_artifact_bytes(), true, 512, JSON_THROW_ON_ERROR );
		$event      = $artifact['event'];
		$occurrence = $artifact['occurrence'];

		$this->assertSame( 507001, $event['id'] );
		$this->assertSame( 507001, $occurrence['post_id'] );
		$this->assertSame( '2099-07-21', $event['date']['start_date'] );
		$this->assertSame( '19:30:00', $event['date']['start_time'] );
		$this->assertSame( '2099-07-22', $event['date']['end_date'] );
		$this->assertSame( '00:30:00', $event['date']['end_time'] );
		$this->assertSame( 'The Seeded Performers', $event['performer']['name'] );
		$this->assertSame( 'PerformingGroup', $event['performer']['type'] );
		$this->assertSame( 'Seeded Productions', $event['organizer']['name'] );
		$this->assertSame( 'Organization', $event['organizer']['type'] );
		$this->assertSame( 'EventRescheduled', $event['status'] );
		$this->assertSame( 507101, $event['venue']['term_id'] );
		$this->assertSame( 'Seeded Hall', $event['venue']['name'] );
		$this->assertSame( 'seeded-hall', $event['venue']['slug'] );

		foreach ( array( 'artist', 'location', 'promoter' ) as $taxonomy ) {
			$this->assertNotEmpty( $event['taxonomies'][ $taxonomy ] );
			foreach ( $event['taxonomies'][ $taxonomy ] as $term ) {
				$this->assertSame( array( 'term_id', 'name', 'slug' ), array_keys( $term ) );
				$this->assertNotSame( '', $term['name'] );
				$this->assertNotSame( '', $term['slug'] );
			}
		}
		foreach ( array( 'address', 'formatted_address', 'city', 'state', 'zip', 'country', 'coordinates', 'timezone' ) as $field ) {
			$this->assertNotSame( '', $event['venue'][ $field ], $field );
		}
		foreach ( array( 'is_multi_day', 'is_start_day', 'is_end_day', 'is_continuation', 'display_date', 'original_start_date', 'original_end_date', 'day_number', 'total_days' ) as $field ) {
			$this->assertArrayHasKey( $field, $occurrence['display_context'] );
		}
		foreach ( array( 'formatted_time_display', 'multi_day_label', 'iso_start_date', 'venue_name', 'performer_name', 'show_performer', 'show_ticket_link', 'is_continuation', 'is_multi_day' ) as $field ) {
			$this->assertArrayHasKey( $field, $occurrence['display'] );
		}
	}

	/**
	 * Portable pin checks must reject producer version and hash drift.
	 */
	public function test_portable_pin_verification_rejects_version_and_hash_drift(): void {
		$manifest = CalendarOccurrenceArtifact::manifest();

		$this->assertTrue( CalendarOccurrenceArtifact::verify_pin( $manifest['name'], $manifest['version'], $manifest['sha256'] ) );
		$this->assertFalse( CalendarOccurrenceArtifact::verify_pin( $manifest['name'], $manifest['version'] + 1, $manifest['sha256'] ) );
		$this->assertFalse( CalendarOccurrenceArtifact::verify_pin( $manifest['name'], $manifest['version'], str_repeat( '0', 64 ) ) );
	}

	/**
	 * Generate canonical bytes from raw, deterministic producer input.
	 */
	private function generate_artifact_bytes(): string {
		$artifact = ( new Calendar() )->serialize_contract_occurrence( $this->seeded_raw_occurrence() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Portable test intentionally runs without WordPress.
		return json_encode( $artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	}

	/**
	 * Seed the raw occurrence shape emitted by CalendarAbilities.
	 */
	private function seeded_raw_occurrence(): array {
		return array(
			'post_id'         => 507001,
			'title'           => 'Seeded Calendar Contract Event',
			'event_data'      => array(
				'startDate'     => '2099-07-21',
				'startTime'     => '19:30:00',
				'endDate'       => '2099-07-22',
				'endTime'       => '00:30:00',
				'venue'         => 'Seeded Hall',
				'address'       => '100 Fixture Way, Seed City, SC, 29000',
				'venueTimezone' => 'America/New_York',
				'organizer'     => 'Seeded Productions',
				'organizerUrl'  => 'https://producer.invalid',
				'organizerType' => 'Organization',
				'ticketUrl'     => 'https://tickets.invalid/seeded-show',
				'performer'     => 'The Seeded Performers',
				'performerType' => 'PerformingGroup',
				'eventStatus'   => 'EventRescheduled',
			),
			'display_context' => array(
				'is_multi_day'        => true,
				'is_start_day'        => true,
				'is_end_day'          => false,
				'is_continuation'     => false,
				'display_date'        => '2099-07-21',
				'original_start_date' => '2099-07-21',
				'original_end_date'   => '2099-07-22',
				'day_number'          => 1,
				'total_days'          => 1,
			),
		);
	}
}
