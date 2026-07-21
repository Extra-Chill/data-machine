<?php
/**
 * EventUpsertAbilities tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Core\Database\PostIdentityIndex\PostIdentityIndex;
use DataMachineEvents\Abilities\EventUpsertAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\Upsert\Events\EventUpsert;
use WP_UnitTestCase;

class EventUpsertAbilitiesTest extends WP_UnitTestCase {

	private EventUpsertAbilities $ability;

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
		if ( class_exists( PostIdentityIndex::class ) ) {
			( new PostIdentityIndex() )->create_table();
		}

		$this->ability = new EventUpsertAbilities();
	}

	public function test_creates_canonical_event_and_returns_normalized_metadata(): void {
		$result = $this->ability->executeUpsertEvent( $this->validInput() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'created', $result['action'] );
		$this->assertGreaterThan( 0, $result['event_id'] );
		$this->assertSame( 'publish', $result['normalized']['post_status'] );
		$this->assertSame( '2027-02-20 20:00:00', $result['normalized']['start_datetime'] );
		$this->assertGreaterThan( 0, $result['normalized']['venue_id'] );
		$this->assertSame(
			hash( 'sha256', $result['source']['name'] . "\0" . $result['source']['id'] ),
			get_post_meta( $result['event_id'], EventUpsert::SOURCE_IDENTITY_META_KEY, true )
		);
		$this->assertStringContainsString( 'wp:data-machine-events/event-details', get_post_field( 'post_content', $result['event_id'] ) );
	}

	public function test_replay_of_source_identity_returns_same_event(): void {
		$input  = $this->validInput();
		$first  = $this->ability->executeUpsertEvent( $input );
		$second = $this->ability->executeUpsertEvent( $input );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( $first['event_id'], $second['event_id'] );
		$this->assertSame( 'no_change', $second['action'] );
	}

	public function test_validation_failure_returns_machine_readable_error_without_write(): void {
		$input = $this->validInput();
		unset( $input['event']['startDate'] );

		$result = $this->ability->executeUpsertEvent( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_start_date', $result->get_error_code() );
		$this->assertSame( 0, $this->countEventsWithTitle( $input['event']['title'] ) );
	}

	public function test_ambiguous_venue_resolution_fails_before_event_write(): void {
		$input = $this->validInput();
		$name  = $input['event']['venue'];

		Venue_Taxonomy::find_or_create_venue(
			$name,
			array(
				'address' => '100 Main Street',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			)
		);
		$input['event']['venueAddress'] = '200 Main Street';
		$input['event']['venueCity']    = 'Atlanta';
		$input['event']['venueState']   = 'GA';

		$result = $this->ability->executeUpsertEvent( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'ambiguous_venue', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->countEventsWithTitle( $input['event']['title'] ) );
	}

	private function validInput(): array {
		$suffix = uniqid();

		return array(
			'source'    => 'unit-test-source',
			'source_id' => 'event-' . $suffix,
			'event'     => array(
				'title'        => 'Public Ability Event ' . $suffix,
				'description'  => '<p>Canonical event body.</p>',
				'startDate'    => '2027-02-20',
				'startTime'    => '20:00',
				'venue'        => 'Public Ability Venue ' . $suffix,
				'venueAddress' => '300 King Street',
				'venueCity'    => 'Charleston',
				'venueState'   => 'SC',
				'venueCountry' => 'US',
				'performer'    => 'Ability Performer',
				'ticketUrl'    => 'https://tickets.example/event-' . $suffix,
				'eventStatus'  => 'EventScheduled',
			),
		);
	}

	private function countEventsWithTitle( string $title ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'title'          => $title,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		return count( $query->posts );
	}
}
