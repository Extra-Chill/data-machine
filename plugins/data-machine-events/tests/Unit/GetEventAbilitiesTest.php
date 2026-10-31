<?php
/**
 * Public event detail lookup tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */
namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\GetEventAbilities;
use DataMachineEvents\Api\Controllers\Calendar;
use DataMachineEvents\Blocks\Calendar\Data\EventHydrator;
use DataMachineEvents\Blocks\Calendar\Grouping\DateGrouper;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;

class GetEventAbilitiesTest extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Event_Post_Type::register();
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
	}

	private function seed( string $status = 'publish', string $type = 'data_machine_events' ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => $type,
				'post_status'  => $status,
				'post_title'   => 'Detail lookup',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2099-07-21","startTime":"19:30:00","endDate":"2099-07-21","endTime":"22:00:00","performer":"Test Band","ticketUrl":"https://tickets.invalid/show"} /--><!-- wp:paragraph --><p>Event description.</p><!-- /wp:paragraph -->',
			)
		);
	}

	public function test_published_event_reuses_calendar_contract(): void {
		$id   = $this->seed();
		$post = get_post( $id );
		$data = EventHydrator::parse_event_data( $post );
		$this->assertNotNull( $data );
		$groups = DateGrouper::group_events_by_date(
			array(
				array(
					'post' => $post,
					'event_data' => $data,
					'datetime' => new \DateTime( '2099-07-21 19:30:00' ),
				),
			),
			true
		);
		$group    = reset( $groups );
		$expected = ( new Calendar() )->serialize_contract_occurrence(
			array(
				'post_id' => $id,
				'title' => get_the_title( $id ),
				'event_data' => $data,
				'display_context' => $group['events'][0]['display_context'],
			)
		);
		wp_set_current_user( 0 );
		$result = ( new GetEventAbilities() )->executeGetEvent( array( 'event_id' => $id ) );
		$this->assertIsArray( $result );
		foreach ( $expected['event'] as $key => $value ) {
			$this->assertSame( $value, $result[ $key ], $key );
		}
		$this->assertSame( $expected['occurrence'], $result['occurrence'] );
		$this->assertSame( $expected['occurrence']['display'], $result['occurrence_display'] );
		$this->assertSame( $expected['event']['ticket']['url'], $result['ticket_url'] );
		$this->assertStringContainsString( '2099-07-21T19:30:00', $result['datetime'] );
		$this->assertStringContainsString( '2099-07-21T22:00:00', $result['end_datetime'] );
		$this->assertStringContainsString( 'Event description.</p>', $result['content_html'] );
		$this->assertSame( get_permalink( $id ), $result['permalink'] );
	}

	public function test_draft_missing_and_wrong_post_type_return_404(): void {
		$ability = new GetEventAbilities();
		foreach ( array( $this->seed( 'draft' ), $this->seed( 'private' ), $this->seed( 'publish', 'post' ), 99999999, 0 ) as $id ) {
			$result = $ability->executeGetEvent( array( 'event_id' => $id ) );
			$this->assertWPError( $result );
			$this->assertSame( 404, $result->get_error_data()['status'] );
		}
	}

	public function test_password_protected_event_is_not_public_detail(): void {
		$id = $this->seed();
		wp_update_post( array(
			'ID' => $id,
			'post_password' => 'secret',
		) );
		$this->assertWPError( ( new GetEventAbilities() )->executeGetEvent( array( 'event_id' => $id ) ) );
	}

	public function test_ability_is_rest_visible_and_public(): void {
		$ability = wp_get_ability( 'data-machine-events/get-event' );
		$this->assertNotNull( $ability );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );
		wp_set_current_user( 0 );
		$this->assertIsArray( $ability->execute( array( 'event_id' => $this->seed() ) ) );
	}
}
