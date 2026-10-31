<?php
/**
 * Event date query distinct-row tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventDateQueryAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use WP_UnitTestCase;

class EventDateQueryDistinctTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'event_query_distinct_style' ) ) {
			register_taxonomy( 'event_query_distinct_style', Event_Post_Type::POST_TYPE );
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
	}

	public function test_multiple_matching_taxonomy_rows_return_one_canonical_event(): void {
		$first_term  = wp_insert_term( 'Query multi-term A ' . uniqid(), 'event_query_distinct_style' );
		$second_term = wp_insert_term( 'Query multi-term B ' . uniqid(), 'event_query_distinct_style' );
		$this->assertNotWPError( $first_term );
		$this->assertNotWPError( $second_term );
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Multi-term canonical event',
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $post_id, array( (int) $first_term['term_id'], (int) $second_term['term_id'] ), 'event_query_distinct_style' );
		add_post_meta( $post_id, '_event_query_distinct_marker', 'first' );
		add_post_meta( $post_id, '_event_query_distinct_marker', 'second' );
		EventDatesTable::upsert( $post_id, '2026-11-22 20:00:00', '2026-11-22 22:00:00', 'publish' );

		$query_input = array(
			'scope'       => 'all',
			'date_start'  => '2026-11-22',
			'date_end'    => '2026-11-22',
			'tax_filters' => array( 'event_query_distinct_style' => array( (int) $first_term['term_id'], (int) $second_term['term_id'] ) ),
		);
		$result      = ( new EventDateQueryAbilities() )->executeQueryEvents( array_merge( $query_input, array( 'fields' => 'ids' ) ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 1, $result['post_count'] );
		$this->assertSame( array( $post_id ), $result['posts'] );

		$count_input               = $query_input;
		$count_input['meta_query'] = array( array( 'key' => '_event_query_distinct_marker' ) );
		$count_input['fields']     = 'count';
		$count                       = ( new EventDateQueryAbilities() )->executeQueryEvents( $count_input );

		$this->assertSame( 1, $count['total'] );
		$this->assertSame( 0, $count['post_count'] );
		$this->assertSame( array(), $count['posts'] );
	}
}
