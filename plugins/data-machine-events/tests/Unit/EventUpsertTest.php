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

	public function test_datetime_confidence_full_with_date_and_time(): void {
		$method = new \ReflectionMethod( $this->handler, 'getDateTimeConfidence' );
		$method->setAccessible( true );

		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$result = $method->invoke(
			$this->handler,
			array(
				'title'     => 'Full Event',
				'startDate' => '2026-07-05',
				'startTime' => '20:00',
			),
			$engine
		);

		$this->assertSame( 'full', $result, 'A valid Y-m-d date plus a time must yield full datetime confidence.' );
	}

	/**
	 * Regression: an event with no startDate must be rejected at the upsert
	 * boundary — an undated event can never be published.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/415
	 */
	public function test_execute_upsert_rejects_empty_start_date(): void {
		$method = new \ReflectionMethod( $this->handler, 'executeUpsert' );
		$method->setAccessible( true );

		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$result = $method->invoke(
			$this->handler,
			array(
				'title'  => 'Undated Event ' . uniqid(),
				'engine' => $engine,
				'job_id' => 0,
			),
			array()
		);

		$this->assertFalse( $result['success'] ?? null, 'Upsert with an empty startDate must not succeed.' );
		$this->assertStringContainsString( 'startDate', $result['error'] ?? '', 'Error must reference the missing startDate.' );
	}

	/**
	 * Regression: a startTime present with an empty startDate is the exact
	 * failure signature of upstream date-extraction failure (the scraper got
	 * the time but not the date). It must be rejected, never published.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/415
	 */
	public function test_execute_upsert_rejects_start_time_without_start_date(): void {
		$method = new \ReflectionMethod( $this->handler, 'executeUpsert' );
		$method->setAccessible( true );

		$engine = new \DataMachine\Core\EngineData(
			array(
				'startTime' => '19:00',
			),
			0
		);

		$result = $method->invoke(
			$this->handler,
			array(
				'title'    => 'Time Only Event ' . uniqid(),
				'startTime' => '19:00',
				'engine'   => $engine,
				'job_id'   => 0,
			),
			array()
		);

		$this->assertFalse( $result['success'] ?? null, 'Upsert with a startTime but no startDate must not succeed.' );
		$this->assertStringContainsString( 'startDate', $result['error'] ?? '' );
	}

	/**
	 * Regression: an unparseable startDate (wrong format / impossible calendar
	 * date) must be rejected just like a missing one.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/415
	 */
	public function test_execute_upsert_rejects_unparseable_start_date(): void {
		$method = new \ReflectionMethod( $this->handler, 'executeUpsert' );
		$method->setAccessible( true );

		$engine = new \DataMachine\Core\EngineData(
			array(
				'startDate' => '2026-13-99',
			),
			0
		);

		$result = $method->invoke(
			$this->handler,
			array(
				'title'     => 'Bad Date Event ' . uniqid(),
				'startDate' => '2026-13-99',
				'engine'    => $engine,
				'job_id'    => 0,
			),
			array()
		);

		$this->assertFalse( $result['success'] ?? null, 'Upsert with an unparseable startDate must not succeed.' );
		$this->assertStringContainsString( 'startDate', $result['error'] ?? '' );
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

	/**
	 * Helper: invoke the private validateForPublish() gate directly.
	 */
	private function invoke_gate( array $evidence, array $parameters = array(), array $engine_data = array() ): ?array {
		$method = new \ReflectionMethod( $this->handler, 'validateForPublish' );
		$method->setAccessible( true );

		$engine = new \DataMachine\Core\EngineData( $engine_data, 0 );

		return $method->invoke( $this->handler, $evidence, $parameters, $engine );
	}

	/**
	 * A valid event must pass the gate cleanly (null return).
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/417
	 */
	public function test_gate_passes_valid_event(): void {
		$result = $this->invoke_gate(
			array(
				'title'       => 'Eggy at Charleston Pour House',
				'venue'       => 'Charleston Pour House',
				'startDate'   => '2026-08-01',
				'startTime'   => '20:00',
				'source_type' => '',
				'artist'      => 'Eggy',
			),
			array(
				'title'     => 'Eggy at Charleston Pour House',
				'startDate' => '2026-08-01',
				'startTime' => '20:00',
			)
		);

		$this->assertNull( $result, 'A valid event must pass the validation gate.' );
	}

	/**
	 * Empty startDate is rejected at the gate (folds in #415).
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/415
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/417
	 */
	public function test_gate_rejects_empty_start_date(): void {
		$result = $this->invoke_gate(
			array(
				'title'       => 'Undated Event ' . uniqid(),
				'venue'       => 'Some Venue',
				'startDate'   => '',
				'startTime'   => '',
				'source_type' => '',
				'artist'      => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] ?? null );
		$this->assertSame( 'invalid_start_date', $result['rule'] ?? '' );
		$this->assertStringContainsString( 'startDate', $result['error'] ?? '' );
	}

	/**
	 * A placeholder "Test Event" title is rejected at the gate regardless of
	 * source — the generic placeholder check is source-agnostic.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/417
	 */
	public function test_gate_rejects_placeholder_test_event_title(): void {
		$result = $this->invoke_gate(
			array(
				'title'       => 'Test Event',
				'venue'       => 'Some Venue',
				'startDate'   => '2026-08-01',
				'startTime'   => '20:00',
				'source_type' => 'universal_web_scraper',
				'artist'      => '',
			),
			array(
				'title'     => 'Test Event',
				'startDate' => '2026-08-01',
				'startTime' => '20:00',
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] ?? null );
		$this->assertSame( 'placeholder_title', $result['rule'] ?? '' );
	}

	/**
	 * A bare-punctuation title (e.g. "?") is rejected as noise.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/417
	 */
	public function test_gate_rejects_bare_question_mark_title(): void {
		$result = $this->invoke_gate(
			array(
				'title'       => '?',
				'venue'       => 'Some Venue',
				'startDate'   => '2026-08-01',
				'startTime'   => '',
				'source_type' => '',
				'artist'      => '',
			),
			array(
				'title'     => '?',
				'startDate' => '2026-08-01',
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] ?? null );
		$this->assertSame( 'placeholder_title', $result['rule'] ?? '' );
	}

	/**
	 * A junk/test payload is caught at the gate from a NON-Ticketmaster path.
	 *
	 * This is the key consolidation win: the JunkPayloadFilter (#416) now
	 * runs at the upsert boundary for every source, not only inside the
	 * Ticketmaster handler. Here a custom 'dice' source registers a junk
	 * pattern via the filter; the gate must reject a matching title even
	 * though Dice has no handler-level filter of its own.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/416
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/417
	 */
	public function test_gate_rejects_junk_payload_from_non_ticketmaster_source(): void {
		$callback = function ( array $patterns, string $source_type ): array {
			if ( 'dice' !== $source_type ) {
				return $patterns;
			}
			$patterns['title'][] = 'QA Sandbox';

			return $patterns;
		};
		add_filter( 'data_machine_events_junk_payload_patterns', $callback, 10, 2 );

		$result = $this->invoke_gate(
			array(
				'title'       => 'QA Sandbox Preview Night',
				'venue'       => 'Dice Venue',
				'startDate'   => '2026-09-01',
				'startTime'   => '21:00',
				'source_type' => 'dice',
				'artist'      => '',
			),
			array(
				'title'     => 'QA Sandbox Preview Night',
				'startDate' => '2026-09-01',
				'startTime' => '21:00',
			),
			array( 'source_type' => 'dice' )
		);

		remove_filter( 'data_machine_events_junk_payload_patterns', $callback, 10 );

		$this->assertIsArray( $result, 'A junk payload from a non-Ticketmaster source must be rejected at the gate.' );
		$this->assertFalse( $result['success'] ?? null );
		$this->assertSame( 'junk_payload', $result['rule'] ?? '' );
	}

	/**
	 * The placeholder title deny-list is filterable.
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/417
	 */
	public function test_placeholder_titles_are_filterable(): void {
		$callback = static function ( array $placeholders ): array {
			$placeholders[] = 'Placeholder Concert';

			return $placeholders;
		};
		add_filter( 'data_machine_events_placeholder_titles', $callback );

		$result = $this->invoke_gate(
			array(
				'title'       => 'Placeholder Concert',
				'venue'       => 'Some Venue',
				'startDate'   => '2026-08-01',
				'startTime'   => '',
				'source_type' => '',
				'artist'      => '',
			),
			array(
				'title'     => 'Placeholder Concert',
				'startDate' => '2026-08-01',
			)
		);

		remove_filter( 'data_machine_events_placeholder_titles', $callback );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] ?? null );
		$this->assertSame( 'placeholder_title', $result['rule'] ?? '' );
	}

	/**
	 * A title that merely CONTAINS a placeholder word but is not an exact
	 * match must pass (e.g. "Test Event Cancellation Policy" is not junk by
	 * this rule — substring junk detection is the JunkPayloadFilter's job).
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/417
	 */
	public function test_placeholder_check_is_exact_match_not_substring(): void {
		$result = $this->invoke_gate(
			array(
				'title'       => 'Upcoming Event Featuring Phish',
				'venue'       => 'Some Venue',
				'startDate'   => '2026-08-01',
				'startTime'   => '',
				'source_type' => '',
				'artist'      => 'Phish',
			),
			array(
				'title'     => 'Upcoming Event Featuring Phish',
				'startDate' => '2026-08-01',
			)
		);

		$this->assertNull( $result, 'A non-exact title containing a placeholder word must NOT be rejected by the placeholder rule.' );
	}
}
