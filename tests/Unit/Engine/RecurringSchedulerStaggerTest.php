<?php
/**
 * RecurringScheduler Stagger Tests
 *
 * Tests for the deterministic stagger offset calculation on the shared
 * scheduling primitive.
 *
 * @package DataMachine\Tests\Unit\Engine
 */

namespace DataMachine\Tests\Unit\Engine;

use DataMachine\Engine\Tasks\RecurringScheduler;
use WP_UnitTestCase;

class RecurringSchedulerStaggerTest extends WP_UnitTestCase {

	/**
	 * Same seed + interval should produce identical offsets.
	 */
	public function test_stagger_is_deterministic(): void {
		$offset_a = RecurringScheduler::calculateStaggerOffset( 42, 86400 );
		$offset_b = RecurringScheduler::calculateStaggerOffset( 42, 86400 );

		$this->assertSame( $offset_a, $offset_b, 'Same seed should produce identical offsets' );
	}

	/**
	 * Different seeds should (usually) produce different offsets.
	 */
	public function test_different_seeds_get_different_offsets(): void {
		$offsets = array();
		for ( $i = 1; $i <= 50; $i++ ) {
			$offsets[] = RecurringScheduler::calculateStaggerOffset( $i, 3600 );
		}

		$unique = array_unique( $offsets );
		$this->assertGreaterThan( 30, count( $unique ), 'Most seeds should produce distinct offsets' );
	}

	/**
	 * Offset should never exceed the interval.
	 */
	public function test_offset_bounded_by_interval(): void {
		for ( $seed = 1; $seed <= 100; $seed++ ) {
			$offset = RecurringScheduler::calculateStaggerOffset( $seed, 300 );
			$this->assertGreaterThanOrEqual( 0, $offset );
			$this->assertLessThan( 300, $offset );
		}
	}

	/**
	 * Offset should be capped at MAX_STAGGER_SECONDS (3600) even for large intervals.
	 */
	public function test_offset_capped_for_large_intervals(): void {
		for ( $seed = 1; $seed <= 100; $seed++ ) {
			// Weekly interval = 604800 seconds, but stagger should cap at 3600.
			$offset = RecurringScheduler::calculateStaggerOffset( $seed, 604800 );
			$this->assertGreaterThanOrEqual( 0, $offset );
			$this->assertLessThan( RecurringScheduler::MAX_STAGGER_SECONDS, $offset );
		}
	}

	/**
	 * Zero or negative interval should return 0 offset.
	 */
	public function test_zero_interval_returns_zero(): void {
		$this->assertSame( 0, RecurringScheduler::calculateStaggerOffset( 1, 0 ) );
		$this->assertSame( 0, RecurringScheduler::calculateStaggerOffset( 1, -100 ) );
	}

	/**
	 * Very small intervals should still produce valid offsets.
	 */
	public function test_small_interval(): void {
		$offset = RecurringScheduler::calculateStaggerOffset( 42, 60 );
		$this->assertGreaterThanOrEqual( 0, $offset );
		$this->assertLessThan( 60, $offset );
	}
}
