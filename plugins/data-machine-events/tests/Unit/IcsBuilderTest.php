<?php
/**
 * IcsBuilder Tests
 *
 * The .ics endpoint is public and fetchable without JS or interaction, so
 * any affiliate-wrapped ticket URL embedded in the VEVENT DESCRIPTION is
 * recoverable by a crawler — see #817. These tests assert the DESCRIPTION
 * field never embeds the raw ticket URL, only the event permalink — exactly
 * once, labelled "Tickets:" when a ticket URL exists or "More info:"
 * otherwise, never both — and that no permalink line is emitted at all when
 * no permalink is available.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.62.0
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\EventActions\IcsBuilder;
use DataMachineEvents\Steps\Upsert\Events\EventBlockContentBuilder;

class IcsBuilderTest extends WP_UnitTestCase {

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

	private function create_event_post( array $event_fields, string $slug ): int {
		$builder = new EventBlockContentBuilder();
		$content = $builder->generate_event_block_content( $event_fields );

		return $this->factory()->post->create(
			array(
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_title'   => 'Affiliate Leak Test Event',
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
	}

	/**
	 * Unfold RFC 5545 folded lines (CRLF + leading space continuation) back
	 * into a single logical line per property so substring assertions work
	 * regardless of the 75-octet fold width.
	 */
	private function unfold( string $ics ): string {
		return str_replace( "\r\n ", '', $ics );
	}

	private function extract_description( string $ics ): string {
		$unfolded = $this->unfold( $ics );
		foreach ( explode( "\r\n", $unfolded ) as $line ) {
			if ( 0 === strpos( $line, 'DESCRIPTION:' ) ) {
				return substr( $line, strlen( 'DESCRIPTION:' ) );
			}
		}
		return '';
	}

	public function test_ics_description_never_embeds_affiliate_url() {
		$post_id = $this->create_event_post(
			array(
				'startDate' => '2026-09-23',
				'startTime' => '20:00',
				'venue'     => 'The Royal American',
				'ticketUrl' => self::AFFILIATE_TICKET_URL,
			),
			'affiliate-leak-test-ics-event'
		);

		$ics         = IcsBuilder::build( $post_id );
		$description = $this->extract_description( $ics );

		$this->assertNotEmpty( $description );
		$this->assertStringNotContainsString( 'evyy.net', $description );
		$this->assertStringNotContainsString( 'utm_medium=affiliate', $description );

		$permalink = get_permalink( $post_id );
		$this->assertStringContainsString( 'Tickets: ' . $permalink, $description );
		// With a ticket URL present, "Tickets:" wins and "More info:" must not
		// also appear — the permalink is the same destination either way, so
		// it must not be duplicated under two labels.
		$this->assertStringNotContainsString( 'More info:', $description );
		$this->assertSame( 1, substr_count( $description, $permalink ), 'Permalink must appear exactly once in the description.' );
	}

	public function test_ics_url_property_is_never_the_affiliate_url() {
		$post_id = $this->create_event_post(
			array(
				'startDate' => '2026-09-23',
				'startTime' => '20:00',
				'ticketUrl' => self::AFFILIATE_TICKET_URL,
			),
			'affiliate-leak-test-ics-url-property'
		);

		$ics = $this->unfold( IcsBuilder::build( $post_id ) );

		foreach ( explode( "\r\n", $ics ) as $line ) {
			if ( 0 === strpos( $line, 'URL:' ) ) {
				$this->assertStringNotContainsString( 'evyy.net', $line );
				$this->assertSame( 'URL:' . get_permalink( $post_id ), $line );
				return;
			}
		}
		$this->fail( 'Expected a URL: property in the generated .ics' );
	}

	public function test_more_info_line_is_used_when_ticket_url_is_absent() {
		$post_id = $this->create_event_post(
			array(
				'startDate' => '2026-09-23',
				'startTime' => '20:00',
			),
			'affiliate-leak-test-ics-no-ticket'
		);

		$description = $this->extract_description( IcsBuilder::build( $post_id ) );
		$permalink   = get_permalink( $post_id );

		// No ticket URL -> falls back to "More info:", and "Tickets:" must
		// not appear since there was never a ticket URL to label.
		$this->assertStringNotContainsString( 'Tickets:', $description );
		$this->assertStringContainsString( 'More info: ' . $permalink, $description );
		$this->assertSame( 1, substr_count( $description, $permalink ), 'Permalink must appear exactly once in the description.' );
	}
}
