<?php
/**
 * MissingVenueRepairer Tests
 *
 * Covers the #803 repair path: published events with no venue term are
 * resolved from their own event-details block attributes, matched terms are
 * assigned on apply (never created), and ambiguous/unmatched events are only
 * reported.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Core\MissingVenueRepairer;

class MissingVenueRepairerTest extends WP_UnitTestCase {
	/** @var int[] */
	private array $term_ids = array();

	/** @var int[] */
	private array $post_ids = array();

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );

		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		EventDatesTable::create_table();
	}

	public function tearDown(): void {
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( array_reverse( $this->term_ids ) as $term_id ) {
			wp_delete_term( $term_id, 'venue' );
		}
		global $wpdb;
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tearDown();
	}

	/**
	 * Create a venue term with exactly the meta supplied.
	 *
	 * @param string                $name Venue name.
	 * @param array<string, string> $meta Field => value using data keys (address, city, ...).
	 * @return int
	 */
	private function create_venue( string $name, array $meta = array() ): int {
		$result = wp_insert_term( $name, 'venue' );
		$this->assertNotWPError( $result );
		$term_id = (int) $result['term_id'];
		$this->term_ids[] = $term_id;

		foreach ( $meta as $field => $value ) {
			if ( isset( Venue_Taxonomy::$meta_fields[ $field ] ) ) {
				update_term_meta( $term_id, Venue_Taxonomy::$meta_fields[ $field ], $value );
			}
		}

		return $term_id;
	}

	/**
	 * Create a published event with an event-details block.
	 *
	 * @param string $title   Event title.
	 * @param string $venue   Venue attribute (may be empty).
	 * @param string $address Address attribute.
	 * @return int
	 */
	private function create_event( string $title, string $venue, string $address ): int {
		$attrs = array(
			'startDate' => '2030-08-23',
			'startTime' => '20:00',
			'address'   => $address,
		);

		if ( '' !== $venue ) {
			$attrs['venue'] = $venue;
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => sprintf(
					'<!-- wp:data-machine-events/event-details %s /-->',
					wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES )
				),
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Find the repair candidate row for a post.
	 *
	 * @param array $result Repair result.
	 * @param int   $post_id Post ID.
	 * @return array|null
	 */
	private function candidate_for( array $result, int $post_id ): ?array {
		foreach ( $result['candidates'] as $candidate ) {
			if ( (int) $candidate['post_id'] === $post_id ) {
				return $candidate;
			}
		}

		return null;
	}

	public function test_dry_run_reports_matched_without_assigning(): void {
		$name    = 'Far Out Lounge Repair Dry ' . uniqid();
		$term_id = $this->create_venue( $name, array( 'address' => '8504 South Congress Avenue' ) );
		$post_id = $this->create_event( 'Dry Run Event ' . uniqid(), $name, '8504 S Congress Ave' );

		$result = ( new MissingVenueRepairer() )->repair( 'all', 90, false );

		$candidate = $this->candidate_for( $result, $post_id );
		$this->assertNotNull( $candidate );
		$this->assertSame( 'matched', $candidate['match_status'] );
		$this->assertFalse( $candidate['assigned'] );

		// Dry run assigns nothing and writes no meta.
		$this->assertSame( array(), wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertSame( '', get_post_meta( $post_id, MissingVenueRepairer::REPAIRED_AT_META, true ) );
		$this->assertGreaterThan( 0, $term_id );
	}

	public function test_apply_assigns_matched_term_and_writes_meta(): void {
		$name    = 'Far Out Lounge Repair Apply ' . uniqid();
		$term_id = $this->create_venue( $name, array( 'address' => '8504 South Congress Avenue' ) );
		$post_id = $this->create_event( 'Apply Event ' . uniqid(), $name, '8504 S Congress Ave' );

		$result = ( new MissingVenueRepairer() )->repair( 'all', 90, true );

		$candidate = $this->candidate_for( $result, $post_id );
		$this->assertNotNull( $candidate );
		$this->assertSame( 'matched', $candidate['match_status'] );
		$this->assertTrue( $candidate['assigned'] );
		$this->assertSame( $term_id, $candidate['term_id'] );

		$this->assertSame( array( $term_id ), array_map( 'intval', wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) ) );
		$this->assertNotSame( '', get_post_meta( $post_id, MissingVenueRepairer::REPAIRED_AT_META, true ) );
	}

	public function test_ambiguous_event_is_reported_but_not_assigned(): void {
		$name    = 'Ambiguous Repair Venue ' . uniqid();
		$term_id = $this->create_venue( $name, array( 'address' => '8504 South Congress Avenue' ) );
		$post_id = $this->create_event( 'Ambiguous Event ' . uniqid(), $name, '8505 S Congress Ave' );

		$result = ( new MissingVenueRepairer() )->repair( 'all', 90, true );

		$candidate = $this->candidate_for( $result, $post_id );
		$this->assertNotNull( $candidate );
		$this->assertSame( 'ambiguous', $candidate['match_status'] );
		$this->assertFalse( $candidate['assigned'] );
		$this->assertSame( array(), wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertGreaterThan( 0, $term_id );
	}

	public function test_empty_block_venue_is_counted_as_empty(): void {
		$this->create_venue( 'Empty Attr Venue ' . uniqid(), array( 'address' => '100 King St' ) );
		$post_id = $this->create_event( 'Empty Venue Event ' . uniqid(), '', '100 King St' );

		$result = ( new MissingVenueRepairer() )->repair( 'all', 90, true );

		$candidate = $this->candidate_for( $result, $post_id );
		$this->assertNull( $candidate );
		$this->assertSame( 1, $result['empty'] );
		$this->assertSame( array(), wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
	}
}
