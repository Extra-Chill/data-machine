<?php
/**
 * EventIdentityWriter regression tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex;
use DataMachineEvents\Core\DuplicateDetection\EventIdentityWriter;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;
use WP_UnitTestCase;

class EventIdentityWriterTest extends WP_UnitTestCase {

	private PostIdentityIndex $index;

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

		$this->index = new PostIdentityIndex();
		$this->index->create_table();

		if ( false === has_action( 'set_object_terms', array( EventIdentityWriter::class, 'onVenueTermsChanged' ) ) ) {
			EventIdentityWriter::register();
		}
	}

	public function test_venue_reassignment_updates_identity_row(): void {
		$post_id = $this->make_event();
		$first   = wp_insert_term( 'First Identity Venue ' . uniqid(), 'venue' );
		$second  = wp_insert_term( 'Second Identity Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );

		wp_set_object_terms( $post_id, array( $first['term_id'] ), 'venue' );
		$this->assertSame( (string) $first['term_id'], (string) $this->index->get( $post_id )['venue_term_id'] );

		wp_set_object_terms( $post_id, array( $second['term_id'] ), 'venue' );
		$this->assertSame( (string) $second['term_id'], (string) $this->index->get( $post_id )['venue_term_id'] );
	}

	public function test_venue_removal_clears_identity_venue(): void {
		$post_id = $this->make_event();
		$venue   = wp_insert_term( 'Removed Identity Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );

		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );
		wp_set_object_terms( $post_id, array(), 'venue' );

		$row = $this->index->get( $post_id );
		$this->assertNotNull( $row );
		$this->assertNull( $row['venue_term_id'], 'Removing the venue relationship must clear the indexed venue.' );
	}

	public function test_repeated_venue_assignment_keeps_one_current_identity_row(): void {
		$post_id = $this->make_event();
		$venue   = wp_insert_term( 'Idempotent Identity Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );

		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );
		wp_set_object_terms( $post_id, array( $venue['term_id'] ), 'venue' );

		global $wpdb;
		$table = $wpdb->prefix . PostIdentityIndex::TABLE_NAME;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) );

		$this->assertSame( 1, $count );
		$this->assertSame( (string) $venue['term_id'], (string) $this->index->get( $post_id )['venue_term_id'] );
	}

	public function test_removing_event_details_block_deletes_identity_row(): void {
		$post_id = $this->make_event();
		$this->assertNotNull( $this->index->get( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<!-- wp:paragraph --><p>No event details.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertNull( $this->index->get( $post_id ) );
	}

	public function test_removing_ticket_url_clears_identity_ticket(): void {
		$post_id = $this->make_event( 'https://tickets.example.com/event/123' );
		$this->assertSame( 'https://tickets.example.com/event/123', $this->index->get( $post_id )['ticket_url'] );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2026-09-20","startTime":"20:00"} --><div class="wp-block-data-machine-events-event-details"></div><!-- /wp:data-machine-events/event-details -->',
			)
		);

		$this->assertSame( '', get_post_meta( $post_id, EVENT_TICKET_URL_META_KEY, true ) );
		$this->assertNull( $this->index->get( $post_id )['ticket_url'], 'Deleting ticket meta must clear the identity ticket URL after date sync runs.' );
	}

	private function make_event( string $ticket_url = '' ): int {
		$ticket_attribute = $ticket_url ? ',"ticketUrl":"' . $ticket_url . '"' : '';

		return wp_insert_post(
			array(
				'post_title'   => 'Identity Event ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2026-09-20","startTime":"20:00"' . $ticket_attribute . '} --><div class="wp-block-data-machine-events-event-details"></div><!-- /wp:data-machine-events/event-details -->',
			)
		);
	}
}
