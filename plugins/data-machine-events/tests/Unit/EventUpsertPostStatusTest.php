<?php
/**
 * Event-specific visibility policy at the core upsert boundary.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

require_once __DIR__ . '/EventUpsertTestCase.php';

/**
 * Regression coverage for overlapping flows with different status settings.
 */
class EventUpsertPostStatusTest extends EventUpsertTestCase {

	/**
	 * Build a future event fixture.
	 *
	 * @return array Event parameters.
	 */
	private function event(): array {
		return array(
			'title'     => 'Visibility Policy Concert',
			'venue'     => 'Visibility Policy Hall',
			'startDate' => ( new \DateTimeImmutable( '+4 days', wp_timezone() ) )->format( 'Y-m-d' ),
			'startTime' => '20:00',
		);
	}

	/**
	 * Draft flows must retain the status of visible events.
	 *
	 * @param string $status Existing visibility.
	 * @dataProvider protected_statuses
	 */
	public function test_draft_flow_preserves_existing_visibility( string $status ): void {
		$event   = $this->event();
		$created = $this->invoke_upsert( $event, array( 'post_status' => $status ) );
		$this->assertTrue( $created['success'], wp_json_encode( $created ) );
		$post_id = (int) $created['data']['post_id'];
		$this->assertSame( $status, get_post_status( $post_id ) );

		// Match through duplicate detection, as overlapping import flows do.
		$event['description'] = 'Updated by a draft-configured flow.';
		$result               = $this->invoke_upsert( $event, array( 'post_status' => 'draft' ) );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertSame( $post_id, (int) $result['data']['post_id'] );
		$this->assertSame( $status, get_post_status( $post_id ) );
		$this->assertStringContainsString( $event['description'], get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * Existing visible statuses.
	 *
	 * @return array Status test cases.
	 */
	public static function protected_statuses(): array {
		return array(
			'published' => array( 'publish' ),
			'private'   => array( 'private' ),
		);
	}

	/**
	 * Scheduled events retain their status at the persistence boundary.
	 */
	public function test_draft_flow_preserves_scheduled_status_at_boundary(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'     => \DataMachineEvents\Core\Event_Post_Type::POST_TYPE,
				'post_status'   => 'future',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
			)
		);
		$this->assertSame( 'future', get_post_status( $post_id ) );
		$context = null;
		add_filter(
			'datamachine_events_before_event_upsert_persistence',
			static function ( $preflight, $resolved ) use ( &$context ) {
				$context = $resolved;
				return false;
			},
			10,
			2
		);
		$event             = $this->event();
		$event['event_id'] = $post_id;
		$this->invoke_upsert( $event, array( 'post_status' => 'draft' ) );

		$this->assertIsArray( $context );
		$this->assertSame( $post_id, $context['existing_post_id'] );
		$this->assertSame( 'future', $context['post_status'] );
	}

	/**
	 * Configured status applies to creates.
	 */
	public function test_draft_flow_creates_draft(): void {
		$result = $this->invoke_upsert( $this->event(), array( 'post_status' => 'draft' ) );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertSame( 'created', $result['data']['action'] );
		$this->assertSame( 'draft', get_post_status( $result['data']['post_id'] ) );
	}

	/**
	 * Published flows can still promote drafts.
	 */
	public function test_publish_flow_promotes_existing_draft(): void {
		$event   = $this->event();
		$created = $this->invoke_upsert( $event, array( 'post_status' => 'draft' ) );
		$this->assertTrue( $created['success'], wp_json_encode( $created ) );
		$post_id           = (int) $created['data']['post_id'];
		$event['event_id'] = $post_id;

		$result = $this->invoke_upsert( $event, array( 'post_status' => 'publish' ) );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertSame( $post_id, (int) $result['data']['post_id'] );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Ordinary trash retains the configured restoration behavior.
	 */
	public function test_draft_flow_restores_ordinary_trash_as_draft(): void {
		$event   = $this->event();
		$created = $this->invoke_upsert( $event );
		$this->assertTrue( $created['success'], wp_json_encode( $created ) );
		$post_id = (int) $created['data']['post_id'];
		wp_trash_post( $post_id );
		$event['event_id'] = $post_id;

		$result = $this->invoke_upsert( $event, array( 'post_status' => 'draft' ) );

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertSame( $post_id, (int) $result['data']['post_id'] );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}
}
