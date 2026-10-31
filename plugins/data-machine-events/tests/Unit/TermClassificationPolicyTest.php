<?php
/**
 * Term classification is reserved for events people can still attend.
 *
 * Classification costs an inference call per post, and terms exist to make
 * upcoming shows discoverable. Classifying a show from last year changes
 * nothing anyone can find. 74% of published events on the events site are
 * already in the past.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;

use function DataMachineEvents\Core\skip_term_classification_for_past_events;

class TermClassificationPolicyTest extends WP_UnitTestCase {

	/**
	 * Create an event with explicit dates.
	 *
	 * @param string      $start MySQL datetime.
	 * @param string|null $end   MySQL datetime or null.
	 * @return \WP_Post
	 */
	private function event( string $start, ?string $end = null ): \WP_Post {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Event',
			)
		);

		EventDatesTable::upsert( $post_id, $start, $end, 'publish' );

		return get_post( $post_id );
	}

	private function offset( string $modifier ): string {
		return gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' ' . $modifier ) );
	}

	public function test_past_event_is_not_classified(): void {
		$post = $this->event( $this->offset( '-30 days' ), $this->offset( '-30 days +3 hours' ) );

		$this->assertFalse(
			skip_term_classification_for_past_events( true, $post, 'events' ),
			'an event that already happened must not buy an inference call'
		);
	}

	public function test_upcoming_event_is_classified(): void {
		$post = $this->event( $this->offset( '+30 days' ), $this->offset( '+30 days +3 hours' ) );

		$this->assertTrue( skip_term_classification_for_past_events( true, $post, 'events' ) );
	}

	/**
	 * A festival mid-run has a past start and is still worth finding.
	 */
	public function test_event_in_progress_is_classified(): void {
		$post = $this->event( $this->offset( '-1 day' ), $this->offset( '+2 days' ) );

		$this->assertTrue(
			skip_term_classification_for_past_events( true, $post, 'events' ),
			'an event still running is upcoming for discovery purposes'
		);
	}

	/**
	 * Unknown timing is a data problem, not a decision to withhold work.
	 */
	public function test_event_without_dates_is_left_alone(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Dateless Event',
			)
		);

		$this->assertTrue( skip_term_classification_for_past_events( true, get_post( $post_id ), 'events' ) );
	}

	/**
	 * The rule is about events; it must not reach other post types.
	 */
	public function test_non_event_post_is_untouched(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		$this->assertTrue( skip_term_classification_for_past_events( true, get_post( $post_id ), 'events' ) );
	}

	/**
	 * A veto already cast by another callback stands.
	 */
	public function test_existing_veto_is_preserved(): void {
		$post = $this->event( $this->offset( '+30 days' ) );

		$this->assertFalse( skip_term_classification_for_past_events( false, $post, 'events' ) );
	}

	/**
	 * The filter is actually wired, not merely defined.
	 */
	public function test_filter_is_registered(): void {
		$this->assertNotFalse(
			has_filter( 'extrachill_network_should_classify_post', 'DataMachineEvents\Core\skip_term_classification_for_past_events' )
		);
	}
}
