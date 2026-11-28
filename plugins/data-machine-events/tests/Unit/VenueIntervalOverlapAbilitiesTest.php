<?php
/**
 * Venue interval-overlap Ability tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\VenueIntervalOverlapAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class VenueIntervalOverlapAbilitiesTest extends WP_UnitTestCase {

	private VenueIntervalOverlapAbilities $ability;
	private int $venue_id;

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

		$venue = wp_insert_term( 'Overlap Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$this->venue_id = (int) $venue['term_id'];
		add_term_meta( $this->venue_id, '_venue_timezone', 'America/New_York', true );
		$this->ability = new VenueIntervalOverlapAbilities();
	}

	public function test_exact_half_open_overlap_relations_and_venue_isolation(): void {
		$expected = array(
			$this->seedEvent( 'Exact', '2027-06-01 10:00:00', '2027-06-01 12:00:00' ),
			$this->seedEvent( 'Starts inside', '2027-06-01 11:00:00', '2027-06-01 13:00:00' ),
			$this->seedEvent( 'Ends inside', '2027-06-01 09:00:00', '2027-06-01 11:00:00' ),
			$this->seedEvent( 'Contains', '2027-06-01 09:00:00', '2027-06-01 13:00:00' ),
			$this->seedEvent( 'Contained', '2027-06-01 10:30:00', '2027-06-01 11:30:00' ),
		);
		$this->seedEvent( 'Adjacent before', '2027-06-01 08:00:00', '2027-06-01 10:00:00' );
		$this->seedEvent( 'Adjacent after', '2027-06-01 12:00:00', '2027-06-01 14:00:00' );
		$this->seedEvent( 'Open', '2027-06-01 11:00:00', null );
		$this->seedEvent( 'Inverted', '2027-06-01 13:00:00', '2027-06-01 11:00:00' );

		$other_venue = wp_insert_term( 'Other Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $other_venue );
		$this->seedEvent( 'Other venue', '2027-06-01 10:00:00', '2027-06-01 12:00:00', 'publish', (int) $other_venue['term_id'] );

		$result = $this->query( '2027-06-01T10:00:00-04:00', '2027-06-01T12:00:00-04:00' );
		$this->assertIsArray( $result );
		$actual = array_column( $result['events'], 'event_id' );
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}

	public function test_multi_day_range_is_continuous_and_occurrence_dates_do_not_create_indexes(): void {
		$event_id = $this->seedEvent(
			'Discrete display dates',
			'2027-07-01 20:00:00',
			'2027-07-05 22:00:00',
			'publish',
			$this->venue_id,
			array( '2027-07-01', '2027-07-05' )
		);

		$result = $this->query( '2027-07-03T12:00:00-04:00', '2027-07-03T13:00:00-04:00' );
		$this->assertSame( array( $event_id ), array_column( $result['events'], 'event_id' ) );
	}

	public function test_rfc3339_instants_are_normalized_across_dst(): void {
		$event_id = $this->seedEvent( 'DST event', '2027-03-14 03:15:00', '2027-03-14 04:00:00' );

		$result = $this->query( '2027-03-14T06:30:00Z', '2027-03-14T07:30:00Z' );

		$this->assertSame( '2027-03-14T01:30:00-05:00', $result['interval']['start'] );
		$this->assertSame( '2027-03-14T03:30:00-04:00', $result['interval']['end'] );
		$this->assertSame( array( $event_id ), array_column( $result['events'], 'event_id' ) );
		$this->assertSame( '2027-03-14T03:15:00-04:00', $result['events'][0]['start'] );
	}

	public function test_fall_back_repeated_hour_and_local_inversion_are_rejected(): void {
		$repeated = $this->query( '2027-11-07T00:30:00-04:00', '2027-11-07T02:30:00-05:00' );
		$this->assertWPError( $repeated );
		$this->assertSame( 'venue_overlap_unrepresentable_interval', $repeated->get_error_code() );

		$inverted = $this->query( '2027-11-07T01:45:00-04:00', '2027-11-07T01:15:00-05:00' );
		$this->assertWPError( $inverted );
		$this->assertSame( 'venue_overlap_unrepresentable_interval', $inverted->get_error_code() );
	}

	public function test_fall_back_repeated_hour_adjacency_remains_queryable(): void {
		$before = $this->seedEvent( 'Before repeated hour', '2027-11-07 00:30:00', '2027-11-07 00:59:59' );
		$after  = $this->seedEvent( 'After repeated hour', '2027-11-07 02:00:00', '2027-11-07 03:00:00' );

		$this->assertSame( array( $before ), array_column( $this->query( '2027-11-07T00:00:00-04:00', '2027-11-07T01:00:00-04:00' )['events'], 'event_id' ) );
		$this->assertSame( array( $after ), array_column( $this->query( '2027-11-07T02:00:00-05:00', '2027-11-07T04:00:00-05:00' )['events'], 'event_id' ) );
	}

	public function test_ambiguous_and_nonexistent_returned_index_values_fail_closed(): void {
		$ambiguous = $this->seedEvent( 'Ambiguous index', '2027-11-07 01:30:00', '2027-11-07 03:00:00' );
		$result    = $this->query( '2027-11-07T02:00:00-05:00', '2027-11-07T04:00:00-05:00' );
		$this->assertWPError( $result );
		$this->assertSame( 'venue_overlap_unrepresentable_index', $result->get_error_code() );
		$this->assertSame( $ambiguous, $result->get_error_data()['event_id'] );

		EventDatesTable::upsert( $ambiguous, '2027-03-14 02:30:00', '2027-03-14 03:30:00', 'publish' );
		$result = $this->query( '2027-03-14T03:00:00-04:00', '2027-03-14T04:00:00-04:00' );
		$this->assertWPError( $result );
		$this->assertSame( 'venue_overlap_unrepresentable_index', $result->get_error_code() );
	}

	public function test_public_status_filter_excludes_non_published_and_tracks_transitions(): void {
		$published = $this->seedEvent( 'Published', '2027-08-01 10:00:00', '2027-08-01 11:00:00' );
		$draft     = $this->seedEvent( 'Draft', '2027-08-01 10:00:00', '2027-08-01 11:00:00', 'draft' );
		$cancelled = $this->seedEvent( 'Cancelled', '2027-08-01 10:00:00', '2027-08-01 11:00:00', 'cancelled' );
		$trash     = $this->seedEvent( 'Trash', '2027-08-01 10:00:00', '2027-08-01 11:00:00', 'trash' );

		$this->assertSame( array( $published ), array_column( $this->queryDay( '2027-08-01' )['events'], 'event_id' ) );

		wp_update_post( array( 'ID' => $published, 'post_status' => 'draft' ) );
		wp_update_post( array( 'ID' => $draft, 'post_status' => 'publish' ) );
		$this->assertSame( array( $draft ), array_column( $this->queryDay( '2027-08-01' )['events'], 'event_id' ) );
		$this->assertNotContains( $cancelled, array_column( $this->queryDay( '2027-08-01' )['events'], 'event_id' ) );
		$this->assertNotContains( $trash, array_column( $this->queryDay( '2027-08-01' )['events'], 'event_id' ) );
	}

	public function test_exclusions_and_bounded_stable_pagination(): void {
		$ids = array();
		for ( $hour = 10; $hour < 14; ++$hour ) {
			$ids[] = $this->seedEvent( 'Page ' . $hour, sprintf( '2027-09-01 %02d:00:00', $hour ), sprintf( '2027-09-01 %02d:30:00', $hour ) );
		}

		$first = $this->queryDay( '2027-09-01', array( 'per_page' => 2, 'exclude' => array( $ids[0] ) ) );
		$this->assertSame( array( $ids[1], $ids[2] ), array_column( $first['events'], 'event_id' ) );
		$this->assertTrue( $first['has_more'] );

		$second = $this->queryDay( '2027-09-01', array( 'per_page' => 2, 'page' => 2, 'exclude' => array( $ids[0] ) ) );
		$this->assertSame( array( $ids[3] ), array_column( $second['events'], 'event_id' ) );
		$this->assertFalse( $second['has_more'] );
	}

	public function test_venue_mutation_and_index_repair_change_the_next_result(): void {
		$event_id = $this->seedEvent( 'Mutable', '2027-10-01 10:00:00', '2027-10-01 11:00:00' );
		$this->assertSame( array( $event_id ), array_column( $this->queryDay( '2027-10-01' )['events'], 'event_id' ) );

		$other = wp_insert_term( 'Mutation Target ' . uniqid(), 'venue' );
		$this->assertNotWPError( $other );
		wp_set_object_terms( $event_id, array( (int) $other['term_id'] ), 'venue' );
		$this->assertSame( array(), $this->queryDay( '2027-10-01' )['events'] );

		wp_set_object_terms( $event_id, array( $this->venue_id ), 'venue' );
		EventDatesTable::upsert( $event_id, '2027-10-02 10:00:00', '2027-10-02 11:00:00', 'publish' );
		$this->assertSame( array(), $this->queryDay( '2027-10-01' )['events'] );
		EventDatesTable::upsert( $event_id, '2027-10-01 10:00:00', '2027-10-01 11:00:00', 'publish' );
		$this->assertSame( array( $event_id ), array_column( $this->queryDay( '2027-10-01' )['events'], 'event_id' ) );
	}

	public function test_source_owned_date_update_changes_the_next_result(): void {
		$event_id = $this->seedEvent( 'Source update', '2027-10-03 10:00:00', '2027-10-03 11:00:00' );
		$this->assertSame( array( $event_id ), array_column( $this->queryDay( '2027-10-03' )['events'], 'event_id' ) );

		wp_update_post(
			array(
				'ID'           => $event_id,
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2027-10-04","startTime":"10:00","endDate":"2027-10-04","endTime":"11:00"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);

		$this->assertSame( array(), $this->queryDay( '2027-10-03' )['events'] );
		$this->assertSame( array( $event_id ), array_column( $this->queryDay( '2027-10-04' )['events'], 'event_id' ) );
	}

	/**
	 * @dataProvider hostileInputs
	 */
	public function test_invalid_and_hostile_inputs_fail_closed( array $changes, string $code ): void {
		$result = $this->queryDay( '2027-11-01', $changes );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public static function hostileInputs(): array {
		return array(
			'missing offset' => array( array( 'start' => '2027-11-01T10:00:00' ), 'venue_overlap_invalid_datetime' ),
			'inverted'       => array( array( 'start' => '2027-11-02T00:00:00-04:00' ), 'venue_overlap_invalid_interval' ),
			'private status' => array( array( 'statuses' => array( 'private' ) ), 'rest_not_in_enum' ),
			'scalar status'  => array( array( 'statuses' => 'publish' ), 'rest_invalid_type' ),
			'excess exclude' => array( array( 'exclude' => range( 1, 101 ) ), 'rest_too_many_items' ),
			'excess page'    => array( array( 'page' => 10001 ), 'rest_out_of_bounds' ),
			'unknown field'  => array( array( 'unknown' => true ), 'rest_additional_properties_forbidden' ),
			'string venue'   => array( array( 'venue_id' => '1' ), 'rest_invalid_type' ),
			'zero venue'     => array( array( 'venue_id' => 0 ), 'rest_out_of_bounds' ),
			'string page'    => array( array( 'page' => '2' ), 'rest_invalid_type' ),
			'zero per page'  => array( array( 'per_page' => 0 ), 'rest_out_of_bounds' ),
			'duplicate IDs'  => array( array( 'exclude' => array( 1, 1 ) ), 'rest_duplicate_items' ),
			'string ID'      => array( array( 'exclude' => array( '1' ) ), 'rest_invalid_type' ),
			'duplicate status' => array( array( 'statuses' => array( 'publish', 'publish' ) ), 'rest_too_many_items' ),
			'invalid offset'   => array( array( 'start' => '2027-11-01T10:00:00+24:00' ), 'venue_overlap_invalid_datetime' ),
		);
	}

	public function test_malformed_calendar_date_is_rejected_without_normalization(): void {
		$result = $this->query( '2027-02-30T10:00:00-05:00', '2027-03-01T10:00:00-05:00' );
		$this->assertWPError( $result );
	}

	public function test_public_php_wrapper_uses_the_same_strict_validator(): void {
		$input             = $this->dayInput( '2027-12-01' );
		$input['venue_id'] = (string) $this->venue_id;

		$direct  = $this->ability->execute( $input );
		$wrapper = data_machine_events_query_venue_interval_overlaps( $input );
		$this->assertWPError( $direct );
		$this->assertWPError( $wrapper );
		$this->assertSame( $direct->get_error_code(), $wrapper->get_error_code() );
	}

	public function test_registered_ability_schema_and_read_contract_are_bounded(): void {
		$registered = wp_get_ability( VenueIntervalOverlapAbilities::ABILITY_NAME );
		$this->assertNotNull( $registered );
		$this->assertTrue( $registered->get_meta()['show_in_rest'] );
		$input = $registered->get_input_schema();
		$this->assertSame( array( 'venue_id', 'start', 'end' ), $input['required'] );
		$this->assertSame( 100, $input['properties']['per_page']['maximum'] );
		$this->assertSame( 10000, $input['properties']['page']['maximum'] );
		$this->assertSame( array( 'publish' ), $input['properties']['statuses']['items']['enum'] );

		$this->seedEvent( 'Contract', '2027-12-01 10:00:00', '2027-12-01 11:00:00' );
		$result = $registered->execute( $this->dayInput( '2027-12-01' ) );
		$this->assertIsArray( $result );
		$this->assertSame( array( 'venue_id', 'timezone', 'interval', 'events', 'page', 'per_page', 'has_more' ), array_keys( $result ) );
		$this->assertSame( array( 'event_id', 'start', 'end', 'status' ), array_keys( $result['events'][0] ) );
	}

	private function seedEvent( string $title, string $start, ?string $end, string $status = 'publish', ?int $venue_id = null, array $occurrence_dates = array() ): int {
		$attributes = array(
			'startDate'       => substr( $start, 0, 10 ),
			'startTime'       => substr( $start, 11, 5 ),
			'occurrenceDates' => $occurrence_dates,
		);
		if ( null !== $end ) {
			$attributes['endDate'] = substr( $end, 0, 10 );
			$attributes['endTime'] = substr( $end, 11, 5 );
		}
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => $status,
				'post_content' => '<!-- wp:data-machine-events/event-details ' . wp_json_encode( $attributes ) . ' --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);
		EventDatesTable::upsert( $post_id, $start, $end, $status );
		wp_set_object_terms( $post_id, array( $venue_id ?? $this->venue_id ), 'venue' );
		return $post_id;
	}

	private function query( string $start, string $end, array $changes = array() ): array|\WP_Error {
		return $this->ability->execute(
			array_merge(
				array(
					'venue_id' => $this->venue_id,
					'start'    => $start,
					'end'      => $end,
				),
				$changes
			)
		);
	}

	private function queryDay( string $date, array $changes = array() ): array|\WP_Error {
		return $this->ability->execute( array_merge( $this->dayInput( $date ), $changes ) );
	}

	private function dayInput( string $date ): array {
		$timezone = new \DateTimeZone( 'America/New_York' );
		$start    = new \DateTimeImmutable( $date . ' 00:00:00', $timezone );
		$end      = new \DateTimeImmutable( $date . ' 23:59:59', $timezone );

		return array(
			'venue_id' => $this->venue_id,
			'start'    => $start->format( DATE_RFC3339 ),
			'end'      => $end->format( DATE_RFC3339 ),
		);
	}
}
