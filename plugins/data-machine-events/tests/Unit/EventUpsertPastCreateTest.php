<?php
/**
 * Create-only past-date guard at the event tool boundary.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\Event_Post_Type;

require_once __DIR__ . '/EventUpsertTestCase.php';

class EventUpsertPastCreateTest extends EventUpsertTestCase {

	private function event( string $relative_date ): array {
		return array(
			'title'     => 'Evening Concert',
			'venue'     => 'Concert Hall',
			'startDate' => ( new \DateTimeImmutable( $relative_date, wp_timezone() ) )->format( 'Y-m-d' ),
			'startTime' => '00:00',
		);
	}

	public function test_past_create_is_rejected_with_tool_reason_and_no_post(): void {
		$result = $this->invoke_upsert( $this->event( '-4 days' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'past_event_create', $result['rule'] );
		$this->assertStringContainsString( 'before today in the site timezone', $result['error'] );
		$this->assertArrayHasKey( 'tool_name', $result );
		$this->assertSame( 0, $this->countEventsWithTitle( 'Evening Concert' ) );
	}

	public function test_today_create_is_allowed_even_after_start_time(): void {
		$result = $this->invoke_upsert( $this->event( 'today' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'created', $result['data']['action'] );
	}

	public function test_future_create_is_allowed(): void {
		$result = $this->invoke_upsert( $this->event( '+4 days' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'created', $result['data']['action'] );
	}

	public function test_existing_past_event_update_is_allowed(): void {
		$post_id           = self::factory()->post->create( array( 'post_type' => Event_Post_Type::POST_TYPE ) );
		$event             = $this->event( '-4 days' );
		$event['event_id'] = $post_id;

		$result = $this->invoke_upsert( $event );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'updated', $result['data']['action'] );
		$this->assertSame( $post_id, $result['data']['post_id'] );
	}

	public function test_duplicate_matched_past_event_update_is_allowed(): void {
		$event   = $this->event( '-4 days' );
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $event['title'],
				'post_content' => '<!-- wp:data-machine-events/event-details ' . wp_json_encode( $event ) . ' /-->',
			)
		);
		$venue   = wp_insert_term( $event['venue'], 'venue' );
		$this->assertNotWPError( $venue );
		wp_set_object_terms( $post_id, array( (int) $venue['term_id'] ), 'venue' );

		$result = $this->invoke_upsert( $event );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertSame( $post_id, $result['data']['post_id'] );
		$this->assertContains( $result['data']['action'], array( 'updated', 'no_change' ) );
	}

	public function test_site_timezone_controls_same_day_grace(): void {
		// Pick a site offset whose day differs from UTC right now.
		$utc_hour = (int) gmdate( 'G' );
		$offset   = $utc_hour < 12 ? '-12:00' : '+14:00';
		$previous = get_option( 'timezone_string' );
		update_option( 'timezone_string', $offset );
		try {
			$result = $this->invoke_upsert( $this->event( 'today' ) );
			$this->assertTrue( $result['success'] );
			$this->assertSame( 'created', $result['data']['action'] );
			$event          = $this->event( 'yesterday' );
			$event['title'] = 'Earlier Concert';
			$result         = $this->invoke_upsert( $event );
			$this->assertFalse( $result['success'] );
			$this->assertSame( 'past_event_create', $result['rule'] );
		} finally {
			update_option( 'timezone_string', $previous );
		}
	}
}
