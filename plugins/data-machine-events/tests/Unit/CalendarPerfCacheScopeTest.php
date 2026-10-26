<?php
namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator;
use DataMachineEvents\Core\EventDatesTable;

class CalendarPerfCacheScopeTest extends WP_UnitTestCase {
	private string $bucket = '2026-10-01 10';

	public function clock(): string {
		return $this->bucket;
	}

	public function setUp(): void {
		parent::setUp();
		EventDatesTable::create_table();
		add_filter( 'datamachine_calendar_past_time_bucket', array( $this, 'clock' ) );
	}

	public function tearDown(): void {
		remove_filter( 'datamachine_calendar_past_time_bucket', array( $this, 'clock' ) );
		parent::tearDown();
	}

	private function event( bool $past ): int {
		$date    = $past ? '2020-01-01' : '2090-01-01';
		$content = '<!-- wp:data-machine-events/event-details ' . wp_json_encode(
			array(
				'startDate' => $date,
				'startTime' => '18:00',
				'endDate'   => $date,
				'endTime'   => '20:00',
			)
		) . ' /-->';
		$id      = self::factory()->post->create(
			array(
				'post_type'    => 'data_machine_events',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		EventDatesTable::upsert( $id, "$date 18:00:00", "$date 20:00:00" );
		return $id;
	}

	public function test_past_bucket_and_full_keys_change_each_hour(): void {
		$key  = CalendarCache::generate_key( array( 'show_past' => true ), 'dates' );
		$full = CalendarCache::generate_full_response_key( array( 'past' => true ) );
		CalendarCache::set( $key, array( 'old' ), CalendarCache::TTL_DATES );
		CalendarCache::set_full_response( $full, array( 'old' ), CalendarCache::TTL_FULL_PAST );
		$this->bucket = '2026-10-01 11';
		$next         = CalendarCache::generate_key( array( 'show_past' => true ), 'dates' );
		$next_full    = CalendarCache::generate_full_response_key( array( 'past' => true ) );
		$this->assertNotSame( $key, $next );
		$this->assertNotSame( $full, $next_full );
		$this->assertFalse( CalendarCache::get( $next ) );
		$this->assertFalse( CalendarCache::get_full_response( $next_full ) );
	}

	public function test_upcoming_save_preserves_past_within_bucket(): void {
		$id   = $this->event( false );
		$key  = CalendarCache::generate_key( array( 'show_past' => true ), 'dates' );
		$full = CalendarCache::generate_full_response_key( array( 'past' => true ) );
		CalendarCache::set( $key, array( 'past' ), CalendarCache::TTL_DATES );
		CalendarCache::set_full_response( $full, array( 'past' ), CalendarCache::TTL_FULL_PAST );
		$generation = CalendarCache::get_generation();
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Updated upcoming',
			)
		);
		$this->assertNotSame( $generation, CalendarCache::get_generation() );
		$this->assertSame( $key, CalendarCache::generate_key( array( 'show_past' => true ), 'dates' ) );
		$this->assertSame( array( 'past' ), CalendarCache::get( $key ) );
		$this->assertSame( array( 'past' ), CalendarCache::get_full_response( $full ) );
	}

	public function test_past_save_invalidates_past(): void {
		$id         = $this->event( true );
		$generation = CalendarCache::get_past_generation();
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Updated past',
			)
		);
		$this->assertNotSame( $generation, CalendarCache::get_past_generation() );
	}

	public function test_moving_past_event_to_future_invalidates_past(): void {
		$id         = $this->event( true );
		$generation = CalendarCache::get_past_generation();
		EventDatesTable::upsert( $id, '2090-01-01 18:00:00', '2090-01-01 20:00:00' );
		$this->assertNotSame( $generation, CalendarCache::get_past_generation() );
	}

	public function test_missing_dates_invalidates_conservatively(): void {
		$generation = CalendarCache::get_past_generation();
		CacheInvalidator::invalidate_event( 99999999 );
		$this->assertNotSame( $generation, CalendarCache::get_past_generation() );
	}

	public function test_global_invalidation_rotates_both_generations(): void {
		$past     = CalendarCache::get_past_generation();
		$upcoming = CalendarCache::get_generation();
		CacheInvalidator::invalidate_all();
		$this->assertNotSame( $past, CalendarCache::get_past_generation() );
		$this->assertNotSame( $upcoming, CalendarCache::get_generation() );
	}

	public function test_past_grouped_counts_refresh_on_bucket_change(): void {
		global $wpdb;
		register_taxonomy( 'calendar_perf_kind', 'data_machine_events', array( 'public' => true ) );
		$id   = $this->event( true );
		$term = self::factory()->term->create( array( 'taxonomy' => 'calendar_perf_kind' ) );
		wp_set_object_terms( $id, array( $term ), 'calendar_perf_kind' );
		$method = new \ReflectionMethod( \DataMachineEvents\Abilities\FilterAbilities::class, 'get_grouped_term_counts' );
		$method->setAccessible( true );
		$abilities = new \DataMachineEvents\Abilities\FilterAbilities();
		$counts    = static function () use ( $method, $abilities ) {
			return $method->invoke( $abilities, array( 'calendar_perf_kind' ), array( 'past' => '1' ), array(), null, array() );
		};
		try {
			$this->assertSame( 1, $counts()['calendar_perf_kind'][ $term ] );
			// Simulate a time-driven eligibility change without any save hook.
			$wpdb->update(
				EventDatesTable::table_name(),
				array(
					'start_datetime' => '2090-01-01 18:00:00',
					'end_datetime'   => '2090-01-01 20:00:00',
				),
				array( 'post_id' => $id )
			);
			$this->assertSame( 1, $counts()['calendar_perf_kind'][ $term ] );
			$this->bucket = '2026-10-01 11';
			$this->assertEmpty( $counts() );
		} finally {
			unregister_taxonomy( 'calendar_perf_kind' );
		}
	}

	public function test_fixed_upcoming_key_does_not_use_past_clock(): void {
		$params       = array( 'date_start' => '2090-01-01' );
		$key          = CalendarCache::generate_key( $params, 'dates' );
		$full         = CalendarCache::generate_full_response_key( $params );
		$this->bucket = '2026-10-01 11';
		$this->assertSame( $key, CalendarCache::generate_key( $params, 'dates' ) );
		$this->assertSame( $full, CalendarCache::generate_full_response_key( $params ) );
	}
}
