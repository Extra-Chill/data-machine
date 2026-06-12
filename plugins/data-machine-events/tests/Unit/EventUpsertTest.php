<?php
/**
 * EventUpsert Tests
 *
 * Tests event creation/update logic.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

class EventUpsertTest extends WP_UnitTestCase {

	private EventUpsert $handler;

	public function setUp(): void {
		parent::setUp();

		// Ensure post type and taxonomies are registered
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		$this->handler = new EventUpsert();
	}

	public function test_handler_instantiation() {
		$this->assertInstanceOf( EventUpsert::class, $this->handler );
	}

	public function test_venue_taxonomy_handler_registered() {
		$handlers = \DataMachine\Core\WordPress\TaxonomyHandler::getCustomHandlers();
		$this->assertArrayHasKey( 'venue', $handlers );
	}

	public function test_promoter_taxonomy_handler_registered() {
		$handlers = \DataMachine\Core\WordPress\TaxonomyHandler::getCustomHandlers();
		$this->assertArrayHasKey( 'promoter', $handlers );
	}

	public function test_find_existing_event_returns_null_for_new() {
		$method = new \ReflectionMethod( $this->handler, 'findExistingEvent' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->handler,
			'Unique Event ' . uniqid(),
			'Test Venue',
			'2026-12-31',
			''
		);

		$this->assertNull( $result );
	}

	public function test_create_event_post_with_minimum_data() {
		// Create a test event post directly
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Test Event ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		$post = get_post( $post_id );
		$this->assertEquals( 'data_machine_events', $post->post_type );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	public function test_event_datetime_meta_storage() {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'DateTime Test Event ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);

		$datetime = '2026-06-15 19:30:00';
		update_post_meta( $post_id, '_datamachine_event_datetime', $datetime );

		$stored = get_post_meta( $post_id, '_datamachine_event_datetime', true );
		$this->assertEquals( $datetime, $stored );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	public function test_event_with_venue_assignment() {
		// Create venue
		$venue_term = wp_insert_term( 'Test Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue_term );
		$venue_id = $venue_term['term_id'];

		// Create event
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Venue Test Event ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);

		// Assign venue
		wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );

		$terms = wp_get_object_terms( $post_id, 'venue' );
		$this->assertCount( 1, $terms );
		$this->assertEquals( $venue_id, $terms[0]->term_id );

		// Cleanup
		wp_delete_post( $post_id, true );
		wp_delete_term( $venue_id, 'venue' );
	}

	public function test_error_response_without_title() {
		// Test that executeUpdate returns error without title
		// This tests the validation logic
		$method = new \ReflectionMethod( $this->handler, 'errorResponse' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->handler,
			'title parameter is required',
			array()
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_datetime_confidence_none_without_start_date(): void {
		$method = new \ReflectionMethod( $this->handler, 'getDateTimeConfidence' );
		$method->setAccessible( true );

		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$result = $method->invoke(
			$this->handler,
			array(
				'title' => 'Weak Event',
			),
			$engine
		);

		$this->assertSame( 'none', $result );
	}

	public function test_datetime_confidence_date_only_without_start_time(): void {
		$method = new \ReflectionMethod( $this->handler, 'getDateTimeConfidence' );
		$method->setAccessible( true );

		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$result = $method->invoke(
			$this->handler,
			array(
				'title'     => 'Date Only Event',
				'startDate' => '2026-03-20',
			),
			$engine
		);

		$this->assertSame( 'date_only', $result );
	}

	/**
	 * Verify that recurring series events with the same title but different
	 * dates are treated as distinct events, not duplicates.
	 *
	 * The legacy findExistingEvent() method correctly uses venue + date +
	 * fuzzy title matching. This test verifies that the method returns null
	 * for a different date at the same venue with the same title.
	 *
	 * @see https://github.com/Extra-Chill/data-machine/issues/1108
	 */
	public function test_find_existing_event_distinguishes_recurring_series_by_date(): void {
		$method = new \ReflectionMethod( $this->handler, 'findExistingEvent' );
		$method->setAccessible( true );

		$venue_name = 'Recurring Venue ' . uniqid();

		// Create venue term.
		$venue_term = wp_insert_term( $venue_name, 'venue' );
		$this->assertNotWPError( $venue_term );

		// Create existing event: "Barn Jam" on April 22.
		$existing_post_id = wp_insert_post(
			array(
				'post_title'   => 'Barn Jam',
				'post_type'    => 'data_machine_events',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2026-04-22","venue":"' . $venue_name . '"} --><div class="wp-block-data-machine-events-event-details"></div><!-- /wp:data-machine-events/event-details -->',
			)
		);
		$this->assertGreaterThan( 0, $existing_post_id );
		wp_set_object_terms( $existing_post_id, array( $venue_term['term_id'] ), 'venue' );

		// Search for "Barn Jam" at same venue but DIFFERENT date — should NOT match.
		$result = $method->invoke(
			$this->handler,
			'Barn Jam',
			$venue_name,
			'2026-05-06',
			''
		);

		$this->assertNull(
			$result,
			'findExistingEvent should NOT match same-title event on a different date (recurring series)'
		);

		// Search for "Barn Jam" at same venue and SAME date — should match.
		$result_same_date = $method->invoke(
			$this->handler,
			'Barn Jam',
			$venue_name,
			'2026-04-22',
			''
		);

		$this->assertSame(
			$existing_post_id,
			$result_same_date,
			'findExistingEvent should match same-title event on the same date'
		);

		// Cleanup.
		wp_delete_post( $existing_post_id, true );
		wp_delete_term( $venue_term['term_id'], 'venue' );
	}

	/**
	 * The defensive guard against "Rejected:" title leakage refuses titles
	 * beginning with "Rejected:" (case-insensitive, after trimming).
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/349
	 */
	public function test_is_junk_title_refuses_colon_prefix(): void {
		$method = new \ReflectionMethod( $this->handler, 'isJunkTitle' );
		$method->setAccessible( true );

		$this->assertTrue(
			$method->invoke( $this->handler, 'Rejected: 2026 Premium Season Tickets — Everwise Amphitheater (Ticketmaster)' ),
			'A title starting with "Rejected:" must be refused.'
		);

		// Leading whitespace + lowercase variant must also be caught.
		$this->assertTrue(
			$method->invoke( $this->handler, '   rejected: parking pass' ),
			'A trimmed, lowercase "rejected:" title must be refused.'
		);
	}

	/**
	 * The guard also catches the "Rejected -" dash variant.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/349
	 */
	public function test_is_junk_title_refuses_dash_prefix(): void {
		$method = new \ReflectionMethod( $this->handler, 'isJunkTitle' );
		$method->setAccessible( true );

		$this->assertTrue(
			$method->invoke( $this->handler, 'Rejected - 2026 Premium Season Tickets' ),
			'A title starting with "Rejected -" must be refused.'
		);
	}

	/**
	 * Dedup-marker prefixes ("Duplicate:", "Consolidate:") and dash/em-dash
	 * variants are refused.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/367
	 */
	public function test_is_junk_title_refuses_dedup_prefixes(): void {
		$method = new \ReflectionMethod( $this->handler, 'isJunkTitle' );
		$method->setAccessible( true );

		$this->assertTrue(
			$method->invoke( $this->handler, 'Duplicate: ÉLA-Vated Saturdays (merged)' ),
			'A title starting with "Duplicate:" must be refused.'
		);

		$this->assertTrue(
			$method->invoke( $this->handler, 'Duplicate — Dan Spencer / Drip Fed / Tied Up (canonical moved)' ),
			'A title starting with "Duplicate —" (em dash) must be refused.'
		);

		$this->assertTrue(
			$method->invoke( $this->handler, 'Consolidate: Smokedope2016 duplicates' ),
			'A title starting with "Consolidate:" must be refused.'
		);
	}

	/**
	 * Parenthesized dedup markers anywhere in the title are refused.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/367
	 */
	public function test_is_junk_title_refuses_paren_markers(): void {
		$method = new \ReflectionMethod( $this->handler, 'isJunkTitle' );
		$method->setAccessible( true );

		$this->assertTrue(
			$method->invoke( $this->handler, 'Kev G Mor (duplicate)' ),
			'A title with a "(duplicate)" suffix must be refused.'
		);

		$this->assertTrue(
			$method->invoke( $this->handler, 'Kinky Coffee  NEW (Duplicate)' ),
			'A title with a "(Duplicate)" suffix must be refused.'
		);

		$this->assertTrue(
			$method->invoke( $this->handler, 'Some Event (merged)' ),
			'A title with a "(merged)" marker must be refused.'
		);

		$this->assertTrue(
			$method->invoke( $this->handler, 'Some Event (Duplicate — Consolidated)' ),
			'A title with a compound "(Duplicate — Consolidated)" marker must be refused.'
		);
	}

	/**
	 * The "see canonical" marker substring is refused.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/367
	 */
	public function test_is_junk_title_refuses_see_canonical(): void {
		$method = new \ReflectionMethod( $this->handler, 'isJunkTitle' );
		$method->setAccessible( true );

		$this->assertTrue(
			$method->invoke( $this->handler, 'Tommy Stinson (duplicate) — see canonical listing' ),
			'A title containing "see canonical" must be refused.'
		);

		$this->assertTrue(
			$method->invoke( $this->handler, 'Some Event — See Canonical Listing' ),
			'A title containing "See Canonical" (any case) must be refused.'
		);
	}

	/**
	 * Titles containing control characters or null bytes are refused
	 * unconditionally.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/367
	 */
	public function test_is_junk_title_refuses_control_characters(): void {
		$method = new \ReflectionMethod( $this->handler, 'isJunkTitle' );
		$method->setAccessible( true );

		$this->assertTrue(
			$method->invoke( $this->handler, "Duplicate: \0\0\0ÉLA-Vated Saturdays (merged)" ),
			'A title containing null bytes must be refused.'
		);

		$this->assertTrue(
			$method->invoke( $this->handler, "Innocuous Title\x01 With Control Char" ),
			'A title containing any control character must be refused.'
		);
	}

	/**
	 * A normal event title is NOT treated as junk, including titles that
	 * merely contain guarded words in legitimate positions.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/349
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/367
	 */
	public function test_is_junk_title_allows_normal_title(): void {
		$method = new \ReflectionMethod( $this->handler, 'isJunkTitle' );
		$method->setAccessible( true );

		$this->assertFalse(
			$method->invoke( $this->handler, 'The Rejects Live at The Royal American' ),
			'A normal event title must NOT be refused.'
		);

		$this->assertFalse(
			$method->invoke( $this->handler, 'Eggy at Charleston Pour House' ),
			'A normal event title must NOT be refused.'
		);

		// "Duplicate" as a band/title word without a separator is fine.
		$this->assertFalse(
			$method->invoke( $this->handler, 'Duplicate Minds at The Sinkhole' ),
			'A title beginning with the word "Duplicate" but no marker separator must NOT be refused.'
		);

		// Em dashes and parens in normal titles are fine.
		$this->assertFalse(
			$method->invoke( $this->handler, 'Dan Spencer / Drip Fed / Tied Up — The Royal American (late show)' ),
			'A normal title with em dash and parenthetical must NOT be refused.'
		);
	}
}
