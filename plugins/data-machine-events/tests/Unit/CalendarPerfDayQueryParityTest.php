<?php
namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventDateQueryAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;

class CalendarPerfDayQueryParityTest extends \WP_UnitTestCase {
	public function test_window_overlap_hierarchy_order_pagination_and_count(): void {
		global $wpdb;
		// WP Codebox/Playground runs the SQLite driver, which still reports
		// is_mysql. Parity is asserted everywhere; plans only on real MySQL.
		$mysql = EventDateQueryAbilities::supportsIndexedWindowQuery();
		// Some test backends emulate MySQL without EXPLAIN; plans are asserted
		// only where the server can produce them.
		$explain = $mysql && ! empty( $wpdb->get_results( 'EXPLAIN SELECT 1', ARRAY_A ) );
		EventDatesTable::create_table();
		Event_Post_Type::register();
		register_taxonomy( 'perf_region', Event_Post_Type::POST_TYPE, array( 'hierarchical' => true ) );
		register_taxonomy( 'perf_style', Event_Post_Type::POST_TYPE );
		$parent = self::factory()->term->create( array( 'taxonomy' => 'perf_region' ) );
		$child = self::factory()->term->create( array( 'taxonomy' => 'perf_region', 'parent' => $parent ) );
		$style = self::factory()->term->create( array( 'taxonomy' => 'perf_style' ) );
		foreach ( array(
			array( '2026-10-04 12:00:00', '2026-10-08 12:00:00' ),
			array( '2026-10-06 05:00:00', null ),
			array( '2026-10-06 05:00:00', '2026-10-06 09:00:00' ),
			array( '2026-10-07 04:59:59', null ),
			array( '2026-10-07 05:00:00', null ),
			array( '2026-10-04 12:00:00', null ),
		) as $i => $dates ) {
			$id = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE, 'post_status' => 'publish' ) );
			EventDatesTable::upsert( $id, $dates[0], $dates[1], 'publish' );
			wp_set_object_terms( $id, array( $child, $parent ), 'perf_region' );
			if ( $i % 2 ) {
				wp_set_object_terms( $id, array( $style ), 'perf_style' );
			}
		}
		$ability = new EventDateQueryAbilities();
		// A harmless extra argument forces the canonical WP_Query fallback.
		$fallback = static function ( $args ) { $args['ignore_sticky_posts'] = true; return $args; };
		foreach ( array( array(), array( 'perf_region' => array( $parent ) ), array( 'perf_region' => array( $parent ), 'perf_style' => array( $style ) ) ) as $tax ) {
			foreach ( array( 'ASC', 'DESC' ) as $order ) {
				foreach ( array( -1, 2 ) as $limit ) {
					$input = array( 'date_start' => '2026-10-06', 'date_end' => '2026-10-07', 'time_start' => '05:00:00', 'time_end' => '04:59:59', 'tax_filters' => $tax, 'order' => $order, 'per_page' => $limit, 'page' => 2, 'fields' => 'ids' );
					$actual = $ability->executeQueryEvents( $input );
					if ( $mysql ) {
						$sql = $wpdb->last_query;
						$this->assertStringContainsString( 'UNION ALL', $sql );
					}
					if ( $explain ) {
						$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
						$this->assertNotEmpty( $plan );
						$this->assertSame( '', $wpdb->last_error );
						$date_rows = array_values( array_filter( $plan, static function ( $row ) { return 'ed' === $row['table']; } ) );
						$this->assertCount( 2, $date_rows );
						$this->assertSame( array( 'status_start', 'end_datetime' ), array_column( $date_rows, 'key' ) );
					}
					add_filter( 'data_machine_events_calendar_query_args', $fallback );
					try {
						$this->assertSame( $ability->executeQueryEvents( $input ), $actual );
						if ( -1 === $limit ) {
							$this->assertSame( count( $actual['posts'] ), $ability->executeQueryEvents( array_merge( $input, array( 'fields' => 'count' ) ) )['total'] );
						}
					} finally {
						remove_filter( 'data_machine_events_calendar_query_args', $fallback );
					}
				}
			}
		}
		foreach ( array( '2026-10-06', '2026-10-12' ) as $end ) {
			$input = array( 'scope' => 'past', 'date_start' => '2026-10-06', 'date_end' => $end, 'fields' => 'all', 'tax_filters' => array( 'perf_region' => array( $parent ) ) );
			$actual = $ability->executeQueryEvents( $input );
			$this->assertNotEmpty( $actual['posts'] );
			$this->assertInstanceOf( \WP_Post::class, $actual['posts'][0] );
			add_filter( 'data_machine_events_calendar_query_args', $fallback );
			try {
				$this->assertEquals( $ability->executeQueryEvents( $input ), $actual );
			} finally {
				remove_filter( 'data_machine_events_calendar_query_args', $fallback );
			}
		}
		$this->assertSame( array(), $ability->executeQueryEvents( array( 'date_start' => '2026-10-06', 'date_end' => '2026-10-07', 'tax_filters' => array( 'not_a_taxonomy' => array( 1 ) ) ) )['posts'] );
	}
}
