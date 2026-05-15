<?php
/**
 * EventMergeHelper Tests
 *
 * Covers the shared pairwise merge primitive used by both
 * CleanDuplicatesCommand and the MergeEventPostsAbilities ability (issue
 * #256).
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.34.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\DuplicateDetection\EventMergeHelper;
use DataMachineEvents\Core\Event_Post_Type;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;

class EventMergeHelperTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( 'data_machine_events' ) ) {
			Event_Post_Type::register();
		}
	}

	private function makeEventPost( string $title, string $ticket_url = '' ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		if ( '' !== $ticket_url ) {
			update_post_meta( $id, EVENT_TICKET_URL_META_KEY, $ticket_url );
		}

		return $id;
	}

	public function test_invalid_post_ids_return_error(): void {
		$result = EventMergeHelper::merge( 0, 5 );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid', $result['error'] );

		$result = EventMergeHelper::merge( 5, 0 );
		$this->assertFalse( $result['success'] );
	}

	public function test_same_winner_and_loser_returns_error(): void {
		$id     = $this->makeEventPost( 'Event A' );
		$result = EventMergeHelper::merge( $id, $id );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'same post', $result['error'] );
	}

	public function test_missing_post_returns_error(): void {
		$id     = $this->makeEventPost( 'Event A' );
		$result = EventMergeHelper::merge( $id, 999999999 );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'do not exist', $result['error'] );
	}

	public function test_merge_trashes_loser_and_keeps_winner(): void {
		$winner = $this->makeEventPost( 'Winner' );
		$loser  = $this->makeEventPost( 'Loser' );

		$result = EventMergeHelper::merge( $winner, $loser );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['trashed'] );
		$this->assertSame( 'publish', get_post_status( $winner ) );
		$this->assertSame( 'trash', get_post_status( $loser ) );
	}

	public function test_ticket_url_forward_merge_when_winner_lacks_one(): void {
		$winner = $this->makeEventPost( 'Winner' );
		$loser  = $this->makeEventPost( 'Loser', 'https://tickets.example.com/loser' );

		$result = EventMergeHelper::merge( $winner, $loser );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['ticket_url_merged'] );
		$this->assertSame(
			'https://tickets.example.com/loser',
			get_post_meta( $winner, EVENT_TICKET_URL_META_KEY, true )
		);
	}

	public function test_ticket_url_not_overwritten_when_winner_already_has_one(): void {
		$winner = $this->makeEventPost( 'Winner', 'https://tickets.example.com/winner' );
		$loser  = $this->makeEventPost( 'Loser', 'https://tickets.example.com/loser' );

		$result = EventMergeHelper::merge( $winner, $loser );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['ticket_url_merged'] );
		$this->assertSame(
			'https://tickets.example.com/winner',
			get_post_meta( $winner, EVENT_TICKET_URL_META_KEY, true )
		);
	}

	public function test_merge_ticket_url_option_can_be_disabled(): void {
		$winner = $this->makeEventPost( 'Winner' );
		$loser  = $this->makeEventPost( 'Loser', 'https://tickets.example.com/loser' );

		$result = EventMergeHelper::merge( $winner, $loser, array( 'merge_ticket_url' => false ) );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['ticket_url_merged'] );
		$this->assertSame( '', get_post_meta( $winner, EVENT_TICKET_URL_META_KEY, true ) );
	}
}
