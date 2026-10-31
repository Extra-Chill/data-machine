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
	 * @param string $city    City attribute.
	 * @return int
	 */
	private function create_event( string $title, string $venue, string $address, string $city = '' ): int {
		$attrs = array(
			'startDate' => '2030-08-23',
			'startTime' => '20:00',
			'address'   => $address,
			'city'      => $city,
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
		// Two same-name terms with no stored geography — both compatible, so
		// the identity is genuinely ambiguous (#803).
		$name   = 'Ambiguous Repair Twins ' . uniqid();
		$first  = wp_insert_term( $name, 'venue' );
		$this->assertNotWPError( $first );
		$second = wp_insert_term( $name, 'venue', array( 'slug' => sanitize_title( $name ) . '-2' ) );
		$this->assertNotWPError( $second );
		$this->term_ids[] = (int) $first['term_id'];
		$this->term_ids[] = (int) $second['term_id'];

		$post_id = $this->create_event( 'Ambiguous Event ' . uniqid(), $name, '' );

		$result = ( new MissingVenueRepairer() )->repair( 'all', 90, true );

		$candidate = $this->candidate_for( $result, $post_id );
		$this->assertNotNull( $candidate );
		$this->assertSame( 'ambiguous', $candidate['match_status'] );
		$this->assertFalse( $candidate['assigned'] );
		$this->assertSame( 1, $result['ambiguous'] );
		$this->assertSame( 0, $result['assigned'] );
		$this->assertSame( array(), wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
	}

	public function test_conflict_event_creates_and_assigns_distinct_term_on_apply(): void {
		$name      = 'Congress Repair Venue ' . uniqid();
		$stored_id = $this->create_venue(
			$name,
			array( 'address' => '8504 S Congress Ave', 'city' => 'Austin' )
		);
		$post_id = $this->create_event( 'Conflict Apply Event ' . uniqid(), $name, '8505 S Congress Ave', 'Austin' );

		$result = ( new MissingVenueRepairer() )->repair( 'all', 90, true );

		$candidate = $this->candidate_for( $result, $post_id );
		$this->assertNotNull( $candidate );
		$this->assertSame( 'conflict', $candidate['match_status'] );
		$this->assertTrue( $candidate['created'] );
		$this->assertNotSame( $stored_id, $candidate['term_id'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['assigned'] );
		$this->assertNotSame( '', get_post_meta( $post_id, MissingVenueRepairer::REPAIRED_AT_META, true ) );

		$assigned_ids = array_map( 'intval', wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertSame( array( (int) $candidate['term_id'] ), $assigned_ids );

		// The distinct term carries the incoming geography (#806).
		$this->assertSame( '8505 S Congress Ave', get_term_meta( (int) $candidate['term_id'], '_venue_address', true ) );
		$this->assertSame( 'Austin', get_term_meta( (int) $candidate['term_id'], '_venue_city', true ) );
	}

	public function test_conflict_event_dry_run_reports_without_creating(): void {
		$name      = 'Congress Dry Run Venue ' . uniqid();
		$stored_id = $this->create_venue(
			$name,
			array( 'address' => '8504 S Congress Ave', 'city' => 'Austin' )
		);
		$post_id = $this->create_event( 'Conflict Dry Run Event ' . uniqid(), $name, '8505 S Congress Ave' );

		$result = ( new MissingVenueRepairer() )->repair( 'all', 90, false );

		$candidate = $this->candidate_for( $result, $post_id );
		$this->assertNotNull( $candidate );
		$this->assertSame( 'conflict', $candidate['match_status'] );
		$this->assertFalse( $candidate['created'] );
		$this->assertFalse( $candidate['assigned'] );
		$this->assertNull( $candidate['term_id'] );
		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 0, $result['assigned'] );
		$this->assertSame( array(), wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'ids' ) ) );
		$this->assertGreaterThan( 0, $stored_id );
	}

	public function test_conflicts_report_groups_unresolved_candidates_by_venue_name(): void {
		$name      = 'Foundry Report Venue ' . uniqid();
		$stored_id = $this->create_venue(
			$name,
			array( 'address' => '250 W Washington St', 'city' => 'Athens' )
		);

		$post_one = $this->create_event( 'Foundry Report Event One ' . uniqid(), $name, '4256 Pearl Rd' );
		$post_two = $this->create_event( 'Foundry Report Event Two ' . uniqid(), $name, '2035 W 29th St' );
		update_post_meta( $post_one, '_datamachine_post_flow_id', 321 );
		update_post_meta( $post_two, '_datamachine_post_flow_id', 654 );

		$groups = ( new MissingVenueRepairer() )->conflicts_report( 'all', 90 );

		$group = null;
		foreach ( $groups as $candidate_group ) {
			if ( $name === $candidate_group['venue_name'] ) {
				$group = $candidate_group;
				break;
			}
		}

		$this->assertNotNull( $group, 'The conflicting venue name must be grouped.' );
		$this->assertSame( 2, $group['event_count'] );
		$this->assertEqualsCanonicalizing( array( '4256 Pearl Rd', '2035 W 29th St' ), $group['incoming_addresses'] );
		$this->assertEqualsCanonicalizing( array( 321, 654 ), $group['flow_ids'] );
		$this->assertCount( 1, $group['stored'] );
		$this->assertSame( $stored_id, $group['stored'][0]['term_id'] );
		$this->assertSame( '250 W Washington St', $group['stored'][0]['address'] );
		$this->assertSame( 'Athens', $group['stored'][0]['city'] );
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
