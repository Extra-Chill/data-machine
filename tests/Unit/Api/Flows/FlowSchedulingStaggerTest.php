<?php
/**
 * FlowScheduling Stagger Tests
 *
 * Tests for the deterministic stagger offset in flow scheduling.
 *
 * @package DataMachine\Tests\Unit\Api\Flows
 */

namespace DataMachine\Tests\Unit\Api\Flows;

use DataMachine\Api\Flows\FlowScheduling;
use WP_UnitTestCase;

class FlowSchedulingStaggerTest extends WP_UnitTestCase {

	/**
	 * Stagger offset should be deterministic — same flow ID always produces same offset.
	 */
	public function test_stagger_is_deterministic(): void {
		$offset_a = FlowScheduling::calculate_stagger_offset( 42, 86400 );
		$offset_b = FlowScheduling::calculate_stagger_offset( 42, 86400 );

		$this->assertSame( $offset_a, $offset_b, 'Same flow_id should produce identical offsets' );
	}

	/**
	 * Different flow IDs should (usually) produce different offsets.
	 */
	public function test_different_flows_get_different_offsets(): void {
		$offsets = array();
		for ( $i = 1; $i <= 50; $i++ ) {
			$offsets[] = FlowScheduling::calculate_stagger_offset( $i, 3600 );
		}

		$unique = array_unique( $offsets );
		// With 50 flows in a 3600s window, we expect significant spread.
		// Allow some collisions but most should be unique.
		$this->assertGreaterThan( 30, count( $unique ), 'Most flow IDs should produce distinct offsets' );
	}

	/**
	 * Offset should never exceed the interval.
	 */
	public function test_offset_bounded_by_interval(): void {
		for ( $flow_id = 1; $flow_id <= 100; $flow_id++ ) {
			$offset = FlowScheduling::calculate_stagger_offset( $flow_id, 300 );
			$this->assertGreaterThanOrEqual( 0, $offset );
			$this->assertLessThan( 300, $offset );
		}
	}

	/**
	 * Offset should be capped at MAX_STAGGER_SECONDS (3600) even for large intervals.
	 */
	public function test_offset_capped_for_large_intervals(): void {
		for ( $flow_id = 1; $flow_id <= 100; $flow_id++ ) {
			// Weekly interval = 604800 seconds, but stagger should cap at 3600.
			$offset = FlowScheduling::calculate_stagger_offset( $flow_id, 604800 );
			$this->assertGreaterThanOrEqual( 0, $offset );
			$this->assertLessThan( 3600, $offset );
		}
	}

	/**
	 * Zero or negative interval should return 0 offset.
	 */
	public function test_zero_interval_returns_zero(): void {
		$this->assertSame( 0, FlowScheduling::calculate_stagger_offset( 1, 0 ) );
		$this->assertSame( 0, FlowScheduling::calculate_stagger_offset( 1, -100 ) );
	}

	/**
	 * Very small intervals should still produce valid offsets.
	 */
	public function test_small_interval(): void {
		$offset = FlowScheduling::calculate_stagger_offset( 42, 60 );
		$this->assertGreaterThanOrEqual( 0, $offset );
		$this->assertLessThan( 60, $offset );
	}
}
