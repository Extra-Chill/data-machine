<?php
/**
 * EventTaxonomyAssigner Tests
 *
 * Direct unit tests for the venue/promoter taxonomy assignment collaborator
 * extracted from EventUpsert in #425.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\Upsert\Events\EventTaxonomyAssigner;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

class EventTaxonomyAssignerTest extends WP_UnitTestCase {

	private EventTaxonomyAssigner $assigner;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! taxonomy_exists( 'location' ) ) {
			// The `location` taxonomy is owned by the consumer layer, not this
			// substrate. Register a minimal hierarchical instance for tests.
			register_taxonomy(
				'location',
				'data_machine_events',
				array(
					'hierarchical' => true,
					'public'       => true,
					'show_in_rest' => true,
				)
			);
		}

		$this->assigner = new EventTaxonomyAssigner();
	}

	public function test_assigner_instantiation() {
		$this->assertInstanceOf( EventTaxonomyAssigner::class, $this->assigner );
	}

	public function test_process_venue_assigns_venue_term() {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Venue Assign Test ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		$venue_name = 'Test Assigner Venue ' . uniqid();
		$engine     = new \DataMachine\Core\EngineData( array( 'venue' => $venue_name ), 0 );

		$this->assigner->processVenue( $post_id, array(), $engine );

		$terms = wp_get_object_terms( $post_id, 'venue' );
		$this->assertNotWPError( $terms );
		$this->assertCount( 1, $terms, 'processVenue must assign exactly one venue term.' );
		$this->assertSame( $venue_name, $terms[0]->name );

		wp_delete_post( $post_id, true );
		wp_delete_term( $terms[0]->term_id, 'venue' );
	}

	public function test_process_venue_skips_when_no_venue() {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'No Venue Test ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		// No venue in engine data or parameters.
		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$this->assigner->processVenue( $post_id, array(), $engine );

		$terms = wp_get_object_terms( $post_id, 'venue' );
		$this->assertNotWPError( $terms );
		$this->assertCount( 0, $terms, 'processVenue must not assign a venue when none is provided.' );

		wp_delete_post( $post_id, true );
	}

	public function test_process_venue_repairs_missing_taxonomy_without_changing_content(): void {
		$post_id = $this->make_event_post();
		$content = get_post_field( 'post_content', $post_id );
		$venue   = 'Taxonomy Repair Venue ' . uniqid();
		$engine  = new \DataMachine\Core\EngineData( array( 'venue' => $venue ), 0 );

		$this->assigner->processVenue( $post_id, array(), $engine );

		$terms = wp_get_object_terms( $post_id, 'venue' );
		$this->assertNotWPError( $terms );
		$this->assertCount( 1, $terms, 'An unchanged event must repair its missing venue relationship.' );
		$this->assertSame( $venue, $terms[0]->name );
		$this->assertSame( $content, get_post_field( 'post_content', $post_id ) );

		wp_delete_post( $post_id, true );
		wp_delete_term( $terms[0]->term_id, 'venue' );
	}

	public function test_process_venue_is_idempotent_when_assignment_is_already_correct(): void {
		$post_id = $this->make_event_post();
		$venue   = 'Idempotent Venue ' . uniqid();
		$engine  = new \DataMachine\Core\EngineData( array( 'venue' => $venue ), 0 );

		$this->assigner->processVenue( $post_id, array(), $engine );
		$this->assigner->processVenue( $post_id, array(), $engine );

		$terms = wp_get_object_terms( $post_id, 'venue' );
		$this->assertNotWPError( $terms );
		$this->assertCount( 1, $terms, 'Repeated reconciliation must not duplicate venue relationships.' );
		$this->assertSame( $venue, $terms[0]->name );

		wp_delete_post( $post_id, true );
		wp_delete_term( $terms[0]->term_id, 'venue' );
	}

	public function test_process_venue_removes_stale_assignment_when_source_venue_is_empty(): void {
		$post_id = $this->make_event_post();
		$venue   = wp_insert_term( 'Removed Upsert Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );

		$this->assigner->processVenue( $post_id, array(), new \DataMachine\Core\EngineData( array(), 0 ) );

		$this->assertSame( array(), wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );

		wp_delete_post( $post_id, true );
		wp_delete_term( $venue['term_id'], 'venue' );
	}

	public function test_process_promoter_skips_when_selection_is_skip() {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Promoter Skip Test ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );

		$engine = new \DataMachine\Core\EngineData( array( 'organizer' => 'Test Promoter' ), 0 );

		// 'skip' selection must short-circuit before any promoter work.
		$this->assigner->processPromoter(
			$post_id,
			array( 'organizer' => 'Test Promoter' ),
			$engine,
			array( 'taxonomy_promoter_selection' => 'skip' )
		);

		$terms = wp_get_object_terms( $post_id, 'promoter' );
		if ( is_wp_error( $terms ) ) {
			// Promoter taxonomy may not be registered in this test context;
			// the important assertion is that processPromoter returned without
			// attempting assignment (skip short-circuit).
			$this->markTestSkipped( 'promoter taxonomy unavailable in test context' );
		}
		$this->assertCount( 0, (array) $terms, 'processPromoter must not assign when selection is skip.' );

		wp_delete_post( $post_id, true );
	}

	public function test_process_location_returns_false_for_skip_mode() {
		$post_id = $this->make_event_post();

		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$handled = $this->assigner->processLocation(
			$post_id,
			array(),
			$engine,
			array( 'taxonomy_location_selection' => 'skip' )
		);

		$this->assertFalse( $handled, 'processLocation must not take ownership for SKIP mode.' );
		wp_delete_post( $post_id, true );
	}

	public function test_process_location_returns_false_for_ai_decides_mode() {
		$post_id = $this->make_event_post();

		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$handled = $this->assigner->processLocation(
			$post_id,
			array(),
			$engine,
			array( 'taxonomy_location_selection' => 'ai_decides' )
		);

		$this->assertFalse( $handled, 'processLocation must defer AI_DECIDES to the generic taxonomy pass.' );
		wp_delete_post( $post_id, true );
	}

	/**
	 * The core #379 bug: a Houston venue fetched inside a Galveston-centered
	 * 50mi sweep must NOT inherit the pipeline's Galveston location term — it
	 * must carry Houston, derived from the venue's own city.
	 */
	public function test_process_location_derives_term_from_venue_city_not_pipeline_center() {
		$galveston = wp_insert_term( 'Galveston', 'location' );
		$houston   = wp_insert_term( 'Houston', 'location' );
		$this->assertNotWPError( $galveston );
		$this->assertNotWPError( $houston );

		$post_id = $this->make_event_post();

		// Attach a venue term whose city is Houston (the event's actual city).
		$venue = wp_insert_term( 'Toyota Center', 'venue' );
		$this->assertNotWPError( $venue );
		update_term_meta( $venue['term_id'], '_venue_city', 'Houston' );
		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );

		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$handled = $this->assigner->processLocation(
			$post_id,
			array(),
			$engine,
			array( 'taxonomy_location_selection' => (string) $galveston['term_id'] ) // pipeline center = Galveston
		);

		$this->assertTrue( $handled, 'processLocation must take ownership for PRE_SELECTED mode.' );

		$assigned = wp_get_object_terms( $post_id, 'location' );
		$this->assertNotWPError( $assigned );
		$this->assertCount( 1, $assigned, 'Exactly one location term must be assigned.' );
		$this->assertSame( 'Houston', $assigned[0]->name, 'Venue-city term must override the pipeline-center term.' );

		wp_delete_post( $post_id, true );
		wp_delete_term( $venue['term_id'], 'venue' );
		wp_delete_term( $houston['term_id'], 'location' );
		wp_delete_term( $galveston['term_id'], 'location' );
	}

	/**
	 * When the venue city is the pipeline's own city, the assignment is
	 * unchanged — the fix must not regress the normal in-city case.
	 */
	public function test_process_location_keeps_pipeline_term_when_venue_is_in_that_city() {
		$galveston = wp_insert_term( 'Galveston', 'location' );
		$this->assertNotWPError( $galveston );

		$post_id = $this->make_event_post();

		$venue = wp_insert_term( 'The Grand 1894 Opera House', 'venue' );
		$this->assertNotWPError( $venue );
		update_term_meta( $venue['term_id'], '_venue_city', 'Galveston' );
		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );

		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$this->assigner->processLocation(
			$post_id,
			array(),
			$engine,
			array( 'taxonomy_location_selection' => (string) $galveston['term_id'] )
		);

		$assigned = wp_get_object_terms( $post_id, 'location' );
		$this->assertNotWPError( $assigned );
		$this->assertCount( 1, $assigned );
		$this->assertSame( 'Galveston', $assigned[0]->name );

		wp_delete_post( $post_id, true );
		wp_delete_term( $venue['term_id'], 'venue' );
		wp_delete_term( $galveston['term_id'], 'location' );
	}

	/**
	 * When the venue city has no matching location term (e.g. an unmapped
	 * suburb), the pipeline's configured term is kept as a conservative
	 * fallback rather than dropping the event from the location archive.
	 */
	public function test_process_location_falls_back_to_pipeline_term_when_venue_city_unresolved() {
		$galveston = wp_insert_term( 'Galveston', 'location' );
		$this->assertNotWPError( $galveston );

		$post_id = $this->make_event_post();

		// Venue in "Tinyburg" — no matching location term exists.
		$venue = wp_insert_term( 'Tinyburg Hall', 'venue' );
		$this->assertNotWPError( $venue );
		update_term_meta( $venue['term_id'], '_venue_city', 'Tinyburg' );
		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );

		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		$this->assigner->processLocation(
			$post_id,
			array(),
			$engine,
			array( 'taxonomy_location_selection' => (string) $galveston['term_id'] )
		);

		$assigned = wp_get_object_terms( $post_id, 'location' );
		$this->assertNotWPError( $assigned );
		$this->assertCount( 1, $assigned );
		$this->assertSame( 'Galveston', $assigned[0]->name, 'Unresolved venue city must fall back to the pipeline term.' );

		wp_delete_post( $post_id, true );
		wp_delete_term( $venue['term_id'], 'venue' );
		wp_delete_term( $galveston['term_id'], 'location' );
	}

	/**
	 * The data_machine_events_resolve_event_location_term filter lets a
	 * consumer layer supply a richer resolver (e.g. suburb→market rollup).
	 */
	public function test_process_location_honors_consumer_filter_override() {
		$galveston = wp_insert_term( 'Galveston', 'location' );
		$houston   = wp_insert_term( 'Houston', 'location' );
		$this->assertNotWPError( $galveston );
		$this->assertNotWPError( $houston );

		$post_id = $this->make_event_post();

		// Venue in "Sugar Land" (a Houston suburb with no direct location term).
		$venue = wp_insert_term( 'Smart Financial Centre', 'venue' );
		$this->assertNotWPError( $venue );
		update_term_meta( $venue['term_id'], '_venue_city', 'Sugar Land' );
		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );

		$engine = new \DataMachine\Core\EngineData( array(), 0 );

		// Consumer filter rolls "Sugar Land" up to the Houston market.
		$callback = function () use ( $houston ) {
			return get_term( $houston['term_id'], 'location' );
		};
		add_filter( 'data_machine_events_resolve_event_location_term', $callback );

		$this->assigner->processLocation(
			$post_id,
			array(),
			$engine,
			array( 'taxonomy_location_selection' => (string) $galveston['term_id'] )
		);

		remove_filter( 'data_machine_events_resolve_event_location_term', $callback );

		$assigned = wp_get_object_terms( $post_id, 'location' );
		$this->assertNotWPError( $assigned );
		$this->assertCount( 1, $assigned );
		$this->assertSame( 'Houston', $assigned[0]->name, 'Consumer filter override must win.' );

		wp_delete_post( $post_id, true );
		wp_delete_term( $venue['term_id'], 'venue' );
		wp_delete_term( $houston['term_id'], 'location' );
		wp_delete_term( $galveston['term_id'], 'location' );
	}

	private function make_event_post(): int {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Location Test ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		return $post_id;
	}
}
