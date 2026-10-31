<?php
/**
 * Legacy inline affiliate ticket link gate tests.
 *
 * Covers the `the_content` filter that rewrites AI-generated inline
 * affiliate anchors (written directly into `post_content` at import time,
 * before this compliance pass) into the same JS-gated form as the Event
 * Details block's ticket button. See issue #816.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\Event_Post_Type;
use WP_UnitTestCase;

use function DataMachineEvents\Core\data_machine_events_gate_legacy_affiliate_ticket_links;

class LegacyTicketLinkGateTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
	}

	public function tearDown(): void {
		wp_reset_postdata();
		parent::tearDown();
	}

	public function test_rewrites_inline_affiliate_anchor_on_event_post(): void {
		$affiliate_href = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-' ) . '&utm_medium=affiliate';
		$post_id        = $this->makeEventWithContent(
			'<p><strong>Tickets:</strong> Available via Ticketmaster: <a href="' . esc_url( $affiliate_href ) . '">Buy Tickets</a></p>'
		);

		$this->setUpGlobalPost( $post_id );
		$output = data_machine_events_gate_legacy_affiliate_ticket_links( get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString( 'evyy.net', $output );
		$this->assertStringNotContainsString( '1191134', $output );
		$this->assertStringContainsString( 'data-ticket-ref="' . $post_id . '"', $output );
		$this->assertStringNotContainsString( 'href="' . esc_url( $affiliate_href ) . '"', $output );
	}

	public function test_leaves_non_affiliate_anchor_untouched(): void {
		$post_id = $this->makeEventWithContent( '<p>Tickets: <a href="https://www.ticketmaster.com/event/123">Buy Tickets</a></p>' );

		$this->setUpGlobalPost( $post_id );
		$content = get_post_field( 'post_content', $post_id );
		$output  = data_machine_events_gate_legacy_affiliate_ticket_links( $content );

		$this->assertSame( $content, $output );
	}

	public function test_ignores_non_event_post_types(): void {
		$affiliate_href = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/1' );
		$post_id        = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => '<p><a href="' . esc_url( $affiliate_href ) . '">Buy Tickets</a></p>',
			)
		);

		$this->setUpGlobalPost( $post_id );
		$content = get_post_field( 'post_content', $post_id );
		$output  = data_machine_events_gate_legacy_affiliate_ticket_links( $content );

		$this->assertSame( $content, $output, 'Only data_machine_events posts are rewritten.' );
	}

	public function test_early_bails_on_content_with_no_anchors(): void {
		$post_id = $this->makeEventWithContent( '<p>No links here.</p>' );

		$this->setUpGlobalPost( $post_id );
		$content = get_post_field( 'post_content', $post_id );
		$output  = data_machine_events_gate_legacy_affiliate_ticket_links( $content );

		$this->assertSame( $content, $output );
	}

	public function test_rewrites_multiple_affiliate_anchors_in_one_post(): void {
		$href_a  = 'https://ticketmaster.evyy.net/c/1191134/264167/1?u=' . rawurlencode( 'https://www.ticketmaster.com/event/a' );
		$href_b  = 'https://ticketmaster.evyy.net/c/1191134/264167/2?u=' . rawurlencode( 'https://www.ticketmaster.com/event/b' );
		$post_id = $this->makeEventWithContent(
			'<p><a href="' . esc_url( $href_a ) . '">Night 1</a> and <a href="' . esc_url( $href_b ) . '">Night 2</a></p>'
		);

		$this->setUpGlobalPost( $post_id );
		$output = data_machine_events_gate_legacy_affiliate_ticket_links( get_post_field( 'post_content', $post_id ) );

		$this->assertSame( 2, substr_count( $output, 'data-ticket-ref="' . $post_id . '"' ) );
		$this->assertStringNotContainsString( 'evyy.net', $output );
	}

	/**
	 * The filter reads the current post from `get_post()` (global `$post`),
	 * mirroring how `the_content` is actually invoked inside the loop.
	 */
	private function setUpGlobalPost( int $post_id ): void {
		global $post;
		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture mirrors the_content()'s loop context.
		setup_postdata( $post );
	}

	private function makeEventWithContent( string $content ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		return $post_id;
	}
}
