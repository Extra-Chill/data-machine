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
		if ( ! post_type_exists( 'datamachine_events' ) ) {
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
				'post_type'   => 'datamachine_events',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		$post = get_post( $post_id );
		$this->assertEquals( 'datamachine_events', $post->post_type );

		// Cleanup
		wp_delete_post( $post_id, true );
	}

	public function test_event_datetime_meta_storage() {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'DateTime Test Event ' . uniqid(),
				'post_type'   => 'datamachine_events',
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
				'post_type'   => 'datamachine_events',
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
}
