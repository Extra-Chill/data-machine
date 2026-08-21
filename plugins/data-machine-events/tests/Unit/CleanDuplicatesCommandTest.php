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
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;

class CleanDuplicatesCommandTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! taxonomy_exists( 'location' ) ) {
			register_taxonomy( 'location', Event_Post_Type::POST_TYPE, array( 'hierarchical' => true ) );
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

	public function test_repair_preserves_expanded_and_distinct_bills(): void {
		$venue = wp_insert_term( 'Expanded bill venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$venue_id = (int) $venue['term_id'];
		$events   = array(
			$this->seedEvent( 'Meltt', '2026-11-22 20:00:00', $venue_id ),
			$this->seedEvent( 'Meltt, Los Eclipses', '2026-11-22 20:00:00', $venue_id ),
			$this->seedEvent( 'The Charities with Mae Powell', '2026-11-23 20:00:00', $venue_id ),
			$this->seedEvent( 'The Charities', '2026-11-23 20:00:00', $venue_id ),
			$this->seedEvent( 'Foxtide', '2026-11-24 20:00:00', $venue_id ),
			$this->seedEvent( 'Foxtide with The Braymores, Geskle', '2026-11-24 20:00:00', $venue_id ),
		);

		$groups = $this->invokePrivate( 'find_duplicates', array_map( 'get_post', $events ) );

		$this->assertSame( array(), $groups );
	}

	public function test_repair_accepts_exact_normalized_replays(): void {
		$venue = wp_insert_term( 'Replay & Hall ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$first = $this->seedEvent( 'The Exact Replay', '2026-11-25 20:00:00', (int) $venue['term_id'] );
		$dupe  = $this->seedEvent( ' exact   replay ', '2026-11-25 20:00:00', (int) $venue['term_id'] );

		$groups = $this->invokePrivate( 'find_duplicates', array_map( 'get_post', array( $first, $dupe ) ) );

		$this->assertCount( 1, $groups );
		$this->assertEqualsCanonicalizing( array( $first, $dupe ), array( $groups[0]['event_a']['id'], $groups[0]['event_b']['id'] ) );
	}

	public function test_location_scope_uses_existing_taxonomy_query_filter(): void {
		$venue    = wp_insert_term( 'Scoped cleaner venue ' . uniqid(), 'venue' );
		$chicago  = wp_insert_term( 'Chicago cleaner ' . uniqid(), 'location' );
		$nashville = wp_insert_term( 'Nashville cleaner ' . uniqid(), 'location' );
		$this->assertNotWPError( $venue );
		$this->assertNotWPError( $chicago );
		$this->assertNotWPError( $nashville );
		$inside  = $this->seedEvent( 'Chicago scoped event', '2026-11-26 20:00:00', (int) $venue['term_id'], (int) $chicago['term_id'] );
		$outside = $this->seedEvent( 'Nashville scoped event', '2026-11-26 21:00:00', (int) $venue['term_id'], (int) $nashville['term_id'] );

		$posts = $this->invokePrivate( 'query_events', 'all', 90, (int) $chicago['term_id'] );
		$ids   = wp_list_pluck( $posts, 'ID' );

		$this->assertContains( $inside, $ids );
		$this->assertNotContains( $outside, $ids );
	}

	public function test_apply_selection_requires_exact_reviewed_candidate_parity(): void {
		$venue = wp_insert_term( 'Reviewed cleaner venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$title  = 'Reviewed candidate ' . uniqid();
		$first  = $this->seedEvent( $title, '2026-11-27 20:00:00', (int) $venue['term_id'] );
		$dupe   = $this->seedEvent( $title, '2026-11-27 20:00:00', (int) $venue['term_id'] );
		$groups = $this->invokePrivate( 'find_duplicates', array_map( 'get_post', array( $first, $dupe ) ) );
		$actions = $this->invokePrivate( 'build_actions', $groups );
		$reviewed_id = $actions[0]['candidate_id'];

		$selected = $this->invokePrivate( 'select_reviewed_actions', $actions, array( $reviewed_id ) );
		$this->assertCount( 1, $selected );
		$this->assertSame( $reviewed_id, $selected[0]['candidate_id'] );

		wp_update_post(
			array(
				'ID'         => $dupe,
				'post_title' => $title . ' with a newly expanded bill',
			)
		);
		$changed_groups  = $this->invokePrivate( 'find_duplicates', array_map( 'get_post', array( $first, $dupe ) ) );
		$changed_actions = $this->invokePrivate( 'build_actions', $changed_groups );
		$stale_selection = $this->invokePrivate( 'select_reviewed_actions', $changed_actions, array( $reviewed_id ) );

		$this->assertWPError( $stale_selection );
		$this->assertSame( 'stale_duplicate_review', $stale_selection->get_error_code() );
	}

	public function test_apply_selection_excludes_unreviewed_candidates(): void {
		$venue = wp_insert_term( 'Bounded cleaner venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$venue_id    = (int) $venue['term_id'];
		$first_keep  = $this->seedEvent( 'First bounded replay', '2026-11-28 19:00:00', $venue_id );
		$first_trash = $this->seedEvent( 'First bounded replay', '2026-11-28 19:00:00', $venue_id );
		$other_keep  = $this->seedEvent( 'Other bounded replay', '2026-11-28 21:00:00', $venue_id );
		$other_dupe  = $this->seedEvent( 'Other bounded replay', '2026-11-28 21:00:00', $venue_id );
		$posts       = array_map( 'get_post', array( $first_keep, $first_trash, $other_keep, $other_dupe ) );
		$groups      = $this->invokePrivate( 'find_duplicates', $posts );
		$actions     = $this->invokePrivate( 'build_actions', $groups );
		$reviewed    = array_values( array_filter( $actions, static fn( $action ) => $first_trash === $action['trash_id'] ) );

		$this->assertCount( 2, $actions );
		$this->assertCount( 1, $reviewed );
		$selected = $this->invokePrivate( 'select_reviewed_actions', $actions, array( $reviewed[0]['candidate_id'] ) );
		$result   = $this->invokePrivate( 'apply_actions', $selected );

		$this->assertSame( array( 'trashed' => 1, 'merged' => 0 ), $result );
		$this->assertSame( 'trash', get_post_status( $first_trash ) );
		$this->assertSame( 'publish', get_post_status( $other_keep ) );
		$this->assertSame( 'publish', get_post_status( $other_dupe ) );
	}

	public function test_reviewed_apply_merges_ticket_url_from_duplicate(): void {
		$venue = wp_insert_term( 'Ticket cleaner venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$title  = 'Ticket merge replay ' . uniqid();
		$first  = $this->seedEvent( $title, '2026-11-29 20:00:00', (int) $venue['term_id'] );
		$dupe   = $this->seedEvent( $title, '2026-11-29 20:00:00', (int) $venue['term_id'] );
		$ticket = 'https://tickets.example.com/' . uniqid();
		update_post_meta( $dupe, EVENT_TICKET_URL_META_KEY, $ticket );
		$groups  = $this->invokePrivate( 'find_duplicates', array_map( 'get_post', array( $first, $dupe ) ) );
		$actions = $this->invokePrivate( 'build_actions', $groups );

		$this->assertSame( 'yes', $actions[0]['merge_ticket'] );
		$result = $this->invokePrivate( 'apply_actions', $actions );

		$this->assertSame( array( 'trashed' => 1, 'merged' => 1 ), $result );
		$this->assertSame( $ticket, get_post_meta( $first, EVENT_TICKET_URL_META_KEY, true ) );
		$this->assertSame( 'trash', get_post_status( $dupe ) );
	}

	private function seedEvent( string $title, string $start, int $venue_id, int $location_id = 0 ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );
		if ( $location_id > 0 ) {
			wp_set_object_terms( $post_id, array( $location_id ), 'location' );
		}
		EventDatesTable::upsert( $post_id, $start, null, 'publish' );

		return $post_id;
	}

	private function invokePrivate( string $method_name, ...$arguments ) {
		$method = new \ReflectionMethod( CleanDuplicatesCommand::class, $method_name );
		$method->setAccessible( true );

		return $method->invoke( new CleanDuplicatesCommand(), ...$arguments );
	}
}
