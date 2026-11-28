<?php
/**
 * Calendar page expiry tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Blocks\Calendar\Cache\PageExpiry;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;

class PageExpiryTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		PageExpiry::reset();
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
	}

	public function tearDown(): void {
		PageExpiry::reset();
		parent::tearDown();
	}

	private function site_datetime( int $timestamp ): string {
		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	public function test_lifetime_ends_at_the_transition(): void {
		$now = 1_800_000_000;

		$this->assertSame( 2400, PageExpiry::max_age_until( $this->site_datetime( $now + 2400 ), $now ) );
	}

	public function test_lifetime_is_clamped_to_floor_and_ceiling(): void {
		$now = 1_800_000_000;

		$this->assertSame( PageExpiry::FLOOR, PageExpiry::max_age_until( $this->site_datetime( $now + 5 ), $now ) );
		$this->assertSame( PageExpiry::CEILING, PageExpiry::max_age_until( $this->site_datetime( $now + DAY_IN_SECONDS ), $now ) );
		$this->assertSame( PageExpiry::CEILING, PageExpiry::max_age_until( '', $now ) );
	}

	public function test_event_starting_in_forty_minutes_bounds_max_age(): void {
		$now     = current_datetime();
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		EventDatesTable::upsert( $post_id, $now->modify( '+40 minutes' )->format( 'Y-m-d H:i:s' ), null, 'publish' );

		$max_age = PageExpiry::max_age();

		$this->assertLessThanOrEqual( 2400, $max_age );
		$this->assertGreaterThan( 2400 - 30, $max_age );
	}

	public function test_multiple_calendars_share_the_shortest_lifetime(): void {
		$now     = current_datetime();
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( PageExpiry::CEILING, PageExpiry::emit() );

		EventDatesTable::upsert( $post_id, $now->modify( '+10 minutes' )->format( 'Y-m-d H:i:s' ), null, 'publish' );
		$this->assertLessThanOrEqual( 600, PageExpiry::emit() );

		EventDatesTable::upsert( $post_id, $now->modify( '+50 minutes' )->format( 'Y-m-d H:i:s' ), null, 'publish' );
		$this->assertLessThanOrEqual( 600, PageExpiry::emit(), 'A later calendar must not extend an earlier, shorter expiry.' );
		$this->assertLessThanOrEqual( 600, PageExpiry::current() );
	}

	public function test_header_value_is_generic_cache_control(): void {
		$this->assertSame( 'Cache-Control: public, max-age=2400', PageExpiry::header_value( 2400 ) );
	}
}
