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

	public function test_generated_column_backfills_and_tracks_all_writes(): void {
		global $wpdb;
		$table = EventDatesTable::table_name();
		// A private legacy-shaped table avoids destructive DDL on the shared table.
		$legacy = $table . '_migration_test';
		$wpdb->query( "CREATE TEMPORARY TABLE {$legacy} (post_id BIGINT PRIMARY KEY, start_datetime DATETIME, end_datetime DATETIME, post_status VARCHAR(20))" );
		try {
			$wpdb->query( "INSERT INTO {$legacy} VALUES (1, '2099-01-01 12:00:00', NULL, 'publish')" );
			$wpdb->query( "ALTER TABLE {$legacy} ADD effective_end DATETIME GENERATED ALWAYS AS (COALESCE(end_datetime, start_datetime)) STORED, ADD KEY status_effective_end (post_status, effective_end, post_id)" );
			$this->assertSame( '2099-01-01 12:00:00', $wpdb->get_var( "SELECT effective_end FROM {$legacy}" ) );
			$wpdb->query( "UPDATE {$legacy} SET end_datetime = '2099-01-02 12:00:00'" );
			$this->assertSame( '2099-01-02 12:00:00', $wpdb->get_var( "SELECT effective_end FROM {$legacy}" ) );
		} finally {
			$wpdb->query( "DROP TEMPORARY TABLE {$legacy}" );
		}
		$this->assertCount( 3, $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'status_effective_end'" ) );
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
