<?php
/**
 * Explicit source cancellations reconcile existing events, never create new ones.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

require_once __DIR__ . '/EventUpsertTestCase.php';

class EventUpsertCancellationTest extends EventUpsertTestCase {

	private function event(): array {
		return array(
			'title'           => 'Cancellation Test Concert',
			'venue'           => 'Cancellation Test Hall',
			'startDate'       => ( new \DateTimeImmutable( '+4 days', wp_timezone() ) )->format( 'Y-m-d' ),
			'startTime'       => '19:00',
			'eventStatus'     => 'EventScheduled',
			'source_identity' => 'ticketmaster:cancellation-test',
		);
	}

	public function test_new_cancelled_event_is_not_created(): void {
		$event                = $this->event();
		$event['eventStatus'] = 'EventCancelled';
		$result               = $this->invoke_upsert( $event );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'cancelled_event_create', $result['rule'] );
		$this->assertSame( 0, $this->countEventsWithTitle( $event['title'] ) );
	}

	public function test_new_postponed_event_is_not_created(): void {
		$event                = $this->event();
		$event['eventStatus'] = 'EventPostponed';
		$result               = $this->invoke_upsert( $event );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 0, $this->countEventsWithTitle( $event['title'] ) );
	}

	public function test_source_identity_match_updates_published_event_to_cancelled(): void {
		$this->assertCancellationUpdatesExisting( false );
	}

	public function test_dedup_match_updates_published_event_to_cancelled(): void {
		$this->assertCancellationUpdatesExisting( true );
	}

	private function assertCancellationUpdatesExisting( bool $dedup ): void {
		$event   = $this->event();
		$created = $this->invoke_upsert( $event );
		$this->assertTrue( $created['success'], wp_json_encode( $created ) );
		$post_id = $created['data']['post_id'];
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		if ( $dedup ) {
			unset( $event['source_identity'] );
		}
		$event['eventStatus'] = 'EventCancelled';
		$result               = $this->invoke_upsert( $event );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertSame( 'updated', $result['data']['action'] );
		$this->assertStringContainsString( '"eventStatus":"EventCancelled"', get_post_field( 'post_content', $post_id ) );
		$this->assertSame( 1, $this->countEventsWithTitle( $event['title'] ) );
	}
}
