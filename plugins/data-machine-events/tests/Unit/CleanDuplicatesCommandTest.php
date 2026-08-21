<?php
/**
 * Clean duplicate events command tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Cli\Check\CleanDuplicatesCommand;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class CleanDuplicatesCommandTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
	}

	public function test_repair_selects_exact_replays_without_collapsing_later_showtime(): void {
		$venue = wp_insert_term( 'Cleaner venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$title = 'Cleaner multiple showtime ' . uniqid();
		$first = $this->seedEvent( $title, '2026-11-21 19:00:00', (int) $venue['term_id'] );
		$dupe  = $this->seedEvent( $title, '2026-11-21 19:00:00', (int) $venue['term_id'] );
		$late  = $this->seedEvent( $title, '2026-11-21 21:30:00', (int) $venue['term_id'] );

		$method = new \ReflectionMethod( CleanDuplicatesCommand::class, 'find_duplicates' );
		$method->setAccessible( true );
		$groups = $method->invoke( new CleanDuplicatesCommand(), array_map( 'get_post', array( $first, $dupe, $late ) ) );

		$this->assertCount( 1, $groups );
		$this->assertEqualsCanonicalizing( array( $first, $dupe ), array( $groups[0]['event_a']['id'], $groups[0]['event_b']['id'] ) );
		$this->assertNotContains( $late, array( $groups[0]['event_a']['id'], $groups[0]['event_b']['id'] ) );
	}

	private function seedEvent( string $title, string $start, int $venue_id ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );
		EventDatesTable::upsert( $post_id, $start, null, 'publish' );

		return $post_id;
	}
}
