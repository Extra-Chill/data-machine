<?php
/**
 * ResolveTicketDestinationAbilities tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\ResolveTicketDestinationAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use WP_UnitTestCase;

class ResolveTicketDestinationAbilitiesTest extends WP_UnitTestCase {

	private ResolveTicketDestinationAbilities $ability;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		$this->ability = new ResolveTicketDestinationAbilities();
	}

	public function test_registered_ability_has_public_permission_and_is_hidden_from_rest(): void {
		$registered = wp_get_ability( 'data-machine-events/resolve-ticket-destination' );

		$this->assertNotNull( $registered );
		// get_meta() takes no arguments and always returns the whole meta
		// array (WP_Ability::get_meta(): array); get_meta_item() is the
		// single-key accessor.
		$this->assertFalse( $registered->get_meta_item( 'show_in_rest' ) );
	}

	public function test_rejects_non_positive_event_id(): void {
		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => 0 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_event_id', $result->get_error_code() );
	}

	public function test_rejects_missing_event(): void {
		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => 999999 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'event_not_found', $result->get_error_code() );
	}

	public function test_rejects_non_event_post_type(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'event_not_found', $result->get_error_code() );
	}

	public function test_rejects_unpublished_event(): void {
		$event_id = $this->makeEvent( 'https://www.ticketmaster.com/event/123', 'draft' );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'event_not_found', $result->get_error_code() );
	}

	public function test_rejects_event_with_no_ticket_url(): void {
		$event_id = self::factory()->post->create(
			array(
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'no_ticket_url', $result->get_error_code() );
	}

	public function test_resolves_direct_ticket_url_as_non_affiliate(): void {
		$url      = 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-';
		$event_id = $this->makeEvent( $url );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $url, $result['url'] );
		$this->assertFalse( $result['is_affiliate'] );
	}

	public function test_resolves_affiliate_ticket_url_as_affiliate(): void {
		$url      = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-' ) . '&utm_medium=affiliate';
		$event_id = $this->makeEventWithRawTicketUrl( $url );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $url, $result['url'], 'Ability returns the stored affiliate URL verbatim (this fixture has no entities to normalize); unwrapping is a separate concern.' );
		$this->assertTrue( $result['is_affiliate'] );
	}

	/**
	 * Regression guard for a revenue-affecting bug found while investigating
	 * the utm_medium test above: on the live network, ~54% of published
	 * events with an affiliate ticket URL store it with the ampersand
	 * HTML-entity-encoded (`&amp;utm_medium=affiliate`) — correct for an
	 * `href` attribute (browsers decode entities before following a link),
	 * WRONG for a value handed to an HTTP `Location` header, which is sent
	 * byte-for-byte. An un-normalized `&amp;` in a Location header means
	 * the affiliate network receives a parameter literally named
	 * `amp;utm_medium` and the real `utm_medium` never arrives — silently
	 * breaking affiliate attribution on tens of thousands of events. A
	 * smaller number of legacy-imported posts carry a literal `\u0026`
	 * JSON-escape artifact instead (apparently double-JSON-encoded at
	 * import time). All three observed stored shapes must normalize to the
	 * identical destination URL.
	 *
	 * @dataProvider stored_ampersand_shape_provider
	 */
	public function test_normalizes_every_observed_stored_ampersand_shape_to_the_same_url( string $stored_query_tail ): void {
		$base_url = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-' );
		$expected = $base_url . '&utm_medium=affiliate';

		$event_id = $this->makeEventWithRawContent(
			'<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01","startTime":"20:00","ticketUrl":"' . $base_url . $stored_query_tail . '"} --><div></div><!-- /wp:data-machine-events/event-details -->'
		);

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertSame( $expected, $result['url'] );
		$this->assertTrue( $result['is_affiliate'] );
	}

	/**
	 * Each case is the literal text appended to `$base_url` inside the raw
	 * block-comment JSON — i.e. exactly as it is stored in `post_content`,
	 * before `parse_blocks()`'s json_decode() runs.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function stored_ampersand_shape_provider(): array {
		return array(
			// Raw ampersand — already correct, must pass through unchanged.
			'raw &'          => array( '&utm_medium=affiliate' ),
			// HTML-entity-encoded — the majority production shape (~54%).
			'&amp; entity'   => array( '&amp;utm_medium=affiliate' ),
			// Literal `\u0026` JSON-escape artifact. Two backslash BYTES
			// are embedded directly in the raw JSON (not run through any
			// PHP escaping helper) so that parse_blocks()'s single
			// json_decode() pass leaves exactly one literal backslash +
			// "u0026" in the decoded ticketUrl string — the actual 6-byte
			// artifact production posts carry, not a real ampersand and
			// not a PHP-level unicode escape.
			'\u0026 escape' => array( '\\\\u0026utm_medium=affiliate' ),
		);
	}

	public function test_resolves_full_url_from_block_content_even_when_meta_is_absent(): void {
		$url      = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-' );
		$event_id = $this->makeEvent( $url );

		delete_post_meta( $event_id, '_datamachine_ticket_url' );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $url, $result['url'] );
		$this->assertTrue( $result['is_affiliate'] );
	}

	/**
	 * Regression guard for a revenue-affecting bug: `_datamachine_ticket_url`
	 * meta is deliberately lossy (`datamachine_normalize_ticket_url()`
	 * strips non-identity query params like `utm_medium` for dedup
	 * comparison — see event-dates-sync.php). This ability must resolve
	 * the FULL, as-authored URL from block content — never the stripped
	 * meta — whenever block content is available, or every affiliate
	 * click-through would silently lose tracking parameters.
	 *
	 * The lossy meta value below is set directly via `update_post_meta()`
	 * rather than relying on the real `save_post` sync hook to produce it
	 * (which it does — see `datamachine_normalize_ticket_url()` — but
	 * exercising that indirectly here would make this test's outcome
	 * depend on two functions instead of the one this test actually
	 * targets: `resolveTicketUrl()`'s block-vs-meta priority).
	 */
	public function test_prefers_full_block_content_url_over_lossy_dedup_meta(): void {
		$url      = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-' ) . '&utm_medium=affiliate';
		$event_id = $this->makeEventWithRawTicketUrl( $url );

		// Simulate what datamachine_normalize_ticket_url() actually
		// produces for this URL: identity ?u= param preserved, utm_medium
		// stripped.
		update_post_meta(
			$event_id,
			'_datamachine_ticket_url',
			'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-' )
		);

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertSame( $url, $result['url'], 'Must return the full block-content URL, not the tracking-param-stripped dedup meta.' );
	}

	public function test_falls_back_to_meta_when_block_content_has_no_ticket_url(): void {
		$event_id = self::factory()->post->create(
			array(
				'post_title'   => 'Meta-Only Ticket Event ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);
		$this->assertIsInt( $event_id );

		// Simulate a legacy/edge-case post where the dedup meta carries a
		// ticket URL independently of (missing) block content — e.g.
		// written by an older import path. The ability should still
		// resolve it rather than returning nothing.
		update_post_meta( $event_id, '_datamachine_ticket_url', 'https://www.ticketmaster.com/event/fallback-only' );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://www.ticketmaster.com/event/fallback-only', $result['url'] );
	}

	private function makeEvent( string $ticket_url, string $status = 'publish' ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_title'   => 'Ticket Destination Event ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => $status,
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01","startTime":"20:00","ticketUrl":"' . $ticket_url . '"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);
		$this->assertIsInt( $event_id );
		$this->assertGreaterThan( 0, $event_id );

		return $event_id;
	}

	/**
	 * Like `makeEvent()`, but for fixtures where the stored byte sequence
	 * itself is the point of the test (raw `&`, `&amp;`, `\u0026`, etc.).
	 *
	 * `wp_insert_post()`'s content_save_pre filters run
	 * `wp_kses_normalize_entities()` for any post author without the
	 * `unfiltered_html` capability — including this test suite's default
	 * user — which rewrites a bare `&` not already part of a recognized
	 * HTML entity into `&amp;` on save. That makes it impossible to
	 * deterministically construct a "raw ampersand" fixture through the
	 * normal factory path: the exact behavior under test would depend on
	 * environment-specific capability state instead of the input given.
	 * Bypasses `wp_insert_post()`'s content filters entirely via a direct
	 * `$wpdb` write so the stored bytes are always exactly what was given.
	 */
	private function makeEventWithRawTicketUrl( string $ticket_url, string $status = 'publish' ): int {
		return $this->makeEventWithRawContent(
			'<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01","startTime":"20:00","ticketUrl":"' . $ticket_url . '"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			$status
		);
	}

	/**
	 * Create an event post and force its `post_content` to an exact raw
	 * byte sequence, bypassing `wp_insert_post()`'s content-filter pipeline
	 * (see `makeEventWithRawTicketUrl()` docblock for why). This also
	 * means `save_post` never fires against the final content, so the
	 * `_datamachine_ticket_url` meta is not auto-synced here — none of the
	 * tests using this helper need it to be, since `resolveTicketUrl()`
	 * reads block content first regardless of meta state.
	 */
	private function makeEventWithRawContent( string $post_content, string $status = 'publish' ): int {
		global $wpdb;

		$event_id = self::factory()->post->create(
			array(
				'post_title'  => 'Ticket Destination Event ' . uniqid(),
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => $status,
			)
		);
		$this->assertIsInt( $event_id );
		$this->assertGreaterThan( 0, $event_id );

		$updated = $wpdb->update( $wpdb->posts, array( 'post_content' => $post_content ), array( 'ID' => $event_id ) );
		$this->assertNotFalse( $updated, 'Direct post_content write failed.' );
		clean_post_cache( $event_id );

		return $event_id;
	}
}
