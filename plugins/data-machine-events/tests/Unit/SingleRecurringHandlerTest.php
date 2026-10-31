<?php
/**
 * SingleRecurring handler tests.
 *
 * Occurrence calculation and event data building are tested with an
 * injected clock (DateTimeImmutable), never wall-clock time.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\SingleRecurring\SingleRecurring;
use ReflectionClass;
use WP_UnitTestCase;

class SingleRecurringHandlerTest extends WP_UnitTestCase {

	private $handler;

	private $reflection;

	public function setUp(): void {
		parent::setUp();

		$this->handler    = new SingleRecurring();
		$this->reflection = new ReflectionClass( $this->handler );
	}

	/**
	 * Most recent Monday (midnight) in the WP timezone. Deterministic
	 * regardless of the day the test suite runs on.
	 */
	private function monday(): \DateTimeImmutable {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );

		return $today->modify( 'monday this week' );
	}

	private function nextOccurrence( int $day_of_week, string $start_time, \DateTimeImmutable $now ): \DateTime {
		$method = $this->reflection->getMethod( 'calculateNextOccurrence' );
		$method->setAccessible( true );

		return $method->invoke( $this->handler, $day_of_week, $start_time, $now );
	}

	private function buildEventData( array $config, string $event_date ): array {
		$method = $this->reflection->getMethod( 'buildEventData' );
		$method->setAccessible( true );

		return $method->invoke( $this->handler, $config, $event_date );
	}

	public function test_today_counts_before_configured_start_time(): void {
		$monday = $this->monday();
		$now    = $monday->setTime( 8, 0 );

		$next = $this->nextOccurrence( 1, '18:00', $now );

		$this->assertSame( $monday->format( 'Y-m-d' ), $next->format( 'Y-m-d' ) );
	}

	public function test_rolls_to_next_week_after_configured_start_time(): void {
		$monday = $this->monday();
		$now    = $monday->setTime( 20, 0 );

		$next = $this->nextOccurrence( 1, '18:00', $now );

		$this->assertSame( $monday->modify( '+7 days' )->format( 'Y-m-d' ), $next->format( 'Y-m-d' ) );
	}

	public function test_exact_start_time_rolls_to_next_week(): void {
		$monday = $this->monday();
		$now    = $monday->setTime( 18, 0 );

		$next = $this->nextOccurrence( 1, '18:00', $now );

		$this->assertSame( $monday->modify( '+7 days' )->format( 'Y-m-d' ), $next->format( 'Y-m-d' ) );
	}

	public function test_empty_start_time_keeps_today_any_time_of_day(): void {
		$monday = $this->monday();

		foreach ( array( 8, 12, 23 ) as $hour ) {
			$next = $this->nextOccurrence( 1, '', $monday->setTime( $hour, 0 ) );

			$this->assertSame( $monday->format( 'Y-m-d' ), $next->format( 'Y-m-d' ), "Empty start time at {$hour}:00 should keep today" );
		}
	}

	public function test_sunday_emits_tomorrow_for_monday_target(): void {
		$monday = $this->monday();
		$sunday = $monday->modify( '-1 day' )->setTime( 12, 0 );

		$next = $this->nextOccurrence( 1, '18:00', $sunday );

		$this->assertSame( $monday->format( 'Y-m-d' ), $next->format( 'Y-m-d' ) );
	}

	public function test_maps_full_venue_config_to_event_data(): void {
		$data = $this->buildEventData(
			array(
				'event_title'         => 'Test Event',
				'venue_name'          => 'Chico Feo',
				'venue_address'       => '1 Apiary Ln',
				'venue_city'          => 'Folly Beach',
				'venue_state'         => 'South Carolina',
				'venue_zip'           => '29439',
				'venue_country'       => 'US',
				'venue_phone'         => '(843) 906-2710',
				'venue_website'       => 'https://www.chicofeo.com/',
				'venue_ticketing_url' => 'https://www.eventbrite.com/o/example',
				'venue_capacity'      => '150',
			),
			'2026-01-05'
		);

		$this->assertSame( 'Chico Feo', $data['venue'] );
		$this->assertSame( '1 Apiary Ln', $data['venueAddress'] );
		$this->assertSame( 'Folly Beach', $data['venueCity'] );
		$this->assertSame( 'South Carolina', $data['venueState'] );
		$this->assertSame( '29439', $data['venueZip'] );
		$this->assertSame( 'US', $data['venueCountry'] );
		$this->assertSame( '(843) 906-2710', $data['venuePhone'] );
		$this->assertSame( 'https://www.chicofeo.com/', $data['venueWebsite'] );
		$this->assertSame( 'https://www.eventbrite.com/o/example', $data['venueTicketingUrl'] );
		$this->assertSame( '150', $data['venueCapacity'] );
	}

	public function test_overnight_end_time_moves_end_date_to_next_day(): void {
		$data = $this->buildEventData(
			array(
				'event_title' => 'Late Night Set',
				'start_time'  => '21:00',
				'end_time'    => '00:00',
			),
			'2026-01-05'
		);

		$this->assertSame( '2026-01-05', $data['startDate'] );
		$this->assertSame( '2026-01-06', $data['endDate'] );
	}

	public function test_same_day_end_time_keeps_end_date(): void {
		$data = $this->buildEventData(
			array(
				'event_title' => 'Evening Set',
				'start_time'  => '18:00',
				'end_time'    => '22:00',
			),
			'2026-01-05'
		);

		$this->assertSame( '2026-01-05', $data['startDate'] );
		$this->assertSame( '2026-01-05', $data['endDate'] );
	}

	public function test_end_time_without_start_time_keeps_end_date(): void {
		$data = $this->buildEventData(
			array(
				'event_title' => 'End Only',
				'end_time'    => '00:30',
			),
			'2026-01-05'
		);

		$this->assertSame( '2026-01-05', $data['endDate'] );
	}
}
