<?php
/**
 * EventRatings Tests
 *
 * Covers the comment-backed show-rating primitive: eligibility, one review
 * per user, aggregate maintenance across moderation, the consumer gate
 * filter, and the abilities.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\EventRatings;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;

class EventRatingsTest extends WP_UnitTestCase {

	private int $user_a;
	private int $user_b;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
		EventRatings::register();
		EventRatings::add_comment_support();

		// Approve first-time commenters so aggregates have approved reviews.
		update_option( 'comment_previously_approved', 0 );
		update_option( 'comment_moderation', 0 );
		// Test reviews share an empty author IP; skip core's per-IP flood window.
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );

		$this->user_a = self::factory()->user->create( array( 'display_name' => 'Fan A' ) );
		$this->user_b = self::factory()->user->create( array( 'display_name' => 'Fan B' ) );
	}

	public function tearDown(): void {
		remove_all_filters( 'data_machine_events_can_rate_event' );
		remove_all_filters( 'data_machine_events_rating_gate_message' );
		remove_filter( 'wp_is_comment_flood', '__return_false', 99 );
		parent::tearDown();
	}

	private function makeEvent( string $start ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_title'  => 'Show ' . $start,
				'post_status' => 'publish',
			)
		);
		EventDatesTable::upsert( $id, $start, null, 'publish' );
		return $id;
	}

	private function pastEvent(): int {
		return $this->makeEvent( gmdate( 'Y-m-d 20:00:00', strtotime( '-30 days' ) ) );
	}

	private function futureEvent(): int {
		return $this->makeEvent( gmdate( 'Y-m-d 20:00:00', strtotime( '+30 days' ) ) );
	}

	public function test_normalize_rating_accepts_only_whole_numbers_in_range(): void {
		$this->assertSame( 4, EventRatings::normalize_rating( 4 ) );
		$this->assertSame( 5, EventRatings::normalize_rating( '5' ) );
		$this->assertSame( 1, EventRatings::normalize_rating( 1.0 ) );
		$this->assertSame( 0, EventRatings::normalize_rating( 0 ) );
		$this->assertSame( 0, EventRatings::normalize_rating( 6 ) );
		$this->assertSame( 0, EventRatings::normalize_rating( '4.5' ) );
		$this->assertSame( 0, EventRatings::normalize_rating( 4.5 ) );
		$this->assertSame( 0, EventRatings::normalize_rating( 'great' ) );
		$this->assertSame( 0, EventRatings::normalize_rating( null ) );
	}

	public function test_only_past_published_events_are_rateable(): void {
		$past   = $this->pastEvent();
		$future = $this->futureEvent();

		$this->assertTrue( EventRatings::is_rateable_event( $past ) );
		$this->assertFalse( EventRatings::is_rateable_event( $future ) );
		$this->assertTrue( comments_open( $past ) );
		$this->assertFalse( comments_open( $future ) );

		$regular = self::factory()->post->create();
		$this->assertFalse( EventRatings::is_rateable_event( $regular ) );

		$result = EventRatings::rate( $this->user_a, $future, 5, 'Can\'t wait' );
		$this->assertWPError( $result );
		$this->assertSame( 'event_not_rateable', $result->get_error_code() );
	}

	public function test_logged_out_users_cannot_rate(): void {
		$this->assertFalse( EventRatings::can_rate( 0, $this->pastEvent() ) );
	}

	public function test_rate_creates_one_review_and_updates_it_in_place(): void {
		$event = $this->pastEvent();

		$first = EventRatings::rate( $this->user_a, $event, 4, 'Great second set.' );
		$this->assertIsArray( $first );
		$this->assertTrue( $first['created'] );
		$this->assertSame( 4, $first['review']['rating'] );
		$this->assertSame( EventRatings::COMMENT_TYPE, get_comment( $first['review']['comment_id'] )->comment_type );

		$second = EventRatings::rate( $this->user_a, $event, 5 );
		$this->assertFalse( $second['created'] );
		$this->assertSame( $first['review']['comment_id'], $second['review']['comment_id'] );
		$this->assertSame( 5, $second['review']['rating'] );
		$this->assertSame( 'Great second set.', $second['review']['review'], 'Omitted review text keeps the existing text.' );

		$this->assertCount( 1, EventRatings::get_reviews( $event ) );
	}

	public function test_invalid_rating_is_rejected(): void {
		$result = EventRatings::rate( $this->user_a, $this->pastEvent(), 7 );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_rating', $result->get_error_code() );
	}

	public function test_summary_averages_approved_reviews_only(): void {
		$event = $this->pastEvent();

		EventRatings::rate( $this->user_a, $event, 5, 'Fire' );
		$b = EventRatings::rate( $this->user_b, $event, 2, 'Sound was rough' );
		$this->assertIsArray( $b );

		$summary = EventRatings::get_summary( $event );
		$this->assertSame( 2, $summary['count'] );
		$this->assertEqualsWithDelta( 3.5, $summary['average'], 0.001 );

		wp_set_comment_status( $b['review']['comment_id'], 'hold' );
		$summary = EventRatings::get_summary( $event );
		$this->assertSame( 1, $summary['count'] );
		$this->assertEqualsWithDelta( 5.0, $summary['average'], 0.001 );

		wp_delete_comment( $b['review']['comment_id'], true );
		$this->assertSame( 1, EventRatings::get_summary( $event )['count'] );

		$reviews = EventRatings::get_reviews( $event );
		$this->assertCount( 1, $reviews );
		$this->assertSame( 'Fan A', $reviews[0]['author_name'] );
	}

	public function test_summary_is_empty_without_reviews(): void {
		$summary = EventRatings::get_summary( $this->pastEvent() );
		$this->assertNull( $summary['average'] );
		$this->assertSame( 0, $summary['count'] );
	}

	public function test_consumer_gate_filter_blocks_rating_with_its_message(): void {
		$event   = $this->pastEvent();
		$allowed = $this->user_a;

		add_filter(
			'data_machine_events_can_rate_event',
			static function ( $can, $user_id ) use ( $allowed ) {
				return $can && $user_id === $allowed;
			},
			10,
			2
		);
		add_filter(
			'data_machine_events_rating_gate_message',
			static function () {
				return 'Mark that you were there first.';
			}
		);

		$this->assertTrue( EventRatings::can_rate( $this->user_a, $event ) );
		$this->assertFalse( EventRatings::can_rate( $this->user_b, $event ) );

		$denied = EventRatings::rate( $this->user_b, $event, 5 );
		$this->assertWPError( $denied );
		$this->assertSame( 'rating_not_allowed', $denied->get_error_code() );
		$this->assertSame( 'Mark that you were there first.', $denied->get_error_message() );

		$this->assertIsArray( EventRatings::rate( $this->user_a, $event, 5 ) );
	}

	public function test_comment_form_submission_becomes_a_review(): void {
		$event = $this->pastEvent();
		wp_set_current_user( $this->user_a );

		$_POST[ EventRatings::FORM_FIELD ] = '3';
		$data                              = EventRatings::preprocess_comment(
			array(
				'comment_post_ID' => $event,
				'comment_parent'  => 0,
				'user_id'         => $this->user_a,
				'comment_content' => 'Solid show',
			)
		);
		unset( $_POST[ EventRatings::FORM_FIELD ] );

		$this->assertSame( EventRatings::COMMENT_TYPE, $data['comment_type'] );
		$this->assertSame( 3, $data['comment_meta'][ EventRatings::META_RATING ] );
	}

	public function test_replies_and_followups_stay_plain_comments(): void {
		$event = $this->pastEvent();
		wp_set_current_user( $this->user_a );

		$reply = EventRatings::preprocess_comment(
			array(
				'comment_post_ID' => $event,
				'comment_parent'  => 12,
				'user_id'         => $this->user_a,
				'comment_content' => 'Agreed',
			)
		);
		$this->assertArrayNotHasKey( 'comment_type', $reply );

		EventRatings::rate( $this->user_a, $event, 4 );
		$followup = EventRatings::preprocess_comment(
			array(
				'comment_post_ID' => $event,
				'comment_parent'  => 0,
				'user_id'         => $this->user_a,
				'comment_content' => 'One more thing',
			)
		);
		$this->assertArrayNotHasKey( 'comment_type', $followup );
	}

	public function test_rating_only_submission_is_allowed_on_events(): void {
		$event = $this->pastEvent();

		$_POST[ EventRatings::FORM_FIELD ] = '5';
		$this->assertTrue( EventRatings::allow_rating_only_review( false, array( 'comment_post_ID' => $event ) ) );
		$this->assertFalse( EventRatings::allow_rating_only_review( false, array( 'comment_post_ID' => self::factory()->post->create() ) ) );
		unset( $_POST[ EventRatings::FORM_FIELD ] );

		$this->assertFalse( EventRatings::allow_rating_only_review( false, array( 'comment_post_ID' => $event ) ) );
	}

	public function test_review_text_is_prefixed_with_stars(): void {
		$event  = $this->pastEvent();
		$result = EventRatings::rate( $this->user_a, $event, 4, 'Tight' );
		$html   = EventRatings::filter_comment_text( '<p>Tight</p>', get_comment( $result['review']['comment_id'] ) );

		$this->assertStringContainsString( '★★★★☆', $html );
		$this->assertStringContainsString( 'Rated 4 out of 5', $html );

		$plain = self::factory()->comment->create( array( 'comment_post_ID' => $event ) );
		$this->assertSame( 'x', EventRatings::filter_comment_text( 'x', get_comment( $plain ) ) );
	}

	public function test_summary_markup_renders_only_with_ratings(): void {
		$event = $this->pastEvent();
		$this->assertSame( '', EventRatings::render_summary( $event ) );

		EventRatings::rate( $this->user_a, $event, 5 );
		$html = EventRatings::render_summary( $event );
		$this->assertStringContainsString( '5.0', $html );
		$this->assertStringContainsString( '1 rating', $html );
	}

	public function test_abilities_rate_and_read(): void {
		$event = $this->pastEvent();

		$rate = wp_get_ability( 'data-machine-events/rate-event' );
		$get  = wp_get_ability( 'data-machine-events/get-event-ratings' );
		$this->assertNotNull( $rate );
		$this->assertNotNull( $get );

		wp_set_current_user( $this->user_a );
		$result = $rate->execute(
			array(
				'event_id' => $event,
				'rating'   => 4,
				'review'   => 'Loved it',
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['summary']['count'] );

		$read = $get->execute( array( 'event_id' => $event ) );
		$this->assertTrue( $read['can_rate'] );
		$this->assertSame( 4, $read['user_review']['rating'] );
		$this->assertCount( 1, $read['reviews'] );

		wp_set_current_user( 0 );
		$this->assertWPError(
			$rate->execute(
				array(
					'event_id' => $event,
					'rating'   => 3,
				)
			)
		);
	}

	private function renderFormFor( int $user_id, int $event ): string {
		wp_set_current_user( $user_id );
		$GLOBALS['post'] = get_post( $event );
		setup_postdata( $GLOBALS['post'] );
		ob_start();
		comment_form( array(), $event );
		$html = (string) ob_get_clean();
		wp_reset_postdata();
		return $html;
	}

	public function test_gated_user_gets_a_locked_form_that_can_unlock_in_place(): void {
		$event   = $this->pastEvent();
		$allowed = $this->user_a;
		add_filter(
			'data_machine_events_can_rate_event',
			static function ( $can, $user_id ) use ( $allowed ) {
				return $can && $user_id === $allowed;
			},
			10,
			2
		);

		$locked = $this->renderFormFor( $this->user_b, $event );
		$this->assertStringContainsString( 'data-dme-rating-gate="' . $event . '"', $locked );
		$this->assertMatchesRegularExpression( '/data-dme-rating-locked="' . $event . '" hidden><fieldset class="dme-rating-field"/', $locked );
		$this->assertMatchesRegularExpression( '/data-dme-rating-locked="' . $event . '" hidden>[^<]*<p class="comment-form-comment"/', $locked );
		$this->assertTrue( wp_script_is( EventRatings::FORM_SCRIPT, 'enqueued' ) );

		$open = $this->renderFormFor( $this->user_a, $event );
		$this->assertStringNotContainsString( 'data-dme-rating-gate', $open );
		$this->assertStringNotContainsString( 'data-dme-rating-locked', $open );
		$this->assertStringContainsString( 'dme-rating-field', $open );

		// Submission is still enforced server-side for the locked user.
		$this->assertWPError( EventRatings::rate( $this->user_b, $event, 5 ) );
	}

	public function test_listing_badge_shows_average_and_count(): void {
		$event = $this->pastEvent();
		$this->assertSame( '', EventRatings::render_listing_badge( $event ) );

		EventRatings::rate( $this->user_a, $event, 5 );
		EventRatings::rate( $this->user_b, $event, 4 );

		$badge = EventRatings::render_listing_badge( $event );
		$this->assertStringContainsString( 'dme-rating-badge', $badge );
		$this->assertStringContainsString( '<strong aria-hidden="true">4.5</strong>', $badge );
		$this->assertStringContainsString( '(2)', $badge );
		$this->assertStringContainsString( 'Rated 4.5 out of 5 by 2 people', $badge );
	}

	public function test_rating_change_rotates_calendar_cache_generation(): void {
		$event  = $this->pastEvent();
		$before = \DataMachineEvents\Blocks\Calendar\Cache\CalendarCache::get_generation();
		EventRatings::rate( $this->user_a, $event, 3 );
		$this->assertNotSame( $before, \DataMachineEvents\Blocks\Calendar\Cache\CalendarCache::get_generation() );
	}

	public function test_get_my_event_ratings_returns_only_the_current_users_ratings(): void {
		$one   = $this->pastEvent();
		$two   = $this->pastEvent();
		$three = $this->pastEvent();
		EventRatings::rate( $this->user_a, $one, 5 );
		EventRatings::rate( $this->user_a, $two, 2 );
		EventRatings::rate( $this->user_b, $three, 4 );

		$ability = wp_get_ability( 'data-machine-events/get-my-event-ratings' );
		$this->assertNotNull( $ability );

		wp_set_current_user( $this->user_a );
		$result = $ability->execute( array( 'event_ids' => array( $one, $two, $three ) ) );
		$this->assertSame(
			array(
				(string) $one => 5,
				(string) $two => 2,
			),
			array_map( 'intval', (array) $result['ratings'] )
		);

		wp_set_current_user( $this->user_b );
		$empty = $ability->execute( array( 'event_ids' => array( $one ) ) );
		$this->assertSame( '{}', wp_json_encode( $empty['ratings'] ) );
	}

	public function test_first_review_form_makes_text_optional_and_labels_submit(): void {
		$event = $this->pastEvent();

		$first = $this->renderFormFor( $this->user_a, $event );
		$this->assertStringContainsString( 'Review (optional)', $first );
		$this->assertDoesNotMatchRegularExpression( '/<textarea[^>]*\srequired/', $first );
		$this->assertStringContainsString( 'value="Post Rating"', $first );

		EventRatings::rate( $this->user_a, $event, 4 );
		$followup = $this->renderFormFor( $this->user_a, $event );
		$this->assertStringNotContainsString( 'Review (optional)', $followup );
		$this->assertMatchesRegularExpression( '/<textarea[^>]*\srequired/', $followup );
		$this->assertStringNotContainsString( 'value="Post Rating"', $followup );
	}

	public function test_first_review_form_is_titled_rate_this_show(): void {
		$event = $this->pastEvent();
		$this->assertStringContainsString( 'Rate this show', $this->renderFormFor( $this->user_a, $event ) );

		EventRatings::rate( $this->user_a, $event, 4 );
		$this->assertStringNotContainsString( 'Rate this show', $this->renderFormFor( $this->user_a, $event ) );
	}
}
