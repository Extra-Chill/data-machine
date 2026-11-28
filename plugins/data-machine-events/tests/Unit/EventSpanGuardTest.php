<?php
/**
 * EventSpanGuard tests.
 *
 * Covers the maximum-plausible-occurrence-span policy introduced for
 * issue #199: series-range ends leaking onto single occurrences.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.64.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\EventSpanGuard;
use WP_UnitTestCase;

class EventSpanGuardTest extends WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_max_event_span_hours' );
		parent::tearDown();
	}

	public function test_default_max_span_hours_is_14_days(): void {
		$this->assertSame( 336, EventSpanGuard::max_span_hours() );
	}

	public function test_max_span_hours_is_filterable(): void {
		add_filter( 'data_machine_events_max_event_span_hours', static fn() => 72 );

		$this->assertSame( 72, EventSpanGuard::max_span_hours() );
	}

	public function test_max_span_hours_falls_back_to_default_below_one(): void {
		add_filter( 'data_machine_events_max_event_span_hours', static fn() => 0 );

		$this->assertSame( 336, EventSpanGuard::max_span_hours() );
	}

	public function test_plausible_within_default_threshold(): void {
		$this->assertTrue( EventSpanGuard::is_end_plausible( '2026-09-12 19:30:00', '2026-09-12 23:00:00' ) );
		$this->assertTrue( EventSpanGuard::is_end_plausible( '2026-09-12 19:30:00', '2026-09-15 19:30:00' ) );
		$this->assertTrue( EventSpanGuard::is_end_plausible( '2026-09-12 19:30:00', '2026-09-26 19:29:00' ) );
	}

	public function test_series_range_end_exceeds_default_threshold(): void {
		// The measured production leak from issue #199: 281 days.
		$this->assertFalse( EventSpanGuard::is_end_plausible( '2026-09-10 20:00:00', '2027-06-18 21:00:00' ) );
		// The Sorry Elliot case: 160 hours — inside the 336h write-guard
		// default, but triaged by the 48h quality-rule threshold.
		$this->assertTrue( EventSpanGuard::is_end_plausible( '2026-09-12 19:30:00', '2026-09-19 11:30:00' ) );
		$this->assertFalse( EventSpanGuard::is_end_plausible( '2026-09-12 19:30:00', '2026-09-19 11:30:00', 48 ) );
	}

	public function test_explicit_threshold_override_is_respected(): void {
		$this->assertTrue( EventSpanGuard::is_end_plausible( '2026-09-12 19:30:00', '2026-09-19 11:30:00', 200 ) );
		$this->assertFalse( EventSpanGuard::is_end_plausible( '2026-09-12 19:30:00', '2026-09-12 21:00:00', 1 ) );
	}

	public function test_unparseable_inputs_default_to_plausible(): void {
		// Malformed dates belong to the #394/#395 rejection path, not the span guard.
		$this->assertTrue( EventSpanGuard::is_end_plausible( 'not-a-date', '2026-09-19 11:30:00' ) );
		$this->assertTrue( EventSpanGuard::is_end_plausible( '2026-09-12 19:30:00', 'garbage' ) );
	}

	public function test_span_hours_from_block_attrs_mirrors_sync_derivation(): void {
		// endTime present: exact span.
		$this->assertSame(
			6745,
			EventSpanGuard::span_hours_from_block_attrs(
				array(
					'startDate' => '2026-09-10',
					'startTime' => '20:00',
					'endDate'   => '2027-06-18',
					'endTime'   => '21:00',
				)
			)
		);

		// No endTime: defaults to 23:59:59 like the sync.
		$hours = EventSpanGuard::span_hours_from_block_attrs(
			array(
				'startDate' => '2026-09-10',
				'endDate'   => '2026-09-11',
			)
		);
		$this->assertSame( 48, $hours );
	}

	public function test_span_hours_from_block_attrs_returns_null_when_not_measurable(): void {
		$this->assertNull( EventSpanGuard::span_hours_from_block_attrs( array( 'startDate' => '2026-09-10' ) ) );
		$this->assertNull( EventSpanGuard::span_hours_from_block_attrs( array( 'endDate' => '2026-09-11' ) ) );
		$this->assertNull(
			EventSpanGuard::span_hours_from_block_attrs(
				array(
					'startDate' => '2026-09-11',
					'endDate'   => '2026-09-10',
				)
			)
		);
		$this->assertNull(
			EventSpanGuard::span_hours_from_block_attrs(
				array(
					'startDate' => 'junk',
					'endDate'   => '2026-09-11',
				)
			)
		);
	}
}
