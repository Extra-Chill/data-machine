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

use DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex;
use DataMachine\Core\EngineData;
use WP_UnitTestCase;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator;
use DataMachineEvents\Blocks\Calendar\Query\UpcomingFilter;

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
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		if ( class_exists( PostIdentityIndex::class ) ) {
			( new PostIdentityIndex() )->create_table();
		}
		CacheInvalidator::init();

		$this->handler = new EventUpsert();
	}

	public function test_handler_instantiation() {
		$this->assertInstanceOf( EventUpsert::class, $this->handler );
	}

	public function test_consumer_can_provide_automated_import_author(): void {
		$user_id = self::factory()->user->create();
		$callback = static function ( int $author_id ) use ( $user_id ): int {
			return $user_id;
		};
		add_filter( 'data_machine_events_fallback_author_id', $callback );

		$method = new \ReflectionMethod( $this->handler, 'resolvePostAuthor' );
		$method->setAccessible( true );
		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$this->assertSame( $user_id, $method->invoke( $this->handler, array(), $engine ) );
		remove_filter( 'data_machine_events_fallback_author_id', $callback );
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

	public function test_event_datetime_table_storage() {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'DateTime Test Event ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);

		$datetime = '2026-06-15 19:30:00';
		EventDatesTable::upsert( $post_id, $datetime );

		$stored = EventDatesTable::get( $post_id );
		$this->assertNotNull( $stored );
		$this->assertEquals( $datetime, $stored->start_datetime );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	/**
	 * save_post must populate the datamachine_event_dates table from the
	 * Event Details block attributes (block = authoring source of truth,
	 * table = query source of truth).
	 *
	 * @see https://github.com/Extra-Chill/data-machine-events/issues/424
	 */
	public function test_save_post_populates_table_from_block(): void {
		$start_date = '2026-08-15';
		$start_time = '20:00';
		$end_date   = '2026-08-15';
		$end_time   = '23:00';

		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Block Sync Test ' . uniqid(),
				'post_type'    => 'data_machine_events',
				'post_status'  => 'publish',
				'post_content' => sprintf(
					'<!-- wp:data-machine-events/event-details {"startDate":"%s","startTime":"%s","endDate":"%s","endTime":"%s"} --><div class="wp-block-data-machine-events-event-details"></div><!-- /wp:data-machine-events/event-details -->',
					$start_date,
					$start_time,
					$end_date,
					$end_time
				),
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		$dates = EventDatesTable::get( $post_id );
		$this->assertNotNull( $dates, 'save_post should have written a row to the event_dates table.' );
		$this->assertEquals( '2026-08-15 20:00:00', $dates->start_datetime );
		$this->assertEquals( '2026-08-15 23:00:00', $dates->end_datetime );

		// Calendar hydration (EventHydrator) must read the same values from the table.
		$post = get_post( $post_id );
		$data = \DataMachineEvents\Blocks\Calendar\Data\EventHydrator::parse_event_data( $post );
		$this->assertNotNull( $data );
		$this->assertEquals( '2026-08-15', $data['startDate'] );
		$this->assertEquals( '20:00:00', $data['startTime'] );
		$this->assertEquals( '2026-08-15', $data['endDate'] );
		$this->assertEquals( '23:00:00', $data['endTime'] );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	public function test_save_post_deletes_indexed_dates_when_event_details_block_is_removed(): void {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Block Removal Sync Test ' . uniqid(),
				'post_type'    => 'data_machine_events',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2026-08-15","startTime":"20:00"} --><div class="wp-block-data-machine-events-event-details"></div><!-- /wp:data-machine-events/event-details -->',
			)
		);

		$this->assertNotNull( EventDatesTable::get( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<!-- wp:paragraph --><p>Event details removed.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertNull( EventDatesTable::get( $post_id ), 'Removing the source block must delete the stale date index row.' );
	}

	public function test_no_change_upsert_repairs_indexes_and_invalidates_cache_without_content_or_image_work(): void {
		$title      = 'No Change Integrity Repair ' . uniqid();
		$venue_name = 'No Change Repair Venue ' . uniqid();
		$engine     = new EngineData(
			array(
				'image_file_path' => '/missing/no-change-image.jpg',
			),
			0
		);
		$parameters = array(
			'title'       => $title,
			'venue'       => $venue_name,
			'startDate'   => '2026-10-15',
			'startTime'   => '20:00',
			'description' => 'Integration coverage for unchanged event reconciliation.',
			'engine'      => $engine,
			'job_id'      => 0,
		);
		$config     = array(
			'post_status'    => 'publish',
			'post_author'    => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			'include_images' => true,
		);
		$execute    = new \ReflectionMethod( $this->handler, 'executeUpsert' );
		$execute->setAccessible( true );

		$created = $execute->invoke( $this->handler, $parameters, $config );
		$this->assertTrue( $created['success'] ?? false );
		$this->assertSame( 'created', $created['data']['action'] ?? '' );

		$post_id = (int) $created['data']['post_id'];
		$venue   = get_term_by( 'name', $venue_name, 'venue' );
		$this->assertInstanceOf( \WP_Term::class, $venue );

		wp_set_object_terms( $post_id, array(), 'venue' );
		$identity = ( new PostIdentityIndex() )->get( $post_id );
		$this->assertNull( $identity['venue_term_id'] );

		$cache_key = CalendarCache::PREFIX . 'no_change_repair_' . uniqid();
		set_transient( $cache_key, 'stale', HOUR_IN_SECONDS );
		$content_before     = get_post_field( 'post_content', $post_id );
		$attachments_before = (int) wp_count_posts( 'attachment' )->inherit;
		$image_attempts     = 0;
		$log_listener       = static function ( $level, $message ) use ( &$image_attempts ): void {
			if ( str_contains( (string) $message, 'Image file not found for attachment' ) ) {
				++$image_attempts;
			}
		};
		add_action( 'datamachine_log', $log_listener, 10, 2 );
		$cache_invalidations = 0;
		$query_listener      = static function ( string $query ) use ( &$cache_invalidations ): string {
			if ( str_starts_with( ltrim( $query ), 'DELETE FROM' ) && str_contains( $query, '_transient_' . CalendarCache::PREFIX ) ) {
				++$cache_invalidations;
			}
			return $query;
		};
		add_filter( 'query', $query_listener );

		$repaired = $execute->invoke( $this->handler, $parameters, $config );

		remove_action( 'datamachine_log', $log_listener, 10 );
		remove_filter( 'query', $query_listener );
		$this->assertTrue( $repaired['success'] ?? false );
		$this->assertSame( 'no_change', $repaired['data']['action'] ?? '' );
		$this->assertSame( $content_before, get_post_field( 'post_content', $post_id ) );
		$this->assertSame( 0, $image_attempts, 'The no_change path must not invoke image attachment work.' );
		$this->assertSame( $attachments_before, (int) wp_count_posts( 'attachment' )->inherit );
		$this->assertSame( array( $venue->term_id ), wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertSame( (string) $venue->term_id, (string) ( new PostIdentityIndex() )->get( $post_id )['venue_term_id'] );
		$this->assertSame( 1, $cache_invalidations, 'A no_change taxonomy repair must invalidate canonical caches exactly once.' );
		$this->assertFalse( get_transient( $cache_key ), 'Taxonomy-only repair must invalidate calendar caches.' );
	}

	public function test_save_post_normalizes_implicit_overnight_end_time(): void {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Implicit Overnight Sync Test ' . uniqid(),
				'post_type'    => 'data_machine_events',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2026-08-15","startTime":"23:00","endTime":"02:00"} --><div class="wp-block-data-machine-events-event-details"></div><!-- /wp:data-machine-events/event-details -->',
			)
		);

		$dates = EventDatesTable::get( $post_id );
		$this->assertNotNull( $dates );
		$this->assertSame( '2026-08-15 23:00:00', $dates->start_datetime );
		$this->assertSame( '2026-08-16 02:00:00', $dates->end_datetime );

		global $wpdb;
		$table      = EventDatesTable::table_name();
		$comparison = '2026-08-16 01:00:00';
		$visible    = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} ed WHERE ed.post_id = %d AND " . UpcomingFilter::upcoming_where( $comparison ), $post_id )
		);
		$this->assertSame( 1, $visible, 'The overnight event must remain in the canonical upcoming filter after midnight.' );
	}

	public function test_save_post_preserves_explicit_contradictory_end_datetime(): void {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Explicit Contradiction Sync Test ' . uniqid(),
				'post_type'    => 'data_machine_events',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2026-08-15","startTime":"23:00","endDate":"2026-08-15","endTime":"02:00"} --><div class="wp-block-data-machine-events-event-details"></div><!-- /wp:data-machine-events/event-details -->',
			)
		);

		$dates = EventDatesTable::get( $post_id );
		$this->assertNotNull( $dates );
		$this->assertSame( '2026-08-15 02:00:00', $dates->end_datetime );
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
