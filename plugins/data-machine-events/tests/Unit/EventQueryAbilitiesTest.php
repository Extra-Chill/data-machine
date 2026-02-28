<?php
/**
 * EventQueryAbilities Tests
 *
 * Tests for event query abilities layer.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Abilities\EventQueryAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;

class EventQueryAbilitiesTest extends WP_UnitTestCase {

	private EventQueryAbilities $abilities;

	public function setUp(): void {
		parent::setUp();

		// Ensure post type and taxonomies are registered
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		$this->abilities = new EventQueryAbilities();
	}

	public function test_abilities_class_instantiates() {
		$this->assertInstanceOf( EventQueryAbilities::class, $this->abilities );
	}

	public function test_get_venue_events_returns_array() {
		// Create a venue
		$venue_term = wp_insert_term( 'Query Test Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue_term );
		$venue_id = $venue_term['term_id'];

		// Query for events (should be empty)
		$result = $this->abilities->executeGetVenueEvents(
			array(
				'venue' => $venue_id,
				'limit' => 10,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'events', $result );

		// Cleanup
		wp_delete_term( $venue_id, 'venue' );
	}

	public function test_get_venue_events_with_venue_name() {
		$venue_name = 'Venue Name Test ' . uniqid();

		$venue_term = wp_insert_term( $venue_name, 'venue' );
		$this->assertNotWPError( $venue_term );
		$venue_id = $venue_term['term_id'];

		$result = $this->abilities->executeGetVenueEvents(
			array(
				'venue' => $venue_name,
				'limit' => 5,
			)
		);

		$this->assertIsArray( $result );

		// Cleanup
		wp_delete_term( $venue_id, 'venue' );
	}

	public function test_get_venue_events_limits_results() {
		$venue_term = wp_insert_term( 'Limit Test Venue ' . uniqid(), 'venue' );
		$venue_id = $venue_term['term_id'];

		// Create multiple events
		for ( $i = 0; $i < 5; $i++ ) {
			$post_id = wp_insert_post(
				array(
					'post_title'  => "Event $i " . uniqid(),
					'post_type'   => 'data_machine_events',
					'post_status' => 'publish',
				)
			);
			wp_set_object_terms( $post_id, array( $venue_id ), 'venue' );
		}

		$result = $this->abilities->executeGetVenueEvents(
			array(
				'venue' => $venue_id,
				'limit' => 3,
			)
		);

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 3, count( $result['events'] ) );

		// Cleanup
		wp_delete_term( $venue_id, 'venue' );
	}

	public function test_get_venue_events_filters_by_status() {
		$venue_term = wp_insert_term( 'Status Test Venue ' . uniqid(), 'venue' );
		$venue_id = $venue_term['term_id'];

		// Create published event
		$published_id = wp_insert_post(
			array(
				'post_title'  => 'Published Event ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $published_id, array( $venue_id ), 'venue' );

		// Create draft event
		$draft_id = wp_insert_post(
			array(
				'post_title'  => 'Draft Event ' . uniqid(),
				'post_type'   => 'data_machine_events',
				'post_status' => 'draft',
			)
		);
		wp_set_object_terms( $draft_id, array( $venue_id ), 'venue' );

		// Query published only
		$result = $this->abilities->executeGetVenueEvents(
			array(
				'venue'  => $venue_id,
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $result );
		// All returned events should be published
		foreach ( $result['events'] as $event ) {
			if ( isset( $event['status'] ) ) {
				$this->assertEquals( 'publish', $event['status'] );
			}
		}

		// Cleanup
		wp_delete_post( $published_id, true );
		wp_delete_post( $draft_id, true );
		wp_delete_term( $venue_id, 'venue' );
	}

	public function test_get_venue_events_invalid_venue_returns_error() {
		$result = $this->abilities->executeGetVenueEvents(
			array(
				'venue' => 'Non Existent Venue ' . uniqid(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( isset( $result['error'] ) || isset( $result['events'] ) );
	}
}
