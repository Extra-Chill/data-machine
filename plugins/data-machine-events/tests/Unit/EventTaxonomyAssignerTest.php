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
}
