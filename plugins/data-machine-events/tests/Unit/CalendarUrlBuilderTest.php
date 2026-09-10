<?php
/**
 * CalendarUrlBuilder Tests
 *
 * Ticketmaster (and other vendors) wrap outbound ticket links in affiliate
 * redirectors that must not be recoverable from a static fetch — see #817.
 * These tests assert the Google Calendar `details` and Outlook `body`
 * deeplink params never embed the raw ticket URL, only the event permalink
 * — exactly once, labelled "Tickets:" when a ticket URL exists or
 * "More info:" otherwise, never both — and that no permalink line is
 * emitted at all when no permalink is available.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.62.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\EventActions\CalendarUrlBuilder;

class CalendarUrlBuilderTest extends WP_UnitTestCase {

	/**
	 * Impact Radius affiliate redirector carrying the Ticketmaster affiliate ID,
	 * mirroring the real-world leak reported in #817.
	 */
	const AFFILIATE_TICKET_URL = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7JFo-&utm_medium=affiliate';

	public function setUp(): void {
		parent::setUp();
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
	}

	/**
	 * @return int Post ID of a published event post.
	 */
	private function create_event_post( string $slug = 'affiliate-leak-test-event' ): int {
		return $this->factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_title'  => 'Affiliate Leak Test Event',
				'post_name'   => $slug,
				'post_status' => 'publish',
			)
		);
	}

	private function base_event_fields(): array {
		return array(
			'startDate'  => '2026-09-23',
			'startTime'  => '20:00:00',
			'endTime'    => '',
			'venue'      => 'The Royal American',
			'address'    => '970 Morrison Dr, Charleston, SC',
			'ticketUrl'  => self::AFFILIATE_TICKET_URL,
			'performer'  => 'Jimmy Fortune',
		);
	}

	public function test_google_details_never_embeds_affiliate_url() {
		$post_id = $this->create_event_post();
		$event   = array_merge( $this->base_event_fields(), array( 'post_id' => $post_id ) );

		$url = CalendarUrlBuilder::google( $event );
		$this->assertNotEmpty( $url );

		$details = $this->extract_query_param( $url, 'details' );

		$this->assertStringNotContainsString( 'evyy.net', $details );
		$this->assertStringNotContainsString( 'utm_medium=affiliate', $details );
		$this->assertStringContainsString( get_permalink( $post_id ), $details );
	}

	public function test_outlook_body_never_embeds_affiliate_url() {
		$post_id = $this->create_event_post( 'affiliate-leak-test-event-outlook' );
		$event   = array_merge( $this->base_event_fields(), array( 'post_id' => $post_id ) );

		$url = CalendarUrlBuilder::outlook( $event );
		$this->assertNotEmpty( $url );

		$body = $this->extract_query_param( $url, 'body' );

		$this->assertStringNotContainsString( 'evyy.net', $body );
		$this->assertStringNotContainsString( 'utm_medium=affiliate', $body );
		$this->assertStringContainsString( get_permalink( $post_id ), $body );
	}

	public function test_google_details_includes_tickets_line_pointing_at_permalink_exactly_once() {
		$post_id = $this->create_event_post( 'affiliate-leak-test-event-tickets-line' );
		$event   = array_merge( $this->base_event_fields(), array( 'post_id' => $post_id ) );

		$details = $this->extract_query_param( CalendarUrlBuilder::google( $event ), 'details' );

		$permalink = get_permalink( $post_id );
		$this->assertStringContainsString( 'Tickets: ' . $permalink, $details );
		// With a ticket URL present, "Tickets:" wins and "More info:" must not
		// also appear — the permalink is the same destination either way, so
		// it must not be duplicated under two labels.
		$this->assertStringNotContainsString( 'More info:', $details );
		$this->assertSame( 1, substr_count( $details, $permalink ), 'Permalink must appear exactly once in the description.' );
	}

	public function test_tickets_line_is_omitted_when_no_permalink_is_available() {
		// post_id is 0 (unknown post) -> get_permalink() has nothing to resolve,
		// so no permalink line must be emitted at all, rather than falling
		// back to the raw (possibly affiliate-wrapped) ticket URL.
		$event = array_merge( $this->base_event_fields(), array( 'post_id' => 0, 'title' => 'Untracked Event' ) );

		$details = $this->extract_query_param( CalendarUrlBuilder::google( $event ), 'details' );

		$this->assertStringNotContainsString( 'Tickets:', $details );
		$this->assertStringNotContainsString( 'More info:', $details );
		$this->assertStringNotContainsString( 'evyy.net', $details );
	}

	public function test_more_info_line_is_used_when_ticket_url_is_absent() {
		$post_id = $this->create_event_post( 'affiliate-leak-test-event-no-ticket' );
		$fields  = $this->base_event_fields();
		unset( $fields['ticketUrl'] );
		$event = array_merge( $fields, array( 'post_id' => $post_id ) );

		$details = $this->extract_query_param( CalendarUrlBuilder::google( $event ), 'details' );

		$permalink = get_permalink( $post_id );
		// No ticket URL -> falls back to "More info:", and "Tickets:" must
		// not appear since there was never a ticket URL to label.
		$this->assertStringNotContainsString( 'Tickets:', $details );
		$this->assertStringContainsString( 'More info: ' . $permalink, $details );
		$this->assertSame( 1, substr_count( $details, $permalink ), 'Permalink must appear exactly once in the description.' );
	}

	/**
	 * Decode a rawurlencode'd query param from a deeplink URL built by
	 * CalendarUrlBuilder::build_query() (which uses rawurlencode, not
	 * http_build_query()/urlencode()).
	 */
	private function extract_query_param( string $url, string $param ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		foreach ( explode( '&', $query ) as $pair ) {
			list( $key, $value ) = array_pad( explode( '=', $pair, 2 ), 2, '' );
			if ( rawurldecode( $key ) === $param ) {
				return rawurldecode( $value );
			}
		}
		return '';
	}
}
