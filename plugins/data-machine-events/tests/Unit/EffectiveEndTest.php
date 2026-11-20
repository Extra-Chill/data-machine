<?php
/** Effective-end schema, predicate and transition regressions. */
namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Blocks\Calendar\Cache\PageExpiry;

class EffectiveEndTest extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		EventDatesTable::create_table();
		CalendarCache::invalidate();
	}

	public function test_effective_end_is_backfilled_and_maintained_by_upsert(): void {
		global $wpdb;
		$table = EventDatesTable::table_name();
		$id    = self::factory()->post->create( array( 'post_type' => 'data_machine_events', 'post_status' => 'publish' ) );

		// A row written before the column existed has no effective_end.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", $id ) );
		$wpdb->insert(
			$table,
			array(
				'post_id'        => $id,
				'start_datetime' => '2099-01-01 12:00:00',
				'post_status'    => 'publish',
			)
		);
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT effective_end FROM {$table} WHERE post_id = %d", $id ) ) );

		EventDatesTable::create_table();
		$this->assertSame( '2099-01-01 12:00:00', $wpdb->get_var( $wpdb->prepare( "SELECT effective_end FROM {$table} WHERE post_id = %d", $id ) ) );

		$this->assertTrue( EventDatesTable::upsert( $id, '2099-01-01 12:00:00', '2099-01-02 12:00:00' ) );
		$this->assertSame( '2099-01-02 12:00:00', $wpdb->get_var( $wpdb->prepare( "SELECT effective_end FROM {$table} WHERE post_id = %d", $id ) ) );

		$this->assertTrue( EventDatesTable::upsert( $id, '2099-01-05 12:00:00', null ) );
		$this->assertSame( '2099-01-05 12:00:00', $wpdb->get_var( $wpdb->prepare( "SELECT effective_end FROM {$table} WHERE post_id = %d", $id ) ) );
		$this->assertSame( EventDatesTable::SCHEMA_VERSION, (int) get_option( 'datamachine_event_dates_schema_version' ) );
	}

	public function test_predicate_equivalence_for_null_end_ongoing_multiday_and_boundary(): void {
		global $wpdb;
		$table = EventDatesTable::table_name();
		$now = '2099-01-02 12:00:00';
		$cases = array(
			array( '2099-01-03 12:00:00', null, true ),
			array( '2099-01-01 12:00:00', null, false ),
			array( '2099-01-01 12:00:00', '2099-01-03 12:00:00', true ),
			array( '2099-01-02 11:00:00', '2099-01-02 13:00:00', true ),
			array( '2099-01-02 11:00:00', $now, true ),
			array( '2099-01-01 11:00:00', '2099-01-01 13:00:00', false ),
			// Preserved end-before-start rows still match on their start.
			array( '2099-01-03 12:00:00', '2099-01-03 10:00:00', true ),
		);
		foreach ( $cases as $case ) {
			$id = self::factory()->post->create( array( 'post_type' => 'data_machine_events', 'post_status' => 'publish' ) );
			$this->assertTrue( EventDatesTable::upsert( $id, $case[0], $case[1] ) );
			$old = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$table} ed WHERE post_id = %d AND (start_datetime >= %s OR end_datetime >= %s)", $id, $now, $now ) );
			$new = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$table} ed WHERE post_id = %d AND ", $id ) . UpcomingFilter::upcoming_where( $now ) );
			$this->assertSame( (bool) $old, (bool) $new );
			$this->assertSame( $case[2], (bool) $new );
		}
	}

	public function test_transition_is_memoized_and_shared_with_page_expiry(): void {
		global $wpdb;
		$queries = array();
		$record = static function ( $sql ) use ( &$queries ) {
			if ( str_contains( $sql, 'SELECT MIN(effective_end)' ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $record );
		try {
			$first = CalendarCache::next_upcoming_transition();
			CalendarCache::next_upcoming_transition();
			CalendarCache::ttl_for_upcoming_transition( 1800 );
			PageExpiry::max_age();
			$this->assertCount( 1, $queries );
			CalendarCache::reset_transitions();
			$this->assertSame( $first, CalendarCache::next_upcoming_transition() );
			$this->assertCount( 1, $queries, 'Object cache survives request-local reset, including empty results.' );
			CalendarCache::invalidate();
			CalendarCache::next_upcoming_transition();
			$this->assertCount( 2, $queries );
		} finally {
			remove_filter( 'query', $record );
		}
	}
}
