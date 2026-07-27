<?php
/**
 * Real MySQL atomic source-update coverage.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace DataMachineEvents\Tests\Integration;

use DataMachineEvents\Abilities\EventUpdateAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class EventSourceUpdateMySqlAtomicityTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		if ( ! $wpdb->dbh instanceof \mysqli ) {
			$this->markTestSkipped( 'Atomic update integration skipped: WordPress is not using MySQL.' );
		}
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	public function tearDown(): void {
		remove_all_filters( 'datamachine_events_update_source_event_permission' );
		remove_all_actions( 'datamachine_events_after_event_venue_mutation' );
		parent::tearDown();
	}

	public function test_second_connection_never_observes_combined_update_half_applied(): void {
		global $table_prefix;
		add_filter( 'datamachine_events_update_source_event_permission', '__return_true' );
		$previous = wp_insert_term( 'Atomic Previous ' . uniqid(), 'venue' );
		$next     = wp_insert_term( 'Atomic Next ' . uniqid(), 'venue' );
		$this->assertNotWPError( $previous );
		$this->assertNotWPError( $next );
		$event_id = self::factory()->post->create(
			array(
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01","startTime":"20:00"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);
		wp_set_post_terms( $event_id, array( $previous['term_id'] ), 'venue' );
		$identity = hash( 'sha256', "booking\0booking-atomic" );
		update_post_meta( $event_id, '_datamachine_event_source', 'booking' );
		update_post_meta( $event_id, '_datamachine_event_source_id', 'booking-atomic' );
		update_post_meta( $event_id, '_datamachine_event_source_identity', $identity );
		$observed = null;

		add_action(
			'datamachine_events_after_event_venue_mutation',
			static function () use ( &$observed, $event_id, $table_prefix ): void {
				$reader = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
				$reader->set_prefix( $table_prefix );
				$content = $reader->get_var( $reader->prepare( "SELECT post_content FROM {$reader->posts} WHERE ID = %d", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent connection proves transaction isolation.
				$terms = $reader->get_col(
					$reader->prepare(
						"SELECT tt.term_id FROM {$reader->term_relationships} tr INNER JOIN {$reader->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = 'venue'",
						$event_id
					)
				); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent connection proves transaction isolation.
				$observed = array( (string) $content, array_map( 'intval', $terms ) );
				$reader->close();
			},
			10,
			0
		);

		$result = ( new EventUpdateAbilities() )->executeUpdateSourceEvent(
			array(
				'event'                => $event_id,
				'source'               => 'booking',
				'source_id'            => 'booking-atomic',
				'source_identity'      => $identity,
				'expected_fingerprint' => EventUpdateAbilities::fingerprintForEvent( $event_id, 'booking', 'booking-atomic', $identity ),
				'venue'                => (int) $next['term_id'],
				'startTime'            => '21:00',
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( '"startTime":"20:00"', $observed[0] );
		$this->assertSame( array( (int) $previous['term_id'] ), $observed[1] );
		$this->assertStringContainsString( '"startTime":"21:00"', get_post( $event_id )->post_content );
		$this->assertSame( array( (int) $next['term_id'] ), wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) );
	}
}
